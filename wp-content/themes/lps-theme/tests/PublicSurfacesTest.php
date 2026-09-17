<?php
/**
 * People, organization, and infrastructure surface contracts.
 *
 * @package LPS\Theme\Tests
 */

declare(strict_types=1);

namespace LPS\Theme\Tests;

use LPS\Theme\PublicSurfaces;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/includes/class-publicsurfaces.php';

final class PublicSurfacesTest extends TestCase {
	/** @return array<int, array<string, mixed>> */
	private function people(): array {
		return array(
			array(
				'id'               => 11,
				'slug'             => 'ana-alvares',
				'name'             => 'Ana Álvares',
				'roles'            => array( 'student' ),
				'status'           => 'active',
				'areas'            => array( 'signal-processing' ),
				'summary'          => 'Pesquisa processamento de sinais.',
				'public_email'     => 'ana.publica@example.org',
				'private_email'    => 'ana.private@example.org',
				'privacy_reviewed' => true,
				'photo_url'        => '/uploads/ana.jpg',
				'photo_rights'     => 'cleared',
				'photo_alt'        => 'Ana no laboratório.',
			),
			array(
				'id'               => 12,
				'slug'             => 'ana-alvares-2',
				'name'             => 'Ana Alvares',
				'roles'            => array( 'external-collaborator' ),
				'status'           => 'alumni',
				'areas'            => array( 'software-engineering' ),
				'public_email'     => 'must-not-leak@example.org',
				'private_email'    => 'never@example.org',
				'privacy_reviewed' => false,
				'photo_url'        => 'https://untrusted.example/photo.jpg',
				'photo_rights'     => 'unknown',
			),
		);
	}

	/** A declared portrait that is not present renders the published fallback, never a broken image. */
	public function test_portrait_missing_from_disk_renders_the_no_photo_fallback(): void {
		$person = array(
			'name'             => 'Pessoa de Teste',
			'slug'             => 'pessoa-de-teste',
			'published'        => true,
			'privacy_reviewed' => true,
			'photo_url'        => '/wp-content/uploads/missing-portrait.jpg',
			'photo_rights'     => 'cleared',
			'photo_alt'        => 'Retrato.',
			'photo_present'    => false,
		);

		$html = PublicSurfaces::person_profile( 'pt-br', $person );

		self::assertStringNotContainsString( '<img', $html );
		self::assertStringContainsString( 'Foto não publicada', $html );
	}

	/** A portrait that is present still renders as an image with its alt text. */
	public function test_portrait_present_on_disk_still_renders(): void {
		$person = array(
			'name'             => 'Pessoa de Teste',
			'slug'             => 'pessoa-de-teste',
			'published'        => true,
			'privacy_reviewed' => true,
			'photo_url'        => '/wp-content/uploads/present-portrait.jpg',
			'photo_rights'     => 'cleared',
			'photo_alt'        => 'Retrato.',
			'photo_present'    => true,
		);

		$html = PublicSurfaces::person_profile( 'pt-br', $person );

		self::assertStringContainsString( '<img class="lps-person-photo" src="/wp-content/uploads/present-portrait.jpg" alt="Retrato.">', $html );
	}

	/** A record heading is an H1 only where the record owns the page. */
	public function test_organization_heading_level_follows_its_context(): void {
		$public = array(
			'name'           => 'Parceiro Público',
			'slug'           => 'parceiro-publico',
			'public_profile' => true,
			'summary'        => 'Instituição parceira.',
		);

		// Given: the same record rendered as its own page and inside a listing.
		$detail  = PublicSurfaces::organization_profile( 'pt-br', $public );
		$listing = PublicSurfaces::organization_profile( 'pt-br', $public, 2 );

		// Then: the detail page titles itself at H1 and the listing entry stays at H2.
		self::assertStringContainsString( '<h1>Parceiro Público</h1>', $detail );
		self::assertStringNotContainsString( '<h1>', $listing );
		self::assertStringContainsString( '<h2>Parceiro Público</h2>', $listing );
	}

	/** The people filter submits through the token-driven button primitive. */
	public function test_people_filter_submit_uses_the_token_button_primitive(): void {
		// Given: the people listing rendered with its filter form.
		$html = PublicSurfaces::people_listing( 'pt-br', $this->people(), array() );

		// Then: the submit control is the design-system button, not an unstyled native default.
		self::assertStringContainsString( '<button class="lps-button lps-button-primary" type="submit">', $html );
	}

	public function test_people_facets_preserve_distinct_cohorts_and_stable_urls(): void {
		$html = PublicSurfaces::people_listing(
			'pt-br',
			$this->people(),
			array(
				'role'   => array( 'student' ),
				'status' => array( 'active' ),
				'area'   => array( 'signal-processing' ),
			)
		);

		self::assertStringContainsString( 'name="role[]"', $html );
		self::assertStringContainsString( 'name="status[]"', $html );
		self::assertStringContainsString( 'name="area[]"', $html );
		self::assertStringContainsString( '/pt-br/pessoas/ana-alvares/', $html );
		self::assertStringContainsString( 'Estudante', $html );
		self::assertStringNotContainsString( 'ana-alvares-2', $html );
	}

