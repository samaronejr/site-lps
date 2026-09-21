<?php
/**
 * Search route, request-state, canonical URL, and rendering contracts.
 *
 * @package LPS\Theme\Tests
 */

declare(strict_types=1);

namespace LPS\Theme\Tests;

use LPS\ContentModel\SearchIndex;
use LPS\ContentModel\SearchPolicy;
use LPS\Theme\SearchRoutes;
use LPS\Theme\SearchSurfaces;
use PHPUnit\Framework\Attributes\DataProvider;

require_once dirname( __DIR__ ) . '/includes/class-searchroutes.php';

/** Contract tests for the server-rendered locale search surface. */
final class SearchRoutesTest extends \PHPUnit\Framework\TestCase {
	/**
	 * Provides the frozen search route of each locale.
	 *
	 * @return array<string, array{string, string}>
	 */
	public static function search_routes(): array {
		return array(
			'portuguese' => array( 'pt-br', '/pt-br/busca/' ),
			'english'    => array( 'en', '/en/search/' ),
		);
	}

	/**
	 * Both locales expose a frozen server-rendered search route.
	 *
	 * @param string $locale Data-provider value.
	 * @param string $path   Data-provider value.
	 */
	#[DataProvider( 'search_routes' )]
	public function test_both_locales_expose_a_frozen_search_route( string $locale, string $path ): void {
		self::assertSame( $path, SearchRoutes::search_path( $locale ) );

		$route = SearchRoutes::match_path( $path );
		self::assertIsArray( $route );
		self::assertSame( $locale, $route['locale'] );
		self::assertSame( 1, $route['page'] );

		$paged = SearchRoutes::match_path( $path . 'page/3/' );
		self::assertIsArray( $paged );
		self::assertSame( 3, $paged['page'] );
	}

	/**
	 * Unknown paths never resolve to the search surface.
	 */
	public function test_unknown_paths_never_resolve_to_the_search_surface(): void {
		self::assertNull( SearchRoutes::match_path( '/pt-br/search/' ) );
		self::assertNull( SearchRoutes::match_path( '/en/busca/' ) );
		self::assertNull( SearchRoutes::match_path( '/fr/search/' ) );
		self::assertNull( SearchRoutes::match_path( '/pt-br/publicacoes/' ) );
	}

	/**
	 * Rewrite rules cover the bare and paginated search routes.
	 */
	public function test_rewrite_rules_cover_the_bare_and_paginated_search_routes(): void {
		$rules = SearchRoutes::rewrite_rules();

		self::assertArrayHasKey( 'pt-br/busca/?$', $rules );
		self::assertArrayHasKey( 'en/search/?$', $rules );
		self::assertArrayHasKey( 'pt-br/busca/page/([0-9]{1,})/?$', $rules );
		foreach ( $rules as $query ) {
			self::assertStringStartsWith( 'index.php?pagename=', $query );
			self::assertStringContainsString( 'lps_search_locale=', $query );
		}
	}

	/**
	 * Search documents declare the route language tag.
	 */
	public function test_search_documents_declare_the_route_language_tag(): void {
		self::assertSame( 'lang="en" dir="ltr"', SearchRoutes::language_attributes_for_path( 'lang="pt-BR" dir="ltr"', '/en/search/' ) );
		self::assertSame( 'lang="pt-BR"', SearchRoutes::language_attributes_for_path( 'lang="en-US"', '/pt-br/busca/' ) );
		self::assertSame( 'lang="en-US"', SearchRoutes::language_attributes_for_path( 'lang="en-US"', '/en/people/' ) );
	}

	/**
	 * Request state keeps only approved query, record type, facets, and page.
	 */
	public function test_request_state_keeps_only_approved_query_record_facets_and_page(): void {
		$state = SearchRoutes::state_from_request(
			array(
				'q'        => '  instrumentação  ',
				'record'   => 'lps_project',
				'status'   => array( 'active', 'completed' ),
				'area'     => array( 'signal-processing', '<script>' ),
				'unknown'  => array( 'x' ),
				'lps_page' => '2',
			),
			'pt-br'
		);

		self::assertSame( 'instrumentação', $state['query'] );
		self::assertSame( 'lps_project', $state['record'] );
		self::assertSame(
			array(
				'status' => array( 'active', 'completed' ),
				'area'   => array( 'signal-processing' ),
			),
			$state['facets']
		);
		self::assertSame( 2, $state['page'] );
		self::assertSame( '', $state['error'] );
	}

