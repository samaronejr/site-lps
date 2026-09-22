<?php
/**
 * Offering-scoped editorial authorization contracts (task 4).
 *
 * @package LPS\ContentModel\Tests
 */

declare(strict_types=1);

namespace LPS\ContentModel\Tests;

require_once dirname( __DIR__ ) . '/includes/class-policy.php';
require_once dirname( __DIR__ ) . '/includes/class-securitypolicy.php';
require_once dirname( __DIR__ ) . '/includes/class-teachingpolicy.php';
require_once dirname( __DIR__ ) . '/includes/class-teachingcontracts.php';
require_once dirname( __DIR__ ) . '/includes/class-contracts.php';
require_once dirname( __DIR__ ) . '/includes/class-relationshippolicy.php';
require_once dirname( __DIR__ ) . '/includes/class-translationpolicy.php';

use LPS\ContentModel\Contracts;
use LPS\ContentModel\RelationshipPolicy;
use LPS\ContentModel\SecurityPolicy;
use LPS\ContentModel\TeachingPolicy;
use PHPUnit\Framework\TestCase;

/**
 * Proves the offering-scoped authorization contract before any WordPress boundary.
 *
 * The pure policy is the single source of truth: scoped roles hold no collection
 * or global rights, every scoped action needs a persisted active grant, input
 * IDs never create access, and denial is the default.
 */
final class LpsRedesignTask04Test extends TestCase {
	private const FIXTURE_DIR = __DIR__ . '/../../../../tests/fixtures/lps-redesign';
	private const NOW         = '2026-09-18T12:00:00+00:00';

	/**
	 * Reads one lps-redesign JSON fixture and enforces the shared schema.
	 *
	 * @return array<string, mixed>
	 */
	private static function load_fixture( string $name ): array {
		$file = self::FIXTURE_DIR . '/' . $name . '.json';
		self::assertFileExists( $file );
		$fixture = json_decode( (string) file_get_contents( $file ), true, 512, JSON_THROW_ON_ERROR );
		if ( ! is_array( $fixture ) ) {
			self::fail( $name . ' must decode to an object' );
		}
		self::assertSame( 1, $fixture['schemaVersion'] ?? null, $name . ' schemaVersion must equal 1' );
		self::assertTrue( $fixture['synthetic'] ?? null, $name . ' must declare synthetic data' );
		/** @var array<string, mixed> $fixture */
		return $fixture;
	}

	/**
	 * Reads one list-of-records field of a decoded fixture.
	 *
	 * @param array<string, mixed> $fixture Decoded fixture.
	 * @param string               $key     Field name.
	 * @return array<int, array<string, mixed>>
	 */
	private static function rows( array $fixture, string $key ): array {
		$value = $fixture[ $key ] ?? null;
		if ( ! is_array( $value ) ) {
			self::fail( $key . ' must be an array' );
		}
		/** @var array<int, array<string, mixed>> $value */
		return $value;
	}

	/**
	 * Converts boundary input to string.
	 *
	 * @param mixed $value Boundary input.
	 */
	private static function text( mixed $value ): string {
		return is_scalar( $value ) ? (string) $value : '';
	}

	/**
	 * Builds one normalized grant row.
	 *
	 * @param array<string, mixed> $overrides Field overrides.
	 * @return array{scope: string, offering_id: int, role: string, granted_at: string, expires_at: string, revoked_at: string, granted_by: int}
	 */
	private static function grant( array $overrides = array() ): array {
		return TeachingPolicy::normalize_grant(
			array_merge(
				array(
					'scope'       => 'offering',
					'offering_id' => 30,
					'role'        => 'professor',
					'granted_at'  => '2026-01-10T09:00:00+00:00',
					'expires_at'  => '',
					'revoked_at'  => '',
					'granted_by'  => 7,
				),
				$overrides
			)
		);
	}

