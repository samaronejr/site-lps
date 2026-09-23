<?php
/**
 * Local rights-cleared migration asset staging.
 *
 * @package LPS\ContentModel
 */

declare(strict_types=1);

namespace LPS\ContentModel;

use WP_Error;

/** Copies only validated local assets and keys replay by checksum. */
final class MediaStager {
	/**
	 * Stages a single asset.
	 *
	 * @param array<string, mixed> $asset Validated media row.
	 * @param string               $assets_dir Asset directory.
	 * @return array{attachment_id: int, changed: bool, created_file: string}|WP_Error
	 */
	public static function stage( array $asset, string $assets_dir ): array|WP_Error {
		$checksum = preg_replace( '/^sha256:/', '', self::text( $asset['checksum'] ?? '' ) ) ?? '';
		$existing = self::attachment_id( $checksum );
		if ( 0 < $existing ) {
			return array(
				'attachment_id' => $existing,
				'changed'       => false,
				'created_file'  => '',
			);
		}
		$source = rtrim( $assets_dir, '/' ) . '/' . basename( self::text( $asset['path'] ?? '' ) );
		$upload = wp_upload_dir();
		if ( ! empty( $upload['error'] ) ) {
			return new WP_Error( 'lps_media_stage_directory_unavailable', 'The WordPress upload directory is unavailable.' );
		}
		$directory = rtrim( $upload['basedir'], '/' ) . '/lps-import';
		if ( ! wp_mkdir_p( $directory ) ) {
			return new WP_Error( 'lps_media_stage_directory_unavailable', 'The migration asset directory could not be created.' );
		}
		$filename = substr( $checksum, 0, 16 ) . '-' . sanitize_file_name( basename( $source ) );
		$target   = $directory . '/' . $filename;
		if ( ! copy( $source, $target ) ) {
			return new WP_Error( 'lps_media_stage_copy_failed', 'The validated local asset could not be staged.' );
		}
		$url           = rtrim( (string) $upload['baseurl'], '/' ) . '/lps-import/' . rawurlencode( $filename );
		$attachment_id = wp_insert_attachment(
			array(
				'post_title'     => self::text( $asset['title'] ?? $filename ),
				'post_status'    => 'inherit',
				'post_mime_type' => self::text( $asset['media_type'] ?? '' ),
				'guid'           => $url,
			),
			$target,
			0,
			true
		);
		if ( $attachment_id instanceof WP_Error ) {
			wp_delete_file( $target );
			return $attachment_id;
		}
		$alt_text = self::text( $asset['alt_text'] ?? '' );
		if ( '' !== $alt_text ) {
			update_post_meta( (int) $attachment_id, '_wp_attachment_image_alt', $alt_text );
		}
		$meta = array(
			'_lps_media_checksum'            => $checksum,
			'_lps_media_source_url'          => self::text( $asset['source_url'] ?? '' ),
			'_lps_media_rights_status'       => self::text( $asset['rights_status'] ?? '' ),
			'_lps_media_rights_holder'       => self::text( $asset['rights_holder'] ?? '' ),
			'_lps_media_credit'              => self::text( $asset['credit'] ?? '' ),
			'_lps_media_license'             => self::text( $asset['license'] ?? '' ),
			'_lps_media_privacy_status'      => self::text( $asset['privacy_status'] ?? '' ),
			'_lps_media_import_source_id'    => self::text( $asset['source_id'] ?? '' ),
			'_lps_media_import_captured_at'  => self::text( $asset['captured_at'] ?? '' ),
			'_lps_media_import_locale'       => self::text( $asset['locale'] ?? '' ),
			'_lps_media_import_review_state' => self::text( $asset['review_state'] ?? '' ),
		);
		foreach ( $meta as $key => $value ) {
			update_post_meta( (int) $attachment_id, $key, $value );
		}
		return array(
			'attachment_id' => (int) $attachment_id,
			'changed'       => true,
			'created_file'  => $target,
		);
	}

	/**
	 * Returns a staged attachment by normalized checksum.
	 *
	 * @param string $checksum Checksum.
	 */
	private static function attachment_id( string $checksum ): int {
		if ( '' === $checksum ) {
			return 0;
		}
		$ids = get_posts(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'orderby'        => 'ID',
				'order'          => 'ASC',
			)
		);
		foreach ( $ids as $id ) {
			$attachment_id = Policy::sanitize_integer( $id );
			$stored        = self::text( get_post_meta( $attachment_id, '_lps_media_checksum', true ) );
			if ( '' !== $stored && hash_equals( $stored, $checksum ) ) {
				return $attachment_id;
			}
		}
		return 0;
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
