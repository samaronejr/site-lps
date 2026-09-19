<?php
/**
 * Safe next-term copy-forward and correction-history contracts (task 15).
 *
 * @package LPS\ContentModel\Tests
 */

declare(strict_types=1);

namespace LPS\ContentModel\Tests;

require_once dirname( __DIR__ ) . '/includes/class-policy.php';
require_once dirname( __DIR__ ) . '/includes/class-contracts.php';
require_once dirname( __DIR__ ) . '/includes/class-relationshippolicy.php';
require_once dirname( __DIR__ ) . '/includes/class-teachingcontracts.php';
require_once dirname( __DIR__ ) . '/includes/class-teachingmigrations.php';
require_once dirname( __DIR__ ) . '/includes/class-translationpolicy.php';
require_once dirname( __DIR__ ) . '/includes/class-teachingcopy.php';

use LPS\ContentModel\Contracts;
use LPS\ContentModel\Policy;
use LPS\ContentModel\TeachingContracts;
use LPS\ContentModel\TeachingCopy;
use PHPUnit\Framework\TestCase;

/**
 * Proves the task-15 copy-forward and correction contracts without WordPress.
 *
 * Field carry/reset decisions, the released-version reuse gate, correction
 * field validation and the revision-enabled field set are all pure policy:
 * they run here directly. Database-backed behavior — draft creation,
 * idempotent retries, rollback, scoped authorization, revision creation and
 * audit — is exercised by the e2e spec against the real WordPress boundary.
 */
final class LpsRedesignTask15Test extends TestCase {
	private const NOW = '2026-09-19T12:00:00+00:00';

	/**
	 * Returns a source offering meta view; tests mutate one field at a time.
	 *
	 * @return array<string, mixed>
	 */
	private static function offering_meta(): array {
		return array(
			'_lps_section_key'       => 't01',
			'_lps_schedule'          => 'Ter/Qui 10h-12h',
			'_lps_venue'             => 'Sala 201',
			'_lps_syllabus_snapshot' => 'Ementa publicada 2025.2',
			'_lps_lms_url'           => 'https://lms.example.org/turma-2025-2',
			'_lps_lms_url_approved'  => true,
			'_lps_cancelled'         => true,
			'_lps_temporal_status'   => 'completed',
		);
	}

	/**
	 * Returns a source resource meta view; tests mutate one field at a time.
	 *
	 * @return array<string, mixed>
	 */
	private static function resource_meta(): array {
		return array(
			'_lps_resource_type'        => 'document',
			'_lps_resource_language'    => 'pt-br',
			'_lps_version_id'           => 'lpsver:' . str_repeat( 'a', 64 ),
			'_lps_external_url'         => '',
			'_lps_release_state'        => 'released',
			'_lps_release_at'           => '',
			'_lps_withdrawn_at'         => '',
			'_lps_rights_review'        => 'approved',
			'_lps_accessibility_review' => 'approved',
		);
	}

	public function test_offering_copy_fields_carry_structure_and_reset_sensitive_values(): void {
		$fields = TeachingCopy::offering_copy_fields( self::offering_meta(), 'Ementa do curso' );

		// Descriptive structure and the published syllabus snapshot copy forward.
		self::assertSame( 'Ter/Qui 10h-12h', $fields['_lps_schedule'] );
		self::assertSame( 'Sala 201', $fields['_lps_venue'] );
		self::assertSame( 'Ementa publicada 2025.2', $fields['_lps_syllabus_snapshot'] );

		// Sensitive and term-bound fields are reset, never carried.
		self::assertSame( '', $fields['_lps_lms_url'], 'the term-bound LMS link must be reset' );
		self::assertFalse( $fields['_lps_lms_url_approved'], 'the LMS approval must be reset' );
		self::assertFalse( $fields['_lps_cancelled'], 'the source cancellation flag must be reset' );

		// Identity and temporal fields are never copied: the new draft derives
		// its own section identity and temporal status from the target term.
		self::assertArrayNotHasKey( '_lps_section_key', $fields );
		self::assertArrayNotHasKey( '_lps_temporal_status', $fields );
	}

	public function test_offering_copy_fields_snapshots_the_course_syllabus_when_absent(): void {
		$meta                              = self::offering_meta();
		$meta['_lps_syllabus_snapshot']    = '';
		$fields                            = TeachingCopy::offering_copy_fields( $meta, 'Ementa do curso' );
		self::assertSame( 'Ementa do curso', $fields['_lps_syllabus_snapshot'], 'an absent snapshot falls back to the course syllabus' );

		$meta['_lps_syllabus_snapshot'] = '  ';
		$fields                         = TeachingCopy::offering_copy_fields( $meta, 'Ementa do curso' );
		self::assertSame( 'Ementa do curso', $fields['_lps_syllabus_snapshot'], 'a blank snapshot falls back too' );
	}

