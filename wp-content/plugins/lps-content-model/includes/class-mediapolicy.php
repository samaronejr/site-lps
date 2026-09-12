<?php
/**
 * Rights, privacy, accessibility, and upload policy for media.
 *
 * @package LPS\ContentModel
 */

declare(strict_types=1);

namespace LPS\ContentModel;

/** Pure media boundary used by WordPress adapters and tests. */
final class MediaPolicy {
	private const MIME_LIMITS = array(
		'image/jpeg'      => 12_000_000,
		'image/png'       => 12_000_000,
		'image/webp'      => 12_000_000,
		'image/avif'      => 12_000_000,
		'video/mp4'       => 200_000_000,
		'video/webm'      => 200_000_000,
		'audio/mpeg'      => 50_000_000,
		'audio/ogg'       => 50_000_000,
		'application/pdf' => 25_000_000,
		'text/vtt'        => 2_000_000,
	);

	/**
	 * Returns an upload denial code.
	 *
	 * @param string $mime File MIME type.
	 * @param int    $bytes File size.
	 * @param int    $width Image width.
	 * @param int    $height Image height.
	 */
	public static function upload_error( string $mime, int $bytes, int $width, int $height ): ?string {
		if ( ! isset( self::MIME_LIMITS[ $mime ] ) ) {
			return 'lps_media_mime_forbidden';
		}
		if ( $bytes <= 0 || $bytes > self::MIME_LIMITS[ $mime ] ) {
			return 'lps_media_file_too_large';
		}
		if ( str_starts_with( $mime, 'image/' ) && ( $width <= 0 || $height <= 0 || $width > 12000 || $height > 12000 ) ) {
			return 'lps_media_dimensions_too_large';
		}
		return null;
	}

	/**
	 * Returns a duplicate-checksum denial.
	 *
	 * @param string             $checksum Candidate checksum.
	 * @param array<int, string> $stored_checksums Existing checksums.
	 */
	public static function checksum_error( string $checksum, array $stored_checksums ): ?string {
		return in_array( strtolower( $checksum ), array_map( 'strtolower', $stored_checksums ), true ) ? 'lps_media_duplicate_checksum' : null;
	}

	/**
	 * Returns public attachment-level violations.
	 *
	 * @param array<string, mixed> $asset Attachment values.
	 * @return array<string, string>
	 */
	public static function asset_errors( array $asset ): array {
		$errors   = array();
		$required = array(
			'credit'        => 'lps_media_credit_required',
			'rights_holder' => 'lps_media_rights_holder_required',
			'license'       => 'lps_media_license_required',
			'source_url'    => 'lps_media_source_required',
			'checksum'      => 'lps_media_checksum_required',
		);
		foreach ( $required as $field => $code ) {
			if ( '' === self::string_value( $asset[ $field ] ?? '' ) ) {
				$errors[ $field ] = $code;
			}
		}
		if ( 'cleared' !== ( $asset['rights_status'] ?? '' ) ) {
			$errors['rights_status'] = 'lps_media_rights_unknown';
		}
		if ( ! in_array( $asset['privacy_status'] ?? '', array( 'reviewed', 'not-required' ), true ) ) {
			$errors['privacy_status'] = 'lps_media_privacy_unreviewed';
		}
		if ( null !== self::upload_error( self::string_value( $asset['mime'] ?? '' ), max( 1, Policy::sanitize_integer( $asset['bytes'] ?? 1 ) ), Policy::sanitize_integer( $asset['width'] ?? 0 ), Policy::sanitize_integer( $asset['height'] ?? 0 ) ) ) {
			$mime = self::string_value( $asset['mime'] ?? '' );
			if ( str_starts_with( $mime, 'image/' ) && Policy::sanitize_integer( $asset['width'] ?? 0 ) > 0 && Policy::sanitize_integer( $asset['height'] ?? 0 ) > 0 ) {
				return $errors;
			}
			if ( ! isset( self::MIME_LIMITS[ $mime ] ) ) {
				$errors['mime'] = 'lps_media_mime_forbidden';
			}
		}
		return $errors;
	}

	/**
	 * Returns one localized usage's violations.
	 *
	 * @param array<string, mixed> $usage Contextual use.
	 * @param array<string, mixed> $asset Attachment values.
	 * @return array<string, string>
	 */
	public static function usage_errors( array $usage, array $asset ): array {
		return MediaUsagePolicy::errors( $usage, $asset );
	}

	/**
	 * Returns violations across all media usages in one public record.
	 *
	 * @param array<int, array<string, mixed>> $usages Contextual uses.
	 * @param array<int, array<string, mixed>> $assets Attachments keyed by ID.
	 * @return array<string, string>
	 */
	public static function collection_errors( array $usages, array $assets ): array {
		return MediaUsagePolicy::collection_errors( $usages, $assets );
	}