	public function test_profile_exposes_only_reviewed_public_contact_and_rights_cleared_local_photo(): void {
		$public  = PublicSurfaces::person_profile( 'pt-br', $this->people()[0] );
		$private = PublicSurfaces::person_profile( 'pt-br', $this->people()[1] );

		self::assertStringContainsString( 'ana.publica@example.org', $public );
		self::assertStringContainsString( 'src="/uploads/ana.jpg"', $public );
		self::assertStringNotContainsString( 'ana.private@example.org', $public );
		self::assertStringNotContainsString( 'must-not-leak@example.org', $private );
		self::assertStringNotContainsString( 'never@example.org', $private );
		self::assertStringNotContainsString( 'untrusted.example', $private );
		self::assertStringContainsString( 'Foto não publicada', $private );
		self::assertStringContainsString( 'Colaboração externa', $private );
		self::assertStringContainsString( 'Egresso', $private );
	}

	public function test_identifiers_must_validate_before_rendering(): void {
		$person = $this->people()[0] + array(
			'orcid'      => '0000-0002-1825-0097',
			'lattes_url' => 'http://lattes.cnpq.br/1234567890123456',
		);
		$html   = PublicSurfaces::person_profile( 'pt-br', $person );
		self::assertStringContainsString( 'https://orcid.org/0000-0002-1825-0097', $html );
		self::assertStringContainsString( 'http://lattes.cnpq.br/1234567890123456', $html );

		$invalid = PublicSurfaces::person_profile(
			'en',
			array_merge(
				$person,
				array(
					'orcid'      => '0000-0002-1825-0098',
					'lattes_url' => 'https://example.org/lattes',
				)
			)
		);
		self::assertStringNotContainsString( 'orcid.org', $invalid );
		self::assertStringNotContainsString( 'example.org/lattes', $invalid );
	}

	public function test_hidden_organizations_never_render_or_leak_logo(): void {
		$hidden = array(
			'name'           => 'Hidden Partner',
			'public_profile' => false,
			'logo_url'       => '/uploads/hidden.svg',
			'canonical_url'  => 'https://hidden.example/',
		);
		self::assertSame( '', PublicSurfaces::organization_profile( 'en', $hidden ) );

		$public = array(
			'name'           => 'Public Partner',
			'public_profile' => true,
			'kind'           => 'partner',
			'logo_url'       => '/uploads/logo.svg',
			'logo_rights'    => 'unknown',
			'canonical_url'  => 'https://partner.example/',
		);
		$html   = PublicSurfaces::organization_profile( 'en', $public );
		self::assertStringContainsString( 'Public Partner', $html );
		self::assertStringNotContainsString( 'logo.svg', $html );
	}

	public function test_infrastructure_omits_unsourced_claims_and_connects_evidence_and_contacts(): void {
		$html = PublicSurfaces::infrastructure_page(
			'en',
			array(
				array(
					'name'     => 'Validated computing facility',
					'kind'     => 'facility',
					'claims'   => array(
						array(
							'text'        => 'Supports reproducible signal-processing experiments.',
							'source_url'  => 'https://coppe.ufrj.br/source',
							'reviewed_at' => '2026-08-20',
						),
						array(
							'text'        => 'Fastest facility in Brazil.',
							'source_url'  => '',
							'reviewed_at' => '',
						),
					),
					'research' => array(
						array(
							'title' => 'Signal processing',
							'url'   => '/en/research/signal-processing/',
						),
					),
					'projects' => array(
						array(
							'title' => 'Project Atlas',
							'url'   => '/en/projects/atlas/',
						),
					),
					'contacts' => array(
						array(
							'name' => 'Technical staff',
							'url'  => '/en/people/technical-staff/',
						),
					),
				),
			)
		);

		self::assertStringContainsString( 'Supports reproducible', $html );
		self::assertStringNotContainsString( 'Fastest facility', $html );
		self::assertStringContainsString( '/en/research/signal-processing/', $html );
		self::assertStringContainsString( '/en/projects/atlas/', $html );
		self::assertStringContainsString( '/en/people/technical-staff/', $html );
		self::assertStringContainsString( 'Source reviewed <time datetime="2026-08-20">2026-08-20</time>', $html );
	}

