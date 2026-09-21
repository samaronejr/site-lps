<?php
/**
 * Faculty task-dashboard contracts (task 16).
 *
 * @package LPS\ContentModel\Tests
 */

declare(strict_types=1);

namespace LPS\ContentModel\Tests;

require_once dirname( __DIR__ ) . '/includes/class-policy.php';
require_once dirname( __DIR__ ) . '/includes/class-securitypolicy.php';
require_once dirname( __DIR__ ) . '/includes/class-teachingcontracts.php';
require_once dirname( __DIR__ ) . '/includes/class-teachingpolicy.php';
require_once dirname( __DIR__ ) . '/includes/class-taskdashboard.php';

use LPS\ContentModel\TaskDashboard;
use PHPUnit\Framework\TestCase;

/**
 * Proves the task-16 dashboard contracts without WordPress.
 *
 * Task visibility, lifecycle-state mapping, proposal normalization and
 * validation, team-input normalization and the notice/error vocabulary are
 * pure policy: they run here directly. Persisted grants, scoped writes,
 * review decisions, uploads and the copy-forward journey are exercised by
 * the e2e spec against the real WordPress boundary.
 */
final class LpsRedesignTask16Test extends TestCase {
	/** The task list mirrors the authorization boundary, never widens it. */
	public function test_tasks_for_role_reflects_scope(): void {
		// A professor with an offering, a person record and a news grant sees
		// the full scoped task set but never the editor-only create task.
		self::assertSame(
			array( 'profile', 'offerings', 'news' ),
			TaskDashboard::tasks_for_role( 'professor', true, true, true, false )
		);
		// A professor with no offerings keeps the profile task only.
		self::assertSame(
			array( 'profile' ),
			TaskDashboard::tasks_for_role( 'professor', false, false, true, false )
		);
		// A delegate sees the same scoped tasks; publish is a per-record gate,
		// not a task-list entry.
		self::assertSame(
			array( 'profile', 'offerings' ),
			TaskDashboard::tasks_for_role( 'delegate', true, false, true, false )
		);
		// An editor with a review duty sees the queue and the create task.
		self::assertSame(
			array( 'review', 'create-offering' ),
			TaskDashboard::tasks_for_role( 'section-editor', false, false, false, true )
		);
		// An account with nothing resolvable sees an empty task list.
		self::assertSame( array(), TaskDashboard::tasks_for_role( 'professor', false, false, false, false ) );
	}

	/** Lifecycle states map to distinct, honest keys. */
	public function test_state_key_never_claims_success(): void {
		self::assertSame( 'draft', TaskDashboard::state_key( 'draft', 'draft' ) );
		self::assertSame( 'in-review', TaskDashboard::state_key( 'draft', 'in_review' ) );
		self::assertSame( 'public', TaskDashboard::state_key( 'publish', 'published' ) );
		self::assertSame( 'public', TaskDashboard::state_key( 'publish', 'draft' ) );
		self::assertSame( 'scheduled', TaskDashboard::state_key( 'publish', 'published', 'scheduled' ) );
		self::assertSame( 'withdrawn', TaskDashboard::state_key( 'publish', 'published', 'withdrawn' ) );
		self::assertSame( 'archived', TaskDashboard::state_key( 'lps_archived', 'archived' ) );
		// A draft post with a released material flag still reads as a draft:
		// the record is not public until the post itself publishes.
		self::assertSame( 'draft', TaskDashboard::state_key( 'draft', 'draft', 'released' ) );
	}

	/** Proposal normalization drops unknown keys and malformed rows. */
	public function test_normalize_proposals_filters_rows(): void {
		$proposals = TaskDashboard::normalize_proposals(
			array(
				array(
					'id'           => 'p1',
					'fields'       => array( '_lps_orcid' => '0000-0001-2345-6789', '_lps_secret' => 'x' ),
					'state'        => 'pending',
					'submitted_at' => '2026-09-19T12:00:00+00:00',
					'extra'        => 'dropped',
				),
				array( 'id' => '', 'fields' => array( '_lps_orcid' => 'x' ) ),
				'not-an-array',
				array( 'id' => 'p2', 'fields' => array(), 'state' => 'bogus' ),
			)
		);
		self::assertCount( 1, $proposals );
		self::assertSame( 'p1', $proposals[0]['id'] );
		self::assertSame( array( '_lps_orcid' => '0000-0001-2345-6789' ), $proposals[0]['fields'] );
		self::assertSame( 'pending', $proposals[0]['state'] );
		self::assertArrayNotHasKey( 'extra', $proposals[0] );
	}