	/**
	 * An unknown record type collapses to the all-types search.
	 */
	public function test_an_unknown_record_type_collapses_to_the_all_types_search(): void {
		$state = SearchRoutes::state_from_request(
			array(
				'q'      => 'sinais',
				'record' => 'wp_user',
				'year'   => array( '2025' ),
			),
			'pt-br'
		);

		self::assertSame( '', $state['record'] );
		self::assertSame( array(), $state['facets'] );
	}

	/**
	 * Provides malformed and out-of-range queries.
	 *
	 * @return array<string, array{string, string}>
	 */
	public static function boundary_queries(): array {
		return array(
			'one character' => array( 'a', 'too_short' ),
			'overlong'      => array( str_repeat( 'a', 101 ), 'too_long' ),
			'null byte'     => array( "sinal\0", 'malformed' ),
			'markup only'   => array( '<<>>', 'malformed' ),
		);
	}

	/**
	 * Boundary queries report an accessible, non-leaking error state.
	 *
	 * @param string $query    Data-provider value.
	 * @param string $expected Data-provider value.
	 */
	#[DataProvider( 'boundary_queries' )]
	public function test_boundary_queries_report_an_accessible_error_state( string $query, string $expected ): void {
		$state = SearchRoutes::state_from_request( array( 'q' => $query ), 'pt-br' );
		self::assertSame( $expected, $state['error'] );

		$html = SearchSurfaces::render( $state, array( 'total' => 0 ), array(), array(), 'pt-br', '/pt-br/busca/' );
		self::assertStringContainsString( 'role="alert"', $html );
		self::assertStringContainsString( SearchSurfaces::error_message( $expected, 'pt-br' ), $html );
		self::assertStringNotContainsString( '<ol class="lps-search-results"', $html );
		self::assertStringNotContainsString( '<script', $html );
	}

	/**
	 * Canonical URLs are stable, shareable, and independent of parameter order.
	 */
	public function test_canonical_urls_are_stable_shareable_and_order_independent(): void {
		$first  = SearchRoutes::state_from_request(
			array(
				'q'        => 'sinais',
				'record'   => 'lps_project',
				'area'     => array( 'signal-processing' ),
				'status'   => array( 'completed', 'active' ),
				'lps_page' => '2',
			),
			'pt-br'
		);
		$second = SearchRoutes::state_from_request(
			array(
				'lps_page' => '2',
				'status'   => array( 'active', 'completed' ),
				'area'     => array( 'signal-processing' ),
				'record'   => 'lps_project',
				'q'        => 'sinais',
			),
			'pt-br'
		);

		$url = SearchRoutes::canonical_url( '/pt-br/busca/', $first );
		self::assertSame( $url, SearchRoutes::canonical_url( '/pt-br/busca/', $second ) );
		self::assertSame(
			'/pt-br/busca/page/2/?q=sinais&record=lps_project&area%5B%5D=signal-processing&status%5B%5D=active&status%5B%5D=completed',
			$url
		);
		self::assertSame( '/pt-br/busca/?q=sinais&record=lps_project&area%5B%5D=signal-processing&status%5B%5D=active&status%5B%5D=completed', SearchRoutes::canonical_url( '/pt-br/busca/', $first, 1 ) );
	}

	/**
	 * Filter and pagination combinations are never crawlable.
	 */
	public function test_filter_and_pagination_combinations_are_never_crawlable(): void {
		$bare = SearchRoutes::state_from_request( array(), 'pt-br' );
		self::assertSame( 'index,follow', SearchRoutes::robots_directive( $bare ) );

		$searched = SearchRoutes::state_from_request( array( 'q' => 'sinais' ), 'pt-br' );
		self::assertSame( 'noindex,follow', SearchRoutes::robots_directive( $searched ) );

		$filtered = SearchRoutes::state_from_request(
			array(
				'record' => 'lps_project',
				'status' => array( 'active' ),
			),
			'pt-br'
		);
		self::assertSame( 'noindex,follow', SearchRoutes::robots_directive( $filtered ) );

		$paged = SearchRoutes::state_from_request(
			array(
				'q'        => 'sinais',
				'lps_page' => '4',
			),
			'pt-br'
		);
		self::assertSame( 'noindex,follow', SearchRoutes::robots_directive( $paged ) );
	}

