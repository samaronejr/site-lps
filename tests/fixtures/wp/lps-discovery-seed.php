<?php
/**
 * Local-only discovery fixtures seeded through the plugin's own write path.
 *
 * The REST route refuses record creation because the content model maps custom
 * capabilities per record type. This seeder therefore uses the same PHP entry
 * points the plugin itself uses (wp_insert_post plus the Relationships API), so
 * the capability mapping stays untouched and every publish gate still applies.
 *
 * @package LPS\Development
 */

declare(strict_types=1);

use LPS\ContentModel\Relationships;
use LPS\ContentModel\Translations;

const LPS_DISCOVERY_SEED_VERSION = '2';
const LPS_DISCOVERY_SEED_OPTION  = 'lps_discovery_seed_version';

/**
 * Publishes one bilingual record pair and returns both database IDs.
 *
 * @param string               $post_type Record type.
 * @param array<string, mixed> $source    Portuguese authority record.
 * @param array<string, mixed> $target    English variant record.
 * @return array{pt-br: int, en: int}
 */
function lps_seed_pair( string $post_type, array $source, array $target ): array {
	$portuguese = wp_insert_post(
		array(
			'post_type'    => $post_type,
			'post_status'  => 'draft',
			'post_title'   => $source['title'],
			'post_name'    => $source['slug'],
			'post_excerpt' => $source['summary'],
			'post_content' => $source['body'],
			'meta_input'   => array_merge( array( '_lps_locale' => 'pt-br' ), $source['meta'] ?? array() ),
		),
		true
	);
	$english    = wp_insert_post(
		array(
			'post_type'    => $post_type,
			'post_status'  => 'draft',
			'post_title'   => $target['title'],
			'post_name'    => $target['slug'],
			'post_excerpt' => $target['summary'],
			'post_content' => $target['body'],
			'meta_input'   => array_merge( array( '_lps_locale' => 'en' ), $target['meta'] ?? array() ),
		),
		true
	);
	if ( is_wp_error( $portuguese ) || is_wp_error( $english ) ) {
		return array(
			'pt-br' => 0,
			'en'    => 0,
		);
	}
	if ( is_wp_error( Translations::associate( $portuguese, $english ) ) ) {
		// An unassociated pair can never satisfy the bilingual publish gate, so the
		// half-built records are removed instead of lingering as unpublishable drafts.
		wp_delete_post( $portuguese, true );
		wp_delete_post( $english, true );
		return array(
			'pt-br' => 0,
			'en'    => 0,
		);
	}
	return array(
		'pt-br' => $portuguese,
		'en'    => $english,
	);
}

/**
 * Publishes an already-linked bilingual pair in dependency order.
 *
 * @param array{pt-br: int, en: int} $pair Linked record pair.
 */
function lps_seed_publish( array $pair ): void {
	if ( 0 === $pair['pt-br'] || 0 === $pair['en'] ) {
		return;
	}
	update_post_meta( $pair['en'], '_lps_reviewed_source_hash', (string) get_post_meta( $pair['en'], '_lps_source_hash', true ) );
	wp_update_post(
		array(
			'ID'          => $pair['en'],
			'post_status' => 'publish',
		)
	);
	wp_update_post(
		array(
			'ID'          => $pair['pt-br'],
			'post_status' => 'publish',
		)
	);
}

/** Returns whether both supported languages are registered and usable. */
function lps_discovery_seed_languages_ready(): bool {
	if ( ! function_exists( 'pll_languages_list' ) || ! function_exists( 'pll_save_post_translations' ) ) {
		return false;
	}
	$languages = pll_languages_list();
	if ( ! is_array( $languages ) ) {
		return false;
	}
	return in_array( 'pt-br', $languages, true ) && in_array( 'en', $languages, true );
}

