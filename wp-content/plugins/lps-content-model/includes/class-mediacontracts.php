<?php
/**
 * Typed WordPress attachment metadata contracts.
 *
 * @package LPS\ContentModel
 */

declare(strict_types=1);

namespace LPS\ContentModel;

/** Canonical metadata carried by every governed attachment. */
final class MediaContracts {
	/**
	 * Returns attachment metadata definitions.
	 *
	 * @return array<string, array{type: string, single: bool, description: string, show_in_rest: bool, sanitize_callback: callable(mixed): mixed, auth_callback: callable(mixed, string, int, int): bool}>
	 */
	public static function attachment_fields(): array {
		return array(
			'_lps_media_credit'              => self::field( 'string', 'Public credit line', 'text' ),
			'_lps_media_rights_holder'       => self::field( 'string', 'Documented rights holder', 'text' ),
			'_lps_media_license'             => self::field( 'string', 'Documented license or permission basis', 'text' ),
			'_lps_media_source_url'          => self::field( 'string', 'Source provenance URL', 'url' ),
			'_lps_media_checksum'            => self::field( 'string', 'SHA-256 file checksum', 'checksum' ),
			'_lps_media_focal_x'             => self::field( 'number', 'Horizontal focal point from 0 to 1', 'focal' ),
			'_lps_media_focal_y'             => self::field( 'number', 'Vertical focal point from 0 to 1', 'focal' ),
			'_lps_media_width'               => self::field( 'integer', 'Intrinsic width in pixels', 'integer' ),
			'_lps_media_height'              => self::field( 'integer', 'Intrinsic height in pixels', 'integer' ),
			'_lps_media_duration'            => self::field( 'number', 'Duration in seconds', 'duration' ),
			'_lps_media_rights_status'       => self::field( 'string', 'Rights review status', 'rights_status' ),
			'_lps_media_privacy_status'      => self::field( 'string', 'Privacy review status', 'privacy_status' ),
			'_lps_media_transcript_status'   => self::field( 'string', 'Transcript status', 'access_status' ),
			'_lps_media_caption_status'      => self::field( 'string', 'Timed-caption status', 'access_status' ),
			'_lps_media_document_status'     => self::field( 'string', 'Accessible-document status', 'document_status' ),
			'_lps_media_import_source_id'    => self::field( 'string', 'Migration source identifier', 'text' ),
			'_lps_media_import_captured_at'  => self::field( 'string', 'Migration capture timestamp', 'text' ),
			'_lps_media_import_locale'       => self::field( 'string', 'Migration source locale', 'text' ),
			'_lps_media_import_review_state' => self::field( 'string', 'Migration review state', 'text' ),
		);
	}

	/**
	 * Builds an attachment field definition.
	 *
	 * @param string $type REST primitive type.
	 * @param string $description Accessible description.
	 * @param string $sanitizer Sanitizer selector.
	 * @return array{type: string, single: bool, description: string, show_in_rest: bool, sanitize_callback: callable(mixed): mixed, auth_callback: callable(mixed, string, int, int): bool}
	 */
	private static function field( string $type, string $description, string $sanitizer ): array {
		$callback = match ( $sanitizer ) {
			'integer' => array( Policy::class, 'sanitize_integer' ),
			'url' => array( Policy::class, 'sanitize_url' ),
			'checksum' => array( MediaPolicy::class, 'sanitize_checksum' ),
			'focal' => array( MediaPolicy::class, 'sanitize_focal_point' ),
			'duration' => array( MediaPolicy::class, 'sanitize_duration' ),
			'rights_status' => array( MediaPolicy::class, 'sanitize_rights_status' ),
			'privacy_status' => array( MediaPolicy::class, 'sanitize_privacy_status' ),
			'access_status' => array( MediaPolicy::class, 'sanitize_access_status' ),
			'document_status' => array( MediaPolicy::class, 'sanitize_document_status' ),
			default => array( Policy::class, 'sanitize_text' ),
		};
		return array(
			'type'              => $type,
			'single'            => true,
			'description'       => $description,
			'show_in_rest'      => true,
			'sanitize_callback' => $callback,
			'auth_callback'     => array( Policy::class, 'can_edit_meta' ),
		);
	}
}
