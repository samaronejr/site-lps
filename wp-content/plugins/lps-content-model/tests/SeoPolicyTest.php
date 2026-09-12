<?php
/**
 * Canonical URL, hreflang, robots, sitemap, and redirect contracts.
 *
 * @package LPS\ContentModel\Tests
 */

declare(strict_types=1);

namespace LPS\ContentModel\Tests;

require_once dirname( __DIR__ ) . '/includes/class-seopolicy.php';

use LPS\ContentModel\SeoPolicy;
use PHPUnit\Framework\TestCase;

/** Contract tests for the SEO policy boundary. */
final class SeoPolicyTest extends TestCase {
	private const SITE = 'https://lps.ufrj.br';

	/** Locale slugs resolve to BCP47 tags. */
	public function test_locale_slugs_resolve_to_bcp47_tags(): void {
		self::assertSame( 'pt-BR', SeoPolicy::bcp47( 'pt-br' ) );
		self::assertSame( 'en', SeoPolicy::bcp47( 'en' ) );
		self::assertSame( '', SeoPolicy::bcp47( 'fr' ) );
	}

	/** A canonical URL is absolute, lowercase, query-free, and trailing-slashed. */
	public function test_canonical_url_is_absolute_normalized_and_query_free(): void {
		self::assertSame(
			'https://lps.ufrj.br/pt-br/projetos/monitoramento-de-turbinas/',
			SeoPolicy::canonical_url( self::SITE, '/pt-br/Projetos/Monitoramento-De-Turbinas?q=1' )
		);
		self::assertSame( 'https://lps.ufrj.br/en/', SeoPolicy::canonical_url( self::SITE . '/', '/en' ) );
	}

	/** A page never canonicalizes to a different address than itself. */
	public function test_canonical_url_is_self_referential_for_a_published_page(): void {
		$path = '/pt-br/noticias/nova-bancada/';
		self::assertSame(
			SeoPolicy::canonical_url( self::SITE, $path ),
			SeoPolicy::canonical_url( self::SITE, SeoPolicy::canonical_url( self::SITE, $path ) )
		);
	}

	/** Reciprocal alternates are emitted only when both locales are published. */
	public function test_alternates_are_emitted_only_for_published_reciprocal_pairs(): void {
		$alternates = SeoPolicy::alternates(
			self::SITE,
			array(
				'pt-br' => array(
					'path'      => '/pt-br/pessoas/ana-alvares/',
					'published' => true,
				),
				'en'    => array(
					'path'      => '/en/people/ana-alvares/',
					'published' => true,
				),
			)
		);

		self::assertSame(
			array(
				array(
					'hreflang' => 'pt-BR',
					'href'     => 'https://lps.ufrj.br/pt-br/pessoas/ana-alvares/',
				),
				array(
					'hreflang' => 'en',
					'href'     => 'https://lps.ufrj.br/en/people/ana-alvares/',
				),
				array(
					'hreflang' => 'x-default',
					'href'     => 'https://lps.ufrj.br/pt-br/pessoas/ana-alvares/',
				),
			),
			$alternates
		);
	}

	/** An unpublished counterpart removes every alternate, including the self link. */
	public function test_absent_reciprocal_locale_removes_all_alternates(): void {
		$alternates = SeoPolicy::alternates(
			self::SITE,
			array(
				'pt-br' => array(
					'path'      => '/pt-br/noticias/somente-portugues/',
					'published' => true,
				),
				'en'    => array(
					'path'      => '/en/news/portuguese-only/',
					'published' => false,
				),
			)
		);

		self::assertSame( array(), $alternates );
	}

	/** Indexable pages carry an explicit index directive. */
	public function test_published_public_page_is_indexable(): void {
		self::assertSame( 'index, follow', SeoPolicy::robots_directive( array() ) );
		self::assertTrue( SeoPolicy::is_indexable( array() ) );
	}

	/** Draft, preview, search, filter, expired, private, and withheld states are never indexable. */
	public function test_draft_preview_search_filter_expired_and_private_states_are_noindex(): void {
		foreach ( array( 'draft', 'preview', 'search', 'filtered', 'expired', 'private', 'unavailable' ) as $flag ) {
			self::assertSame( 'noindex, follow', SeoPolicy::robots_directive( array( $flag => true ) ), $flag );
			self::assertFalse( SeoPolicy::is_indexable( array( $flag => true ) ), $flag );
		}
	}

