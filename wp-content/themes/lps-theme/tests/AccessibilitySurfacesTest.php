<?php
/**
 * Todo 23 accessibility contract tests for the public surfaces.
 *
 * @package LPS\Theme\Tests
 */

declare(strict_types=1);

namespace LPS\Theme\Tests;

use LPS\Theme\DiscoverySurfaces;
use LPS\Theme\PublicSurfaces;
use LPS\Theme\Shell;

require_once dirname( __DIR__ ) . '/includes/class-shell.php';
require_once dirname( __DIR__ ) . '/includes/class-publicsurfaces.php';
require_once dirname( __DIR__ ) . '/includes/class-discoverysurfaces.php';
require_once dirname( __DIR__ ) . '/includes/class-trustsurfaces.php';


/** Verifies the Todo 23 accessibility repairs in the public rendering seams. */
final class AccessibilitySurfacesTest extends \PHPUnit\Framework\TestCase {
	/** Verifies that english shell marks portuguese institutional text with a language change. */
	public function test_english_shell_marks_portuguese_institutional_text_with_a_language_change(): void {
		// Given: the English shell, whose affiliation line stays in Portuguese.
		// When: the header is rendered.
		$header = Shell::header_markup( 'en', '/en/' );

		// Then: every Portuguese fragment declares its own language.
		self::assertStringContainsString( '<p lang="pt-BR">Laboratório de Processamento de Sinais', $header );
		self::assertStringContainsString( '<p class="lps-meta" lang="pt-BR">Universidade Federal do Rio de Janeiro</p>', $header );
		self::assertStringContainsString( '<p>Signal Processing Laboratory</p>', $header );
		self::assertStringNotContainsString( '<p lang="pt-BR">Signal Processing Laboratory', $header );
	}

	/** Verifies that portuguese shell keeps its own language undeclared twice. */
	public function test_portuguese_shell_keeps_its_own_language_undeclared_twice(): void {
		// Given: the Portuguese shell, where the document language already matches.
		// When: the header is rendered.
		$header = Shell::header_markup( 'pt-br', '/pt-br/' );

		// Then: the wordmark subtitle is marked, and the document language covers the rest.
		self::assertStringContainsString( '<p lang="pt-BR">Laboratório de Processamento de Sinais', $header );
	}

	/** Verifies that authored table becomes a named keyboard reachable region. */
	public function test_authored_table_becomes_a_named_keyboard_reachable_region(): void {
		// Given: a core table block with a caption.
		$block   = array( 'blockName' => 'core/table' );
		$content = '<figure class="wp-block-table"><table><thead><tr><th scope="col">Equipamento</th></tr></thead>'
			. '<tbody><tr><th scope="row">Osciloscópio</th></tr></tbody></table>'
			. '<figcaption class="wp-element-caption">Equipamentos do laboratório</figcaption></figure>';

		// When: the shell filters the rendered block.
		$filtered = Shell::make_tables_scrollable_by_keyboard( $content, $block );

		// Then: the scroll container is focusable and named by its own caption.
		// The region lives on an inner div, not the figure: a figure that owns a
		// figcaption may not take role=region (axe aria-allowed-role).
		self::assertStringContainsString( 'tabindex="0"', $filtered );
		self::assertStringContainsString( 'role="region"', $filtered );
		self::assertStringContainsString( 'aria-label="Equipamentos do laboratório (tabela, rolagem horizontal)"', $filtered );
		self::assertStringContainsString( 'class="wp-block-table"', $filtered );
		self::assertStringContainsString( '<div class="lps-table-scroll" tabindex="0" role="region"', $filtered );
		self::assertStringContainsString( '</div><figcaption', $filtered );
		self::assertStringNotContainsString( '<figure tabindex', $filtered );
	}

	/** Verifies that table filter is idempotent and ignores other blocks. */
	public function test_table_filter_is_idempotent_and_ignores_other_blocks(): void {
		// Given: an already repaired table and an unrelated block.
		$repaired = Shell::make_tables_scrollable_by_keyboard(
			'<figure class="wp-block-table"><table><tr><th scope="col">A</th></tr></table></figure>',
			array( 'blockName' => 'core/table' )
		);

		// When: the filter runs again, and on a paragraph block.
		$again     = Shell::make_tables_scrollable_by_keyboard( $repaired, array( 'blockName' => 'core/table' ) );
		$paragraph = Shell::make_tables_scrollable_by_keyboard( '<p>Sem tabela</p>', array( 'blockName' => 'core/paragraph' ) );

		// Then: nothing is duplicated and unrelated blocks are untouched.
		self::assertSame( $repaired, $again );
		self::assertSame( 1, substr_count( $again, 'tabindex="0"' ) );
		self::assertSame( '<p>Sem tabela</p>', $paragraph );
	}

