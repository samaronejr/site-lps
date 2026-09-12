<?php
/**
 * Atomic idempotent migration application service.
 *
 * @package LPS\ContentModel
 */

declare(strict_types=1);

namespace LPS\ContentModel;

use WP_Error;
use wpdb;

/**
 * Applies only a complete ready plan and reports exact writes.
 *
 * @phpstan-import-type ImportPackageShape from ImportPackage
 */
final class Importer {
	/**
	 * Applies a preflighted package.
	 *
	 * @param array  $package Parsed package.
	 * @phpstan-param ImportPackageShape $package Parsed package.
	 * @param string $assets_dir Local asset directory.
	 * @return array{status: string, package_hash: string, writes: int, record_writes: int, relationship_writes: int, media_writes: int, counts: array<string, int>}|WP_Error
	 */
	public static function apply( array $package, string $assets_dir ): array|WP_Error {
		$plan = ImportPlanner::plan( $package, $assets_dir );
		if ( 'ready' !== $plan['status'] ) {
			return new WP_Error(
				'lps_import_preflight_blocked',
				'Import apply requires a ready plan.',
				array(
					'status' => 409,
					'plan'   => $plan,
				)
			);
		}
		$database = self::database();
		$database->query( 'START TRANSACTION' );
		$created_files   = array();
		$writes          = 0;
		$ids             = array();
		$record_writes   = 0;
		$relation_writes = 0;
		$media_writes    = 0;
		foreach ( $plan['records'] as $record ) {
			$result = ImportRepository::save( $record );
			if ( $result instanceof WP_Error ) {
				return self::rollback( $database, $created_files, $result );
			}
			$ids[ self::text( $record['record_id'] ?? '' ) ] = $result['post_id'];
			if ( $result['changed'] ) {
				++$writes;
				++$record_writes;
			}
		}
		$relation_result = self::save_relationships( $package, $ids );
		if ( $relation_result instanceof WP_Error ) {
			return self::rollback( $database, $created_files, $relation_result );
		}
		$writes          += $relation_result['writes'];
		$relation_writes += $relation_result['writes'];
		$media            = $package['media'];
		foreach ( $media as $asset ) {
			$result = MediaStager::stage( $asset, $assets_dir );
			if ( $result instanceof WP_Error ) {
				return self::rollback( $database, $created_files, $result );
			}
			if ( '' !== $result['created_file'] ) {
				$created_files[] = $result['created_file'];
			}
			if ( $result['changed'] ) {
				++$writes;
				++$media_writes;
			}
		}
		$database->query( 'COMMIT' );
		if ( 0 < $writes ) {
			Audit::record(
				'import',
				0,
				0,
				array(
					'package_hash' => $plan['package_hash'],
					'writes'       => $writes,
				)
			);
		}
		return array(
			'status'              => 'applied',
			'package_hash'        => $plan['package_hash'],
			'writes'              => $writes,
			'record_writes'       => $record_writes,
			'relationship_writes' => $relation_writes,
			'media_writes'        => $media_writes,
			'counts'              => $plan['counts'],
		);
	}

	/**
	 * Writes every canonical relation and authorship group.
	 *
	 * @param array              $package Parsed package.
	 * @phpstan-param ImportPackageShape $package Parsed package.
	 * @param array<string, int> $ids Record ID map.
	 * @return array{writes: int}|WP_Error
	 */
	private static function save_relationships( array $package, array $ids ): array|WP_Error {
		$groups        = array();
		$relationships = $package['relationships'];
		foreach ( $relationships as $row ) {
			$source_key = self::text( $row['source'] ?? '' );
			$target_key = self::text( $row['target'] ?? '' );
			$source_id  = $ids[ $source_key ] ?? ImportRepository::post_id( $source_key );
			$target_id  = $ids[ $target_key ] ?? ImportRepository::post_id( $target_key );
			$type       = self::text( $row['type'] ?? '' );
			$groups[ $source_id . '|' . $type ]['source_id'] = $source_id;
			$groups[ $source_id . '|' . $type ]['type']      = $type;
			$groups[ $source_id . '|' . $type ]['rows'][]    = array(
				'target_post_id'    => $target_id,
				'relationship_role' => self::text( $row['role'] ?? '' ),
				'sort_order'        => Policy::sanitize_integer( $row['order'] ?? 0 ),
				'start_date'        => self::text( $row['start_date'] ?? '' ),
				'end_date'          => self::text( $row['end_date'] ?? '' ),
				'public_visibility' => ! array_key_exists( 'public_visibility', $row ) || true === $row['public_visibility'],
			);
		}
		$writes = 0;
		foreach ( $groups as $group ) {
			$result = Relationships::replace( (int) $group['source_id'], (string) $group['type'], $group['rows'] );
			if ( $result instanceof WP_Error ) {
				return $result;
			}
			$writes += $result['changed'] ? 1 : 0;
		}
		$authorships = $package['authorships'];
		foreach ( $authorships as $group ) {
			$publication_key = self::text( $group['publication'] ?? '' );
			$publication_id  = $ids[ $publication_key ] ?? ImportRepository::post_id( $publication_key );
			$authors         = array();
			foreach ( is_array( $group['authors'] ?? null ) ? $group['authors'] : array() as $author ) {
				if ( ! is_array( $author ) ) {
					continue;
				}
				$person_key = self::text( $author['person'] ?? '' );
				$authors[]  = array(
					'author_kind'       => self::text( $author['kind'] ?? '' ),
					'author_post_id'    => '' === $person_key ? 0 : ( $ids[ $person_key ] ?? ImportRepository::post_id( $person_key ) ),
					'display_name'      => self::text( $author['display_name'] ?? '' ),
					'orcid'             => self::text( $author['orcid'] ?? '' ),
					'affiliation'       => self::text( $author['affiliation'] ?? '' ),
					'author_role'       => self::text( $author['role'] ?? 'author' ),
					'sort_order'        => Policy::sanitize_integer( $author['order'] ?? 0 ),
					'start_date'        => self::text( $author['start_date'] ?? '' ),
					'end_date'          => self::text( $author['end_date'] ?? '' ),
					'public_visibility' => ! array_key_exists( 'public_visibility', $author ) || true === $author['public_visibility'],
				);
			}
			$result = Relationships::replace_authors( $publication_id, $authors );
			if ( $result instanceof WP_Error ) {
				return $result;
			}
			$writes += $result['changed'] ? 1 : 0;
		}
		return array( 'writes' => $writes );
	}

	/**
	 * Rolls back storage and staged files.
	 *
	 * @param wpdb               $database Database adapter.
	 * @param array<int, string> $created_files Staged files.
	 * @phpstan-param list<string> $created_files Staged files.
	 * @param WP_Error           $error Original error.
	 */
	private static function rollback( wpdb $database, array $created_files, WP_Error $error ): WP_Error {
		$database->query( 'ROLLBACK' );
		foreach ( $created_files as $file ) {
			if ( is_file( $file ) ) {
				wp_delete_file( $file );
			}
		}
		return $error;
	}

	/**
	 * Returns the WordPress database adapter.
	 *
	 * @throws \RuntimeException When WordPress has no database adapter.
	 */
	private static function database(): wpdb {
		global $wpdb;
		if ( ! $wpdb instanceof wpdb ) {
			throw new \RuntimeException( 'WordPress database adapter is unavailable.' );
		}
		return $wpdb;
	}

	/**
	 * Converts a boundary scalar to text.
	 *
	 * @param mixed $value Value.
	 */
	private static function text( mixed $value ): string {
		return is_scalar( $value ) ? trim( (string) $value ) : '';
	}
}
