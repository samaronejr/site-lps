<?php
/**
 * Local-only homepage fixtures: the governed home page, its reviewed
 * selections, one cleared attachment, and the QA selection endpoint.
 *
 * Records are written through the plugin's own PHP entry points so every
 * capability map, provenance rule, and publish gate still applies exactly as
 * it does for editorial content. The `lps-qa/v1/homepage` endpoint exists so
 * the e2e spec can perform real CMS operations — assigning an accountable
 * owner, linking a related record, associating a translation — that the
 * public REST surface deliberately does not expose to editors.
 *
 * @package LPS\Development
 */

declare(strict_types=1);

use LPS\ContentModel\Relationships;
use LPS\ContentModel\Translations;

const LPS_HOME_SEED_VERSION = '5';
const LPS_HOME_SEED_OPTION  = 'lps_home_seed_version';
const LPS_HOME_SEED_REPORT  = 'lps_home_seed_report';

/** Returns the first administrator ID, or 1 when none is listed. */
function lps_home_seed_owner(): int {
	$admins = get_users(
		array(
			'role'   => 'administrator',
			'fields' => 'ID',
			'number' => 1,
		)
	);
	return isset( $admins[0] ) ? (int) $admins[0] : 1;
}

/** Returns the review date the fixtures use for feature eligibility. */
function lps_home_seed_review_date(): string {
	return gmdate( 'Y-m-d', time() + 300 * 86400 );
}

/**
 * Finds a post by slug across the governed types.
 *
 * @param string $post_type Record type.
 * @param string $slug      Post slug.
 */
function lps_home_seed_find( string $post_type, string $slug ): int {
	$post = get_page_by_path( $slug, OBJECT, $post_type );
	return $post instanceof WP_Post ? $post->ID : 0;
}

/**
 * Creates or adopts one governed record and returns its ID.
 *
 * @param string               $post_type Record type.
 * @param string               $locale    Record locale.
 * @param array<string, mixed> $record    Record fields.
 */
function lps_home_seed_record( string $post_type, string $locale, array $record ): int {
	$existing = lps_home_seed_find( $post_type, (string) $record['slug'] );
	$meta     = array_merge( array( '_lps_locale' => $locale ), $record['meta'] ?? array() );
	if ( 0 !== $existing ) {
		foreach ( $meta as $key => $value ) {
			update_post_meta( $existing, $key, $value );
		}
		if ( function_exists( 'pll_set_post_language' ) ) {
			pll_set_post_language( $existing, $locale );
		}
		return $existing;
	}
	$id = wp_insert_post(
		array(
			'post_type'    => $post_type,
			'post_status'  => 'draft',
			'post_title'   => (string) $record['title'],
			'post_name'    => (string) $record['slug'],
			'post_excerpt' => (string) ( $record['summary'] ?? '' ),
			'post_content' => (string) ( $record['body'] ?? ( $record['summary'] ?? '' ) ),
			'meta_input'   => $meta,
		),
		true
	);
	if ( is_wp_error( $id ) ) {
		return 0;
	}
	if ( function_exists( 'pll_set_post_language' ) ) {
		pll_set_post_language( $id, $locale );
	}
	return $id;
}

/**
 * Creates or adopts a bilingual pair and publishes it in dependency order.
 *
 * @param string               $post_type Record type.
 * @param array<string, mixed> $source    Portuguese authority record.
 * @param array<string, mixed> $target    English variant record.
 * @param bool                 $publish   Whether to publish the pair now.
 * @return array{pt-br: int, en: int}
 */
function lps_home_seed_pair( string $post_type, array $source, array $target, bool $publish = true ): array {
	$pt = lps_home_seed_record( $post_type, 'pt-br', $source );
	$en = lps_home_seed_record( $post_type, 'en', $target );
	if ( 0 === $pt || 0 === $en ) {
		return array(
			'pt-br' => 0,
			'en'    => 0,
		);
	}
	$variants = Translations::variants( $pt );
	if ( ( $variants['en'] ?? 0 ) !== $en ) {
		if ( is_wp_error( Translations::associate( $pt, $en ) ) ) {
			return array(
				'pt-br' => 0,
				'en'    => 0,
			);
		}
	}
	$pair = array(
		'pt-br' => $pt,
		'en'    => $en,
	);
	if ( $publish ) {
		lps_home_seed_publish_pair( $pair );
	}
	return $pair;
}