	/** Verifies that people filter options are localized. */
	public function test_people_filter_options_are_localized(): void {
		// Given: the same people listing in both locales.
		$people = array(
			array(
				'slug'   => 'pessoa-fixture',
				'name'   => 'Pessoa Fixture',
				'roles'  => array( 'researcher' ),
				'status' => 'active',
				'areas'  => array( 'signal-processing' ),
			),
		);

		// When: each locale renders its filter form.
		$portuguese = PublicSurfaces::people_listing( 'pt-br', $people, array() );
		$english    = PublicSurfaces::people_listing( 'en', $people, array() );

		// Then: no interface label stays in the other language.
		self::assertStringContainsString( '>Processamento de sinais</option>', $portuguese );
		self::assertStringContainsString( '>Engenharia de software</option>', $portuguese );
		self::assertStringNotContainsString( '>Signal processing</option>', $portuguese );
		self::assertStringContainsString( '>Signal processing</option>', $english );
	}

	/** Verifies that people listing announces its result count. */
	public function test_people_listing_announces_its_result_count(): void {
		// Given: a filter that matches one person and a filter that matches none.
		$people = array(
			array(
				'slug'   => 'pessoa-fixture',
				'name'   => 'Pessoa Fixture',
				'roles'  => array( 'researcher' ),
				'status' => 'active',
			),
		);

		// When: both listings render.
		$matched = PublicSurfaces::people_listing( 'pt-br', $people, array( 'role' => array( 'researcher' ) ) );
		$empty   = PublicSurfaces::people_listing( 'en', $people, array( 'role' => array( 'student' ) ) );

		// Then: each result is announced through a live status region.
		self::assertStringContainsString( '<p class="lps-people-count" id="lps-people-status" role="status">1 pessoa listada</p>', $matched );
		self::assertStringContainsString( 'role="status">No person matches this filter</p>', $empty );
	}

	/** Verifies that listing filters are labelled in readable language. */
	public function test_listing_filters_are_labelled_in_readable_language(): void {
		// Given: a listing with a filter whose storage key is not human readable.
		$listing = DiscoverySurfaces::render_listing(
			'publications',
			array(
				array(
					'title' => 'Registro',
					'url'   => '/pt-br/publicacoes/registro/',
				),
			),
			array( 'year' => '2026' ),
			array(
				'current'  => 1,
				'total'    => 1,
				'base_url' => '/pt-br/publicacoes/',
			),
			'pt-br'
		);

		// Then: the control is labelled, associated, and the count is announced.
		self::assertStringContainsString( '<label for="lps-listing-filter-year">Ano</label>', $listing );
		self::assertStringContainsString( 'id="lps-listing-filter-year"', $listing );
		self::assertStringContainsString( 'role="status">1 registro nesta página</p>', $listing );
	}

	/** Verifies that unmapped filter key still receives a meaningful label. */
	public function test_unmapped_filter_key_still_receives_a_meaningful_label(): void {
		// Given: an unknown filter key.
		// When: the label is resolved.
		// Then: the reader receives a word, not a bare identifier.
		self::assertSame( 'Filtro (custom)', DiscoverySurfaces::filter_label( 'custom', 'pt-br' ) );
		self::assertSame( 'Filter (custom)', DiscoverySurfaces::filter_label( 'custom', 'en' ) );
	}

	/** Verifies that a record archive is titled in the language of its route. */
	public function test_record_archive_title_follows_the_route_locale(): void {
		// Given: an opportunity archive whose post type is registered in English.
		// When: the title is localized for each locale.
		$portuguese = Shell::archive_title_for( 'Opportunities', 'lps_opportunity', 'pt-br' );
		$english    = Shell::archive_title_for( 'Opportunities', 'lps_opportunity', 'en' );
		$unknown    = Shell::archive_title_for( 'Archives: Notes', 'core_note', 'pt-br' );

		// Then: each locale receives its own label and unknown types stay untouched.
		self::assertSame( 'Oportunidades', $portuguese );
		self::assertSame( 'Opportunities', $english );
		self::assertSame( 'Archives: Notes', $unknown );
	}

	/** Verifies that core search results are localized and announced. */
	public function test_core_search_results_are_localized_and_announced(): void {
		// Given: search responses in both locales.
		// When: the heading markup is built.
		$portuguese = Shell::search_results_markup( 'sinais', 3, 'pt-br' );
		$english    = Shell::search_results_markup( 'signal', 1, 'en' );
		$empty      = Shell::search_results_markup( 'zzz', 0, 'pt-br' );

		// Then: the heading follows the locale and the count is announced.
		self::assertStringContainsString( 'Resultados da busca para: sinais', $portuguese );
		self::assertStringContainsString( '<p class="lps-result-count" role="status">3 resultados</p>', $portuguese );
		self::assertStringNotContainsString( 'Search results for', $portuguese );
		self::assertStringContainsString( 'Search results for: signal', $english );
		self::assertStringContainsString( 'role="status">1 result</p>', $english );
		self::assertStringContainsString( 'role="status">0 resultado</p>', $empty );
	}

