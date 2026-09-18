<?php
/**
 * Teaching entity, uniqueness, and history contract tests (task 3).
 *
 * @package LPS\ContentModel\Tests
 */

declare(strict_types=1);

namespace LPS\ContentModel\Tests;

require_once dirname( __DIR__ ) . '/includes/class-policy.php';
require_once dirname( __DIR__ ) . '/includes/class-teachingcontracts.php';
require_once dirname( __DIR__ ) . '/includes/class-teachingmigrations.php';
require_once dirname( __DIR__ ) . '/includes/class-contracts.php';
require_once dirname( __DIR__ ) . '/includes/class-relationshippolicy.php';
require_once dirname( __DIR__ ) . '/includes/class-translationpolicy.php';

use LPS\ContentModel\Contracts;
use LPS\ContentModel\Policy;
use LPS\ContentModel\RelationshipPolicy;
use LPS\ContentModel\TeachingContracts;
use LPS\ContentModel\TeachingMigrations;
use LPS\ContentModel\TranslationPolicy;
use PHPUnit\Framework\TestCase;

/** Proves the teaching domain contracts before any storage boundary is crossed. */
final class LpsRedesignTask03Test extends TestCase {
	private const FIXTURE_DIR = __DIR__ . '/../../../../tests/fixtures/lps-redesign';

	/**
	 * Reads one lps-redesign JSON fixture and enforces the shared schema.
	 *
	 * @return array<string, mixed>
	 */
	private static function load_fixture( string $name ): array {
		$file = self::FIXTURE_DIR . '/' . $name . '.json';
		self::assertFileExists( $file );
		$fixture = json_decode( (string) file_get_contents( $file ), true, 512, JSON_THROW_ON_ERROR );
		self::assertSame( 1, $fixture['schemaVersion'], $name . ' schemaVersion must equal 1' );
		self::assertTrue( $fixture['synthetic'], $name . ' must declare synthetic data' );
		return $fixture;
	}

	public function test_registers_five_teaching_types_with_explicit_registration_calls(): void {
		$types = Contracts::post_types();
		foreach ( TeachingContracts::POST_TYPES as $post_type ) {
			self::assertArrayHasKey( $post_type, $types );
			self::assertTrue( $types[ $post_type ]['show_in_rest'], $post_type . ' must expose REST' );
		}
		self::assertTrue( $types['lps_course']['public'] );
		self::assertTrue( $types['lps_offering']['public'] );
		self::assertFalse( $types['lps_term']['public'] );
		self::assertFalse( $types['lps_unit']['public'] );
		self::assertFalse( $types['lps_resource']['public'] );

		$source = (string) file_get_contents( dirname( __DIR__ ) . '/includes/class-teachingcontracts.php' );
		foreach ( TeachingContracts::POST_TYPES as $post_type ) {
			self::assertStringContainsString( "register_post_type( '" . $post_type . "'", $source, 'the registration call for ' . $post_type . ' must be named explicitly' );
		}
	}

	public function test_every_teaching_field_has_type_normalization_visibility_and_ownership(): void {
		$fields = Contracts::meta_fields();
		foreach ( TeachingContracts::POST_TYPES as $post_type ) {
			self::assertArrayHasKey( $post_type, $fields );
			$ownership = TeachingContracts::field_ownership( $post_type );
			foreach ( $fields[ $post_type ] as $key => $definition ) {
				self::assertContains( $definition['type'], array( 'string', 'integer', 'number', 'boolean', 'array' ), $key );
				self::assertIsCallable( $definition['sanitize_callback'], $key . ' must declare normalization' );
				self::assertIsBool( $definition['show_in_rest'], $key . ' must declare visibility' );
				self::assertArrayHasKey( $key, $ownership, $key . ' must declare ownership' );
				self::assertContains( $ownership[ $key ], TeachingContracts::OWNERSHIP_KINDS, $key . ' ownership must be a known kind' );
			}
		}
		foreach ( TeachingContracts::private_meta_keys() as $private_key ) {
			self::assertFalse( $fields['lps_resource'][ $private_key ]['show_in_rest'], $private_key . ' must stay out of REST' );
		}
	}

	public function test_record_id_format_accepts_teaching_prefixes(): void {
		foreach ( array( 'course', 'term', 'offering', 'unit', 'resource' ) as $prefix ) {
			$record_id = 'lps:' . $prefix . ':018f21ce-7d7a-7abc-8a2f-2d6937f89a11';
			self::assertSame( $record_id, Policy::sanitize_record_id( strtoupper( $record_id ) ), $prefix . ' record IDs must keep the canonical format' );
		}
		self::assertSame( '', Policy::sanitize_record_id( 'lps:offering:not-a-uuid' ) );
	}

