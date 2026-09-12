<?php
/**
 * Read-only migration planning and conflict detection.
 *
 * @package LPS\ContentModel
 */

declare(strict_types=1);

namespace LPS\ContentModel;

use WP_Error;

/**
 * Builds a complete mutation plan before apply crosses a write boundary.
 *
 * @phpstan-import-type ImportPackageShape from ImportPackage
 * @phpstan-import-type ImportRecord from ImportPackage
 * @phpstan-type ImportIssue array{code: string, path: string}
 * @phpstan-type ImportConflict array{code: string, field: string, stored: mixed, incoming: mixed, record_id?: string}
 * @phpstan-type ImportPlan array{status: string, package_hash: string, counts: array{records: int, relationships: int, authorship_groups: int, media: int, redirects: int}, errors: list<ImportIssue>, quarantine: list<ImportIssue>, conflicts: list<ImportConflict>, records: list<ImportRecord>}
 */
final class ImportPlanner {
	/**
	 * Creates a deterministic read-only plan.
	 *
	 * @param array  $package Parsed package.
	 * @phpstan-param ImportPackageShape $package Parsed migration package.
	 * @param string $assets_dir Local asset directory.
	 * @return array Import plan.
	 * @phpstan-return ImportPlan
	 */
	public static function plan( array $package, string $assets_dir ): array {
		$package_errors = ImportPackage::errors( $package );
		$errors         = array();
		/**
		 * Issues requiring manual review.
		 *
		 * @var list<ImportIssue> $quarantine
		 */
		$quarantine = array();
		foreach ( $package_errors as $error ) {
			if ( in_array( $error['code'], array( 'lps_person_match_confirmation_required', 'lps_media_rights_unknown', 'lps_media_provenance_required' ), true ) ) {
				$quarantine[] = $error;
			} else {
				$errors[] = $error;
			}
		}
		$conflicts = array();
		$records   = self::records( $package, $quarantine );
		$redirects = self::redirect_rows( $records );
		foreach ( RedirectPolicy::verify( $redirects, home_url( '/' ) ) as $error ) {
			$errors[] = array(
				'code' => $error['code'],
				'path' => 'redirects.' . $error['source'],
			);
		}
		foreach ( $records as $index => $record ) {
			$post_id  = ImportRepository::post_id( self::text( $record['record_id'] ?? '' ) );
			$reviewed = self::strings( $record['reviewed_fields'] ?? null );
			if ( 0 < $post_id ) {
				$stored_reviewed = self::strings( get_post_meta( $post_id, '_lps_import_reviewed_fields', true ) );
				$reviewed        = array_values( array_unique( array_merge( $reviewed, $stored_reviewed ) ) );
				$incoming        = self::record_values( $record, $reviewed );
				foreach ( MigrationPolicy::reviewed_conflicts( ImportRepository::values( $post_id, $reviewed ), $incoming, $reviewed ) as $conflict ) {
					$conflict['record_id'] = self::text( $record['record_id'] ?? '' );
					$conflicts[]           = $conflict;
				}
			}
			if ( 'lps_publication' === ( $record['type'] ?? '' ) ) {
				$meta      = self::object( $record['meta'] ?? null );
				$doi_error = Relationships::doi_error( $post_id, $meta['_lps_doi'] ?? '' );
				if ( $doi_error instanceof WP_Error ) {
					$errors[] = array(
						'code' => (string) $doi_error->get_error_code(),
						'path' => "records.$index.meta._lps_doi",
					);
				}
			}
		}
		$media = $package['media'];
		foreach ( $media as $index => $asset ) {
			$path = rtrim( $assets_dir, '/' ) . '/' . basename( self::text( $asset['path'] ?? '' ) );
			if ( ! is_file( $path ) ) {
				$quarantine[] = array(
					'code' => 'lps_media_local_file_missing',
					'path' => "media.$index.path",
				);
				continue;
			}
			$actual   = hash_file( 'sha256', $path );
			$expected = preg_replace( '/^sha256:/', '', self::text( $asset['checksum'] ?? '' ) ) ?? '';
			if ( ! is_string( $actual ) || ! hash_equals( $expected, $actual ) ) {
				$quarantine[] = array(
					'code' => 'lps_media_checksum_mismatch',
					'path' => "media.$index.checksum",
				);
			}
		}
		$errors = self::unique_errors( $errors );
		$status = array() !== $errors ? 'failed' : ( array() !== $quarantine || array() !== $conflicts ? 'blocked' : 'ready' );
		return array(
			'status'       => $status,
			'package_hash' => MigrationPolicy::fingerprint( $package ),
			'counts'       => array(
				'records'           => count( $records ),
				'relationships'     => count( $package['relationships'] ),
				'authorship_groups' => count( $package['authorships'] ),
				'media'             => count( $media ),
				'redirects'         => count( $redirects ),
			),
			'errors'       => $errors,
			'quarantine'   => $quarantine,
			'conflicts'    => $conflicts,
			'records'      => $records,
		);
	}

