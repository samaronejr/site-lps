<?php
/**
 * Todo 21 media delivery tests: modern formats, dimensions, and single LCP priority.
 *
 * @package LPS\ContentModel\Tests
 */

declare(strict_types=1);

namespace LPS\ContentModel\Tests;

require_once __DIR__ . '/trait-mediatestfixtures.php';

use LPS\ContentModel\MediaPolicy;

/** Todo 21 media delivery tests: modern formats, dimensions, and single LCP priority. */
final class MediaPerformanceTest extends \PHPUnit\Framework\TestCase {
	use MediaTestFixtures;

	/**
	 * Provides modern asset.
	 *
	 * @return array<string, mixed>
	 */
	private static function modern_asset(): array {
		return array_merge(
			self::valid_image_asset(),
			array(
				'sources' => array(
					'image/avif' => '/uploads/experiment-2026-640.avif 640w, /uploads/experiment-2026.avif 2400w',
					'image/webp' => '/uploads/experiment-2026-640.webp 640w, /uploads/experiment-2026.webp 2400w',
				),
			)
		);
	}

	/**
	 * Verifies that hero renders avif then webp with a legacy fallback and dimensions.
	 */
	public function test_hero_renders_avif_then_webp_with_a_legacy_fallback_and_dimensions(): void {
		// Given: a governed hero asset with modern derivatives.
		$html = MediaPolicy::render_image(
			self::image_usage( 'Arranjo de detectores no laboratorio do LPS', false, 'home-hero', 'hero' ),
			self::modern_asset()
		);

		// Then: the browser is offered AVIF first, WebP second, and the original as the fallback.
		self::assertStringContainsString( '<picture>', $html );
		$avif = strpos( $html, 'type="image/avif"' );
		$webp = strpos( $html, 'type="image/webp"' );
		$img  = strpos( $html, '<img ' );
		self::assertIsInt( $avif );
		self::assertIsInt( $webp );
		self::assertIsInt( $img );
		self::assertLessThan( $webp, $avif, 'AVIF must be offered before WebP.' );
		self::assertLessThan( $img, $webp, 'The legacy raster stays the last fallback.' );

		// And: every candidate declares the same layout box, so no shift can occur.
		self::assertStringContainsString( 'width="2400" height="1600"', $html );
		self::assertSame( 3, substr_count( $html, 'sizes="(max-width: 768px) 100vw, 1280px"' ) );
		self::assertStringContainsString( 'srcset="/uploads/experiment-2026-640.avif 640w, /uploads/experiment-2026.avif 2400w"', $html );
		self::assertStringContainsString( 'src="/uploads/experiment-2026.jpg"', $html );

		// And: only the true LCP image is prioritized and eagerly fetched.
		self::assertStringContainsString( 'loading="eager" fetchpriority="high"', $html );
	}

	/**
	 * Verifies that below fold media keeps modern formats but never claims priority.
	 */
	public function test_below_fold_media_keeps_modern_formats_but_never_claims_priority(): void {
		$html = MediaPolicy::render_image(
			self::image_usage( 'Detalhe do arranjo experimental', false, 'article-body', 'content' ),
			self::modern_asset()
		);

		self::assertStringContainsString( 'type="image/avif"', $html );
		self::assertStringContainsString( 'loading="lazy" fetchpriority="auto"', $html );
		self::assertStringNotContainsString( 'fetchpriority="high"', $html );
		self::assertStringContainsString( 'width="2400" height="1600"', $html );
	}

	/**
	 * Verifies that assets without modern derivatives render a plain sized image.
	 */
	public function test_assets_without_modern_derivatives_render_a_plain_sized_image(): void {
		// Given: a legacy asset with no AVIF/WebP derivative.
		$html = MediaPolicy::render_image(
			self::image_usage( 'Fotografia historica do laboratorio', false, 'article-body', 'content' ),
			self::valid_image_asset()
		);

		// Then: no empty <picture> wrapper is emitted, and the image is still fully sized and lazy.
		self::assertStringNotContainsString( '<picture>', $html );
		self::assertStringNotContainsString( '<source', $html );
		self::assertStringContainsString( 'width="2400" height="1600"', $html );
		self::assertStringContainsString( 'loading="lazy"', $html );
	}

	/**
	 * Verifies that modern source markup is escaped and never hotlinked.
	 */
	public function test_modern_source_markup_is_escaped_and_never_hotlinked(): void {
		$asset = array_merge(
			self::valid_image_asset(),
			array( 'sources' => array( 'image/avif' => '"><script>alert(1)</script> 640w' ) )
		);

		$html = MediaPolicy::render_image( self::image_usage( 'Contexto seguro', false, 'article-body', 'content' ), $asset );

		self::assertStringNotContainsString( '<script>', $html );
		self::assertStringContainsString( '&lt;script&gt;', $html );
	}

	/**
	 * Verifies that unapproved source formats are dropped.
	 */
	public function test_unapproved_source_formats_are_dropped(): void {
		$asset = array_merge(
			self::valid_image_asset(),
			array(
				'sources' => array(
					'image/avif' => '/uploads/experiment-2026.avif 2400w',
					'image/jxl'  => '/uploads/experiment-2026.jxl 2400w',
					'text/html'  => '/uploads/evil.html 2400w',
				),
			)
		);

		$html = MediaPolicy::render_image( self::image_usage( 'Contexto seguro', false, 'article-body', 'content' ), $asset );

		self::assertStringContainsString( 'type="image/avif"', $html );
		self::assertStringNotContainsString( 'image/jxl', $html );
		self::assertStringNotContainsString( 'text/html', $html );
	}
}
