<?php
/**
 * Todo 21 delivery policy tests: asset trimming and safe anonymous caching.
 *
 * @package LPS\Theme\Tests
 */

declare(strict_types=1);

namespace LPS\Theme\Tests;

use LPS\Theme\AssetPolicy;
use LPS\Theme\CachePolicy;
use PHPUnit\Framework\Attributes\DataProvider;

require_once dirname( __DIR__ ) . '/includes/class-assetpolicy.php';
require_once dirname( __DIR__ ) . '/includes/class-cachepolicy.php';

/** Todo 21 delivery policy tests: asset trimming and safe anonymous caching. */
final class PerformancePolicyTest extends \PHPUnit\Framework\TestCase {
	/**
	 * Builds a normalized anonymous public request.
	 *
	 * @param array<string, mixed> $overrides Request overrides.
	 *
	 * @return array{method: string, path: string, status: int, query: array<string, mixed>, cookies: list<string>, is_admin: bool, logged_in: bool}
	 */
	private static function public_request( array $overrides = array() ): array {
		$merged = array_merge(
			array(
				'method'    => 'GET',
				'path'      => '/pt-br/pesquisa/',
				'status'    => 200,
				'query'     => array(),
				'cookies'   => array(),
				'is_admin'  => false,
				'logged_in' => false,
			),
			$overrides
		);

		$query = array();
		foreach ( is_array( $merged['query'] ) ? $merged['query'] : array() as $key => $value ) {
			$query[ (string) $key ] = $value;
		}

		$cookies = array();
		foreach ( is_array( $merged['cookies'] ) ? $merged['cookies'] : array() as $cookie ) {
			if ( is_string( $cookie ) ) {
				$cookies[] = $cookie;
			}
		}

		return array(
			'method'    => is_string( $merged['method'] ) ? $merged['method'] : 'GET',
			'path'      => is_string( $merged['path'] ) ? $merged['path'] : '/',
			'status'    => is_int( $merged['status'] ) ? $merged['status'] : 200,
			'query'     => $query,
			'cookies'   => $cookies,
			'is_admin'  => true === $merged['is_admin'],
			'logged_in' => true === $merged['logged_in'],
		);
	}

	/**
	 * Verifies that public requests drop unused core front end assets.
	 */
	public function test_public_requests_drop_unused_core_front_end_assets(): void {
		// Given: a public page that renders only LPS blocks.
		$plan = AssetPolicy::dequeue_plan(
			array(
				'is_admin'     => false,
				'block_editor' => false,
				'blocks'       => array( 'lps-theme/homepage', 'core/group' ),
			)
		);

		// Then: the unused core payloads are removed, including the block library.
		self::assertContains( 'wp-block-library', $plan['styles'] );
		self::assertContains( 'wp-block-library-theme', $plan['styles'] );
		self::assertContains( 'classic-theme-styles', $plan['styles'] );
		self::assertContains( 'wp-embed', $plan['scripts'] );
		self::assertTrue( $plan['remove_emoji'] );

		// And: the theme's own tokenized styles are never removed.
		self::assertNotContains( 'lps-theme', $plan['styles'] );
		self::assertNotContains( 'global-styles', $plan['styles'], 'theme.json presets carry the approved design tokens.' );
	}

	/**
	 * Provides block sets.
	 *
	 * @return array<string, array{array<int, string>, bool}>
	 */
	public static function block_sets(): array {
		return array(
			'layout-only blocks need no core CSS' => array( array( 'core/group', 'core/columns', 'lps-theme/header' ), false ),
			'tables need core block CSS'          => array( array( 'core/group', 'core/table' ), true ),
			'quotes need core block CSS'          => array( array( 'core/quote' ), true ),
			'lists need core block CSS'           => array( array( 'core/list' ), true ),
			'theme-styled query blocks do not'    => array( array( 'core/query', 'core/post-template', 'core/post-title', 'core/query-title', 'core/post-date' ), false ),
			'query pagination still needs it'     => array( array( 'core/query', 'core/query-pagination' ), true ),
		);
	}

	/**
	 * Retains core styles exactly when rendered blocks require them.
	 *
	 * @param array<int, string> $blocks   Rendered block names.
	 * @param bool               $expected Whether core block CSS is still required.
	 */
	#[DataProvider( 'block_sets' )]
	public function test_core_block_styles_are_kept_only_when_a_rendered_block_needs_them( array $blocks, bool $expected ): void {
		self::assertSame( $expected, AssetPolicy::needs_core_block_styles( $blocks ) );

		$plan = AssetPolicy::dequeue_plan(
			array(
				'is_admin'     => false,
				'block_editor' => false,
				'blocks'       => $blocks,
			)
		);
		self::assertSame( ! $expected, in_array( 'wp-block-library', $plan['styles'], true ) );
	}