	/** Only self-canonical indexable pages reach a sitemap. */
	public function test_sitemap_admits_only_self_canonical_indexable_pages(): void {
		$page = array(
			'path'      => '/pt-br/projetos/turbinas/',
			'canonical' => 'https://lps.ufrj.br/pt-br/projetos/turbinas/',
			'state'     => array(),
		);
		self::assertTrue( SeoPolicy::in_sitemap( self::SITE, $page ) );

		$draft          = $page;
		$draft['state'] = array( 'draft' => true );
		self::assertFalse( SeoPolicy::in_sitemap( self::SITE, $draft ) );

		$aliased              = $page;
		$aliased['canonical'] = 'https://lps.ufrj.br/pt-br/projetos/outro/';
		self::assertFalse( SeoPolicy::in_sitemap( self::SITE, $aliased ) );
	}

	/** An address the public surface refuses to serve never reaches a sitemap. */
	public function test_sitemap_rejects_an_address_the_site_does_not_serve(): void {
		$withheld = array(
			'path'      => '/pt-br/pessoas/pessoa-sem-consentimento/',
			'canonical' => 'https://lps.ufrj.br/pt-br/pessoas/pessoa-sem-consentimento/',
			'state'     => array( 'unavailable' => true ),
		);

		self::assertFalse( SeoPolicy::in_sitemap( self::SITE, $withheld ) );
		self::assertFalse( SeoPolicy::is_indexable( array( 'unavailable' => true ) ) );
	}

	/** A legacy source resolves in exactly one hop even when the graph chains. */
	public function test_redirect_chain_is_flattened_to_a_single_hop(): void {
		$graph = array(
			'/lps/antigo.html' => array(
				'target' => '/pt-br/intermediario/',
				'status' => 301,
			),
			'/pt-br/intermediario/' => array(
				'target' => '/pt-br/pesquisa/',
				'status' => 301,
			),
		);

		$resolved = SeoPolicy::resolve_redirect( $graph, '/lps/antigo.html' );

		self::assertSame( 301, $resolved['status'] );
		self::assertSame( '/pt-br/pesquisa/', $resolved['target'] );
		self::assertSame( 1, $resolved['hops'] );
	}

	/** A deliberate removal answers 410 and never redirects. */
	public function test_deliberate_removal_answers_gone_without_a_target(): void {
		$resolved = SeoPolicy::resolve_redirect(
			array( '/lps/removido.html' => array( 'status' => 410 ) ),
			'/lps/removido.html'
		);

		self::assertSame( 410, $resolved['status'] );
		self::assertSame( '', $resolved['target'] );
		self::assertSame( 0, $resolved['hops'] );
	}

	/** An unknown source is not a redirect. */
	public function test_unknown_source_is_not_a_redirect(): void {
		self::assertSame( 404, SeoPolicy::resolve_redirect( array(), '/lps/desconhecido.html' )['status'] );
	}

	/** A redirect loop refuses to resolve instead of looping forever. */
	public function test_redirect_loop_refuses_to_resolve(): void {
		$graph = array(
			'/a/' => array(
				'target' => '/b/',
				'status' => 301,
			),
			'/b/' => array(
				'target' => '/a/',
				'status' => 301,
			),
		);

		self::assertSame( 404, SeoPolicy::resolve_redirect( $graph, '/a/' )['status'] );
	}

	/** Titles combine the record, its section, and the institutional suffix. */
	public function test_document_title_is_unique_localized_and_suffixed(): void {
		self::assertSame(
			'Monitoramento de turbinas — Projetos — LPS/UFRJ',
			SeoPolicy::document_title( 'Monitoramento de turbinas', 'Projetos', 'pt-br' )
		);
		self::assertSame(
			'Turbine monitoring — Projects — LPS/UFRJ',
			SeoPolicy::document_title( 'Turbine monitoring', 'Projects', 'en' )
		);
		self::assertSame( 'Projetos — LPS/UFRJ', SeoPolicy::document_title( '', 'Projetos', 'pt-br' ) );
	}

	/** Descriptions are single-line, collapsed, and bounded. */
	public function test_meta_description_is_collapsed_and_bounded(): void {
		$description = SeoPolicy::meta_description( "  Uma  linha\ncom   espaços\tdemais.  " );
		self::assertSame( 'Uma linha com espaços demais.', $description );

		$long = SeoPolicy::meta_description( str_repeat( 'palavra ', 60 ) );
		self::assertLessThanOrEqual( 160, mb_strlen( $long ) );
		self::assertStringEndsWith( '…', $long );
	}
}
