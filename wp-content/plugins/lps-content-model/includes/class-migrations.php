<?php
/**
 * Idempotent and staging-reversible relationship schema migrations.
 *
 * @package LPS\ContentModel
 */

declare(strict_types=1);

namespace LPS\ContentModel;

use WP_Error;
use wpdb;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Schema application and fresh migration receipts necessarily bypass object caches.
/** Owns the custom relationship tables independently of plugin deactivation. */
final class Migrations {
	public const VERSION = '1.0.0';

	private const VERSION_OPTION = 'lps_relationship_schema_version';

	/**
	 * Returns table suffixes in dependency-safe creation order.
	 *
	 * @return array<int, string>
	 */
	public static function table_suffixes(): array {
		return array( 'lps_relationships', 'lps_authorships', 'lps_publication_dois' );
	}

	/**
	 * Returns fully qualified custom table names.
	 *
	 * @param wpdb|null $database Optional WordPress database adapter.
	 * @return array<string, string>
	 */
	public static function table_names( ?wpdb $database = null ): array {
		if ( null === $database ) {
			$database = self::database();
		}
		return array(
			'relationships' => $database->prefix . 'lps_relationships',
			'authorships'   => $database->prefix . 'lps_authorships',
			'dois'          => $database->prefix . 'lps_publication_dois',
		);
	}

	/**
	 * Builds deterministic dbDelta-compatible schema statements.
	 *
	 * WordPress deliberately avoids database foreign keys. Referential behavior is enforced by
	 * the relationship write/delete boundary so archived historical records remain addressable.
	 *
	 * @param string $prefix          WordPress table prefix.
	 * @param string $charset_collate Trusted wpdb charset/collation clause.
	 * @return array<string, string>
	 */
	public static function schema_sql( string $prefix, string $charset_collate ): array {
		$relationships = $prefix . 'lps_relationships';
		$authorships   = $prefix . 'lps_authorships';
		$dois          = $prefix . 'lps_publication_dois';
		return array(
			$relationships => "CREATE TABLE {$relationships} (
 relationship_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
 source_post_id bigint(20) unsigned NOT NULL,
 target_post_id bigint(20) unsigned NOT NULL,
 relationship_type varchar(48) NOT NULL,
 relationship_role varchar(64) NOT NULL,
 sort_order int(10) unsigned NOT NULL,
 start_date varchar(10) NOT NULL DEFAULT '',
 end_date varchar(10) NOT NULL DEFAULT '',
 public_visibility tinyint(1) NOT NULL DEFAULT 1,
 PRIMARY KEY  (relationship_id),
 UNIQUE KEY source_order (source_post_id,relationship_type,sort_order),
 UNIQUE KEY source_target (source_post_id,relationship_type,target_post_id),
 KEY target_lookup (target_post_id,relationship_type),
 KEY source_lookup (source_post_id,relationship_type)
) {$charset_collate};",
			$authorships   => "CREATE TABLE {$authorships} (
 authorship_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
 publication_id bigint(20) unsigned NOT NULL,
 author_kind varchar(16) NOT NULL,
 author_post_id bigint(20) unsigned NOT NULL DEFAULT 0,
 display_name varchar(255) NOT NULL DEFAULT '',
 orcid varchar(19) NOT NULL DEFAULT '',
 affiliation varchar(255) NOT NULL DEFAULT '',
 author_role varchar(64) NOT NULL DEFAULT 'author',
 sort_order int(10) unsigned NOT NULL,
 start_date varchar(10) NOT NULL DEFAULT '',
 end_date varchar(10) NOT NULL DEFAULT '',
 public_visibility tinyint(1) NOT NULL DEFAULT 1,
 PRIMARY KEY  (authorship_id),
 UNIQUE KEY publication_order (publication_id,sort_order),
 KEY publication_lookup (publication_id),
 KEY internal_author_lookup (author_post_id)
) {$charset_collate};",
			$dois          => "CREATE TABLE {$dois} (
 publication_id bigint(20) unsigned NOT NULL,
 normalized_doi varchar(255) NOT NULL,
 doi_hash char(64) NOT NULL,
 PRIMARY KEY  (publication_id),
 UNIQUE KEY doi_hash (doi_hash)
) {$charset_collate};",
		);
	}

	/**
	 * Returns deterministic reverse statements in dependency-safe order.
	 *
	 * @param string $prefix WordPress table prefix.
	 * @return array<int, string>
	 */
	public static function rollback_sql( string $prefix ): array {
		return array(
			'DROP TABLE IF EXISTS ' . $prefix . 'lps_publication_dois',
			'DROP TABLE IF EXISTS ' . $prefix . 'lps_authorships',
			'DROP TABLE IF EXISTS ' . $prefix . 'lps_relationships',
		);
	}

	/**
	 * Applies or repairs the relationship schema idempotently.
	 *
	 * @return array{version: string, schema_hash: string, tables: int, changes: int, terms_created: int, terms_restored: int, term_errors: array<int, string>}
	 */
	public static function apply(): array {
		$wpdb = self::database();

		require_once dirname( __DIR__, 4 ) . '/wp-admin/includes/upgrade.php';
		$schema  = self::schema_sql( $wpdb->prefix, $wpdb->get_charset_collate() );
		$changes = array();
		foreach ( $schema as $statement ) {
			$result  = dbDelta( $statement );
			$changes = array_merge( $changes, $result );
		}
		$terms = Taxonomies::seed();
		update_option( self::VERSION_OPTION, self::VERSION, false );

		return array(
			'version'        => self::VERSION,
			'schema_hash'    => self::schema_hash( $wpdb->prefix, $wpdb->get_charset_collate() ),
			'tables'         => count( self::table_suffixes() ),
			'changes'        => count( $changes ),
			'terms_created'  => $terms['created'],
			'terms_restored' => $terms['restored'],
			'term_errors'    => $terms['errors'],
		);
	}

	/**
	 * Reverses only Todo 8 storage in a non-production environment.
	 *
	 * @return array{tables_removed: int, terms_removed: int}|WP_Error
	 */
	public static function rollback(): array|WP_Error {
		$environment = wp_get_environment_type();
		if ( ! in_array( $environment, array( 'local', 'development', 'staging', 'test' ), true ) ) {
			return new WP_Error(
				'lps_migration_rollback_forbidden',
				'Relationship schema rollback is allowed only in local, test, development, or staging environments.',
				array(
					'status'      => 403,
					'environment' => $environment,
				)
			);
		}

		$wpdb    = self::database();
		$removed = 0;
		foreach ( self::rollback_sql( $wpdb->prefix ) as $statement ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Deterministic table names come from the trusted wpdb prefix.
			if ( false !== $wpdb->query( $statement ) ) {
				++$removed;
			}
		}
		$terms = Taxonomies::rollback_seed();
		delete_option( self::VERSION_OPTION );
		return array(
			'tables_removed' => $removed,
			'terms_removed'  => $terms['removed'],
		);
	}

	/**
	 * Reports physical table existence and row counts.
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
	 * Returns a deterministic expected-schema fingerprint.
	 *
	 * @param string $prefix          WordPress table prefix.
	 * @param string $charset_collate Trusted wpdb charset/collation clause.
	 */
	public static function schema_hash( string $prefix, string $charset_collate ): string {
		return hash( 'sha256', implode( "\n", self::schema_sql( $prefix, $charset_collate ) ) );
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
	 * Checks one migration-owned table without mutating it.
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