	public function test_unit_copy_fields_preserve_order_and_reset_topic_dates(): void {
		$fields = TeachingCopy::unit_copy_fields(
			array(
				'_lps_anchor'     => 'unidade-3',
				'_lps_position'   => 3,
				'_lps_topic_date' => '2025-10-14',
			)
		);
		self::assertSame( 'unidade-3', $fields['_lps_anchor'] );
		self::assertSame( 3, $fields['_lps_position'] );
		self::assertSame( '', $fields['_lps_topic_date'], 'topic dates are bound to the source term and must reset' );
	}

	public function test_resource_copy_decision_reuses_only_explicit_public_versions(): void {
		$released = self::resource_meta();
		$selected = array( 'lpsver:' . str_repeat( 'a', 64 ) );

		// An explicitly selected, effectively released version is reused.
		$decision = TeachingCopy::resource_copy_decision( $released, $selected, self::NOW );
		self::assertNull( $decision['error'] );
		self::assertTrue( $decision['reuse'] );
		self::assertTrue( $decision['carry_public'] );

		// A released version that was not selected is not reused — the clone
		// arrives as an empty draft stub instead of a public copy.
		$decision = TeachingCopy::resource_copy_decision( $released, array(), self::NOW );
		self::assertNull( $decision['error'] );
		self::assertFalse( $decision['reuse'] );
		self::assertFalse( $decision['carry_public'] );

		// An unreleased resource that references a selected version is reset,
		// never a copy blocker — selection validity is gated at plan level.
		$draft = array_merge( $released, array( '_lps_release_state' => 'draft' ) );
		$decision = TeachingCopy::resource_copy_decision( $draft, $selected, self::NOW );
		self::assertNull( $decision['error'] );
		self::assertFalse( $decision['reuse'] );
		self::assertFalse( $decision['carry_public'] );

		// A withdrawn resource referencing a selected version is reset too.
		$withdrawn = array_merge( $released, array( '_lps_release_state' => 'withdrawn' ) );
		$decision  = TeachingCopy::resource_copy_decision( $withdrawn, $selected, self::NOW );
		self::assertNull( $decision['error'] );
		self::assertFalse( $decision['reuse'] );

		// A future-scheduled release is not yet public: no reuse, no carry.
		$scheduled = array_merge(
			$released,
			array(
				'_lps_release_state' => 'scheduled',
				'_lps_release_at'    => '2999-01-01T00:00:00+00:00',
			)
		);
		$decision = TeachingCopy::resource_copy_decision( $scheduled, $selected, self::NOW );
		self::assertNull( $decision['error'] );
		self::assertFalse( $decision['reuse'] );
		$decision = TeachingCopy::resource_copy_decision( $scheduled, array(), self::NOW );
		self::assertNull( $decision['error'] );
		self::assertFalse( $decision['carry_public'] );

		// A due schedule is already public, so its version may be selected.
		$due = array_merge(
			$released,
			array(
				'_lps_release_state' => 'scheduled',
				'_lps_release_at'    => '2020-01-01T00:00:00+00:00',
			)
		);
		$decision = TeachingCopy::resource_copy_decision( $due, $selected, self::NOW );
		self::assertNull( $decision['error'] );
		self::assertTrue( $decision['reuse'] );
	}

	public function test_resource_copy_decision_carries_public_external_urls_only(): void {
		$external = array_merge(
			self::resource_meta(),
			array(
				'_lps_version_id'   => '',
				'_lps_external_url' => 'https://example.org/aula.pdf',
			)
		);
		$decision = TeachingCopy::resource_copy_decision( $external, array(), self::NOW );
		self::assertNull( $decision['error'] );
		self::assertFalse( $decision['reuse'] );
		self::assertTrue( $decision['carry_public'], 'a released external resource keeps its public URL' );

		$unreleased = array_merge( $external, array( '_lps_release_state' => 'draft' ) );
		$decision   = TeachingCopy::resource_copy_decision( $unreleased, array(), self::NOW );
		self::assertNull( $decision['error'] );
		self::assertFalse( $decision['carry_public'], 'an unreleased external URL is reset, not carried' );
	}

	public function test_resource_copy_fields_reset_release_and_review_state(): void {
		$meta = self::resource_meta();

		// Reused public version: reviews and the public payload carry forward.
		$fields = TeachingCopy::resource_copy_fields(
			$meta,
			array(
				'reuse'        => true,
				'carry_public' => true,
			)
		);
		self::assertSame( 'document', $fields['_lps_resource_type'] );
		self::assertSame( 'pt-br', $fields['_lps_resource_language'] );
		self::assertSame( 'approved', $fields['_lps_rights_review'] );
		self::assertSame( 'approved', $fields['_lps_accessibility_review'] );
		self::assertSame( 'draft', $fields['_lps_release_state'], 'the clone is always a draft' );
		self::assertSame( '', $fields['_lps_release_at'] );
		self::assertSame( '', $fields['_lps_withdrawn_at'] );
		self::assertArrayNotHasKey( '_lps_version_id', $fields, 'version selection is explicit, never copied' );
		self::assertArrayNotHasKey( '_lps_storage_key', $fields );
		self::assertArrayNotHasKey( '_lps_sha256', $fields );

		// Non-carried resource: payload and reviews reset to pending.
		$fields = TeachingCopy::resource_copy_fields(
			$meta,
			array(
				'reuse'        => false,
				'carry_public' => false,
			)
		);
		self::assertSame( '', $fields['_lps_external_url'] );
		self::assertSame( 'pending', $fields['_lps_rights_review'] );
		self::assertSame( 'pending', $fields['_lps_accessibility_review'] );
		self::assertSame( 'draft', $fields['_lps_release_state'] );
	}

