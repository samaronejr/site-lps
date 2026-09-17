<?php
/**
 * Research, project, and publication discovery contracts.
 *
 * @package LPS\Theme\Tests
 */

declare(strict_types=1);

namespace LPS\Theme\Tests;

use LPS\Theme\DiscoverySurfaces;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/includes/class-discoverysurfaces.php';

/** Contract tests for the discovery rendering library. */
final class DiscoverySurfacesTest extends TestCase {
	/** Stored block markup must render as HTML, never as visible block comments. */
	public function test_record_body_renders_html_instead_of_raw_block_markup(): void {
		// Given: a record whose body is stored as WordPress block markup.
		$record = array(
			'title'   => 'Área de teste',
			'slug'    => 'area-de-teste',
			'summary' => 'Resumo curto.',
			'body'    => '<p>Métodos estatísticos para detecção.</p>',
			'locale'  => 'pt-br',
		);

		// When: the research-area surface renders it.
		$html = DiscoverySurfaces::render_area( $record, array(), 'pt-br' );

		// Then: the paragraph is real markup and no block delimiter is shown as text.
		// The body carries the reading-serif container class of the replacement system.
		self::assertStringContainsString( '<div class="lps-body lps-reading"><p>Métodos estatísticos para detecção.</p></div>', $html );
		self::assertStringNotContainsString( '&lt;p&gt;', $html );
		self::assertStringNotContainsString( 'wp:paragraph', $html );
	}

	/** Untrusted markup in a stored body is still sanitised. */
	public function test_record_body_strips_disallowed_markup(): void {
		$record = array(
			'title'   => 'Área de teste',
			'slug'    => 'area-de-teste',
			'summary' => 'Resumo curto.',
			'body'    => '<p>Texto seguro.</p><script>alert(1)</script>',
			'locale'  => 'pt-br',
		);

		$html = DiscoverySurfaces::render_area( $record, array(), 'pt-br' );

		self::assertStringContainsString( '<p>Texto seguro.</p>', $html );
		self::assertStringNotContainsString( '<script', $html );
	}

	/**
	 * Provides date values, precisions, locales, and honest renderings.
	 *
	 * @return array<string, array{string, string, string, string}>
	 */
	public static function dates(): array {
		return array(
			'unknown Portuguese' => array( '', 'unknown', 'pt-br', 'Data não informada' ),
			'unknown English'    => array( '', 'unknown', 'en', 'Date not provided' ),
			'year'               => array( '2024-01-01', 'year', 'en', '2024' ),
			'month Portuguese'   => array( '2024-03-01', 'month', 'pt-br', 'março de 2024' ),
			'full English'       => array( '2024-03-09', 'day', 'en', '9 March 2024' ),
		);
	}

	/**
	 * Dates preserve known precision and name unknown values.
	 *
	 * @param string $date      Data-provider value.
	 * @param string $precision Data-provider value.
	 * @param string $locale    Data-provider value.
	 * @param string $expected  Data-provider value.
	 */
	#[DataProvider( 'dates' )]
	public function test_dates_preserve_known_precision_and_name_unknown_values( string $date, string $precision, string $locale, string $expected ): void {
		self::assertSame( $expected, DiscoverySurfaces::format_date( $date, $precision, $locale ) );
	}

	/**
	 * Project template puts plain summary before technical body and derives relationships.
	 */
	public function test_project_template_puts_plain_summary_before_technical_body_and_derives_relationships(): void {
		$record        = array(
			'title'      => 'Monitoramento de sinais',
			'summary'    => 'Explicação para leitores não especialistas.',
			'body'       => '<p>Descrição técnica.</p>',
			'status'     => 'current',
			'start_date' => '2022-01-01',
			'end_date'   => '',
		);
		$relationships = array(
			'members'      => array(
				array(
					'name'     => 'Ana LPS',
					'url'      => '/pt-br/pessoas/ana/',
					'role'     => 'lead',
					'archived' => false,
				),
			),
			'funders'      => array(
				array(
					'name' => 'CNPq',
					'url'  => 'https://example.test/cnpq/',
				),
				array(
					'name' => 'FAPERJ',
					'url'  => 'https://example.test/faperj/',
				),
			),
			'publications' => array(
				array(
					'title' => 'Título autoritativo',
					'url'   => '/pt-br/publicacoes/titulo/',
				),
			),
		);

		$html = DiscoverySurfaces::render_project( $record, $relationships, 'pt-br' );

		self::assertLessThan( strpos( $html, 'Descrição técnica.' ), strpos( $html, 'Explicação para leitores não especialistas.' ) );
		self::assertStringContainsString( 'Projeto em andamento', $html );
		self::assertStringContainsString( 'CNPq', $html );
		self::assertStringContainsString( 'FAPERJ', $html );
		self::assertStringContainsString( '/pt-br/pessoas/ana/', $html );
		self::assertStringContainsString( '/pt-br/publicacoes/titulo/', $html );
	}