	/**
	 * The surface renders without any script or client-side dependency.
	 */
	public function test_the_surface_renders_without_any_script_dependency(): void {
		$state  = SearchRoutes::state_from_request(
			array(
				'q'      => 'sinal',
				'record' => 'lps_project',
			),
			'pt-br'
		);
		$result = SearchPolicy::search_records( self::rows(), 'sinal', 'pt-br', array(), 1, 20 );
		$counts = SearchPolicy::facet_counts( self::rows(), SearchPolicy::facet_definitions( 'lps_project' ) );

		$html = SearchSurfaces::render( $state, $result, $counts, SearchPolicy::facet_definitions( 'lps_project' ), 'pt-br', '/pt-br/busca/' );

		self::assertStringNotContainsString( '<script', $html );
		self::assertStringNotContainsString( 'onclick', $html );
		self::assertStringContainsString( '<form class="lps-search-form" method="get" action="/pt-br/busca/" role="search" aria-label="Buscar no site">', $html );
		self::assertStringContainsString( 'type="checkbox"', $html );
		self::assertStringContainsString( '<button class="lps-button lps-button-primary" type="submit">', $html );
	}

	/**
	 * The surface carries its own layout hook and grouped facet values.
	 */
	public function test_the_surface_carries_its_layout_hook_and_grouped_facets(): void {
		$counts = SearchPolicy::facet_counts( self::rows(), SearchPolicy::facet_definitions( 'lps_project' ) );

		$html = SearchSurfaces::render(
			SearchRoutes::state_from_request( array( 'q' => 'sinal' ), 'pt-br' ),
			SearchPolicy::search_records( self::rows(), 'sinal', 'pt-br', array(), 1, 20 ),
			$counts,
			SearchPolicy::facet_definitions( 'lps_project' ),
			'pt-br',
			'/pt-br/busca/'
		);

		self::assertStringContainsString( '<section class="lps-search lps-search-surface"', $html );
		self::assertStringContainsString( 'lps-search-facet-values', $html );
		self::assertStringContainsString( 'lps-search-kind', $html );
	}

	/**
	 * Facet controls announce their available counts.
	 */
	public function test_facet_controls_announce_their_available_counts(): void {
		$counts = SearchPolicy::facet_counts( self::rows(), SearchPolicy::facet_definitions( 'lps_project' ) );
		self::assertSame(
			array(
				'active'    => 2,
				'completed' => 1,
			),
			$counts['status']
		);

		$html = SearchSurfaces::render(
			SearchRoutes::state_from_request( array( 'q' => 'sinal' ), 'pt-br' ),
			SearchPolicy::search_records( self::rows(), 'sinal', 'pt-br', array(), 1, 20 ),
			$counts,
			SearchPolicy::facet_definitions( 'lps_project' ),
			'pt-br',
			'/pt-br/busca/'
		);

		self::assertStringContainsString( '<legend>Situação</legend>', $html );
		self::assertStringContainsString( 'active <span class="lps-facet-count">(2)</span>', $html );
		self::assertStringContainsString( '3 resultados', $html );
	}

	/**
	 * An empty result set renders an accessible empty state.
	 */
	public function test_an_empty_result_set_renders_an_accessible_empty_state(): void {
		$state  = SearchRoutes::state_from_request( array( 'q' => 'termoinexistente' ), 'pt-br' );
		$result = SearchPolicy::search_records( self::rows(), 'termoinexistente', 'pt-br', array(), 1, 20 );

		$html = SearchSurfaces::render( $state, $result, array(), array(), 'pt-br', '/pt-br/busca/' );

		self::assertSame( 0, $result['total'] );
		self::assertStringContainsString( 'role="status"', $html );
		self::assertStringContainsString( '0 resultado', $html );
		self::assertStringContainsString( 'Nenhum registro corresponde a esta busca.', $html );
	}