	public function test_section_keys_normalize_deterministically_and_collide(): void {
		self::assertSame( 'turma-a', TeachingContracts::normalize_section_key( ' Turma  A ' ) );
		self::assertSame( 'turma-a', TeachingContracts::normalize_section_key( 'TURMA-A' ) );
		self::assertSame( 'turma-a', TeachingContracts::normalize_section_key( 'turma a' ) );
		self::assertSame( '', TeachingContracts::normalize_section_key( '   ' ) );
		self::assertSame( '', TeachingContracts::normalize_section_key( array( 'a' ) ) );

		$first  = TeachingContracts::offering_identity( 10, 20, 'Turma A' );
		$second = TeachingContracts::offering_identity( 10, 20, 'turma a' );
		self::assertSame(
			TeachingContracts::offering_identity_hash( $first ),
			TeachingContracts::offering_identity_hash( $second ),
			'normalized section keys must collide deterministically so the registry can reject them'
		);
		$other = TeachingContracts::offering_identity( 10, 20, 'turma-b' );
		self::assertNotSame( TeachingContracts::offering_identity_hash( $first ), TeachingContracts::offering_identity_hash( $other ) );
	}

	public function test_term_identity_and_token_are_calendar_qualified(): void {
		$identity = TeachingContracts::term_identity( 'semester', '2026.1' );
		self::assertSame( 'semester', $identity['calendar_key'] );
		self::assertSame( '2026-1', $identity['term_code'] );
		self::assertSame( '2026-1-semester', $identity['token'] );

		$trimester = TeachingContracts::term_identity( 'trimester', '2026.1' );
		self::assertSame( '2026-1-trimester', $trimester['token'], 'the same period label on another calendar must produce a different token' );
		self::assertNotSame(
			TeachingContracts::term_identity_hash( $identity ),
			TeachingContracts::term_identity_hash( $trimester ),
			'two calendars cannot collide accidentally'
		);
		self::assertSame( '', TeachingContracts::term_token( '', '2026.1' ) );
	}

	public function test_english_variants_cannot_bypass_uniqueness(): void {
		$translations = array(
			'pt-br' => 501,
			'en'    => 502,
		);
		self::assertSame( 501, TeachingContracts::uniqueness_subject_id( 502, $translations ), 'an English variant resolves to the Portuguese authority' );
		self::assertSame( 501, TeachingContracts::uniqueness_subject_id( 501, $translations ) );
		self::assertSame( 777, TeachingContracts::uniqueness_subject_id( 777, array() ), 'an unassociated record is its own subject' );

		$shared = TranslationPolicy::shared_meta_keys( 'lps_offering' );
		self::assertContains( '_lps_section_key', $shared );
		self::assertSame(
			'lps_shared_field_portuguese_authority',
			TranslationPolicy::shared_write_error( 'en', '_lps_section_key', 'lps_offering' ),
			'an English variant cannot write the shared section key'
		);
		self::assertNull( TranslationPolicy::shared_write_error( 'pt-br', '_lps_section_key', 'lps_offering' ) );
	}

	public function test_temporal_status_is_derived_and_separate_from_editorial_state(): void {
		self::assertSame( 'upcoming', TeachingContracts::temporal_status( '2026-03-02', '2026-07-10', false, '2026-01-15' ) );
		self::assertSame( 'current', TeachingContracts::temporal_status( '2026-03-02', '2026-07-10', false, '2026-05-01' ) );
		self::assertSame( 'completed', TeachingContracts::temporal_status( '2025-08-04', '2025-12-12', false, '2026-01-15' ) );
		self::assertSame( 'cancelled', TeachingContracts::temporal_status( '2026-03-02', '2026-07-10', true, '2026-05-01' ), 'cancellation is explicit, not derived' );
		self::assertSame( '', TeachingContracts::temporal_status( 'not-a-date', '2026-07-10', false, '2026-05-01' ) );

		$model = TeachingContracts::state_model();
		self::assertSame( '_lps_temporal_status', $model['temporal']['field'] );
		self::assertSame( '_lps_state', $model['editorial']['field'] );
		self::assertContains( 'completed', $model['temporal']['publishable'], 'a completed offering stays publishable' );
		self::assertTrue( Policy::valid_transition( 'published', 'published' ), 'editorial state is untouched by temporal completion' );
	}

