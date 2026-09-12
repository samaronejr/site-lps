<?php
/**
 * Local-only fixtures for canonical metadata, feeds, sitemaps, and redirects.
 *
 * Adds the records that only the indexation layer exercises: a genuine
 * employment vacancy that may legitimately become a `JobPosting`, a draft that
 * must stay out of every sitemap and feed, and the legacy redirect graph
 * (direct hop, chained hop, and deliberate removal).
 *
 * @package LPS\Development
 */

declare(strict_types=1);

use LPS\ContentModel\Translations;

const LPS_SEO_SEED_VERSION = '5';
const LPS_SEO_SEED_OPTION  = 'lps_seo_seed_version';

/**
 * Inserts or updates one record and returns its identifier.
 *
 * @param string               $post_type Record type.
 * @param string               $locale    Locale slug.
 * @param string               $status    Post status.
 * @param array<string, mixed> $record    Record fields.
 */
function lps_seo_seed_record( string $post_type, string $locale, string $status, array $record ): int {
	$meta     = array_merge( array( '_lps_locale' => $locale ), $record['meta'] ?? array() );
	$existing = get_page_by_path( $record['slug'], OBJECT, $post_type );
	if ( $existing instanceof WP_Post ) {
		foreach ( $meta as $key => $value ) {
			update_post_meta( $existing->ID, $key, $value );
		}
		return $existing->ID;
	}
	$id = wp_insert_post(
		array(
			'post_type'    => $post_type,
			'post_status'  => $status,
			'post_title'   => $record['title'],
			'post_name'    => $record['slug'],
			'post_excerpt' => $record['summary'],
			'post_content' => $record['body'] ?? $record['summary'],
			'meta_input'   => $meta,
		),
		true
	);
	return is_wp_error( $id ) ? 0 : $id;
}

/**
 * Publishes one bilingual pair through the translation gate.
 *
 * @param string               $post_type  Record type.
 * @param array<string, mixed> $portuguese Portuguese authority record.
 * @param array<string, mixed> $english    English variant record.
 */
function lps_seo_seed_pair( string $post_type, array $portuguese, array $english ): void {
	$pt = lps_seo_seed_record( $post_type, 'pt-br', 'draft', $portuguese );
	$en = lps_seo_seed_record( $post_type, 'en', 'draft', $english );
	if ( 0 === $pt || 0 === $en ) {
		return;
	}
	Translations::associate( $pt, $en );
	update_post_meta( $en, '_lps_reviewed_source_hash', (string) get_post_meta( $en, '_lps_source_hash', true ) );
	foreach ( array( $en, $pt ) as $id ) {
		wp_update_post(
			array(
				'ID'          => $id,
				'post_status' => 'publish',
			)
		);
	}
}

/**
 * Publishes one redirect record.
 *
 * @param string $slug   Record slug.
 * @param string $source Normalized legacy source.
 * @param string $target Local target path, empty for a deliberate removal.
 * @param bool   $gone   Whether the source answers HTTP 410.
 */
function lps_seo_seed_redirect( string $slug, string $source, string $target, bool $gone = false ): void {
	lps_seo_seed_record(
		'lps_redirect',
		'pt-br',
		'publish',
		array(
			'slug'    => $slug,
			'title'   => $source,
			'summary' => 'Fixture de redirecionamento legado.',
			'meta'    => array(
				'_lps_redirect_source' => $source,
				'_lps_redirect_target' => $target,
				'_lps_redirect_gone'   => $gone ? '1' : '',
				'_lps_redirect_status' => $gone ? 410 : 301,
				'_lps_redirect_reason' => 'Inventário de migração local.',
			),
		)
	);
	$post = get_page_by_path( $slug, OBJECT, 'lps_redirect' );
	if ( $post instanceof WP_Post && 'publish' !== $post->post_status ) {
		wp_update_post(
			array(
				'ID'          => $post->ID,
				'post_status' => 'publish',
			)
		);
	}
}

/**
 * Drafts records duplicated by repeated local provisioning.
 *
 * Re-provisioning a long-lived development database made WordPress append a
 * numeric suffix to colliding fixture slugs, leaving several published copies
 * of the same record. A copy is only ever moved back to `draft` - never
 * deleted - so nothing that references it can break.
 *
 * @return int Number of drifted copies returned to draft.
 */
