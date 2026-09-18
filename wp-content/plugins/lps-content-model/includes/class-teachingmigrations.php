<?php
/**
 * Versioned, additive teaching-registry schema migrations.
 *
 * @package LPS\ContentModel
 */

declare(strict_types=1);

namespace LPS\ContentModel;

use WP_Error;
use wpdb;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Schema application and fresh registry receipts necessarily bypass object caches.
/**
 * Owns the indexed uniqueness registries for teaching identities.
 *
 * Two plugin-owned tables carry the atomic uniqueness contracts:
 * `{prefix}lps_term_registry` enforces `(calendar_key, term_code)` and the
 * immutable `term_token`; `{prefix}lps_offering_registry` enforces
 * `(authoritative course, authoritative term, normalized section key)`.
 * Migrations are versioned, additive, idempotent, and dry-run capable; a
 * dry-run never touches the database and preserves every existing record.
 */
final class TeachingMigrations {
	public const VERSION = '1.0.0';

	private const VERSION_OPTION = 'lps_teaching_schema_version';

	/**
	 * Returns the ordered additive schema steps keyed by version.
	 *
	 * @return array<string, array<int, string>>
	 */
	public static function steps(): array {
		return array(
			'1.0.0' => array( 'create_term_registry', 'create_offering_registry' ),
		);
	}

	/**
	 * Returns table suffixes in dependency-safe creation order.
	 *
	 * @return array<int, string>
	 */
	public static function table_suffixes(): array {
		return array( 'lps_term_registry', 'lps_offering_registry' );
	}

	/**
	 * Returns fully qualified registry table names.
	 *
	 * @param wpdb|null $database Optional WordPress database adapter.
	 * @return array<string, string>
	 */
	public static function table_names( ?wpdb $database = null ): array {
		if ( null === $database ) {
			$database = self::database();
		}
		return array(
			'term_registry'     => $database->prefix . 'lps_term_registry',
			'offering_registry' => $database->prefix . 'lps_offering_registry',
		);
	}

