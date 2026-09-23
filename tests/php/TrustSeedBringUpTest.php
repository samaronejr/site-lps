<?php
/**
 * Fresh-database bring-up order regressions for the trust seed mu-plugin.
 *
 * Every scenario runs against the in-memory request runtime, so no web server,
 * browser, or live import is involved. Method declaration order deliberately
 * models a real bring-up timeline: the first request happens while the
 * content-model plugin is still inactive, later requests happen after the
 * plugin activated and wrote its migration receipts.
 *
 * @package LPS\Tests
 */

declare(strict_types=1);

namespace LPS\Tests;

use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

final class TrustSeedBringUpTest extends TestCase {
	public static function setUpBeforeClass(): void {
		require_once __DIR__ . '/WpSeedRuntime.php';
		require_once dirname( __DIR__ ) . '/fixtures/wp/lps-trust-seed.php';
	}

	protected function setUp(): void {
		WpSeedRuntime::reset();
	}

	// An isolated process models the truly fresh boot: no other suite's test
	// file may have loaded the content-model classes before this request.
	#[RunInSeparateProcess]
	public function test_first_request_on_a_fresh_database_defers_instead_of_fatally_erroring(): void {
		self::assertFalse(
			class_exists( \LPS\ContentModel\Translations::class, false ),
			'Bring-up precondition: this scenario must run before any test loads the content-model plugin.'
		);

		WpSeedRuntime::fire( 'init' );

		self::assertSame( 0, WpSeedRuntime::writes(), 'An inactive plugin must produce zero seed writes.' );
		self::assertSame( 0, WpSeedRuntime::post_count() );
		self::assertSame( '', WpSeedRuntime::get_option( LPS_TRUST_SEED_OPTION, '' ) );
	}

	public function test_active_plugin_without_migration_receipts_still_defers(): void {
		WpSeedRuntime::activate_content_model_plugin();

		WpSeedRuntime::fire( 'init' );

		self::assertSame( 0, WpSeedRuntime::writes(), 'Seeding before migrations complete must not write records.' );
		self::assertSame( '', WpSeedRuntime::get_option( LPS_TRUST_SEED_OPTION, '' ) );
	}

	public function test_relationship_receipt_alone_does_not_release_the_seed(): void {
		WpSeedRuntime::activate_content_model_plugin();
		WpSeedRuntime::update_option( 'lps_relationship_schema_version', '1.0.0' );

		WpSeedRuntime::fire( 'init' );

		self::assertSame( 0, WpSeedRuntime::writes(), 'The audit ledger receipt is also required before seeding.' );
	}

	public function test_audit_receipt_alone_does_not_release_the_seed(): void {
		WpSeedRuntime::activate_content_model_plugin();
		WpSeedRuntime::update_option( 'lps_audit_schema_version', '1.0.0' );

		WpSeedRuntime::fire( 'init' );

		self::assertSame( 0, WpSeedRuntime::writes(), 'The relationship migration receipt is also required before seeding.' );
	}

	public function test_seed_applies_once_every_dependency_is_present(): void {
		WpSeedRuntime::activate_content_model_plugin();
		WpSeedRuntime::apply_plugin_migration_receipts();

		WpSeedRuntime::fire( 'init' );

		self::assertGreaterThan( 0, WpSeedRuntime::inserts(), 'A released seed must create records.' );
		self::assertSame( LPS_TRUST_SEED_VERSION, WpSeedRuntime::get_option( LPS_TRUST_SEED_OPTION, '' ) );
		$expected = array(
			'bolsa-doutorado-sinais' => 'lps_opportunity',
			'seminario-lps'          => 'lps_event',
			'novo-laboratorio'       => 'lps_news',
		);
		foreach ( $expected as $slug => $post_type ) {
			self::assertNotNull(
				WpSeedRuntime::find_post( $slug, $post_type ),
				sprintf( 'Expected the seed to publish %s.', $slug )
			);
		}
		self::assertNotNull( WpSeedRuntime::find_post( 'sobre', 'page' ) );
		self::assertNotNull( WpSeedRuntime::find_post( 'about', 'page' ) );
	}

