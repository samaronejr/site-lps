<?php
/**
 * Contextual media-use validation.
 *
 * @package LPS\ContentModel
 */

declare(strict_types=1);

namespace LPS\ContentModel;

/** Enforces per-use alternatives, playback, document, and hero contracts. */
final class MediaUsagePolicy {
	/**
	 * Returns one localized usage's violations.
	 *
	 * @param array<string, mixed> $usage Contextual use.
	 * @param array<string, mixed> $asset Attachment values.
	 * @return array<string, string>
	 */
	public static function errors( array $usage, array $asset ): array {
		$errors = MediaPolicy::asset_errors( $asset );
		if ( ! in_array( $usage['locale'] ?? '', array( 'pt-br', 'en' ), true ) ) {
			$errors['locale'] = 'lps_media_locale_required';
		}
		$block = MediaPolicy::string_value( $usage['block'] ?? '' );
		if ( in_array( $block, array( 'image', 'figure', 'gallery' ), true ) ) {
			$errors = array_merge( $errors, self::image_errors( $usage, $asset ) );
		}
		if ( 'video' === $block ) {
			$errors = array_merge( $errors, self::video_errors( $usage, $asset ) );
		}
		if ( 'document' === $block ) {
			$errors = array_merge( $errors, self::document_errors( $usage, $asset ) );
		}
		return $errors;
	}

	/**
	 * Returns violations across all usages in one public record.
	 *
	 * @param array<int, array<string, mixed>> $usages Contextual uses.
	 * @param array<int, array<string, mixed>> $assets Attachments keyed by ID.
	 * @return array<string, string>
	 */
	public static function collection_errors( array $usages, array $assets ): array {
		$errors    = array();
		$hero_seen = false;
		$alts      = array();
		foreach ( $usages as $index => $usage ) {
			$media_id = Policy::sanitize_integer( $usage['media_id'] ?? 0 );
			if ( ! isset( $assets[ $media_id ] ) ) {
				$errors[ "usage:$index:media_id" ] = 'lps_media_attachment_required';
				continue;
			}
			foreach ( self::errors( $usage, $assets[ $media_id ] ) as $field => $code ) {
				$errors[ "usage:$index:$field" ] = $code;
			}
			if ( 'hero' === ( $usage['placement'] ?? '' ) ) {
				if ( $hero_seen ) {
					$errors[ "usage:$index:placement" ] = 'lps_media_multiple_heroes';
				}
				$hero_seen = true;
			}
			$alt = MediaPolicy::string_value( $usage['alt'] ?? '' );
			if ( '' !== $alt ) {
				$key = $media_id . ':' . strtolower( $alt );
				if ( isset( $alts[ $key ] ) && ( $usage['context'] ?? '' ) !== $alts[ $key ] ) {
					$errors[ "usage:$index:alt" ] = 'lps_media_reused_context_alt';
				}
				$alts[ $key ] = MediaPolicy::string_value( $usage['context'] ?? '' );
			}
		}
		return $errors;
	}

	/**
	 * Validates image alternatives.
	 *
	 * @param array<string, mixed> $usage Contextual use.
	 * @param array<string, mixed> $asset Attachment values.
	 * @return array<string, string>
	 */
	private static function image_errors( array $usage, array $asset ): array {
		$errors     = array();
		$alt        = MediaPolicy::string_value( $usage['alt'] ?? '' );
		$decorative = true === ( $usage['decorative'] ?? false );
		if ( '' === $alt && ! $decorative ) {
			$errors['alt'] = 'lps_media_alt_or_decorative_required';
		} elseif ( '' !== $alt && $decorative ) {
			$errors['alt'] = 'lps_media_alt_decorative_conflict';
		} elseif ( '' !== $alt && self::filename_stem( MediaPolicy::string_value( $asset['filename'] ?? '' ) ) === self::filename_stem( $alt ) ) {
			$errors['alt'] = 'lps_media_filename_alt_forbidden';
		}
		if ( '' === MediaPolicy::string_value( $usage['context'] ?? '' ) ) {
			$errors['context'] = 'lps_media_context_required';
		}
		if ( '' === MediaPolicy::string_value( $usage['caption'] ?? '' ) ) {
			$errors['caption'] = 'lps_media_caption_required';
		}
		return $errors;
	}

	/**
	 * Validates video accessibility and playback.
	 *
	 * @param array<string, mixed> $usage Contextual use.
	 * @param array<string, mixed> $asset Attachment values.
	 * @return array<string, string>
	 */
	private static function video_errors( array $usage, array $asset ): array {
		$errors   = array();
		$label    = MediaPolicy::string_value( $usage['label'] ?? '' );
		$autoplay = true === ( $usage['autoplay'] ?? false );
		if ( true === ( $usage['iframe'] ?? false ) ) {
			$iframe_error = MediaPolicy::iframe_error( $label, $autoplay );
			if ( null !== $iframe_error ) {
				$errors[ '' === $label ? 'label' : 'autoplay' ] = $iframe_error;
			}
		} else {
			if ( '' === $label ) {
				$errors['label'] = 'lps_media_label_required';
			}
			if ( $autoplay ) {
				$errors['autoplay'] = 'lps_media_autoplay_forbidden';
			}
		}
		if ( '' === MediaPolicy::string_value( $usage['caption'] ?? '' ) ) {
			$errors['caption'] = 'lps_media_caption_required';
		}
		if ( 'provided' !== ( $asset['caption_status'] ?? '' ) ) {
			$errors['caption_status'] = 'lps_media_captions_missing';
		}
		$captions_url = MediaPolicy::string_value( $usage['captions_url'] ?? '' );
		if ( ! str_starts_with( $captions_url, '/' ) ) {
			$errors['captions_url'] = '' === $captions_url ? 'lps_media_caption_file_required' : 'lps_media_hotlink_forbidden';
		}
		if ( 'provided' !== ( $asset['transcript_status'] ?? '' ) ) {
			$errors['transcript_status'] = 'lps_media_transcript_missing';
		}
		$transcript_url = MediaPolicy::string_value( $usage['transcript_url'] ?? '' );
		if ( ! str_starts_with( $transcript_url, '/' ) ) {
			$errors['transcript_url'] = '' === $transcript_url ? 'lps_media_transcript_file_required' : 'lps_media_hotlink_forbidden';
		}
		return $errors;
	}

	/**
	 * Validates accessible-document use.
	 *
	 * @param array<string, mixed> $usage Contextual use.
	 * @param array<string, mixed> $asset Attachment values.
	 * @return array<string, string>
	 */
	private static function document_errors( array $usage, array $asset ): array {
		$errors = array();
		if ( '' === MediaPolicy::string_value( $usage['label'] ?? '' ) ) {
			$errors['label'] = 'lps_media_label_required';
		}
		if ( ! in_array( $asset['document_status'] ?? '', array( 'accessible', 'html-equivalent' ), true ) ) {
			$errors['document_status'] = 'lps_media_document_inaccessible';
		}
		if ( true === ( $usage['essential'] ?? false ) && '' === MediaPolicy::string_value( $usage['html_equivalent'] ?? '' ) ) {
			$errors['html_equivalent'] = 'lps_media_html_equivalent_required';
		}
		return $errors;
	}

	/**
	 * Normalizes a filename or candidate alt.
	 *
	 * @param string $value Filename or alt candidate.
	 */
	private static function filename_stem( string $value ): string {
		$stem = pathinfo( strtolower( $value ), PATHINFO_FILENAME );
		return trim( (string) preg_replace( '/[^a-z0-9]+/', ' ', $stem ) );
	}
}
