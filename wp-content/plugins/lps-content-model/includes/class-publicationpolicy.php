<?php
/**
 * Origin-aware publication provenance and public-visibility policy.
 *
 * @package LPS\ContentModel
 */

declare(strict_types=1);

namespace LPS\ContentModel;

require_once __DIR__ . '/class-policy.php';
require_once __DIR__ . '/class-trustsurfacepolicy.php';

/**
 * Owns the single public-visibility decision and the record-origin contract.
 *
 * Every governed record resolves to exactly one origin: `native` for content
 * authored through the editorial path, `imported` for content carrying real
 * migration provenance, or `ambiguous` for legacy records that declare
 * neither. The resolution is derived, never claimed: stored import provenance
 * always wins over a `_lps_origin` value, so an unreviewed imported record can
 * never be marked native to bypass review gates, and a native record never
 * needs fictitious `_lps_import_source_id` or `_lps_import_review_state`
 * values.
 *
 * `visibility_decision()` is the one eligibility decision consumed by
 * renderers, search, feeds, and previews. The `public` surface carries the
 * baseline gates every public consumer shares; the `feature` surface adds the
 * homepage's stricter accountable-owner, review-currency, claim-verification,
 * and feature-window requirements. No consumer reimplements a weaker
 * condition: surfaces only choose which declared surface they evaluate.
 */
final class PublicationPolicy {
	public const ORIGIN_NATIVE    = 'native';
	public const ORIGIN_IMPORTED  = 'imported';
	public const ORIGIN_AMBIGUOUS = 'ambiguous';
	public const ORIGINS          = array( self::ORIGIN_NATIVE, self::ORIGIN_IMPORTED );

	public const SURFACE_PUBLIC  = 'public';
	public const SURFACE_FEATURE = 'feature';
	public const SURFACES        = array( self::SURFACE_PUBLIC, self::SURFACE_FEATURE );

	/**
	 * Import provenance keys that prove a record came through migration.
	 *
	 * @var array<int, string>
	 */
	private const IMPORT_PROVENANCE_KEYS = array(
		'_lps_import_source_id',
		'_lps_import_source_url',
		'_lps_import_captured_at',
		'_lps_import_checksum',
		'_lps_import_rights',
		'_lps_import_review_state',
		'_lps_import_fingerprint',
		'_lps_import_reviewed_fields',
		'_lps_crossref_fields',
		'_lps_crossref_cache_key',
		'_lps_crossref_cached_at',
	);

	/**
	 * Provenance keys an English variant may never write.
	 *
	 * Provenance lives on the Portuguese authority; a variant cannot mint its
	 * own import identity or origin claim.
	 *
	 * @var array<int, string>
	 */
	private const EN_FORBIDDEN_PROVENANCE_KEYS = array(
		'_lps_origin',
		'_lps_import_source_id',
		'_lps_import_source_url',
		'_lps_import_captured_at',
		'_lps_import_checksum',
		'_lps_import_rights',
		'_lps_import_review_state',
		'_lps_import_fingerprint',
		'_lps_import_reviewed_fields',
		'_lps_crossref_fields',
		'_lps_crossref_cache_key',
		'_lps_crossref_cached_at',
	);

	/**
	 * Event statuses that cannot be promoted as features.
	 *
	 * @var array<int, string>
	 */
	private const UNFEATUREABLE_EVENT_STATUSES = array( 'cancelled', 'postponed' );

	/**
	 * Returns the provenance metadata keys the visibility decision reads.
	 *
	 * Consumers assemble decision records through
	 * `PublicationRecords::publication_record()`, which reads this list from
	 * the authoritative record so every surface evaluates the same fields.
	 *
	 * @return array<int, string>
	 */
	public static function decision_meta_keys(): array {
		return array_merge(
			self::IMPORT_PROVENANCE_KEYS,
			array(
				'_lps_origin',
				'_lps_record_id',
				'_lps_owner_user_id',
				'_lps_review_date',
				'_lps_claim_verified',
				'_lps_claim_source_url',
				'_lps_claim_reviewed_at',
				'_lps_person_status',
				'_lps_in_memoriam_approved',
				'_lps_public_profile',
				'_lps_event_status',
				'_lps_release_state',
				'_lps_scan_state',
				'_lps_rights_review',
				'_lps_accessibility_review',
			)
		);
	}

	/**
	 * Returns the provenance keys an English variant may never write.
	 *
	 * @return array<int, string>
	 */
	public static function english_forbidden_provenance_keys(): array {
		return self::EN_FORBIDDEN_PROVENANCE_KEYS;
	}

