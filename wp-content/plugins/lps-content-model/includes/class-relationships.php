<?php
/**
 * Canonical custom-table relationship persistence.
 *
 * @package LPS\ContentModel
 */

declare(strict_types=1);

namespace LPS\ContentModel;

use WP_Error;
use WP_Post;

require_once __DIR__ . '/class-teachingmigrations.php';

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- This canonical custom-table repository must read fresh integrity state and invalidates through atomic replacement.
/** Provides the only writable boundary for Todo 8 relationships and publication DOIs. */
final class Relationships {
	/**
	 * Whether a canonical DOI write is synchronizing compatibility metadata.
	 *
	 * @var bool
	 */
	private static bool $syncing_doi_meta = false;

	/**
	 * Atomically replaces one canonical relationship set.
	 *
	 * @param int                              $source_post_id    Source record database ID.
	 * @param string                           $relationship_type Canonical relationship type.
	 * @param array<int, array<string, mixed>> $rows              Complete ordered set.
	 * @return array{changed: bool, count: int}|WP_Error
	 */
	public static function replace( int $source_post_id, string $relationship_type, array $rows ): array|WP_Error {
		$validated = self::validate_relationship_set( $source_post_id, $relationship_type, $rows );
		if ( $validated instanceof WP_Error ) {
			return $validated;
		}
		$current = self::for_source( $source_post_id, $relationship_type );
		if ( $current === $validated ) {
			return array(
				'changed' => false,
				'count'   => count( $validated ),
			);
		}

		$wpdb        = self::database();
		$table       = Migrations::table_names( $wpdb )['relationships'];
		$transaction = self::begin_transaction();
		$deleted     = $wpdb->delete(
			$table,
			array(
				'source_post_id'    => $source_post_id,
				'relationship_type' => $relationship_type,
			),
			array( '%d', '%s' )
		);
		if ( false === $deleted ) {
			self::rollback_transaction( $transaction );
			return self::storage_error( $wpdb->last_error );
		}

		foreach ( $validated as $row ) {
			$inserted = $wpdb->insert(
				$table,
				array(
					'source_post_id'    => $source_post_id,
					'target_post_id'    => $row['target_post_id'],
					'relationship_type' => $relationship_type,
					'relationship_role' => $row['relationship_role'],
					'sort_order'        => $row['sort_order'],
					'start_date'        => $row['start_date'],
					'end_date'          => $row['end_date'],
					'public_visibility' => $row['public_visibility'] ? 1 : 0,
				),
				array( '%d', '%d', '%s', '%s', '%d', '%s', '%s', '%d' )
			);
			if ( false === $inserted ) {
				self::rollback_transaction( $transaction );
				return self::storage_error( $wpdb->last_error );
			}
		}
		self::commit_transaction( $transaction );
		if ( 'research_area' === $relationship_type ) {
			self::sync_research_area_cache( $source_post_id, $validated );
		}
		return array(
			'changed' => true,
			'count'   => count( $validated ),
		);
	}

