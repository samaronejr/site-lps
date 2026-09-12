<?php
/**
 * Controlled bilingual publishing and translation freshness tests.
 *
 * @package LPS\ContentModel\Tests
 */

declare(strict_types=1);

namespace LPS\ContentModel\Tests;

require_once dirname( __DIR__ ) . '/includes/class-translationpolicy.php';

use LPS\ContentModel\TranslationPolicy;
use PHPUnit\Framework\TestCase;

final class TranslationContractsTest extends TestCase {
	public function test_declares_exact_locales_and_prefixed_route_contract(): void {
		self::assertSame(
			array(
				'pt-br' => array( 'locale' => 'pt_BR', 'w3c' => 'pt-BR', 'name' => 'Portugues do Brasil' ),
				'en'    => array( 'locale' => 'en_US', 'w3c' => 'en', 'name' => 'English' ),
			),
			TranslationPolicy::locales()
		);
		self::assertSame( array( 'force_lang' => 1, 'hide_default' => 0, 'rewrite' => 1, 'default_lang' => 'pt-br', 'browser' => 0 ), TranslationPolicy::route_options() );
		self::assertTrue( TranslationPolicy::route_is_locale_prefixed( '/pt-br/projects/example/' ) );
		self::assertTrue( TranslationPolicy::route_is_locale_prefixed( '/en/projects/example/' ) );
		self::assertFalse( TranslationPolicy::route_is_locale_prefixed( '/projects/example/' ) );
		self::assertFalse( TranslationPolicy::route_is_locale_prefixed( '/fr/projects/example/' ) );
	}

	public function test_required_english_matrix_is_explicit_and_news_events_are_optional(): void {
		$required_types = array( 'lps_person', 'lps_research_area', 'lps_project', 'lps_publication', 'lps_opportunity' );
		foreach ( $required_types as $post_type ) {
			self::assertTrue( TranslationPolicy::requires_english( $post_type ) );
		}
		foreach ( array( 'lps_news', 'lps_event', 'lps_organization', 'lps_redirect' ) as $post_type ) {
			self::assertFalse( TranslationPolicy::requires_english( $post_type ) );
		}
		foreach ( array( 'home', 'about', 'research', 'projects', 'people', 'publications', 'infrastructure', 'opportunities', 'collaboration', 'contact', 'privacy', 'accessibility' ) as $page_key ) {
			self::assertTrue( TranslationPolicy::requires_english( 'page', $page_key ) );
		}
		self::assertFalse( TranslationPolicy::requires_english( 'page', 'news' ) );
	}

	public function test_relevant_portuguese_changes_make_reviewed_english_stale(): void {
		$source = array(
			'post_title'            => 'Projeto',
			'post_excerpt'          => 'Resumo',
			'post_content'          => 'Corpo inicial',
			'_lps_project_status'   => 'active',
			'_lps_start_date'       => '2025-01-01',
			'_lps_application_note' => 'ignore unknown meta',
		);
		$first_hash = TranslationPolicy::source_hash( 'lps_project', $source );
		self::assertFalse( TranslationPolicy::is_stale( $first_hash, $first_hash ) );
		$source['post_content'] = 'Corpo revisado';
		$second_hash            = TranslationPolicy::source_hash( 'lps_project', $source );
		self::assertNotSame( $first_hash, $second_hash );
		self::assertTrue( TranslationPolicy::is_stale( $first_hash, $second_hash ) );
		$source['_lps_updated_at'] = '2099-01-01T00:00:00Z';
		self::assertSame( $second_hash, TranslationPolicy::source_hash( 'lps_project', $source ) );
	}

	public function test_shared_and_material_fields_are_not_translation_owned(): void {
		self::assertContains( '_lps_record_id', TranslationPolicy::shared_meta_keys( 'lps_project' ) );
		self::assertContains( '_lps_start_date', TranslationPolicy::shared_meta_keys( 'lps_project' ) );
		self::assertContains( '_lps_closes_at', TranslationPolicy::material_meta_keys( 'lps_opportunity' ) );
		self::assertContains( '_lps_event_status', TranslationPolicy::material_meta_keys( 'lps_event' ) );
		self::assertSame( array(), TranslationPolicy::material_meta_keys( 'lps_person' ) );
		self::assertSame( 'lps_shared_field_portuguese_authority', TranslationPolicy::shared_write_error( 'en', '_lps_start_date', 'lps_project' ) );
		self::assertNull( TranslationPolicy::shared_write_error( 'pt-br', '_lps_start_date', 'lps_project' ) );
	}

	public function test_publish_decisions_preserve_independent_drafts_and_no_fallback(): void {
		self::assertSame( 'lps_required_english_variant_missing', TranslationPolicy::publish_error( 'lps_project', '', 'pt-br', 'publish', null, null ) );
		self::assertSame( 'lps_required_english_variant_unpublished', TranslationPolicy::publish_error( 'lps_project', '', 'pt-br', 'publish', 'draft', null ) );
		self::assertNull( TranslationPolicy::publish_error( 'lps_project', '', 'pt-br', 'draft', null, null ) );
		self::assertNull( TranslationPolicy::publish_error( 'lps_news', '', 'pt-br', 'publish', null, null ) );
		self::assertSame( 'lps_material_translation_sync_required', TranslationPolicy::publish_error( 'lps_opportunity', '', 'pt-br', 'publish', 'publish', false ) );
		self::assertSame( 'lps_english_source_review_required', TranslationPolicy::publish_error( 'lps_project', '', 'en', 'publish', 'publish', true ) );
		self::assertNull( TranslationPolicy::fallback_post_id( array( 'pt-br' => 10 ), 'en' ) );
	}

	public function test_dashboard_rows_are_missing_before_stale_then_type_title_and_id(): void {
		$rows = array(
			array( 'state' => 'stale', 'post_type' => 'lps_project', 'title' => 'Zulu', 'source_id' => 9 ),
			array( 'state' => 'missing', 'post_type' => 'lps_person', 'title' => 'Ana', 'source_id' => 4 ),
			array( 'state' => 'missing', 'post_type' => 'lps_person', 'title' => 'Ana', 'source_id' => 2 ),
			array( 'state' => 'stale', 'post_type' => 'lps_person', 'title' => 'Beta', 'source_id' => 3 ),
		);
		self::assertSame( array( 2, 4, 3, 9 ), array_column( TranslationPolicy::sort_report( $rows ), 'source_id' ) );
	}

	public function test_only_published_real_variants_produce_hreflang(): void {
		$variants = array(
			'pt-br' => array( 'status' => 'publish', 'url' => 'https://example.test/pt-br/projetos/x/' ),
			'en'    => array( 'status' => 'draft', 'url' => 'https://example.test/en/projects/x/' ),
		);
		self::assertSame( array( 'pt-BR' => 'https://example.test/pt-br/projetos/x/' ), TranslationPolicy::hreflangs( $variants ) );
		self::assertSame( 'en', TranslationPolicy::html_lang( 'en' ) );
		self::assertSame( 'pt-BR', TranslationPolicy::html_lang( 'pt-br' ) );
	}
}
