<?php
/**
 * Shared media test fixtures.
 *
 * @package LPS\ContentModel\Tests
 */

declare(strict_types=1);

namespace LPS\ContentModel\Tests;

/** Supplies deterministic governed assets and contextual image uses. */
trait MediaTestFixtures {
	/** @return array<string, int|string> */
	private static function valid_image_asset(): array {
		return array(
			'id' => 41, 'mime' => 'image/jpeg', 'url' => '/uploads/experiment-2026.jpg', 'filename' => 'experiment-2026.jpg',
			'width' => 2400, 'height' => 1600, 'credit' => 'LPS archive', 'rights_holder' => 'LPS', 'license' => 'authorized-use',
			'source_url' => 'https://records.example/asset/41', 'checksum' => 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
			'rights_status' => 'cleared', 'privacy_status' => 'reviewed',
			'srcset' => '/uploads/experiment-2026-640.jpg 640w, /uploads/experiment-2026.jpg 2400w',
		);
	}

	/** @return array<string, int|string> */
	private static function valid_video_asset(): array {
		return array(
			'id' => 52, 'mime' => 'video/mp4', 'url' => '/uploads/lab-tour.mp4', 'filename' => 'lab-tour.mp4', 'width' => 1920, 'height' => 1080,
			'credit' => 'LPS archive', 'rights_holder' => 'LPS', 'license' => 'authorized-use', 'source_url' => 'https://records.example/asset/52',
			'checksum' => 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb', 'rights_status' => 'cleared', 'privacy_status' => 'reviewed',
			'caption_status' => 'provided', 'transcript_status' => 'provided',
		);
	}

	/** @return array<string, int|string> */
	private static function valid_pdf_asset(): array {
		return array(
			'id' => 63, 'mime' => 'application/pdf', 'url' => '/uploads/admissions.pdf', 'filename' => 'admissions.pdf', 'width' => 0, 'height' => 0,
			'credit' => 'LPS', 'rights_holder' => 'LPS', 'license' => 'authorized-use', 'source_url' => 'https://records.example/asset/63',
			'checksum' => 'cccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccc', 'rights_status' => 'cleared',
			'privacy_status' => 'reviewed', 'document_status' => 'html-equivalent',
		);
	}

	/** @return array<string, bool|int|string> */
	private static function image_usage( string $alt, bool $decorative, string $context = 'project-overview', string $placement = 'content' ): array {
		return array( 'block' => 'figure', 'media_id' => 41, 'locale' => 'en', 'alt' => $alt, 'decorative' => $decorative, 'caption' => 'Detector calibration', 'context' => $context, 'placement' => $placement );
	}
}