	/**
	 * Atomically replaces ordered publication authorship.
	 *
	 * @param int                              $publication_id Publication record database ID.
	 * @param array<int, array<string, mixed>> $authors        Complete ordered author list.
	 * @return array{changed: bool, count: int}|WP_Error
	 */
	public static function replace_authors( int $publication_id, array $authors ): array|WP_Error {
		$validated = self::validate_author_set( $publication_id, $authors );
		if ( $validated instanceof WP_Error ) {
			return $validated;
		}
		$current = self::authors_for_publication( $publication_id );
		if ( $current === $validated ) {
			return array(
				'changed' => false,
				'count'   => count( $validated ),
			);
		}

		$wpdb        = self::database();
		$table       = Migrations::table_names( $wpdb )['authorships'];
		$transaction = self::begin_transaction();
		$deleted     = $wpdb->delete( $table, array( 'publication_id' => $publication_id ), array( '%d' ) );
		if ( false === $deleted ) {
			self::rollback_transaction( $transaction );
			return self::storage_error( $wpdb->last_error );
		}
		foreach ( $validated as $author ) {
			$inserted = $wpdb->insert(
				$table,
				array(
					'publication_id'    => $publication_id,
					'author_kind'       => $author['author_kind'],
					'author_post_id'    => $author['author_post_id'],
					'display_name'      => $author['display_name'],
					'orcid'             => $author['orcid'],
					'affiliation'       => $author['affiliation'],
					'author_role'       => $author['author_role'],
					'sort_order'        => $author['sort_order'],
					'start_date'        => $author['start_date'],
					'end_date'          => $author['end_date'],
					'public_visibility' => $author['public_visibility'] ? 1 : 0,
				),
				array( '%d', '%s', '%d', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%d' )
			);
			if ( false === $inserted ) {
				self::rollback_transaction( $transaction );
				return self::storage_error( $wpdb->last_error );
			}
		}
		self::commit_transaction( $transaction );
		return array(
			'changed' => true,
			'count'   => count( $validated ),
		);
	}

	/**
	 * Assigns approved application-domain terms as the canonical taxonomy value.
	 *
	 * @param int                $project_id Project database ID.
	 * @param array<int, string> $keys       Complete language-neutral key set.
	 * @return array{changed: bool, count: int}|WP_Error
	 */
	public static function replace_application_domains( int $project_id, array $keys ): array|WP_Error {
		$project = get_post( $project_id );
		if ( ! $project instanceof WP_Post ) {
			return self::error( 'lps_orphan_relationship_source', 'The project source does not exist.', 'project_id' );
		}
		if ( 'lps_project' !== $project->post_type ) {
			return self::error( 'lps_invalid_relationship_source_type', 'Application domains may be assigned only to projects.', 'project_id' );
		}
		$normalized = array_values( array_unique( array_map( 'sanitize_key', $keys ) ) );
		foreach ( $normalized as $key ) {
			if ( ! Taxonomies::is_approved( 'lps_application_domain', $key ) ) {
				return self::error( 'lps_unapproved_taxonomy_term', 'An application-domain key is not approved.', 'application_domains', array( 'term' => $key ) );
			}
		}
		$current = wp_get_object_terms( $project_id, 'lps_application_domain', array( 'fields' => 'slugs' ) );
		$current = is_array( $current ) ? array_map( 'strval', $current ) : array();
		if ( $current === $normalized ) {
			return array(
				'changed' => false,
				'count'   => count( $normalized ),
			);
		}
		$result = wp_set_object_terms( $project_id, $normalized, 'lps_application_domain', false );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return array(
			'changed' => true,
			'count'   => count( $normalized ),
		);
	}

	/**
	 * Returns one canonical forward relationship list in stable order.
	 *
	 * @param int    $source_post_id    Source record database ID.
	 * @param string $relationship_type Canonical relationship type.
	 * @return array<int, array{target_post_id: int, relationship_role: string, sort_order: int, start_date: string, end_date: string, public_visibility: bool}>
	 */
	public static function for_source( int $source_post_id, string $relationship_type ): array {
		$wpdb  = self::database();
		$table = Migrations::table_names( $wpdb )['relationships'];
		/**
		 * Typed forward-query rows.
		 *
		 * @var array<int, array<string, mixed>> $rows
		 */
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT target_post_id, relationship_role, sort_order, start_date, end_date, public_visibility FROM %i WHERE source_post_id = %d AND relationship_type = %s ORDER BY sort_order ASC, relationship_id ASC', $table, $source_post_id, $relationship_type ), 'ARRAY_A' );
		return array_map( array( self::class, 'cast_relationship_row' ), $rows );
	}

	/**
	 * Derives reverse relationships directly from canonical forward rows.
	 *
	 * @param int         $target_post_id    Target record database ID.
	 * @param string|null $relationship_type Optional canonical type filter.
	 * @return array<int, array{source_post_id: int, target_post_id: int, relationship_type: string, relationship_role: string, sort_order: int, start_date: string, end_date: string, public_visibility: bool}>
	 */
	public static function reverse_for( int $target_post_id, ?string $relationship_type = null ): array {
		$wpdb  = self::database();
		$table = Migrations::table_names( $wpdb )['relationships'];
		if ( null === $relationship_type ) {
			$queried = $wpdb->get_results( $wpdb->prepare( 'SELECT source_post_id, target_post_id, relationship_type, relationship_role, sort_order, start_date, end_date, public_visibility FROM %i WHERE target_post_id = %d ORDER BY sort_order ASC, source_post_id ASC, relationship_id ASC', $table, $target_post_id ), 'ARRAY_A' );
		} else {
			$queried = $wpdb->get_results( $wpdb->prepare( 'SELECT source_post_id, target_post_id, relationship_type, relationship_role, sort_order, start_date, end_date, public_visibility FROM %i WHERE target_post_id = %d AND relationship_type = %s ORDER BY sort_order ASC, source_post_id ASC, relationship_id ASC', $table, $target_post_id, $relationship_type ), 'ARRAY_A' );
		}
		/**
		 * Typed reverse-query rows.
		 *
		 * @var array<int, array<string, mixed>> $rows
		 */
		$rows = is_array( $queried ) ? $queried : array();
		return array_map( array( self::class, 'cast_reverse_row' ), $rows );
	}

	/**
	 * Returns ordered authors exactly as stored, with no project-membership inference.
	 *
	 * @param int $publication_id Publication record database ID.
	 * @return array<int, array{author_kind: string, author_post_id: int, display_name: string, orcid: string, affiliation: string, author_role: string, sort_order: int, start_date: string, end_date: string, public_visibility: bool}>
	 */
	public static function authors_for_publication( int $publication_id ): array {
		$wpdb  = self::database();
		$table = Migrations::table_names( $wpdb )['authorships'];
		/**
		 * Typed authorship-query rows.
		 *
		 * @var array<int, array<string, mixed>> $rows
		 */
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT author_kind, author_post_id, display_name, orcid, affiliation, author_role, sort_order, start_date, end_date, public_visibility FROM %i WHERE publication_id = %d ORDER BY sort_order ASC, authorship_id ASC', $table, $publication_id ), 'ARRAY_A' );
		return array_map( array( self::class, 'cast_author_row' ), $rows );
	}

	/**
	 * Derives publication lists for an internal author from canonical authorship.
	 *
	 * @param int $author_post_id Internal Person database ID.
	 * @return array<int, array{publication_id: int, author_role: string, sort_order: int, public_visibility: bool}>
	 */
	public static function publications_for_author( int $author_post_id ): array {
		$wpdb  = self::database();
		$table = Migrations::table_names( $wpdb )['authorships'];
		/**
		 * Typed internal-author reverse rows.
		 *
		 * @var array<int, array<string, mixed>> $rows
		 */
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT publication_id, author_role, sort_order, public_visibility FROM %i WHERE author_post_id = %d AND author_kind = 'internal' ORDER BY publication_id ASC, sort_order ASC", $table, $author_post_id ), 'ARRAY_A' );
		return array_map(
			static fn ( array $row ): array => array(
				'publication_id'    => Policy::sanitize_integer( $row['publication_id'] ?? 0 ),
				'author_role'       => Policy::scalar_string( $row['author_role'] ?? '' ),
				'sort_order'        => Policy::sanitize_integer( $row['sort_order'] ?? 0 ),
				'public_visibility' => 1 === Policy::sanitize_integer( $row['public_visibility'] ?? 0 ),
			),
			$rows
		);
	}

	/**
	 * Stores a normalized DOI under a database unique constraint and syncs its compatibility meta.
	 *
	 * @param int   $publication_id Publication record database ID.
	 * @param mixed $doi            Boundary DOI input.
	 * @return array{changed: bool, normalized_doi: string}|WP_Error
	 */
	public static function set_doi( int $publication_id, mixed $doi ): array|WP_Error {
		$validated = self::validate_doi( $publication_id, $doi );
		if ( $validated instanceof WP_Error ) {
			return $validated;
		}
		$current = self::doi_for_publication( $publication_id );
		if ( $current === $validated ) {
			self::sync_doi_meta( $publication_id, $validated );
			return array(
				'changed'        => false,
				'normalized_doi' => $validated,
			);
		}

		$wpdb        = self::database();
		$table       = Migrations::table_names( $wpdb )['dois'];
		$transaction = self::begin_transaction();
		if ( '' === $validated ) {
			$result = $wpdb->delete( $table, array( 'publication_id' => $publication_id ), array( '%d' ) );
		} elseif ( '' === $current ) {
			$result = $wpdb->insert(
				$table,
				array(
					'publication_id' => $publication_id,
					'normalized_doi' => $validated,
					'doi_hash'       => hash( 'sha256', $validated ),
				),
				array( '%d', '%s', '%s' )
			);
		} else {
			$result = $wpdb->update(
				$table,
				array(
					'normalized_doi' => $validated,
					'doi_hash'       => hash( 'sha256', $validated ),
				),
				array( 'publication_id' => $publication_id ),
				array( '%s', '%s' ),
				array( '%d' )
			);
		}
		if ( false === $result ) {
			self::rollback_transaction( $transaction );
			$duplicate = self::duplicate_doi_publication( $validated, $publication_id );
			return 0 < $duplicate
				? self::error(
					'lps_duplicate_doi',
					'This normalized DOI belongs to another publication.',
					'_lps_doi',
					array(
						'normalized_doi'          => $validated,
						'existing_publication_id' => $duplicate,
					)
				)
				: self::storage_error( $wpdb->last_error );
		}
		self::commit_transaction( $transaction );
		self::sync_doi_meta( $publication_id, $validated );
		return array(
			'changed'        => true,
			'normalized_doi' => $validated,
		);
	}

	/**
	 * Returns one canonical normalized publication DOI.
	 *
	 * @param int $publication_id Publication record database ID.
	 */
	public static function doi_for_publication( int $publication_id ): string {
		$wpdb  = self::database();
		$table = Migrations::table_names( $wpdb )['dois'];
		$value = $wpdb->get_var( $wpdb->prepare( 'SELECT normalized_doi FROM %i WHERE publication_id = %d', $table, $publication_id ) );
		return is_string( $value ) ? $value : '';
	}

	/**
	 * Validates a DOI before REST or direct post mutation.
	 *
	 * @param int   $publication_id Publication record database ID, or zero before creation.
	 * @param mixed $doi            Boundary DOI input.
	 */
	public static function doi_error( int $publication_id, mixed $doi ): ?WP_Error {
		if ( 0 >= $publication_id ) {
			$raw        = Policy::scalar_string( $doi );
			$normalized = RelationshipPolicy::normalize_doi( $doi );
			if ( '' !== trim( $raw ) && '' === $normalized ) {
				return self::error( 'lps_invalid_doi', 'The DOI is malformed.', '_lps_doi' );
			}
			$duplicate = self::duplicate_doi_publication( $normalized, 0 );
			return 0 < $duplicate
				? self::error(
					'lps_duplicate_doi',
					'This normalized DOI belongs to another publication.',
					'_lps_doi',
					array(
						'normalized_doi'          => $normalized,
						'existing_publication_id' => $duplicate,
					)
				)
				: null;
		}
		$result = self::validate_doi( $publication_id, $doi );
		return $result instanceof WP_Error ? $result : null;
	}

	/**
	 * Returns publish-time relationship errors keyed to machine fields.
	 *
	 * @param string $post_type Record post type.
	 * @param int    $post_id   Record database ID.
	 * @return array<string, string>
	 */
	public static function publish_errors( string $post_type, int $post_id ): array {
		$errors = array();
		if ( 'lps_project' === $post_type ) {
			$members = 0 < $post_id ? self::for_source( $post_id, 'project_member' ) : array();
			if ( ! in_array( 'lead', array_column( $members, 'relationship_role' ), true ) ) {
				$errors['_lps_relationship_project_member'] = 'lps_project_lead_required';
			}
		}
		if ( in_array( $post_type, array( 'lps_person', 'lps_project', 'lps_publication' ), true ) && 0 < $post_id ) {
			$areas = self::for_source( $post_id, 'research_area' );
			if ( array() !== $areas ) {
				$area_errors = RelationshipPolicy::relationship_errors( 'research_area', $areas );
				if ( array() !== $area_errors ) {
					$errors['_lps_relationship_research_area'] = $area_errors[0];
				}
			}
		}
		if ( 'lps_offering' === $post_type && 0 < $post_id ) {
			foreach ( array( 'offering_course', 'offering_term', 'teaching_team' ) as $relationship_type ) {
				$type_errors = TeachingContracts::relationship_errors( $relationship_type, self::for_source( $post_id, $relationship_type ) );
				if ( array() !== $type_errors ) {
					$errors[ '_lps_relationship_' . $relationship_type ] = $type_errors[0];
				}
			}
		}
		if ( 'lps_unit' === $post_type && 0 < $post_id ) {
			$unit_errors = TeachingContracts::relationship_errors( 'unit_offering', self::for_source( $post_id, 'unit_offering' ) );
			if ( array() !== $unit_errors ) {
				$errors['_lps_relationship_unit_offering'] = $unit_errors[0];
			}
		}
		if ( 'lps_resource' === $post_type && 0 < $post_id ) {
			$resource_errors = TeachingContracts::relationship_errors( 'resource_offering', self::for_source( $post_id, 'resource_offering' ) );
			if ( array() !== $resource_errors ) {
				$errors['_lps_relationship_resource_offering'] = $resource_errors[0];
			}
			$unit_rows = self::for_source( $post_id, 'resource_unit' );
			if ( 1 === count( $unit_rows ) && array() === $resource_errors ) {
				$resource_offering = self::for_source( $post_id, 'resource_offering' );
				$unit_offering     = self::for_source( $unit_rows[0]['target_post_id'], 'unit_offering' );
				$unit_error        = TeachingContracts::resource_unit_error(
					Policy::sanitize_integer( $resource_offering[0]['target_post_id'] ?? 0 ),
					Policy::sanitize_integer( $unit_offering[0]['target_post_id'] ?? 0 )
				);
				if ( null !== $unit_error ) {
					$errors['_lps_relationship_resource_unit'] = $unit_error;
				}
			}
		}
		return $errors;
	}

	/**
	 * Checks both endpoints and authorship before hard deletion.
	 *
	 * @param int $post_id Candidate record database ID.
	 */
	public static function is_referenced( int $post_id ): bool {
		$wpdb         = self::database();
		$tables       = Migrations::table_names( $wpdb );
		$relationship = $wpdb->get_var( $wpdb->prepare( 'SELECT relationship_id FROM %i WHERE source_post_id = %d OR target_post_id = %d LIMIT 1', $tables['relationships'], $post_id, $post_id ) );
		if ( null !== $relationship ) {
			return true;
		}
		$authorship = $wpdb->get_var( $wpdb->prepare( 'SELECT authorship_id FROM %i WHERE publication_id = %d OR author_post_id = %d LIMIT 1', $tables['authorships'], $post_id, $post_id ) );
		return null !== $authorship;
	}

	/**
	 * Removes identifier state for a record WordPress has actually deleted.
	 *
	 * @param int $post_id Deleted record database ID.
	 */
	public static function cleanup_deleted_post( int $post_id ): void {
		$wpdb   = self::database();
		$tables = Migrations::table_names( $wpdb );
		$wpdb->delete( $tables['dois'], array( 'publication_id' => $post_id ), array( '%d' ) );
		TeachingMigrations::release_post_claims( $post_id );
	}

	/**
	 * Exports all canonical relationship state without physical row IDs.
	 *
	 * @return array{schema_version: string, authors: array<int, array{publication_id: int, rows: array<int, array<string, mixed>>}>, relationships: array<int, array{source_post_id: int, relationship_type: string, rows: array<int, array<string, mixed>>}>, dois: array<int, array{publication_id: int, normalized_doi: string}>}
	 */
	public static function export(): array {
		$wpdb       = self::database();
		$tables     = Migrations::table_names( $wpdb );
		$author_ids = $wpdb->get_col( $wpdb->prepare( 'SELECT DISTINCT publication_id FROM %i ORDER BY publication_id ASC', $tables['authorships'] ) );
		$authors    = array();
		foreach ( $author_ids as $publication_id ) {
			$id        = Policy::sanitize_integer( $publication_id );
			$authors[] = array(
				'publication_id' => $id,
				'rows'           => self::authors_for_publication( $id ),
			);
		}

		/**
		 * Typed relationship export groups.
		 *
		 * @var array<int, array<string, mixed>> $groups
		 */
		$groups        = $wpdb->get_results( $wpdb->prepare( 'SELECT DISTINCT source_post_id, relationship_type FROM %i ORDER BY source_post_id ASC, relationship_type ASC', $tables['relationships'] ), 'ARRAY_A' );
		$relationships = array();
		foreach ( $groups as $group ) {
			$source_post_id    = Policy::sanitize_integer( $group['source_post_id'] ?? 0 );
			$relationship_type = Policy::scalar_string( $group['relationship_type'] ?? '' );
			$relationships[]   = array(
				'source_post_id'    => $source_post_id,
				'relationship_type' => $relationship_type,
				'rows'              => self::for_source( $source_post_id, $relationship_type ),
			);
		}

		/**
		 * Typed DOI export rows.
		 *
		 * @var array<int, array<string, mixed>> $doi_rows
		 */
		$doi_rows = $wpdb->get_results( $wpdb->prepare( 'SELECT publication_id, normalized_doi FROM %i ORDER BY publication_id ASC', $tables['dois'] ), 'ARRAY_A' );
		$dois     = array();
		foreach ( $doi_rows as $row ) {
			$dois[] = array(
				'publication_id' => Policy::sanitize_integer( $row['publication_id'] ?? 0 ),
				'normalized_doi' => Policy::scalar_string( $row['normalized_doi'] ?? '' ),
			);
		}
		return array(
			'schema_version' => Migrations::VERSION,
			'authors'        => $authors,
			'relationships'  => $relationships,
			'dois'           => $dois,
		);
	}

	/** Returns a deterministic semantic export hash. */
	public static function export_hash(): string {
		$json = wp_json_encode( self::export(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		return hash( 'sha256', false === $json ? '' : $json );
	}

	/**
	 * Validates then idempotently imports a complete canonical relationship payload.
	 *
	 * @param array<string, mixed> $payload Export-shaped payload.
	 * @return array{before_hash: string, after_hash: string, changed_groups: int, authors: int, relationships: int, dois: int}|WP_Error
	 */
	public static function import( array $payload ): array|WP_Error {
		$reverse_error = RelationshipPolicy::manual_reverse_error( $payload );
		if ( null !== $reverse_error ) {
			return self::error( $reverse_error, 'Reverse relationships are always derived and cannot be imported.', 'reverse_relationships' );
		}
		foreach ( array_keys( $payload ) as $key ) {
			if ( ! in_array( $key, array( 'schema_version', 'authors', 'relationships', 'dois' ), true ) ) {
				return self::error( 'lps_relationship_import_unknown_field', 'The relationship import contains an unknown top-level field.', (string) $key );
			}
		}
		foreach ( array( 'authors', 'relationships', 'dois' ) as $collection ) {
			if ( isset( $payload[ $collection ] ) && ! is_array( $payload[ $collection ] ) ) {
				return self::error( 'lps_invalid_relationship_import', 'A relationship import collection is malformed.', $collection );
			}
		}
		$raw_author_groups       = isset( $payload['authors'] ) && is_array( $payload['authors'] ) ? $payload['authors'] : array();
		$raw_relationship_groups = isset( $payload['relationships'] ) && is_array( $payload['relationships'] ) ? $payload['relationships'] : array();
		$raw_doi_rows            = isset( $payload['dois'] ) && is_array( $payload['dois'] ) ? $payload['dois'] : array();
		/**
		 * Validated authorship import groups.
		 *
		 * @var array<int, array{publication_id: int, rows: array<int, array<string, mixed>>}> $author_groups
		 */
		$author_groups = array();
		foreach ( $raw_author_groups as $group ) {
			if ( ! is_array( $group ) ) {
				return self::error( 'lps_invalid_relationship_import', 'An authorship import group is malformed.', 'authors' );
			}
			$rows = self::import_rows( $group['rows'] ?? null, 'authors' );
			if ( $rows instanceof WP_Error ) {
				return $rows;
			}
			$publication_id = Policy::sanitize_integer( $group['publication_id'] ?? 0 );
			$validated      = self::validate_author_set( $publication_id, $rows );
			if ( $validated instanceof WP_Error ) {
				return $validated;
			}
			$author_groups[] = array(
				'publication_id' => $publication_id,
				'rows'           => $rows,
			);
		}

		/**
		 * Validated canonical relationship import groups.
		 *
		 * @var array<int, array{source_post_id: int, relationship_type: string, rows: array<int, array<string, mixed>>}> $relationship_groups
		 */
		$relationship_groups = array();
		foreach ( $raw_relationship_groups as $group ) {
			if ( ! is_array( $group ) ) {
				return self::error( 'lps_invalid_relationship_import', 'A relationship import group is malformed.', 'relationships' );
			}
			$rows = self::import_rows( $group['rows'] ?? null, 'relationships' );
			if ( $rows instanceof WP_Error ) {
				return $rows;
			}
			$source_post_id = Policy::sanitize_integer( $group['source_post_id'] ?? 0 );
			$type           = Policy::scalar_string( $group['relationship_type'] ?? '' );
			$validated      = self::validate_relationship_set( $source_post_id, $type, $rows );
			if ( $validated instanceof WP_Error ) {
				return $validated;
			}
			$relationship_groups[] = array(
				'source_post_id'    => $source_post_id,
				'relationship_type' => $type,
				'rows'              => $rows,
			);
		}

		/**
		 * Validated DOI import rows.
		 *
		 * @var array<int, array{publication_id: int, normalized_doi: string}> $doi_rows
		 */
		$doi_rows     = array();
		$payload_dois = array();
		foreach ( $raw_doi_rows as $row ) {
			if ( ! is_array( $row ) ) {
				return self::error( 'lps_invalid_relationship_import', 'A DOI import row is malformed.', 'dois' );
			}
			$publication_id = Policy::sanitize_integer( $row['publication_id'] ?? 0 );
			$validated      = self::validate_doi( $publication_id, $row['normalized_doi'] ?? '' );
			if ( $validated instanceof WP_Error ) {
				return $validated;
			}
			if ( '' !== $validated && isset( $payload_dois[ $validated ] ) && $publication_id !== $payload_dois[ $validated ] ) {
				return self::error( 'lps_duplicate_doi', 'The import contains duplicate normalized DOI identities.', '_lps_doi', array( 'normalized_doi' => $validated ) );
			}
			$payload_dois[ $validated ] = $publication_id;
			$doi_rows[]                 = array(
				'publication_id' => $publication_id,
				'normalized_doi' => $validated,
			);
		}

		$before  = self::export_hash();
		$changed = 0;
		foreach ( $author_groups as $group ) {
			$result = self::replace_authors( $group['publication_id'], $group['rows'] );
			if ( $result instanceof WP_Error ) {
				return $result;
			}
			$changed += $result['changed'] ? 1 : 0;
		}
		foreach ( $relationship_groups as $group ) {
			$result = self::replace( $group['source_post_id'], $group['relationship_type'], $group['rows'] );
			if ( $result instanceof WP_Error ) {
				return $result;
			}
			$changed += $result['changed'] ? 1 : 0;
		}
		foreach ( $doi_rows as $row ) {
			$result = self::set_doi( $row['publication_id'], $row['normalized_doi'] );
			if ( $result instanceof WP_Error ) {
				return $result;
			}
			$changed += $result['changed'] ? 1 : 0;
		}
		return array(
			'before_hash'    => $before,
			'after_hash'     => self::export_hash(),
			'changed_groups' => $changed,
			'authors'        => count( $author_groups ),
			'relationships'  => count( $relationship_groups ),
			'dois'           => count( $doi_rows ),
		);
	}

	/**
	 * Intercepts canonical DOI and forbidden manual reverse metadata updates.
	 *
	 * @param mixed  $check      Existing short-circuit value.
	 * @param int    $object_id  Record database ID.
	 * @param string $meta_key   Metadata key.
	 * @param mixed  $meta_value Candidate value.
	 * @return mixed
	 */
	public static function intercept_metadata_update( mixed $check, int $object_id, string $meta_key, mixed $meta_value ): mixed {
		if ( str_starts_with( $meta_key, '_lps_reverse_' ) || in_array( $meta_key, RelationshipPolicy::manual_reverse_meta_keys(), true ) ) {
			return false;
		}
		if ( '_lps_doi' !== $meta_key || self::$syncing_doi_meta ) {
			return $check;
		}
		$result = self::set_doi( $object_id, $meta_value );
		return $result instanceof WP_Error ? false : true;
	}

	/**
	 * Parses one import row collection into string-keyed boundary rows.
	 *
	 * @param mixed  $value Import collection.
	 * @param string $field Import field name.
	 * @return array<int, array<string, mixed>>|WP_Error
	 */
	private static function import_rows( mixed $value, string $field ): array|WP_Error {
		if ( ! is_array( $value ) ) {
			return self::error( 'lps_invalid_relationship_import', 'A relationship import row collection is malformed.', $field );
		}
		$rows = array();
		foreach ( $value as $row ) {
			if ( ! is_array( $row ) ) {
				return self::error( 'lps_invalid_relationship_import', 'A relationship import row is malformed.', $field );
			}
			$typed = array();
			foreach ( $row as $key => $item ) {
				if ( ! is_string( $key ) ) {
					return self::error( 'lps_invalid_relationship_import', 'Relationship import row keys must be strings.', $field );
				}
				$typed[ $key ] = $item;
			}
			$rows[] = $typed;
		}
		return $rows;
	}

	/**
	 * Validates and normalizes a full relationship set without mutation.
	 *
	 * @param int                              $source_post_id    Source database ID.
	 * @param string                           $relationship_type Canonical type.
	 * @param array<int, array<string, mixed>> $rows              Candidate rows.
	 * @return array<int, array{target_post_id: int, relationship_role: string, sort_order: int, start_date: string, end_date: string, public_visibility: bool}>|WP_Error
	 */
	private static function validate_relationship_set( int $source_post_id, string $relationship_type, array $rows ): array|WP_Error {
		$shape_errors = RelationshipPolicy::relationship_errors( $relationship_type, $rows );
		if ( array() !== $shape_errors ) {
			return self::error( $shape_errors[0], 'The relationship set violates its typed integrity contract.', 'relationships', array( 'errors' => $shape_errors ) );
		}
		$source = get_post( $source_post_id );
		if ( ! $source instanceof WP_Post || 'trash' === $source->post_status ) {
			return self::error( 'lps_orphan_relationship_source', 'The relationship source does not exist.', 'source_post_id' );
		}
		$normalized = RelationshipPolicy::normalize_relationships( $rows );
		foreach ( $normalized as $row ) {
			$target = get_post( $row['target_post_id'] );
			$exists = $target instanceof WP_Post && 'trash' !== $target->post_status;
			$error  = RelationshipPolicy::endpoint_error( $relationship_type, $source->post_type, $exists ? $target->post_type : null, $exists );
			if ( null !== $error ) {
				return self::error( $error, 'A relationship endpoint violates its typed integrity contract.', 'target_post_id', array( 'target_post_id' => $row['target_post_id'] ) );
			}
			if ( $source_post_id === $row['target_post_id'] ) {
				return self::error( 'lps_self_relationship_forbidden', 'A record cannot relate to itself through this relationship.', 'target_post_id' );
			}
			if ( 'research_area' === $relationship_type ) {
				$key = Policy::scalar_string( get_post_meta( $row['target_post_id'], '_lps_stable_key', true ) );
				if ( ! Taxonomies::is_approved( 'lps_research_area_key', $key ) ) {
					return self::error( 'lps_unapproved_taxonomy_term', 'The related Research Area record does not use an approved key.', 'target_post_id', array( 'term' => $key ) );
				}
			}
		}
		return $normalized;
	}

	/**
	 * Validates and normalizes a complete authorship set without mutation.
	 *
	 * @param int                              $publication_id Publication database ID.
	 * @param array<int, array<string, mixed>> $authors        Candidate rows.
	 * @return array<int, array{author_kind: string, author_post_id: int, display_name: string, orcid: string, affiliation: string, author_role: string, sort_order: int, start_date: string, end_date: string, public_visibility: bool}>|WP_Error
	 */
	private static function validate_author_set( int $publication_id, array $authors ): array|WP_Error {
		$errors = RelationshipPolicy::author_errors( $authors );
		if ( array() !== $errors ) {
			return self::error( $errors[0], 'The authorship set violates its typed integrity contract.', 'authors', array( 'errors' => $errors ) );
		}
		$publication = get_post( $publication_id );
		if ( ! $publication instanceof WP_Post || 'trash' === $publication->post_status ) {
			return self::error( 'lps_orphan_relationship_source', 'The publication source does not exist.', 'publication_id' );
		}
		if ( 'lps_publication' !== $publication->post_type ) {
			return self::error( 'lps_invalid_relationship_source_type', 'Authorship may be attached only to publications.', 'publication_id' );
		}
		$normalized = RelationshipPolicy::normalize_authors( $authors );
		$identities = array();
		foreach ( $normalized as $author ) {
			if ( 'internal' === $author['author_kind'] ) {
				$target = get_post( $author['author_post_id'] );
				if ( ! $target instanceof WP_Post || 'trash' === $target->post_status ) {
					return self::error( 'lps_orphan_relationship_target', 'An internal author record does not exist.', 'author_post_id', array( 'author_post_id' => $author['author_post_id'] ) );
				}
				if ( 'lps_person' !== $target->post_type ) {
					return self::error( 'lps_invalid_relationship_target_type', 'An internal author must reference a Person record.', 'author_post_id' );
				}
				$identity = 'internal:' . $author['author_post_id'];
			} elseif ( 'external' === $author['author_kind'] ) {
				if ( 0 !== $author['author_post_id'] ) {
					return self::error( 'lps_invalid_relationship_target_type', 'An external author must not reference an internal record.', 'author_post_id' );
				}
				$identity = 'external:' . strtolower( $author['display_name'] ) . ':' . $author['orcid'];
			} else {
				if ( 0 < $author['author_post_id'] ) {
					$target = get_post( $author['author_post_id'] );
					if ( ! $target instanceof WP_Post || 'lps_organization' !== $target->post_type || 'trash' === $target->post_status ) {
						return self::error( 'lps_invalid_relationship_target_type', 'A referenced collective author must be an Organization record.', 'author_post_id' );
					}
				}
				$identity = 'collective:' . $author['author_post_id'] . ':' . strtolower( $author['display_name'] );
			}
			if ( isset( $identities[ $identity ] ) ) {
				return self::error( 'lps_duplicate_author', 'The same author appears more than once in one publication.', 'authors' );
			}
			$identities[ $identity ] = true;
		}
		return $normalized;
	}

	/**
	 * Validates and normalizes one DOI without mutation.
	 *
	 * @param int   $publication_id Publication record database ID.
	 * @param mixed $doi            Boundary value.
	 * @return string|WP_Error
	 */
	private static function validate_doi( int $publication_id, mixed $doi ): string|WP_Error {
		$publication = get_post( $publication_id );
		if ( ! $publication instanceof WP_Post || 'trash' === $publication->post_status ) {
			return self::error( 'lps_orphan_relationship_source', 'The publication source does not exist.', 'publication_id' );
		}
		if ( 'lps_publication' !== $publication->post_type ) {
			return self::error( 'lps_invalid_relationship_source_type', 'A DOI may be attached only to a Publication record.', 'publication_id' );
		}
		$raw        = Policy::scalar_string( $doi );
		$normalized = RelationshipPolicy::normalize_doi( $doi );
		if ( '' !== trim( $raw ) && '' === $normalized ) {
			return self::error( 'lps_invalid_doi', 'The DOI is malformed.', '_lps_doi' );
		}
		$duplicate = self::duplicate_doi_publication( $normalized, $publication_id );
		if ( 0 < $duplicate ) {
			return self::error(
				'lps_duplicate_doi',
				'This normalized DOI belongs to another publication.',
				'_lps_doi',
				array(
					'normalized_doi'          => $normalized,
					'existing_publication_id' => $duplicate,
				)
			);
		}
		return $normalized;
	}

	/**
	 * Returns another publication owning the normalized DOI, or zero.
	 *
	 * @param string $normalized_doi Normalized DOI identity.
	 * @param int    $publication_id Publication excluded from the collision check.
	 */
	private static function duplicate_doi_publication( string $normalized_doi, int $publication_id ): int {
		if ( '' === $normalized_doi ) {
			return 0;
		}
		$wpdb      = self::database();
		$table     = Migrations::table_names( $wpdb )['dois'];
		$duplicate = $wpdb->get_var( $wpdb->prepare( 'SELECT publication_id FROM %i WHERE doi_hash = %s AND publication_id <> %d LIMIT 1', $table, hash( 'sha256', $normalized_doi ), $publication_id ) );
		return null === $duplicate ? 0 : Policy::sanitize_integer( $duplicate );
	}

	/**
	 * Syncs approved research keys as a deterministic, non-editor-maintained taxonomy cache.
	 *
	 * @param int                                    $source_post_id Source record database ID.
	 * @param array<int, array{target_post_id: int}> $rows           Canonical research rows.
	 */
	private static function sync_research_area_cache( int $source_post_id, array $rows ): void {
		$keys = array();
		foreach ( $rows as $row ) {
			$key = Policy::scalar_string( get_post_meta( $row['target_post_id'], '_lps_stable_key', true ) );
			if ( Taxonomies::is_approved( 'lps_research_area_key', $key ) ) {
				$keys[] = $key;
			}
		}
		wp_set_object_terms( $source_post_id, $keys, 'lps_research_area_key', false );
	}

	/**
	 * Synchronizes the normalized DOI into Todo 7's declared compatibility metadata.
	 *
	 * @param int    $publication_id Publication database ID.
	 * @param string $normalized_doi Canonical DOI or an empty value.
	 */
	private static function sync_doi_meta( int $publication_id, string $normalized_doi ): void {
		self::$syncing_doi_meta = true;
		try {
			if ( '' === $normalized_doi ) {
				delete_post_meta( $publication_id, '_lps_doi' );
			} else {
				update_post_meta( $publication_id, '_lps_doi', $normalized_doi );
			}
		} finally {
			self::$syncing_doi_meta = false;
		}
	}

	/**
	 * Casts one database relationship row.
	 *
	 * @param array<string, mixed> $row Database relationship row.
	 * @return array{target_post_id: int, relationship_role: string, sort_order: int, start_date: string, end_date: string, public_visibility: bool}
	 */
	private static function cast_relationship_row( array $row ): array {
		return array(
			'target_post_id'    => Policy::sanitize_integer( $row['target_post_id'] ?? 0 ),
			'relationship_role' => Policy::scalar_string( $row['relationship_role'] ?? '' ),
			'sort_order'        => Policy::sanitize_integer( $row['sort_order'] ?? 0 ),
			'start_date'        => Policy::scalar_string( $row['start_date'] ?? '' ),
			'end_date'          => Policy::scalar_string( $row['end_date'] ?? '' ),
			'public_visibility' => 1 === Policy::sanitize_integer( $row['public_visibility'] ?? 0 ),
		);
	}

	/**
	 * Casts one database reverse-query row.
	 *
	 * @param array<string, mixed> $row Database reverse row.
	 * @return array{source_post_id: int, target_post_id: int, relationship_type: string, relationship_role: string, sort_order: int, start_date: string, end_date: string, public_visibility: bool}
	 */
	private static function cast_reverse_row( array $row ): array {
		return array(
			'source_post_id'    => Policy::sanitize_integer( $row['source_post_id'] ?? 0 ),
			'target_post_id'    => Policy::sanitize_integer( $row['target_post_id'] ?? 0 ),
			'relationship_type' => Policy::scalar_string( $row['relationship_type'] ?? '' ),
			'relationship_role' => Policy::scalar_string( $row['relationship_role'] ?? '' ),
			'sort_order'        => Policy::sanitize_integer( $row['sort_order'] ?? 0 ),
			'start_date'        => Policy::scalar_string( $row['start_date'] ?? '' ),
			'end_date'          => Policy::scalar_string( $row['end_date'] ?? '' ),
			'public_visibility' => 1 === Policy::sanitize_integer( $row['public_visibility'] ?? 0 ),
		);
	}

	/**
	 * Casts one database author row.
	 *
	 * @param array<string, mixed> $row Database author row.
	 * @return array{author_kind: string, author_post_id: int, display_name: string, orcid: string, affiliation: string, author_role: string, sort_order: int, start_date: string, end_date: string, public_visibility: bool}
	 */
	private static function cast_author_row( array $row ): array {
		return array(
			'author_kind'       => Policy::scalar_string( $row['author_kind'] ?? '' ),
			'author_post_id'    => Policy::sanitize_integer( $row['author_post_id'] ?? 0 ),
			'display_name'      => Policy::scalar_string( $row['display_name'] ?? '' ),
			'orcid'             => Policy::scalar_string( $row['orcid'] ?? '' ),
			'affiliation'       => Policy::scalar_string( $row['affiliation'] ?? '' ),
			'author_role'       => Policy::scalar_string( $row['author_role'] ?? '' ),
			'sort_order'        => Policy::sanitize_integer( $row['sort_order'] ?? 0 ),
			'start_date'        => Policy::scalar_string( $row['start_date'] ?? '' ),
			'end_date'          => Policy::scalar_string( $row['end_date'] ?? '' ),
			'public_visibility' => 1 === Policy::sanitize_integer( $row['public_visibility'] ?? 0 ),
		);
	}

	/** Starts a transaction when the active database adapter supports it. */
	private static function begin_transaction(): bool {
		return false !== self::database()->query( 'START TRANSACTION' );
	}

	/**
	 * Commits a transaction started by this service.
	 *
	 * @param bool $started Whether START TRANSACTION succeeded.
	 */
	private static function commit_transaction( bool $started ): void {
		if ( $started ) {
			self::database()->query( 'COMMIT' );
		}
	}

	/**
	 * Rolls back a transaction started by this service.
	 *
	 * @param bool $started Whether START TRANSACTION succeeded.
	 */
	private static function rollback_transaction( bool $started ): void {
		if ( $started ) {
			self::database()->query( 'ROLLBACK' );
		}
	}

	/**
	 * Returns the initialized WordPress database adapter.
	 *
	 * @throws \RuntimeException When WordPress has not initialized wpdb.
	 */
	private static function database(): \wpdb {
		global $wpdb;
		if ( ! $wpdb instanceof \wpdb ) {
			throw new \RuntimeException( 'WordPress database adapter is unavailable.' );
		}
		return $wpdb;
	}

	/**
	 * Builds one typed boundary error.
	 *
	 * @param string               $code    Stable machine error code.
	 * @param string               $message Human-readable boundary message.
	 * @param string               $field   Machine field name.
	 * @param array<string, mixed> $context Additional machine-readable context.
	 */
	private static function error( string $code, string $message, string $field, array $context = array() ): WP_Error {
		return new WP_Error(
			$code,
			$message,
			array_merge(
				array(
					'status' => 400,
					'field'  => $field,
				),
				$context
			)
		);
	}

	/**
	 * Builds a typed storage error without swallowing the database observable.
	 *
	 * @param string $database_error Raw database error.
	 */
	private static function storage_error( string $database_error ): WP_Error {
		return self::error( 'lps_relationship_storage_error', 'The canonical relationship store rejected the write.', 'relationships', array( 'database_error' => $database_error ) );
	}
}
// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