	/** Proposal validation names the field and the fix. */
	public function test_proposal_errors_validate_typed_fields(): void {
		self::assertSame(
			array( 'fields' => 'lps_dashboard_proposal_empty' ),
			TaskDashboard::proposal_errors( array( 'fields' => array() ) )
		);
		self::assertSame(
			array( '_lps_public_email' => 'lps_invalid_email' ),
			TaskDashboard::proposal_errors( array( 'fields' => array( '_lps_public_email' => 'not-an-email' ) ) )
		);
		self::assertSame(
			array( '_lps_orcid' => 'lps_invalid_orcid' ),
			TaskDashboard::proposal_errors( array( 'fields' => array( '_lps_orcid' => '1234' ) ) )
		);
		self::assertSame(
			array( '_lps_website_url' => 'lps_invalid_url' ),
			TaskDashboard::proposal_errors( array( 'fields' => array( '_lps_website_url' => 'javascript:alert(1)' ) ) )
		);
		self::assertSame(
			array( '_lps_syllabus' => 'lps_dashboard_field_forbidden' ),
			TaskDashboard::proposal_errors( array( 'fields' => array( '_lps_syllabus' => 'x', '_lps_orcid' => '0000-0001-2345-6789' ) ) )
		);
		self::assertSame(
			array(),
			TaskDashboard::proposal_errors( array( 'fields' => array( '_lps_orcid' => '0000-0001-2345-6789', 'post_excerpt' => 'Resumo' ) ) )
		);
	}

	/** Copy-forward team input keeps only real person IDs and known roles. */
	public function test_team_from_input_filters_rows(): void {
		self::assertSame(
			array(
				array( 'person_id' => 7, 'role' => 'lead' ),
				array( 'person_id' => 9, 'role' => 'assistant' ),
			),
			TaskDashboard::team_from_input(
				array(
					array( 'person_id' => 7, 'role' => 'lead' ),
					array( 'person_id' => 0, 'role' => 'lead' ),
					array( 'person_id' => 9, 'role' => 'assistant' ),
					array( 'person_id' => 4, 'role' => 'owner' ),
					'junk',
				)
			)
		);
		self::assertSame( array(), TaskDashboard::team_from_input( 'junk' ) );
	}

	/** Every state key and notice code resolves to a localized message. */
	public function test_labels_and_messages_resolve_in_both_locales(): void {
		foreach ( array( 'draft', 'in-review', 'scheduled', 'public', 'withdrawn', 'archived', 'pending', 'approved', 'rejected' ) as $state ) {
			self::assertNotSame( $state, TaskDashboard::state_label( $state, 'pt-br' ) );
			self::assertNotSame( $state, TaskDashboard::state_label( $state, 'en' ) );
		}
		foreach ( array( 'saved', 'created', 'published', 'submitted', 'proposal-sent', 'proposal-approved', 'proposal-rejected', 'reviewed', 'copied', 'copy-replayed', 'version-uploaded', 'version-selected', 'released', 'scheduled', 'withdrawn' ) as $code ) {
			self::assertNotSame( $code, TaskDashboard::notice_message( $code, 'pt-br' ) );
			self::assertNotSame( $code, TaskDashboard::notice_message( $code, 'en' ) );
		}
		foreach ( array( 'lps_dashboard_nonce', 'lps_dashboard_forbidden', 'lps_dashboard_scope', 'lps_dashboard_review_note', 'lps_dashboard_proposal_empty', 'lps_required_title', 'lps_invalid_orcid', 'lps_copy_forward_team_review_required', 'lps_resource_rights_not_approved' ) as $code ) {
			self::assertNotSame( $code, TaskDashboard::error_message( $code, 'pt-br' ) );
			self::assertNotSame( $code, TaskDashboard::error_message( $code, 'en' ) );
		}
		// An unknown error code still resolves to a sentence, never a raw key.
		self::assertStringContainsString( 'lps_unknown_code', TaskDashboard::error_message( 'lps_unknown_code', 'en' ) );
	}

	/** The proposal field allowlist stays narrower than the person schema. */
	public function test_proposal_fields_exclude_system_and_identity_fields(): void {
		$fields = TaskDashboard::proposal_fields();
		foreach ( array( '_lps_record_id', '_lps_locale', '_lps_state', '_lps_owner_user_id', '_lps_review_comments', '_lps_profile_proposals', '_lps_published_slug' ) as $forbidden ) {
			self::assertNotContains( $forbidden, $fields );
		}
		self::assertContains( '_lps_orcid', $fields );
		self::assertContains( 'post_excerpt', $fields );
	}
}
