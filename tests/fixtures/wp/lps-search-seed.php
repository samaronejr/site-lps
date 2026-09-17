<?php
/**
 * Local-only search route pages and index warm-up.
 *
 * The two search pages are ordinary published pages so the frozen locale
 * rewrite rules resolve to a real template; the index rebuild only replays the
 * plugin's own synchronization path over already-published records.
 *
 * @package LPS\Development
 */

declare(strict_types=1);

use LPS\ContentModel\SearchIndex;
use LPS\ContentModel\Translations;

const LPS_SEARCH_SEED_VERSION = '17';
const LPS_SEARCH_SEED_OPTION  = 'lps_search_seed_version';

/**
 * Publishes one search route page when it is missing.
 *
 * @param string $slug   Page slug.
 * @param string $title  Page title.
 * @param string $locale Supported locale slug.
 */
function lps_search_seed_page( string $slug, string $title, string $locale ): int {
	$summary = 'pt-br' === $locale ? 'Busca no acervo publicado do laboratório.' : 'Search the published laboratory record set.';
	$content = 'pt-br' === $locale ? 'Esta página apresenta a busca do site.' : 'This page hosts the site search.';

	$existing = get_page_by_path( $slug );
	if ( $existing instanceof WP_Post ) {
		$page_id = $existing->ID;
		wp_update_post(
			array(
				'ID'           => $page_id,
				'post_excerpt' => $summary,
				'post_content' => $content,
				'post_status'  => 'publish',
			)
		);
	} else {
		$inserted = wp_insert_post(
			array(
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_title'   => $title,
				'post_name'    => $slug,
				'post_excerpt' => $summary,
				'post_content' => $content,
			),
			true
		);
		if ( ! is_int( $inserted ) || 0 >= $inserted ) {
			return 0;
		}
		$page_id = $inserted;
	}

	update_post_meta( $page_id, '_lps_locale', $locale );

	// The route locale is authoritative for this page, so the language is
	// reasserted on every run instead of trusting a prior assignment.
	if ( function_exists( 'pll_set_post_language' ) ) {
		pll_set_post_language( $page_id, $locale );
	}
	return $page_id;
}


/** Returns whether both supported languages are registered and usable. */
function lps_search_seed_languages_ready(): bool {
	if ( ! function_exists( 'pll_languages_list' ) || ! function_exists( 'pll_save_post_translations' ) || ! function_exists( 'pll_set_post_language' ) ) {
		return false;
	}
	$languages = pll_languages_list();
	if ( ! is_array( $languages ) ) {
		return false;
	}
	return in_array( 'pt-br', $languages, true ) && in_array( 'en', $languages, true );
}

/**
 * Tracks whether the current run left an unassociated pair behind.
 *
 * @param bool|null $set New state, or null to read the current one.
 */
function lps_search_seed_incomplete( ?bool $set = null ): bool {
	static $incomplete = false;
	if ( null !== $set ) {
		$incomplete = $set;
	}
	return $incomplete;
}

/**
 * Publishes one search fixture record through the plugin's own write path.
 *
 * @param string               $post_type Record type.
 * @param string               $locale    Supported locale slug.
 * @param string               $title     Record title.
 * @param string               $slug      Record slug.
 * @param string               $summary   Record summary.
 * @param string               $body      Record body.
 * @param array<string, mixed> $meta      Record metadata.
 * @param array<int, string>   $areas     Controlled research-area keys.
 */