	public function test_scoped_roles_extend_the_action_matrix(): void {
		foreach ( TeachingPolicy::SCOPED_ROLES as $role ) {
			self::assertContains( $role, SecurityPolicy::roles(), $role . ' must be a governed policy role' );
		}
		foreach ( array( 'create', 'edit', 'submit', 'publish', 'copy-forward' ) as $action ) {
			self::assertTrue( SecurityPolicy::allows( 'professor', $action ), 'professor ' . $action );
		}
		foreach ( array( 'create', 'edit', 'submit' ) as $action ) {
			self::assertTrue( SecurityPolicy::allows( 'delegate', $action ), 'delegate ' . $action );
		}
		foreach ( array( 'review', 'unpublish', 'archive', 'import', 'redirect', 'settings', 'audit', 'dormant-report', 'deploy', 'grant-scope', 'revoke-scope' ) as $action ) {
			self::assertFalse( SecurityPolicy::allows( 'professor', $action ), 'professor must not ' . $action );
			self::assertFalse( SecurityPolicy::allows( 'delegate', $action ), 'delegate must not ' . $action );
		}
		self::assertFalse( SecurityPolicy::allows( 'delegate', 'publish' ), 'delegates cannot publish' );
		self::assertFalse( SecurityPolicy::allows( 'delegate', 'copy-forward' ) );

		foreach ( array( 'grant-scope', 'revoke-scope' ) as $action ) {
			self::assertTrue( SecurityPolicy::allows( 'administrator', $action ), 'administrator ' . $action );
			self::assertTrue( SecurityPolicy::allows( 'section-editor', $action ), 'section-editor ' . $action );
			self::assertFalse( SecurityPolicy::allows( 'publisher', $action ), 'publisher must not ' . $action );
			self::assertContains( $action, SecurityPolicy::audited_actions(), $action . ' is auditable' );
		}
		self::assertContains( 'lps_grant_scope', SecurityPolicy::capabilities_for_role( 'administrator' ) );
		self::assertContains( 'lps_copy_forward', SecurityPolicy::capabilities_for_role( 'professor' ) );
		self::assertNotContains( 'lps_publish', SecurityPolicy::capabilities_for_role( 'delegate' ) );
	}

	public function test_assigned_professor_performs_the_required_scoped_actions(): void {
		$grants = array( self::grant() );
		foreach ( array( 'create', 'edit', 'submit' ) as $action ) {
			foreach ( array( 'lps_offering', 'lps_unit', 'lps_resource' ) as $post_type ) {
				self::assertNull(
					TeachingPolicy::scope_error( 'professor', $action, $post_type, 30, $grants, self::NOW ),
					"professor {$action} on {$post_type} inside the granted offering"
				);
			}
		}
		foreach ( array( 'lps_unit', 'lps_resource' ) as $post_type ) {
			self::assertNull(
				TeachingPolicy::scope_error( 'professor', 'publish', $post_type, 30, $grants, self::NOW ),
				"professor publishes cleared {$post_type} materials inside the granted offering"
			);
		}
		self::assertNull(
			TeachingPolicy::scope_error( 'professor', 'copy-forward', 'lps_offering', 30, $grants, self::NOW ),
			'professor may copy forward an assigned offering'
		);
		self::assertTrue( TeachingPolicy::has_scope( 'professor', 'offering', 30, $grants, self::NOW ) );
	}

	public function test_delegate_prepares_drafts_but_cannot_publish(): void {
		$grants = array( self::grant( array( 'role' => 'delegate' ) ) );
		foreach ( array( 'create', 'edit', 'submit' ) as $action ) {
			self::assertNull(
				TeachingPolicy::scope_error( 'delegate', $action, 'lps_resource', 30, $grants, self::NOW ),
				'delegate ' . $action . ' inside the granted offering'
			);
		}
		self::assertSame(
			'lps_teaching_action_forbidden',
			TeachingPolicy::scope_error( 'delegate', 'publish', 'lps_resource', 30, $grants, self::NOW ),
			'a delegate grant never publishes'
		);
		self::assertSame(
			'lps_teaching_action_forbidden',
			TeachingPolicy::scope_error( 'delegate', 'copy-forward', 'lps_offering', 30, $grants, self::NOW )
		);
		foreach ( array( '_lps_release_state', '_lps_release_at', '_lps_withdrawn_at', '_lps_news_status' ) as $field ) {
			self::assertFalse(
				TeachingPolicy::field_write_allowed( 'delegate', 'lps_resource', $field ),
				$field . ' changes public delivery and is professor-only'
			);
		}
		self::assertTrue( TeachingPolicy::field_write_allowed( 'professor', 'lps_resource', '_lps_release_state' ) );
	}