/** Seeds the discovery fixtures exactly once per environment. */
function lps_seed_discovery_fixtures(): void {
	if ( LPS_DISCOVERY_SEED_VERSION === get_option( LPS_DISCOVERY_SEED_OPTION ) ) {
		return;
	}
	if ( ! class_exists( Relationships::class ) || ! class_exists( Translations::class ) ) {
		return;
	}
	if ( ! lps_discovery_seed_languages_ready() ) {
		// Polylang registers its languages during this same boot; seeding before the
		// association back end is usable would leave unlinked, unpublishable drafts.
		return;
	}

	$report = array();

	$area = lps_seed_pair(
		'lps_research_area',
		array(
			'title'   => 'Processamento de sinais (fixture de QA)',
			'slug'    => 'processamento-de-sinais-fixture',
			'summary' => 'Registro de teste local usado para validar as rotas de descoberta.',
			'body'    => 'Conteúdo de fixture. Não representa uma afirmação institucional.',
			'meta'    => array(
				'_lps_stable_key' => 'qa-signal-processing',
				'_lps_label'      => 'Processamento de sinais (fixture de QA)',
				'_lps_sort_order' => 1,
			),
		),
		array(
			'title'   => 'Signal processing (QA fixture)',
			'slug'    => 'signal-processing-fixture',
			'summary' => 'Local test record used to validate the discovery routes.',
			'body'    => 'Fixture content. It states no institutional claim.',
			'meta'    => array( '_lps_label' => 'Signal processing (QA fixture)' ),
		)
	);
	lps_seed_publish( $area );
	$report['area'] = $area;

	$lead = lps_seed_pair(
		'lps_person',
		array(
			'title'   => 'Pessoa Ativa (fixture de QA)',
			'slug'    => 'pessoa-ativa-fixture',
			'summary' => 'Perfil de teste local.',
			'body'    => 'Perfil de fixture usado apenas no ambiente de desenvolvimento.',
			'meta'    => array(
				'_lps_canonical_name' => 'Pessoa Ativa (fixture de QA)',
				'_lps_person_status'  => 'active',
				'_lps_orcid'          => '0000-0002-1825-0097',
			),
		),
		array(
			'title'   => 'Active Person (QA fixture)',
			'slug'    => 'active-person-fixture',
			'summary' => 'Local test profile.',
			'body'    => 'Fixture profile used only in the development environment.',
		)
	);
	lps_seed_publish( $lead );
	$report['lead'] = $lead;

	$archived = lps_seed_pair(
		'lps_person',
		array(
			'title'   => 'Pessoa Arquivada (fixture de QA)',
			'slug'    => 'pessoa-arquivada-fixture',
			'summary' => 'Perfil de teste local arquivado.',
			'body'    => 'Perfil de fixture arquivado.',
			'meta'    => array(
				'_lps_canonical_name' => 'Pessoa Arquivada (fixture de QA)',
				'_lps_person_status'  => 'alumni',
			),
		),
		array(
			'title'   => 'Archived Person (QA fixture)',
			'slug'    => 'archived-person-fixture',
			'summary' => 'Archived local test profile.',
			'body'    => 'Archived fixture profile.',
		)
	);
	lps_seed_publish( $archived );
	update_post_meta( $archived['pt-br'], '_lps_state', 'archived' );
	update_post_meta( $archived['en'], '_lps_state', 'archived' );
	$report['archived'] = $archived;

	$project = lps_seed_pair(
		'lps_project',
		array(
			'title'   => 'Projeto de demonstração (fixture de QA)',
			'slug'    => 'projeto-demonstracao-fixture',
			'summary' => 'Resumo em linguagem simples do projeto de fixture.',
			'body'    => 'Descrição técnica de fixture, exibida depois do resumo.',
			'meta'    => array(
				'_lps_project_status' => 'current',
				'_lps_start_date'     => '2024-01-15',
			),
		),
		array(
			'title'   => 'Demonstration project (QA fixture)',
			'slug'    => 'demonstration-project-fixture',
			'summary' => 'Plain-language summary of the fixture project.',
			'body'    => 'Fixture technical description, rendered after the summary.',
		)
	);
	if ( 0 !== $project['pt-br'] ) {
		Relationships::replace(
			$project['pt-br'],
			'project_member',
			array(
				array(
					'target_post_id'    => $lead['pt-br'],
					'relationship_role' => 'lead',
					'sort_order'        => 1,
				),
				array(
					'target_post_id'    => $archived['pt-br'],
					'relationship_role' => 'member',
					'sort_order'        => 2,
				),
			)
		);
		Relationships::replace(
			$project['pt-br'],
			'research_area',
			array(
				array(
					'target_post_id'    => $area['pt-br'],
					'relationship_role' => 'primary',
					'sort_order'        => 1,
				),
			)
		);
	}
	lps_seed_publish( $project );
	$report['project'] = $project;

	$publication = lps_seed_pair(
		'lps_publication',
		array(
			'title'   => 'Publicação de demonstração (fixture de QA)',
			'slug'    => 'publicacao-demonstracao-fixture',
			'summary' => 'Resumo em linguagem simples da publicação de fixture.',
			'body'    => 'Resumo técnico de fixture.',
			'meta'    => array(
				'_lps_publication_type'    => 'journal-article',
				'_lps_publication_status'  => 'final',
				'_lps_authoritative_title' => 'Publicação de demonstração (fixture de QA)',
				'_lps_language'            => 'pt-BR',
				'_lps_publication_date'    => '2025-03-09',
				'_lps_date_precision'      => 'day',
				'_lps_venue'               => 'Periódico de fixture',
				'_lps_canonical_url'       => 'https://example.test/fixture-record',
				'_lps_open_access_url'     => 'https://example.test/fixture-open-access',
				'_lps_pdf_url'             => 'https://example.test/fixture.pdf',
				'_lps_code_url'            => 'https://example.test/fixture-code',
				'_lps_data_url'            => 'https://example.test/fixture-data',
			),
		),
		array(
			'title'   => 'Demonstration publication (QA fixture)',
			'slug'    => 'demonstration-publication-fixture',
			'summary' => 'Plain-language summary of the fixture publication.',
			'body'    => 'Fixture technical abstract.',
		)
	);
	if ( 0 !== $publication['pt-br'] ) {
		Relationships::set_doi( $publication['pt-br'], '10.5555/lps.qa.fixture' );
		Relationships::replace_authors(
			$publication['pt-br'],
			array(
				array(
					'author_kind'    => 'internal',
					'author_post_id' => $lead['pt-br'],
					'sort_order'     => 1,
				),
				array(
					'author_kind'  => 'external',
					'display_name' => 'External Fixture Author',
					'sort_order'   => 2,
				),
			)
		);
		Relationships::replace(
			$publication['pt-br'],
			'publication_project',
			array(
				array(
					'target_post_id'    => $project['pt-br'],
					'relationship_role' => 'output-of',
					'sort_order'        => 1,
				),
			)
		);
		Relationships::replace(
			$publication['pt-br'],
			'research_area',
			array(
				array(
					'target_post_id'    => $area['pt-br'],
					'relationship_role' => 'primary',
					'sort_order'        => 1,
				),
			)
		);
	}
	lps_seed_publish( $publication );
	$report['publication'] = $publication;

	$sparse = lps_seed_pair(
		'lps_publication',
		array(
			'title'   => 'Registro sem identificadores (fixture de QA)',
			'slug'    => 'registro-sem-identificadores-fixture',
			'summary' => 'Registro de fixture sem DOI, sem data e sem PDF.',
			'body'    => 'Resumo técnico de fixture.',
			'meta'    => array(
				'_lps_publication_type'    => 'report',
				'_lps_publication_status'  => 'final',
				'_lps_authoritative_title' => 'Registro sem identificadores (fixture de QA)',
				'_lps_language'            => 'pt-BR',
				'_lps_date_precision'      => 'unknown',
			),
		),
		array(
			'title'   => 'Record without identifiers (QA fixture)',
			'slug'    => 'record-without-identifiers-fixture',
			'summary' => 'Fixture record with no DOI, no date, and no PDF.',
			'body'    => 'Fixture technical abstract.',
		)
	);
	if ( 0 !== $sparse['pt-br'] ) {
		Relationships::replace_authors(
			$sparse['pt-br'],
			array(
				array(
					'author_kind'  => 'external',
					'display_name' => 'Solo Fixture Author',
					'sort_order'   => 1,
				),
			)
		);
	}
	lps_seed_publish( $sparse );
	$report['sparse'] = $sparse;

	$collective = lps_seed_pair(
		'lps_publication',
		array(
			'title'   => 'Colaboração de 3000 autores (fixture de QA)',
			'slug'    => 'colaboracao-3000-autores-fixture',
			'summary' => 'Registro de fixture com 3000 autores para exportação completa.',
			'body'    => 'Resumo técnico de fixture.',
			'meta'    => array(
				'_lps_publication_type'    => 'journal-article',
				'_lps_publication_status'  => 'final',
				'_lps_authoritative_title' => 'Colaboração de 3000 autores (fixture de QA)',
				'_lps_language'            => 'pt-BR',
				'_lps_publication_date'    => '2026',
				'_lps_date_precision'      => 'year',
				'_lps_venue'               => 'Periódico de fixture',
			),
		),
		array(
			'title'   => '3000-author collaboration (QA fixture)',
			'slug'    => 'three-thousand-author-collaboration-fixture',
			'summary' => 'Fixture record carrying 3000 authors for complete exports.',
			'body'    => 'Fixture technical abstract.',
		)
	);
	if ( 0 !== $collective['pt-br'] ) {
		$authors = array();
		for ( $index = 1; $index <= 3000; $index++ ) {
			$authors[] = array(
				'author_kind'  => 'external',
				'display_name' => sprintf( 'Author %04d', $index ),
				'sort_order'   => $index,
			);
		}
		Relationships::replace_authors( $collective['pt-br'], $authors );
	}
	lps_seed_publish( $collective );
	$report['collective'] = $collective;

	update_option( 'lps_discovery_seed_report', $report );
	update_option( LPS_DISCOVERY_SEED_OPTION, LPS_DISCOVERY_SEED_VERSION );
}

add_action(
	'init',
	static function (): void {
		if ( '/%postname%/' !== get_option( 'permalink_structure' ) ) {
			update_option( 'permalink_structure', '/%postname%/' );
			flush_rewrite_rules( false );
		}
		$rules = get_option( 'rewrite_rules' );
		if ( ! is_array( $rules ) || ! isset( $rules['pt-br/projetos/?$'] ) ) {
			flush_rewrite_rules( false );
		}
		lps_seed_discovery_fixtures();
	},
	100
);
