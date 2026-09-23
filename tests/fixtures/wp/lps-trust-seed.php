<?php
/**
 * Local-only opportunity, event, news, and institutional trust fixtures.
 *
 * Dates are computed relative to the seeding instant so every derived state
 * (upcoming, open, closed, aged-closed, cancelled) stays deterministic no
 * matter when the environment is provisioned.
 *
 * @package LPS\Development
 */

declare(strict_types=1);

use LPS\ContentModel\Translations;

const LPS_TRUST_SEED_VERSION = '7';
const LPS_TRUST_SEED_OPTION  = 'lps_trust_seed_version';

/**
 * Migration receipts the content-model plugin publishes once its own idempotent
 * migrations and audit ledger installation have completed.
 *
 * The seed mirrors the plugin ordering through these receipts instead of
 * reimplementing schema logic, so a fresh database converges in the same order
 * the plugin itself defines.
 */
const LPS_TRUST_SEED_REQUIRED_RECEIPTS = array(
	'lps_relationship_schema_version',
	'lps_audit_schema_version',
);

/**
 * Reports whether the content-model plugin finished the boot work this seed needs.
 *
 * On a fresh-database first boot the mu-plugin loads before the content-model
 * plugin is active, so the translation boundary and the migrated storage may not
 * exist yet. The seed then defers to a later request rather than fatally erroring.
 */
function lps_trust_seed_dependencies_ready(): bool {
	if ( ! class_exists( Translations::class ) ) {
		return false;
	}
	foreach ( LPS_TRUST_SEED_REQUIRED_RECEIPTS as $receipt ) {
		$version = get_option( $receipt, '' );
		if ( ! is_string( $version ) || '' === $version ) {
			return false;
		}
	}
	return lps_trust_seed_languages_ready();
}

