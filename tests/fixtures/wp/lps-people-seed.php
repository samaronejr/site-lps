<?php
/**
 * Local-only people, organization, and infrastructure fixtures.
 *
 * The records are written through the plugin's own PHP entry points so that every
 * capability map, privacy gate, and publish gate still applies exactly as it does
 * for editorial content.
 *
 * @package LPS\Development
 */

declare(strict_types=1);

use LPS\ContentModel\Relationships;
use LPS\ContentModel\Translations;

const LPS_PEOPLE_SEED_VERSION = '10';
const LPS_PEOPLE_SEED_OPTION  = 'lps_people_seed_version';
const LPS_PEOPLE_SEED_LOCK    = 'lps_people_seed_lock';
const LPS_PEOPLE_SEED_QUEUE   = 'lps_people_seed_queue';

/**
 * Removes previously seeded fixture records, including slug-collision variants.
 *
 * WordPress appends a numeric suffix when a slug is taken, so an interrupted
 * seeding run can leave "slug-2" style duplicates behind. Only the fixture slugs
 * listed here are removed; editorial records are never touched.
 *
 * @param string             $post_type Record type.
 * @param array<int, string> $slugs     Fixture slugs.
 */
function lps_people_seed_purge( string $post_type, array $slugs ): void {
	$names = array();
	foreach ( $slugs as $slug ) {
		$names[] = $slug;
		for ( $suffix = 2; $suffix <= 9; $suffix++ ) {
			$names[] = $slug . '-' . $suffix;
		}
	}
	foreach ( get_posts(
		array(
			'post_type'        => $post_type,
			'post_status'      => 'any',
			'numberposts'      => 200,
			'fields'           => 'ids',
			'post_name__in'    => $names,
			'suppress_filters' => false,
		)
	) as $stale ) {
		if ( is_int( $stale ) ) {
			wp_delete_post( $stale, true );
		}
	}
}

/**
 * Tracks whether the current step left an incomplete pair behind.
 *
 * Polylang registers its languages during the same boot in which this fixture
 * first runs, so an early step can insert both variants before the association
 * back end is usable. A step that cannot associate its pair reports itself as
 * incomplete and is retried on the next request instead of leaving unlinked
 * drafts that the bilingual publish gate then refuses forever.
 *
 * @param bool|null $set New state, or null to read the current one.
 */
function lps_people_seed_incomplete( ?bool $set = null ): bool {
	static $incomplete = false;
	if ( null !== $set ) {
		$incomplete = $set;
	}
	return $incomplete;
}

/** Returns whether both supported languages are registered and usable. */
function lps_people_seed_languages_ready(): bool {
	if ( ! function_exists( 'pll_languages_list' ) || ! function_exists( 'pll_save_post_translations' ) ) {
		return false;
	}
	$languages = pll_languages_list();
	if ( ! is_array( $languages ) ) {
		return false;
	}
	return in_array( 'pt-br', $languages, true ) && in_array( 'en', $languages, true );
}

/**
 * Returns the database ID of an already-seeded record, or zero when absent.
 *
 * The seeder is re-entrant: a boot that fails halfway is retried on the next
 * request without creating duplicate records.
 *
 * @param string $post_type Record type.
 * @param string $slug      Record slug.
 */
function lps_people_seed_existing( string $post_type, string $slug ): int {
	$found = get_posts(
		array(
			'post_type'        => $post_type,
			'name'             => $slug,
			'post_status'      => 'any',
			'numberposts'      => 1,
			'fields'           => 'ids',
			'suppress_filters' => false,
		)
	);
	return isset( $found[0] ) && is_int( $found[0] ) ? $found[0] : 0;
}

/**
 * Publishes one bilingual person, organization, or page pair.
 *
 * @param string               $post_type Record type.
 * @param array<string, mixed> $source    Portuguese authority record.
 * @param array<string, mixed> $target    English variant record.
 * @return array{pt-br: int, en: int}
 */
