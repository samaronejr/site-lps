<?php
/**
 * Task 5 tests: unified native authoring, provenance, and teaching-language publication.
 *
 * @package LPS_Content_Model
 */

declare(strict_types=1);

require_once dirname( __DIR__ ) . '/includes/class-policy.php';
require_once dirname( __DIR__ ) . '/includes/class-trustsurfacepolicy.php';
require_once dirname( __DIR__ ) . '/includes/class-teachingcontracts.php';
require_once dirname( __DIR__ ) . '/includes/class-translationpolicy.php';
require_once dirname( __DIR__ ) . '/includes/class-publicationpolicy.php';

use LPS\ContentModel\PublicationPolicy;
use LPS\ContentModel\TeachingContracts;
use LPS\ContentModel\TranslationPolicy;
use PHPUnit\Framework\TestCase;

/**
 * Covers the single public-visibility decision, origin-aware publication
 * policy, and field-specific teaching staleness.
 */
final class LpsRedesignTask05Test extends TestCase {

	/**
	 * Decoded shared fixture.
	 *
	 * @var array<string, mixed>
	 */
	private static array $fixture;

	private const FIXTURE_PATH = __DIR__ . '/../../../../tests/fixtures/lps-redesign/publication-origin.json';

	/** Loads the shared synthetic fixture once for the whole class. */
	public static function setUpBeforeClass(): void {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local fixture, not a remote URL.
		$decoded       = json_decode( (string) file_get_contents( self::FIXTURE_PATH ), true );
		self::$fixture = is_array( $decoded ) ? $decoded : array();
	}

	/**
	 * Returns one named case list from the fixture, asserting it is non-empty.
	 *
	 * @param string $key Fixture collection key.
	 * @return array<int, array<string, mixed>>
	 */
	private static function fixture_cases( string $key ): array {
		$cases = self::$fixture[ $key ] ?? array();
		self::assertIsArray( $cases );
		self::assertNotEmpty( $cases, 'Fixture must define ' . $key );
		return $cases;
	}

	/** Returns the institutional date the fixture decisions are evaluated at. */
	private static function today(): string {
		return (string) ( self::$fixture['today'] ?? '2026-09-18' );
	}

	/** Origin resolution: provenance always wins over a stored claim. */
	public function test_origin_resolution_prefers_provenance(): void {
		foreach ( self::fixture_cases( 'originCases' ) as $case ) {
			self::assertSame(
				$case['expectOrigin'],
				PublicationPolicy::resolve_origin( $case['record'] ),
				$case['id']
			);
		}
	}

	/** The origin write gate: an unreviewed import can never be marked native. */
	public function test_origin_write_gate(): void {
		foreach ( self::fixture_cases( 'originWriteCases' ) as $case ) {
			self::assertSame(
				$case['expectError'],
				PublicationPolicy::origin_write_error( $case['stored'], $case['candidate'], $case['record'] ),
				$case['id']
			);
		}
	}

	/** The single public-visibility decision across surfaces and origins. */
	public function test_visibility_decision_is_unified(): void {
		foreach ( self::fixture_cases( 'visibilityCases' ) as $case ) {
			$locale   = (string) ( $case['locale'] ?? self::$fixture['locale'] ?? 'pt-br' );
			$decision = PublicationPolicy::visibility_decision( $case['record'], $case['surface'], $locale, self::today() );
			self::assertSame( $case['expectVisible'], $decision['visible'], $case['id'] );
			self::assertSame( $case['expectOrigin'], $decision['origin'], $case['id'] );
			foreach ( (array) ( $case['expectErrors'] ?? array() ) as $field => $code ) {
				self::assertSame( $code, $decision['errors'][ $field ] ?? null, $case['id'] . ' field ' . $field );
			}
		}
	}

	/** A native record needs no import fields; an imported one needs review. */
	public function test_native_and_imported_paths_share_the_same_gates(): void {
		$native   = self::find_case( 'visibilityCases', 'native-news-feature' );
		$imported = self::find_case( 'visibilityCases', 'imported-reviewed-news' );
		foreach ( array( $native, $imported ) as $case ) {
			foreach ( array( PublicationPolicy::SURFACE_PUBLIC, PublicationPolicy::SURFACE_FEATURE ) as $surface ) {
				self::assertTrue(
					PublicationPolicy::visibility_decision( $case['record'], $surface, 'pt-br', self::today() )['visible'],
					$case['id'] . ' on ' . $surface
				);
			}
		}
	}

	/** Field-specific staleness for teaching types. */
	public function test_teaching_staleness_is_field_specific(): void {
		foreach ( self::fixture_cases( 'stalenessCases' ) as $case ) {
			$base    = $case['base'];
			$changed = array_merge( $base, $case['change'] );
			$stale   = TranslationPolicy::source_hash( $case['postType'], $base ) !== TranslationPolicy::source_hash( $case['postType'], $changed );
			self::assertSame( $case['expectStale'], $stale, $case['id'] );
		}
	}

	/** Localized/shared ownership is complete for every teaching type. */
	public function test_teaching_field_ownership_is_complete(): void {
		foreach ( TeachingContracts::POST_TYPES as $post_type ) {
			$ownership = TeachingContracts::field_ownership( $post_type );
			self::assertNotEmpty( $ownership, $post_type );
			$localized = TeachingContracts::localized_meta_keys( $post_type );
			$shared    = array_keys( array_filter( $ownership, static fn ( string $owner ): bool => 'shared' === $owner ) );
			self::assertSame( array(), array_intersect( $localized, $shared ), $post_type . ' localized/shared overlap' );
			foreach ( $ownership as $key => $owner ) {
				if ( 'localized' === $owner ) {
					self::assertContains( $key, $localized, $post_type . ' ' . $key );
				}
			}
		}
	}

	/** Reconciliation is a report, never an automatic trust decision. */
	public function test_reconciliation_classification(): void {
		foreach ( self::fixture_cases( 'reconciliationCases' ) as $case ) {
			$classification = PublicationPolicy::reconciliation_class( $case['record'] );
			self::assertSame( $case['expectAction'], $classification['action'], $case['id'] );
			self::assertSame( $case['expectOrigin'], $classification['origin'], $case['id'] );
			self::assertSame( $case['expectOrigin'], PublicationPolicy::resolve_origin( $case['record'] ), $case['id'] );
		}
	}

	/** Preview evaluates the same public decision; it never grants visibility. */
	public function test_preview_decision_mirrors_public(): void {
		foreach ( self::fixture_cases( 'visibilityCases' ) as $case ) {
			$locale  = (string) ( $case['locale'] ?? self::$fixture['locale'] ?? 'pt-br' );
			$preview = PublicationPolicy::preview_decision( $case['record'], $locale, self::today() );
			$public  = PublicationPolicy::visibility_decision( $case['record'], PublicationPolicy::SURFACE_PUBLIC, $locale, self::today() );
			self::assertTrue( $preview['preview'], $case['id'] );
			self::assertSame( $public['visible'], $preview['public_visible'], $case['id'] );
		}
	}

	/**
	 * Returns one fixture case by id, failing the test when it is absent.
	 *
	 * @param string $key Fixture collection key.
	 * @param string $id  Case identifier.
	 * @return array<string, mixed>
	 */
	private static function find_case( string $key, string $id ): array {
		foreach ( self::fixture_cases( $key ) as $case ) {
			if ( $id === $case['id'] ) {
				return $case;
			}
		}
		self::fail( 'Fixture case not found: ' . $id );
	}
}
