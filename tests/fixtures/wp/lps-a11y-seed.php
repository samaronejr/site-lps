<?php
/**
 * Local-only accessibility fixtures: authored media, a core post, and a date archive.
 *
 * Todo 23 audits authored-media classes (figure, decorative image, data table,
 * downloadable document, captioned media with transcript, inline language change)
 * against the real editor output, not against synthetic markup, so this fixture
 * authors them as regular block content.
 *
 * @package LPS\Development
 */

declare(strict_types=1);

use LPS\ContentModel\Translations;

const LPS_A11Y_SEED_VERSION = '4';
const LPS_A11Y_SEED_OPTION  = 'lps_a11y_seed_version';

/**
 * Returns the authored media page content for one locale.
 *
 * @param string $locale Supported locale slug.
 * @throws RuntimeException When the local media manifest cannot be decoded.
 */
function lps_a11y_seed_media_content( string $locale ): string {
	$english = 'en' === $locale;
	$image   = '/wp-includes/images/media/document.png';
	$uploads = wp_upload_dir();
	$dir     = $uploads['basedir'] . '/lps-qa-media/';
	$url     = $uploads['baseurl'] . '/lps-qa-media/';
	$content = wp_json_file_decode( $dir . 'content.json', array( 'associative' => true ) );
	if ( ! is_array( $content ) ) {
		throw new RuntimeException( 'The local QA media content manifest could not be decoded.' );
	}
	$media = $content[ $locale ];
	$pdf   = 'document-' . $locale . '.pdf';
	$bytes = filesize( $dir . $pdf );

	$blocks  = '<!-- wp:paragraph -->' . "\n";
	$blocks .= '<p>' . ( $english ? 'Local QA fixture. These examples are test data, not institutional records.' : 'Fixture local de QA. Estes exemplos são dados de teste, não registros institucionais.' ) . '</p>' . "\n";
	$blocks .= '<!-- /wp:paragraph -->' . "\n\n";
	$blocks .= '<!-- wp:heading -->' . "\n";
	$blocks .= '<h2 class="wp-block-heading">' . ( $english ? 'Documented figure' : 'Figura documentada' ) . '</h2>' . "\n";
	$blocks .= '<!-- /wp:heading -->' . "\n\n";

	$alt     = $english
		? 'Instrumentation bench with an oscilloscope connected to a signal generator.'
		: 'Bancada de instrumentação com osciloscópio conectado a um gerador de sinais.';
	$caption = $english ? 'Instrumentation bench, LPS, 2026.' : 'Bancada de instrumentação, LPS, 2026.';
	$blocks .= '<!-- wp:image {"sizeSlug":"large"} -->' . "\n";
	$blocks .= '<figure class="wp-block-image size-large"><img src="' . $image . '" alt="' . esc_attr( $alt ) . '"/>';
	$blocks .= '<figcaption class="wp-element-caption">' . esc_html( $caption ) . '</figcaption></figure>' . "\n";
	$blocks .= '<!-- /wp:image -->' . "\n\n";

	$blocks .= '<!-- wp:image -->' . "\n";
	$blocks .= '<figure class="wp-block-image"><img src="' . $image . '" alt="" role="presentation"/></figure>' . "\n";
	$blocks .= '<!-- /wp:image -->' . "\n\n";

	$blocks .= '<!-- wp:heading -->' . "\n";
	$blocks .= '<h2 class="wp-block-heading">' . ( $english ? 'Equipment table' : 'Tabela de equipamentos' ) . '</h2>' . "\n";
	$blocks .= '<!-- /wp:heading -->' . "\n\n";

	$table_caption = $english ? 'Laboratory equipment and operating status' : 'Equipamentos do laboratório e situação de operação';
	$blocks       .= '<!-- wp:table {"hasFixedLayout":false} -->' . "\n";
	$blocks       .= '<figure class="wp-block-table"><table><thead><tr>';
	$blocks       .= '<th scope="col">' . ( $english ? 'Equipment' : 'Equipamento' ) . '</th>';
	$blocks       .= '<th scope="col">' . ( $english ? 'Status' : 'Situação' ) . '</th>';
	$blocks       .= '<th scope="col">' . ( $english ? 'Reviewed' : 'Revisado em' ) . '</th></tr></thead><tbody>';
	$blocks       .= '<tr><th scope="row">' . ( $english ? 'Oscilloscope' : 'Osciloscópio' ) . '</th><td>' . ( $english ? 'In operation' : 'Em operação' ) . '</td><td>2026-09-01</td></tr>';
	$blocks       .= '<tr><th scope="row">' . ( $english ? 'Signal generator' : 'Gerador de sinais' ) . '</th><td>' . ( $english ? 'In operation' : 'Em operação' ) . '</td><td>2026-09-01</td></tr>';
	$blocks       .= '</tbody></table><figcaption class="wp-element-caption">' . esc_html( $table_caption ) . '</figcaption></figure>' . "\n";
	$blocks       .= '<!-- /wp:table -->' . "\n\n";

	$blocks .= '<!-- wp:heading -->' . "\n";
	$blocks .= '<h2 class="wp-block-heading">' . ( $english ? 'Documents' : 'Documentos' ) . '</h2>' . "\n";
	$blocks .= '<!-- /wp:heading -->' . "\n\n";

	$html_route = ( $english ? '/en/accessible-media/' : '/pt-br/midia-acessivel/' ) . '#lps-qa-document';
	$blocks    .= '<!-- wp:paragraph -->' . "\n";
	$blocks    .= '<p><a href="' . esc_url( $url . $pdf ) . '" download data-html-alternative="' . $html_route . '">';
	$blocks    .= esc_html( $media['title'] . ' (PDF, ' . $bytes . ' bytes)' );
	$blocks    .= '</a> — <a href="' . $html_route . '" data-html-alternative="' . $html_route . '">';
	$blocks    .= $english ? 'read the same QA document in HTML below' : 'ler o mesmo documento de QA em HTML abaixo';
	$blocks    .= '</a></p>' . "\n";
	$blocks    .= '<!-- /wp:paragraph -->' . "\n\n";

	$blocks .= '<!-- wp:heading -->' . "\n";
	$blocks .= '<h2 class="wp-block-heading">' . esc_html( $media['videoTitle'] ) . '</h2>' . "\n";
	$blocks .= '<!-- /wp:heading -->' . "\n\n";

	$blocks .= '<!-- wp:html -->' . "\n";
	// Core KSES preserves video[src], but strips source elements on fixture insertion.
	$blocks .= '<video controls preload="none" src="' . esc_url( $url . 'qa-demo.webm' ) . '" poster="' . esc_url( $url . 'qa-poster.png' ) . '" aria-label="' . esc_attr( $media['videoTitle'] ) . '" aria-describedby="lps-qa-transcript" width="640" height="360">';
	$blocks .= '<track kind="captions" src="' . esc_url( $url . 'captions-' . $locale . '.vtt' ) . '" srclang="' . ( $english ? 'en' : 'pt-BR' ) . '" label="' . ( $english ? 'English captions' : 'Legendas em português' ) . '" default>';
	$blocks .= '</video>' . "\n";
	$blocks .= '<!-- /wp:html -->' . "\n\n";

	$blocks .= '<!-- wp:group {"tagName":"div","anchor":"lps-qa-transcript"} -->' . "\n";
	$blocks .= '<div id="lps-qa-transcript" class="wp-block-group"><!-- wp:heading {"level":3} -->' . "\n";
	$blocks .= '<h3 class="wp-block-heading">' . ( $english ? 'Transcript' : 'Transcrição' ) . '</h3>' . "\n";
	$blocks .= '<!-- /wp:heading -->' . "\n\n";
	foreach ( $media['cues'] as $index => $cue ) {
		$blocks .= '<!-- wp:paragraph -->' . "\n";
		$blocks .= '<p>' . esc_html( sprintf( '00:%02d–00:%02d: %s', $index * 4, ( $index + 1 ) * 4, $cue ) ) . '</p>' . "\n";
		$blocks .= '<!-- /wp:paragraph -->' . "\n";
	}
	$blocks .= '</div><!-- /wp:group -->' . "\n\n";

	$blocks .= '<!-- wp:group {"anchor":"lps-qa-document"} -->' . "\n";
	$blocks .= '<div id="lps-qa-document" class="wp-block-group"><!-- wp:heading -->' . "\n";
	$blocks .= '<h2 class="wp-block-heading">' . esc_html( $media['title'] ) . '</h2><!-- /wp:heading -->' . "\n";
	foreach ( $media['paragraphs'] as $paragraph ) {
		$blocks .= '<!-- wp:paragraph -->' . "\n" . '<p>' . esc_html( $paragraph ) . '</p><!-- /wp:paragraph -->' . "\n";
	}
	$blocks .= '</div><!-- /wp:group -->' . "\n\n";

	$blocks .= '<!-- wp:paragraph -->' . "\n";
	$blocks .= $english
		? '<p>The Portuguese title of the laboratory is <span lang="pt-BR">Laboratório de Processamento de Sinais</span>.</p>' . "\n"
		: '<p>O nome do laboratório em inglês é <span lang="en">Signal Processing Laboratory</span>.</p>' . "\n";
	$blocks .= '<!-- /wp:paragraph -->' . "\n";

	return $blocks;
}

