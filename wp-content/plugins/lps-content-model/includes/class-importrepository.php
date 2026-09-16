<?php
/**
 * WordPress persistence seam for deterministic migration records.
 *
 * @package LPS\ContentModel
 */

declare(strict_types=1);

namespace LPS\ContentModel;

use WP_Error;
use WP_Post;

/** Resolves and writes records by immutable domain identity. */
final class ImportRepository {
	/**
	 * Returns a post ID by immutable record ID.
	 *
	 * @param string $record_id Record ID.
	 */
	public static function post_id( string $record_id ): int {
		if ( '' === $record_id ) {
			return 0;
		}
		$ids = get_posts(
			array(
				'post_type'      => array_keys( Contracts::post_types() ),
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'orderby'        => 'ID',
				'order'          => 'ASC',
			)
		);
		foreach ( $ids as $id ) {
			$post_id = Policy::sanitize_integer( $id );
			$stored  = self::text( get_post_meta( $post_id, '_lps_record_id', true ) );
			if ( '' !== $stored && hash_equals( $stored, $record_id ) ) {
				return $post_id;
			}
		}
		return 0;
	}

	/**
	 * Returns storage values used for reviewed-field conflict checks.
	 *
	 * @param int                $post_id Post ID.
	 * @param array<int, string> $fields  Candidate fields.
	 * @phpstan-param list<string> $fields
	 * @return array<string, mixed>
	 */
	public static function values( int $post_id, array $fields ): array {
		$post = get_post( $post_id );
		if ( ! $post instanceof WP_Post ) {
			return array();
		}
		$values = array();
		foreach ( $fields as $field ) {
			$values[ $field ] = match ( $field ) {
				'post_title' => $post->post_title,
				'post_content' => $post->post_content,
				'post_excerpt' => $post->post_excerpt,
				'post_name' => $post->post_name,
				default => get_post_meta( $post_id, $field, true ),
			};
		}
		return $values;
	}

	/**
	 * Inserts or updates one preflighted record.
	 *
	 * @param array<string, mixed> $record Normalized record.
	 * @return array{post_id: int, changed: bool}|WP_Error
	 */
	public static function save( array $record ): array|WP_Error {
		$record_id   = self::text( $record['record_id'] ?? '' );
		$post_id     = self::post_id( $record_id );
		$fingerprint = MigrationPolicy::fingerprint( $record );
		if ( 0 < $post_id && hash_equals( self::text( get_post_meta( $post_id, '_lps_import_fingerprint', true ) ), $fingerprint ) ) {
			return array(
				'post_id' => $post_id,
				'changed' => false,
			);
		}
		$post = array(
			'post_type'    => self::text( $record['type'] ?? '' ),
			'post_status'  => 'draft',
			'post_title'   => self::text( $record['title'] ?? '' ),
			'post_name'    => sanitize_title( self::text( $record['slug'] ?? $record_id ) ),
			'post_content' => self::text( $record['content'] ?? '' ),
			'post_excerpt' => self::text( $record['excerpt'] ?? '' ),
		);
		if ( 0 < $post_id ) {
			$post['ID'] = $post_id;
		} else {
			$post['meta_input'] = array( '_lps_record_id' => $record_id );
		}
		$saved = wp_insert_post( $post, true );
		if ( $saved instanceof WP_Error ) {
			return $saved;
		}
		$post_id                             = (int) $saved;
		$meta                                = is_array( $record['meta'] ?? null ) ? $record['meta'] : array();
		$meta['_lps_record_id']              = $record_id;
		$meta['_lps_locale']                 = self::text( $record['locale'] ?? 'und' );
		$meta['_lps_state']                  = self::text( $record['state'] ?? 'draft' );
		$meta['_lps_import_source_id']       = self::text( $record['source_id'] ?? '' );
		$meta['_lps_import_source_url']      = self::text( $record['source_url'] ?? '' );
		$meta['_lps_import_captured_at']     = self::text( $record['captured_at'] ?? '' );
		$meta['_lps_import_checksum']        = self::text( $record['checksum'] ?? '' );
		$meta['_lps_import_rights']          = self::text( $record['rights'] ?? '' );
		$meta['_lps_import_review_state']    = self::text( $record['review_state'] ?? '' );
		$meta['_lps_import_reviewed_fields'] = is_array( $record['reviewed_fields'] ?? null ) ? $record['reviewed_fields'] : array();
		$meta['_lps_import_fingerprint']     = $fingerprint;
		foreach ( $meta as $key => $value ) {
			update_post_meta( $post_id, (string) $key, $value );
		}
		if ( 'lps_publication' === $post['post_type'] ) {
			$doi = Relationships::set_doi( $post_id, $meta['_lps_doi'] ?? '' );
			if ( $doi instanceof WP_Error ) {
				return $doi;
			}
		}
		return array(
			'post_id' => $post_id,
			'changed' => true,
		);
	}

	/**
	 * Converts standalone redirects into canonical content records.
	 *
	 * @param array<int, array<string, mixed>> $redirects Redirect rows.
	 * @return array<int, array<string, mixed>>
	 */
	public static function redirect_records( array $redirects ): array {
		$records = array();
		foreach ( $redirects as $redirect ) {
			$source    = RedirectPolicy::source( self::text( $redirect['source'] ?? '' ) );
			$gone      = true === ( $redirect['gone'] ?? false ) || 410 === Policy::sanitize_integer( $redirect['status'] ?? 0 );
			$hash      = hash( 'sha256', $source );
			$uuid      = substr( $hash, 0, 8 ) . '-' . substr( $hash, 8, 4 ) . '-4' . substr( $hash, 13, 3 ) . '-8' . substr( $hash, 17, 3 ) . '-' . substr( $hash, 20, 12 );
			$records[] = array(
				'source_id'    => 'redirect:' . $source,
				'type'         => 'lps_redirect',
				'record_id'    => 'lps:redirect:' . $uuid,
				'title'        => 'Redirect ' . $source,
				'slug'         => 'redirect-' . substr( hash( 'sha256', $source ), 0, 12 ),
				'locale'       => 'pt-br',
				'state'        => 'draft',
				'excerpt'      => self::text( $redirect['reason'] ?? '' ),
				'content'      => self::text( $redirect['reason'] ?? '' ),
				'source_url'   => self::text( $redirect['provenance'] ?? '' ),
				'captured_at'  => self::text( $redirect['captured_at'] ?? '' ),
				'checksum'     => 'sha256:' . hash( 'sha256', wp_json_encode( $redirect ) ? wp_json_encode( $redirect ) : '' ),
				'rights'       => 'public-record',
				'review_state' => 'reviewed',
				'meta'         => array(
					'_lps_redirect_source'     => $source,
					'_lps_redirect_target'     => $gone ? '' : RedirectPolicy::target( self::text( $redirect['target'] ?? '' ), home_url( '/' ) ),
					'_lps_redirect_gone'       => $gone,
					'_lps_redirect_status'     => $gone ? 410 : 301,
					'_lps_redirect_reason'     => self::text( $redirect['reason'] ?? '' ),
					'_lps_redirect_provenance' => self::text( $redirect['provenance'] ?? '' ),
				),
			);
		}
		return $records;
	}

	/**
	 * Converts a boundary scalar.
	 *
	 * @param mixed $value Value.
	 */
	private static function text( mixed $value ): string {
		return is_scalar( $value ) ? trim( (string) $value ) : '';
	}
}