/**
 * Publishes an already-linked bilingual pair in dependency order.
 *
 * @param array{pt-br: int, en: int} $pair Linked record pair.
 */
function lps_home_seed_publish_pair( array $pair ): void {
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

/**
 * Adopts an existing governed page by page key, or creates the pair.
 *
 * @param string               $key       Stable page key.
 * @param array<string, mixed> $source    Portuguese authority record.
 * @param array<string, mixed> $target    English variant record.
 * @param array<string, mixed> $extra     Extra meta applied to both variants.
 * @return array{pt-br: int, en: int}
 */
function lps_home_seed_page( string $key, array $source, array $target, array $extra = array() ): array {
	$pt          = 0;
	$pt_fallback = 0;
	foreach ( get_posts(
		array(
			'post_type'   => 'page',
			'post_status' => 'any',
			'numberposts' => 50,
			'meta_key'    => '_lps_page_key',
			'meta_value'  => $key,
		)
	) as $candidate ) {
		if ( ! $candidate instanceof WP_Post || 'pt-br' !== Translations::locale( $candidate->ID ) ) {
			continue;
		}
		// Prefer the canonical slug; suffixed duplicates from earlier fixture
		// runs stay adopted only when no canonical page exists.
		if ( $candidate->post_name === $source['slug'] ) {
			$pt = $candidate->ID;
			break;
		}
		if ( 0 === $pt_fallback ) {
			$pt_fallback = $candidate->ID;
		}
	}
	if ( 0 === $pt ) {
		$pt = $pt_fallback;
	}
	if ( 0 === $pt ) {
		$pt = lps_home_seed_record( 'page', 'pt-br', $source );
	}
	$variants = 0 < $pt ? Translations::variants( $pt ) : array();
	$en       = $variants['en'] ?? 0;
	if ( 0 === $en ) {
		$en = lps_home_seed_record( 'page', 'en', $target );
		if ( 0 < $pt && 0 < $en ) {
			Translations::associate( $pt, $en );
		}
	}
	foreach ( array( $pt, $en ) as $id ) {
		if ( 0 === $id ) {
			continue;
		}
		foreach ( $extra as $meta_key => $value ) {
			update_post_meta( $id, $meta_key, $value );
		}
		// Adopted pages authored before origin tracking carry a record ID but
		// no explicit origin; without one the visibility decision reads them
		// as ambiguous and withholds them from every surface.
		if ( '' === (string) get_post_meta( $id, '_lps_origin', true ) && '' !== (string) get_post_meta( $id, '_lps_record_id', true ) ) {
			update_post_meta( $id, '_lps_origin', 'native' );
		}
	}
	if ( 0 < $en ) {
		update_post_meta( $en, '_lps_reviewed_source_hash', (string) get_post_meta( $en, '_lps_source_hash', true ) );
	}
	foreach ( array( $en, $pt ) as $id ) {
		if ( 0 < $id ) {
			wp_update_post(
				array(
					'ID'          => $id,
					'post_status' => 'publish',
				)
			);
		}
	}
	return array(
		'pt-br' => $pt,
		'en'    => $en,
	);
}

/** Creates or adopts the cleared governed attachment. */
function lps_home_seed_attachment(): int {
	$existing = get_posts(
		array(
			'post_type'   => 'attachment',
			'post_status' => 'any',
			'numberposts' => 1,
			'meta_key'    => '_lps_media_checksum',
			'meta_value'  => 'dddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddd',
		)
	);
	if ( isset( $existing[0] ) && $existing[0] instanceof WP_Post ) {
		return $existing[0]->ID;
	}
	$uploads = wp_upload_dir();
	$source  = $uploads['basedir'] . '/lps-qa-media/qa-poster.png';
	if ( ! is_file( $source ) ) {
		return 0;
	}
	$target = $uploads['basedir'] . '/lps-home-hero.png';
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_copy -- local fixture copy inside the uploads tree.
	copy( $source, $target );
	$id = wp_insert_attachment(
		array(
			'post_title'     => 'Bancada de instrumentação (fixture de QA)',
			'post_excerpt'   => 'Bancada de instrumentação do laboratório, registro de fixture.',
			'post_content'   => 'Fixture de QA local. Não é um registro institucional.',
			'post_mime_type' => 'image/png',
			'post_status'    => 'inherit',
		),
		$target
	);
	if ( is_wp_error( $id ) || 0 === $id ) {
		return 0;
	}
	require_once ABSPATH . 'wp-admin/includes/image.php';
	$metadata = wp_generate_attachment_metadata( $id, $target );
	if ( is_array( $metadata ) ) {
		wp_update_attachment_metadata( $id, $metadata );
	}
	$size = is_array( $metadata ) && isset( $metadata['width'], $metadata['height'] ) ? $metadata : array( 'width' => 0, 'height' => 0 );
	foreach (
		array(
			'_lps_media_credit'         => 'Arquivo LPS (fixture de QA)',
			'_lps_media_rights_holder'  => 'LPS',
			'_lps_media_license'        => 'authorized-use',
			'_lps_media_source_url'     => 'https://records.example/qa/home-hero',
			'_lps_media_checksum'       => 'dddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddd',
			'_lps_media_rights_status'  => 'cleared',
			'_lps_media_privacy_status' => 'reviewed',
			'_lps_media_focal_x'        => 0.35,
			'_lps_media_focal_y'        => 0.65,
			'_lps_media_width'          => (int) $size['width'],
			'_lps_media_height'         => (int) $size['height'],
			'_wp_attachment_image_alt'  => 'Bancada de instrumentação com equipamentos de medida do laboratório.',
		) as $key => $value
	) {
		update_post_meta( $id, $key, $value );
	}
	return $id;
}

/** Seeds the homepage composition fixtures exactly once per environment. */
function lps_home_seed(): void {
	if ( ! class_exists( Translations::class ) || ! function_exists( 'pll_languages_list' ) ) {
		return;
	}
	$owner       = lps_home_seed_owner();
	$review_date = lps_home_seed_review_date();
	$report      = array();

	$home = lps_home_seed_page(
		'home',
		array(
			'slug'    => 'inicio',
			'title'   => 'Laboratório de Processamento de Sinais',
			'summary' => 'Pesquisa, ensino e colaboração em processamento de sinais na COPPE/UFRJ.',
			'meta'    => array( '_lps_page_key' => 'home' ),
		),
		array(
			'slug'    => 'home',
			'title'   => 'Signal Processing Laboratory',
			'summary' => 'Research, teaching, and collaboration in signal processing at COPPE/UFRJ.',
			'meta'    => array( '_lps_page_key' => 'home' ),
		),
		array(
			'_lps_owner_user_id' => $owner,
			'_lps_review_date'   => $review_date,
		)
	);
	$report['home'] = $home;

	$journeys = array(
		'opportunities'  => array(
			array(
				'slug'    => 'oportunidades',
				'title'   => 'Oportunidades no LPS',
				'summary' => 'Bolsas, estágios e posições abertas no laboratório.',
				'meta'    => array( '_lps_page_key' => 'opportunities' ),
			),
			array(
				'slug'    => 'opportunities',
				'title'   => 'Opportunities at LPS',
				'summary' => 'Scholarships, internships, and open positions at the laboratory.',
				'meta'    => array( '_lps_page_key' => 'opportunities' ),
			),
			'Junte-se ao LPS',
			'Join LPS',
		),
		'collaboration'  => array(
			array(
				'slug'    => 'colabore',
				'title'   => 'Colabore com o LPS',
				'summary' => 'Rotas para participar, colaborar e ser parceiro.',
				'meta'    => array( '_lps_page_key' => 'collaboration' ),
			),
			array(
				'slug'    => 'collaborate',
				'title'   => 'Collaborate with LPS',
				'summary' => 'Routes to join, collaborate, and partner.',
				'meta'    => array( '_lps_page_key' => 'collaboration' ),
			),
			'Colabore',
			'Collaborate',
		),
		'infrastructure' => array(
			array(
				'slug'    => 'infraestrutura',
				'title'   => 'Infraestrutura do LPS',
				'summary' => 'Laboratórios, equipamentos e capacidades de pesquisa.',
				'meta'    => array( '_lps_page_key' => 'infrastructure' ),
			),
			array(
				'slug'    => 'infrastructure',
				'title'   => 'LPS infrastructure',
				'summary' => 'Laboratories, equipment, and research capabilities.',
				'meta'    => array( '_lps_page_key' => 'infrastructure' ),
			),
			'Seja parceiro',
			'Partner',
		),
		'contact'        => array(
			array(
				'slug'    => 'contato',
				'title'   => 'Contato',
				'summary' => 'Contatos institucionais públicos do laboratório.',
				'meta'    => array( '_lps_page_key' => 'contact' ),
			),
			array(
				'slug'    => 'contact',
				'title'   => 'Contact',
				'summary' => 'Public institutional contacts of the laboratory.',
				'meta'    => array( '_lps_page_key' => 'contact' ),
			),
			'Fale com o LPS',
			'Contact LPS',
		),
	);
	foreach ( $journeys as $key => $definition ) {
		$pair = lps_home_seed_page(
			$key,
			$definition[0],
			$definition[1],
			array(
				'_lps_owner_user_id'  => $owner,
				'_lps_review_date'    => $review_date,
				'_lps_canonical_task' => $definition[2],
			)
		);
		if ( 0 < $pair['en'] ) {
			update_post_meta( $pair['en'], '_lps_canonical_task', $definition[3] );
		}
		$report[ $key ] = $pair;
	}

	$attachment = lps_home_seed_attachment();
	$report['attachment'] = $attachment;
	if ( 0 < $attachment && 0 < $home['pt-br'] ) {
		update_post_meta( $home['pt-br'], '_thumbnail_id', $attachment );
	}

	$governance = array(
		'_lps_owner_user_id' => $owner,
		'_lps_review_date'   => $review_date,
	);

	$area = lps_home_seed_pair(
		'lps_research_area',
		array(
			'slug'    => 'qa-home-area-sinais',
			'title'   => 'Processamento de sinais (fixture da home)',
			'summary' => 'Área de pesquisa de fixture para a composição da home.',
			'meta'    => array_merge(
				$governance,
				array(
					'_lps_stable_key' => 'qa-home-signal-processing',
					'_lps_label'      => 'Processamento de sinais (fixture da home)',
					'_lps_sort_order' => 1,
				)
			),
		),
		array(
			'slug'    => 'qa-home-signal-processing',
			'title'   => 'Signal processing (home fixture)',
			'summary' => 'Fixture research area for the homepage composition.',
			'meta'    => array( '_lps_label' => 'Signal processing (home fixture)' ),
		)
	);
	$report['area'] = $area;

	$person = lps_home_seed_pair(
		'lps_person',
		array(
			'slug'    => 'qa-home-docente',
			'title'   => 'Docente da home (fixture de QA)',
			'summary' => 'Perfil de fixture para o módulo de pessoas da home.',
			'meta'    => array_merge(
				$governance,
				array(
					'_lps_canonical_name' => 'Docente da home (fixture de QA)',
					'_lps_person_status'  => 'active',
				)
			),
		),
		array(
			'slug'    => 'qa-home-faculty',
			'title'   => 'Home faculty member (QA fixture)',
			'summary' => 'Fixture profile for the homepage people module.',
		)
	);
	$report['person'] = $person;

	$project = lps_home_seed_pair(
		'lps_project',
		array(
			'slug'    => 'qa-home-projeto',
			'title'   => 'Projeto da home (fixture de QA)',
			'summary' => 'Projeto de fixture para o estrato de projetos da home.',
			'meta'    => array_merge(
				$governance,
				array(
					'_lps_project_status' => 'current',
					'_lps_start_date'     => '2026-01-15',
					'_lps_asset_ids'      => 0 < $attachment ? array( (string) $attachment ) : array(),
				)
			),
		),
		array(
			'slug'    => 'qa-home-project',
			'title'   => 'Home project (QA fixture)',
			'summary' => 'Fixture project for the homepage projects stratum.',
		),
		false
	);
	if ( 0 < $project['pt-br'] && 0 < $person['pt-br'] ) {
		Relationships::replace(
			$project['pt-br'],
			'project_member',
			array(
				array(
					'target_post_id'    => $person['pt-br'],
					'relationship_role' => 'lead',
					'sort_order'        => 1,
				),
			)
		);
	}
	lps_home_seed_publish_pair( $project );
	$report['project'] = $project;

	$news = lps_home_seed_pair(
		'lps_news',
		array(
			'slug'    => 'qa-home-noticia',
			'title'   => 'Notícia da home (fixture de QA)',
			'summary' => 'Notícia de fixture para o módulo de novidades da home.',
			'meta'    => array_merge(
				$governance,
				array(
					'_lps_canonical_date' => gmdate( 'Y-m-d\TH:i:sP', time() - 2 * 86400 ),
					'_lps_news_status'    => 'published',
				)
			),
		),
		array(
			'slug'    => 'qa-home-news',
			'title'   => 'Home news (QA fixture)',
			'summary' => 'Fixture news item for the homepage latest module.',
		)
	);
	$report['news'] = $news;

	$event = lps_home_seed_pair(
		'lps_event',
		array(
			'slug'    => 'qa-home-seminario',
			'title'   => 'Seminário da home (fixture de QA)',
			'summary' => 'Evento de fixture para o módulo de novidades da home.',
			'meta'    => array_merge(
				$governance,
				array(
					'_lps_starts_at'    => gmdate( 'Y-m-d\TH:i:sP', time() + 20 * 86400 ),
					'_lps_ends_at'      => gmdate( 'Y-m-d\TH:i:sP', time() + 20 * 86400 + 7200 ),
					'_lps_event_status' => 'scheduled',
					'_lps_venue'        => 'Auditório do LPS, COPPE/UFRJ',
				)
			),
		),
		array(
			'slug'    => 'qa-home-seminar',
			'title'   => 'Home seminar (QA fixture)',
			'summary' => 'Fixture event for the homepage latest module.',
		)
	);
	$report['event'] = $event;

	$publication = lps_home_seed_pair(
		'lps_publication',
		array(
			'slug'    => 'qa-home-publicacao',
			'title'   => 'Publicação da home (fixture de QA)',
			'summary' => 'Publicação de fixture para o estrato de evidências da home.',
			'meta'    => array_merge(
				$governance,
				array(
					'_lps_publication_type'    => 'journal-article',
					'_lps_publication_status'  => 'final',
					'_lps_authoritative_title' => 'Publicação da home (fixture de QA)',
					'_lps_language'            => 'pt-BR',
					'_lps_publication_date'    => '2026-03-09',
					'_lps_date_precision'      => 'day',
					'_lps_venue'               => 'Periódico de fixture',
				)
			),
		),
		array(
			'slug'    => 'qa-home-publication',
			'title'   => 'Home publication (QA fixture)',
			'summary' => 'Fixture publication for the homepage evidence stratum.',
		)
	);
	$report['publication'] = $publication;

	$partner = lps_home_seed_pair(
		'lps_organization',
		array(
			'slug'    => 'qa-home-parceiro',
			'title'   => 'Parceiro da home (fixture de QA)',
			'summary' => 'Organização parceira de fixture para a home.',
			'meta'    => array_merge(
				$governance,
				array(
					'_lps_organization_name' => 'Parceiro da home (fixture de QA)',
					'_lps_organization_kind' => 'partner',
					'_lps_country_code'      => 'BR',
					'_lps_public_profile'    => '1',
				)
			),
		),
		array(
			'slug'    => 'qa-home-partner',
			'title'   => 'Home partner (QA fixture)',
			'summary' => 'Fixture partner organization for the homepage.',
		)
	);
	$report['partner'] = $partner;

	if ( 0 < $home['pt-br'] ) {
		$rows = array();
		$sort = 1;
		foreach (
			array(
				array( $area['pt-br'], 'featured' ),
				array( $project['pt-br'], 'featured' ),
				array( $person['pt-br'], 'featured' ),
				array( $news['pt-br'], 'featured' ),
				array( $event['pt-br'], 'featured' ),
				array( $partner['pt-br'], 'featured' ),
				array( $publication['pt-br'], 'context' ),
				array( $report['opportunities']['pt-br'], 'featured' ),
				array( $report['collaboration']['pt-br'], 'featured' ),
				array( $report['infrastructure']['pt-br'], 'featured' ),
				array( $report['contact']['pt-br'], 'featured' ),
			) as $selection
		) {
			if ( 0 === $selection[0] ) {
				continue;
			}
			$rows[] = array(
				'target_post_id'    => $selection[0],
				'relationship_role' => $selection[1],
				'sort_order'        => $sort,
				'public_visibility' => true,
			);
			$sort++;
		}
		Relationships::replace( $home['pt-br'], 'related_record', $rows );
	}

	update_option( 'show_on_front', 'page' );
	update_option( 'page_on_front', $home['pt-br'] );
	update_option( 'page_for_posts', 0 );
	update_option( LPS_HOME_SEED_REPORT, $report );
}

/** Returns the seeded report, or an empty map when the seed has not run. */
function lps_home_seed_report(): array {
	$report = get_option( LPS_HOME_SEED_REPORT, array() );
	return is_array( $report ) ? $report : array();
}

/** Registers the QA homepage-selection endpoint. */
function lps_home_seed_rest(): void {
	register_rest_route(
		'lps-qa/v1',
		'/homepage',
		array(
			array(
				'methods'             => 'GET',
				'callback'            => 'lps_home_seed_rest_report',
				'permission_callback' => 'is_user_logged_in',
			),
			array(
				'methods'             => 'POST',
				'callback'            => 'lps_home_seed_rest_update',
				'permission_callback' => 'lps_home_seed_rest_allowed',
			),
		)
	);
}

/**
 * Reports whether the current session may drive the QA selection endpoint.
 *
 * The endpoint performs the same writes an institutional editor performs in
 * the CMS — assigning an accountable owner, linking a related record — so it
 * requires an authenticated session that can edit the target record.
 *
 * @param WP_REST_Request $request Current request.
 */
function lps_home_seed_rest_allowed( WP_REST_Request $request ): bool {
	// The publisher role edits records but not other users' pages or
	// attachments, so the gate is the editorial primitive plus the field
	// allowlist enforced in the callback — not a per-post map_meta_cap.
	return is_user_logged_in() && current_user_can( 'edit_posts' );
}

/**
 * Returns the seeded homepage identifiers, or a visibility probe when the
 * `probe` parameter names a record ID. The probe reports the exact
 * `visibility_decision` errors the homepage applies so the spec can assert
 * exclusion reasons instead of guessing.
 *
 * @param WP_REST_Request $request Current request.
 */
function lps_home_seed_rest_report( WP_REST_Request $request ): WP_REST_Response {
	$probe = (int) $request->get_param( 'probe' );
	if ( 'snapshot' === (string) $request->get_param( 'probe' ) && class_exists( \LPS\Theme\Homepage::class ) ) {
		$locale = (string) $request->get_param( 'locale' );
		$locale = in_array( $locale, array( 'pt-br', 'en' ), true ) ? $locale : 'pt-br';
		$report = lps_home_seed_report();
		$home   = (int) ( $report['home']['pt-br'] ?? 0 );
		return new WP_REST_Response(
			array(
				'home_id'      => $home,
				'page_key'     => get_post_meta( $home, '_lps_page_key', true ),
				'front'        => (int) get_option( 'page_on_front' ),
				'show_on'      => get_option( 'show_on_front' ),
				'pll_front_pt' => function_exists( 'pll_get_post' ) ? (int) pll_get_post( (int) get_option( 'page_on_front' ), 'pt-br' ) : -1,
				'pll_front_en' => function_exists( 'pll_get_post' ) ? (int) pll_get_post( (int) get_option( 'page_on_front' ), 'en' ) : -1,
				'pll_lang'     => function_exists( 'pll_current_language' ) ? (string) pll_current_language() : '',
				'pll_home_url' => function_exists( 'pll_home_url' ) ? (string) pll_home_url( 'pt-br' ) : '',
				'relations'    => \LPS\ContentModel\Translations::relationships_for( $home, 'related_record' ),
				'records'      => \LPS\Theme\Homepage::cms_records( $home, $locale, gmdate( 'Y-m-d' ) ),
			),
			200
		);
	}
	if ( 0 < $probe && class_exists( \LPS\ContentModel\PublicationPolicy::class ) && class_exists( \LPS\ContentModel\PublicationRecords::class ) ) {
		$post = get_post( $probe );
		if ( $post instanceof WP_Post ) {
			$locale   = (string) $request->get_param( 'locale' );
			$locale   = in_array( $locale, array( 'pt-br', 'en' ), true ) ? $locale : 'pt-br';
			$decision = \LPS\ContentModel\PublicationPolicy::visibility_decision(
				\LPS\ContentModel\PublicationRecords::publication_record( $post ),
				\LPS\ContentModel\PublicationPolicy::SURFACE_FEATURE,
				$locale,
				gmdate( 'Y-m-d' )
			);
			return new WP_REST_Response( $decision, 200 );
		}
	}
	return new WP_REST_Response( lps_home_seed_report(), 200 );
}

/**
 * Applies one QA homepage-selection write.
 *
 * Actions mirror real CMS operations: `meta` assigns governed fields (the
 * accountable owner, the next review date, a featured image, import
 * provenance), `relate`/`unrelate` edit the homepage's related-record
 * selections, and `associate` links a translation variant and marks it
 * reviewed against the current source.
 *
 * @param WP_REST_Request $request Current request.
 * @return WP_REST_Response|WP_Error
 */
function lps_home_seed_rest_update( WP_REST_Request $request ) {
	$action  = (string) $request->get_param( 'action' );
	$post_id = (int) $request->get_param( 'post_id' );
	$post    = get_post( $post_id );
	if ( ! $post instanceof WP_Post ) {
		return new WP_Error( 'lps_qa_record_missing', 'The target record does not exist.', array( 'status' => 404 ) );
	}
	if ( 'meta' === $action ) {
		$meta = $request->get_param( 'meta' );
		if ( ! is_array( $meta ) ) {
			return new WP_Error( 'lps_qa_meta_required', 'A meta object is required.', array( 'status' => 400 ) );
		}
		foreach ( $meta as $key => $value ) {
			if ( ! is_string( $key ) || ( ! str_starts_with( $key, '_lps_' ) && '_thumbnail_id' !== $key && '_wp_attachment_image_alt' !== $key ) ) {
				return new WP_Error( 'lps_qa_meta_key_forbidden', 'Only governed _lps_* fields may be assigned.', array( 'status' => 400 ) );
			}
			update_post_meta( $post_id, $key, $value );
		}
		return new WP_REST_Response( array( 'updated' => $post_id ), 200 );
	}
	if ( 'relate' === $action || 'unrelate' === $action ) {
		$report  = lps_home_seed_report();
		$home_id = (int) ( $report['home']['pt-br'] ?? 0 );
		if ( 0 === $home_id ) {
			return new WP_Error( 'lps_qa_home_missing', 'The seeded homepage is missing.', array( 'status' => 409 ) );
		}
		// Row-level relate/unrelate: `Relationships::replace` rewrites the
		// whole selection set, so parallel QA writers (the three browser
		// projects) would resurrect one another's removals. A single INSERT
		// or DELETE touches only the target row; the file lock serializes the
		// sort_order read for the insert path.
		global $wpdb;
		$table    = \LPS\ContentModel\Migrations::table_names( $wpdb )['relationships'];
		$uploads  = wp_upload_dir();
		$lockfile = fopen( (string) $uploads['basedir'] . '/lps-qa-media/.homepage-selection.lock', 'c' );
		if ( false !== $lockfile ) {
			flock( $lockfile, LOCK_EX );
		}
		if ( 'unrelate' === $action ) {
			$deleted = $wpdb->delete(
				$table,
				array(
					'source_post_id'    => $home_id,
					'relationship_type' => 'related_record',
					'target_post_id'    => $post_id,
				),
				array( '%d', '%s', '%d' )
			);
			if ( false === $deleted ) {
				if ( false !== $lockfile ) {
					flock( $lockfile, LOCK_UN );
					fclose( $lockfile );
				}
				return new WP_Error( 'lps_qa_unrelate_failed', (string) $wpdb->last_error, array( 'status' => 500 ) );
			}
			$result = array( 'unrelated' => $post_id );
		} else {
			$role        = in_array( $request->get_param( 'role' ), array( 'featured', 'context' ), true ) ? (string) $request->get_param( 'role' ) : 'featured';
			$start_date  = (string) $request->get_param( 'start_date' );
			$end_date    = (string) $request->get_param( 'end_date' );
			$sort_order  = (int) $request->get_param( 'sort_order' );
			$existing_id = (int) $wpdb->get_var(
				$wpdb->prepare(
					'SELECT relationship_id FROM %i WHERE source_post_id = %d AND relationship_type = %s AND target_post_id = %d LIMIT 1',
					$table,
					$home_id,
					'related_record',
					$post_id
				)
			);
			if ( 0 < $existing_id ) {
				$wpdb->update(
					$table,
					array(
						'relationship_role' => $role,
						'start_date'        => $start_date,
						'end_date'          => $end_date,
						'public_visibility' => 1,
					),
					array( 'relationship_id' => $existing_id ),
					array( '%s', '%s', '%s', '%d' ),
					array( '%d' )
				);
			} else {
				if ( 0 >= $sort_order ) {
					$sort_order = 1 + (int) $wpdb->get_var(
						$wpdb->prepare(
							'SELECT MAX(sort_order) FROM %i WHERE source_post_id = %d AND relationship_type = %s',
							$table,
							$home_id,
							'related_record'
						)
					);
				}
				$inserted = $wpdb->insert(
					$table,
					array(
						'source_post_id'    => $home_id,
						'target_post_id'    => $post_id,
						'relationship_type' => 'related_record',
						'relationship_role' => $role,
						'sort_order'        => $sort_order,
						'start_date'        => $start_date,
						'end_date'          => $end_date,
						'public_visibility' => 1,
					),
					array( '%d', '%d', '%s', '%s', '%d', '%s', '%s', '%d' )
				);
				if ( false === $inserted ) {
					if ( false !== $lockfile ) {
						flock( $lockfile, LOCK_UN );
						fclose( $lockfile );
					}
					return new WP_Error( 'lps_qa_relate_failed', (string) $wpdb->last_error, array( 'status' => 500 ) );
				}
			}
			$result = array( 'related' => $post_id );
		}
		if ( false !== $lockfile ) {
			flock( $lockfile, LOCK_UN );
			fclose( $lockfile );
		}
		return new WP_REST_Response( $result, 200 );
	}
	if ( 'associate' === $action ) {
		$english_id = (int) $request->get_param( 'english_id' );
		if ( 0 >= $english_id ) {
			return new WP_Error( 'lps_qa_variant_required', 'An english_id is required.', array( 'status' => 400 ) );
		}
		$result = Translations::associate( $post_id, $english_id );
		if ( $result instanceof WP_Error ) {
			return $result;
		}
		update_post_meta( $english_id, '_lps_reviewed_source_hash', (string) get_post_meta( $english_id, '_lps_source_hash', true ) );
		wp_update_post(
			array(
				'ID'          => $english_id,
				'post_status' => 'publish',
			)
		);
		return new WP_REST_Response( array( 'associated' => array( $post_id, $english_id ) ), 200 );
	}
	return new WP_Error( 'lps_qa_action_unknown', 'Unknown homepage action.', array( 'status' => 400 ) );
}

add_action( 'rest_api_init', 'lps_home_seed_rest' );

add_action(
	'init',
	static function (): void {
		if ( LPS_HOME_SEED_VERSION === get_option( LPS_HOME_SEED_OPTION ) ) {
			return;
		}
		if ( ! class_exists( Translations::class ) || ! function_exists( 'pll_languages_list' ) ) {
			// The seed bails without its dependencies; the version must not be
			// written in that case or the fixture set is skipped permanently
			// (e.g. when init fires before the plugin and Polylang activate).
			return;
		}
		lps_home_seed();
		update_option( LPS_HOME_SEED_OPTION, LPS_HOME_SEED_VERSION );
	},
	60
);
