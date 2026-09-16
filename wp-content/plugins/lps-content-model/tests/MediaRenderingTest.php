<?php
/**
 * Accessible media rendering tests.
 *
 * @package LPS\ContentModel\Tests
 */

declare(strict_types=1);

namespace LPS\ContentModel\Tests;

require_once __DIR__ . '/trait-mediatestfixtures.php';

use LPS\ContentModel\MediaPolicy;

/** Accessible media rendering tests. */
final class MediaRenderingTest extends \PHPUnit\Framework\TestCase {
	use MediaTestFixtures;

	/**
	 * Verifies that responsive rendering prioritizes only true hero and lazies content.
	 */
	public function test_responsive_rendering_prioritizes_only_true_hero_and_lazies_content(): void {
		$asset = self::valid_image_asset();
		$hero  = MediaPolicy::render_image( self::image_usage( 'Detector array in the LPS laboratory', false, 'home-hero', 'hero' ), $asset );
		self::assertStringContainsString( 'srcset="/uploads/experiment-2026-640.jpg 640w, /uploads/experiment-2026.jpg 2400w"', $hero );
		self::assertStringContainsString( 'sizes="(max-width: 768px) 100vw, 1280px"', $hero );
		self::assertStringContainsString( 'width="2400" height="1600"', $hero );
		self::assertStringContainsString( 'loading="eager" fetchpriority="high"', $hero );
		$content = MediaPolicy::render_image( self::image_usage( 'Detector array used for calibration', false ), $asset );
		self::assertStringContainsString( 'loading="lazy" fetchpriority="auto"', $content );
		self::assertStringNotContainsString( 'fetchpriority="high"', $content );
	}

	/**
	 * Verifies that video iframe and document render with accessible labels.
	 */
	public function test_video_iframe_and_document_render_with_accessible_labels(): void {
		$video = MediaPolicy::render_video(
			array(
				'block'          => 'video',
				'media_id'       => 52,
				'locale'         => 'pt-br',
				'label'          => 'Visita ao laboratorio',
				'caption'        => 'Demonstracao do detector',
				'autoplay'       => false,
				'placement'      => 'content',
				'transcript_url' => '/uploads/transcript.html',
				'captions_url'   => '/uploads/lab-tour.vtt',
			),
			self::valid_video_asset()
		);
		self::assertStringContainsString( '<video controls preload="metadata"', $video );
		self::assertStringContainsString( '<track kind="captions" src="/uploads/lab-tour.vtt"', $video );
		self::assertStringContainsString( 'width="1920" height="1080"', $video );
		self::assertStringNotContainsString( 'autoplay', $video );
		$iframe = MediaPolicy::render_iframe(
			array(
				'label'     => 'Local document preview',
				'placement' => 'content',
			),
			self::valid_pdf_asset()
		);
		self::assertStringContainsString( 'title="Local document preview"', $iframe );
		self::assertStringContainsString( 'loading="lazy"', $iframe );
		$document = MediaPolicy::render_document(
			array(
				'block'           => 'document',
				'media_id'        => 63,
				'locale'          => 'en',
				'label'           => 'Admissions notice',
				'essential'       => true,
				'html_equivalent' => '<p>Applications close on 30 September.</p>',
				'placement'       => 'content',
			),
			self::valid_pdf_asset()
		);
		self::assertStringContainsString( 'Applications close on 30 September.', $document );
		self::assertStringContainsString( 'href="/uploads/admissions.pdf"', $document );
	}

	/**
	 * Verifies that multiple heroes hotlinks and unlabeled iframes are denied.
	 */
	public function test_multiple_heroes_hotlinks_and_unlabeled_iframes_are_denied(): void {
		$asset  = self::valid_image_asset();
		$usages = array( self::image_usage( 'First hero context', false, 'hero-one', 'hero' ), self::image_usage( 'Second hero context', false, 'hero-two', 'hero' ) );
		self::assertSame( 'lps_media_multiple_heroes', MediaPolicy::collection_errors( $usages, array( 41 => $asset ) )['usage:1:placement'] );
		self::assertSame( 'lps_media_hotlink_forbidden', MediaPolicy::source_error( 'https://outside.example/video.mp4', 'https://lps.test' ) );
		self::assertSame( 'lps_media_iframe_label_required', MediaPolicy::iframe_error( '', false ) );
		self::assertSame( 'lps_media_autoplay_forbidden', MediaPolicy::iframe_error( 'Laboratory video', true ) );
	}
}
