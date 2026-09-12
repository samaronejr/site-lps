<?php
/**
 * Head metadata, sitemap, feed, and robots rendering contracts.
 *
 * @package LPS\Theme\Tests
 */

declare(strict_types=1);

namespace LPS\Theme\Tests;

require_once dirname( __DIR__ ) . '/includes/class-seosurfaces.php';

use LPS\Theme\SeoSurfaces;
use PHPUnit\Framework\TestCase;

/** Contract tests for the SEO rendering library. */
final class SeoSurfacesTest extends TestCase {
	private const SITE = 'https://lps.ufrj.br';

	/**
	 * Returns a published bilingual news document.
	 *
	 * @return array<string, mixed>
	 */
	private static function document(): array {
		return array(
			'site_url'    => self::SITE,
			'locale'      => 'pt-br',
			'path'        => '/pt-br/noticias/nova-bancada/',
			'title'       => 'Nova bancada — Notícias — LPS/UFRJ',
			'description' => 'Bancada de ensaios instalada no laboratório.',
			'og_type'     => 'article',
			'state'       => array(),
			'variants'    => array(
				'pt-br' => array(
					'path'      => '/pt-br/noticias/nova-bancada/',
					'published' => true,
				),
				'en'    => array(
					'path'      => '/en/news/new-test-bench/',
					'published' => true,
				),
			),
			'graph'       => array(
				'@context' => 'https://schema.org',
				'@graph'   => array(
					array(
						'@type' => 'NewsArticle',
						'@id'   => self::SITE . '/pt-br/noticias/nova-bancada/#record',
						'name'  => 'Nova bancada',
					),
				),
			),
		);
	}

	/** The head carries exactly one title, description, and self-canonical link. */
	public function test_head_emits_one_title_description_and_self_canonical(): void {
		$head = SeoSurfaces::head_markup( self::document() );

		self::assertSame( 1, substr_count( $head, '<title>' ) );
		self::assertStringContainsString( '<title>Nova bancada — Notícias — LPS/UFRJ</title>', $head );
		self::assertSame( 1, substr_count( $head, 'name="description"' ) );
		self::assertSame( 1, substr_count( $head, 'rel="canonical"' ) );
		self::assertStringContainsString(
			'<link rel="canonical" href="https://lps.ufrj.br/pt-br/noticias/nova-bancada/">',
			$head
		);
	}

	/** Reciprocal alternates render with BCP47 tags plus x-default. */
	public function test_head_emits_reciprocal_alternates_with_bcp47_tags(): void {
		$head = SeoSurfaces::head_markup( self::document() );

		self::assertStringContainsString( 'hreflang="pt-BR" href="https://lps.ufrj.br/pt-br/noticias/nova-bancada/"', $head );
		self::assertStringContainsString( 'hreflang="en" href="https://lps.ufrj.br/en/news/new-test-bench/"', $head );
		self::assertStringContainsString( 'hreflang="x-default"', $head );
	}

	/** A page whose counterpart is unpublished emits no alternate at all. */
	public function test_head_omits_alternates_when_the_counterpart_is_unpublished(): void {
		$document             = self::document();
		$variants             = $document['variants'];
		self::assertIsArray( $variants );
		$variants['en']       = array(
			'path'      => '/en/news/new-test-bench/',
			'published' => false,
		);
		$document['variants'] = $variants;

		$head = SeoSurfaces::head_markup( $document );

		self::assertStringNotContainsString( 'hreflang=', $head );
		self::assertStringContainsString( 'rel="canonical"', $head );
	}

	/** Open Graph describes the same canonical document. */
	public function test_head_emits_open_graph_bound_to_the_canonical_url(): void {
		$head = SeoSurfaces::head_markup( self::document() );

		self::assertStringContainsString( '<meta property="og:type" content="article">', $head );
		self::assertStringContainsString( '<meta property="og:url" content="https://lps.ufrj.br/pt-br/noticias/nova-bancada/">', $head );
		self::assertStringContainsString( '<meta property="og:locale" content="pt_BR">', $head );
		self::assertStringContainsString( 'property="og:title"', $head );
		self::assertStringContainsString( 'property="og:description"', $head );
	}

	/** A draft document is marked noindex and never claims to be canonical elsewhere. */
	public function test_draft_document_is_marked_noindex(): void {
		$document          = self::document();
		$document['state'] = array( 'draft' => true );

		$head = SeoSurfaces::head_markup( $document );

		self::assertStringContainsString( '<meta name="robots" content="noindex, follow">', $head );
		self::assertSame( 1, substr_count( $head, 'name="robots"' ) );
	}

	/** Exactly one JSON-LD block is emitted and it is valid JSON. */
	public function test_head_emits_exactly_one_valid_json_ld_block(): void {
		$head = SeoSurfaces::head_markup( self::document() );

		self::assertSame( 1, substr_count( $head, 'application/ld+json' ) );
		self::assertSame( 1, preg_match( '#<script type="application/ld\+json">(.+?)</script>#s', $head, $found ) );
		$block = $found[1] ?? '';
		self::assertNotSame( '', $block );
		self::assertIsArray( json_decode( $block, true ) );
	}