	public function test_correction_fields_error_enforces_the_correctable_allowlist(): void {
		self::assertSame( 'lps_correction_fields_required', TeachingCopy::correction_fields_error( array() ) );
		self::assertSame(
			'lps_correction_field_forbidden',
			TeachingCopy::correction_fields_error( array( '_lps_section_key' => 't02' ) ),
			'identity fields are never correctable'
		);
		self::assertSame(
			'lps_correction_field_forbidden',
			TeachingCopy::correction_fields_error( array( '_lps_state' => 'published' ) ),
			'editorial state is never correctable through propagation'
		);
		self::assertSame(
			'lps_correction_field_forbidden',
			TeachingCopy::correction_fields_error( array( '_lps_temporal_status' => 'cancelled' ) ),
			'derived temporal status is never correctable'
		);
		self::assertSame(
			'lps_correction_field_forbidden',
			TeachingCopy::correction_fields_error( array( 'post_title' => 'x' ) ),
			'post fields are outside the propagation contract'
		);
		self::assertNull(
			TeachingCopy::correction_fields_error(
				array(
					'_lps_schedule'          => 'Seg/Qua 14h',
					'_lps_syllabus_snapshot' => 'Ementa corrigida',
					'_lps_cancelled'         => true,
				)
			)
		);
	}

	public function test_correctable_fields_are_revisioned_and_system_owned(): void {
		// The correctable allowlist is exactly the six declared fields.
		self::assertSame(
			array( '_lps_schedule', '_lps_venue', '_lps_syllabus_snapshot', '_lps_lms_url', '_lps_lms_url_approved', '_lps_cancelled' ),
			TeachingContracts::CORRECTABLE_OFFERING_FIELDS
		);

		// Every correctable field is registered and revision-enabled so a
		// propagated correction preserves the prior value in the record's own
		// WordPress revision history.
		$fields = Contracts::meta_fields()['lps_offering'];
		foreach ( TeachingContracts::CORRECTABLE_OFFERING_FIELDS as $key ) {
			self::assertArrayHasKey( $key, $fields, $key . ' must be a registered offering field' );
			self::assertTrue( $fields[ $key ]['revisions_enabled'] ?? false, $key . ' must ride revisions' );
		}

		// The copy-forward provenance fields are registered as system-owned.
		self::assertArrayHasKey( '_lps_copy_operation_id', $fields );
		self::assertArrayHasKey( '_lps_copy_source_offering_id', $fields );
		$ownership = TeachingContracts::field_ownership( 'lps_offering' );
		self::assertSame( 'system', $ownership['_lps_copy_operation_id'] ?? '' );
		self::assertSame( 'system', $ownership['_lps_copy_source_offering_id'] ?? '' );
	}

	public function test_operation_ids_follow_the_normalized_contract(): void {
		self::assertSame( 'copy-2025-2-to-2026-1', TeachingContracts::normalize_operation_id( ' Copy-2025-2-to-2026-1 ' ) );
		self::assertSame( '', TeachingContracts::normalize_operation_id( 'short' ), 'operation IDs require 8+ characters' );
		self::assertSame( '', TeachingContracts::normalize_operation_id( 'has spaces here' ) );
		self::assertSame( '', TeachingContracts::normalize_operation_id( '' ) );
		self::assertSame( '', TeachingContracts::normalize_operation_id( 12345 ) );
	}

	public function test_copy_forward_contract_still_gates_the_service_plan(): void {
		// The service builds this exact plan shape; the task-3 contract is the
		// final gate, so the required resets and draft-only invariants hold.
		$plan = array(
			'operation_id'         => 'copy-2025-2-to-2026-1',
			'source_offering_id'   => 30,
			'source_term_id'       => 20,
			'source_section'       => 't01',
			'new_term_id'          => 21,
			'new_section'          => 't01',
			'creates_draft'        => true,
			'publishes'            => false,
			'team_reviewed'        => true,
			'resets'               => array( 'announcements', 'deadlines', 'release_times', 'active_notices', 'unreleased_resources' ),
			'selected_version_ids' => array(),
			'cleared_version_ids'  => array(),
		);
		self::assertNull( TeachingContracts::copy_forward_plan_error( $plan ) );
		$manifest = TeachingContracts::copy_forward_manifest( $plan );
		self::assertTrue( $manifest['creates_draft'] );
		self::assertFalse( $manifest['publishes'] );
		self::assertTrue( $manifest['atomic'] );
		self::assertTrue( $manifest['idempotent_retry'] );

		// The same term and section is an identity collision, never a copy.
		$plan['new_term_id'] = 20;
		self::assertSame( 'lps_copy_forward_identity_collision', TeachingContracts::copy_forward_plan_error( $plan ) );
	}
}