	/**
	 * Denies cross-origin media sources.
	 *
	 * @param string $source Candidate source.
	 * @param string $site_url Canonical site URL.
	 */
	public static function source_error( string $source, string $site_url ): ?string {
		if ( str_starts_with( $source, '/' ) ) {
			return null;
		}
		$source_host = self::url_host( $source );
		$site_host   = self::url_host( $site_url );
		return '' !== $source_host && '' !== $site_host && hash_equals( strtolower( $site_host ), strtolower( $source_host ) ) ? null : 'lps_media_hotlink_forbidden';
	}

	/**
	 * Validates iframe naming and playback behavior.
	 *
	 * @param string $label Accessible iframe label.
	 * @param bool   $autoplay Whether autoplay was requested.
	 */
	public static function iframe_error( string $label, bool $autoplay ): ?string {
		if ( '' === trim( $label ) ) {
			return 'lps_media_iframe_label_required';
		}
		return $autoplay ? 'lps_media_autoplay_forbidden' : null;
	}

	/**
	 * Renders an image.
	 *
	 * @param array<string, mixed> $usage Contextual use.
	 * @param array<string, mixed> $asset Attachment values.
	 */
	public static function render_image( array $usage, array $asset ): string {
		return MediaRenderer::image( $usage, $asset );
	}

	/**
	 * Renders native video.
	 *
	 * @param array<string, mixed> $usage Contextual use.
	 * @param array<string, mixed> $asset Attachment values.
	 */
	public static function render_video( array $usage, array $asset ): string {
		return MediaRenderer::video( $usage, $asset );
	}

	/**
	 * Renders a local iframe.
	 *
	 * @param array<string, mixed> $usage Contextual use.
	 * @param array<string, mixed> $asset Attachment values.
	 */
	public static function render_iframe( array $usage, array $asset ): string {
		return MediaRenderer::iframe( $usage, $asset );
	}

	/**
	 * Renders an accessible document.
	 *
	 * @param array<string, mixed> $usage Contextual use.
	 * @param array<string, mixed> $asset Attachment values.
	 */
	public static function render_document( array $usage, array $asset ): string {
		return MediaRenderer::document( $usage, $asset );
	}

	/**
	 * Sanitizes a SHA-256 checksum.
	 *
	 * @param mixed $value Boundary value.
	 */
	public static function sanitize_checksum( mixed $value ): string {
		$value = strtolower( trim( self::string_value( $value ) ) );
		return 1 === preg_match( '/^[a-f0-9]{64}$/', $value ) ? $value : '';
	}

	/**
	 * Sanitizes a normalized focal coordinate.
	 *
	 * @param mixed $value Boundary value.
	 */
	public static function sanitize_focal_point( mixed $value ): float {
		return max( 0.0, min( 1.0, is_numeric( $value ) ? (float) $value : 0.5 ) );
	}

	/**
	 * Sanitizes a media duration.
	 *
	 * @param mixed $value Boundary value.
	 */
	public static function sanitize_duration( mixed $value ): float {
		return max( 0.0, is_numeric( $value ) ? (float) $value : 0.0 );
	}

	/**
	 * Sanitizes the rights-review status.
	 *
	 * @param mixed $value Boundary value.
	 */
	public static function sanitize_rights_status( mixed $value ): string {
		return self::enum_value( $value, array( 'unknown', 'cleared', 'restricted' ), 'unknown' );
	}

	/**
	 * Sanitizes the privacy-review status.
	 *
	 * @param mixed $value Boundary value.
	 */
	public static function sanitize_privacy_status( mixed $value ): string {
		return self::enum_value( $value, array( 'pending', 'reviewed', 'not-required' ), 'pending' );
	}

	/**
	 * Sanitizes captions or transcript status.
	 *
	 * @param mixed $value Boundary value.
	 */
	public static function sanitize_access_status( mixed $value ): string {
		return self::enum_value( $value, array( 'missing', 'provided', 'not-required' ), 'missing' );
	}

	/**
	 * Sanitizes accessible-document status.
	 *
	 * @param mixed $value Boundary value.
	 */
	public static function sanitize_document_status( mixed $value ): string {
		return self::enum_value( $value, array( 'unknown', 'accessible', 'html-equivalent', 'inaccessible', 'not-required' ), 'unknown' );
	}

	/**
	 * Converts boundary input to a trimmed scalar string.
	 *
	 * @param mixed $value Boundary value.
	 */
	public static function string_value( mixed $value ): string {
		return is_scalar( $value ) ? trim( (string) $value ) : '';
	}

	/**
	 * Sanitizes an enum value.
	 *
	 * @param mixed              $value Boundary value.
	 * @param array<int, string> $allowed Allowed values.
	 * @param string             $fallback Safe fallback.
	 */
	private static function enum_value( mixed $value, array $allowed, string $fallback ): string {
		$value = self::string_value( $value );
		return in_array( $value, $allowed, true ) ? $value : $fallback;
	}

	/**
	 * Extracts an HTTP URL host deterministically.
	 *
	 * @param string $url HTTP URL.
	 */
	private static function url_host( string $url ): string {
		return 1 === preg_match( '~^https?://([^/:?#]+)~i', $url, $matches ) ? strtolower( $matches[1] ) : '';
	}
}