	/**
	 * Adds cached Crossref fields and standalone redirect records.
	 *
	 * @param array $package    Parsed package.
	 * @param array $quarantine Quarantine output.
	 * @phpstan-param ImportPackageShape $package
	 * @phpstan-param list<ImportIssue> $quarantine
	 * @return array<int, array<string, mixed>>
	 * @phpstan-return list<ImportRecord>
	 */
	private static function records( array $package, array &$quarantine ): array {
		$records = $package['records'];
		foreach ( $records as $index => &$record ) {
			$cache = self::object( $record['crossref'] ?? null );
			if ( array() === $cache ) {
				continue;
			}
			$crossref = MigrationPolicy::crossref_fields( $cache );
			if ( '' !== $crossref['code'] ) {
				$quarantine[] = array(
					'code' => $crossref['code'],
					'path' => "records.$index.crossref",
				);
				continue;
			}
			$meta = self::object( $record['meta'] ?? null );
			foreach ( $crossref['fields'] as $field => $value ) {
				if ( ! array_key_exists( $field, $meta ) ) {
					$meta[ $field ] = $value;
				}
			}
			$meta['_lps_crossref_fields']    = array_keys( $crossref['fields'] );
			$meta['_lps_crossref_cache_key'] = self::text( $cache['cache_key'] ?? '' );
			$meta['_lps_crossref_cached_at'] = self::text( $cache['fetched_at'] ?? '' );
			$record['meta']                  = $meta;
		}
		unset( $record );
		$redirects = $package['redirects'];
		return array_merge( $records, ImportRepository::redirect_records( $redirects ) );
	}

	/**
	 * Extracts redirect rows.
	 *
	 * @param array<int, array<string, mixed>> $records Records.
	 * @phpstan-param list<ImportRecord> $records
	 * @return array<int, array<string, mixed>> Object rows.
	 * @phpstan-return list<array<string, mixed>>
	 */
	private static function redirect_rows( array $records ): array {
		$rows = array();
		foreach ( $records as $record ) {
			if ( 'lps_redirect' !== ( $record['type'] ?? '' ) ) {
				continue;
			}
			$meta   = self::object( $record['meta'] ?? null );
			$rows[] = array(
				'source' => $meta['_lps_redirect_source'] ?? '',
				'target' => $meta['_lps_redirect_target'] ?? '',
				'gone'   => $meta['_lps_redirect_gone'] ?? false,
				'status' => $meta['_lps_redirect_status'] ?? 0,
			);
		}
		return $rows;
	}

	/**
	 * Reads incoming reviewed values.
	 *
	 * @param array<string, mixed> $record Record.
	 * @phpstan-param ImportRecord $record Record.
	 * @param array<int, string>   $fields Reviewed fields.
	 * @phpstan-param list<string> $fields Reviewed fields.
	 * @return array<string, mixed>
	 */
	private static function record_values( array $record, array $fields ): array {
		$meta   = self::object( $record['meta'] ?? null );
		$values = array();
		foreach ( $fields as $field ) {
			$values[ $field ] = match ( $field ) {
				'post_title' => self::text( $record['title'] ?? '' ),
				'post_content' => self::text( $record['content'] ?? '' ),
				'post_excerpt' => self::text( $record['excerpt'] ?? '' ),
				'post_name' => sanitize_title( self::text( $record['slug'] ?? '' ) ),
				default => $meta[ $field ] ?? null,
			};
		}
		return $values;
	}

	/**
	 * Deduplicates errors.
	 *
	 * @param array<int, array{code: string, path: string}> $errors Errors.
	 * @phpstan-param list<ImportIssue> $errors Errors.
	 * @return list<ImportIssue>
	 */
	private static function unique_errors( array $errors ): array {
		$quarantine_codes = array( 'lps_person_match_confirmation_required', 'lps_media_rights_unknown', 'lps_media_provenance_required' );
		$result           = array();
		foreach ( $errors as $error ) {
			if ( ! in_array( $error['code'], $quarantine_codes, true ) ) {
				$result[ $error['code'] . '|' . $error['path'] ] = $error;
			}
		}
		return array_values( $result );
	}

	/**
	 * Parses a string-keyed object.
	 *
	 * @param mixed $value Boundary value.
	 * @return array<string, mixed>
	 */
	private static function object( mixed $value ): array {
		if ( ! is_array( $value ) ) {
			return array();
		}
		$result = array();
		foreach ( $value as $key => $item ) {
			if ( is_string( $key ) ) {
				$result[ $key ] = $item;
			}
		}
		return $result;
	}

	/**
	 * Parses a scalar list as strings.
	 *
	 * @param mixed $value Boundary value.
	 * @return list<string>
	 */
	private static function strings( mixed $value ): array {
		return is_array( $value ) ? array_values( array_map( array( Policy::class, 'scalar_string' ), $value ) ) : array();
	}

	/**
	 * Converts a boundary scalar to text.
	 *
	 * @param mixed $value Boundary value.
	 */
	private static function text( mixed $value ): string {
		return is_scalar( $value ) ? trim( (string) $value ) : '';
	}
}