	public function test_deferred_first_request_still_converges_on_the_next_request(): void {
		WpSeedRuntime::fire( 'init' );
		self::assertSame( 0, WpSeedRuntime::writes(), 'The deferred request must stay inert.' );

		WpSeedRuntime::activate_content_model_plugin();
		WpSeedRuntime::apply_plugin_migration_receipts();
		WpSeedRuntime::fire( 'init' );

		self::assertGreaterThan( 0, WpSeedRuntime::inserts() );
		self::assertSame( LPS_TRUST_SEED_VERSION, WpSeedRuntime::get_option( LPS_TRUST_SEED_OPTION, '' ) );
	}

	public function test_repeated_requests_perform_zero_additional_writes(): void {
		WpSeedRuntime::activate_content_model_plugin();
		WpSeedRuntime::apply_plugin_migration_receipts();
		WpSeedRuntime::fire( 'init' );
		$seeded_posts = WpSeedRuntime::post_count();
		$seeded_meta  = WpSeedRuntime::meta();

		WpSeedRuntime::reset_counters();
		WpSeedRuntime::fire( 'init' );
		WpSeedRuntime::fire( 'init' );

		self::assertSame( 0, WpSeedRuntime::writes(), 'A converged seed must not rewrite records on later requests.' );
		self::assertSame( $seeded_posts, WpSeedRuntime::post_count() );
		self::assertSame( $seeded_meta, WpSeedRuntime::meta() );
	}

	public function test_seeded_locales_are_well_formed_bcp47_tags(): void {
		WpSeedRuntime::activate_content_model_plugin();
		WpSeedRuntime::apply_plugin_migration_receipts();
		WpSeedRuntime::fire( 'init' );

		$tags = array();
		foreach ( WpSeedRuntime::meta() as $meta ) {
			$locale = $meta['_lps_locale'] ?? null;
			if ( is_string( $locale ) && '' !== $locale ) {
				$tags[ $locale ] = true;
			}
		}

		self::assertNotSame( array(), $tags, 'Every seeded record must carry a locale.' );
		foreach ( array_keys( $tags ) as $tag ) {
			self::assertMatchesRegularExpression(
				'/^[a-z]{2,3}(-[a-z]{2})?$/',
				(string) $tag,
				sprintf( '%s is not a language[-region] BCP47 tag in WordPress slug casing.', (string) $tag )
			);
		}
		self::assertSame( array( 'pt-br', 'en' ), array_keys( $tags ) );
	}

	public function test_stale_rewrite_rules_are_flushed_once_after_seeding(): void {
		self::assertTrue( WpSeedRuntime::has_hook( 'wp_loaded' ), 'The seed must own a rewrite-rule convergence hook.' );

		WpSeedRuntime::activate_content_model_plugin();
		WpSeedRuntime::apply_plugin_migration_receipts();
		WpSeedRuntime::fire( 'init' );
		WpSeedRuntime::update_option( 'rewrite_rules', array( '^feed$' => 'index.php?feed=1' ) );

		WpSeedRuntime::reset_counters();
		WpSeedRuntime::fire( 'wp_loaded' );

		self::assertSame( 1, WpSeedRuntime::rewrite_flushes(), 'Stale rules must be flushed exactly once.' );

		WpSeedRuntime::update_option(
			'rewrite_rules',
			array( 'lps_opportunity/([^/]+)/?$' => 'index.php?lps_opportunity=$matches[1]' )
		);
		WpSeedRuntime::reset_counters();
		WpSeedRuntime::fire( 'wp_loaded' );

		self::assertSame( 0, WpSeedRuntime::rewrite_flushes(), 'Fresh rules must not be flushed again.' );
	}

	public function test_rewrite_convergence_does_not_run_before_the_seed_applies(): void {
		WpSeedRuntime::update_option( 'rewrite_rules', array( '^feed$' => 'index.php?feed=1' ) );

		WpSeedRuntime::fire( 'wp_loaded' );

		self::assertSame( 0, WpSeedRuntime::rewrite_flushes(), 'An unseeded environment must not be flushed by this fixture.' );
	}
}