	/**
	 * Completed project and one funder are rendered without invented impact.
	 */
	public function test_completed_project_and_one_funder_are_rendered_without_invented_impact(): void {
		$html = DiscoverySurfaces::render_project(
			array(
				'title'      => 'Projeto concluído',
				'summary'    => 'Resumo.',
				'body'       => '',
				'status'     => 'completed',
				'start_date' => '2020-01-01',
				'end_date'   => '2023-12-31',
			),
			array(
				'members'      => array(),
				'funders'      => array(
					array(
						'name' => 'Finep',
						'url'  => '',
					),
				),
				'publications' => array(),
			),
			'pt-br'
		);
		self::assertStringContainsString( 'Projeto concluído', $html );
		self::assertStringContainsString( 'Finep', $html );
		self::assertStringNotContainsString( 'impacto', strtolower( $html ) );
	}

	/**
	 * Publication preserves authoritative title author order and link distinctions.
	 */
	public function test_publication_preserves_authoritative_title_author_order_and_link_distinctions(): void {
		$record  = array(
			'id'              => 77,
			'title'           => 'Do NOT Rewrite: Σ Signals',
			'summary'         => 'Plain-language account.',
			'abstract'        => '<p>Technical abstract.</p>',
			'type'            => 'journal-article',
			'status'          => 'final',
			'date'            => '2025-01-01',
			'date_precision'  => 'year',
			'doi'             => '10.5555/lps.77',
			'canonical_url'   => 'https://publisher.test/article',
			'open_access_url' => 'https://repository.test/article',
			'pdf_url'         => 'https://repository.test/article.pdf',
			'code_url'        => 'https://code.test/repo',
			'data_url'        => 'https://data.test/set',
		);
		$authors = array(
			array(
				'kind'  => 'internal',
				'name'  => 'Zoe First',
				'url'   => '/en/people/zoe/',
				'orcid' => '0000-0002-1825-0097',
			),
			array(
				'kind'  => 'external',
				'name'  => 'Ada Second',
				'url'   => '',
				'orcid' => '',
			),
			array(
				'kind'  => 'collective',
				'name'  => 'LPS Collaboration',
				'url'   => '',
				'orcid' => '',
			),
		);

		$html = DiscoverySurfaces::render_publication( $record, $authors, array(), 'en' );

		self::assertStringContainsString( 'Do NOT Rewrite: Σ Signals', $html );
		self::assertLessThan( strpos( $html, 'Technical abstract.' ), strpos( $html, 'Plain-language account.' ) );
		self::assertLessThan( strpos( $html, 'Ada Second' ), strpos( $html, 'Zoe First' ) );
		self::assertLessThan( strpos( $html, 'LPS Collaboration' ), strpos( $html, 'Ada Second' ) );
		self::assertStringContainsString( 'https://doi.org/10.5555/lps.77', $html );
		self::assertStringContainsString( 'https://orcid.org/0000-0002-1825-0097', $html );
		self::assertStringContainsString( 'Canonical record', $html );
		self::assertStringContainsString( 'Open-access copy', $html );
		self::assertStringContainsString( 'PDF', $html );
		self::assertStringContainsString( 'Code', $html );
		self::assertStringContainsString( 'Data', $html );
		self::assertStringContainsString( '?lps_citation=bibtex', $html );
		self::assertStringContainsString( '?lps_citation=csl-json', $html );
	}