function lps_search_seed_draft( string $post_type, string $locale, string $title, string $slug, string $summary, string $body, array $meta, array $areas = array() ): int {
	$existing = get_posts(
		array(
			'post_type'        => $post_type,
			'name'             => $slug,
			'post_status'      => 'any',
			'numberposts'      => 1,
			'suppress_filters' => false,
		)
	);
	if ( array() !== $existing ) {
		// The repair path still asserts the language term so records seeded before
		// the Polylang languages were usable become visible to archive queries.
		if ( function_exists( 'pll_set_post_language' ) ) {
			pll_set_post_language( $existing[0]->ID, $locale );
		}
		return $existing[0]->ID;
	}
	$post_id = wp_insert_post(
		array(
			'post_type'    => $post_type,
			'post_status'  => 'draft',
			'post_title'   => $title,
			'post_name'    => $slug,
			'post_excerpt' => $summary,
			'post_content' => $body,
			'meta_input'   => array_merge( array( '_lps_locale' => $locale ), $meta ),
		),
		true
	);
	if ( ! is_int( $post_id ) || 0 >= $post_id ) {
		return 0;
	}
	if ( array() !== $areas ) {
		wp_set_object_terms( $post_id, $areas, 'lps_research_area_key' );
	}
	if ( function_exists( 'pll_set_post_language' ) ) {
		pll_set_post_language( $post_id, $locale );
	}
	return $post_id;
}

/**
 * Associates one bilingual pair and publishes both sides.
 *
 * The translation policy refuses to publish a record before its counterpart
 * exists, so the pair is associated first and published afterwards.
 *
 * @param array<string, int> $ids Portuguese and English database IDs.
 */
function lps_search_seed_publish_pair( array $ids ): int {
	$portuguese = $ids['pt-br'] ?? 0;
	$english    = $ids['en'] ?? 0;
	if ( 0 >= $portuguese || 0 >= $english ) {
		return 0;
	}
	if ( class_exists( Translations::class ) ) {
		if ( is_wp_error( Translations::associate( $portuguese, $english ) ) ) {
			lps_search_seed_incomplete( true );
			return 0;
		}
	}

	// The bilingual contract publishes the reviewed English variant first and
	// refuses the Portuguese transition until its counterpart is live.
	update_post_meta( $english, '_lps_reviewed_source_hash', (string) get_post_meta( $english, '_lps_source_hash', true ) );

	$published = 0;
	foreach ( array( $english, $portuguese ) as $post_id ) {
		wp_update_post(
			array(
				'ID'          => $post_id,
				'post_status' => 'publish',
			)
		);
		$post       = get_post( $post_id );
		$published += $post instanceof WP_Post && 'publish' === $post->post_status ? 1 : 0;
	}
	return $published;
}