function lps_seo_seed_repair_duplicates(): int {
	global $wpdb;
	$types = "'lps_person','lps_organization','lps_research_area','lps_project','lps_publication','lps_news','lps_opportunity','lps_event','lps_infrastructure'";
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- local fixture repair over a fixed type list.
	$rows = $wpdb->get_results( "SELECT ID, post_type, post_name, post_title FROM {$wpdb->posts} WHERE post_status = 'publish' AND post_type IN ({$types})" );
	if ( ! is_array( $rows ) ) {
		return 0;
	}
	$groups = array();
	foreach ( $rows as $row ) {
		$groups[ $row->post_type . '|' . $row->post_title ][] = $row;
	}
	$drafted = 0;
	foreach ( $groups as $group ) {
		if ( count( $group ) < 2 ) {
			continue;
		}
		usort(
			$group,
			static function ( object $left, object $right ): int {
				$left_suffixed  = 1 === preg_match( '/-\d+$/', (string) $left->post_name ) ? 1 : 0;
				$right_suffixed = 1 === preg_match( '/-\d+$/', (string) $right->post_name ) ? 1 : 0;
				return array( $left_suffixed, (int) $left->ID ) <=> array( $right_suffixed, (int) $right->ID );
			}
		);
		foreach ( array_slice( $group, 1 ) as $copy ) {
			if ( 1 !== preg_match( '/-\d+$/', (string) $copy->post_name ) ) {
				continue;
			}
			wp_update_post(
				array(
					'ID'          => (int) $copy->ID,
					'post_status' => 'draft',
				)
			);
			++$drafted;
		}
	}
	return $drafted;
}

/**
 * Refreshes pagination fixtures that were seeded with one shared summary.
 *
 * The pagination corpus predates the unique-description contract, so each
 * bulletin receives its own edition summary here instead of sharing a single
 * sentence with twenty-four siblings.
 *
 * @return int Number of refreshed records.
 */
function lps_seo_seed_refresh_pagination_summaries(): int {
	$refreshed = 0;
	foreach ( get_posts(
		array(
			'post_type'        => 'lps_news',
			'post_status'      => 'any',
			'numberposts'      => 100,
			'suppress_filters' => false,
		)
	) as $post ) {
		if ( 1 !== preg_match( '/^boletim-paginacao-fixture-(\d+)$/', (string) $post->post_name, $found ) ) {
			continue;
		}
		$summary = sprintf( 'Resumo de fixture para paginação estável, edição %02d.', (int) $found[1] );
		if ( $summary === (string) $post->post_excerpt ) {
			continue;
		}
		wp_update_post(
			array(
				'ID'           => (int) $post->ID,
				'post_excerpt' => $summary,
			)
		);
		++$refreshed;
	}
	return $refreshed;
}

/**
 * Refreshes profile fixtures that were seeded with one shared summary.
 *
 * People and organizations were provisioned with a single boilerplate
 * sentence, which made eight public addresses publish the same meta
 * description. Each record now describes itself, exactly as its seed source
 * does for a fresh provisioning.
 *
 * @return int Number of refreshed records.
 */
function lps_seo_seed_refresh_profile_summaries(): int {
	$shared = array(
		'pt-br' => 'Registro de fixture local usado para validar as superfícies públicas.',
		'en'    => 'Local fixture record used to validate the public surfaces.',
	);

	$refreshed = 0;
	foreach ( get_posts(
		array(
			'post_type'        => array( 'lps_person', 'lps_organization' ),
			'post_status'      => 'any',
			'numberposts'      => 200,
			'suppress_filters' => false,
		)
	) as $post ) {
		$excerpt = (string) $post->post_excerpt;
		$locale  = in_array( $excerpt, array( $shared['en'] ), true ) ? 'en' : 'pt-br';
		if ( ! in_array( $excerpt, $shared, true ) ) {
			continue;
		}
		$title   = (string) get_the_title( $post );
		$summary = 'en' === $locale
			? sprintf( 'Local fixture record for %s used to validate the public surfaces.', $title )
			: sprintf( 'Registro de fixture local de %s usado para validar as superfícies públicas.', $title );
		wp_update_post(
			array(
				'ID'           => (int) $post->ID,
				'post_excerpt' => $summary,
			)
		);
		++$refreshed;
	}
	return $refreshed;
}

