<?php
/**
 * Content-model contract tests.
 *
 * @package LPS\ContentModel\Tests
 */

declare(strict_types=1);

namespace LPS\ContentModel\Tests;

require_once dirname( __DIR__ ) . '/includes/class-policy.php';
require_once dirname(__DIR__) . '/includes/class-contracts.php';

use LPS\ContentModel\Contracts;
use LPS\ContentModel\Policy;
use LPS\ContentModel\TeachingContracts;
use PHPUnit\Framework\TestCase;

final class ContentContractsTest extends TestCase {
	public function test_registers_every_portable_record_type_with_rest_support(): void {
		$types = Contracts::post_types();
		self::assertSame(
			array( 'page', 'lps_person', 'lps_organization', 'lps_research_area', 'lps_project', 'lps_publication', 'lps_news', 'lps_opportunity', 'lps_event', 'lps_redirect', 'lps_course', 'lps_term', 'lps_offering', 'lps_unit', 'lps_resource' ),
			array_keys( $types )
		);
		foreach ( $types as $type => $definition ) {
			self::assertTrue( $definition['show_in_rest'], $type . ' must be visible through REST' );
			self::assertArrayHasKey( 'rest_base', $definition );
		}
	}

	public function test_declares_typed_rest_metadata_and_one_site_settings_schema(): void {
		$fields = Contracts::meta_fields();
		self::assertGreaterThanOrEqual( 90, array_sum( array_map( 'count', $fields ) ) );
		foreach ( $fields as $postType => $definitions ) {
			self::assertNotEmpty( $definitions, $postType . ' must have metadata' );
			$private_keys = array_merge( array( '_lps_owner_user_id', '_lps_translation_reviewer_id' ), TeachingContracts::private_meta_keys() );
			foreach ( $definitions as $key => $definition ) {
				self::assertContains( $definition['type'], array( 'string', 'integer', 'number', 'boolean', 'array' ) );
				self::assertSame( ! in_array( $key, $private_keys, true ), $definition['show_in_rest'], $key . ' must respect REST privacy' );
			}
		}
		$settings = Contracts::site_settings_schema();
		self::assertSame( 'object', $settings['type'] );
		self::assertCount( 19, $settings['properties'] );
	}

	public function test_identifier_and_metadata_sanitization_is_strict(): void {
		self::assertSame( '', Policy::sanitize_record_id( 'not-an-id<script>' ) );
		self::assertSame( 'lps:person:018f21ce-7d7a-7abc-8a2f-2d6937f89a11', Policy::sanitize_record_id( 'lps:person:018F21CE-7D7A-7ABC-8A2F-2D6937F89A11' ) );
		$person = Contracts::meta_fields()['lps_person'];
		self::assertSame( 'Janealert(1)', ( $person['_lps_canonical_name']['sanitize_callback'] )( 'Jane<script>alert(1)</script>' ) );
		self::assertSame( 'https://example.org/profile', ( $person['_lps_website_url']['sanitize_callback'] )( 'javascript:alert(1) https://example.org/profile' ) );
	}

	public function test_record_identity_and_published_slug_are_immutable(): void {
		self::assertTrue( Policy::can_change_identity( '', 'lps:person:018f21ce-7d7a-7abc-8a2f-2d6937f89a11' ) );
		self::assertTrue( Policy::can_change_identity( 'alpha', 'alpha' ) );
		self::assertFalse( Policy::can_change_identity( 'alpha', 'beta' ) );
	}

	public function test_state_transitions_and_review_dates_are_governed(): void {
		self::assertTrue( Policy::valid_transition( 'draft', 'in_review' ) );
		self::assertTrue( Policy::valid_transition( 'in_review', 'published' ) );
		self::assertTrue( Policy::valid_transition( 'published', 'archived' ) );
		self::assertFalse( Policy::valid_transition( 'archived', 'published' ) );
		self::assertArrayHasKey( '_lps_review_date', Contracts::meta_fields()['lps_project'] );
	}

	public function test_publish_validation_denies_missing_localized_and_type_required_fields(): void {
		$errors = Policy::publish_errors(
			'lps_person',
			array(
				'post_title'   => '',
				'post_excerpt' => '',
				'post_content' => '',
				'_lps_locale'  => 'fr',
			)
		);
		self::assertSame( 'lps_required_title', $errors['post_title'] );
		self::assertSame( 'lps_required_summary', $errors['post_excerpt'] );
		self::assertSame( 'lps_required_body', $errors['post_content'] );
		self::assertSame( 'lps_invalid_locale', $errors['_lps_locale'] );
		self::assertSame( 'lps_required_canonical_name', $errors['_lps_canonical_name'] );
	}

	public function test_published_or_referenced_records_must_be_archived_not_deleted(): void {
		self::assertSame( 'lps_archive_required', Policy::deletion_error( true, false ) );
		self::assertSame( 'lps_record_referenced', Policy::deletion_error( false, true ) );
		self::assertNull( Policy::deletion_error( false, false ) );
	}
}
