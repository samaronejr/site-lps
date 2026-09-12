<?php
/**
 * Locale route and citation download contracts.
 *
 * @package LPS\Theme\Tests
 */

declare(strict_types=1);

namespace LPS\Theme\Tests;

use LPS\Theme\DiscoveryRoutes;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/includes/class-discoveryroutes.php';

/** Contract tests for discovery locale routes and citation downloads. */
final class DiscoveryRoutesTest extends TestCase {
	/**
	 * Provides the frozen locale route for each discovery record type.
	 *
	 * @return array<string, array{string, string, string}>
	 */
	public static function locale_routes(): array {
		return array(
			'research pt-br'     => array( 'lps_research_area', 'pt-br', '/pt-br/pesquisa/' ),
			'research en'        => array( 'lps_research_area', 'en', '/en/research/' ),
			'projects pt-br'     => array( 'lps_project', 'pt-br', '/pt-br/projetos/' ),
			'projects en'        => array( 'lps_project', 'en', '/en/projects/' ),
			'publications pt-br' => array( 'lps_publication', 'pt-br', '/pt-br/publicacoes/' ),
			'publications en'    => array( 'lps_publication', 'en', '/en/publications/' ),
		);
	}

	/**
	 * Every record type has a frozen route in both locales.
	 *
	 * @param string $post_type Data-provider value.
	 * @param string $locale    Data-provider value.
	 * @param string $path      Data-provider value.
	 */
	#[DataProvider( 'locale_routes' )]
	public function test_every_record_type_has_a_frozen_route_in_both_locales( string $post_type, string $locale, string $path ): void {
		self::assertSame( $path, DiscoveryRoutes::archive_path( $post_type, $locale ) );
		self::assertSame( $path . 'slug-do-registro/', DiscoveryRoutes::single_path( $post_type, $locale, 'slug-do-registro' ) );
	}

	/**
	 * Archive and single paths resolve back to type and locale.
	 *
	 * @param string $post_type Data-provider value.
	 * @param string $locale    Data-provider value.
	 * @param string $path      Data-provider value.
	 */
	#[DataProvider( 'locale_routes' )]
	public function test_archive_and_single_paths_resolve_back_to_type_and_locale( string $post_type, string $locale, string $path ): void {
		$archive = DiscoveryRoutes::match_path( $path );
		self::assertIsArray( $archive );
		self::assertSame( $post_type, $archive['post_type'] );
		self::assertSame( $locale, $archive['locale'] );
		self::assertSame( '', $archive['slug'] );

		$single = DiscoveryRoutes::match_path( $path . 'um-registro/' );
		self::assertIsArray( $single );
		self::assertSame( $post_type, $single['post_type'] );
		self::assertSame( $locale, $single['locale'] );
		self::assertSame( 'um-registro', $single['slug'] );
	}

	/**
	 * Unknown paths do not match a discovery route.
	 */
	public function test_unknown_paths_do_not_match_a_discovery_route(): void {
		self::assertNull( DiscoveryRoutes::match_path( '/pt-br/pessoas/' ) );
		self::assertNull( DiscoveryRoutes::match_path( '/en/' ) );
		self::assertNull( DiscoveryRoutes::match_path( '/fr/research/' ) );
	}

	/**
	 * Route locales are declared as bcp47 language tags.
	 */
	public function test_route_locales_are_declared_as_bcp47_language_tags(): void {
		self::assertSame( 'pt-BR', DiscoveryRoutes::bcp47( 'pt-br' ) );
		self::assertSame( 'en', DiscoveryRoutes::bcp47( 'en' ) );
	}

	/**
	 * Rewrite rules cover archive paged and single requests.
	 */
	public function test_rewrite_rules_cover_archive_paged_and_single_requests(): void {
		$rules = DiscoveryRoutes::rewrite_rules();

		self::assertNotSame( array(), $rules );
		foreach ( $rules as $pattern => $query ) {
			self::assertStringStartsWith( 'index.php?', $query );
			self::assertStringContainsString( 'lps_discovery_locale=', $query );
			self::assertSame( 1, preg_match( '/^[^^].*\$$/', $pattern ), 'Rewrite patterns are anchored at the end.' );
		}
		$targets = array_values( $rules );
		self::assertNotSame(
			array(),
			array_filter( $targets, static fn( string $query ): bool => str_contains( $query, 'paged=' ) ),
			'A paged archive rule exists.'
		);
		self::assertNotSame(
			array(),
			array_filter( $targets, static fn( string $query ): bool => str_contains( $query, 'name=' ) ),
			'A single-record rule exists.'
		);
	}