/** Publishes the search-specific discovery fixtures in both locales. */
function lps_search_seed_records(): int {
	$seeded = 0;

	$pairs = array(
		array(
			'type' => 'lps_publication',
			'pt'   => array( 'Detecção de falhas em instrumentação nuclear', 'deteccao-falhas-instrumentacao-fixture', 'Estudo de José Álvarez sobre detecção de falhas.', '10.1000/lps.falhas', '' ),
			'en'   => array( 'Fault detection in nuclear instrumentation', 'fault-detection-instrumentation-fixture', 'Study by Jose Alvarez on fault detection.', '10.1000/lps.falhas', '' ),
		),
		array(
			'type' => 'lps_publication',
			'pt'   => array( 'Sistema Integrado de Gestão e Monitoramento Analítico', 'sigma-monitoramento-fixture', 'Plataforma SIGMA de monitoramento analítico.', '10.1000/lps.sigma', 'SIGMA' ),
			'en'   => array( 'Integrated Analytical Monitoring System', 'sigma-monitoring-fixture', 'The SIGMA analytical monitoring platform.', '10.1000/lps.sigma', 'SIGMA' ),
		),
		array(
			'type' => 'lps_publication',
			'pt'   => array( 'Processamento de sinais sísmicos', 'sinais-sismicos-fixture', 'Redes profundas aplicadas a sinais sísmicos.', '10.1000/lps.sismico', '' ),
			'en'   => array( 'Seismic signal processing', 'seismic-signal-processing-fixture', 'Deep networks applied to seismic signals.', '10.1000/lps.sismico', '' ),
		),
	);
	foreach ( $pairs as $pair ) {
		$ids = array();
		foreach ( array(
			'pt-br' => $pair['pt'],
			'en'    => $pair['en'],
		) as $locale => $record ) {
			$ids[ $locale ] = lps_search_seed_draft(
				$pair['type'],
				$locale,
				$record[0],
				$record[1],
				$record[2],
				'Corpo de fixture. Não representa uma afirmação institucional.',
				array(
					'_lps_publication_type'    => 'article',
					'_lps_authoritative_title' => $record[0],
					'_lps_language'            => $locale,
					'_lps_doi'                 => $record[3],
					'_lps_acronym'             => $record[4],
					'_lps_type'                => 'article',
				),
				array( 'signal-processing' )
			);
		}
		$seeded += lps_search_seed_publish_pair( $ids );
	}

	for ( $item = 1; $item <= 25; ++$item ) {
		$created = lps_search_seed_draft(
			'lps_news',
			'pt-br',
			sprintf( 'Boletim de paginação %02d', $item ),
			sprintf( 'boletim-paginacao-fixture-%02d', $item ),
			sprintf( 'Resumo de fixture para paginação estável, edição %02d.', $item ),
			'Corpo de fixture do boletim.',
			array(
				'_lps_canonical_date' => '2025-03-01',
				'_lps_category'       => 'institucional',
			)
		);
		if ( 0 < $created ) {
			wp_update_post(
				array(
					'ID'          => $created,
					'post_status' => 'publish',
				)
			);
			++$seeded;
		}
	}

	// A draft record proves the index refuses unpublished content.
	lps_search_seed_draft(
		'lps_news',
		'pt-br',
		'Rascunho de paginação nunca indexado',
		'rascunho-nunca-indexado-fixture',
		'Resumo de rascunho.',
		'Corpo de rascunho.',
		array( '_lps_canonical_date' => '2025-03-01' )
	);

	return $seeded;
}

/** Seeds the search route pages and warms the locale index. */
function lps_search_seed_fixtures(): void {
	if ( LPS_SEARCH_SEED_VERSION === get_option( LPS_SEARCH_SEED_OPTION ) ) {
		return;
	}
	if ( ! lps_search_seed_languages_ready() ) {
		// Polylang registers its languages during this same boot; seeding before
		// the association back end is usable would leave unlinked drafts.
		return;
	}
	$portuguese = lps_search_seed_page( 'busca', 'Busca', 'pt-br' );
	$english    = lps_search_seed_page( 'search', 'Search', 'en' );

	if ( 0 < $portuguese && 0 < $english && function_exists( 'pll_save_post_translations' ) ) {
		pll_save_post_translations(
			array(
				'pt-br' => $portuguese,
				'en'    => $english,
			)
		);
	}
	// Route pages follow the same bilingual contract as every other record.
	if ( 0 < $portuguese && 0 < $english ) {
		lps_search_seed_publish_pair(
			array(
				'pt-br' => $portuguese,
				'en'    => $english,
			)
		);
	}
	update_option(
		'lps_search_seed_pages',
		array(
			'pt-br' => $portuguese,
			'en'    => $english,
		)
	);

	update_option( 'lps_search_seed_records', lps_search_seed_records() );

	if ( class_exists( SearchIndex::class ) ) {
		SearchIndex::install();
		$indexed = SearchIndex::rebuild();
		update_option( 'lps_search_seed_indexed', $indexed );
	}

	if ( ! lps_search_seed_incomplete() ) {
		update_option( LPS_SEARCH_SEED_OPTION, LPS_SEARCH_SEED_VERSION );
	}
	flush_rewrite_rules( false );
}

add_action(
	'init',
	static function (): void {
		$rules = get_option( 'rewrite_rules' );
		if ( ! is_array( $rules ) || ! isset( $rules['pt-br/busca/?$'] ) || ! isset( $rules['en/search/?$'] ) ) {
			flush_rewrite_rules( false );
		}
		lps_search_seed_fixtures();
	},
	120
);