function lps_people_seed_pair( string $post_type, array $source, array $target ): array {
	$existing_pt = lps_people_seed_existing( $post_type, $source['slug'] );
	$existing_en = lps_people_seed_existing( $post_type, $target['slug'] );
	if ( 0 !== $existing_pt && 0 !== $existing_en ) {
		return array(
			'pt-br' => $existing_pt,
			'en'    => $existing_en,
		);
	}
	$portuguese = wp_insert_post(
		array(
			'post_type'    => $post_type,
			'post_status'  => 'draft',
			'post_title'   => $source['title'],
			'post_name'    => $source['slug'],
			'post_excerpt' => $source['summary'] ?? '',
			'post_content' => $source['body'] ?? '',
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
			'post_excerpt' => $target['summary'] ?? '',
			'post_content' => $target['body'] ?? '',
			'meta_input'   => array_merge( array( '_lps_locale' => 'en' ), $target['meta'] ?? $source['meta'] ?? array() ),
		),
		true
	);
	if ( is_wp_error( $portuguese ) || is_wp_error( $english ) ) {
		lps_people_seed_incomplete( true );
		return array(
			'pt-br' => 0,
			'en'    => 0,
		);
	}
	$association = Translations::associate( $portuguese, $english );
	if ( is_wp_error( $association ) ) {
		// Remove the half-built pair so the retried step starts from a clean slate.
		wp_delete_post( $portuguese, true );
		wp_delete_post( $english, true );
		lps_people_seed_incomplete( true );
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
function lps_people_seed_publish( array $pair ): void {
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
	foreach ( array( $pair['en'], $pair['pt-br'] ) as $variant ) {
		if ( 'publish' !== get_post_status( $variant ) ) {
			lps_people_seed_incomplete( true );
		}
	}
}

/**
 * Returns the requested publication state of one fixture definition.
 *
 * @param array<string, mixed> $definition Fixture definition.
 */
function lps_people_seed_requested_state( array $definition ): string {
	$meta  = isset( $definition['meta'] ) && is_array( $definition['meta'] ) ? $definition['meta'] : array();
	$state = $meta['_lps_state'] ?? '';
	return is_string( $state ) ? $state : '';
}

/**
 * Archives a published pair through the plugin archival path.
 *
 * The plugin converts a prohibited hard delete of a published record into an
 * archival transition, which is the only supported way to reach the archived
 * state from outside the editor.
 *
 * @param array{pt-br: int, en: int} $pair Published record pair.
 */
function lps_people_seed_archive( array $pair ): void {
	foreach ( array( $pair['en'], $pair['pt-br'] ) as $variant ) {
		if ( 0 === $variant || 'lps_archived' === get_post_status( $variant ) ) {
			continue;
		}
		wp_delete_post( $variant );
		if ( 'lps_archived' !== get_post_status( $variant ) ) {
			lps_people_seed_incomplete( true );
		}
	}
}

/**
 * Returns the person fixture matrix.
 *
 * @return array<string, array{pt: array<string, mixed>, en: array<string, mixed>, meta: array<string, mixed>}>
 */
function lps_people_seed_matrix(): array {
	return array(
		'professor'     => array(
			'pt'   => array(
				'title'   => 'Ana Álvares (fixture de QA)',
				'slug'    => 'ana-alvares-fixture',
				'summary' => 'Registro de fixture local de Ana Álvares (fixture de QA) usado para validar as superfícies públicas.',
				'body'    => 'Conteúdo de fixture. Não representa uma afirmação institucional.',
			),
			'en'   => array(
				'title'   => 'Ana Álvares (QA fixture)',
				'slug'    => 'ana-alvares-fixture-en',
				'summary' => 'Local fixture record for Ana Álvares (QA fixture) used to validate the public surfaces.',
				'body'    => 'Fixture content. It states no institutional claim.',
			),
			'meta' => array(
				'_lps_canonical_name'    => 'Ana Álvares',
				'_lps_person_status'     => 'active',
				'_lps_roles'             => array( 'professor', 'researcher' ),
				'_lps_research_area_ids' => array( 'signal-processing' ),
				'_lps_public_email'      => 'ana.publica@example.org',
				'_lps_private_email'     => 'ana.privada@example.org',
				'_lps_home_address'      => 'Rua Privada 10',
				'_lps_privacy_reviewed'  => '1',
				'_lps_photo_url'         => '/wp-content/uploads/ana-fixture.jpg',
				'_lps_photo_rights'      => 'cleared',
				'_lps_photo_alt'         => 'Retrato de fixture.',
				'_lps_orcid'             => '0000-0002-1825-0097',
				'_lps_start_date'        => '2015-03-01',
			),
		),
		'homograph'     => array(
			'pt'   => array(
				'title'   => 'Ana Alvares (fixture de QA)',
				'slug'    => 'ana-alvares-2-fixture',
				'summary' => 'Registro de fixture local de Ana Alvares (fixture de QA) usado para validar as superfícies públicas.',
				'body'    => 'Conteúdo de fixture. Não representa uma afirmação institucional.',
			),
			'en'   => array(
				'title'   => 'Ana Alvares (QA fixture)',
				'slug'    => 'ana-alvares-2-fixture-en',
				'summary' => 'Local fixture record for Ana Alvares (QA fixture) used to validate the public surfaces.',
				'body'    => 'Fixture content. It states no institutional claim.',
			),
			'meta' => array(
				'_lps_canonical_name'   => 'Ana Alvares',
				'_lps_person_status'    => 'active',
				'_lps_roles'            => array( 'student' ),
				'_lps_privacy_reviewed' => '0',
				'_lps_public_email'     => 'nao.revisado@example.org',
				'_lps_private_email'    => 'privado.oculto@example.org',
				'_lps_photo_url'        => 'https://untrusted.example/foto.jpg',
				'_lps_photo_rights'     => 'unknown',
			),
		),
		'student'       => array(
			'pt'   => array(
				'title'   => 'Estudante de fixture',
				'slug'    => 'estudante-fixture',
				'summary' => 'Registro de fixture local de Estudante de fixture usado para validar as superfícies públicas.',
				'body'    => 'Conteúdo de fixture. Não representa uma afirmação institucional.',
			),
			'en'   => array(
				'title'   => 'Fixture student',
				'slug'    => 'fixture-student',
				'summary' => 'Local fixture record for Fixture student used to validate the public surfaces.',
				'body'    => 'Fixture content. It states no institutional claim.',
			),
			'meta' => array(
				'_lps_canonical_name'    => 'Estudante de fixture',
				'_lps_person_status'     => 'active',
				'_lps_roles'             => array( 'student' ),
				'_lps_research_area_ids' => array( 'signal-processing' ),
				'_lps_privacy_reviewed'  => '1',
			),
		),
		'technical'     => array(
			'pt'   => array(
				'title'   => 'Equipe técnica de fixture',
				'slug'    => 'equipe-tecnica-fixture',
				'summary' => 'Registro de fixture local de Equipe técnica de fixture usado para validar as superfícies públicas.',
				'body'    => 'Conteúdo de fixture. Não representa uma afirmação institucional.',
			),
			'en'   => array(
				'title'   => 'Fixture technical staff',
				'slug'    => 'fixture-technical-staff',
				'summary' => 'Local fixture record for Fixture technical staff used to validate the public surfaces.',
				'body'    => 'Fixture content. It states no institutional claim.',
			),
			'meta' => array(
				'_lps_canonical_name'   => 'Equipe técnica de fixture',
				'_lps_person_status'    => 'active',
				'_lps_roles'            => array( 'technical-staff' ),
				'_lps_privacy_reviewed' => '1',
				'_lps_public_email'     => 'infra.publica@example.org',
			),
		),
		'external'      => array(
			'pt'   => array(
				'title'   => 'Colaborador Externo de fixture',
				'slug'    => 'colaborador-externo-fixture',
				'summary' => 'Registro de fixture local de Colaborador Externo de fixture usado para validar as superfícies públicas.',
				'body'    => 'Conteúdo de fixture. Não representa uma afirmação institucional.',
			),
			'en'   => array(
				'title'   => 'Fixture external collaborator',
				'slug'    => 'fixture-external-collaborator',
				'summary' => 'Local fixture record for Fixture external collaborator used to validate the public surfaces.',
				'body'    => 'Fixture content. It states no institutional claim.',
			),
			'meta' => array(
				'_lps_canonical_name'   => 'Colaborador Externo de fixture',
				'_lps_person_status'    => 'active',
				'_lps_roles'            => array( 'external-collaborator' ),
				'_lps_affiliations'     => array( 'Universidade Parceira' ),
				'_lps_privacy_reviewed' => '1',
			),
		),
		'alumni'        => array(
			'pt'   => array(
				'title'   => 'Pessoa Egressa de fixture',
				'slug'    => 'pessoa-egressa-fixture',
				'summary' => 'Registro de fixture local de Pessoa Egressa de fixture usado para validar as superfícies públicas.',
				'body'    => 'Conteúdo de fixture. Não representa uma afirmação institucional.',
			),
			'en'   => array(
				'title'   => 'Fixture alumni member',
				'slug'    => 'fixture-alumni-member',
				'summary' => 'Local fixture record for Fixture alumni member used to validate the public surfaces.',
				'body'    => 'Fixture content. It states no institutional claim.',
			),
			'meta' => array(
				'_lps_canonical_name'   => 'Pessoa Egressa de fixture',
				'_lps_person_status'    => 'alumni',
				'_lps_roles'            => array( 'researcher', 'alumni' ),
				'_lps_privacy_reviewed' => '1',
				'_lps_start_date'       => '2016-02-01',
				'_lps_end_date'         => '2023-12-31',
			),
		),
		'memoriam'      => array(
			'pt'   => array(
				'title'   => 'Pessoa Homenageada de fixture',
				'slug'    => 'pessoa-homenageada-fixture',
				'summary' => 'Registro de fixture local de Pessoa Homenageada de fixture usado para validar as superfícies públicas.',
				'body'    => 'Conteúdo de fixture. Não representa uma afirmação institucional.',
			),
			'en'   => array(
				'title'   => 'Fixture in-memoriam member',
				'slug'    => 'fixture-in-memoriam-member',
				'summary' => 'Local fixture record for Fixture in-memoriam member used to validate the public surfaces.',
				'body'    => 'Fixture content. It states no institutional claim.',
			),
			'meta' => array(
				'_lps_canonical_name'       => 'Pessoa Homenageada de fixture',
				'_lps_person_status'        => 'in-memoriam',
				'_lps_roles'                => array( 'professor' ),
				'_lps_privacy_reviewed'     => '1',
				'_lps_in_memoriam_approved' => '1',
			),
		),
		'memoriam_hold' => array(
			'pt'   => array(
				'title'   => 'Pessoa Sem Consentimento de fixture',
				'slug'    => 'pessoa-sem-consentimento-fixture',
				'summary' => 'Registro de fixture local de Pessoa Sem Consentimento de fixture usado para validar as superfícies públicas.',
				'body'    => 'Conteúdo de fixture. Não representa uma afirmação institucional.',
			),
			'en'   => array(
				'title'   => 'Fixture member awaiting consent',
				'slug'    => 'fixture-member-awaiting-consent',
				'summary' => 'Local fixture record for Fixture member awaiting consent used to validate the public surfaces.',
				'body'    => 'Fixture content. It states no institutional claim.',
			),
			'meta' => array(
				'_lps_canonical_name'   => 'Pessoa Sem Consentimento de fixture',
				'_lps_person_status'    => 'in-memoriam',
				'_lps_roles'            => array( 'professor' ),
				'_lps_privacy_reviewed' => '1',
			),
		),
		'archived'      => array(
			'pt'   => array(
				'title'   => 'Pessoa Arquivada de fixture',
				'slug'    => 'pessoa-arquivada-publica-fixture',
				'summary' => 'Registro de fixture local de Pessoa Arquivada de fixture usado para validar as superfícies públicas.',
				'body'    => 'Conteúdo de fixture. Não representa uma afirmação institucional.',
			),
			'en'   => array(
				'title'   => 'Fixture archived member',
				'slug'    => 'fixture-archived-member',
				'summary' => 'Local fixture record for Fixture archived member used to validate the public surfaces.',
				'body'    => 'Fixture content. It states no institutional claim.',
			),
			'meta' => array(
				'_lps_canonical_name'   => 'Pessoa Arquivada de fixture',
				'_lps_person_status'    => 'active',
				'_lps_roles'            => array( 'researcher' ),
				'_lps_privacy_reviewed' => '1',
				'_lps_state'            => 'archived',
			),
		),
	);
}

/**
 * Returns the infrastructure page body carrying the public surface block.
 */
function lps_people_seed_infrastructure_body(): string {
	return '<!-- wp:lps-theme/public-surfaces {"lock":{"move":true,"remove":true}} /-->';
}

/**
 * Returns the organization fixture definitions.
 *
 * @return array<string, array{pt: array<string, mixed>, en: array<string, mixed>}>
 */
function lps_people_seed_organizations(): array {
	$pt_summary = static fn ( string $name ): string => sprintf( 'Registro de fixture local de %s usado para validar as superfícies públicas.', $name );
	$pt_body    = 'Conteúdo de fixture. Não representa uma afirmação institucional.';
	$en_summary = static fn ( string $name ): string => sprintf( 'Local fixture record for %s used to validate the public surfaces.', $name );
	$en_body    = 'Fixture content. It states no institutional claim.';

	return array(
		'partner'        => array(
			'pt' => array(
				'title'   => 'Parceiro Público de fixture',
				'slug'    => 'parceiro-publico-fixture',
				'summary' => $pt_summary( 'Parceiro Público de fixture' ),
				'body'    => $pt_body,
				'meta'    => array(
					'_lps_organization_name' => 'Parceiro Público de fixture',
					'_lps_organization_kind' => 'partner',
					'_lps_country_code'      => 'BR',
					'_lps_public_profile'    => '1',
					'_lps_canonical_url'     => 'https://parceiro.example/',
					'_lps_logo_url'          => '/wp-content/uploads/parceiro-fixture.svg',
					'_lps_logo_rights'       => 'unknown',
				),
			),
			'en' => array(
				'title'   => 'Fixture public partner',
				'slug'    => 'fixture-public-partner',
				'summary' => $en_summary( 'Fixture public partner' ),
				'body'    => $en_body,
			),
		),
		'hidden_partner' => array(
			'pt' => array(
				'title'   => 'Parceiro Oculto de fixture',
				'slug'    => 'parceiro-oculto-fixture',
				'summary' => $pt_summary( 'Parceiro Oculto de fixture' ),
				'body'    => $pt_body,
				'meta'    => array(
					'_lps_organization_name' => 'Parceiro Oculto de fixture',
					'_lps_organization_kind' => 'partner',
					'_lps_country_code'      => 'BR',
					'_lps_public_profile'    => '0',
					'_lps_canonical_url'     => 'https://oculto.example/',
					'_lps_logo_url'          => '/wp-content/uploads/oculto-fixture.svg',
					'_lps_logo_rights'       => 'cleared',
				),
			),
			'en' => array(
				'title'   => 'Fixture hidden partner',
				'slug'    => 'fixture-hidden-partner',
				'summary' => $en_summary( 'Fixture hidden partner' ),
				'body'    => $en_body,
			),
		),
	);
}

/**
 * Returns the infrastructure fixture definitions per locale.
 *
 * @return array<string, array<string, mixed>>
 */
function lps_people_seed_facilities(): array {
	return array(
		'pt-br' => array(
			'page'      => array(
				'title' => 'Infraestrutura',
				'slug'  => 'infraestrutura',
			),
			'facility'  => array(
				'title' => 'Laboratório de sinais (fixture de QA)',
				'slug'  => 'laboratorio-de-sinais-fixture',
			),
			'summary'   => 'Página de fixture local que descreve a infraestrutura publicada.',
			'claim'     => 'Suporta experimentos reprodutíveis de processamento de sinais.',
			'unsourced' => 'O laboratório mais rápido do Brasil.',
			'equipment' => 'Bancada de aquisição de fixture',
			'research'  => 'Processamento de sinais (fixture de QA)',
			'project'   => 'Projeto de demonstração (fixture de QA)',
			'contact'   => 'Equipe técnica de fixture',
			'links'     => array(
				'equipment' => '/pt-br/infraestrutura/',
				'research'  => '/pt-br/pesquisa/processamento-de-sinais-fixture/',
				'project'   => '/pt-br/projetos/projeto-demonstracao-fixture/',
				'contact'   => '/pt-br/pessoas/equipe-tecnica-fixture/',
			),
		),
		'en'    => array(
			'page'      => array(
				'title' => 'Infrastructure',
				'slug'  => 'infrastructure',
			),
			'facility'  => array(
				'title' => 'Signal laboratory (QA fixture)',
				'slug'  => 'signal-laboratory-fixture',
			),
			'summary'   => 'Local fixture page describing the published infrastructure.',
			'claim'     => 'Supports reproducible signal-processing experiments.',
			'unsourced' => 'The fastest laboratory in Brazil.',
			'equipment' => 'Fixture acquisition bench',
			'research'  => 'Signal processing (QA fixture)',
			'project'   => 'Demonstration project (QA fixture)',
			'contact'   => 'Fixture technical staff',
			'links'     => array(
				'equipment' => '/en/infrastructure/',
				'research'  => '/en/research/signal-processing-fixture/',
				'project'   => '/en/projects/demonstration-project-fixture/',
				'contact'   => '/en/people/fixture-technical-staff/',
			),
		),
	);
}

/**
 * Returns the ordered seeding steps.
 *
 * @return array<int, string>
 */
function lps_people_seed_steps(): array {
	$steps = array( 'purge' );
	foreach ( array_keys( lps_people_seed_matrix() ) as $key ) {
		$steps[] = 'person:' . $key;
	}
	foreach ( array_keys( lps_people_seed_organizations() ) as $key ) {
		$steps[] = 'organization:' . $key;
	}
	$steps[] = 'history';
	$steps[] = 'infrastructure';
	return $steps;
}

/** Removes every fixture record this seeder owns. */
function lps_people_seed_purge_all(): void {
	$person_slugs = array();
	foreach ( lps_people_seed_matrix() as $definition ) {
		$person_slugs[] = $definition['pt']['slug'];
		$person_slugs[] = $definition['en']['slug'];
	}
	lps_people_seed_purge( 'lps_person', $person_slugs );

	$organization_slugs = array();
	foreach ( lps_people_seed_organizations() as $definition ) {
		$organization_slugs[] = $definition['pt']['slug'];
		$organization_slugs[] = $definition['en']['slug'];
	}
	lps_people_seed_purge( 'lps_organization', $organization_slugs );

	$page_slugs = array();
	foreach ( lps_people_seed_facilities() as $definition ) {
		$page_slugs[] = $definition['page']['slug'];
		$page_slugs[] = $definition['facility']['slug'];
	}
	lps_people_seed_purge( 'page', $page_slugs );
}

/**
 * Links the alumni fixture to the seeded project so departure keeps its history.
 *
 * @param array<string, mixed> $report Seeding report.
 */
function lps_people_seed_history( array $report ): void {
	$discovery = get_option( 'lps_discovery_seed_report' );
	$project   = is_array( $discovery ) && isset( $discovery['project']['pt-br'] ) ? (int) $discovery['project']['pt-br'] : 0;
	$alumni    = isset( $report['alumni']['pt-br'] ) ? (int) $report['alumni']['pt-br'] : 0;
	if ( 0 === $project || 0 === $alumni ) {
		return;
	}
	$rows = array();
	foreach ( Relationships::for_source( $project, 'project_member' ) as $index => $row ) {
		if ( $alumni === $row['target_post_id'] ) {
			return;
		}
		$rows[] = array(
			'target_post_id'    => $row['target_post_id'],
			'relationship_role' => $row['relationship_role'],
			'sort_order'        => $index + 1,
		);
	}
	$rows[] = array(
		'target_post_id'    => $alumni,
		'relationship_role' => 'member',
		'sort_order'        => count( $rows ) + 1,
	);
	Relationships::replace( $project, 'project_member', $rows );
}

/**
 * Returns the stored metadata of one locale facility record.
 *
 * @param string               $locale     Supported locale slug.
 * @param array<string, mixed> $definition Facility definition.
 * @return array<string, mixed>
 */
function lps_people_seed_facility_meta( string $locale, array $definition ): array {
	unset( $locale );

	return array(
		'_lps_page_key'          => 'infrastructure-facility',
		'_lps_capability_claims' => array(
			array(
				'text'        => $definition['claim'],
				'source_url'  => 'https://www.coppe.ufrj.br/pt-br/laboratorios',
				'reviewed_at' => '2026-08-20',
			),
			array(
				'text'       => $definition['unsourced'],
				'source_url' => '',
			),
		),
		'_lps_equipment_links'   => array(
			array(
				'title' => $definition['equipment'],
				'url'   => $definition['links']['equipment'],
			),
		),
		'_lps_research_links'    => array(
			array(
				'title' => $definition['research'],
				'url'   => $definition['links']['research'],
			),
		),
		'_lps_project_links'     => array(
			array(
				'title' => $definition['project'],
				'url'   => $definition['links']['project'],
			),
		),
		'_lps_contact_links'     => array(
			array(
				'title' => $definition['contact'],
				'url'   => $definition['links']['contact'],
			),
		),
	);
}

/**
 * Creates the bilingual infrastructure page and its facility record.
 *
 * Both locales are associated through the plugin translation API before they are
 * published, because the infrastructure page is on the required-English matrix
 * and the bilingual publish gate refuses a lone variant.
 *
 * @return array<string, array{pt-br: int, en: int}>
 */
function lps_people_seed_infrastructure(): array {
	$definitions = lps_people_seed_facilities();
	$portuguese  = $definitions['pt-br'];
	$english     = $definitions['en'];

	$page = lps_people_seed_pair(
		'page',
		array(
			'title'   => $portuguese['page']['title'],
			'slug'    => $portuguese['page']['slug'],
			'summary' => $portuguese['summary'],
			'body'    => lps_people_seed_infrastructure_body(),
			'meta'    => array( '_lps_page_key' => 'infrastructure' ),
		),
		array(
			'title'   => $english['page']['title'],
			'slug'    => $english['page']['slug'],
			'summary' => $english['summary'],
			'body'    => lps_people_seed_infrastructure_body(),
			'meta'    => array( '_lps_page_key' => 'infrastructure' ),
		)
	);
	lps_people_seed_publish( $page );

	$facility = lps_people_seed_pair(
		'page',
		array(
			'title'   => $portuguese['facility']['title'],
			'slug'    => $portuguese['facility']['slug'],
			'summary' => $portuguese['summary'],
			'body'    => $portuguese['summary'],
			'meta'    => lps_people_seed_facility_meta( 'pt-br', $portuguese ),
		),
		array(
			'title'   => $english['facility']['title'],
			'slug'    => $english['facility']['slug'],
			'summary' => $english['summary'],
			'body'    => $english['summary'],
			'meta'    => lps_people_seed_facility_meta( 'en', $english ),
		)
	);
	lps_people_seed_publish( $facility );

	return array(
		'page'     => $page,
		'facility' => $facility,
	);
}

/**
 * Runs one seeding step.
 *
 * Seeding advances one step per request because a single boot request is not a
 * reliable unit of work in this runtime; the queue makes the run resumable.
 *
 * @param string               $step   Step identifier.
 * @param array<string, mixed> $report Seeding report so far.
 * @return array<string, mixed>
 */
function lps_people_seed_run_step( string $step, array $report ): array {
	if ( 'purge' === $step ) {
		lps_people_seed_purge_all();
		return $report;
	}
	if ( 'history' === $step ) {
		lps_people_seed_history( $report );
		return $report;
	}
	if ( 'infrastructure' === $step ) {
		$report['infrastructure'] = lps_people_seed_infrastructure();
		return $report;
	}
	$parts = explode( ':', $step, 2 );
	$name  = $parts[1] ?? '';
	if ( 'person' === $parts[0] ) {
		$definition = lps_people_seed_matrix()[ $name ] ?? null;
		if ( null === $definition ) {
			return $report;
		}
		$pair = lps_people_seed_pair(
			'lps_person',
			array_merge( $definition['pt'], array( 'meta' => $definition['meta'] ) ),
			array_merge( $definition['en'], array( 'meta' => $definition['meta'] ) )
		);
		lps_people_seed_publish( $pair );
		if ( 'archived' === lps_people_seed_requested_state( $definition ) ) {
			lps_people_seed_archive( $pair );
		}
		$report[ $name ] = $pair;
		return $report;
	}
	if ( 'organization' === $parts[0] ) {
		$definition = lps_people_seed_organizations()[ $name ] ?? null;
		if ( null === $definition ) {
			return $report;
		}
		$pair = lps_people_seed_pair( 'lps_organization', $definition['pt'], $definition['en'] );
		lps_people_seed_publish( $pair );
		$report[ $name ] = $pair;
		return $report;
	}
	return $report;
}

/** Advances the public-surface fixtures by one step per request. */
function lps_people_seed_fixtures(): void {
	if ( LPS_PEOPLE_SEED_VERSION === get_option( LPS_PEOPLE_SEED_OPTION ) ) {
		return;
	}
	if ( ! class_exists( Relationships::class ) || ! class_exists( Translations::class ) ) {
		return;
	}
	if ( ! lps_people_seed_languages_ready() ) {
		return;
	}

	// One request at a time advances the queue; a claim older than sixty seconds
	// is treated as abandoned so an interrupted request still heals.
	$claimed = (int) get_option( LPS_PEOPLE_SEED_LOCK, 0 );
	if ( 0 !== $claimed && 60 > ( time() - $claimed ) ) {
		return;
	}
	update_option( LPS_PEOPLE_SEED_LOCK, time() );

	$queue = get_option( LPS_PEOPLE_SEED_QUEUE );
	if ( ! is_array( $queue ) ) {
		$queue = lps_people_seed_steps();
	}
	$report = get_option( 'lps_people_seed_report' );
	$report = is_array( $report ) ? $report : array();

	$step = array_shift( $queue );
	if ( ! is_string( $step ) ) {
		update_option( LPS_PEOPLE_SEED_OPTION, LPS_PEOPLE_SEED_VERSION );
		delete_option( LPS_PEOPLE_SEED_LOCK );
		flush_rewrite_rules( false );
		return;
	}

	lps_people_seed_incomplete( false );
	$report = lps_people_seed_run_step( $step, $report );
	if ( lps_people_seed_incomplete() ) {
		// The step could not complete its bilingual pair; retry it on the next request.
		array_unshift( $queue, $step );
	}

	update_option( 'lps_people_seed_report', $report );
	update_option( LPS_PEOPLE_SEED_QUEUE, $queue );
	delete_option( LPS_PEOPLE_SEED_LOCK );
}

add_action(
	'init',
	static function (): void {
		$rules = get_option( 'rewrite_rules' );
		if ( ! is_array( $rules ) || ! isset( $rules['pt-br/pessoas/?$'] ) ) {
			flush_rewrite_rules( false );
		}
		lps_people_seed_fixtures();
	},
	110
);