	/**
	 * Verifies that admin and block editor assets are never stripped.
	 */
	public function test_admin_and_block_editor_assets_are_never_stripped(): void {
		foreach ( array( array( 'is_admin' => true ), array( 'block_editor' => true ) ) as $context ) {
			$plan = AssetPolicy::dequeue_plan(
				array_merge(
					array(
						'is_admin'     => false,
						'block_editor' => false,
						'blocks'       => array(),
					),
					$context
				)
			);
			self::assertSame( array(), $plan['styles'] );
			self::assertSame( array(), $plan['scripts'] );
			self::assertFalse( $plan['remove_emoji'] );
		}
	}

	/**
	 * Verifies the only front-end script is the approved deferred enhancement file.
	 */
	public function test_the_theme_ships_no_render_blocking_front_end_javascript(): void {
		self::assertSame( array( 'lps-theme' ), AssetPolicy::front_end_scripts() );
	}

	/**
	 * Verifies that only the two subset faces are preloaded as woff2.
	 */
	public function test_only_the_two_subset_faces_are_preloaded_as_woff2(): void {
		$preloads = AssetPolicy::font_preloads( 'https://lps.example/wp-content/themes/lps-theme' );

		self::assertCount( 2, $preloads );
		foreach ( $preloads as $preload ) {
			self::assertStringEndsWith( '.woff2', $preload['href'] );
			self::assertSame( 'font', $preload['as'] );
			self::assertSame( 'font/woff2', $preload['type'] );
			self::assertTrue( $preload['crossorigin'] );
		}
	}

	/**
	 * Verifies that anonymous html is cacheable with shared ttl and encoding vary.
	 */
	public function test_anonymous_html_is_cacheable_with_shared_ttl_and_encoding_vary(): void {
		$decision = CachePolicy::decide( self::public_request() );

		self::assertTrue( $decision['cacheable'] );
		self::assertSame( 'anonymous-html', $decision['reason'] );
		self::assertStringContainsString( 's-maxage=' . $decision['ttl'], $decision['headers']['Cache-Control'] );
		self::assertStringContainsString( 'public', $decision['headers']['Cache-Control'] );
		self::assertStringContainsString( 'stale-while-revalidate', $decision['headers']['Cache-Control'] );
		self::assertSame( 'Accept-Encoding', $decision['headers']['Vary'] );
		self::assertContains( 'lps-locale-pt-br', $decision['surrogate_keys'] );
	}

	/**
	 * Verifies that allowlisted search and facet queries are cacheable with a short ttl.
	 */
	public function test_allowlisted_search_and_facet_queries_are_cacheable_with_a_short_ttl(): void {
		$decision = CachePolicy::decide(
			self::public_request(
				array(
					'path'  => '/pt-br/busca/',
					'query' => array(
						'q'        => 'sinais',
						'record'   => 'lps_news',
						'category' => array( 'institucional' ),
						'page'     => '2',
					),
				)
			)
		);

		self::assertTrue( $decision['cacheable'] );
		self::assertSame( 'anonymous-query', $decision['reason'] );
		self::assertLessThan( CachePolicy::HTML_TTL, $decision['ttl'] );
		self::assertGreaterThan( 0, $decision['ttl'] );
	}

	/**
	 * Verifies that unapproved query parameters are never cached.
	 */
	public function test_unapproved_query_parameters_are_never_cached(): void {
		$decision = CachePolicy::decide(
			self::public_request(
				array(
					'path'  => '/pt-br/busca/',
					'query' => array(
						'q'          => 'sinais',
						'utm_source' => 'x',
					),
				)
			)
		);

		self::assertFalse( $decision['cacheable'] );
		self::assertSame( 'unapproved-query', $decision['reason'] );
		self::assertStringContainsString( 'no-store', $decision['headers']['Cache-Control'] );
	}

	/**
	 * Provides uncacheable requests.
	 *
	 * @return array<string, array{array<string, mixed>, string}>
	 */
	public static function uncacheable_requests(): array {
		return array(
			'admin screen'          => array(
				array(
					'path'      => '/wp-admin/edit.php',
					'is_admin'  => true,
					'logged_in' => true,
				),
				'private-request',
			),
			'admin path anonymous'  => array(
				array(
					'path'     => '/wp-admin/',
					'is_admin' => true,
				),
				'private-request',
			),
			'login screen'          => array( array( 'path' => '/wp-login.php' ), 'private-request' ),
			'logged-in cookie'      => array(
				array(
					'cookies'   => array( 'wordpress_logged_in_5f4dcc3b' ),
					'logged_in' => true,
				),
				'authenticated',
			),
			'comment author cookie' => array( array( 'cookies' => array( 'comment_author_5f4dcc3b' ) ), 'authenticated' ),
			'preview request'       => array( array( 'query' => array( 'preview' => 'true' ) ), 'preview' ),
			'nonce request'         => array( array( 'query' => array( '_wpnonce' => 'abc123' ) ), 'preview' ),
			'post submission'       => array( array( 'method' => 'POST' ), 'unsafe-method' ),
			'server error'          => array( array( 'status' => 500 ), 'error-status' ),
			'redirect'              => array( array( 'status' => 302 ), 'error-status' ),
		);
	}