	public function test_unassigned_accounts_cannot_acquire_access_through_input_ids(): void {
		foreach ( array( 'create', 'edit', 'submit' ) as $action ) {
			self::assertSame(
				'lps_teaching_scope_required',
				TeachingPolicy::scope_error( 'professor', $action, 'lps_offering', 30, array(), self::NOW ),
				'no persisted grant means no access, whatever the request claims'
			);
		}
		self::assertSame(
			'lps_teaching_scope_required',
			TeachingPolicy::scope_error( 'professor', 'publish', 'lps_resource', 30, array(), self::NOW ),
			'even a publishable type denies without a persisted grant'
		);
		self::assertSame(
			'lps_teaching_scope_required',
			TeachingPolicy::scope_error( 'professor', 'copy-forward', 'lps_offering', 30, array(), self::NOW )
		);
		foreach ( TeachingPolicy::UNTRUSTED_SCOPE_INPUTS as $field ) {
			self::assertFalse(
				TeachingPolicy::scope_source_is_server_side( $field ),
				$field . ' is user-controlled and must never resolve scope'
			);
		}
		foreach ( TeachingPolicy::TRUSTED_SCOPE_SOURCES as $source ) {
			self::assertTrue( TeachingPolicy::scope_source_is_server_side( $source ) );
		}
		$forbidden = array(
			'lps_offering' => array( '_lps_owner_user_id', '_lps_record_id', '_lps_locale', '_lps_state', '_lps_claim_verified', '_lps_section_key', '_lps_lms_url', '_lps_lms_url_approved', '_lps_cancelled', '_lps_temporal_status', '_lps_teaching_team_ids' ),
			'lps_resource' => array( '_lps_version_id', '_lps_storage_key', '_lps_uploader_user_id', '_lps_sha256', '_lps_mime_type', '_lps_byte_size', '_lps_scan_state', '_lps_scan_version', '_lps_rights_review', '_lps_accessibility_review', '_lps_import_source_id' ),
			'lps_news'     => array( '_lps_featured_until', '_lps_translation_reviewer_id', '_lps_owner_user_id' ),
		);
		foreach ( $forbidden as $post_type => $fields ) {
			foreach ( $fields as $field ) {
				self::assertFalse(
					TeachingPolicy::field_write_allowed( 'professor', $post_type, $field ),
					"{$field} on {$post_type} must never be writable by a scoped role"
				);
			}
		}
	}

	public function test_cross_professor_and_wrong_scope_denials(): void {
		$grants = array( self::grant() );
		self::assertSame(
			'lps_teaching_scope_required',
			TeachingPolicy::scope_error( 'professor', 'edit', 'lps_offering', 31, $grants, self::NOW ),
			'a grant on offering 30 never covers offering 31'
		);
		self::assertSame(
			'lps_teaching_scope_required',
			TeachingPolicy::scope_error( 'delegate', 'edit', 'lps_offering', 30, $grants, self::NOW ),
			'a professor grant never serves a delegate account'
		);
		self::assertSame(
			'lps_teaching_scope_required',
			TeachingPolicy::scope_error( 'professor', 'edit', 'lps_news', 0, $grants, self::NOW ),
			'an offering grant never covers the news scope'
		);
		self::assertSame(
			'lps_teaching_role_not_scoped',
			TeachingPolicy::scope_error( 'publisher', 'edit', 'lps_offering', 30, $grants, self::NOW ),
			'non-scoped roles are outside the scoped boundary entirely'
		);
		self::assertSame(
			'lps_teaching_role_not_scoped',
			TeachingPolicy::scope_error( '', 'edit', 'lps_offering', 30, $grants, self::NOW )
		);
	}