add_action(
	'init',
	static function (): void {
		if ( ! class_exists( Translations::class ) ) {
			return;
		}
		if ( LPS_SEO_SEED_VERSION === get_option( LPS_SEO_SEED_OPTION ) ) {
			return;
		}

		$day = static fn ( int $offset ): string => gmdate( 'Y-m-d\TH:i:sP', time() + ( $offset * DAY_IN_SECONDS ) );

		lps_seo_seed_pair(
			'lps_opportunity',
			array(
				'slug'    => 'tecnico-de-laboratorio-efetivo',
				'title'   => 'Técnico de laboratório (vaga efetiva)',
				'summary' => 'Vaga efetiva de apoio técnico à instrumentação do laboratório.',
				'meta'    => array(
					'_lps_opportunity_type'         => 'employment',
					'_lps_eligibility'              => 'Formação técnica em eletrônica ou área correlata.',
					'_lps_application_instructions' => 'Envie currículo para o endereço institucional.',
					'_lps_opens_at'                 => $day( -3 ),
					'_lps_closes_at'                => $day( 30 ),
					'_lps_location'                 => 'Rio de Janeiro, RJ, Brasil',
					'_lps_positions'                => 1,
					'_lps_contact'                  => 'oportunidades@lps.ufrj.br',
					'_lps_contact_is_role'          => '1',
				),
			),
			array(
				'slug'    => 'laboratory-technician-staff-position',
				'title'   => 'Laboratory technician (staff position)',
				'summary' => 'Staff position supporting laboratory instrumentation.',
				'meta'    => array(
					'_lps_opportunity_type'         => 'employment',
					'_lps_eligibility'              => 'Technical degree in electronics or a related field.',
					'_lps_application_instructions' => 'Send your CV to the institutional address.',
					'_lps_opens_at'                 => $day( -3 ),
					'_lps_closes_at'                => $day( 30 ),
					'_lps_location'                 => 'Rio de Janeiro, RJ, Brazil',
					'_lps_positions'                => 1,
					'_lps_contact'                  => 'oportunidades@lps.ufrj.br',
					'_lps_contact_is_role'          => '1',
				),
			)
		);

		lps_seo_seed_record(
			'lps_news',
			'pt-br',
			'draft',
			array(
				'slug'    => 'rascunho-de-noticia-nao-indexavel',
				'title'   => 'Rascunho de notícia não indexável',
				'summary' => 'Rascunho que nunca pode entrar em sitemap, feed ou índice.',
				'meta'    => array(
					'_lps_canonical_date' => $day( 0 ),
					'_lps_news_status'    => 'draft',
				),
			)
		);

		lps_seo_seed_redirect( 'legado-pesquisa', '/lps/antigo.html', '/pt-br/pesquisa/' );
		lps_seo_seed_redirect( 'legado-cadeia', '/lps/cadeia.html', '/lps/antigo.html' );
		lps_seo_seed_redirect( 'legado-removido', '/lps/removido.html', '', true );

		update_option( 'lps_seo_seed_repaired_duplicates', lps_seo_seed_repair_duplicates() );
		update_option( 'lps_seo_seed_refreshed_summaries', lps_seo_seed_refresh_pagination_summaries() );
		update_option( 'lps_seo_seed_refreshed_profiles', lps_seo_seed_refresh_profile_summaries() );

		flush_rewrite_rules( false );
		update_option( LPS_SEO_SEED_OPTION, LPS_SEO_SEED_VERSION );
	},
	40
);

add_action(
	'wp_loaded',
	static function (): void {
		$rules = get_option( 'rewrite_rules' );
		if ( ! is_array( $rules ) || ! isset( $rules['^sitemap\.xml$'] ) ) {
			flush_rewrite_rules( false );
		}
	},
	20
);