	/** Verifies that the search filter ignores blocks outside a search response. */
	public function test_search_filter_ignores_other_blocks_and_contexts(): void {
		// Given: a paragraph block, which is never the search heading.
		// When: the filter runs.
		$html = Shell::localize_search_results( '<p>Listagem</p>', array( 'blockName' => 'core/paragraph' ) );

		// Then: the markup is unchanged.
		self::assertSame( '<p>Listagem</p>', $html );
	}

	/** Verifies that the institutional page does not duplicate the template heading. */
	public function test_institutional_page_does_not_duplicate_the_page_heading(): void {
		// Given: an institutional page whose title the page template already renders as h1.
		$page = array(
			'key'            => 'accessibility',
			'title'          => 'Acessibilidade',
			'summary'        => 'Declaração de acessibilidade.',
			'reviewed_at'    => '2026-08-01',
			'claims'         => array(),
			'report_contact' => 'acessibilidade@lps.ufrj.br',
		);

		// When: the institutional surface renders.
		$html = \LPS\Theme\TrustSurfaces::render_institutional_page( $page, 'pt-br', new \DateTimeImmutable( '2026-09-06T12:00:00+00:00' ) );

		// Then: it carries no second level-one heading and keeps an accessible name.
		self::assertStringNotContainsString( '<h1>', $html );
		self::assertStringContainsString( 'aria-label="Acessibilidade"', $html );
		self::assertStringContainsString( 'mailto:acessibilidade@lps.ufrj.br', $html );
	}

	/** Verifies that citation downloads are marked as a control cluster. */
	public function test_citation_downloads_are_a_named_control_cluster(): void {
		// Given: a publication with an identifier.
		$record = array(
			'id'         => 42,
			'title'      => 'Registro',
			'summary'    => 'Resumo.',
			'abstract'   => 'Resumo completo.',
			'record_url' => '/pt-br/publicacoes/registro/',
		);

		// When: the publication renders.
		$html = DiscoverySurfaces::render_publication( $record, array(), array(), 'pt-br' );

		// Then: the download pair is a cluster that can carry its own target size.
		self::assertStringContainsString( '<p class="lps-citation-downloads">', $html );
		self::assertStringContainsString( '>BibTeX</a>', $html );
		self::assertStringContainsString( '>CSL-JSON</a>', $html );
	}

	/** Verifies that publication pdf declares its accessible html alternative. */
	public function test_publication_pdf_declares_its_accessible_html_alternative(): void {
		// Given: a publication whose abstract is published as HTML on its own record.
		$record = array(
			'id'         => 42,
			'title'      => 'Registro de demonstração',
			'summary'    => 'Resumo editorial.',
			'abstract'   => 'Resumo completo do trabalho.',
			'pdf_url'    => 'https://example.test/fixture.pdf',
			'record_url' => '/pt-br/publicacoes/registro-de-demonstracao/',
		);

		// When: the publication renders.
		$html = DiscoverySurfaces::render_publication( $record, array(), array(), 'pt-br' );

		// Then: the PDF link declares the HTML route that carries the same content.
		self::assertStringContainsString(
			'<a href="https://example.test/fixture.pdf" data-html-alternative="/pt-br/publicacoes/registro-de-demonstracao/">PDF</a>',
			$html
		);
		self::assertStringContainsString( 'O resumo desta página é a alternativa acessível em HTML.', $html );
	}

	/** Verifies that publication without html text states the missing alternative. */
	public function test_publication_without_html_text_states_the_missing_alternative(): void {
		// Given: a publication with a PDF but no abstract or summary.
		$record = array(
			'id'         => 43,
			'title'      => 'Registro sem resumo',
			'summary'    => '',
			'abstract'   => '',
			'pdf_url'    => 'https://example.test/fixture.pdf',
			'record_url' => '/pt-br/publicacoes/registro-sem-resumo/',
		);

		// When: the publication renders.
		$html = DiscoverySurfaces::render_publication( $record, array(), array(), 'pt-br' );

		// Then: the gap is stated instead of being presented as an equivalent route.
		self::assertStringNotContainsString( 'data-html-alternative', $html );
		self::assertStringContainsString( 'Ainda não há alternativa acessível em HTML para este documento.', $html );
	}
}
