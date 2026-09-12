<?php
/**
 * Governed upload boundary.
 *
 * @package LPS\ContentModel
 */

declare(strict_types=1);

namespace LPS\ContentModel;

require_once __DIR__ . '/class-hardening.php';

/** Enforces file allowlists and records immutable technical metadata. */
final class MediaUploads {
	/** Registers upload hooks. */
	public static function boot(): void {
		add_filter( 'upload_mimes', array( self::class, 'allowed_mimes' ) );
		add_filter( 'wp_handle_upload_prefilter', array( self::class, 'validate_upload' ) );
		add_filter( 'wp_handle_sideload_prefilter', array( self::class, 'validate_upload' ) );
		add_filter( 'rest_pre_dispatch', array( self::class, 'validate_rest_upload' ), 10, 3 );
		add_action( 'add_attachment', array( self::class, 'record_attachment_metadata' ) );
		add_filter( 'wp_generate_attachment_metadata', array( self::class, 'record_generated_metadata' ), 10, 2 );
	}

	/**
	 * Returns the complete upload MIME allowlist.
	 *
	 * @return array<string, string>
	 */
	public static function allowed_mimes(): array {
		return array(
			'jpg|jpeg' => 'image/jpeg',
			'png'      => 'image/png',
			'webp'     => 'image/webp',
			'avif'     => 'image/avif',
			'mp4'      => 'video/mp4',
			'webm'     => 'video/webm',
			'mp3'      => 'audio/mpeg',
			'ogg|oga'  => 'audio/ogg',
			'pdf'      => 'application/pdf',
			'vtt'      => 'text/vtt',
		);
	}

	/**
	 * Validates a temporary upload before WordPress moves it.
	 *
	 * @param array<string, mixed> $file Upload boundary values.
	 * @return array<string, mixed>
	 */
	public static function validate_upload( array $file ): array {
		$tmp_name = is_string( $file['tmp_name'] ?? null ) ? $file['tmp_name'] : '';
		$name     = is_string( $file['name'] ?? null ) ? $file['name'] : '';
		if ( ! Hardening::safe_upload_name( $name ) ) {
			$file['error'] = self::message( 'lps_media_executable_name_forbidden' );
			return $file;
		}
		$checked = wp_check_filetype_and_ext( $tmp_name, $name, self::allowed_mimes() );
		$mime    = is_string( $checked['type'] ) ? $checked['type'] : '';
		$bytes   = is_file( $tmp_name ) ? (int) filesize( $tmp_name ) : 0;
		$width   = 0;
		$height  = 0;
		if ( str_starts_with( $mime, 'image/' ) ) {
			$dimensions = getimagesize( $tmp_name );
			$width      = is_array( $dimensions ) ? $dimensions[0] : 0;
			$height     = is_array( $dimensions ) ? $dimensions[1] : 0;
		}
		$error = MediaPolicy::upload_error( $mime, $bytes, $width, $height );
		if ( null !== $error ) {
			$file['error'] = self::message( $error );
			return $file;
		}
		if ( in_array( $mime, array( 'application/pdf', 'text/vtt' ), true ) ) {
			$document = new \SplFileObject( $tmp_name, 'r' );
			$content  = $document->fread( $bytes );
			if ( false === $content ) {
				$file['error'] = self::message( 'lps_media_read_failed' );
				return $file;
			}
			if ( ! Hardening::safe_document( $mime, $content ) ) {
				$file['error'] = self::message( 'lps_media_active_document_forbidden' );
				return $file;
			}
		}
		$checksum = hash_file( 'sha256', $tmp_name );
		if ( false === $checksum || null !== MediaPolicy::checksum_error( $checksum, self::stored_checksums() ) ) {
			$file['error'] = self::message( 'lps_media_duplicate_checksum' );
		}
		return $file;
	}

	/**
	 * Returns typed REST upload errors before file mutation.
	 *
	 * @param mixed            $result Prior dispatch result.
	 * @param mixed            $server REST server.
	 * @param \WP_REST_Request $request REST request.
	 */
	public static function validate_rest_upload( mixed $result, mixed $server, \WP_REST_Request $request ): mixed {
		unset( $server );
		if ( $result instanceof \WP_Error || 'POST' !== $request->get_method() || ! str_starts_with( $request->get_route(), '/wp/v2/media' ) ) {
			return $result;
		}
		$files = $request->get_file_params();
		$file  = $files['file'] ?? null;
		if ( ! is_array( $file ) ) {
			return $result;
		}
		$upload = array();
		foreach ( $file as $key => $value ) {
			if ( is_string( $key ) ) {
				$upload[ $key ] = $value;
			}
		}
		$checked = self::validate_upload( $upload );
		$error   = is_string( $checked['error'] ?? null ) ? $checked['error'] : '';
		if ( '' === $error || 1 !== preg_match( '/^\[([a-z0-9_]+)\]/', $error, $matches ) ) {
			return $result;
		}
		return new \WP_Error( $matches[1], $error, array( 'status' => 400 ) );
	}

	/**
	 * Records immutable technical metadata after attachment creation.
	 *
	 * @param int $attachment_id Attachment ID.
	 */
	public static function record_attachment_metadata( int $attachment_id ): void {
		$file = get_attached_file( $attachment_id );
		if ( ! is_string( $file ) || ! is_file( $file ) ) {
			return;
		}
		$checksum = hash_file( 'sha256', $file );
		if ( is_string( $checksum ) ) {
			update_post_meta( $attachment_id, '_lps_media_checksum', $checksum );
		}
		update_post_meta( $attachment_id, '_lps_media_rights_status', 'unknown' );
		update_post_meta( $attachment_id, '_lps_media_privacy_status', 'pending' );
		update_post_meta( $attachment_id, '_lps_media_transcript_status', 'missing' );
		update_post_meta( $attachment_id, '_lps_media_caption_status', 'missing' );
		update_post_meta( $attachment_id, '_lps_media_document_status', 'unknown' );
	}

	/**
	 * Records generated dimensions and duration.
	 *
	 * @param array<string, mixed> $metadata Generated metadata.
	 * @param int                  $attachment_id Attachment ID.
	 * @return array<string, mixed>
	 */
	public static function record_generated_metadata( array $metadata, int $attachment_id ): array {
		foreach ( array(
			'width'  => '_lps_media_width',
			'height' => '_lps_media_height',
			'length' => '_lps_media_duration',
		) as $source => $target ) {
			if ( isset( $metadata[ $source ] ) && is_numeric( $metadata[ $source ] ) ) {
				update_post_meta( $attachment_id, $target, $metadata[ $source ] );
			}
		}
		return $metadata;
	}

	/**
	 * Returns stored attachment checksums.
	 *
	 * @return array<int, string>
	 */
	private static function stored_checksums(): array {
		$values    = get_posts(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'fields'         => 'ids',
			)
		);
		$checksums = array();
		foreach ( $values as $attachment_id ) {
			$value = get_post_meta( (int) $attachment_id, '_lps_media_checksum', true );
			if ( is_string( $value ) && '' !== $value ) {
				$checksums[] = $value;
			}
		}
		return $checksums;
	}

	/**
	 * Builds an actionable upload error message.
	 *
	 * @param string $code Typed error code.
	 */
	private static function message( string $code ): string {
		return sprintf( '[%s] %s', $code, __( 'This upload does not satisfy the LPS media security and accessibility contract.', 'lps-content-model' ) );
	}
}