	public function test_relationship_invariants_hold(): void {
		$specs = RelationshipPolicy::relation_specs();
		foreach ( array( 'offering_course', 'offering_term', 'teaching_team', 'unit_offering', 'resource_offering', 'resource_unit' ) as $type ) {
			self::assertArrayHasKey( $type, $specs );
		}
		self::assertSame( array( 'lps_person' ), $specs['teaching_team']['target_types'] );
		self::assertSame( 'offering_course', RelationshipPolicy::reverse_aliases()['course_offering'] );

		$unit_rows = array(
			array(
				'target_post_id'    => 30,
				'relationship_role' => 'part-of',
				'sort_order'        => 1,
				'start_date'        => '',
				'end_date'          => '',
				'public_visibility' => true,
			),
			array(
				'target_post_id'    => 31,
				'relationship_role' => 'part-of',
				'sort_order'        => 2,
				'start_date'        => '',
				'end_date'          => '',
				'public_visibility' => true,
			),
		);
		self::assertContains( 'lps_unit_multiple_offerings', RelationshipPolicy::relationship_errors( 'unit_offering', $unit_rows ), 'a unit cannot reference another offering' );
		self::assertContains( 'lps_unit_offering_required', RelationshipPolicy::relationship_errors( 'unit_offering', array() ) );

		$team = array(
			array(
				'target_post_id'    => 40,
				'relationship_role' => 'lead',
				'sort_order'        => 1,
				'start_date'        => '',
				'end_date'          => '',
				'public_visibility' => true,
			),
			array(
				'target_post_id'    => 41,
				'relationship_role' => 'co-teacher',
				'sort_order'        => 2,
				'start_date'        => '',
				'end_date'          => '',
				'public_visibility' => true,
			),
		);
		self::assertSame( array(), RelationshipPolicy::relationship_errors( 'teaching_team', $team ), 'co-teaching is many-to-many' );
		self::assertContains( 'lps_teaching_lead_required', RelationshipPolicy::relationship_errors( 'teaching_team', array( $team[1] ) ) );

		self::assertSame( 'lps_cross_offering_unit_reference', TeachingContracts::resource_unit_error( 30, 31 ) );
		self::assertNull( TeachingContracts::resource_unit_error( 30, 30 ) );
		self::assertSame( 'lps_resource_unit_orphan', TeachingContracts::resource_unit_error( 30, 0 ) );
	}

	public function test_publish_errors_cover_course_term_offering_and_resource(): void {
		$course_errors = TeachingContracts::publish_errors( 'lps_course', array( '_lps_course_level' => 'kindergarten' ) );
		self::assertSame( 'lps_required_course_code', $course_errors['_lps_course_code'] );
		self::assertSame( 'lps_invalid_course_level', $course_errors['_lps_course_level'] );

		$term_errors = TeachingContracts::publish_errors(
			'lps_term',
			array(
				'_lps_calendar_key' => 'semester',
				'_lps_term_code'    => '2026.1',
				'_lps_period_label' => '2026.1',
				'_lps_period_type'  => 'semester',
				'_lps_starts_on'    => '2026-07-10',
				'_lps_ends_on'      => '2026-03-02',
				'_lps_term_token'   => 'wrong-token',
			)
		);
		self::assertSame( 'lps_invalid_term_date_order', $term_errors['_lps_ends_on'], 'invalid date order is rejected' );
		self::assertSame( 'lps_term_token_mismatch', $term_errors['_lps_term_token'] );

		$offering_errors = TeachingContracts::publish_errors(
			'lps_offering',
			array(
				'_lps_section_key'      => '   ',
				'_lps_temporal_status'  => 'limbo',
				'_lps_lms_url'          => 'https://lms.example/course',
				'_lps_lms_url_approved' => false,
			)
		);
		self::assertSame( 'lps_required_section_key', $offering_errors['_lps_section_key'] );
		self::assertSame( 'lps_invalid_temporal_status', $offering_errors['_lps_temporal_status'] );
		self::assertSame( 'lps_lms_url_not_approved', $offering_errors['_lps_lms_url'] );

		$released = TeachingContracts::publish_errors(
			'lps_resource',
			array(
				'_lps_resource_type'        => 'document',
				'_lps_resource_language'    => 'pt-br',
				'_lps_release_state'        => 'released',
				'_lps_rights_review'        => 'pending',
				'_lps_accessibility_review' => 'approved',
				'_lps_version_id'           => 'lpsver:' . str_repeat( 'a', 64 ),
				'_lps_storage_key'          => 'lps-resource/018f21ce',
				'_lps_sha256'               => str_repeat( 'b', 64 ),
				'_lps_byte_size'            => 128,
				'_lps_mime_type'            => 'application-pdf',
				'_lps_scan_state'           => 'pending',
			)
		);
		self::assertSame( 'lps_resource_scan_not_clean', $released['_lps_scan_state'], 'a released version must be scan-clean' );
		self::assertSame( 'lps_resource_rights_not_approved', $released['_lps_rights_review'] );

		$conflict = TeachingContracts::publish_errors(
			'lps_resource',
			array(
				'_lps_resource_type'        => 'link',
				'_lps_resource_language'    => 'en',
				'_lps_release_state'        => 'draft',
				'_lps_rights_review'        => 'pending',
				'_lps_accessibility_review' => 'pending',
				'_lps_version_id'           => 'lpsver:' . str_repeat( 'a', 64 ),
				'_lps_external_url'         => 'https://repo.example/lab',
				'_lps_storage_key'          => 'lps-resource/018f21ce',
				'_lps_sha256'               => str_repeat( 'b', 64 ),
				'_lps_byte_size'            => 128,
				'_lps_mime_type'            => 'application-pdf',
				'_lps_scan_state'           => 'clean',
			)
		);
		self::assertSame( 'lps_resource_version_and_url_conflict', $conflict['_lps_external_url'], 'a resource is one immutable version or one external URL' );
	}