	/**
	 * Missing identifiers and unavailable pdf are truthful non links.
	 */
	public function test_missing_identifiers_and_unavailable_pdf_are_truthful_non_links(): void {
		$html = DiscoverySurfaces::render_publication(
			array(
				'id'              => 4,
				'title'           => 'Record without IDs',
				'summary'         => 'Summary.',
				'abstract'        => '',
				'type'            => 'report',
				'status'          => 'final',
				'date'            => '',
				'date_precision'  => 'unknown',
				'doi'             => '',
				'canonical_url'   => '',
				'open_access_url' => '',
				'pdf_url'         => '',
				'code_url'        => '',
				'data_url'        => '',
			),
			array(
				array(
					'kind'  => 'external',
					'name'  => 'Known Author',
					'url'   => '',
					'orcid' => '',
				),
			),
			array(),
			'en'
		);
		self::assertStringContainsString( 'Date not provided', $html );
		self::assertStringContainsString( 'DOI not assigned', $html );
		self::assertStringContainsString( 'PDF unavailable', $html );
		self::assertStringNotContainsString( 'doi.org/', $html );
	}

	/**
	 * Preprint final and reverse relationships are labeled from canonical rows.
	 */
	public function test_preprint_final_and_reverse_relationships_are_labeled_from_canonical_rows(): void {
		$html = DiscoverySurfaces::render_publication(
			array(
				'id'              => 5,
				'title'           => 'Preprint',
				'summary'         => 'Summary.',
				'abstract'        => '',
				'type'            => 'preprint',
				'status'          => 'preprint',
				'date'            => '2024-04-01',
				'date_precision'  => 'month',
				'doi'             => '',
				'canonical_url'   => '',
				'open_access_url' => '',
				'pdf_url'         => '',
				'code_url'        => '',
				'data_url'        => '',
			),
			array(
				array(
					'kind'  => 'external',
					'name'  => 'Author',
					'url'   => '',
					'orcid' => '',
				),
			),
			array(
				array(
					'title' => 'Final version',
					'url'   => '/en/publications/final/',
					'role'  => 'preprint-of',
				),
				array(
					'title' => 'Derived project',
					'url'   => '/en/projects/project/',
					'role'  => 'output-of',
				),
			),
			'en'
		);
		self::assertStringContainsString( 'Final version', $html );
		self::assertStringContainsString( 'Preprint of', $html );
		self::assertStringContainsString( 'Derived project', $html );
	}

	/**
	 * Bibtex and csl json preserve all 3000 authors in authoritative order.
	 */
	public function test_bibtex_and_csl_json_preserve_all_3000_authors_in_authoritative_order(): void {
		$authors = array();
		for ( $index = 1; $index <= 3000; ++$index ) {
			$authors[] = array(
				'kind'  => 'external',
				'name'  => sprintf( 'Author %04d', $index ),
				'url'   => '',
				'orcid' => '',
			);
		}
		$record = array(
			'id'             => 9000,
			'title'          => 'Large collaboration',
			'type'           => 'journal-article',
			'date'           => '2026-01-01',
			'date_precision' => 'year',
			'doi'            => '',
			'venue'          => 'Journal',
		);

		$bibtex = DiscoverySurfaces::bibtex( $record, $authors );
		$csl    = json_decode( DiscoverySurfaces::csl_json( $record, $authors ), true, 512, JSON_THROW_ON_ERROR );
		self::assertIsArray( $csl );
		self::assertArrayHasKey( 'author', $csl );
		$csl_authors = $csl['author'];
		self::assertIsArray( $csl_authors );
		self::assertCount( 3000, $csl_authors );
		self::assertIsArray( $csl_authors[0] );
		self::assertIsArray( $csl_authors[2999] );
		self::assertSame( 'Author 0001', $csl_authors[0]['literal'] );
		self::assertSame( 'Author 3000', $csl_authors[2999]['literal'] );
		self::assertLessThan( strpos( $bibtex, 'Author 3000' ), strpos( $bibtex, 'Author 0001' ) );
		self::assertSame( 3000, substr_count( $bibtex, 'Author ' ) );
	}

