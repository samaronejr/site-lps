<?php
/**
 * Opportunity, event, contact, and institutional-trust policy tests.
 *
 * @package LPS\ContentModel\Tests
 */

declare(strict_types=1);

namespace LPS\ContentModel\Tests;

require_once dirname( __DIR__ ) . '/includes/class-policy.php';
require_once dirname( __DIR__ ) . '/includes/class-trustsurfacepolicy.php';

use DateTimeImmutable;
use LPS\ContentModel\Policy;
use LPS\ContentModel\TrustSurfacePolicy;
use PHPUnit\Framework\Attributes\DataProvider;

/** Opportunity, event, contact, and institutional-trust policy tests. */
final class TrustSurfacePolicyTest extends \PHPUnit\Framework\TestCase {
	/**
	 * Provides opportunity states.
	 *
	 * @return array<string, array{string, string, string, string}>
	 */
	public static function opportunity_states(): array {
		return array(
			'upcoming'              => array( '2026-09-10T12:00:00+00:00', '2026-09-20T12:00:00+00:00', '2026-09-03T12:00:00+00:00', 'upcoming' ),
			'open'                  => array( '2026-09-01T12:00:00+00:00', '2026-09-20T12:00:00+00:00', '2026-09-03T12:00:00+00:00', 'open' ),
			'closed'                => array( '2026-08-01T12:00:00+00:00', '2026-09-02T12:00:00+00:00', '2026-09-03T12:00:00+00:00', 'closed' ),
			'expired is never open' => array( '2025-01-01T12:00:00+00:00', '2025-01-31T12:00:00+00:00', '2026-09-03T12:00:00+00:00', 'closed' ),
		);
	}

	/**
	 * Verifies that derives opportunity state from dates.
	 *
	 * @param string $opens Opening instant.
	 * @param string $closes Closing instant.
	 * @param string $now Evaluation instant.
	 * @param string $expected Expected result.
	 */
	#[DataProvider( 'opportunity_states' )]
	public function test_derives_opportunity_state_from_dates( string $opens, string $closes, string $now, string $expected ): void {
		self::assertSame( $expected, TrustSurfacePolicy::opportunity_state( $opens, $closes, new DateTimeImmutable( $now ) ) );
	}

	/**
	 * Verifies that closed opportunity becomes noindex only after ninety days.
	 */
	public function test_closed_opportunity_becomes_noindex_only_after_ninety_days(): void {
		self::assertFalse( TrustSurfacePolicy::opportunity_is_noindex( '2026-06-06T12:00:00+00:00', new DateTimeImmutable( '2026-09-03T11:59:59+00:00' ) ) );
		self::assertTrue( TrustSurfacePolicy::opportunity_is_noindex( '2026-06-05T12:00:00+00:00', new DateTimeImmutable( '2026-09-03T12:00:00+00:00' ) ) );
	}

	/**
	 * Verifies that cancelled and postponed events keep explicit stable status.
	 */
	public function test_cancelled_and_postponed_events_keep_explicit_stable_status(): void {
		$now = new DateTimeImmutable( '2026-09-03T12:00:00+00:00' );
		self::assertSame( 'cancelled', TrustSurfacePolicy::event_state( 'cancelled', '2026-09-10T12:00:00+00:00', '2026-09-10T14:00:00+00:00', $now ) );
		self::assertSame( 'postponed', TrustSurfacePolicy::event_state( 'postponed', '2026-09-10T12:00:00+00:00', '2026-09-10T14:00:00+00:00', $now ) );
		self::assertSame( 'upcoming', TrustSurfacePolicy::event_state( 'scheduled', '2026-09-10T12:00:00+00:00', '2026-09-10T14:00:00+00:00', $now ) );
	}

	/**
	 * Verifies that public handoffs require role contact or approved external url.
	 */
	public function test_public_handoffs_require_role_contact_or_approved_external_url(): void {
		self::assertTrue( TrustSurfacePolicy::has_public_handoff( 'oportunidades@lps.ufrj.br', true, '', false ) );
		self::assertTrue( TrustSurfacePolicy::has_public_handoff( '', false, 'https://app.example.edu/apply', true ) );
		self::assertFalse( TrustSurfacePolicy::has_public_handoff( 'person@lps.ufrj.br', false, '', false ) );
		self::assertFalse( TrustSurfacePolicy::has_public_handoff( '', false, 'javascript:alert(1)', true ) );
	}

	/**
	 * Verifies that publish gate blocks absent contact and stale or unsourced claims.
	 */
	public function test_publish_gate_blocks_absent_contact_and_stale_or_unsourced_claims(): void {
		$opportunity = Policy::publish_errors(
			'lps_opportunity',
			array(
				'post_title'                    => 'Opportunity',
				'post_excerpt'                  => 'Summary',
				'post_content'                  => 'Body',
				'_lps_locale'                   => 'en',
				'_lps_opportunity_type'         => 'scholarship',
				'_lps_eligibility'              => 'Eligibility',
				'_lps_application_instructions' => 'Instructions',
				'_lps_opens_at'                 => '2026-09-01T12:00:00+00:00',
				'_lps_closes_at'                => '2026-09-20T12:00:00+00:00',
			)
		);
		self::assertSame( 'lps_public_handoff_required', $opportunity['_lps_contact'] );

		$claim = TrustSurfacePolicy::claim_errors(
			array(
				'_lps_claim_verified'    => true,
				'_lps_claim_source_url'  => '',
				'_lps_claim_reviewed_at' => '2025-01-01',
			),
			new DateTimeImmutable( '2026-09-03T12:00:00+00:00' )
		);
		self::assertSame( 'lps_claim_source_required', $claim['_lps_claim_source_url'] );
		self::assertSame( 'lps_claim_review_stale', $claim['_lps_claim_reviewed_at'] );
	}
}