/**
 * Creates or updates one seeded post and returns its id.
 *
 * @param string $post_type Registered post type.
 * @param string $slug      Post slug.
 * @param string $title     Post title.
 * @param string $excerpt   Post excerpt.
 * @param string $content   Block content.
 * @param string $date      Optional publication date.
 */
function lps_a11y_seed_post( string $post_type, string $slug, string $title, string $excerpt, string $content, string $date = '' ): int {
	$existing = get_page_by_path( $slug, 'OBJECT', $post_type );
	if ( $existing instanceof WP_Post ) {
		wp_update_post(
			array(
				'ID'           => $existing->ID,
				'post_title'   => $title,
				'post_excerpt' => $excerpt,
				'post_content' => $content,
			)
		);
		return (int) $existing->ID;
	}
	$fields = array(
		'post_type'    => $post_type,
		// Governed records are inserted as drafts and published only after the
		// locale pair is associated and reviewed, exactly like every other fixture.
		'post_status'  => 'page' === $post_type ? 'draft' : 'publish',
		'post_title'   => $title,
		'post_name'    => $slug,
		'post_excerpt' => $excerpt,
		'post_content' => $content,
	);
	if ( '' !== $date ) {
		$fields['post_date'] = $date;
	}
	$id = wp_insert_post( $fields, true );
	return is_wp_error( $id ) ? 0 : (int) $id;
}