	public function test_revocation_and_expiry_take_effect_immediately(): void {
		$active = self::grant();
		self::assertTrue( TeachingPolicy::grant_is_active( $active, self::NOW ) );
		self::assertNull( TeachingPolicy::scope_error( 'professor', 'edit', 'lps_offering', 30, array( $active ), self::NOW ) );

		$revoked               = $active;
		$revoked['revoked_at'] = '2026-09-18T11:59:59+00:00';
		self::assertFalse( TeachingPolicy::grant_is_active( $revoked, self::NOW ) );
		self::assertSame(
			'lps_teaching_grant_revoked',
			TeachingPolicy::scope_error( 'professor', 'edit', 'lps_offering', 30, array( $revoked ), self::NOW ),
			'a revoked grant denies on the very next check'
		);

		$expired               = $active;
		$expired['expires_at'] = '2026-09-18T12:00:00+00:00';
		self::assertFalse( TeachingPolicy::grant_is_active( $expired, self::NOW ), 'expiry is exclusive of the boundary instant' );
		self::assertSame(
			'lps_teaching_grant_expired',
			TeachingPolicy::scope_error( 'professor', 'edit', 'lps_offering', 30, array( $expired ), self::NOW )
		);

		$future               = $active;
		$future['expires_at'] = '2026-09-18T12:00:01+00:00';
		self::assertTrue( TeachingPolicy::grant_is_active( $future, self::NOW ) );

		$malformed               = $active;
		$malformed['expires_at'] = 'not-a-date';
		self::assertFalse( TeachingPolicy::grant_is_active( $malformed, self::NOW ), 'a malformed expiry never extends access' );
		self::assertFalse( TeachingPolicy::grant_is_active( $active, 'not-a-date' ), 'an unparseable clock never grants access' );
	}

	public function test_grant_management_authority_is_editorial_and_audited(): void {
		self::assertTrue( TeachingPolicy::may_manage_grants( 'administrator', array() ) );
		self::assertTrue( TeachingPolicy::may_manage_grants( 'section-editor', array( 'teaching' ) ) );
		self::assertFalse( TeachingPolicy::may_manage_grants( 'section-editor', array( 'news' ) ), 'section editors need the teaching collection' );
		foreach ( array( 'publisher', 'contributor', 'translator', 'privacy-auditor', 'deployer', 'professor', 'delegate', '' ) as $role ) {
			self::assertFalse( TeachingPolicy::may_manage_grants( $role, array( 'teaching' ) ), $role . ' must not manage grants' );
		}

		$candidate = array(
			'scope'       => 'offering',
			'offering_id' => 30,
			'role'        => 'professor',
			'granted_at'  => self::NOW,
		);
		self::assertNull(
			TeachingPolicy::grant_error( 'administrator', array(), 7, 42, 'professor', $candidate, array() )
		);
		self::assertSame(
			'lps_teaching_grant_forbidden',
			TeachingPolicy::grant_error( 'publisher', array(), 7, 42, 'professor', $candidate, array() )
		);
		self::assertSame(
			'lps_teaching_self_grant_forbidden',
			TeachingPolicy::grant_error( 'administrator', array(), 42, 42, 'professor', $candidate, array() ),
			'no account may grant itself scope'
		);
		self::assertSame(
			'lps_teaching_grant_role_mismatch',
			TeachingPolicy::grant_error( 'administrator', array(), 7, 42, 'delegate', $candidate, array() ),
			'the grant role must equal the target account role'
		);
		self::assertSame(
			'lps_teaching_grant_role_mismatch',
			TeachingPolicy::grant_error( 'administrator', array(), 7, 42, 'contributor', $candidate, array() ),
			'grants only exist for scoped roles'
		);
		self::assertSame(
			'lps_teaching_grant_invalid',
			TeachingPolicy::grant_error( 'administrator', array(), 7, 42, 'professor', array_merge( $candidate, array( 'scope' => 'everything' ) ), array() )
		);
		self::assertSame(
			'lps_teaching_grant_invalid',
			TeachingPolicy::grant_error( 'administrator', array(), 7, 42, 'professor', array_merge( $candidate, array( 'offering_id' => 0 ) ), array() )
		);
		self::assertSame(
			'lps_teaching_grant_invalid',
			TeachingPolicy::grant_error(
				'administrator',
				array(),
				7,
				42,
				'professor',
				array_merge(
					$candidate,
					array(
						'scope'       => 'news',
						'offering_id' => 30,
					)
				),
				array()
			),
			'the news scope never carries an offering ID'
		);
		self::assertSame(
			'lps_teaching_grant_invalid',
			TeachingPolicy::grant_error( 'administrator', array(), 7, 42, 'professor', array_merge( $candidate, array( 'expires_at' => '2026-01-01T00:00:00+00:00' ) ), array() ),
			'expiry must follow the grant instant'
		);
		self::assertSame(
			'lps_teaching_grant_duplicate',
			TeachingPolicy::grant_error( 'administrator', array(), 7, 42, 'professor', $candidate, array( self::grant() ) )
		);
		self::assertNull(
			TeachingPolicy::grant_error( 'administrator', array(), 7, 42, 'professor', $candidate, array( self::grant( array( 'revoked_at' => '2026-02-01T00:00:00+00:00' ) ) ) ),
			'a revoked grant may be re-granted'
		);

		$grants = array( self::grant() );
		self::assertNull( TeachingPolicy::revoke_error( 'administrator', array(), 7, 42, 0, $grants ) );
		self::assertSame( 'lps_teaching_grant_missing', TeachingPolicy::revoke_error( 'administrator', array(), 7, 42, 5, $grants ) );
		self::assertSame( 'lps_teaching_grant_forbidden', TeachingPolicy::revoke_error( 'professor', array(), 7, 42, 0, $grants ) );
		self::assertSame( 'lps_teaching_self_grant_forbidden', TeachingPolicy::revoke_error( 'administrator', array(), 42, 42, 0, $grants ) );
		$revoked_grants = array( self::grant( array( 'revoked_at' => '2026-02-01T00:00:00+00:00' ) ) );
		self::assertSame( 'lps_teaching_grant_missing', TeachingPolicy::revoke_error( 'administrator', array(), 7, 42, 0, $revoked_grants ) );
	}

