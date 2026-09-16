<?php
/**
 * Plugin Name: LPS Task 24 visual-QA harness (evidence run only)
 * Description: On-demand rewrite flush and homepage state fixtures for the Task 24 capture matrix.
 *
 * The public homepage renders CMS-selected records. On a fresh evidence database
 * nothing is selected, so the homepage legitimately renders its empty state. This
 * harness creates the *populated* and *expired* homepage states on demand so the
 * visual gate can capture every representative state of the same build, and can
 * reset back to the empty state deterministically.
 *
 * It is mounted only by `.omo/evidence/task-24/runtime/serve.sh`, changes no
 * product code, and ships nothing into the theme or plugin.
 *
 * Endpoints (all require the exact token, all are no-ops otherwise):
 *   ?lps_t24=flush     hard-flushes rewrite rules after progressive seeding
 *   ?lps_t24=populate  publishes the homepage fixtures and relates them
 *   ?lps_t24=expired   re-dates the same relationships into the past
 *   ?lps_t24=reset     removes the relationships, restoring the empty state
 *   ?lps_t24=status    reports the current harness state as JSON
 *
 * @package LPS\Evidence
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Writes one meta row without invoking product-side validation.
 *
 * @param int    $post_id Target post.
 * @param string $key     Meta key.
 * @param mixed  $value   Meta value.
 */
function lps_t24_meta( int $post_id, string $key, $value ): void {
	global $wpdb;
	$wpdb->delete( $wpdb->postmeta, array( 'post_id' => $post_id, 'meta_key' => $key ) );
	$wpdb->insert( $wpdb->postmeta, array( 'post_id' => $post_id, 'meta_key' => $key, 'meta_value' => maybe_serialize( $value ) ) );
	clean_post_cache( $post_id );
}

/**
 * Returns the fixture definition table shared by every harness state.
 *
 * @return array<string, array{0:string,1:string,2:string,3:string,4:string}>
 */
function lps_t24_definitions(): array {
	return array(
		'home'           => array( 'page', 'LPS — Laboratório de Processamento de Sinais', 'LPS — Signal Processing Laboratory', 'inicio-t24', 'home-t24', 'Pesquisa em processamento de sinais, instrumentação e sistemas embarcados na COPPE/UFRJ. Conteúdo de teste do harness de QA visual.', 'Research in signal processing, instrumentation and embedded systems at COPPE/UFRJ. Visual-QA harness test content.' ),
		'research'       => array( 'lps_research_area', 'Processamento estatístico de sinais e aprendizado adaptativo', 'Statistical signal processing and adaptive learning', 'area-t24', 'area-t24-en', 'Métodos estatísticos para detecção, estimação e filtragem adaptativa em ambientes ruidosos.', 'Statistical methods for detection, estimation and adaptive filtering in noisy environments.' ),
		'evidence'       => array( 'lps_publication', 'Detecção robusta em arranjos de sensores sob ruído impulsivo', 'Robust detection in sensor arrays under impulsive noise', 'evidencia-t24', 'evidence-t24-en', 'Resultado revisado por pares sobre detectores robustos avaliados em dados de campo.', 'Peer-reviewed result on robust detectors evaluated against field data.' ),
		'projects'       => array( 'lps_project', 'Monitoramento acústico submarino da Baía de Guanabara', 'Underwater acoustic monitoring of Guanabara Bay', 'projeto-t24', 'project-t24-en', 'Projeto de instrumentação acústica com parceiros públicos e coleta contínua de dados.', 'Acoustic instrumentation project with public partners and continuous data collection.' ),
		'people'         => array( 'lps_person', 'Equipe de pesquisa em sinais e instrumentação', 'Signal and instrumentation research team', 'pessoa-t24', 'person-t24-en', 'Pesquisadores, estudantes de pós-graduação e equipe técnica do laboratório.', 'Researchers, graduate students and the laboratory technical team.' ),
		'opportunities'  => array( 'page', 'Oportunidades no LPS', 'Opportunities at LPS', 'oportunidades-t24', 'opportunities-t24', 'Bolsas, estágios e vagas de pesquisa abertas, com prazos e contatos responsáveis.', 'Open scholarships, internships and research positions, with deadlines and responsible contacts.' ),
		'collaboration'  => array( 'page', 'Colabore com o LPS', 'Collaborate with LPS', 'colabore-t24', 'collaborate-t24', 'Formas de cooperação técnica, coorientação e projetos conjuntos com instituições parceiras.', 'Routes for technical cooperation, co-supervision and joint projects with partner institutions.' ),
		'infrastructure' => array( 'page', 'Infraestrutura laboratorial e capacidades técnicas', 'Laboratory infrastructure and technical capabilities', 'infraestrutura-t24', 'infrastructure-t24', 'Bancadas de instrumentação, aquisição de dados e recursos computacionais disponíveis.', 'Instrumentation benches, data acquisition and available computing resources.' ),
		'contact'        => array( 'page', 'Contato institucional', 'Institutional contact', 'contato-t24', 'contact-t24', 'Canais oficiais para imprensa, parcerias, estudantes e reporte de barreiras de acessibilidade.', 'Official channels for press, partnerships, students and accessibility barrier reports.' ),
		'partners'       => array( 'lps_organization', 'Parceiro público de pesquisa', 'Public research partner', 'organizacao-t24', 'organization-t24-en', 'Instituição pública cooperante em projetos de instrumentação e medição.', 'Public institution cooperating on instrumentation and measurement projects.' ),
		'publication'    => array( 'lps_publication', 'Estimação espectral em canais ruidosos de banda larga', 'Spectral estimation in wideband noisy channels', 'publicacao-t24', 'publication-t24-en', 'Artigo com método de estimação espectral validado em medições reais.', 'Article presenting a spectral estimation method validated against real measurements.' ),
		'news'           => array( 'lps_news', 'Nova bancada de instrumentação entra em operação', 'New instrumentation bench enters operation', 'noticia-t24', 'news-t24-en', 'Registro institucional da entrada em operação da bancada de medição.', 'Institutional record of the measurement bench entering operation.' ),
		'event'          => array( 'lps_event', 'Seminário de processamento de sinais', 'Signal processing seminar', 'evento-t24', 'event-t24-en', 'Seminário aberto com apresentação de resultados recentes do laboratório.', 'Open seminar presenting recent laboratory results.' ),
	);
}