	/** Every cohort keeps its own label instead of being flattened into professors. */
	public function test_every_cohort_keeps_its_own_label(): void {
		$cohorts = array(
			array(
				'slug'   => 'p1',
				'name'   => 'Pessoa Um',
				'roles'  => array( 'student' ),
				'status' => 'active',
			),
			array(
				'slug'   => 'p2',
				'name'   => 'Pessoa Dois',
				'roles'  => array( 'researcher' ),
				'status' => 'active',
			),
			array(
				'slug'   => 'p3',
				'name'   => 'Pessoa Tres',
				'roles'  => array( 'professor' ),
				'status' => 'active',
			),
			array(
				'slug'   => 'p4',
				'name'   => 'Pessoa Quatro',
				'roles'  => array( 'technical-staff' ),
				'status' => 'active',
			),
			array(
				'slug'   => 'p5',
				'name'   => 'Pessoa Cinco',
				'roles'  => array( 'external-collaborator' ),
				'status' => 'active',
			),
			array(
				'slug'   => 'p6',
				'name'   => 'Pessoa Seis',
				'roles'  => array( 'researcher' ),
				'status' => 'alumni',
			),
			array(
				'slug'      => 'p7',
				'name'      => 'Pessoa Sete',
				'roles'     => array( 'professor' ),
				'status'    => 'in-memoriam',
				'published' => true,
			),
		);

		$html  = PublicSurfaces::people_listing( 'pt-br', $cohorts, array() );
		$items = substr( $html, (int) strpos( $html, '</form>' ) );

		foreach ( array( 'Estudante', 'Pesquisador', 'Professor', 'Equipe técnica', 'Colaboração externa', 'Egresso', 'In memoriam' ) as $label ) {
			self::assertStringContainsString( $label, $items, 'Cohort label ' . $label . ' is listed, not only offered as a facet.' );
		}
	}

	/** A person holding several roles shows all of them. */
	public function test_multiple_roles_are_not_reduced_to_the_first(): void {
		$html = PublicSurfaces::person_profile(
			'en',
			array(
				'slug'   => 'multi',
				'name'   => 'Multi Role',
				'roles'  => array( 'professor', 'researcher' ),
				'status' => 'active',
			)
		);

		self::assertStringContainsString( 'Professor', $html );
		self::assertStringContainsString( 'Researcher', $html );
	}

	/** External collaborators are never presented as laboratory staff. */
	public function test_external_collaborators_are_labelled_as_external(): void {
		$row = array(
			'slug'   => 'ext',
			'name'   => 'External Person',
			'roles'  => array( 'external-collaborator' ),
			'status' => 'active',
		);

		$english = PublicSurfaces::person_profile( 'en', $row );
		self::assertStringContainsString( 'External collaborator', $english );
		self::assertStringContainsString( 'not LPS staff', $english );

		$portuguese = PublicSurfaces::person_profile( 'pt-br', $row );
		self::assertStringContainsString( 'não integra a equipe do LPS', $portuguese );
	}

	/** Unpublished records render nothing and never appear in the listing. */
	public function test_unpublished_records_are_withheld(): void {
		$row = array(
			'slug'      => 'pending',
			'name'      => 'Pending Consent',
			'roles'     => array( 'professor' ),
			'status'    => 'in-memoriam',
			'published' => false,
		);

		self::assertSame( '', PublicSurfaces::person_profile( 'pt-br', $row ) );
		self::assertStringNotContainsString( 'Pending Consent', PublicSurfaces::people_listing( 'pt-br', array( $row ), array() ) );
	}

	/** A departed member keeps a published profile and its historical links. */
	public function test_departed_members_keep_stable_history_links(): void {
		$html = PublicSurfaces::person_profile(
			'pt-br',
			array(
				'slug'     => 'egresso',
				'name'     => 'Pessoa Egressa',
				'roles'    => array( 'researcher' ),
				'status'   => 'alumni',
				'end_date' => '2023-12-31',
				'history'  => array(
					array(
						'title' => 'Projeto Atlas',
						'url'   => '/pt-br/projetos/atlas/',
					),
				),
			)
		);

		self::assertStringContainsString( 'Egresso', $html );
		self::assertStringContainsString( '2023-12-31', $html );
		self::assertStringContainsString( '/pt-br/projetos/atlas/', $html );
		self::assertStringContainsString( 'Projeto Atlas', $html );
	}

	/** Names that differ only by diacritics stay distinguishable. */
	public function test_duplicate_names_are_disambiguated(): void {
		$html = PublicSurfaces::people_listing(
			'pt-br',
			array(
				array(
					'slug'   => 'ana-alvares',
					'name'   => 'Ana Álvares',
					'roles'  => array( 'student' ),
					'status' => 'active',
				),
				array(
					'slug'   => 'ana-alvares-2',
					'name'   => 'Ana Alvares',
					'roles'  => array( 'professor' ),
					'status' => 'active',
				),
			),
			array()
		);

		self::assertStringContainsString( '/pt-br/pessoas/ana-alvares/', $html );
		self::assertStringContainsString( '/pt-br/pessoas/ana-alvares-2/', $html );
		self::assertSame( 2, substr_count( $html, 'lps-disambiguation' ), 'Both colliding names carry a qualifier.' );
	}