	public function test_scoped_news_publishing_is_a_designated_grant(): void {
		$news_grant = self::grant(
			array(
				'scope'       => 'news',
				'offering_id' => 0,
			)
		);
		self::assertNull(
			TeachingPolicy::scope_error( 'professor', 'publish', 'lps_news', 0, array( $news_grant ), self::NOW ),
			'a designated faculty news editor publishes news directly'
		);
		self::assertSame(
			'lps_teaching_scope_required',
			TeachingPolicy::scope_error( 'professor', 'publish', 'lps_news', 0, array( self::grant() ), self::NOW ),
			'an offering grant never covers news'
		);
		self::assertSame(
			'lps_teaching_action_forbidden',
			TeachingPolicy::scope_error(
				'delegate',
				'publish',
				'lps_news',
				0,
				array(
					self::grant(
						array(
							'scope'       => 'news',
							'offering_id' => 0,
							'role'        => 'delegate',
						),
					),
				),
				self::NOW
			),
			'a delegate news grant still cannot publish'
		);
	}

	public function test_publishing_roles_meet_the_mfa_contract(): void {
		foreach ( array( 'publisher', 'administrator', 'professor' ) as $role ) {
			self::assertTrue( SecurityPolicy::requires_mfa( $role ), $role . ' holds public publishing authority' );
			self::assertFalse( SecurityPolicy::privileged_session_allowed( $role, false ), $role . ' without enrollment keeps no privileges' );
			self::assertTrue( SecurityPolicy::privileged_session_allowed( $role, true ) );
		}
		foreach ( array( 'delegate', 'contributor', 'translator', 'section-editor', 'privacy-auditor', 'deployer' ) as $role ) {
			self::assertFalse( SecurityPolicy::requires_mfa( $role ), $role );
		}
	}

