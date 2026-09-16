<?php
/**
 * Content-model validation and sanitization policy.
 *
 * @package LPS\ContentModel
 */

declare(strict_types=1);

namespace LPS\ContentModel;

require_once __DIR__ . '/class-trustsurfacepolicy.php';

/** Domain validation and boundary sanitization policy. */
final class Policy {
	private const RECORD_ID_PATTERN = '/^lps:(page|person|organization|research-area|project|publication|news|opportunity|event|redirect):[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/';

	/**
	 * Sanitizes an immutable record identifier.
	 *
	 * @param mixed $value Boundary input.
	 */
	public static function sanitize_record_id( mixed $value ): string {
		$value = strtolower( trim( is_string( $value ) ? $value : '' ) );
		return 1 === preg_match( self::RECORD_ID_PATTERN, $value ) ? $value : '';
	}

	/**
	 * Sanitizes one plain-text value.
	 *
	 * @param mixed $value Boundary input.
	 */
	public static function sanitize_text( mixed $value ): string {
		$value = self::scalar_string( $value );
		$value = (string) preg_replace( '/<[^>]*>/', '', $value );
		return trim( (string) preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value ) );
	}

	/**
	 * Sanitizes multiline plain text.
	 *
	 * @param mixed $value Boundary input.
	 */
	public static function sanitize_textarea( mixed $value ): string {
		return self::sanitize_text( $value );
	}

	/**
	 * Sanitizes a non-negative integer.
	 *
	 * @param mixed $value Boundary input.
	 */
	public static function sanitize_integer( mixed $value ): int {
		return max( 0, is_numeric( $value ) ? (int) $value : 0 );
	}

	/**
	 * Sanitizes a boolean.
	 *
	 * @param mixed $value Boundary input.
	 */
	public static function sanitize_boolean( mixed $value ): bool {
		return filter_var( $value, FILTER_VALIDATE_BOOLEAN );
	}

	/**
	 * Sanitizes a typed scalar list.
	 *
	 * @param mixed $value Boundary input.
	 * @return array<int, int|string>
	 */
	public static function sanitize_array( mixed $value ): array {
		if ( ! is_array( $value ) ) {
			return array();
		}
		$result = array();
		foreach ( $value as $item ) {
			if ( is_int( $item ) ) {
				$result[] = max( 0, $item );
			} elseif ( is_string( $item ) ) {
				$clean = self::sanitize_text( $item );
				if ( '' !== $clean ) {
					$result[] = $clean;
				}
			}
		}
		return array_values( array_unique( $result, SORT_REGULAR ) );
	}

	/**
	 * Sanitizes an HTTP URL or absolute local path.
	 *
	 * @param mixed $value Boundary input.
	 */
	public static function sanitize_url( mixed $value ): string {
		$value = is_string( $value ) ? trim( $value ) : '';
		if ( preg_match( '~(?:https?://[^\s]+|/[^\s]*)$~i', $value, $matches ) ) {
			$candidate = $matches[0];
			if ( str_starts_with( $candidate, '/' ) || false !== filter_var( $candidate, FILTER_VALIDATE_URL ) ) {
				return $candidate;
			}
		}
		return '';
	}

	/**
	 * Sanitizes an email address.
	 *
	 * @param mixed $value Boundary input.
	 */
	public static function sanitize_email( mixed $value ): string {
		$value = is_string( $value ) ? trim( strtolower( $value ) ) : '';
		return false !== filter_var( $value, FILTER_VALIDATE_EMAIL ) ? $value : '';
	}

	/**
	 * Checks the metadata edit capability for the record being written.
	 *
	 * The generic `edit_posts` primitive is never granted to the LPS roles: they
	 * hold the per-type `edit_lps_*` primitives instead, so a blanket
	 * `edit_posts` check would deny every governed meta write. Authorization is
	 * evaluated against the specific record through `edit_post`, which maps to
	 * the per-type primitive and the collection/MFA filters.
	 *
	 * @param mixed  $allowed   Current authorization result.
	 * @param string $meta_key  Metadata key being written.
	 * @param int    $object_id Record the metadata belongs to.
	 * @param int    $user_id   Account performing the write.
	 */
	public static function can_edit_meta( mixed $allowed, string $meta_key, int $object_id, int $user_id ): bool {
		unset( $allowed, $meta_key );
		return function_exists( 'user_can' ) && user_can( $user_id, 'edit_post', $object_id );
	}

	/**
	 * Determines whether an identity assignment preserves immutability.
	 *
	 * @param string $stored Stored identity.
	 * @param string $candidate Candidate identity.
	 */
	public static function can_change_identity( string $stored, string $candidate ): bool {
		return '' === $stored || hash_equals( $stored, $candidate );
	}

	/**
	 * Checks a governed editorial-state transition.
	 *
	 * @param string $from Current state.
	 * @param string $to Candidate state.
	 */
	public static function valid_transition( string $from, string $to ): bool {
		$transitions = array(
			'draft'     => array( 'draft', 'in_review', 'published', 'archived' ),
			'in_review' => array( 'draft', 'in_review', 'published', 'archived' ),
			'published' => array( 'in_review', 'published', 'archived' ),
			'archived'  => array( 'archived' ),
		);
		return isset( $transitions[ $from ] ) && in_array( $to, $transitions[ $from ], true );
	}

	/**
	 * Returns typed publish-gate violations.
	 *
	 * @param string               $post_type Record type.
	 * @param array<string, mixed> $record    Candidate record.
	 * @return array<string, string>
	 */
	public static function publish_errors( string $post_type, array $record ): array {
		$errors = array();
		foreach ( array(
			'post_title'   => 'title',
			'post_excerpt' => 'summary',
			'post_content' => 'body',
		) as $key => $label ) {
			if ( '' === trim( self::scalar_string( $record[ $key ] ?? '' ) ) ) {
				$errors[ $key ] = 'lps_required_' . $label;
			}
		}
		if ( ! in_array( $record['_lps_locale'] ?? '', array( 'pt-br', 'en' ), true ) ) {
			$errors['_lps_locale'] = 'lps_invalid_locale';
		}
		$content = self::scalar_string( $record['post_content'] ?? '' );
		if ( 1 === preg_match( '/<script\b|\bon[a-z]+\s*=|javascript:/i', $content ) ) {
			$errors['post_content'] = 'lps_unsafe_html';
		}
		$required = array(
			'lps_person'        => array( '_lps_canonical_name' ),
			'lps_organization'  => array( '_lps_organization_name', '_lps_organization_kind', '_lps_country_code' ),
			'lps_research_area' => array( '_lps_stable_key', '_lps_label' ),
			'lps_project'       => array( '_lps_project_status' ),
			'lps_publication'   => array( '_lps_publication_type', '_lps_authoritative_title', '_lps_language' ),
			'lps_news'          => array( '_lps_canonical_date' ),
			'lps_opportunity'   => array( '_lps_opportunity_type', '_lps_eligibility', '_lps_application_instructions' ),
			'lps_event'         => array( '_lps_starts_at' ),
			'lps_redirect'      => array( '_lps_redirect_source' ),
		);
		foreach ( $required[ $post_type ] ?? array() as $key ) {
			if ( '' === trim( self::scalar_string( $record[ $key ] ?? '' ) ) ) {
				$errors[ $key ] = 'lps_required_' . substr( $key, 5 );
			}
		}
		if ( 'lps_redirect' === $post_type && empty( $record['_lps_redirect_target'] ) && empty( $record['_lps_redirect_gone'] ) ) {
			$errors['_lps_redirect_target'] = 'lps_redirect_target_or_gone_required';
		}
		if ( 'lps_opportunity' === $post_type && ! TrustSurfacePolicy::has_public_handoff(
			self::scalar_string( $record['_lps_contact'] ?? '' ),
			! empty( $record['_lps_contact_is_role'] ),
			self::scalar_string( $record['_lps_application_url'] ?? '' ),
			! empty( $record['_lps_application_url_approved'] )
		) ) {
			$errors['_lps_contact'] = 'lps_public_handoff_required';
		}
		return $errors;
	}

	/**
	 * Returns the typed deletion denial, when any.
	 *
	 * @param bool $published Whether ever published.
	 * @param bool $referenced Whether referenced.
	 */
	public static function deletion_error( bool $published, bool $referenced ): ?string {
		if ( $referenced ) {
			return 'lps_record_referenced';
		}
		return $published ? 'lps_archive_required' : null;
	}

	/**
	 * Converts trusted scalar boundary input to a string.
	 *
	 * @param mixed $value Boundary input.
	 */
	public static function scalar_string( mixed $value ): string {
		return is_scalar( $value ) ? (string) $value : '';
	}
}