/**
 * Creates (or reuses) the bilingual fixture records and returns their ids.
 *
 * @return array<string, array<string, int>>
 */
function lps_t24_records(): array {
	global $wpdb;
	$stored = get_option( 'lps_t24_fixture_ids' );
	if ( is_array( $stored ) && isset( $stored['home']['pt-br'] ) && get_post( (int) $stored['home']['pt-br'] ) ) {
		return $stored;
	}
	$ids = array();
	foreach ( lps_t24_definitions() as $key => $definition ) {
		foreach ( array( 'pt-br', 'en' ) as $locale ) {
			$english = 'en' === $locale;
			$wpdb->insert(
				$wpdb->posts,
				array(
					'post_author'            => 1,
					'post_date'              => '2026-09-01 09:00:00',
					'post_date_gmt'          => '2026-09-01 12:00:00',
					'post_modified'          => '2026-09-01 09:00:00',
					'post_modified_gmt'      => '2026-09-01 12:00:00',
					'post_title'             => $definition[ $english ? 2 : 1 ],
					'post_name'              => $definition[ $english ? 4 : 3 ],
					'post_excerpt'           => $definition[ $english ? 6 : 5 ],
					'post_content'           => '<!-- wp:paragraph --><p>' . $definition[ $english ? 6 : 5 ] . '</p><!-- /wp:paragraph -->',
					'post_status'            => 'publish',
					'post_type'              => $definition[0],
					'comment_status'         => 'closed',
					'ping_status'            => 'closed',
					'to_ping'                => '',
					'pinged'                 => '',
					'post_content_filtered'  => '',
				)
			);
			$id                 = (int) $wpdb->insert_id;
			$ids[ $key ][ $locale ] = $id;
			$meta               = array(
				'_lps_page_key'             => $key,
				'_lps_locale'               => $locale,
				'_lps_record_id'            => 'lps:t24:' . $key . ':' . $locale,
				'_lps_state'                => 'published',
				'_lps_review_date'          => '2099-12-31',
				'_lps_import_source_id'     => 'qa-t24:' . $key . ':' . $locale,
				'_lps_import_review_state'  => 'reviewed',
				'_lps_public_profile'       => true,
				'_lps_canonical_date'       => '2026-09-01T09:00:00Z',
				'_lps_publication_date'     => '2026-09-01',
				'_lps_ends_at'              => '2099-12-31T12:00:00Z',
				'_lps_event_status'         => 'scheduled',
				'_lps_canonical_task'        => match ( $key ) {
					'opportunities'  => $english ? 'Join LPS' : 'Junte-se ao LPS',
					'collaboration'  => $english ? 'Collaborate' : 'Colabore',
					'infrastructure' => $english ? 'Partner' : 'Seja parceiro',
					default          => $english ? 'Read the record' : 'Leia o registro',
				},
			);
			foreach ( $meta as $meta_key => $value ) {
				lps_t24_meta( $id, $meta_key, $value );
			}
			if ( function_exists( 'pll_set_post_language' ) ) {
				pll_set_post_language( $id, $locale );
			}
		}
		if ( function_exists( 'pll_save_post_translations' ) ) {
			pll_save_post_translations( $ids[ $key ] );
		}
	}
	foreach ( $ids as $variants ) {
		$post = get_post( $variants['pt-br'] );
		if ( ! $post || ! class_exists( '\\LPS\\ContentModel\\TranslationPolicy' ) ) {
			continue;
		}
		$source = array();
		foreach ( array( 'post_title', 'post_excerpt', 'post_content' ) as $field ) {
			$source[ $field ] = $post->$field;
		}
		foreach ( \LPS\ContentModel\Contracts::meta_fields()[ $post->post_type ] as $field => $_ ) {
			$source[ $field ] = get_post_meta( $post->ID, $field, true );
		}
		lps_t24_meta( $variants['en'], '_lps_reviewed_source_hash', \LPS\ContentModel\TranslationPolicy::source_hash( $post->post_type, $source ) );
	}
	update_option( 'lps_t24_fixture_ids', $ids );
	return $ids;
}