	/**
	 * Server rendered listing has get filters pagination and accessible names.
	 */
	public function test_server_rendered_listing_has_get_filters_pagination_and_accessible_names(): void {
		$html = DiscoverySurfaces::render_listing(
			'projects',
			array(
				array(
					'title'   => 'Projeto A',
					'url'     => '/pt-br/projetos/a/',
					'summary' => 'Resumo A',
					'meta'    => '2024 — atual',
				),
			),
			array( 'status' => 'current' ),
			array(
				'current'  => 2,
				'total'    => 4,
				'base_url' => '/pt-br/projetos/',
			),
			'pt-br'
		);
		self::assertStringContainsString( '<form', $html );
		self::assertStringContainsString( 'method="get"', $html );
		self::assertStringContainsString( '<label', $html );
		self::assertStringContainsString( 'aria-label="Paginação de projetos"', $html );
		// `paged` is the archive query var WordPress paginates on; the earlier
		// `page=` links were inert on archive routes.
		self::assertStringContainsString( 'paged=1', $html );
		self::assertStringContainsString( 'paged=3', $html );
		self::assertStringNotContainsString( '<script', $html );
	}

	/**
	 * Active filters render as labeled controls and follow pagination links.
	 */
	public function test_active_filters_render_controls_and_follow_pagination(): void {
		$html = DiscoverySurfaces::render_listing(
			'projects',
			array(
				array(
					'title'   => 'Projeto A',
					'url'     => '/pt-br/projetos/a/',
					'summary' => 'Resumo A',
					'meta'    => 'Projeto em andamento',
				),
			),
			array(
				'q'      => 'sinais',
				'status' => array(
					'value'   => 'active',
					'options' => DiscoverySurfaces::filter_options( 'status', 'pt-br' ),
				),
			),
			array(
				'current'  => 1,
				'total'    => 3,
				'base_url' => '/pt-br/projetos/',
			),
			'pt-br'
		);

		// Then: the closed vocabulary is a select, the term is a search input,
		// a clear link exists, and every page link carries the active filters.
		self::assertStringContainsString( '<select id="lps-listing-filter-status" name="status">', $html );
		self::assertStringContainsString( '<option value="active" selected>Em andamento</option>', $html );
		self::assertStringContainsString( 'type="search" id="lps-listing-filter-q" name="q" value="sinais"', $html );
		self::assertStringContainsString( 'Limpar filtros', $html );
		self::assertStringContainsString( 'q=sinais&amp;status=active&amp;paged=2', $html );
		self::assertStringContainsString( 'role="status"', $html );
	}

	/**
	 * A publication record leads with its metadata line.
	 */
	public function test_publication_leads_with_date_venue_and_type_metadata(): void {
		$html = DiscoverySurfaces::render_publication(
			array(
				'id'             => 12,
				'title'          => 'Artigo',
				'summary'        => 'Resumo.',
				'abstract'       => '',
				'date'           => '2025-03-09',
				'date_precision' => 'day',
				'venue'          => 'Periódico de fixture',
				'type'           => 'journal-article',
			),
			array(),
			array(),
			'pt-br'
		);

		self::assertStringContainsString( '<time datetime="2025-03-09">9 de março de 2025</time>', $html );
		self::assertStringContainsString( 'Periódico de fixture', $html );
		self::assertStringContainsString( 'journal-article', $html );
		self::assertLessThan( strpos( $html, 'lps-summary' ), strpos( $html, 'lps-meta' ) );
		self::assertStringContainsString( 'Acesso e downloads', $html );
		self::assertStringContainsString( 'class="lps-access"', $html );
	}

	/**
	 * A project record announces its status and recorded date range.
	 */
	public function test_project_announces_status_and_recorded_date_range(): void {
		$html = DiscoverySurfaces::render_project(
			array(
				'title'      => 'Projeto',
				'summary'    => 'Resumo.',
				'status'     => 'active',
				'start_date' => '2024-01-15',
				'end_date'   => '',
			),
			array(),
			'pt-br'
		);

		self::assertStringContainsString( 'lps-status-success', $html );
		self::assertStringContainsString( 'Projeto em andamento', $html );
		self::assertStringContainsString( 'Iniciado em 15 de janeiro de 2024', $html );
	}

	/**
	 * CSL-JSON carries the venue as the container title.
	 */
	public function test_csl_json_carries_the_venue_as_container_title(): void {
		$csl = json_decode(
			DiscoverySurfaces::csl_json(
				array(
					'id'    => 7,
					'title' => 'Paper',
					'venue' => 'Journal of Fixtures',
				),
				array()
			),
			true,
			512,
			JSON_THROW_ON_ERROR
		);

		self::assertIsArray( $csl );
		self::assertSame( 'Journal of Fixtures', $csl['container-title'] );
	}

