<?php
/**
 * Rights-aware accessible media contract tests.
 *
 * @package LPS\ContentModel\Tests
 */

declare(strict_types=1);

namespace LPS\ContentModel\Tests;

require_once dirname( __DIR__ ) . '/includes/class-policy.php';
require_once dirname( __DIR__ ) . '/includes/class-mediarenderer.php';
require_once dirname( __DIR__ ) . '/includes/class-mediapolicy.php';
require_once dirname( __DIR__ ) . '/includes/class-mediausagepolicy.php';
require_once dirname( __DIR__ ) . '/includes/class-mediacontracts.php';
require_once dirname( __DIR__ ) . '/includes/class-mediablocks.php';
require_once __DIR__ . '/MediaTestFixtures.php';

use LPS\ContentModel\MediaBlocks;
use LPS\ContentModel\MediaContracts;
use LPS\ContentModel\MediaPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MediaContractsTest extends TestCase {
	use MediaTestFixtures;

	/** @return iterable<string, array{string, int, int, int, string|null}> */
	public static function upload_cases(): iterable {
		yield 'allow responsive photograph' => array( 'image/jpeg', 4_000_000, 2400, 1600, null );
		yield 'deny oversized image bytes' => array( 'image/jpeg', 13_000_000, 2400, 1600, 'lps_media_file_too_large' );
		yield 'deny oversized image dimensions' => array( 'image/png', 4_000_000, 13000, 1600, 'lps_media_dimensions_too_large' );
		yield 'deny executable' => array( 'application/x-php', 100, 0, 0, 'lps_media_mime_forbidden' );
		yield 'allow caption file' => array( 'text/vtt', 20_000, 0, 0, null );
	}

	#[DataProvider( 'upload_cases' )]
	public function test_upload_allowlists_enforce_mime_size_and_dimensions( string $mime, int $bytes, int $width, int $height, ?string $error ): void {
		self::assertSame( $error, MediaPolicy::upload_error( $mime, $bytes, $width, $height ) );
	}

	public function test_attachment_metadata_is_typed_and_complete(): void {
		$fields = MediaContracts::attachment_fields();
		self::assertSame(
			array( '_lps_media_credit', '_lps_media_rights_holder', '_lps_media_license', '_lps_media_source_url', '_lps_media_checksum', '_lps_media_focal_x', '_lps_media_focal_y', '_lps_media_width', '_lps_media_height', '_lps_media_duration', '_lps_media_rights_status', '_lps_media_privacy_status', '_lps_media_transcript_status', '_lps_media_caption_status', '_lps_media_document_status', '_lps_media_import_source_id', '_lps_media_import_captured_at', '_lps_media_import_locale', '_lps_media_import_review_state' ),
			array_keys( $fields )
		);
		foreach ( $fields as $definition ) {
			self::assertTrue( $definition['show_in_rest'] );
			self::assertTrue( $definition['single'] );
		}
	}

	public function test_locked_dynamic_block_contracts_are_complete(): void {
		$blocks = MediaBlocks::definitions();
		self::assertSame( array( 'lps/media', 'lps/figure', 'lps/gallery', 'lps/video' ), array_keys( $blocks ) );
		foreach ( $blocks as $definition ) {
			self::assertFalse( $definition['supports']['html'] );
			self::assertFalse( $definition['supports']['reusable'] );
			self::assertSame( 'string', $definition['attributes']['locale']['type'] );
		}
		self::assertSame( 'array', $blocks['lps/gallery']['attributes']['items']['type'] );
	}

	public function test_checksum_duplicate_is_denied_before_public_mutation(): void {
		self::assertSame( 'lps_media_duplicate_checksum', MediaPolicy::checksum_error( str_repeat( 'a', 64 ), array( str_repeat( 'a', 64 ) ) ) );
		self::assertNull( MediaPolicy::checksum_error( str_repeat( 'b', 64 ), array( str_repeat( 'a', 64 ) ) ) );
	}

	public function test_rights_and_privacy_are_hard_publication_gates(): void {
		$asset = self::valid_image_asset();
		$asset['rights_status'] = 'unknown';
		self::assertSame( 'lps_media_rights_unknown', MediaPolicy::asset_errors( $asset )['rights_status'] );
		$asset['rights_status'] = 'cleared';
		$asset['privacy_status'] = 'pending';
		self::assertSame( 'lps_media_privacy_unreviewed', MediaPolicy::asset_errors( $asset )['privacy_status'] );
	}

	public function test_per_use_alt_and_decorative_are_exclusive_and_filename_alt_is_denied(): void {
		$asset = self::valid_image_asset();
		self::assertSame( 'lps_media_alt_or_decorative_required', MediaPolicy::usage_errors( self::image_usage( '', false ), $asset )['alt'] );
		self::assertSame( 'lps_media_alt_decorative_conflict', MediaPolicy::usage_errors( self::image_usage( 'Oscilloscope trace', true ), $asset )['alt'] );
		self::assertSame( 'lps_media_filename_alt_forbidden', MediaPolicy::usage_errors( self::image_usage( 'experiment-2026', false ), $asset )['alt'] );
		self::assertSame( array(), MediaPolicy::usage_errors( self::image_usage( '', true ), $asset ) );
	}

	public function test_repeated_asset_requires_distinct_contextual_localized_alt(): void {
		$asset = self::valid_image_asset();
		$usages = array( self::image_usage( 'Researcher calibrating the detector before a measurement', false, 'project-method' ), self::image_usage( 'Researcher calibrating the detector before a measurement', false, 'people-lab' ) );
		self::assertSame( 'lps_media_reused_context_alt', MediaPolicy::collection_errors( $usages, array( 41 => $asset ) )['usage:1:alt'] );
		$usages[1]['alt'] = 'Laboratory member working beside the signal analyzer';
		self::assertSame( array(), MediaPolicy::collection_errors( $usages, array( 41 => $asset ) ) );
	}

	public function test_video_requires_label_caption_transcript_and_never_autoplays(): void {
		$asset = self::valid_video_asset();
		$usage = array( 'block' => 'video', 'media_id' => 52, 'locale' => 'pt-br', 'label' => '', 'caption' => '', 'autoplay' => true, 'placement' => 'content' );
		$errors = MediaPolicy::usage_errors( $usage, $asset );
		self::assertSame( 'lps_media_label_required', $errors['label'] );
		self::assertSame( 'lps_media_caption_required', $errors['caption'] );
		self::assertSame( 'lps_media_autoplay_forbidden', $errors['autoplay'] );
		$asset['caption_status'] = 'missing';
		$asset['transcript_status'] = 'missing';
		$errors = MediaPolicy::usage_errors( $usage, $asset );
		self::assertSame( 'lps_media_captions_missing', $errors['caption_status'] );
		self::assertSame( 'lps_media_transcript_missing', $errors['transcript_status'] );
	}

	public function test_essential_pdf_requires_contextual_html_equivalent(): void {
		$asset = self::valid_pdf_asset();
		$usage = array( 'block' => 'document', 'media_id' => 63, 'locale' => 'en', 'label' => 'Admissions notice', 'essential' => true, 'html_equivalent' => '', 'placement' => 'content' );
		self::assertSame( 'lps_media_html_equivalent_required', MediaPolicy::usage_errors( $usage, $asset )['html_equivalent'] );
		$usage['html_equivalent'] = '<p>Applications close on 30 September.</p>';
		self::assertSame( array(), MediaPolicy::usage_errors( $usage, $asset ) );
		$asset['document_status'] = 'inaccessible';
		self::assertSame( 'lps_media_document_inaccessible', MediaPolicy::usage_errors( $usage, $asset )['document_status'] );
	}
}
