<?php
/**
 * Canonical deterministic migration export and reconciliation.
 *
 * @package LPS\ContentModel
 */

declare(strict_types=1);

namespace LPS\ContentModel;

use WP_Post;

/**
 * Exports domain identities rather than environment-specific WordPress IDs.
 *
 * @phpstan-type ExportRecord array<string, mixed>
 * @phpstan-type ExportRelation array{source: string, type: string, target: string, role: string, order: int, start_date: string, end_date: string, public_visibility: bool}
 * @phpstan-type ExportAuthor array{kind: string, person: string, display_name: string, orcid: string, affiliation: string, role: string, order: int, start_date: string, end_date: string, public_visibility: bool}
 * @phpstan-type ExportAuthorship array{publication: string, authors: list<ExportAuthor>}
 * @phpstan-type ExportRedirect array{source: string, target: string, gone: bool, status: int}
 * @phpstan-type ExportData array{schema_version: string, records: list<ExportRecord>, relationships: list<ExportRelation>, authorships: list<ExportAuthorship>, media: list<array<string, string>>, redirects: list<ExportRedirect>}
 */
final class Exporter {
	/**
	 * Returns canonical export data.
	 *
	 * @return array Export data.
	 * @phpstan-return ExportData
	 */
	public static function data(): array {
		$candidates = get_posts(
			array(
				'post_type'      => array_keys( Contracts::post_types() ),
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'orderby'        => 'ID',
				'order'          => 'ASC',
			)
		);
		$posts      = array();
		foreach ( $candidates as $post ) {
			// The block editor echoes every REST-exposed meta back on save, which
			// stamps an empty `_lps_import_source_id` on editor-touched records;
			// only a non-empty source id marks a corpus-imported record.
			if ( '' !== self::meta( $post->ID, '_lps_import_source_id' ) ) {
				$posts[] = $post;
			}
		}
		usort(
			$posts,
			static fn ( WP_Post $left, WP_Post $right ): int => array( self::meta( $left->ID, '_lps_import_source_id' ), $left->ID ) <=> array( self::meta( $right->ID, '_lps_import_source_id' ), $right->ID )
		);
		$records       = array();
		$relationships = array();
		$authorships   = array();
		$redirects     = array();
		$id_map        = array();
		foreach ( $posts as $post ) {
			$id_map[ $post->ID ] = self::meta( $post->ID, '_lps_record_id' );
		}
		foreach ( $posts as $post ) {
			$records[] = self::record( $post );
			foreach ( array_keys( RelationshipPolicy::relation_specs() ) as $type ) {
				foreach ( Relationships::for_source( $post->ID, $type ) as $row ) {
					$relationships[] = array(
						'source'            => $id_map[ $post->ID ],
						'type'              => $type,
						'target'            => $id_map[ $row['target_post_id'] ] ?? '',
						'role'              => $row['relationship_role'],
						'order'             => $row['sort_order'],
						'start_date'        => $row['start_date'],
						'end_date'          => $row['end_date'],
						'public_visibility' => $row['public_visibility'],
					);
				}
			}
			if ( 'lps_publication' === $post->post_type ) {
				$authors = array();
				foreach ( Relationships::authors_for_publication( $post->ID ) as $author ) {
					$authors[] = array(
						'kind'              => $author['author_kind'],
						'person'            => $id_map[ $author['author_post_id'] ] ?? '',
						'display_name'      => $author['display_name'],
						'orcid'             => $author['orcid'],
						'affiliation'       => $author['affiliation'],
						'role'              => $author['author_role'],
						'order'             => $author['sort_order'],
						'start_date'        => $author['start_date'],
						'end_date'          => $author['end_date'],
						'public_visibility' => $author['public_visibility'],
					);
				}
				if ( array() !== $authors ) {
					$authorships[] = array(
						'publication' => $id_map[ $post->ID ],
						'authors'     => $authors,
					);
				}
			}
			if ( 'lps_redirect' === $post->post_type ) {
				$redirects[] = array(
					'source' => self::meta( $post->ID, '_lps_redirect_source' ),
					'target' => self::meta( $post->ID, '_lps_redirect_target' ),
					'gone'   => (bool) get_post_meta( $post->ID, '_lps_redirect_gone', true ),
					'status' => Policy::sanitize_integer( get_post_meta( $post->ID, '_lps_redirect_status', true ) ),
				);
			}
		}
		usort( $relationships, static fn ( array $left, array $right ): int => array( $left['source'], $left['type'], $left['order'] ) <=> array( $right['source'], $right['type'], $right['order'] ) );
		usort( $redirects, static fn ( array $left, array $right ): int => $left['source'] <=> $right['source'] );
		return array(
			'schema_version' => '1.0',
			'records'        => $records,
			'relationships'  => $relationships,
			'authorships'    => $authorships,
			'media'          => self::media(),
			'redirects'      => $redirects,
		);
	}