	/**
	 * The bare route invites a search instead of announcing zero results.
	 */
	public function test_the_bare_route_invites_a_search_instead_of_announcing_zero_results(): void {
		$html = SearchSurfaces::render( SearchRoutes::state_from_request( array(), 'en' ), array( 'total' => 0 ), array(), array(), 'en', '/en/search/' );

		self::assertStringContainsString( 'Enter a term to search records published in this language.', $html );
		self::assertStringNotContainsString( 'No record matches this search.', $html );
	}

	/**
	 * Hostile record content is escaped instead of rendered.
	 */
	public function test_hostile_record_content_is_escaped_instead_of_rendered(): void {
		$rows   = array(
			SearchIndex::hydrate(
				SearchIndex::build_row(
					array(
						'post_id'   => 900,
						'locale'    => 'pt-br',
						'post_type' => 'lps_news',
						'title'     => '<script>alert(1)</script> Sinal',
						'summary'   => '"><img src=x onerror=alert(2)> sinal',
						'body'      => 'corpo',
						'url'       => 'javascript:alert(3)',
						'facets'    => array(),
					)
				)
			),
		);
		$result = SearchPolicy::search_records( $rows, 'sinal', 'pt-br', array(), 1, 20 );

		$html = SearchSurfaces::render( SearchRoutes::state_from_request( array( 'q' => 'sinal' ), 'pt-br' ), $result, array(), array(), 'pt-br', '/pt-br/busca/' );

		self::assertSame( 1, $result['total'] );
		self::assertStringNotContainsString( '<script', $html );
		self::assertStringNotContainsString( '<img', $html );
		self::assertStringNotContainsString( 'javascript:', $html );
		self::assertStringContainsString( '&lt;script&gt;', $html );
	}

	/**
	 * Pagination links stay canonical and omit no record across pages.
	 */
	public function test_pagination_links_stay_canonical_and_omit_no_record_across_pages(): void {
		$rows = array();
		for ( $id = 1; $id <= 45; ++$id ) {
			$rows[] = SearchIndex::hydrate(
				SearchIndex::build_row(
					array(
						'post_id'   => $id,
						'locale'    => 'pt-br',
						'post_type' => 'lps_news',
						'title'     => 'Sinal ' . $id,
						'summary'   => 'resumo',
						'body'      => '',
						'url'       => '/pt-br/noticias/' . $id . '/',
						'facets'    => array(),
					)
				)
			);
		}

		$seen = array();
		for ( $page = 1; $page <= 3; ++$page ) {
			$result = SearchPolicy::search_records( $rows, 'sinal', 'pt-br', array(), $page, 20 );
			$seen   = array_merge( $seen, array_column( $result['items'], 'post_id' ) );
			if ( 1 === $page ) {
				$html = SearchSurfaces::render( SearchRoutes::state_from_request( array( 'q' => 'sinal' ), 'pt-br' ), $result, array(), array(), 'pt-br', '/pt-br/busca/' );
				self::assertStringContainsString( 'aria-label="Paginação dos resultados"', $html );
				self::assertStringContainsString( 'href="/pt-br/busca/page/2/?q=sinal"', $html );
				self::assertStringContainsString( 'Mostrando 1–20 de 45', $html );
			}
		}

		self::assertCount( 45, $seen );
		self::assertCount( 45, array_unique( array_map( static fn( mixed $value ): string => is_scalar( $value ) ? (string) $value : '', $seen ) ) );
	}

	/**
	 * Builds a small project index for rendering assertions.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private static function rows(): array {
		$definitions = array(
			array( 1, 'active', 'signal-processing' ),
			array( 2, 'completed', 'signal-processing' ),
			array( 3, 'active', 'software-engineering' ),
		);
		$rows        = array();
		foreach ( $definitions as $definition ) {
			$rows[] = SearchIndex::hydrate(
				SearchIndex::build_row(
					array(
						'post_id'   => $definition[0],
						'locale'    => 'pt-br',
						'post_type' => 'lps_project',
						'title'     => 'Projeto de sinal ' . $definition[0],
						'summary'   => 'Processamento de sinal',
						'body'      => 'corpo',
						'url'       => '/pt-br/projetos/projeto-' . $definition[0] . '/',
						'facets'    => array(
							'status' => array( $definition[1] ),
							'area'   => array( $definition[2] ),
						),
					)
				)
			);
		}
		return $rows;
	}
}
