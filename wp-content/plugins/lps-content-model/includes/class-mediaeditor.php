<?php
/**
 * Attachment editor fields for governed media metadata.
 *
 * @package LPS\ContentModel
 */

declare(strict_types=1);

namespace LPS\ContentModel;

/** Exposes typed media review fields through WordPress attachment editing. */
final class MediaEditor {
	/** Registers attachment editor hooks. */
	public static function boot(): void {
		add_filter( 'attachment_fields_to_edit', array( self::class, 'fields' ), 10, 2 );
		add_filter( 'attachment_fields_to_save', array( self::class, 'save' ), 10, 2 );
	}

	/**
	 * Adds governed metadata controls.
	 *
	 * @param array<string, array<string, string>> $fields Existing fields.
	 * @param object                               $post Attachment record.
	 * @return array<string, array<string, string>>
	 */
	public static function fields( array $fields, object $post ): array {
		$attachment_id = property_exists( $post, 'ID' ) && is_numeric( $post->ID ) ? (int) $post->ID : 0;
		foreach ( MediaContracts::attachment_fields() as $key => $definition ) {
			$field_id            = ltrim( $key, '_' );
			$fields[ $field_id ] = array(
				'label' => $definition['description'],
				'input' => 'text',
				'value' => MediaPolicy::string_value( get_post_meta( $attachment_id, $key, true ) ),
				'helps' => self::help( $key ),
			);
		}
		return $fields;
	}

	/**
	 * Saves governed metadata through its declared sanitizer.
	 *
	 * @param array<string, mixed> $post Prepared attachment values.
	 * @param array<string, mixed> $attachment Submitted attachment fields.
	 * @return array<string, mixed>
	 */
	public static function save( array $post, array $attachment ): array {
		$attachment_id = Policy::sanitize_integer( $post['ID'] ?? 0 );
		$technical     = array( '_lps_media_checksum', '_lps_media_width', '_lps_media_height', '_lps_media_duration' );
		foreach ( MediaContracts::attachment_fields() as $key => $definition ) {
			$field_id = ltrim( $key, '_' );
			if ( in_array( $key, $technical, true ) || ! array_key_exists( $field_id, $attachment ) ) {
				continue;
			}
			$value = ( $definition['sanitize_callback'] )( $attachment[ $field_id ] );
			update_post_meta( $attachment_id, $key, $value );
		}
		return $post;
	}

	/**
	 * Returns editor guidance for a governed field.
	 *
	 * @param string $key Metadata key.
	 */
	private static function help( string $key ): string {
		return match ( $key ) {
			'_lps_media_rights_status' => 'Allowed: unknown, cleared, restricted.',
			'_lps_media_privacy_status' => 'Allowed: pending, reviewed, not-required.',
			'_lps_media_transcript_status', '_lps_media_caption_status' => 'Allowed: missing, provided, not-required.',
			'_lps_media_document_status' => 'Allowed: unknown, accessible, html-equivalent, inaccessible, not-required.',
			'_lps_media_focal_x', '_lps_media_focal_y' => 'Enter a value from 0 to 1.',
			default => 'Required before public use when applicable.',
		};
	}
}