	/**
	 * Builds deterministic dbDelta-compatible registry schema statements.
	 *
	 * @param string $prefix          WordPress table prefix.
	 * @param string $charset_collate Trusted wpdb charset/collation clause.
	 * @return array<string, string>
	 */
	public static function schema_sql( string $prefix, string $charset_collate ): array {
		$term_registry     = $prefix . 'lps_term_registry';
		$offering_registry = $prefix . 'lps_offering_registry';
		return array(
			$term_registry     => "CREATE TABLE {$term_registry} (
 term_post_id bigint(20) unsigned NOT NULL,
 calendar_key varchar(64) NOT NULL,
 term_code varchar(64) NOT NULL,
 term_token varchar(129) NOT NULL,
 identity_hash char(64) NOT NULL,
 created_at varchar(25) NOT NULL,
 PRIMARY KEY  (term_post_id),
 UNIQUE KEY identity_hash (identity_hash),
 UNIQUE KEY term_token (term_token),
 KEY calendar_lookup (calendar_key,term_code)
) {$charset_collate};",
			$offering_registry => "CREATE TABLE {$offering_registry} (
 offering_post_id bigint(20) unsigned NOT NULL,
 course_post_id bigint(20) unsigned NOT NULL,
 term_post_id bigint(20) unsigned NOT NULL,
 section_key varchar(64) NOT NULL,
 identity_hash char(64) NOT NULL,
 created_at varchar(25) NOT NULL,
 PRIMARY KEY  (offering_post_id),
 UNIQUE KEY identity_hash (identity_hash),
 KEY course_lookup (course_post_id),
 KEY term_lookup (term_post_id),
 KEY section_lookup (section_key)
) {$charset_collate};",
		);
	}

	/**
	 * Returns a deterministic expected-schema fingerprint.
	 *
	 * @param string $prefix          WordPress table prefix.
	 * @param string $charset_collate Trusted wpdb charset/collation clause.
	 */
	public static function schema_hash( string $prefix, string $charset_collate ): string {
		return hash( 'sha256', implode( "\n", self::schema_sql( $prefix, $charset_collate ) ) );
	}

	/**
	 * Validates and returns the additive migration plan between two versions.
	 *
	 * Plans are forward-only: every step must be a known additive operation and
	 * the target version must be newer than the source version.
	 *
	 * @param string $from_version Currently installed schema version.
	 * @param string $to_version   Target schema version.
	 * @return array{ok: bool, errors: array<int, string>, from_version: string, to_version: string, steps: array<int, string>}
	 */
	public static function plan( string $from_version, string $to_version ): array {
		$errors = array();
		$steps  = self::steps();
		if ( ! isset( $steps[ $to_version ] ) ) {
			$errors[] = 'lps_teaching_migration_unknown_version';
		}
		if ( '' !== $from_version && '0.0.0' !== $from_version && ! isset( $steps[ $from_version ] ) ) {
			$errors[] = 'lps_teaching_migration_unknown_version';
		}
		if ( array() === $errors && version_compare( $from_version, $to_version, '>=' ) ) {
			$errors[] = 'lps_teaching_migration_downgrade';
		}
		$operations = array();
		if ( array() === $errors ) {
			foreach ( $steps as $version => $operations_for_version ) {
				if ( version_compare( $version, $from_version, '>' ) && version_compare( $version, $to_version, '<=' ) ) {
					foreach ( $operations_for_version as $operation ) {
						if ( ! self::is_additive_operation( $operation ) ) {
							$errors[] = 'lps_teaching_migration_not_additive';
							continue;
						}
						$operations[] = $operation;
					}
				}
			}
		}
		return array(
			'ok'           => array() === $errors,
			'errors'       => array_values( array_unique( $errors ) ),
			'from_version' => $from_version,
			'to_version'   => $to_version,
			'steps'        => $operations,
		);
	}

	/**
	 * Simulates a migration plan without touching storage.
	 *
	 * The dry-run is pure: it reads no database state and writes nothing, so the
	 * existing schema and data are provably unchanged.
	 *
	 * @param string $from_version Currently installed schema version.
	 * @param string $to_version   Target schema version.
	 * @return array{ok: bool, errors: array<int, string>, from_version: string, to_version: string, steps: array<int, string>, statements: array<int, string>, mutated: false, preserves_existing_records: true}
	 */
	public static function dry_run( string $from_version, string $to_version ): array {
		$plan       = self::plan( $from_version, $to_version );
		$statements = array();
		if ( $plan['ok'] ) {
			$schema = self::schema_sql( '{prefix}', '{charset_collate}' );
			foreach ( $plan['steps'] as $operation ) {
				$table = '{prefix}lps_' . str_replace( 'create_', '', $operation );
				if ( isset( $schema[ $table ] ) ) {
					$statements[] = $schema[ $table ];
				}
			}
		}
		return array_merge(
			$plan,
			array(
				'statements'                 => $statements,
				'mutated'                    => false,
				'preserves_existing_records' => true,
			)
		);
	}

	/**
	 * Applies the additive registry schema idempotently.
	 *
	 * @return array{version: string, schema_hash: string, tables: int, changes: int}|WP_Error
	 */
	public static function apply(): array|WP_Error {
		$wpdb    = self::database();
		$current = get_option( self::VERSION_OPTION, '0.0.0' );
		$current = is_string( $current ) ? $current : '0.0.0';
		if ( version_compare( $current, self::VERSION, '>' ) ) {
			return new WP_Error(
				'lps_teaching_migration_downgrade',
				'The installed teaching registry schema is newer than this plugin.',
				array( 'status' => 400 )
			);
		}

		require_once dirname( __DIR__, 4 ) . '/wp-admin/includes/upgrade.php';
		$changes = array();
		foreach ( self::schema_sql( $wpdb->prefix, $wpdb->get_charset_collate() ) as $statement ) {
			$result  = dbDelta( $statement );
			$changes = array_merge( $changes, $result );
		}
		update_option( self::VERSION_OPTION, self::VERSION, false );
		return array(
			'version'     => self::VERSION,
			'schema_hash' => self::schema_hash( $wpdb->prefix, $wpdb->get_charset_collate() ),
			'tables'      => count( self::table_suffixes() ),
			'changes'     => count( $changes ),
		);
	}

	/**
	 * Reports physical registry table existence and row counts.
	 *
	 * @return array{version: string, schema_hash: string, tables: array<string, array{exists: bool, rows: int}>}
	 */
	public static function report(): array {
		$wpdb   = self::database();
		$report = array();
		foreach ( self::table_names( $wpdb ) as $key => $table ) {
			$exists = self::table_exists( $table, $wpdb );
			$rows   = 0;
			if ( $exists ) {
				$rows = Policy::sanitize_integer( $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $table ) ) );
			}
			$report[ $key ] = array(
				'exists' => $exists,
				'rows'   => $rows,
			);
		}
		$version = get_option( self::VERSION_OPTION, '0.0.0' );
		return array(
			'version'     => is_string( $version ) ? $version : '0.0.0',
			'schema_hash' => self::schema_hash( $wpdb->prefix, $wpdb->get_charset_collate() ),
			'tables'      => $report,
		);
	}

	/**
	 * Releases uniqueness claims owned by a record WordPress actually deleted.
	 *
	 * Claims are released only on hard deletion; archival preserves history and
	 * never silently frees an identity.
	 *
	 * @param int $post_id Deleted record database ID.
	 */
	public static function release_post_claims( int $post_id ): void {
		if ( 0 >= $post_id ) {
			return;
		}
		$wpdb   = self::database();
		$tables = self::table_names( $wpdb );
		foreach ( array(
			'term_registry'     => 'term_post_id',
			'offering_registry' => 'offering_post_id',
		) as $key => $column ) {
			if ( self::table_exists( $tables[ $key ], $wpdb ) ) {
				$wpdb->delete( $tables[ $key ], array( $column => $post_id ), array( '%d' ) );
			}
		}
	}

	/**
	 * Checks whether a migration step is an allowed additive operation.
	 *
	 * @param string $operation Step identifier.
	 */
	private static function is_additive_operation( string $operation ): bool {
		return 1 === preg_match( '/^create_[a-z0-9_]+$/', $operation );
	}

	/**
	 * Returns the initialized WordPress database adapter.
	 *
	 * @throws \RuntimeException When WordPress has not initialized wpdb.
	 */
	private static function database(): wpdb {
		global $wpdb;
		if ( ! $wpdb instanceof wpdb ) {
			throw new \RuntimeException( 'WordPress database adapter is unavailable.' );
		}
		return $wpdb;
	}

	/**
	 * Checks one registry table without mutating it.
	 *
	 * @param string $table    Fully qualified table name.
	 * @param wpdb   $database WordPress database adapter.
	 */
	private static function table_exists( string $table, wpdb $database ): bool {
		$pattern = $database->esc_like( $table );
		$found   = $database->get_var( $database->prepare( 'SHOW TABLES LIKE %s', $pattern ) );
		return is_string( $found ) && $table === $found;
	}
}
// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
