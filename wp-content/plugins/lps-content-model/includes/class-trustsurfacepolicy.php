<?php
/**
 * Date-derived opportunity/event state and institutional-claim policy.
 *
 * @package LPS\ContentModel
 */

declare(strict_types=1);

namespace LPS\ContentModel;

use DateTimeImmutable;

/**
 * Owns the trust rules shared by opportunity, event, and institutional surfaces.
 *
 * State is always derived from stored dates so an expired deadline can never be
 * presented as open, and closed records stay reachable at a stable URL.
 */
final class TrustSurfacePolicy {
	/** Days a closed opportunity stays indexable before it becomes noindex. */
	public const NOINDEX_AFTER_DAYS = 90;

	/** Days an institutional claim review remains fresh. */
	public const CLAIM_REVIEW_MAX_AGE_DAYS = 365;

	/** Event statuses that are editorially pinned and never recomputed from dates. */
	private const PINNED_EVENT_STATUSES = array( 'cancelled', 'postponed' );

	/** URL schemes accepted for an approved external application handoff. */
	private const SAFE_URL_SCHEMES = array( 'https', 'http', 'mailto' );

	/**
	 * Derives the public opportunity state from its application window.
	 *
	 * @param string            $opens_at  Application opening timestamp.
	 * @param string            $closes_at Application closing timestamp.
	 * @param DateTimeImmutable $now       Evaluation instant.
	 * @return string One of `upcoming`, `open`, or `closed`.
	 */
	public static function opportunity_state( string $opens_at, string $closes_at, DateTimeImmutable $now ): string {
		$opens  = self::timestamp( $opens_at );
		$closes = self::timestamp( $closes_at );
		if ( null === $opens || null === $closes ) {
			return 'closed';
		}
		if ( $now < $opens ) {
			return 'upcoming';
		}
		return $now > $closes ? 'closed' : 'open';
	}

	/**
	 * Reports whether a closed opportunity has aged past the indexable window.
	 *
	 * The record stays published at a stable URL; only its indexability changes.
	 *
	 * @param string            $closes_at Application closing timestamp.
	 * @param DateTimeImmutable $now       Evaluation instant.
	 */
	public static function opportunity_is_noindex( string $closes_at, DateTimeImmutable $now ): bool {
		$closes = self::timestamp( $closes_at );
		if ( null === $closes ) {
			return false;
		}
		return $now >= $closes->modify( '+' . self::NOINDEX_AFTER_DAYS . ' days' );
	}

	/**
	 * Derives the public event state, preserving editorially pinned statuses.
	 *
	 * @param string            $status    Stored event status.
	 * @param string            $starts_at Event start timestamp.
	 * @param string            $ends_at   Event end timestamp.
	 * @param DateTimeImmutable $now       Evaluation instant.
	 * @return string One of `cancelled`, `postponed`, `upcoming`, `ongoing`, or `past`.
	 */
	public static function event_state( string $status, string $starts_at, string $ends_at, DateTimeImmutable $now ): string {
		if ( in_array( $status, self::PINNED_EVENT_STATUSES, true ) ) {
			return $status;
		}
		$starts = self::timestamp( $starts_at );
		$ends   = self::timestamp( $ends_at ) ?? $starts;
		if ( null === $starts || null === $ends ) {
			return 'past';
		}
		if ( $now < $starts ) {
			return 'upcoming';
		}
		return $now > $ends ? 'past' : 'ongoing';
	}

	/**
	 * Reports whether a record offers an owned public handoff.
	 *
	 * A handoff is valid only as a role-based public address or an approved
	 * external application URL; a personal address is never a public handoff.
	 *
	 * @param string $contact          Stored contact address.
	 * @param bool   $contact_is_role  Whether the address is a role account.
	 * @param string $application_url  External application URL.
	 * @param bool   $url_is_approved  Whether the URL is editorially approved.
	 */
	public static function has_public_handoff( string $contact, bool $contact_is_role, string $application_url, bool $url_is_approved ): bool {
		if ( $contact_is_role && self::is_email( $contact ) ) {
			return true;
		}
		return $url_is_approved && self::is_safe_url( $application_url );
	}

	/**
	 * Returns typed errors for an institutional claim.
	 *
	 * A verified claim must cite a source and carry a recent review date so no
	 * prestige statement can be published unsourced or left to go stale.
	 *
	 * @param array<string, mixed> $claim Claim fields.
	 * @param DateTimeImmutable    $now   Evaluation instant.
	 * @return array<string, string> Field-keyed error codes.
	 */
	public static function claim_errors( array $claim, DateTimeImmutable $now ): array {
		$errors = array();
		if ( empty( $claim['_lps_claim_verified'] ) ) {
			return $errors;
		}
		$source = is_string( $claim['_lps_claim_source_url'] ?? null ) ? trim( $claim['_lps_claim_source_url'] ) : '';
		if ( '' === $source || ! self::is_safe_url( $source ) ) {
			$errors['_lps_claim_source_url'] = 'lps_claim_source_required';
		}
		$reviewed_raw = is_string( $claim['_lps_claim_reviewed_at'] ?? null ) ? trim( $claim['_lps_claim_reviewed_at'] ) : '';
		$reviewed     = self::timestamp( $reviewed_raw );
		if ( null === $reviewed ) {
			$errors['_lps_claim_reviewed_at'] = 'lps_claim_review_required';
			return $errors;
		}
		if ( $now > $reviewed->modify( '+' . self::CLAIM_REVIEW_MAX_AGE_DAYS . ' days' ) ) {
			$errors['_lps_claim_reviewed_at'] = 'lps_claim_review_stale';
		}
		return $errors;
	}

	/**
	 * Parses a stored timestamp into a comparable instant.
	 *
	 * @param string $value Stored timestamp.
	 */
	private static function timestamp( string $value ): ?DateTimeImmutable {
		$trimmed = trim( $value );
		if ( '' === $trimmed ) {
			return null;
		}
		$parsed = date_create_immutable( $trimmed );
		return false === $parsed ? null : $parsed;
	}

	/**
	 * Reports whether a value is a usable email address.
	 *
	 * @param string $value Stored address.
	 */
	private static function is_email( string $value ): bool {
		return false !== filter_var( trim( $value ), FILTER_VALIDATE_EMAIL );
	}

	/**
	 * Reports whether a URL uses an allowed scheme and host.
	 *
	 * @param string $value Stored URL.
	 */
	private static function is_safe_url( string $value ): bool {
		$trimmed = trim( $value );
		if ( '' === $trimmed ) {
			return false;
		}
		$scheme = strtolower( (string) wp_parse_url( $trimmed, PHP_URL_SCHEME ) );
		if ( ! in_array( $scheme, self::SAFE_URL_SCHEMES, true ) ) {
			return false;
		}
		if ( 'mailto' === $scheme ) {
			return self::is_email( (string) wp_parse_url( $trimmed, PHP_URL_PATH ) );
		}
		return '' !== (string) wp_parse_url( $trimmed, PHP_URL_HOST );
	}
}