	public function test_no_broad_global_editing_shortcuts_exist(): void {
		$grants = array( self::grant() );
		foreach ( array( 'page', 'lps_person', 'lps_course', 'lps_term', 'lps_project', 'lps_publication', 'lps_opportunity', 'lps_event', 'lps_redirect', 'lps_organization', 'lps_research_area' ) as $post_type ) {
			self::assertSame(
				'lps_teaching_scope_post_type',
				TeachingPolicy::scope_error( 'professor', 'edit', $post_type, 30, $grants, self::NOW ),
				"professor edit on {$post_type} is outside every scope"
			);
		}
		self::assertSame(
			'lps_teaching_publish_type_forbidden',
			TeachingPolicy::scope_error( 'professor', 'publish', 'lps_offering', 30, $grants, self::NOW ),
			'offering publication stays with institutional editors'
		);
		foreach ( array( 'delete', 'unpublish', 'archive', 'review', 'import', 'redirect', 'settings', 'audit', 'grant-scope', 'revoke-scope' ) as $action ) {
			self::assertSame(
				'lps_teaching_action_forbidden',
				TeachingPolicy::scope_error( 'professor', $action, 'lps_offering', 30, $grants, self::NOW ),
				'professor ' . $action
			);
		}
		self::assertSame(
			'lps_teaching_action_forbidden',
			TeachingPolicy::scope_error( 'professor', 'copy-forward', 'lps_unit', 30, $grants, self::NOW ),
			'copy-forward applies to offerings only'
		);
		foreach ( TeachingPolicy::EDITOR_ONLY_POST_TYPES as $post_type ) {
			self::assertNotContains( $post_type, TeachingPolicy::SCOPED_POST_TYPES );
			self::assertSame( '', TeachingPolicy::scope_for_post_type( $post_type ), $post_type . ' is editor-owned, never scoped' );
		}
	}

	public function test_scoped_field_allowlist_is_minimal_and_complete(): void {
		$fields = Contracts::meta_fields();
		foreach ( TeachingPolicy::SCOPED_POST_TYPES as $post_type ) {
			foreach ( array_keys( $fields[ $post_type ] ) as $meta_key ) {
				$allowed = TeachingPolicy::field_write_allowed( 'professor', $post_type, $meta_key );
				if ( $allowed ) {
					self::assertContains(
						$meta_key,
						array( '_lps_schedule', '_lps_venue', '_lps_syllabus_snapshot', '_lps_anchor', '_lps_position', '_lps_topic_date', '_lps_resource_type', '_lps_resource_language', '_lps_external_url', '_lps_release_state', '_lps_release_at', '_lps_withdrawn_at', '_lps_canonical_date', '_lps_news_status' ),
						"{$meta_key} on {$post_type} entered the scoped allowlist without review"
					);
				}
			}
		}
		foreach ( array( 'post_title', 'post_excerpt', 'post_content' ) as $field ) {
			foreach ( TeachingPolicy::SCOPED_POST_TYPES as $post_type ) {
				self::assertTrue( TeachingPolicy::field_write_allowed( 'professor', $post_type, $field ), $field );
				self::assertTrue( TeachingPolicy::field_write_allowed( 'delegate', $post_type, $field ), $field );
			}
		}
		self::assertTrue( TeachingPolicy::field_write_allowed( 'contributor', 'lps_offering', '_lps_owner_user_id' ), 'non-scoped roles keep their own boundary' );
	}

	public function test_person_identity_and_history_survive_account_deactivation(): void {
		$person_keys = array_keys( Contracts::meta_fields()['lps_person'] );
		self::assertTrue(
			TeachingPolicy::person_identity_survives_deactivation( $person_keys ),
			'no Person identity field may reference an account'
		);
		foreach ( $person_keys as $key ) {
			if ( str_contains( $key, 'user_id' ) ) {
				self::assertContains( $key, TeachingPolicy::ACCOUNT_LINKAGE_FIELDS, $key . ' is accountability metadata, never identity' );
			}
		}
		self::assertNotContains( '_lps_user_id', $person_keys );
		self::assertSame( array(), array_intersect( TeachingPolicy::person_identity_fields(), TeachingPolicy::ACCOUNT_LINKAGE_FIELDS ) );

		$specs = RelationshipPolicy::relation_specs();
		self::assertSame( array( 'lps_person' ), $specs['teaching_team']['target_types'], 'teaching attribution targets Person records, not accounts' );
		foreach ( array( 'active', 'alumni', 'departed', 'deceased', 'in-memoriam' ) as $status ) {
			self::assertTrue( RelationshipPolicy::historical_target_allowed( $status ), $status . ' keeps teaching history' );
		}
	}