	/**
	 * Bibtex records use real newlines between every field.
	 */
	public function test_bibtex_records_use_real_newlines_between_every_field(): void {
		$bibtex = DiscoverySurfaces::bibtex(
			array(
				'id'    => 77,
				'title' => 'Sigma Signals',
				'date'  => '2025-01-01',
				'doi'   => '10.5555/lps.77',
				'venue' => 'Journal',
			),
			array( array( 'name' => 'Zoe First' ), array( 'name' => 'Ada Second' ) )
		);

		self::assertStringNotContainsString( '\\n', $bibtex );
		self::assertSame( 7, substr_count( $bibtex, "\n" ) );
		self::assertStringStartsWith( "@article{lps77,\n", $bibtex );
		self::assertStringEndsWith( "}\n", $bibtex );
	}

	/**
	 * Year only value is not expanded into a fabricated full date.
	 */
	public function test_year_only_value_is_not_expanded_into_a_fabricated_full_date(): void {
		self::assertSame( '2024', DiscoverySurfaces::format_date( '2024', 'day', 'en' ) );
		self::assertSame( '2024', DiscoverySurfaces::format_date( '2024', 'month', 'pt-br' ) );
		self::assertSame( 'May 2024', DiscoverySurfaces::format_date( '2024-05', 'day', 'en' ) );
	}

	/**
	 * Impossible calendar dates are never normalized into facts.
	 */
	public function test_impossible_calendar_dates_are_never_normalized_into_facts(): void {
		self::assertSame( 'Date not provided', DiscoverySurfaces::format_date( '2024-02-31', 'day', 'en' ) );
		self::assertSame( 'Data não informada', DiscoverySurfaces::format_date( '2024-13-01', 'month', 'pt-br' ) );
		self::assertSame( 'Date not provided', DiscoverySurfaces::format_date( 'not-a-date', 'day', 'en' ) );
	}

	/**
	 * Month precision keeps the month and year only.
	 */
	public function test_month_precision_keeps_the_month_and_year_only(): void {
		self::assertSame( 'May 2024', DiscoverySurfaces::format_date( '2024-05-17', 'month', 'en' ) );
		self::assertSame( 'maio de 2024', DiscoverySurfaces::format_date( '2024-05-17', 'month', 'pt-br' ) );
	}

	/**
	 * Archived member is labelled and never rendered as a live link.
	 */
	public function test_archived_member_is_labelled_and_never_rendered_as_a_live_link(): void {
		$relationships = array(
			'members' => array(
				array(
					'name'     => 'Ana Archived',
					'url'      => '/pt-br/pessoas/ana/',
					'archived' => true,
				),
				array(
					'name'     => 'Beto Active',
					'url'      => '/pt-br/pessoas/beto/',
					'archived' => false,
				),
			),
		);

		$portuguese = DiscoverySurfaces::render_project(
			array(
				'title'   => 'Projeto',
				'summary' => 'Resumo.',
			),
			$relationships,
			'pt-br'
		);
		$english    = DiscoverySurfaces::render_project(
			array(
				'title'   => 'Project',
				'summary' => 'Summary.',
			),
			$relationships,
			'en'
		);

		self::assertStringNotContainsString( '<a href="/pt-br/pessoas/ana/"', $portuguese );
		self::assertStringContainsString( 'Ana Archived', $portuguese );
		self::assertStringContainsString( 'Integrante arquivado', $portuguese );
		self::assertStringContainsString( '<a href="/pt-br/pessoas/beto/"', $portuguese );
		self::assertStringContainsString( 'Archived member', $english );
	}

	/**
	 * Untrusted abstract and body are escaped.
	 */
	public function test_untrusted_abstract_and_body_are_escaped(): void {
		$publication = DiscoverySurfaces::render_publication(
			array(
				'id'       => 8,
				'title'    => 'Escaped',
				'summary'  => 'Summary.',
				'abstract' => '<script>alert(1)</script>',
			),
			array(),
			array(),
			'en'
		);
		$project     = DiscoverySurfaces::render_project(
			array(
				'title'   => 'Escaped',
				'summary' => 'Summary.',
				'body'    => '<script>alert(2)</script>',
			),
			array(),
			'pt-br'
		);

		// The abstract is plain text and stays escaped; the editorial body is rendered
		// markup, so untrusted tags are removed instead of shown. Neither may execute.
		self::assertStringNotContainsString( '<script', $publication );
		self::assertStringNotContainsString( '<script', $project );
		self::assertStringContainsString( '&lt;script&gt;', $publication );
		self::assertStringNotContainsString( '</script>', $project );
		self::assertStringNotContainsString( 'javascript:', $project );
	}