	/**
	 * Refuses shared caching for personalized or unsafe responses.
	 *
	 * @param array<string, mixed> $overrides Request overrides.
	 * @param string               $reason    Expected refusal reason.
	 */
	#[DataProvider( 'uncacheable_requests' )]
	public function test_personalized_and_unsafe_responses_are_never_shared( array $overrides, string $reason ): void {
		$decision = CachePolicy::decide( self::public_request( $overrides ) );

		self::assertFalse( $decision['cacheable'], 'This response must never enter a shared cache.' );
		self::assertSame( $reason, $decision['reason'] );
		self::assertStringContainsString( 'private', $decision['headers']['Cache-Control'] );
		self::assertStringContainsString( 'no-store', $decision['headers']['Cache-Control'] );
		self::assertStringNotContainsString( 's-maxage', $decision['headers']['Cache-Control'] );
		self::assertSame( 0, $decision['ttl'] );
	}

	/**
	 * Verifies that a missing page is cacheable only briefly.
	 */
	public function test_a_missing_page_is_cacheable_only_briefly(): void {
		$decision = CachePolicy::decide( self::public_request( array( 'status' => 404 ) ) );

		self::assertTrue( $decision['cacheable'] );
		self::assertSame( 'not-found', $decision['reason'] );
		self::assertSame( CachePolicy::NOT_FOUND_TTL, $decision['ttl'] );
	}

	/**
	 * Verifies that publishing a record purges exactly its affected public urls.
	 */
	public function test_publishing_a_record_purges_exactly_its_affected_public_urls(): void {
		// Given: a published Portuguese news record with an English variant and one taxonomy term.
		$targets = CachePolicy::purge_targets(
			array(
				'permalink'    => 'https://lps.example/pt-br/noticias/sinal-2026/',
				'archives'     => array( 'https://lps.example/pt-br/noticias/' ),
				'translations' => array( 'https://lps.example/en/news/signal-2026/' ),
				'terms'        => array( 'https://lps.example/pt-br/noticias/categoria/institucional/' ),
				'locales'      => array( 'pt-br', 'en' ),
			)
		);

		// Then: the record, its archive, its translation, its term archive, both locale homes,
		// both search entries and the sitemaps are purged - and nothing else.
		self::assertSame(
			array(
				'https://lps.example/en/',
				'https://lps.example/en/news/signal-2026/',
				'https://lps.example/en/search/',
				'https://lps.example/pt-br/',
				'https://lps.example/pt-br/busca/',
				'https://lps.example/pt-br/noticias/',
				'https://lps.example/pt-br/noticias/categoria/institucional/',
				'https://lps.example/pt-br/noticias/sinal-2026/',
				'https://lps.example/sitemap.xml',
			),
			$targets
		);
	}

	/**
	 * Verifies that purge never touches unrelated records or admin urls.
	 */
	public function test_purge_never_touches_unrelated_records_or_admin_urls(): void {
		$targets = CachePolicy::purge_targets(
			array(
				'permalink'    => 'https://lps.example/pt-br/noticias/sinal-2026/',
				'archives'     => array( 'https://lps.example/pt-br/noticias/' ),
				'translations' => array(),
				'terms'        => array(),
				'locales'      => array( 'pt-br' ),
			)
		);

		self::assertNotContains( 'https://lps.example/pt-br/noticias/outro-registro/', $targets );
		foreach ( $targets as $target ) {
			self::assertStringNotContainsString( '/wp-admin/', $target );
			self::assertStringNotContainsString( '/wp-login.php', $target );
			self::assertStringNotContainsString( '?', $target, 'Purge keys stay canonical so query caches expire by surrogate key.' );
		}
	}

	/**
	 * Verifies that a draft or unchanged record purges nothing.
	 */
	public function test_a_draft_or_unchanged_record_purges_nothing(): void {
		self::assertSame(
			array(),
			CachePolicy::purge_targets(
				array(
					'permalink'    => '',
					'archives'     => array(),
					'translations' => array(),
					'terms'        => array(),
					'locales'      => array(),
				)
			)
		);
	}
}