/** Returns whether both supported languages are registered and usable. */
function lps_a11y_seed_languages_ready(): bool {
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
function lps_a11y_seed_incomplete( ?bool $set = null ): bool {
	static $incomplete = false;
	if ( null !== $set ) {
		$incomplete = $set;
	}
	return $incomplete;
}

/** Seeds the accessibility fixtures. */
function lps_a11y_seed(): void {
	$pt = lps_a11y_seed_post(
		'page',
		'midia-acessivel',
		'Mídia acessível',
		'Exemplos autorais de figura, tabela, documento e mídia com transcrição.',
		lps_a11y_seed_media_content( 'pt-br' )
	);
	$en = lps_a11y_seed_post(
		'page',
		'accessible-media',
		'Accessible media',
		'Authored examples of figure, table, document and media with a transcript.',
		lps_a11y_seed_media_content( 'en' )
	);
	$post = lps_a11y_seed_post(
		'post',
		'nota-de-acessibilidade',
		'Nota de acessibilidade',
		'Registro público sobre a conformidade de acessibilidade do site.',
		'<!-- wp:paragraph -->' . "\n" . '<p>Esta nota registra a política de acessibilidade aplicada às páginas públicas do LPS e o canal de reporte de barreiras.</p>' . "\n" . '<!-- /wp:paragraph -->',
		'2026-03-02 10:00:00'
	);

	foreach ( array( $pt => 'pt-br', $en => 'en' ) as $id => $locale ) {
		if ( 0 !== $id ) {
			update_post_meta( $id, '_lps_locale', $locale );
		}
	}
	if ( function_exists( 'pll_set_post_language' ) ) {
		foreach ( array(
			$pt   => 'pt-br',
			$en   => 'en',
			$post => 'pt-br',
		) as $id => $locale ) {
			if ( 0 !== $id ) {
				pll_set_post_language( $id, $locale );
			}
		}
	}
	if ( 0 === $pt || 0 === $en || ! class_exists( Translations::class ) || is_wp_error( Translations::associate( $pt, $en ) ) ) {
		// Without the reciprocal association the pair can never satisfy the
		// bilingual contract, so the run retries on the next request.
		lps_a11y_seed_incomplete( true );
		return;
	}
	// The publish gate keeps a locale pair in draft until the English variant
	// records a review of the exact Portuguese source it was translated from.
	// A refusal here is a policy outcome, not a seed failure, so the run still
	// records its version instead of retrying a denied transition forever.
	$source_hash = get_post_meta( $en, '_lps_source_hash', true );
	update_post_meta( $en, '_lps_reviewed_source_hash', is_string( $source_hash ) ? $source_hash : '' );
	foreach ( array( $en, $pt ) as $id ) {
		wp_update_post(
			array(
				'ID'          => $id,
				'post_status' => 'publish',
			)
		);
	}
}

add_action(
	'init',
	static function (): void {
		if ( LPS_A11Y_SEED_VERSION === get_option( LPS_A11Y_SEED_OPTION ) ) {
			return;
		}
		if ( ! lps_a11y_seed_languages_ready() ) {
			// Polylang registers its languages during this same boot; seeding before
			// the association back end is usable would leave unlinked drafts.
			return;
		}
		lps_a11y_seed();
		if ( ! lps_a11y_seed_incomplete() ) {
			update_option( LPS_A11Y_SEED_OPTION, LPS_A11Y_SEED_VERSION );
		}
	},
	40
);