	/**
	 * Executable url schemes are rejected everywhere.
	 */
	public function test_executable_url_schemes_are_rejected_everywhere(): void {
		$publication = DiscoverySurfaces::render_publication(
			array(
				'id'            => 9,
				'title'         => 'Hostile',
				'summary'       => 'Summary.',
				'canonical_url' => 'javascript:alert(3)',
			),
			array(
				array(
					'name' => 'Hostile Author',
					'url'  => 'javascript:alert(4)',
				),
			),
			array(
				array(
					'title' => 'Hostile relation',
					'url'   => 'JavaScript:alert(5)',
					'role'  => 'output-of',
				),
			),
			'en'
		);
		$project     = DiscoverySurfaces::render_project(
			array(
				'title'   => 'Hostile',
				'summary' => 'Summary.',
			),
			array(
				'members' => array(
					array(
						'name' => 'Hostile Member',
						'url'  => 'data:text/html;base64,PHNjcmlwdD4=',
					),
				),
			),
			'en'
		);
		$listing     = DiscoverySurfaces::render_listing(
			'projects',
			array(
				array(
					'title' => 'Hostile item',
					'url'   => 'javascript:alert(6)',
				),
			),
			array(),
			array(
				'current'  => 1,
				'total'    => 1,
				'base_url' => '',
			),
			'en'
		);

		foreach ( array( $publication, $project, $listing ) as $markup ) {
			self::assertStringNotContainsString( 'javascript:', strtolower( $markup ) );
			self::assertStringNotContainsString( 'data:text/html', strtolower( $markup ) );
		}
		self::assertStringContainsString( 'Hostile Author', $publication );
		self::assertStringContainsString( 'Hostile Member', $project );
		self::assertStringContainsString( 'Hostile item', $listing );
	}

	/**
	 * Research area page lists related records without inventing claims.
	 */
	public function test_research_area_page_lists_related_records_without_inventing_claims(): void {
		$record   = array(
			'title'   => 'Processamento de sinais',
			'summary' => 'Explicação simples.',
			'body'    => '<b>Escopo</b>',
		);
		$projects = array(
			array(
				'title'   => 'Projeto A',
				'url'     => '/pt-br/projetos/a/',
				'summary' => 'Resumo A',
			),
		);

		$html = DiscoverySurfaces::render_area( $record, $projects, 'pt-br' );

		self::assertStringContainsString( 'lps-research-area', $html );
		self::assertStringContainsString( 'Processamento de sinais', $html );
		self::assertLessThan( strpos( $html, 'Escopo' ), strpos( $html, 'Explicação simples.' ) );
		self::assertStringContainsString( '/pt-br/projetos/a/', $html );
		self::assertStringNotContainsString( '<b>', $html );
	}

	/**
	 * Research area without related records states the empty case.
	 */
	public function test_research_area_without_related_records_states_the_empty_case(): void {
		$portuguese = DiscoverySurfaces::render_area( array( 'title' => 'Área' ), array(), 'pt-br' );
		$english    = DiscoverySurfaces::render_area( array( 'title' => 'Area' ), array(), 'en' );

		self::assertStringContainsString( 'Nenhum projeto publicado nesta área', $portuguese );
		self::assertStringContainsString( 'No published projects in this area', $english );
	}

	/**
	 * Research listing names its own pagination in both locales.
	 */
	public function test_research_listing_names_its_own_pagination_in_both_locales(): void {
		$pagination = array(
			'current'  => 1,
			'total'    => 2,
			'base_url' => '/pt-br/pesquisa/',
		);

		$portuguese = DiscoverySurfaces::render_listing( 'research', array(), array(), $pagination, 'pt-br' );
		$english    = DiscoverySurfaces::render_listing( 'research', array(), array(), $pagination, 'en' );

		self::assertStringContainsString( 'aria-label="Paginação de áreas de pesquisa"', $portuguese );
		self::assertStringContainsString( 'aria-label="Research areas pagination"', $english );
	}
}