/** Returns whether both supported languages are registered and usable. */
function lps_trust_seed_languages_ready(): bool {
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
 * A pair that cannot be associated can never satisfy the bilingual publish
 * gate, so the run reports itself incomplete and is retried on the next
 * request instead of recording a seed version that never converged.
 *
 * @param bool|null $set New state, or null to read the current one.
 */
function lps_trust_seed_incomplete( ?bool $set = null ): bool {
	static $incomplete = false;
	if ( null !== $set ) {
		$incomplete = $set;
	}
	return $incomplete;
}

/**
 * Returns the database ID of an already-seeded record, or zero when absent.
 *
 * Child pages share their slug path with the parent, so a bare get_page_by_path
 * lookup never matches them and every retry inserted a suffixed duplicate.
 * Matching on post_name plus the resolved parent finds the real record.
 *
 * @param string $post_type Record type.
 * @param string $slug      Record slug.
 * @param int    $parent    Parent record ID, zero for top-level records.
 */
function lps_trust_seed_find( string $post_type, string $slug, int $parent = 0 ): int {
	$found = get_posts(
		array(
			'post_type'        => $post_type,
			'name'             => $slug,
			'post_parent'      => $parent,
			'post_status'      => 'any',
			'numberposts'      => 1,
			'fields'           => 'ids',
			'suppress_filters' => true,
		)
	);
	return isset( $found[0] ) && is_int( $found[0] ) ? $found[0] : 0;
}

/**
 * Assigns the Polylang language term a seeded record needs to be listed.
 *
 * Records without a language term are invisible to locale-filtered archive
 * queries, so the assignment runs on every pass - including the repair path
 * for records a previous incomplete run left behind.
 *
 * @param int    $post_id Record ID.
 * @param string $locale  Locale slug.
 */
function lps_trust_seed_language( int $post_id, string $locale ): void {
	if ( 0 < $post_id && function_exists( 'pll_set_post_language' ) ) {
		pll_set_post_language( $post_id, $locale );
	}
}

/** Reports whether this database still needs the current seed revision. */
function lps_trust_seed_is_pending(): bool {
	return LPS_TRUST_SEED_VERSION !== get_option( LPS_TRUST_SEED_OPTION, '' );
}

/**
 * Publishes one trust record.
 *
 * @param string               $post_type Record type.
 * @param string               $locale    Locale slug.
 * @param array<string, mixed> $record    Record fields.
 */
function lps_trust_seed_record( string $post_type, string $locale, array $record ): int {
	$existing = get_page_by_path( $record['slug'], OBJECT, $post_type );
	if ( $existing instanceof WP_Post ) {
		foreach ( array_merge( array( '_lps_locale' => $locale ), $record['meta'] ?? array() ) as $key => $value ) {
			update_post_meta( $existing->ID, $key, $value );
		}
		lps_trust_seed_language( $existing->ID, $locale );
		return $existing->ID;
	}
	$id = wp_insert_post(
		array(
			'post_type'    => $post_type,
			'post_status'  => 'publish',
			'post_title'   => $record['title'],
			'post_name'    => $record['slug'],
			'post_excerpt' => $record['summary'],
			'post_content' => $record['body'] ?? $record['summary'],
			'meta_input'   => array_merge( array( '_lps_locale' => $locale ), $record['meta'] ?? array() ),
		),
		true
	);
	if ( is_wp_error( $id ) ) {
		return 0;
	}
	lps_trust_seed_language( $id, $locale );
	return $id;
}

/**
 * Publishes a bilingual opportunity pair atomically.
 *
 * Opportunities are in the required-English matrix, so the English variant must
 * exist and be associated before either locale can leave draft.
 *
 * @param array<string, mixed> $portuguese Portuguese authority record.
 * @param array<string, mixed> $english    English variant record.
 */
function lps_trust_seed_opportunity_pair( array $portuguese, array $english ): void {
	$pt = lps_trust_seed_draft( 'lps_opportunity', 'pt-br', $portuguese );
	$en = lps_trust_seed_draft( 'lps_opportunity', 'en', $english );
	if ( 0 === $pt || 0 === $en ) {
		lps_trust_seed_incomplete( true );
		return;
	}
	if ( is_wp_error( Translations::associate( $pt, $en ) ) ) {
		// Without the reciprocal association the publish gate would refuse the
		// pair anyway; retry on the next request instead of leaving drafts.
		lps_trust_seed_incomplete( true );
		return;
	}
	update_post_meta( $en, '_lps_reviewed_source_hash', (string) get_post_meta( $en, '_lps_source_hash', true ) );
	wp_update_post(
		array(
			'ID'          => $en,
			'post_status' => 'publish',
		)
	);
	wp_update_post(
		array(
			'ID'          => $pt,
			'post_status' => 'publish',
		)
	);
}

/**
 * Inserts a draft trust record and returns its identifier.
 *
 * @param string               $post_type Record type.
 * @param string               $locale    Locale slug.
 * @param array<string, mixed> $record    Record fields.
 */
function lps_trust_seed_draft( string $post_type, string $locale, array $record ): int {
	$parent = 0;
	if ( isset( $record['parent'] ) && '' !== $record['parent'] ) {
		$parent_post = get_page_by_path( $record['parent'], OBJECT, $post_type );
		$parent      = $parent_post instanceof WP_Post ? $parent_post->ID : 0;
	}
	$existing_id = lps_trust_seed_find( $post_type, $record['slug'], $parent );
	if ( 0 !== $existing_id ) {
		foreach ( array_merge( array( '_lps_locale' => $locale ), $record['meta'] ?? array() ) as $key => $value ) {
			update_post_meta( $existing_id, $key, $value );
		}
		lps_trust_seed_language( $existing_id, $locale );
		return $existing_id;
	}
	$id = wp_insert_post(
		array(
			'post_type'    => $post_type,
			'post_status'  => 'draft',
			'post_parent'  => $parent,
			'post_title'   => $record['title'],
			'post_name'    => $record['slug'],
			'post_excerpt' => $record['summary'],
			'post_content' => $record['body'] ?? $record['summary'],
			'meta_input'   => array_merge( array( '_lps_locale' => $locale ), $record['meta'] ?? array() ),
		),
		true
	);
	if ( is_wp_error( $id ) ) {
		return 0;
	}
	lps_trust_seed_language( $id, $locale );
	return $id;
}

/**
 * Publishes one institutional page pair at its frozen locale paths.
 *
 * @param string               $key        Institutional page key.
 * @param array<string, mixed> $portuguese Portuguese record.
 * @param array<string, mixed> $english    English record.
 */
function lps_trust_seed_page_pair( string $key, array $portuguese, array $english ): void {
	$pt = lps_trust_seed_draft( 'page', 'pt-br', $portuguese );
	$en = lps_trust_seed_draft( 'page', 'en', $english );
	if ( 0 === $pt || 0 === $en ) {
		lps_trust_seed_incomplete( true );
		return;
	}
	if ( is_wp_error( Translations::associate( $pt, $en ) ) ) {
		lps_trust_seed_incomplete( true );
		return;
	}
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

add_action(
	'init',
	static function (): void {
		if ( ! lps_trust_seed_is_pending() ) {
			return;
		}
		if ( ! lps_trust_seed_dependencies_ready() ) {
			return;
		}

		$day = 86400;
		$fmt = static fn( int $offset ): string => gmdate( 'Y-m-d\TH:i:sP', time() + ( $offset * $day ) );

		// Open opportunity with an owned role-contact handoff.
		lps_trust_seed_opportunity_pair(
			array(
				'slug'    => 'bolsa-doutorado-sinais',
				'title'   => 'Bolsa de doutorado em processamento de sinais',
				'summary' => 'Bolsa de doutorado vinculada a projeto de processamento de sinais.',
				'meta'    => array(
					'_lps_opportunity_type'         => 'scholarship',
					'_lps_eligibility'              => 'Mestrado concluído em área correlata.',
					'_lps_application_instructions' => 'Envie histórico escolar e carta de motivação.',
					'_lps_opens_at'                 => $fmt( -10 ),
					'_lps_closes_at'                => $fmt( 30 ),
					'_lps_contact'                  => 'oportunidades@lps.ufrj.br',
					'_lps_contact_is_role'          => true,
				),
			),
			array(
				'slug'    => 'doctoral-scholarship-signal-processing',
				'title'   => 'Doctoral scholarship in signal processing',
				'summary' => 'Doctoral scholarship linked to a signal processing project.',
				'meta'    => array(
					'_lps_opportunity_type'         => 'scholarship',
					'_lps_eligibility'              => 'Completed master degree in a related field.',
					'_lps_application_instructions' => 'Send your transcript and a motivation letter.',
					'_lps_opens_at'                 => $fmt( -10 ),
					'_lps_closes_at'                => $fmt( 30 ),
					'_lps_contact'                  => 'oportunidades@lps.ufrj.br',
					'_lps_contact_is_role'          => true,
				),
			)
		);

		// Recently closed opportunity: stable, still indexable.
		lps_trust_seed_opportunity_pair(
			array(
				'slug'    => 'estagio-encerrado',
				'title'   => 'Estágio em instrumentação encerrado',
				'summary' => 'Processo seletivo de estágio já encerrado.',
				'meta'    => array(
					'_lps_opportunity_type'         => 'internship',
					'_lps_eligibility'              => 'Graduação em andamento.',
					'_lps_application_instructions' => 'Processo encerrado.',
					'_lps_opens_at'                 => $fmt( -60 ),
					'_lps_closes_at'                => $fmt( -10 ),
					'_lps_contact'                  => 'oportunidades@lps.ufrj.br',
					'_lps_contact_is_role'          => true,
				),
			),
			array(
				'slug'    => 'closed-internship',
				'title'   => 'Closed instrumentation internship',
				'summary' => 'Internship selection process already closed.',
				'meta'    => array(
					'_lps_opportunity_type'         => 'internship',
					'_lps_eligibility'              => 'Undergraduate studies in progress.',
					'_lps_application_instructions' => 'Process closed.',
					'_lps_opens_at'                 => $fmt( -60 ),
					'_lps_closes_at'                => $fmt( -10 ),
					'_lps_contact'                  => 'oportunidades@lps.ufrj.br',
					'_lps_contact_is_role'          => true,
				),
			)
		);

		// Long-closed opportunity: stable but noindex past the retention window.
		lps_trust_seed_opportunity_pair(
			array(
				'slug'    => 'bolsa-arquivada',
				'title'   => 'Bolsa arquivada de 2024',
				'summary' => 'Oportunidade mantida apenas para referência histórica.',
				'meta'    => array(
					'_lps_opportunity_type'         => 'scholarship',
					'_lps_eligibility'              => 'Encerrada.',
					'_lps_application_instructions' => 'Encerrada.',
					'_lps_opens_at'                 => $fmt( -400 ),
					'_lps_closes_at'                => $fmt( -200 ),
					'_lps_contact'                  => 'oportunidades@lps.ufrj.br',
					'_lps_contact_is_role'          => true,
				),
			),
			array(
				'slug'    => 'archived-scholarship',
				'title'   => 'Archived 2024 scholarship',
				'summary' => 'Opportunity kept for historical reference only.',
				'meta'    => array(
					'_lps_opportunity_type'         => 'scholarship',
					'_lps_eligibility'              => 'Closed.',
					'_lps_application_instructions' => 'Closed.',
					'_lps_opens_at'                 => $fmt( -400 ),
					'_lps_closes_at'                => $fmt( -200 ),
					'_lps_contact'                  => 'oportunidades@lps.ufrj.br',
					'_lps_contact_is_role'          => true,
				),
			)
		);

		// Upcoming event.
		lps_trust_seed_record(
			'lps_event',
			'pt-br',
			array(
				'slug'    => 'seminario-lps',
				'title'   => 'Seminário LPS de processamento de sinais',
				'summary' => 'Seminário aberto à comunidade acadêmica.',
				'meta'    => array(
					'_lps_starts_at'    => $fmt( 20 ),
					'_lps_ends_at'      => $fmt( 20 ),
					'_lps_event_status' => 'scheduled',
					'_lps_venue'        => 'Auditório do LPS, COPPE/UFRJ',
				),
			)
		);

		// Cancelled event: stable URL, explicit status.
		lps_trust_seed_record(
			'lps_event',
			'pt-br',
			array(
				'slug'    => 'workshop-cancelado',
				'title'   => 'Workshop de instrumentação cancelado',
				'summary' => 'Workshop que não será realizado.',
				'meta'    => array(
					'_lps_starts_at'    => $fmt( 15 ),
					'_lps_ends_at'      => $fmt( 15 ),
					'_lps_event_status' => 'cancelled',
					'_lps_venue'        => 'Auditório do LPS, COPPE/UFRJ',
				),
			)
		);

		lps_trust_seed_record(
			'lps_news',
			'pt-br',
			array(
				'slug'    => 'novo-laboratorio',
				'title'   => 'Novo espaço de laboratório inaugurado',
				'summary' => 'Inauguração do novo espaço experimental do LPS.',
				'meta'    => array(
					'_lps_canonical_date' => $fmt( -5 ),
					'_lps_news_status'    => 'published',
				),
			)
		);

		lps_trust_seed_page_pair(
			'about',
			array(
				'slug'    => 'sobre',
				'title'   => 'Sobre o LPS',
				'summary' => 'O Laboratório de Processamento de Sinais da COPPE/UFRJ.',
				'meta'    => array(
					'_lps_page_key' => 'about',
					'_lps_review_date' => substr( $fmt( -20 ), 0, 10 ),
					'_lps_affiliation' => 'COPPE/UFRJ - Universidade Federal do Rio de Janeiro',
					'_lps_claims' => wp_json_encode(
						array(
							array(
								'statement'   => 'O laboratorio mantem convenios ativos com agencias de fomento.',
								'verified'    => true,
								'source_url'  => 'https://www.ufrj.br/convenios',
								'reviewed_at' => substr( $fmt( -20 ), 0, 10 ),
							),
							array(
								'statement'   => 'ADVERSARIAL PRESTIGE CLAIM WITHOUT SOURCE',
								'verified'    => true,
								'source_url'  => '',
								'reviewed_at' => substr( $fmt( -20 ), 0, 10 ),
							),
							array(
								'statement'   => 'ADVERSARIAL STALE CLAIM',
								'verified'    => true,
								'source_url'  => 'https://www.ufrj.br/historico',
								'reviewed_at' => substr( $fmt( -900 ), 0, 10 ),
							),
						)
					),
					'_lps_location' => 'Cidade Universitaria, Rio de Janeiro, Brasil',
					'_lps_funding' => 'Financiamento CNPq, CAPES e FAPERJ.',
				),
			),
			array(
				'slug'    => 'about',
				'title'   => 'About the LPS',
				'summary' => 'The Signal Processing Laboratory at COPPE/UFRJ.',
				'meta'    => array(
					'_lps_page_key' => 'about',
					'_lps_review_date' => substr( $fmt( -20 ), 0, 10 ),
					'_lps_affiliation' => 'COPPE/UFRJ - Federal University of Rio de Janeiro',
					'_lps_location' => 'Cidade Universitaria, Rio de Janeiro, Brazil',
					'_lps_funding' => 'Funded by CNPq, CAPES and FAPERJ.',
				),
			)
		);

		lps_trust_seed_page_pair(
			'history',
			array(
				'slug'    => 'historia',
				'parent'  => 'sobre',
				'title'   => 'Historia do LPS',
				'summary' => 'Trajetoria do laboratorio.',
				'meta'    => array(
					'_lps_page_key' => 'history',
					'_lps_review_date' => substr( $fmt( -20 ), 0, 10 ),
				),
			),
			array(
				'slug'    => 'history',
				'parent'  => 'about',
				'title'   => 'History of the LPS',
				'summary' => 'Laboratory trajectory.',
				'meta'    => array(
					'_lps_page_key' => 'history',
					'_lps_review_date' => substr( $fmt( -20 ), 0, 10 ),
				),
			)
		);

		lps_trust_seed_page_pair(
			'governance',
			array(
				'slug'    => 'governanca',
				'parent'  => 'sobre',
				'title'   => 'Governanca',
				'summary' => 'Estrutura de governanca do laboratorio.',
				'meta'    => array(
					'_lps_page_key' => 'governance',
					'_lps_review_date' => substr( $fmt( -20 ), 0, 10 ),
					'_lps_governance' => 'Coordenacao academica vinculada ao Programa de Engenharia Eletrica.',
				),
			),
			array(
				'slug'    => 'governance',
				'parent'  => 'about',
				'title'   => 'Governance',
				'summary' => 'Laboratory governance structure.',
				'meta'    => array(
					'_lps_page_key' => 'governance',
					'_lps_review_date' => substr( $fmt( -20 ), 0, 10 ),
					'_lps_governance' => 'Academic coordination under the Electrical Engineering Program.',
				),
			)
		);

		lps_trust_seed_page_pair(
			'collaboration',
			array(
				'slug'    => 'colabore',
				'title'   => 'Colabore com o LPS',
				'summary' => 'Rotas para participar, colaborar e ser parceiro.',
				'meta'    => array(
					'_lps_page_key' => 'collaboration',
					'_lps_journeys' => wp_json_encode(
						array(
							array(
								'key'      => 'join',
								'label'    => 'Participe',
								'contact'  => 'oportunidades@lps.ufrj.br',
								'is_role'  => true,
								'url'      => '',
								'approved' => false,
							),
							array(
								'key'      => 'partner',
								'label'    => 'Seja parceiro',
								'contact'  => '',
								'is_role'  => false,
								'url'      => 'javascript:alert(1)',
								'approved' => true,
							),
						)
					),
					'_lps_review_date' => substr( $fmt( -20 ), 0, 10 ),
				),
			),
			array(
				'slug'    => 'collaborate',
				'title'   => 'Collaborate with the LPS',
				'summary' => 'Routes to join, collaborate and partner.',
				'meta'    => array(
					'_lps_page_key' => 'collaboration',
					'_lps_review_date' => substr( $fmt( -20 ), 0, 10 ),
				),
			)
		);

		lps_trust_seed_page_pair(
			'contact',
			array(
				'slug'    => 'contato',
				'title'   => 'Contato',
				'summary' => 'Contatos institucionais publicos.',
				'meta'    => array(
					'_lps_page_key' => 'contact',
					'_lps_role_contacts' => wp_json_encode(
						array(
							array(
								'role'    => 'Coordenacao',
								'email'   => 'coordenacao@lps.ufrj.br',
								'is_role' => true,
							),
							array(
								'role'    => 'Pesquisadora',
								'email'   => 'ana.pesquisadora@lps.ufrj.br',
								'is_role' => false,
							),
						)
					),
					'_lps_review_date' => substr( $fmt( -20 ), 0, 10 ),
				),
			),
			array(
				'slug'    => 'contact',
				'title'   => 'Contact',
				'summary' => 'Public institutional contacts.',
				'meta'    => array(
					'_lps_page_key' => 'contact',
					'_lps_review_date' => substr( $fmt( -20 ), 0, 10 ),
				),
			)
		);

		lps_trust_seed_page_pair(
			'privacy',
			array(
				'slug'    => 'privacidade',
				'title'   => 'Privacidade',
				'summary' => 'Aviso de privacidade conforme a LGPD.',
				'meta'    => array(
					'_lps_page_key' => 'privacy',
					'_lps_review_date' => substr( $fmt( -20 ), 0, 10 ),
					'_lps_report_contact' => 'privacidade@lps.ufrj.br',
				),
			),
			array(
				'slug'    => 'privacy',
				'title'   => 'Privacy',
				'summary' => 'Privacy notice under the LGPD.',
				'meta'    => array(
					'_lps_page_key' => 'privacy',
					'_lps_review_date' => substr( $fmt( -20 ), 0, 10 ),
					'_lps_report_contact' => 'privacidade@lps.ufrj.br',
				),
			)
		);

		lps_trust_seed_page_pair(
			'accessibility',
			array(
				'slug'    => 'acessibilidade',
				'title'   => 'Acessibilidade',
				'summary' => 'Declaracao de acessibilidade digital.',
				'meta'    => array(
					'_lps_page_key' => 'accessibility',
					'_lps_review_date' => substr( $fmt( -20 ), 0, 10 ),
					'_lps_report_contact' => 'acessibilidade@lps.ufrj.br',
				),
			),
			array(
				'slug'    => 'accessibility',
				'title'   => 'Accessibility',
				'summary' => 'Digital accessibility statement.',
				'meta'    => array(
					'_lps_page_key' => 'accessibility',
					'_lps_review_date' => substr( $fmt( -20 ), 0, 10 ),
					'_lps_report_contact' => 'acessibilidade@lps.ufrj.br',
				),
			)
		);

		// Adversarial: personal (non-role) contact and no approved URL. The publish
		// gate must refuse this record, so it never reaches the public listing.
		lps_trust_seed_opportunity_pair(
			array(
				'slug'    => 'oportunidade-sem-contato',
				'title'   => 'Oportunidade sem contato publico',
				'summary' => 'Registro adversarial sem rota de contato publica.',
				'meta'    => array(
					'_lps_opportunity_type'         => 'scholarship',
					'_lps_eligibility'              => 'Elegibilidade.',
					'_lps_application_instructions' => 'Instrucoes.',
					'_lps_opens_at'                 => $fmt( -5 ),
					'_lps_closes_at'                => $fmt( 25 ),
					'_lps_contact'                  => 'ana.pesquisadora@lps.ufrj.br',
					'_lps_contact_is_role'          => '',
				),
			),
			array(
				'slug'    => 'opportunity-without-contact',
				'title'   => 'Opportunity without public contact',
				'summary' => 'Adversarial record with no public contact route.',
				'meta'    => array(
					'_lps_opportunity_type'         => 'scholarship',
					'_lps_eligibility'              => 'Eligibility.',
					'_lps_application_instructions' => 'Instructions.',
					'_lps_opens_at'                 => $fmt( -5 ),
					'_lps_closes_at'                => $fmt( 25 ),
					'_lps_contact'                  => 'ana.pesquisadora@lps.ufrj.br',
					'_lps_contact_is_role'          => '',
				),
			)
		);

		// Adversarial: Portuguese-only opportunity. Opportunities are in the
		// required-English matrix, so this must not publish in one locale alone.
		lps_trust_seed_draft(
			'lps_opportunity',
			'pt-br',
			array(
				'slug'    => 'oportunidade-so-portugues',
				'title'   => 'Oportunidade apenas em portugues',
				'summary' => 'Registro adversarial sem variante em ingles.',
				'meta'    => array(
					'_lps_opportunity_type'         => 'scholarship',
					'_lps_eligibility'              => 'Elegibilidade.',
					'_lps_application_instructions' => 'Instrucoes.',
					'_lps_opens_at'                 => $fmt( -5 ),
					'_lps_closes_at'                => $fmt( 25 ),
					'_lps_contact'                  => 'oportunidades@lps.ufrj.br',
					'_lps_contact_is_role'          => '1',
				),
			)
		);
		$only_pt = get_page_by_path( 'oportunidade-so-portugues', OBJECT, 'lps_opportunity' );
		if ( $only_pt instanceof WP_Post ) {
			wp_update_post(
				array(
					'ID'          => $only_pt->ID,
					'post_status' => 'publish',
				)
			);
		}

		if ( ! lps_trust_seed_incomplete() ) {
			update_option( LPS_TRUST_SEED_OPTION, LPS_TRUST_SEED_VERSION );
		}
	},
	30
);

add_action(
	'wp_loaded',
	static function (): void {
		if ( lps_trust_seed_is_pending() ) {
			return;
		}
		$rules = get_option( 'rewrite_rules', array() );
		if ( is_array( $rules ) ) {
			foreach ( array_keys( $rules ) as $pattern ) {
				if ( str_contains( (string) $pattern, 'lps_opportunity' ) ) {
					return;
				}
			}
		}
		flush_rewrite_rules( false );
	},
	20
);