	/**
	 * Citation downloads declare real content types.
	 */
	public function test_citation_downloads_declare_real_content_types(): void {
		$bibtex = DiscoveryRoutes::citation_headers( 'bibtex', 'lps77' );
		self::assertSame( 'application/x-bibtex; charset=utf-8', $bibtex['Content-Type'] );
		self::assertSame( 'attachment; filename="lps77.bib"', $bibtex['Content-Disposition'] );
		self::assertSame( 'nosniff', $bibtex['X-Content-Type-Options'] );

		$csl = DiscoveryRoutes::citation_headers( 'csl-json', 'lps77' );
		self::assertSame( 'application/vnd.citationstyles.csl+json; charset=utf-8', $csl['Content-Type'] );
		self::assertSame( 'attachment; filename="lps77.json"', $csl['Content-Disposition'] );
	}

	/**
	 * Unsupported citation format is refused.
	 */
	public function test_unsupported_citation_format_is_refused(): void {
		self::assertSame( array(), DiscoveryRoutes::citation_headers( 'endnote', 'lps77' ) );
		self::assertNull( DiscoveryRoutes::citation_body( 'endnote', array(), array() ) );
	}

	/**
	 * Citation bodies are real bibtex and csl json.
	 */
	public function test_citation_bodies_are_real_bibtex_and_csl_json(): void {
		$record  = array(
			'id'    => 77,
			'title' => 'Sigma Signals',
			'date'  => '2025-01-01',
			'doi'   => '10.5555/lps.77',
			'venue' => 'Journal',
		);
		$authors = array( array( 'name' => 'Zoe First' ), array( 'name' => 'Ada Second' ) );

		$bibtex = DiscoveryRoutes::citation_body( 'bibtex', $record, $authors );
		self::assertIsString( $bibtex );
		self::assertStringStartsWith( '@article{lps77,', $bibtex );
		self::assertGreaterThan( 1, substr_count( $bibtex, "\n" ) );

		$csl = DiscoveryRoutes::citation_body( 'csl-json', $record, $authors );
		self::assertIsString( $csl );
		$decoded = json_decode( $csl, true, 512, JSON_THROW_ON_ERROR );
		self::assertIsArray( $decoded );
		self::assertSame( 'lps77', $decoded['id'] );
		self::assertIsArray( $decoded['author'] );
		self::assertCount( 2, $decoded['author'] );
	}

	/**
	 * Citation urls are locale stable and carry the format.
	 */
	public function test_citation_urls_are_locale_stable_and_carry_the_format(): void {
		self::assertSame( '/pt-br/publicacoes/artigo/?lps_citation=bibtex', DiscoveryRoutes::citation_url( 'pt-br', 'artigo', 'bibtex' ) );
		self::assertSame( '/en/publications/paper/?lps_citation=csl-json', DiscoveryRoutes::citation_url( 'en', 'paper', 'csl-json' ) );
	}

	/**
	 * Discovery documents declare the route language tag.
	 */
	public function test_discovery_documents_declare_the_route_language_tag(): void {
		self::assertSame(
			'lang="en" dir="ltr"',
			DiscoveryRoutes::language_attributes_for_path( 'lang="en-US" dir="ltr"', '/en/publications/paper/' )
		);
		self::assertSame(
			'lang="pt-BR"',
			DiscoveryRoutes::language_attributes_for_path( 'lang="pt-BR"', '/pt-br/publicacoes/artigo/' )
		);
	}

	/**
	 * Documents outside discovery routes keep their language attributes.
	 */
	public function test_documents_outside_discovery_routes_keep_their_language_attributes(): void {
		self::assertSame(
			'lang="en-US"',
			DiscoveryRoutes::language_attributes_for_path( 'lang="en-US"', '/en/people/' )
		);
	}
}
