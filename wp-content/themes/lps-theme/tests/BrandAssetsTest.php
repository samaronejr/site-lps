<?php
/**
 * Brand endpoint contract tests.
 *
 * @package LPS\Theme\Tests
 */

declare(strict_types=1);

namespace LPS\Theme\Tests;

use LPS\Theme\BrandAssets;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/includes/class-brandassets.php';

/** The brand endpoint serves exactly the two contracted artwork files. */
final class BrandAssetsTest extends TestCase {
	public function test_rewrite_rules_claim_only_the_brand_prefix(): void {
		$rules = BrandAssets::rewrite_rules();

		self::assertArrayHasKey( 'lps-brand/([^/]+)/?$', $rules );
		self::assertSame( 'index.php?lps_brand_file=$matches[1]', $rules['lps-brand/([^/]+)/?$'] );
	}

	public function test_match_path_resolves_only_the_two_contracted_files(): void {
		self::assertSame( 'lps_logo_vector.svg', BrandAssets::match_path( '/lps-brand/lps_logo_vector.svg' ) );
		self::assertSame( 'lps_logo_vector.svg', BrandAssets::match_path( '/lps-brand/lps_logo_vector.svg/' ) );
		self::assertSame( 'lps_logo_compact.svg', BrandAssets::match_path( '/lps-brand/lps_logo_compact.svg' ) );
		self::assertNull( BrandAssets::match_path( '/lps-brand/evil.svg' ) );
		self::assertNull( BrandAssets::match_path( '/lps-brand/lps_logo_vector.svg/extra' ) );
		self::assertNull( BrandAssets::match_path( '/pt-br/sobre/' ) );
		self::assertNull( BrandAssets::match_path( '/lps-brand/' ) );
	}

	public function test_query_var_is_registered_without_clobbering(): void {
		$vars = BrandAssets::register_query_vars( array( 'pagename' ) );

		self::assertContains( 'lps_brand_file', $vars );
		self::assertContains( 'pagename', $vars );
	}

	public function test_malformed_input_never_resolves_to_a_file(): void {
		self::assertNull( BrandAssets::match_path( '' ) );
		self::assertNull( BrandAssets::match_path( '/lps-brand/../lps_logo_vector.svg/' ) );
		self::assertNull( BrandAssets::match_path( '/LPS-BRAND/lps_logo_vector.svg/' ) );
		self::assertNull( BrandAssets::match_path( '/lps-brand/lps_logo_vector.svg%00' ) );
	}
}