	/**
	 * Returns stable counts and content hash.
	 *
	 * @return array{counts: array{records: int, relationships: int, authorships: int, media: int, redirects: int}, hash: string}
	 */
	public static function summary(): array {
		$data = self::data();
		return array(
			'counts' => array(
				'records'       => count( $data['records'] ),
				'relationships' => count( $data['relationships'] ),
				'authorships'   => array_sum( array_map( static fn ( array $group ): int => count( $group['authors'] ), $data['authorships'] ) ),
				'media'         => count( $data['media'] ),
				'redirects'     => count( $data['redirects'] ),
			),
			'hash'   => MigrationPolicy::fingerprint( $data ),
		);
	}

	/**
	 * Returns one canonical record.
	 *
	 * @param WP_Post $post Post.
	 * @return array Export record.
	 * @phpstan-return ExportRecord
	 */
	private static function record( WP_Post $post ): array {
		$meta = array();
		foreach ( array_keys( Contracts::meta_fields()[ $post->post_type ] ) as $key ) {
			if ( in_array( $key, array( '_lps_created_at', '_lps_updated_at', '_lps_archived_at', '_lps_translation_reviewed_at', '_lps_verified_at' ), true ) || str_starts_with( $key, '_lps_import_' ) || '_lps_record_id' === $key ) {
				continue;
			}
			$value = get_post_meta( $post->ID, $key, true );
			if ( '' !== $value && array() !== $value ) {
				$meta[ $key ] = $value;
			}
		}
		ksort( $meta );
		$reviewed_fields = get_post_meta( $post->ID, '_lps_import_reviewed_fields', true );
		return array(
			'source_id'       => self::meta( $post->ID, '_lps_import_source_id' ),
			'record_id'       => self::meta( $post->ID, '_lps_record_id' ),
			'type'            => $post->post_type,
			'title'           => $post->post_title,
			'slug'            => $post->post_name,
			'content'         => $post->post_content,
			'excerpt'         => $post->post_excerpt,
			'source_url'      => self::meta( $post->ID, '_lps_import_source_url' ),
			'captured_at'     => self::meta( $post->ID, '_lps_import_captured_at' ),
			'checksum'        => self::meta( $post->ID, '_lps_import_checksum' ),
			'rights'          => self::meta( $post->ID, '_lps_import_rights' ),
			'review_state'    => self::meta( $post->ID, '_lps_import_review_state' ),
			'reviewed_fields' => is_array( $reviewed_fields ) ? $reviewed_fields : array(),
			'meta'            => $meta,
		);
	}

	/**
	 * Returns imported media.
	 *
	 * @return array<int, array<string, string>> Rows.
	 * @phpstan-return list<array<string, string>>
	 */
	private static function media(): array {
		$candidates = get_posts(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'orderby'        => 'ID',
				'order'          => 'ASC',
			)
		);
		$ids        = array();
		foreach ( $candidates as $candidate ) {
			$attachment_id = Policy::sanitize_integer( $candidate );
			// Same echo-back hazard as record source ids: only a non-empty
			// value marks a corpus-imported attachment.
			if ( '' !== self::meta( $attachment_id, '_lps_media_import_source_id' ) ) {
				$ids[] = $attachment_id;
			}
		}
		usort(
			$ids,
			static fn ( int $left, int $right ): int => array( self::meta( $left, '_lps_media_import_source_id' ), $left ) <=> array( self::meta( $right, '_lps_media_import_source_id' ), $right )
		);
		$media = array();
		foreach ( $ids as $attachment_id ) {
			$media[] = array(
				'source_id'     => self::meta( $attachment_id, '_lps_media_import_source_id' ),
				'checksum'      => 'sha256:' . self::meta( $attachment_id, '_lps_media_checksum' ),
				'source_url'    => self::meta( $attachment_id, '_lps_media_source_url' ),
				'captured_at'   => self::meta( $attachment_id, '_lps_media_import_captured_at' ),
				'rights_status' => self::meta( $attachment_id, '_lps_media_rights_status' ),
				'rights_holder' => self::meta( $attachment_id, '_lps_media_rights_holder' ),
				'license'       => self::meta( $attachment_id, '_lps_media_license' ),
				'locale'        => self::meta( $attachment_id, '_lps_media_import_locale' ),
				'review_state'  => self::meta( $attachment_id, '_lps_media_import_review_state' ),
			);
		}
		return $media;
	}

	/**
	 * Returns scalar post metadata.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $key     Metadata key.
	 */
	private static function meta( int $post_id, string $key ): string {
		$value = get_post_meta( $post_id, $key, true );
		return is_scalar( $value ) ? (string) $value : '';
	}
}