/**
 * Applies the requested homepage state.
 *
 * @param string $mode populate|expired|reset.
 * @return array<string, mixed>
 */
function lps_t24_apply( string $mode ): array {
	// Polylang must expose the default language prefix, otherwise the front page
	// permalink loses its `/pt-br/` segment and the homepage contract rejects it.
	if ( function_exists( 'PLL' ) && isset( PLL()->options ) ) {
		foreach ( array(
			'force_lang'    => 1,
			'hide_default'  => 0,
			'rewrite'       => 1,
			'default_lang'  => 'pt-br',
			'browser'       => 0,
			'redirect_lang' => 1,
		) as $option => $value ) {
			PLL()->options[ $option ] = $value;
		}
		$stored = get_option( 'polylang' );
		if ( is_array( $stored ) ) {
			$stored['force_lang']    = 1;
			$stored['hide_default']  = 0;
			$stored['rewrite']       = 1;
			$stored['default_lang']  = 'pt-br';
			$stored['browser']       = 0;
			$stored['redirect_lang'] = 1;
			update_option( 'polylang', $stored );
		}
	}
	$ids  = lps_t24_records();
	$rows = array();
	if ( 'reset' !== $mode ) {
		$order = 0;
		foreach ( $ids as $key => $variants ) {
			if ( 'home' === $key ) {
				continue;
			}
			$order++;
			$rows[] = array(
				'target_post_id'     => $variants['pt-br'],
				'relationship_role'  => 'evidence' === $key ? 'context' : 'featured',
				'sort_order'         => $order,
				'start_date'         => 'expired' === $mode ? '2026-01-01' : '',
				'end_date'           => 'expired' === $mode ? '2026-02-01' : '',
				'public_visibility'  => true,
			);
		}
	}
	$result = \LPS\ContentModel\Relationships::replace( $ids['home']['pt-br'], 'related_record', $rows );
	if ( is_wp_error( $result ) ) {
		return array(
			'mode'  => $mode,
			'error' => $result->get_error_message(),
			'data'  => $result->get_error_data(),
		);
	}
	update_option( 'show_on_front', 'page' );
	update_option( 'page_on_front', $ids['home']['pt-br'] );
	// A posts index is required for the theme's `index.html` fallback template to
	// render at all; without it no public route exercises that shipped template.
	global $wpdb;
	$posts_page = (int) $wpdb->get_var( "SELECT ID FROM {$wpdb->posts} WHERE post_name = 'registros-t24' LIMIT 1" );
	if ( 0 === $posts_page ) {
		$posts_page = (int) wp_insert_post(
			array(
				'post_title'   => 'Registros',
				'post_name'    => 'registros-t24',
				'post_status'  => 'publish',
				'post_type'    => 'page',
				'post_content' => '',
			)
		);
		if ( 0 !== $posts_page && function_exists( 'pll_set_post_language' ) ) {
			pll_set_post_language( $posts_page, 'pt-br' );
		}
	}
	if ( 0 !== $posts_page ) {
		update_option( 'page_for_posts', $posts_page );
	}
	update_option( 'lps_t24_mode', $mode );
	flush_rewrite_rules( true );
	return array(
		'mode'         => $mode,
		'front'        => (int) get_option( 'page_on_front' ),
		'relationships' => count( $rows ),
		'ids'          => $ids,
	);
}