	public function test_grant_normalization_drops_malformed_rows(): void {
		$normalized = TeachingPolicy::normalize_grants(
			array(
				'not-an-array',
				array( 'scope' => 'offering' ),
				self::grant(),
				array(
					'scope'       => 'news',
					'role'        => 'delegate',
					'granted_at'  => '2026-01-01T00:00:00+00:00',
					'granted_by'  => 7,
					'offering_id' => 0,
				),
			)
		);
		self::assertCount( 2, $normalized );
		self::assertSame( 'offering', $normalized[0]['scope'] );
		self::assertSame( 'news', $normalized[1]['scope'] );

		$empty = TeachingPolicy::normalize_grant( 'garbage' );
		self::assertSame( '', $empty['scope'] );
		self::assertFalse( TeachingPolicy::grant_shape_valid( $empty ) );
		self::assertFalse(
			TeachingPolicy::grant_shape_valid(
				self::grant(
					array(
						'scope'       => 'news',
						'offering_id' => 9,
					)
				)
			)
		);
		self::assertFalse( TeachingPolicy::grant_shape_valid( self::grant( array( 'role' => 'publisher' ) ) ) );
		self::assertFalse( TeachingPolicy::grant_shape_valid( self::grant( array( 'granted_by' => 0 ) ) ) );
		self::assertTrue( TeachingPolicy::grant_shape_valid( self::grant() ) );
	}

	public function test_denial_codes_are_stable_machine_strings(): void {
		$codes = array(
			TeachingPolicy::scope_error( '', 'edit', 'lps_offering', 30, array(), self::NOW ),
			TeachingPolicy::scope_error( 'professor', 'delete', 'lps_offering', 30, array(), self::NOW ),
			TeachingPolicy::scope_error( 'professor', 'edit', 'lps_person', 0, array(), self::NOW ),
			TeachingPolicy::scope_error( 'professor', 'publish', 'lps_offering', 30, array( self::grant() ), self::NOW ),
			TeachingPolicy::scope_error( 'professor', 'edit', 'lps_offering', 30, array(), self::NOW ),
			TeachingPolicy::scope_error( 'professor', 'edit', 'lps_offering', 30, array( self::grant( array( 'revoked_at' => '2026-02-01T00:00:00+00:00' ) ) ), self::NOW ),
			TeachingPolicy::scope_error( 'professor', 'edit', 'lps_offering', 30, array( self::grant( array( 'expires_at' => '2026-02-01T00:00:00+00:00' ) ) ), self::NOW ),
			TeachingPolicy::grant_error( 'publisher', array(), 1, 2, 'professor', array(), array() ),
			TeachingPolicy::revoke_error( 'administrator', array(), 1, 2, 9, array() ),
		);
		foreach ( $codes as $code ) {
			self::assertIsString( $code );
			self::assertMatchesRegularExpression( '/^lps_[a-z0-9_]+$/', $code, $code . ' must be a stable machine code' );
		}
	}

	public function test_faculty_fixture_accounts_conform_to_the_scope_contract(): void {
		$faculty = self::load_fixture( 'faculty' );
		$people  = array_column( self::rows( $faculty, 'people' ), null, 'id' );
		foreach ( self::rows( $faculty, 'accounts' ) as $account ) {
			$login = self::text( $account['login'] ?? '' );
			self::assertFalse(
				SecurityPolicy::shared_account_name_forbidden( $login ),
				$login . ' must be an individual account name'
			);
			self::assertArrayHasKey( self::text( $account['person'] ?? '' ), $people, 'accounts reference Person records' );
			if ( 'professor' === $account['role'] ) {
				self::assertTrue( $account['mfaEnrolled'] ?? null, self::text( $account['id'] ?? '' ) . ' publishes publicly and must enroll MFA' );
			}
			if ( 'delegate' === $account['role'] ) {
				self::assertFalse( $account['mfaEnrolled'] ?? null, self::text( $account['id'] ?? '' ) . ' never publishes, so MFA is not required' );
			}
			self::assertContains( $account['role'] ?? null, TeachingPolicy::SCOPED_ROLES );
		}
		foreach ( self::rows( $faculty, 'people' ) as $person ) {
			self::assertArrayNotHasKey( 'userId', $person, 'public Person records carry no account key' );
			self::assertArrayNotHasKey( 'account', $person );
		}
	}
}