	public function test_migration_plan_is_versioned_additive_and_dry_run_preserves_state(): void {
		$plan = TeachingMigrations::plan( '0.0.0', TeachingMigrations::VERSION );
		self::assertTrue( $plan['ok'] );
		self::assertSame( array( 'create_term_registry', 'create_offering_registry' ), $plan['steps'] );

		$before = TeachingMigrations::schema_sql( 'wp_', 'utf8mb4' );
		$dry    = TeachingMigrations::dry_run( '0.0.0', TeachingMigrations::VERSION );
		$after  = TeachingMigrations::schema_sql( 'wp_', 'utf8mb4' );
		self::assertTrue( $dry['ok'] );
		self::assertFalse( $dry['mutated'], 'the dry-run must not mutate' );
		self::assertTrue( $dry['preserves_existing_records'] );
		self::assertSame( $before, $after, 'the dry-run leaves the existing schema unchanged' );
		self::assertCount( 2, $dry['statements'] );
		foreach ( $dry['statements'] as $statement ) {
			self::assertStringStartsWith( 'CREATE TABLE', $statement, 'migration statements are additive only' );
			self::assertStringNotContainsString( 'DROP', $statement );
			self::assertStringNotContainsString( 'ALTER', $statement );
		}
		self::assertSame( $dry, TeachingMigrations::dry_run( '0.0.0', TeachingMigrations::VERSION ), 'the dry-run is idempotent' );

		$downgrade = TeachingMigrations::plan( TeachingMigrations::VERSION, TeachingMigrations::VERSION );
		self::assertFalse( $downgrade['ok'] );
		self::assertContains( 'lps_teaching_migration_downgrade', $downgrade['errors'] );

		$unknown = TeachingMigrations::plan( '0.0.0', '9.9.9' );
		self::assertFalse( $unknown['ok'] );
		self::assertContains( 'lps_teaching_migration_unknown_version', $unknown['errors'] );

		$sql = TeachingMigrations::schema_sql( 'wp_', 'utf8mb4' );
		self::assertStringContainsString( 'UNIQUE KEY identity_hash', $sql['wp_lps_offering_registry'] );
		self::assertStringContainsString( 'UNIQUE KEY term_token', $sql['wp_lps_term_registry'] );
		self::assertStringContainsString( 'UNIQUE KEY identity_hash', $sql['wp_lps_term_registry'] );
	}

