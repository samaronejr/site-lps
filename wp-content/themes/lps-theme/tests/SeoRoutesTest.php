<?php
/**
 * Governed institutional page sitemap paths.
 *
 * @package LPS\Theme\Tests
 */

declare(strict_types=1);

namespace LPS\Theme\Tests;

use LPS\Tests\WpSeedRuntime;
use LPS\Theme\SeoRoutes;
use WP_Post;

/** Institutional pages resolve their shared page key from the authority. */
final class SeoRoutesTest extends \PHPUnit\Framework\TestCase {
	public static function setUpBeforeClass(): void {
		$root = dirname( __DIR__, 4 );
		require_once $root . '/tests/php/WpSeedRuntime.php';
		require_once $root . '/tests/theme-unit/stubs-wp-classes.php';
		require_once $root . '/tests/theme-unit/stubs-intranet.php';
		require_once dirname( __DIR__ ) . '/includes/class-seoroutes.php';
	}

	/** Valid PT/EN page keys produce public paths; missing and unknown keys do not. */
	public function test_institutional_page_paths_use_the_shared_key(): void {
		WpSeedRuntime::reset();
		WpSeedRuntime::activate_content_model_plugin();
		WpSeedRuntime::set_post_language( 8001, 'pt-br' );
		WpSeedRuntime::set_post_language( 8002, 'en' );
		WpSeedRuntime::save_post_translations(
			array(
				'pt-br' => 8001,
				'en'    => 8002,
			)
		);
		$post            = ( new \ReflectionClass( WP_Post::class ) )->newInstanceWithoutConstructor();
		$post->ID        = 8002;
		$post->post_type = 'page';
		try {
			$GLOBALS['lps_test_post_meta'] = array( 8001 => array( '_lps_page_key' => 'contact' ) );
			self::assertSame( '/en/contact/', SeoRoutes::record_path( $post, 'en' ) );
			$post->ID = 8001;
			self::assertSame( '/pt-br/contato/', SeoRoutes::record_path( $post, 'pt-br' ) );
			$GLOBALS['lps_test_post_meta'] = array( 8001 => array( '_lps_page_key' => 'unknown' ) );
			self::assertSame( '', SeoRoutes::record_path( $post, 'pt-br' ) );
			$GLOBALS['lps_test_post_meta'] = array();
			self::assertSame( '', SeoRoutes::record_path( $post, 'en' ) );
		} finally {
			unset( $GLOBALS['lps_test_post_meta'] );
			WpSeedRuntime::reset();
		}
	}
}