	/** Hostile record text is escaped and can never break out of the head. */
	public function test_head_escapes_hostile_record_text(): void {
		$document                = self::document();
		$document['title']       = '</title><script>alert(1)</script>';
		$document['description'] = '"><script>alert(2)</script>';

		$head = SeoSurfaces::head_markup( $document );

		self::assertStringNotContainsString( '<script>alert(1)', $head );
		self::assertStringNotContainsString( '<script>alert(2)', $head );
	}

	/** WordPress may own the single robots tag; the head then defers to it. */
	public function test_head_defers_the_robots_tag_when_wordpress_owns_it(): void {
		$document           = self::document();
		$document['robots'] = false;

		$head = SeoSurfaces::head_markup( $document );

		self::assertStringNotContainsString( 'name="robots"', $head );
		self::assertStringContainsString( 'rel="canonical"', $head );
	}

	/** The head publishes the record type and kind for machine cross-checking. */
	public function test_head_emits_machine_readable_record_marker(): void {
		$document           = self::document();
		$document['record'] = array(
			'type' => 'lps_opportunity',
			'kind' => 'scholarship',
		);

		$head = SeoSurfaces::head_markup( $document );

		self::assertStringContainsString( '<meta name="lps:record-type" content="lps_opportunity">', $head );
		self::assertStringContainsString( '<meta name="lps:record-kind" content="scholarship">', $head );
	}

	/** WordPress may own the single title tag; the head then defers to it. */
	public function test_head_defers_the_title_tag_when_wordpress_owns_it(): void {
		$document              = self::document();
		$document['title_tag'] = false;

		$head = SeoSurfaces::head_markup( $document );

		self::assertStringNotContainsString( '<title>', $head );
		self::assertStringContainsString( 'property="og:title"', $head );
	}

	/** A locale sitemap lists only its own indexable entries. */
	public function test_locale_sitemap_lists_only_its_own_indexable_entries(): void {
		$xml = SeoSurfaces::locale_sitemap(
			self::SITE,
			array(
				array(
					'path'     => '/pt-br/noticias/nova-bancada/',
					'modified' => '2026-02-10T12:00:00+00:00',
				),
				array(
					'path'     => '/pt-br/projetos/turbinas/',
					'modified' => '2026-01-05T12:00:00+00:00',
				),
			)
		);

		self::assertStringStartsWith( '<?xml version="1.0" encoding="UTF-8"?>', $xml );
		self::assertSame( 2, substr_count( $xml, '<url>' ) );
		self::assertStringContainsString( '<loc>https://lps.ufrj.br/pt-br/noticias/nova-bancada/</loc>', $xml );
		self::assertStringContainsString( '<lastmod>2026-02-10T12:00:00+00:00</lastmod>', $xml );
		self::assertInstanceOf( \SimpleXMLElement::class, simplexml_load_string( $xml ) );
	}

	/** The sitemap index references one sitemap per locale. */
	public function test_sitemap_index_references_one_sitemap_per_locale(): void {
		$xml = SeoSurfaces::sitemap_index( self::SITE, array( 'pt-br', 'en' ) );

		self::assertSame( 2, substr_count( $xml, '<sitemap>' ) );
		self::assertStringContainsString( '<loc>https://lps.ufrj.br/sitemap-pt-br.xml</loc>', $xml );
		self::assertStringContainsString( '<loc>https://lps.ufrj.br/sitemap-en.xml</loc>', $xml );
		self::assertInstanceOf( \SimpleXMLElement::class, simplexml_load_string( $xml ) );
	}

	/** The news feed is valid RSS bound to its locale listing. */
	public function test_feed_is_valid_rss_bound_to_its_locale_listing(): void {
		$xml = SeoSurfaces::feed(
			array(
				'site_url' => self::SITE,
				'title'    => 'Notícias do LPS',
				'path'     => '/pt-br/noticias/',
				'locale'   => 'pt-br',
			),
			array(
				array(
					'title'       => 'Nova bancada',
					'path'        => '/pt-br/noticias/nova-bancada/',
					'description' => 'Bancada instalada.',
					'date'        => '2026-02-10T12:00:00+00:00',
				),
			)
		);

		$feed = simplexml_load_string( $xml );
		self::assertInstanceOf( \SimpleXMLElement::class, $feed );
		self::assertSame( 'Notícias do LPS', (string) $feed->channel->title );
		self::assertSame( 'pt-BR', (string) $feed->channel->language );
		self::assertSame( 'https://lps.ufrj.br/pt-br/noticias/nova-bancada/', (string) $feed->channel->item[0]->link );
		self::assertSame( 'Tue, 10 Feb 2026 12:00:00 +0000', (string) $feed->channel->item[0]->pubDate );
	}

	/** The robots.txt document disallows non-indexable surfaces and advertises the sitemap index. */
	public function test_robots_txt_disallows_nonindexable_surfaces(): void {
		$robots = SeoSurfaces::robots_txt( self::SITE );

		self::assertStringContainsString( 'Sitemap: https://lps.ufrj.br/sitemap.xml', $robots );
		self::assertStringContainsString( 'Disallow: /pt-br/busca/', $robots );
		self::assertStringContainsString( 'Disallow: /en/search/', $robots );
		self::assertStringContainsString( 'Disallow: /wp-admin/', $robots );
		self::assertStringContainsString( 'Disallow: /*?*', $robots );
	}
}