	/**
	 * Sanitizes a stored origin claim.
	 *
	 * @param mixed $value Boundary input.
	 */
	public static function sanitize_origin( mixed $value ): string {
		$value = strtolower( trim( Policy::scalar_string( $value ) ) );
		return in_array( $value, self::ORIGINS, true ) ? $value : '';
	}

	/**
	 * Reports whether a record carries real import provenance.
	 *
	 * @param array<string, mixed> $record Candidate record.
	 */
	public static function has_import_provenance( array $record ): bool {
		foreach ( self::IMPORT_PROVENANCE_KEYS as $key ) {
			$value = $record[ $key ] ?? '';
			if ( is_array( $value ) && array() !== $value ) {
				return true;
			}
			if ( '' !== trim( Policy::scalar_string( $value ) ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Resolves the derived origin of a record.
	 *
	 * Import provenance always wins over a stored `_lps_origin` claim, so a
	 * forged `native` flag can never launder an imported record. A record with
	 * neither a valid claim nor provenance is `ambiguous`: it is reported for
	 * reconciliation, never automatically trusted.
	 *
	 * @param array<string, mixed> $record Candidate record.
	 */
	public static function resolve_origin( array $record ): string {
		if ( self::has_import_provenance( $record ) ) {
			return self::ORIGIN_IMPORTED;
		}
		$stored = self::sanitize_origin( $record['_lps_origin'] ?? '' );
		return '' === $stored ? self::ORIGIN_AMBIGUOUS : $stored;
	}

	/**
	 * Returns the stable provenance identifier for display attributes.
	 *
	 * Imported records expose their migration source identifier; native
	 * records expose their immutable record identifier. Ambiguous records have
	 * no trustworthy provenance identifier.
	 *
	 * @param array<string, mixed> $record Candidate record.
	 */
	public static function provenance_id( array $record ): string {
		$origin = self::resolve_origin( $record );
		if ( self::ORIGIN_IMPORTED === $origin ) {
			return trim( Policy::scalar_string( $record['_lps_import_source_id'] ?? '' ) );
		}
		if ( self::ORIGIN_NATIVE === $origin ) {
			return trim( Policy::scalar_string( $record['_lps_record_id'] ?? '' ) );
		}
		return '';
	}

	/**
	 * Returns the typed violation for a candidate `_lps_origin` write.
	 *
	 * Origin is written once by the authoring or import boundary and is then
	 * immutable: a stored `native` claim can only be corrected to `imported`
	 * when real provenance exists, and a `native` claim is always denied when
	 * provenance is present.
	 *
	 * @param string               $stored   Stored origin value.
	 * @param string               $candidate Candidate origin value.
	 * @param array<string, mixed> $record   Record carrying provenance fields.
	 */
	public static function origin_write_error( string $stored, string $candidate, array $record ): ?string {
		$candidate = trim( $candidate );
		if ( '' === $candidate ) {
			return null;
		}
		if ( ! in_array( $candidate, self::ORIGINS, true ) ) {
			return 'lps_origin_invalid';
		}
		$stored = self::sanitize_origin( $stored );
		if ( '' !== $stored && $stored === $candidate ) {
			return null;
		}
		$provenance = self::has_import_provenance( $record );
		if ( self::ORIGIN_NATIVE === $candidate && $provenance ) {
			return 'lps_origin_conflicts_provenance';
		}
		if ( self::ORIGIN_IMPORTED === $candidate ) {
			return $provenance ? null : 'lps_origin_imported_requires_provenance';
		}
		return '' === $stored ? null : 'lps_origin_immutable';
	}

	/**
	 * Returns the publish-time violation for an inconsistent stored origin.
	 *
	 * @param array<string, mixed> $record Candidate record.
	 */
	public static function origin_consistency_error( array $record ): ?string {
		$stored = trim( Policy::scalar_string( $record['_lps_origin'] ?? '' ) );
		if ( '' !== $stored && ! in_array( $stored, self::ORIGINS, true ) ) {
			return 'lps_origin_invalid';
		}
		if ( self::ORIGIN_NATIVE === $stored && self::has_import_provenance( $record ) ) {
			return 'lps_origin_conflicts_provenance';
		}
		return null;
	}

	/**
	 * Evaluates the single public-visibility decision for one record.
	 *
	 * @param array<string, mixed> $record  Portable decision record.
	 * @param string               $surface One of SURFACES.
	 * @param string               $locale  Requested locale slug.
	 * @param string               $today   Institutional date (YYYY-MM-DD).
	 * @return array{visible: bool, origin: string, errors: array<string, string>}
	 */
	public static function visibility_decision( array $record, string $surface, string $locale, string $today ): array {
		$errors = array();
		if ( ! in_array( $surface, self::SURFACES, true ) ) {
			return array(
				'visible' => false,
				'origin'  => self::resolve_origin( $record ),
				'errors'  => array( 'surface' => 'lps_visibility_surface_unknown' ),
			);
		}
		if ( 'publish' !== self::text( $record['status'] ?? '' ) ) {
			$errors['status'] = 'lps_visibility_not_published';
		}
		if ( 'published' !== self::text( $record['_lps_state'] ?? '' ) ) {
			$errors['_lps_state'] = 'lps_visibility_state_not_published';
		}
		$record_locale = self::text( $record['locale'] ?? '' );
		if ( '' === $record_locale ) {
			$errors['locale'] = 'lps_visibility_locale_unknown';
		} elseif ( $record_locale !== $locale ) {
			$errors['locale'] = 'lps_visibility_locale_mismatch';
		}
		// A stale flag withholds the record on any locale: on an English variant
		// it means the translation is out of date, and on a Portuguese record it
		// is a defensive withhold signal that must never be published over.
		if ( ! empty( $record['stale'] ) ) {
			$errors['stale'] = 'lps_visibility_stale_translation';
		}
		if ( 'en' === $record_locale ) {
			if ( 'publish' !== self::text( $record['source_status'] ?? '' ) ) {
				$errors['source_status'] = 'lps_visibility_source_not_published';
			}
			if ( 'published' !== self::text( $record['source_state'] ?? '' ) ) {
				$errors['source_state'] = 'lps_visibility_source_not_published';
			}
		}

		$origin = self::resolve_origin( $record );
		if ( self::ORIGIN_AMBIGUOUS === $origin ) {
			$errors['_lps_origin'] = 'lps_origin_review_required';
		} elseif ( self::ORIGIN_IMPORTED === $origin ) {
			if ( 'reviewed' !== self::text( $record['_lps_import_review_state'] ?? '' ) ) {
				$errors['_lps_import_review_state'] = 'lps_import_review_required';
			}
			if ( '' === trim( self::text( $record['_lps_import_source_id'] ?? '' ) ) ) {
				$errors['_lps_import_source_id'] = 'lps_import_provenance_required';
			}
		} elseif ( '' === trim( self::text( $record['_lps_record_id'] ?? '' ) ) ) {
			$errors['_lps_record_id'] = 'lps_origin_record_id_required';
		}

		$post_type = self::text( $record['post_type'] ?? '' );
		if ( 'lps_organization' === $post_type && ! self::flag( $record['_lps_public_profile'] ?? false ) ) {
			$errors['_lps_public_profile'] = 'lps_visibility_profile_not_public';
		}
		if ( 'lps_person' === $post_type && 'in-memoriam' === self::text( $record['_lps_person_status'] ?? '' ) && ! self::flag( $record['_lps_in_memoriam_approved'] ?? false ) ) {
			$errors['_lps_in_memoriam_approved'] = 'lps_visibility_memoriam_not_approved';
		}
		if ( 'lps_resource' === $post_type ) {
			if ( 'released' !== self::text( $record['_lps_release_state'] ?? '' ) ) {
				$errors['_lps_release_state'] = 'lps_resource_not_released';
			}
			if ( 'clean' !== self::text( $record['_lps_scan_state'] ?? '' ) ) {
				$errors['_lps_scan_state'] = 'lps_resource_scan_not_clean';
			}
			if ( 'approved' !== self::text( $record['_lps_rights_review'] ?? '' ) ) {
				$errors['_lps_rights_review'] = 'lps_resource_rights_not_approved';
			}
			if ( 'approved' !== self::text( $record['_lps_accessibility_review'] ?? '' ) ) {
				$errors['_lps_accessibility_review'] = 'lps_resource_accessibility_not_approved';
			}
		}

		if ( self::SURFACE_FEATURE === $surface ) {
			if ( 1 > Policy::sanitize_integer( $record['_lps_owner_user_id'] ?? 0 ) ) {
				$errors['_lps_owner_user_id'] = 'lps_visibility_owner_required';
			}
			$review_date = trim( self::text( $record['_lps_review_date'] ?? '' ) );
			if ( '' === $review_date || $review_date < $today ) {
				$errors['_lps_review_date'] = 'lps_visibility_review_expired';
			}
			$featured_from = trim( self::text( $record['featured_from'] ?? '' ) );
			if ( '' !== $featured_from && $featured_from > $today ) {
				$errors['featured_from'] = 'lps_visibility_feature_not_started';
			}
			$featured_until = trim( self::text( $record['featured_until'] ?? '' ) );
			if ( '' !== $featured_until && $featured_until < $today ) {
				$errors['featured_until'] = 'lps_visibility_feature_expired';
			}
			if ( 'lps_event' === $post_type && in_array( self::text( $record['_lps_event_status'] ?? '' ), self::UNFEATUREABLE_EVENT_STATUSES, true ) ) {
				$errors['_lps_event_status'] = 'lps_visibility_event_not_featureable';
			}
			$claim = array(
				'_lps_claim_verified'    => self::flag( $record['_lps_claim_verified'] ?? false ),
				'_lps_claim_source_url'  => self::text( $record['_lps_claim_source_url'] ?? '' ),
				'_lps_claim_reviewed_at' => self::text( $record['_lps_claim_reviewed_at'] ?? '' ),
			);
			$now   = \DateTimeImmutable::createFromFormat( '!Y-m-d', $today );
			$now   = false === $now ? new \DateTimeImmutable( 'now' ) : $now;
			foreach ( TrustSurfacePolicy::claim_errors( $claim, $now ) as $field => $code ) {
				$errors[ $field ] = $code;
			}
		}

		return array(
			'visible' => array() === $errors,
			'origin'  => $origin,
			'errors'  => $errors,
		);
	}

	/**
	 * Evaluates the same decision for an editorial preview.
	 *
	 * A preview always renders for an authorized editor, but it evaluates the
	 * identical public decision so the preview surface can report exactly why
	 * the record would or would not be visible. Previews never relax the
	 * decision; they only observe it.
	 *
	 * @param array<string, mixed> $record Portable decision record.
	 * @param string               $locale Requested locale slug.
	 * @param string               $today  Institutional date (YYYY-MM-DD).
	 * @return array{preview: true, public_visible: bool, origin: string, errors: array<string, string>}
	 */
	public static function preview_decision( array $record, string $locale, string $today ): array {
		$decision = self::visibility_decision( $record, self::SURFACE_PUBLIC, $locale, $today );
		return array(
			'preview'        => true,
			'public_visible' => $decision['visible'],
			'origin'         => $decision['origin'],
			'errors'         => $decision['errors'],
		);
	}

	/**
	 * Classifies one record for the ambiguous-origin reconciliation report.
	 *
	 * The report is a review queue, never an automatic trust decision:
	 * `ambiguous` rows need an origin review, `conflict` rows carry a stored
	 * `native` claim contradicted by real provenance, and `unreviewed-import`
	 * rows carry provenance without a completed import review.
	 *
	 * @param array<string, mixed> $record Candidate record.
	 * @return array{origin: string, action: string}
	 */
	public static function reconciliation_class( array $record ): array {
		$origin = self::resolve_origin( $record );
		if ( self::ORIGIN_AMBIGUOUS === $origin ) {
			return array(
				'origin' => $origin,
				'action' => 'ambiguous',
			);
		}
		$stored = self::sanitize_origin( $record['_lps_origin'] ?? '' );
		if ( self::ORIGIN_IMPORTED === $origin && self::ORIGIN_NATIVE === $stored ) {
			return array(
				'origin' => $origin,
				'action' => 'conflict',
			);
		}
		if ( self::ORIGIN_IMPORTED === $origin && 'reviewed' !== self::text( $record['_lps_import_review_state'] ?? '' ) ) {
			return array(
				'origin' => $origin,
				'action' => 'unreviewed-import',
			);
		}
		return array(
			'origin' => $origin,
			'action' => 'none',
		);
	}

	/**
	 * Sorts reconciliation rows deterministically: ambiguous first, then
	 * conflicts, then unreviewed imports, then type, title, and record ID.
	 *
	 * @param array<int, array{post_id: int, post_type: string, title: string, origin: string, action: string}> $rows Unsorted rows.
	 * @return array<int, array{post_id: int, post_type: string, title: string, origin: string, action: string}>
	 */
	public static function sort_reconciliation( array $rows ): array {
		usort(
			$rows,
			static function ( array $left, array $right ): int {
				$rank = array(
					'ambiguous'         => 0,
					'conflict'          => 1,
					'unreviewed-import' => 2,
				);
				return array( $rank[ $left['action'] ] ?? 3, $left['post_type'], strtolower( $left['title'] ), $left['post_id'] )
					<=> array( $rank[ $right['action'] ] ?? 3, $right['post_type'], strtolower( $right['title'] ), $right['post_id'] );
			}
		);
		return $rows;
	}

	/**
	 * Converts boundary input to a string.
	 *
	 * @param mixed $value Boundary input.
	 */
	private static function text( mixed $value ): string {
		return is_scalar( $value ) ? (string) $value : '';
	}

	/**
	 * Converts boundary input to a boolean flag.
	 *
	 * @param mixed $value Boundary input.
	 */
	private static function flag( mixed $value ): bool {
		if ( is_bool( $value ) ) {
			return $value;
		}
		if ( is_int( $value ) ) {
			return 0 !== $value;
		}
		return is_string( $value ) && in_array( strtolower( trim( $value ) ), array( '1', 'true', 'yes' ), true );
	}
}