	public function test_copy_forward_contract(): void {
		$valid = array(
			'operation_id'         => 'copy-2026-1-to-2026-2',
			'source_offering_id'   => 30,
			'source_term_id'       => 20,
			'source_section'       => 'a',
			'new_term_id'          => 21,
			'new_section'          => 'A',
			'creates_draft'        => true,
			'publishes'            => false,
			'team_reviewed'        => true,
			'resets'               => array( 'announcements', 'deadlines', 'release_times', 'active_notices', 'unreleased_resources' ),
			'selected_version_ids' => array( 'lpsver:' . str_repeat( 'c', 64 ) ),
			'cleared_version_ids'  => array( 'lpsver:' . str_repeat( 'c', 64 ), 'lpsver:' . str_repeat( 'd', 64 ) ),
		);
		self::assertNull( TeachingContracts::copy_forward_plan_error( $valid ) );
		$manifest = TeachingContracts::copy_forward_manifest( $valid );
		self::assertSame( 'copy-2026-1-to-2026-2', $manifest['operation_id'] );
		self::assertTrue( $manifest['creates_draft'] );
		self::assertFalse( $manifest['publishes'] );
		self::assertTrue( $manifest['atomic'] );
		self::assertTrue( $manifest['idempotent_retry'], 'retrying the same operation identifier must not duplicate it' );

		$same_identity                = $valid;
		$same_identity['new_term_id'] = 20;
		self::assertSame( 'lps_copy_forward_identity_collision', TeachingContracts::copy_forward_plan_error( $same_identity ), 'copy-forward requires a new term or section' );

		$unreviewed                  = $valid;
		$unreviewed['team_reviewed'] = false;
		self::assertSame( 'lps_copy_forward_team_review_required', TeachingContracts::copy_forward_plan_error( $unreviewed ) );

		$no_reset           = $valid;
		$no_reset['resets'] = array( 'announcements' );
		self::assertSame( 'lps_copy_forward_reset_required', TeachingContracts::copy_forward_plan_error( $no_reset ) );

		$unreleased                         = $valid;
		$unreleased['selected_version_ids'] = array( 'lpsver:' . str_repeat( 'e', 64 ) );
		self::assertSame( 'lps_copy_forward_unreleased_version', TeachingContracts::copy_forward_plan_error( $unreleased ), 'only cleared public versions may be reused' );

		$no_operation                 = $valid;
		$no_operation['operation_id'] = '';
		self::assertSame( 'lps_copy_forward_operation_id_required', TeachingContracts::copy_forward_plan_error( $no_operation ) );
	}

	public function test_teaching_calendar_fixture_conforms_to_contracts(): void {
		$calendar = self::load_fixture( 'teaching-calendar' );

		$calendar_keys = array_column( $calendar['calendars'], 'key' );
		foreach ( $calendar['terms'] as $term ) {
			self::assertContains( $term['calendarKey'], $calendar_keys );
			self::assertSame( TeachingContracts::term_token( $term['calendarKey'], $term['periodLabel'] ), $term['token'], 'fixture term tokens follow the canonical token contract' );
			self::assertNull( TeachingContracts::date_order_error( $term['startsOn'], $term['endsOn'] ) );
		}
		$tokens = array_column( $calendar['terms'], 'token' );
		self::assertSame( $tokens, array_unique( $tokens ), 'term tokens are unique across calendars' );

		$term_ids   = array_column( $calendar['terms'], 'id' );
		$identities = array();
		foreach ( $calendar['offerings'] as $offering ) {
			self::assertContains( $offering['term'], $term_ids );
			self::assertContains( $offering['temporalStatus'], TeachingContracts::TEMPORAL_STATUSES );
			self::assertNotSame( '', TeachingContracts::normalize_section_key( $offering['section'] ) );
			$identity = $offering['course'] . '|' . $offering['term'] . '|' . TeachingContracts::normalize_section_key( $offering['section'] );
			self::assertArrayNotHasKey( $identity, $identities, 'two sections cannot collide accidentally' );
			$identities[ $identity ] = true;
			self::assertGreaterThanOrEqual( 1, count( $offering['teachingTeam'] ) );
		}
		$statuses = array_column( $calendar['offerings'], 'temporalStatus' );
		self::assertContains( 'completed', $statuses, 'the fixture exercises a completed offering' );
		self::assertContains( 'current', $statuses );
		self::assertContains( 'upcoming', $statuses );
		$team_sizes = array_map( 'count', array_column( $calendar['offerings'], 'teachingTeam' ) );
		self::assertContains( 2, $team_sizes, 'the fixture exercises co-teaching' );
		$period_types = array_unique( array_column( $calendar['calendars'], 'periodType' ) );
		self::assertContains( 'semester', $period_types );
		self::assertContains( 'trimester', $period_types );
	}

	public function test_migration_plan_fixture_cases_match_contracts(): void {
		$fixture = self::load_fixture( 'teaching-migration-plan' );
		foreach ( $fixture['plans'] as $case ) {
			$plan = TeachingMigrations::plan( $case['from'], $case['to'] );
			self::assertSame( $case['expectOk'], $plan['ok'], $case['id'] );
			foreach ( $case['expectErrors'] ?? array() as $code ) {
				self::assertContains( $code, $plan['errors'], $case['id'] );
			}
		}
		foreach ( $fixture['copyForward'] as $case ) {
			self::assertSame( $case['expectError'], TeachingContracts::copy_forward_plan_error( $case['plan'] ), $case['id'] );
		}
	}
}