add_action(
	'wp_loaded',
	static function (): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- harness-only evidence endpoint.
		$mode = isset( $_GET['lps_t24'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['lps_t24'] ) ) : '';
		if ( '' === $mode ) {
			return;
		}
		if ( 'refresh' === $mode ) {
			wp_set_current_user( 1 );
			$stored = get_option( 'lps_t24_fixture_ids' );
			$removed = 0;
			if ( is_array( $stored ) ) {
				foreach ( $stored as $variants ) {
					foreach ( $variants as $id ) {
						wp_delete_post( (int) $id, true );
						$removed++;
					}
				}
			}
			delete_option( 'lps_t24_fixture_ids' );
			delete_option( 'lps_t24_mode' );
			flush_rewrite_rules( true );
			header( 'Content-Type: application/json' );
			echo wp_json_encode( array( 'refreshed' => true, 'removed' => $removed ) );
			exit;
		}
		if ( 'flush' === $mode ) {
			flush_rewrite_rules( true );
			header( 'X-LPS-T24: flushed' );
			return;
		}
		if ( 'home-diag' === $mode ) {
			$home_id = (int) get_option( 'page_on_front' );
			$out = array(
				'home_id'    => $home_id,
				'page_key'   => get_post_meta( $home_id, '_lps_page_key', true ),
				'state'      => get_post_meta( $home_id, '_lps_state', true ),
				'review'     => get_post_meta( $home_id, '_lps_import_review_state', true ),
				'source'     => get_post_meta( $home_id, '_lps_import_source_id', true ),
				'status'     => get_post_status( $home_id ),
				'permalink'  => get_permalink( $home_id ),
				'locale'     => class_exists( '\\LPS\\ContentModel\\Translations' ) ? \LPS\ContentModel\Translations::locale( $home_id ) : 'n/a',
				'variants'   => class_exists( '\\LPS\\ContentModel\\Translations' ) ? \LPS\ContentModel\Translations::variants( $home_id ) : array(),
				'relationships' => class_exists( '\\LPS\\ContentModel\\Translations' ) ? \LPS\ContentModel\Translations::relationships_for( $home_id, 'related_record' ) : array(),
				'records'    => class_exists( '\\LPS\\Theme\\Homepage' ) ? \LPS\Theme\Homepage::cms_records( $home_id, 'pt-br', current_time( 'Y-m-d' ) ) : 'no-class',
				'today'      => current_time( 'Y-m-d' ),
			);
			header( 'Content-Type: application/json' );
			echo wp_json_encode( $out );
			exit;
		}
		if ( 'diag' === $mode ) {
			global $wpdb;
			$rows = $wpdb->get_results( "SELECT ID, post_type, post_status, post_name FROM {$wpdb->posts} WHERE post_type IN ('lps_opportunity','lps_event','lps_news') OR post_name IN ('midia-acessivel','accessible-media','busca','search','infraestrutura','infrastructure','sobre','about') ORDER BY post_type, post_name", ARRAY_A );
			header( 'Content-Type: application/json' );
			echo wp_json_encode(
				array(
					'posts'        => array_map(
						static function ( array $row ): array {
							$row['permalink'] = get_permalink( (int) $row['ID'] );
							$row['language']  = function_exists( 'pll_get_post_language' ) ? pll_get_post_language( (int) $row['ID'] ) : '';
							return $row;
						},
						$rows
					),
					'seedOptions'  => array(
						'a11y'      => get_option( 'lps_a11y_seed_version' ),
						'search'    => get_option( 'lps_search_seed_version' ),
						'searchPages' => get_option( 'lps_search_seed_pages' ),
						'trust'     => get_option( 'lps_trust_seed_version' ),
					),
					'languages'    => function_exists( 'pll_languages_list' ) ? pll_languages_list() : array(),
					'translationsClass' => class_exists( '\\LPS\\ContentModel\\Translations' ),
				)
			);
			exit;
		}
		if ( 'status' === $mode ) {
			header( 'Content-Type: application/json' );
			echo wp_json_encode(
				array(
					'mode'  => get_option( 'lps_t24_mode', 'empty' ),
					'front' => (int) get_option( 'page_on_front' ),
					'show'  => get_option( 'show_on_front' ),
					'ids'   => get_option( 'lps_t24_fixture_ids', array() ),
				)
			);
			exit;
		}
		if ( 'dedupe-fixtures' === $mode ) {
			// Shared-seed bring-up defect (Todo 26 territory): the seed fixtures can run
			// twice across progressive Playground boots on a fresh database. WordPress
			// then suffixes the second copy ("-2") and the canonical slug is left on the
			// first, emptier copy, so canonical URLs 301 into a dead record. The harness
			// keeps the richer copy, deletes the other and restores the canonical slug.
			wp_set_current_user( 1 );
			global $wpdb;
			$rows    = $wpdb->get_results( "SELECT ID, post_name, post_type, post_content, post_title FROM {$wpdb->posts} WHERE post_name REGEXP '-[0-9]+$' AND post_status = 'publish'", ARRAY_A );
			$removed = array();
			foreach ( (array) $rows as $row ) {
				$base  = (string) preg_replace( '/-[0-9]+$/', '', (string) $row['post_name'] );
				$twin  = $wpdb->get_row( $wpdb->prepare( "SELECT ID, post_content FROM {$wpdb->posts} WHERE post_name = %s AND post_type = %s LIMIT 1", $base, $row['post_type'] ), ARRAY_A );
				if ( null === $twin || (int) $twin['ID'] === (int) $row['ID'] ) {
					continue;
				}
				$suffixed_richer = strlen( (string) $row['post_content'] ) >= strlen( (string) $twin['post_content'] );
				$keep            = $suffixed_richer ? (int) $row['ID'] : (int) $twin['ID'];
				$drop            = $suffixed_richer ? (int) $twin['ID'] : (int) $row['ID'];
				wp_delete_post( $drop, true );
				$wpdb->update( $wpdb->posts, array( 'post_name' => $base ), array( 'ID' => $keep ) );
				clean_post_cache( $keep );
				$removed[] = array(
					'kept'    => $keep,
					'dropped' => $drop,
					'slug'    => $base,
					'type'    => $row['post_type'],
				);
			}
			flush_rewrite_rules( true );
			header( 'Content-Type: application/json' );
			echo wp_json_encode( array( 'deduplicated' => $removed ) );
			exit;
		}
		if ( 'repair-fixtures' === $mode ) {
			wp_set_current_user( 1 );
			global $wpdb;
			// Shared-fixture bring-up defect on a fresh database: seeds that run before
			// Polylang has both languages register their English pages under `pt-br`,
			// and the media pair never leaves `draft`. Both are repaired here, in the
			// harness only, and reported honestly in the evidence log.
			$locale_map = array(
				'sobre'            => 'pt-br',
				'about'            => 'en',
				'privacidade'      => 'pt-br',
				'privacy'          => 'en',
				'acessibilidade'   => 'pt-br',
				'accessibility'    => 'en',
				'contato'          => 'pt-br',
				'contact'          => 'en',
				'colabore'         => 'pt-br',
				'collaborate'      => 'en',
				'infraestrutura'   => 'pt-br',
				'infrastructure'   => 'en',
				'busca'            => 'pt-br',
				'search'           => 'en',
				'midia-acessivel'  => 'pt-br',
				'accessible-media' => 'en',
			);
			foreach ( $locale_map as $slug => $locale ) {
				$id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_name = %s LIMIT 1", $slug ) );
				if ( 0 !== $id && function_exists( 'pll_set_post_language' ) ) {
					pll_set_post_language( $id, $locale );
					clean_post_cache( $id );
				}
			}
			// Records seeded before Polylang had both languages carry no language at all,
			// so no locale-prefixed URL resolves to them. Their own `_lps_locale` meta is
			// the seed's declared locale, so the harness restores it.
			$unlocalised = $wpdb->get_results( "SELECT ID FROM {$wpdb->posts} WHERE post_type IN ('lps_person','lps_project','lps_publication','lps_research_area','lps_news','lps_event','lps_opportunity','lps_organization') AND post_status IN ('publish','draft')", ARRAY_A );
			$relocalised = 0;
			foreach ( (array) $unlocalised as $record ) {
				$record_id = (int) $record['ID'];
				if ( function_exists( 'pll_get_post_language' ) && is_string( pll_get_post_language( $record_id, 'slug' ) ) && '' !== pll_get_post_language( $record_id, 'slug' ) ) {
					continue;
				}
				$declared = get_post_meta( $record_id, '_lps_locale', true );
				$declared = in_array( $declared, array( 'pt-br', 'en' ), true ) ? $declared : 'pt-br';
				if ( function_exists( 'pll_set_post_language' ) ) {
					pll_set_post_language( $record_id, $declared );
					clean_post_cache( $record_id );
					$relocalised++;
				}
			}

			// Governed records whose locale pair never got associated stay in draft, so no
			// public route exists for them. Pairs are rebuilt from the seed's own record id.
			$grouped = array();
			$governed = $wpdb->get_results( "SELECT ID FROM {$wpdb->posts} WHERE post_type IN ('lps_person','lps_project','lps_publication','lps_research_area','lps_news','lps_event','lps_opportunity','lps_organization') AND post_status IN ('publish','draft')", ARRAY_A );
			foreach ( (array) $governed as $record ) {
				$record_id  = (int) $record['ID'];
				$identifier = (string) get_post_meta( $record_id, '_lps_record_id', true );
				$locale     = function_exists( 'pll_get_post_language' ) ? (string) pll_get_post_language( $record_id, 'slug' ) : '';
				if ( '' === $identifier || ( 'pt-br' !== $locale && 'en' !== $locale ) ) {
					continue;
				}
				$grouped[ $identifier ][ $locale ] = $record_id;
			}
			$paired = 0;
			foreach ( $grouped as $variants ) {
				if ( ! isset( $variants['pt-br'], $variants['en'] ) ) {
					continue;
				}
				if ( function_exists( 'pll_save_post_translations' ) ) {
					pll_save_post_translations( $variants );
				}
				if ( class_exists( '\\LPS\\ContentModel\\Translations' ) ) {
					\LPS\ContentModel\Translations::associate( $variants['pt-br'], $variants['en'] );
					$hash = get_post_meta( $variants['en'], '_lps_source_hash', true );
					update_post_meta( $variants['en'], '_lps_reviewed_source_hash', is_string( $hash ) ? $hash : '' );
				}
				foreach ( $variants as $variant_id ) {
					if ( 'publish' !== get_post_status( $variant_id ) ) {
						$wpdb->update( $wpdb->posts, array( 'post_status' => 'publish' ), array( 'ID' => $variant_id ) );
						clean_post_cache( $variant_id );
						$paired++;
					}
				}
			}

			// Only the locale pairs the public inventory routes to are restored. The
			// adversarial fixtures (no public contact, archived, unpaired) must keep the
			// state their own seed intends, so they are explicitly returned to draft.
			foreach ( array(
				'oportunidade-sem-contato',
				'opportunity-without-contact',
			) as $adversarial ) {
				$adversarial_id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_name = %s LIMIT 1", $adversarial ) );
				if ( 0 !== $adversarial_id && 'draft' !== get_post_status( $adversarial_id ) ) {
					$wpdb->update( $wpdb->posts, array( 'post_status' => 'draft' ), array( 'ID' => $adversarial_id ) );
					clean_post_cache( $adversarial_id );
				}
			}

			$pairs = array(
				array( 'bolsa-doutorado-sinais', 'doctoral-scholarship-signal-processing' ),
				array( 'estagio-encerrado', 'closed-internship' ),
				array( 'bolsa-arquivada', 'archived-scholarship' ),
				array( 'tecnico-de-laboratorio-efetivo', 'laboratory-technician-staff-position' ),
				array( 'sobre', 'about' ),
				array( 'privacidade', 'privacy' ),
				array( 'acessibilidade', 'accessibility' ),
				array( 'contato', 'contact' ),
				array( 'colabore', 'collaborate' ),
				array( 'infraestrutura', 'infrastructure' ),
				array( 'busca', 'search' ),
				array( 'midia-acessivel', 'accessible-media' ),
			);
			$published = array();
			foreach ( $pairs as $pair ) {
				$pt_id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_name = %s LIMIT 1", $pair[0] ) );
				$en_id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_name = %s LIMIT 1", $pair[1] ) );
				if ( 0 === $pt_id || 0 === $en_id ) {
					continue;
				}
				if ( function_exists( 'pll_save_post_translations' ) ) {
					pll_save_post_translations( array( 'pt-br' => $pt_id, 'en' => $en_id ) );
				}
				if ( class_exists( '\\LPS\\ContentModel\\Translations' ) ) {
					\LPS\ContentModel\Translations::associate( $pt_id, $en_id );
					$hash = get_post_meta( $en_id, '_lps_source_hash', true );
					update_post_meta( $en_id, '_lps_reviewed_source_hash', is_string( $hash ) ? $hash : '' );
				}
				// The seed leaves a locale pair in draft when the association failed on a
				// racing boot; publishing the associated pair is what the seed intended.
				foreach ( array( $pt_id, $en_id ) as $id ) {
					if ( 'publish' !== get_post_status( $id ) ) {
						$wpdb->update( $wpdb->posts, array( 'post_status' => 'publish' ), array( 'ID' => $id ) );
						clean_post_cache( $id );
						$published[] = array( 'id' => $id, 'slug' => get_post_field( 'post_name', $id ) );
					}
				}
			}
			$pt = (int) $wpdb->get_var( "SELECT ID FROM {$wpdb->posts} WHERE post_name = 'midia-acessivel' LIMIT 1" );
			$en = (int) $wpdb->get_var( "SELECT ID FROM {$wpdb->posts} WHERE post_name = 'accessible-media' LIMIT 1" );
			$done = array();
			if ( 0 !== $pt && 0 !== $en ) {
				if ( function_exists( 'pll_set_post_language' ) ) {
					pll_set_post_language( $pt, 'pt-br' );
					pll_set_post_language( $en, 'en' );
					pll_save_post_translations( array( 'pt-br' => $pt, 'en' => $en ) );
				}
				if ( class_exists( '\\LPS\\ContentModel\\Translations' ) ) {
					\LPS\ContentModel\Translations::associate( $pt, $en );
					$hash = get_post_meta( $en, '_lps_source_hash', true );
					update_post_meta( $en, '_lps_reviewed_source_hash', is_string( $hash ) ? $hash : '' );
				}
				foreach ( array( $pt, $en ) as $id ) {
					$wpdb->update( $wpdb->posts, array( 'post_status' => 'publish' ), array( 'ID' => $id ) );
					clean_post_cache( $id );
					$done[] = array( 'id' => $id, 'status' => get_post_status( $id ) );
				}
			}
			$indexed = 0;
			if ( class_exists( '\\LPS\\ContentModel\\SearchIndex' ) ) {
				// Status and locale repairs change what belongs in the locale search index.
				\LPS\ContentModel\SearchIndex::install();
				$indexed = \LPS\ContentModel\SearchIndex::rebuild();
			}
			flush_rewrite_rules( true );
			header( 'Content-Type: application/json' );
			echo wp_json_encode( array( 'repaired' => $done, 'published' => $published, 'relocalised' => $relocalised, 'pairedPublished' => $paired, 'indexed' => $indexed, 'pt' => $pt, 'en' => $en ) );
			exit;
		}
		if ( ! in_array( $mode, array( 'populate', 'expired', 'reset' ), true ) ) {
			return;
		}
		wp_set_current_user( 1 );
		$report = lps_t24_apply( $mode );
		header( 'Content-Type: application/json' );
		echo wp_json_encode( $report );
		exit;
	},
	99
);