	/** Infrastructure contacts and evidence render in Portuguese too. */
	public function test_infrastructure_connects_contacts_in_portuguese(): void {
		$html = PublicSurfaces::infrastructure_page(
			'pt-br',
			array(
				array(
					'name'      => 'Laboratório validado',
					'claims'    => array(
						array(
							'text'        => 'Suporta experimentos reprodutíveis.',
							'source_url'  => 'https://coppe.ufrj.br/fonte',
							'reviewed_at' => '2026-08-20',
						),
					),
					'equipment' => array(
						array(
							'title' => 'Bancada de aquisição',
							'url'   => '/pt-br/infraestrutura/bancada/',
						),
					),
					'research'  => array(
						array(
							'title' => 'Processamento de sinais',
							'url'   => '/pt-br/pesquisa/processamento/',
						),
					),
					'projects'  => array(
						array(
							'title' => 'Projeto Atlas',
							'url'   => '/pt-br/projetos/atlas/',
						),
					),
					'contacts'  => array(
						array(
							'name' => 'Equipe técnica',
							'url'  => '/pt-br/pessoas/equipe-tecnica/',
						),
					),
				),
			)
		);

		self::assertStringContainsString( 'Fonte revisada em <time datetime="2026-08-20">2026-08-20</time>', $html );
		self::assertStringContainsString( '/pt-br/infraestrutura/bancada/', $html );
		self::assertStringContainsString( '/pt-br/pesquisa/processamento/', $html );
		self::assertStringContainsString( '/pt-br/projetos/atlas/', $html );
		self::assertStringContainsString( '/pt-br/pessoas/equipe-tecnica/', $html );
	}

	/** A stale English record announces the review state instead of falling back. */
	public function test_stale_english_record_announces_review_instead_of_falling_back(): void {
		$person = array(
			'slug'      => 'ana-alvares',
			'name'      => 'Ana Alvares',
			'roles'     => array( 'researcher' ),
			'status'    => 'active',
			'stale'     => true,
			'published' => true,
		);

		$html = PublicSurfaces::person_profile( 'en', $person );

		self::assertStringContainsString( 'lps-translation-notice', $html );
		self::assertStringContainsString( 'under review', $html );
		self::assertStringContainsString( 'Ana Alvares', $html );
		self::assertStringNotContainsString( 'Ana Álvares', $html );

		$fresh = PublicSurfaces::person_profile( 'en', array_merge( $person, array( 'stale' => false ) ) );
		self::assertStringNotContainsString( 'lps-translation-notice', $fresh );

		$organization = PublicSurfaces::organization_profile(
			'en',
			array(
				'name'           => 'Public Partner',
				'public_profile' => true,
				'stale'          => true,
			)
		);
		self::assertStringContainsString( 'lps-translation-notice', $organization );
	}

	/** Infrastructure groups its evidence links under localized headings. */
	public function test_infrastructure_groups_links_under_localized_headings(): void {
		$facility = array(
			'name'      => 'Laboratório validado',
			'equipment' => array(
				array(
					'title' => 'Bancada de aquisição',
					'url'   => '/pt-br/infraestrutura/bancada/',
				),
			),
			'contacts'  => array(
				array(
					'title' => 'Equipe técnica',
					'url'   => '/pt-br/pessoas/equipe-tecnica/',
				),
			),
		);

		$portuguese = PublicSurfaces::infrastructure_page( 'pt-br', array( $facility ) );
		$english    = PublicSurfaces::infrastructure_page( 'en', array( $facility ) );

		self::assertStringContainsString( '<h3>Equipamento</h3>', $portuguese );
		self::assertStringContainsString( '<h3>Contatos</h3>', $portuguese );
		self::assertStringContainsString( '<h3>Equipment</h3>', $english );
		self::assertStringContainsString( '<h3>Contacts</h3>', $english );
		self::assertStringContainsString( 'lps-infra-group', $portuguese );
	}

	/** An empty facility set renders the documented empty state, not a blank section. */
	public function test_infrastructure_without_facilities_renders_the_empty_state(): void {
		$html = PublicSurfaces::infrastructure_page( 'en', array() );

		self::assertStringContainsString( 'lps-empty', $html );
		self::assertStringContainsString( 'No published facilities', $html );
	}

	/** An absent record renders nothing at all, not an empty profile shell. */
	public function test_absent_person_record_renders_no_markup(): void {
		self::assertSame( '', PublicSurfaces::person_profile( 'pt-br', array() ) );
		self::assertSame(
			'',
			PublicSurfaces::person_profile(
				'en',
				array(
					'slug' => '',
					'name' => '',
				)
			)
		);
	}
}
