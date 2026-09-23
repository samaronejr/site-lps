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
		$decoded = json_decode( (string) file_get_contents( self::FIXTURE_PATH ), true );
		if ( ! is_array( $decoded ) ) {
			$decoded = array();
		}
		/** @var array<string, mixed> $decoded */
		self::$fixture = $decoded;
	}

	/**
	 * Returns one named case list from the fixture, asserting it is non-empty.
	 *
	 * @param string $key Fixture collection key.
	 * @return array<int, array<string, mixed>>
	 */
	private static function fixture_cases( string $key ): array {
		$cases = self::$fixture[ $key ] ?? array();
		if ( ! is_array( $cases ) ) {
			self::fail( 'Fixture must define ' . $key . ' as an array' );
		}
		self::assertNotEmpty( $cases, 'Fixture must define ' . $key );
		/** @var array<int, array<string, mixed>> $cases */
		return $cases;
	}

	/** Returns the institutional date the fixture decisions are evaluated at. */
	private static function today(): string {
		return self::text( self::$fixture['today'] ?? '2026-09-18' );
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
	 * Returns the value as a fixture record, failing the test otherwise.
	 *
	 * @param mixed $value Boundary input.
	 * @return array<string, mixed>
	 */
	private static function record( mixed $value ): array {
		if ( ! is_array( $value ) ) {
			self::fail( 'Fixture record must be an array' );
		}
		/** @var array<string, mixed> $value */
		return $value;
	}

	/** Origin resolution: provenance always wins over a stored claim. */
	public function test_origin_resolution_prefers_provenance(): void {
		foreach ( self::fixture_cases( 'originCases' ) as $case ) {
			self::assertSame(
				$case['expectOrigin'],
				PublicationPolicy::resolve_origin( self::record( $case['record'] ) ),
				self::text( $case['id'] )
			);
		}
	}

	/** The origin write gate: an unreviewed import can never be marked native. */
	public function test_origin_write_gate(): void {
		foreach ( self::fixture_cases( 'originWriteCases' ) as $case ) {
			self::assertSame(
				$case['expectError'],
				PublicationPolicy::origin_write_error( self::text( $case['stored'] ), self::text( $case['candidate'] ), self::record( $case['record'] ) ),
				self::text( $case['id'] )
			);
		}
	}

	/** The single public-visibility decision across surfaces and origins. */
	public function test_visibility_decision_is_unified(): void {
		foreach ( self::fixture_cases( 'visibilityCases' ) as $case ) {
			$locale   = self::text( $case['locale'] ?? self::$fixture['locale'] ?? 'pt-br' );
			$decision = PublicationPolicy::visibility_decision( self::record( $case['record'] ), self::text( $case['surface'] ), $locale, self::today() );
			self::assertSame( $case['expectVisible'], $decision['visible'], self::text( $case['id'] ) );
			self::assertSame( $case['expectOrigin'], $decision['origin'], self::text( $case['id'] ) );
			foreach ( (array) ( $case['expectErrors'] ?? array() ) as $field => $code ) {
				self::assertSame( $code, $decision['errors'][ $field ] ?? null, self::text( $case['id'] ) . ' field ' . $field );
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
					PublicationPolicy::visibility_decision( self::record( $case['record'] ), $surface, 'pt-br', self::today() )['visible'],
					self::text( $case['id'] ) . ' on ' . $surface
				);
			}
		}
	}

	/** Field-specific staleness for teaching types. */
	public function test_teaching_staleness_is_field_specific(): void {
		foreach ( self::fixture_cases( 'stalenessCases' ) as $case ) {
			$base    = self::record( $case['base'] );
			$changed = array_merge( $base, self::record( $case['change'] ) );
			$stale   = TranslationPolicy::source_hash( self::text( $case['postType'] ), $base ) !== TranslationPolicy::source_hash( self::text( $case['postType'] ), $changed );
			self::assertSame( $case['expectStale'], $stale, self::text( $case['id'] ) );
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
			$classification = PublicationPolicy::reconciliation_class( self::record( $case['record'] ) );
			self::assertSame( $case['expectAction'], $classification['action'], self::text( $case['id'] ) );
			self::assertSame( $case['expectOrigin'], $classification['origin'], self::text( $case['id'] ) );
			self::assertSame( $case['expectOrigin'], PublicationPolicy::resolve_origin( self::record( $case['record'] ) ), self::text( $case['id'] ) );
		}
	}

	/** Preview evaluates the same public decision; it never grants visibility. */
	public function test_preview_decision_mirrors_public(): void {
		foreach ( self::fixture_cases( 'visibilityCases' ) as $case ) {
			$locale  = self::text( $case['locale'] ?? self::$fixture['locale'] ?? 'pt-br' );
			$preview = PublicationPolicy::preview_decision( self::record( $case['record'] ), $locale, self::today() );
			$public  = PublicationPolicy::visibility_decision( self::record( $case['record'] ), PublicationPolicy::SURFACE_PUBLIC, $locale, self::today() );
			self::assertSame( $public['visible'], $preview['public_visible'], self::text( $case['id'] ) );
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
