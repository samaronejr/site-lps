<?php
/**
 * Teaching persistence, canonical routes, and reconciliation contracts (task 8).
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
require_once dirname( __DIR__ ) . '/includes/class-teachingrecords.php';
require_once dirname( __DIR__, 3 ) . '/themes/lps-theme/includes/class-teachingroutes.php';

use LPS\ContentModel\Policy;
use LPS\ContentModel\RelationshipPolicy;
use LPS\ContentModel\TeachingContracts;
use LPS\ContentModel\TeachingMigrations;
use LPS\ContentModel\TeachingRecords;
use LPS\Theme\TeachingRoutes;
use PHPUnit\Framework\TestCase;

/**
 * Proves the task-8 persistence and routing contracts without WordPress.
 *
 * Identity normalization, authority resolution, publish gates, migration
 * plans, reconciliation classification, and canonical route matching are all
 * pure policy: they run here directly. Database-backed behavior — atomic
 * claims, REST writes, route resolution, and the reconciliation report — is
 * exercised by the e2e spec against the real WordPress boundary.
 */
final class LpsRedesignTask08Test extends TestCase {
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
		self::assertIsArray( $fixture );
		/** @var array<string, mixed> $fixture */
		self::assertSame( 1, $fixture['schemaVersion'], $name . ' schemaVersion must equal 1' );
		self::assertTrue( $fixture['synthetic'], $name . ' must declare synthetic data' );
		return $fixture;
	}

	public function test_term_identity_normalizes_to_the_immutable_token(): void {
		$identity = TeachingContracts::term_identity( ' Semester ', ' 2026.2 ' );
		self::assertSame( 'semester', $identity['calendar_key'] );
		self::assertSame( '2026-2', $identity['term_code'] );
		self::assertSame( '2026-2-semester', $identity['token'] );

		// The fixture's canonical tokens match the derived form exactly:
		// token = {term_code}-{calendar_key}.
		$calendar = self::load_fixture( 'teaching-calendar' );
		/** @var array<int, array{token: string, calendarKey: string, id: string}> $terms */
		$terms = $calendar['terms'];
		foreach ( $terms as $term ) {
			$term_code = substr( $term['token'], 0, -strlen( '-' . $term['calendarKey'] ) );
			self::assertSame( $term['token'], TeachingContracts::term_token( $term['calendarKey'], $term_code ), 'token for ' . $term['id'] );
		}
	}

	public function test_offering_identity_folds_section_keys_deterministically(): void {
		$first  = TeachingContracts::offering_identity( 10, 20, 'Turma A' );
		$second = TeachingContracts::offering_identity( 10, 20, 'turma a' );
		self::assertSame( $first, $second, 'folded section keys must collide' );
		self::assertSame(
			TeachingContracts::offering_identity_hash( $first ),
			TeachingContracts::offering_identity_hash( $second ),
			'colliding sections must hash identically'
		);
		$other = TeachingContracts::offering_identity( 10, 20, 'turma-b' );
		self::assertNotSame(
			TeachingContracts::offering_identity_hash( $first ),
			TeachingContracts::offering_identity_hash( $other ),
			'distinct sections must not collide'
		);
	}

	public function test_translated_ids_never_mint_a_second_uniqueness_subject(): void {
		// The English variant resolves to its Portuguese authority for hashing.
		self::assertSame(
			10,
			TeachingContracts::uniqueness_subject_id(
				55,
				array(
					'pt-br' => 10,
					'en'    => 55,
				)
			)
		);
		self::assertSame(
			10,
			TeachingContracts::uniqueness_subject_id(
				10,
				array(
					'pt-br' => 10,
					'en'    => 55,
				)
			)
		);
		// An unassociated record is its own subject.
		self::assertSame( 77, TeachingContracts::uniqueness_subject_id( 77, array() ) );
	}

	public function test_publish_gates_require_course_term_and_lead(): void {
		// Offering publish requires course, term, and a lead-bearing team.
		self::assertContains( 'lps_offering_course_required', TeachingContracts::relationship_errors( 'offering_course', array() ) );
		self::assertContains( 'lps_offering_term_required', TeachingContracts::relationship_errors( 'offering_term', array() ) );
		self::assertContains( 'lps_teaching_team_required', TeachingContracts::relationship_errors( 'teaching_team', array() ) );
		self::assertContains(
			'lps_teaching_lead_required',
			TeachingContracts::relationship_errors(
				'teaching_team',
				array( array( 'relationship_role' => 'assistant' ) )
			)
		);
		self::assertContains( 'lps_unit_offering_required', TeachingContracts::relationship_errors( 'unit_offering', array() ) );

		// Field-level gates: course code/level/calendar, term identity, section.
		$course_errors = TeachingContracts::publish_errors( 'lps_course', array() );
		self::assertSame( 'lps_required_course_code', $course_errors['_lps_course_code'] ?? '' );
		self::assertSame( 'lps_invalid_course_level', $course_errors['_lps_course_level'] ?? '' );
		$term_errors = TeachingContracts::publish_errors( 'lps_term', array() );
		self::assertSame( 'lps_required_period_label', $term_errors['_lps_period_label'] ?? '' );
		self::assertSame( 'lps_invalid_period_type', $term_errors['_lps_period_type'] ?? '' );
		$offering_errors = TeachingContracts::publish_errors( 'lps_offering', array() );
		self::assertSame( 'lps_required_section_key', $offering_errors['_lps_section_key'] ?? '' );
		$unit_errors = TeachingContracts::publish_errors( 'lps_unit', array() );
		self::assertSame( 'lps_required_anchor', $unit_errors['_lps_anchor'] ?? '' );
		self::assertSame( 'lps_invalid_unit_position', $unit_errors['_lps_position'] ?? '' );
	}

	public function test_invalid_relation_ids_are_rejected_by_the_integrity_contract(): void {
		// Endpoint type violations are typed, stable machine codes.
		$errors = RelationshipPolicy::relationship_errors(
			'offering_course',
			array(
				array(
					'target_post_id'    => 0,
					'relationship_role' => 'instance-of',
				),
			)
		);
		self::assertNotEmpty( $errors, 'a zero target must violate the endpoint contract' );
		$team_errors = RelationshipPolicy::relationship_errors(
			'teaching_team',
			array(
				array(
					'target_post_id'    => 5,
					'relationship_role' => 'bogus-role',
				),
			)
		);
		self::assertNotEmpty( $team_errors, 'an unknown team role must violate the role contract' );
	}

	public function test_temporal_status_is_derived_never_authored(): void {
		self::assertSame( 'cancelled', TeachingContracts::temporal_status( '2026-08-01', '2026-12-15', true, '2026-09-18' ) );
		self::assertSame( 'upcoming', TeachingContracts::temporal_status( '2027-01-01', '2027-06-01', false, '2026-09-18' ) );
		self::assertSame( 'current', TeachingContracts::temporal_status( '2026-08-01', '2026-12-15', false, '2026-09-18' ) );
		self::assertSame( 'completed', TeachingContracts::temporal_status( '2025-08-01', '2025-12-15', false, '2026-09-18' ) );
		// Missing boundaries derive nothing rather than guessing.
		self::assertSame( '', TeachingContracts::temporal_status( '', '2026-12-15', false, '2026-09-18' ) );
	}

	public function test_migrations_are_additive_idempotent_and_forward_only(): void {
		$plan = TeachingMigrations::plan( '0.0.0', '1.0.0' );
		self::assertTrue( $plan['ok'] );
		self::assertSame( array( 'create_term_registry', 'create_offering_registry' ), $plan['steps'] );

		$downgrade = TeachingMigrations::plan( '1.0.0', '1.0.0' );
		self::assertFalse( $downgrade['ok'] );
		self::assertContains( 'lps_teaching_migration_downgrade', $downgrade['errors'] );

		$unknown = TeachingMigrations::plan( '0.0.0', '9.9.9' );
		self::assertFalse( $unknown['ok'] );
		self::assertContains( 'lps_teaching_migration_unknown_version', $unknown['errors'] );

		// The dry run mutates nothing and preserves existing records.
		$dry = TeachingMigrations::dry_run( '0.0.0', '1.0.0' );
		self::assertTrue( $dry['ok'] );
		// The flags are literal types in the contract; var_export keeps the
		// runtime assertion without tripping the always-true static check.
		self::assertSame( 'false', var_export( $dry['mutated'], true ) );
		self::assertSame( 'true', var_export( $dry['preserves_existing_records'], true ) );
		self::assertCount( 2, $dry['statements'] );

		// Registry schema carries the atomic uniqueness indexes.
		$schema = TeachingMigrations::schema_sql( 'wp_', 'DEFAULT CHARSET utf8mb4' );
		self::assertArrayHasKey( 'wp_lps_term_registry', $schema );
		self::assertArrayHasKey( 'wp_lps_offering_registry', $schema );
		self::assertStringContainsString( 'UNIQUE KEY identity_hash', $schema['wp_lps_term_registry'] );
		self::assertStringContainsString( 'UNIQUE KEY term_token', $schema['wp_lps_term_registry'] );
		self::assertStringContainsString( 'UNIQUE KEY identity_hash', $schema['wp_lps_offering_registry'] );
	}

	public function test_reconciliation_classification_is_typed_and_report_driven(): void {
		$claims = array(
			array(
				'term_post_id'  => 11,
				'identity_hash' => 'aaa',
			),
			array(
				'term_post_id'  => 12,
				'identity_hash' => 'bbb',
			),
			array(
				'term_post_id'  => 13,
				'identity_hash' => 'ccc',
			),
		);
		$posts  = array(
			11 => array(
				'exists'      => true,
				'post_status' => 'publish',
			),
			12 => array(
				'exists'      => false,
				'post_status' => '',
			),
			13 => array(
				'exists'      => true,
				'post_status' => 'trash',
			),
		);
		$hashes = array( 11 => 'different' );
		$issues = TeachingRecords::classify_registry_claims( $claims, $posts, $hashes, 'term' );
		$codes  = array_column( $issues, 'code' );
		self::assertContains( 'lps_reconcile_term_orphaned_claim', $codes );
		self::assertContains( 'lps_reconcile_term_identity_mismatch', $codes );
		// Both the missing and the trashed posts are orphaned claims.
		self::assertSame( 2, count( array_keys( $codes, 'lps_reconcile_term_orphaned_claim', true ) ) );

		// Unclaimed detection is deterministic and authority-only.
		self::assertSame( array( 3, 7 ), TeachingRecords::unclaimed_ids( array( 1, 3, 5, 7 ), array( 1, 5 ) ) );
	}

	public function test_canonical_routes_match_and_rewrite_in_both_locales(): void {
		// Landing.
		self::assertSame( 'landing', TeachingRoutes::match_path( '/pt-br/ensino/' )['view'] ?? '' );
		self::assertSame( 'landing', TeachingRoutes::match_path( '/en/teaching/' )['view'] ?? '' );
		// Course detail.
		$course = TeachingRoutes::match_path( '/pt-br/ensino/disciplinas/sinais-e-sistemas/' );
		self::assertSame( 'course', $course['view'] ?? '' );
		self::assertSame( 'sinais-e-sistemas', $course['course'] );
		$course_en = TeachingRoutes::match_path( '/en/teaching/courses/signals-and-systems/' );
		self::assertSame( 'course', $course_en['view'] ?? '' );
		// Canonical offering page.
		$offering = TeachingRoutes::match_path( '/pt-br/ensino/disciplinas/sinais-e-sistemas/2026-2-semester/t01/' );
		self::assertSame( 'offering', $offering['view'] ?? '' );
		self::assertSame( 'sinais-e-sistemas', $offering['course'] );
		self::assertSame( '2026-2-semester', $offering['term'] );
		self::assertSame( 't01', $offering['section'] );
		$offering_en = TeachingRoutes::match_path( '/en/teaching/courses/signals-and-systems/2026-2-semester/t01/' );
		self::assertSame( 'offering', $offering_en['view'] ?? '' );

		// Collisions and malformed routes resolve to nothing.
		self::assertNull( TeachingRoutes::match_path( '/pt-br/ensino/disciplinas/' ) );
		self::assertNull( TeachingRoutes::match_path( '/pt-br/ensino/disciplinas/slug/2026-2-semester/' ) );
		self::assertNull( TeachingRoutes::match_path( '/pt-br/ensino/disciplinas/slug/2026-2-semester/t01/extra/' ) );
		self::assertNull( TeachingRoutes::match_path( '/pt-br/noticias/' ) );
		self::assertNull( TeachingRoutes::match_path( '/fr/ensino/' ) );

		// Path builders round-trip through the matcher.
		$path = TeachingRoutes::offering_path( 'pt-br', 'sinais-e-sistemas', '2026-2-semester', 't01' );
		self::assertSame( '/pt-br/ensino/disciplinas/sinais-e-sistemas/2026-2-semester/t01/', $path );
		self::assertSame( 'offering', TeachingRoutes::match_path( $path )['view'] ?? '' );
		self::assertSame( '/en/teaching/courses/x/2026-2-semester/a/', TeachingRoutes::offering_path( 'en', 'x', '2026-2-semester', 'a' ) );

		// Rewrite rules cover every canonical route, offering before course.
		$rules       = TeachingRoutes::rewrite_rules();
		$keys        = array_keys( $rules );
		$pt_offering = 'pt-br/ensino/disciplinas/([^/]+)/([^/]+)/([^/]+)/?$';
		$pt_course   = 'pt-br/ensino/disciplinas/([^/]+)/?$';
		self::assertContains( $pt_offering, $keys );
		self::assertContains( $pt_course, $keys );
		self::assertLessThan( array_search( $pt_course, $keys, true ), array_search( $pt_offering, $keys, true ), 'the offering rule must precede the course rule' );
		self::assertStringContainsString( 'lps_offering', $rules[ $pt_offering ] );
		self::assertStringContainsString( 'lps_course', $rules[ $pt_course ] );
	}

	public function test_history_sort_is_newest_term_first(): void {
		$entries = array(
			array(
				'title' => 'B',
				'term'  => array( 'starts_on' => '2025-08-01' ),
			),
			array(
				'title' => 'A',
				'term'  => array( 'starts_on' => '2026-08-01' ),
			),
			array(
				'title' => 'C',
				'term'  => array( 'starts_on' => '2026-08-01' ),
			),
		);
		$sorted  = TeachingRecords::sort_history( $entries );
		self::assertSame( array( 'A', 'C', 'B' ), array_column( $sorted, 'title' ) );
	}

	public function test_ia_fixture_declares_the_teaching_routes(): void {
		$contents = file_get_contents( self::FIXTURE_DIR . '/../ia/routes.json' );
		self::assertIsString( $contents );
		/** @var array{pages: array<int, array{key: string, routes?: array<string, string>, parent?: string, maxDepth?: int}>} $fixture */
		$fixture = json_decode( $contents, true, 512, JSON_THROW_ON_ERROR );
		$pages   = array();
		foreach ( $fixture['pages'] as $page ) {
			$pages[ $page['key'] ] = $page;
		}
		self::assertSame( '/pt-br/ensino/', $pages['teaching']['routes']['pt-br'] ?? '' );
		self::assertSame( '/en/teaching/', $pages['teaching']['routes']['en'] ?? '' );
		self::assertSame( '/pt-br/ensino/disciplinas/:course/', $pages['course-detail']['routes']['pt-br'] ?? '' );
		self::assertSame( '/en/teaching/courses/:course/', $pages['course-detail']['routes']['en'] ?? '' );
		self::assertSame( '/pt-br/ensino/disciplinas/:course/:term/:section/', $pages['offering-detail']['routes']['pt-br'] ?? '' );
		self::assertSame( '/en/teaching/courses/:course/:term/:section/', $pages['offering-detail']['routes']['en'] ?? '' );
		self::assertSame( 5, $pages['offering-detail']['maxDepth'] ?? 0 );
		self::assertSame( 'teaching', $pages['course-detail']['parent'] ?? '' );
		self::assertSame( 'teaching', $pages['offering-detail']['parent'] ?? '' );
	}
}
