<?php
/**
 * Schema.org JSON-LD emission contracts.
 *
 * @package LPS\ContentModel\Tests
 */

declare(strict_types=1);

namespace LPS\ContentModel\Tests;

require_once dirname( __DIR__ ) . '/includes/class-structureddata.php';

use LPS\ContentModel\StructuredData;
use PHPUnit\Framework\TestCase;

/** Contract tests for the structured-data boundary. */
final class StructuredDataTest extends TestCase {
	private const SITE = 'https://lps.ufrj.br';

	/**
	 * Returns the frozen site identity used by every graph.
	 *
	 * @return array<string, mixed>
	 */
	private static function site(): array {
		return array(
			'site_url'   => self::SITE,
			'name'       => 'Laboratório de Processamento de Sinais',
			'acronym'    => 'LPS',
			'parents'    => array( 'COPPE', 'UFRJ' ),
			'founded'    => '1985',
			'locale'     => 'pt-br',
			'contact'    => 'contato@lps.ufrj.br',
			'address'    => 'Rio de Janeiro, RJ, Brasil',
			'identities' => array( 'https://ror.example.invalid/never-used' ),
		);
	}

	/** The site emits one WebSite node bound to the research organization. */
	public function test_website_node_is_bound_to_the_research_organization(): void {
		$website = StructuredData::website( self::site() );

		self::assertSame( 'WebSite', $website['@type'] );
		self::assertSame( self::SITE . '/#website', $website['@id'] );
		self::assertSame( self::SITE . '/#organization', self::branch( $website, 'publisher' )['@id'] );
		self::assertSame( 'pt-BR', $website['inLanguage'] );
	}

	/** The organization is a ResearchOrganization with its verified parents. */
	public function test_organization_node_is_a_research_organization_with_parents(): void {
		$organization = StructuredData::research_organization( self::site() );

		self::assertSame( 'ResearchOrganization', $organization['@type'] );
		self::assertSame( self::SITE . '/#organization', $organization['@id'] );
		self::assertSame( 'LPS', $organization['alternateName'] );
		self::assertSame( array( 'COPPE', 'UFRJ' ), array_column( self::branch( $organization, 'parentOrganization' ), 'name' ) );
	}

	/** No rating, review, or invented identifier is ever emitted. */
	public function test_organization_never_invents_ratings_or_identifiers(): void {
		$organization = StructuredData::research_organization( self::site() );

		self::assertArrayNotHasKey( 'aggregateRating', $organization );
		self::assertArrayNotHasKey( 'review', $organization );
		self::assertArrayNotHasKey( 'identifier', $organization );
	}

	/** A breadcrumb trail becomes an ordered BreadcrumbList. */
	public function test_breadcrumb_trail_becomes_an_ordered_list(): void {
		$breadcrumbs = StructuredData::breadcrumbs(
			self::SITE,
			'/pt-br/projetos/turbinas/',
			array(
				array(
					'name' => 'Início',
					'path' => '/pt-br/',
				),
				array(
					'name' => 'Projetos',
					'path' => '/pt-br/projetos/',
				),
				array(
					'name' => 'Turbinas',
					'path' => '/pt-br/projetos/turbinas/',
				),
			)
		);

		self::assertSame( 'BreadcrumbList', $breadcrumbs['@type'] );
		self::assertSame( array( 1, 2, 3 ), array_column( self::branch( $breadcrumbs, 'itemListElement' ), 'position' ) );
		self::assertSame( self::SITE . '/pt-br/projetos/', self::entry( self::branch( $breadcrumbs, 'itemListElement' ), 1 )['item'] );
	}

	/** A reviewed person becomes ProfilePage plus Person with only real identifiers. */
	public function test_profile_page_emits_person_with_only_verified_identifiers(): void {
		$profile = StructuredData::profile_page(
			self::SITE,
			'/pt-br/pessoas/ana-alvares/',
			array(
				'name'             => 'Ana Álvares',
				'summary'          => 'Pesquisadora em processamento de sinais.',
				'roles'            => array( 'Pesquisadora' ),
				'orcid'            => '0000-0002-1825-0097',
				'lattes_url'       => 'http://lattes.cnpq.br/0000000000000000',
				'scholar_url'      => '',
				'privacy_reviewed' => true,
			)
		);

		self::assertSame( 'ProfilePage', $profile['@type'] );
		self::assertSame( 'Person', self::branch( $profile, 'mainEntity' )['@type'] );
		self::assertSame( 'https://orcid.org/0000-0002-1825-0097', self::branch( $profile, 'mainEntity' )['identifier'] );
		self::assertSame(
			array( 'https://orcid.org/0000-0002-1825-0097', 'http://lattes.cnpq.br/0000000000000000' ),
			self::branch( $profile, 'mainEntity' )['sameAs']
		);
	}

	/** A person without a completed privacy review is never described publicly. */
	public function test_person_without_privacy_review_emits_no_node(): void {
		self::assertSame(
			array(),
			StructuredData::profile_page(
				self::SITE,
				'/pt-br/pessoas/sem-consentimento/',
				array(
					'name'             => 'Pessoa Sem Consentimento',
					'privacy_reviewed' => false,
				)
			)
		);
	}

	/** A project becomes a ResearchProject with funders and members. */
	public function test_project_becomes_a_research_project(): void {
		$project = StructuredData::research_project(
			self::SITE,
			'/pt-br/projetos/turbinas/',
			array(
				'name'    => 'Monitoramento de turbinas',
				'summary' => 'Instrumentação e diagnóstico de turbinas.',
				'status'  => 'active',
				'start'   => '2024-03-01',
				'end'     => '',
				'funders' => array( 'CNPq' ),
				'members' => array( 'Ana Álvares' ),
			)
		);

		self::assertSame( 'ResearchProject', $project['@type'] );
		self::assertSame( '2024-03-01', $project['startDate'] );
		self::assertArrayNotHasKey( 'endDate', $project );
		self::assertSame( array( 'CNPq' ), array_column( self::branch( $project, 'funder' ), 'name' ) );
	}

	/** Each publication type maps to its scholarly schema type. */
	public function test_publication_types_map_to_scholarly_schema_types(): void {
		$expected = array(
			'journal-article'  => 'ScholarlyArticle',
			'conference-paper' => 'ScholarlyArticle',
			'preprint'         => 'ScholarlyArticle',
			'book'             => 'Book',
			'book-chapter'     => 'Chapter',
			'thesis'           => 'Thesis',
			'dataset'          => 'Dataset',
			'software'         => 'SoftwareSourceCode',
			'report'           => 'Report',
		);

		foreach ( $expected as $type => $schema_type ) {
			$node = StructuredData::publication(
				self::SITE,
				'/pt-br/publicacoes/registro/',
				array(
					'title' => 'Registro',
					'type'  => $type,
					'date'  => '2025-01-01',
				)
			);
			self::assertSame( $schema_type, $node['@type'], $type );
		}
	}

	/** A DOI is published as a resolvable identifier and never fabricated. */
	public function test_publication_identifier_comes_only_from_a_stored_doi(): void {
		$with_doi = StructuredData::publication(
			self::SITE,
			'/pt-br/publicacoes/artigo/',
			array(
				'title' => 'Artigo',
				'type'  => 'journal-article',
				'date'  => '2025-01-01',
				'doi'   => '10.1000/exemplo.2025',
			)
		);
		self::assertSame( 'https://doi.org/10.1000/exemplo.2025', $with_doi['identifier'] );
		self::assertContains( 'https://doi.org/10.1000/exemplo.2025', self::branch( $with_doi, 'sameAs' ) );

		$without_doi = StructuredData::publication(
			self::SITE,
			'/pt-br/publicacoes/artigo-sem-doi/',
			array(
				'title' => 'Artigo sem DOI',
				'type'  => 'journal-article',
				'date'  => '2025-01-01',
			)
		);
		self::assertArrayNotHasKey( 'identifier', $without_doi );
	}

	/** News becomes NewsArticle with its canonical date and publisher. */
	public function test_news_becomes_news_article(): void {
		$news = StructuredData::news_article(
			self::SITE,
			'/pt-br/noticias/nova-bancada/',
			array(
				'title'   => 'Nova bancada',
				'summary' => 'Bancada instalada no laboratório.',
				'date'    => '2026-02-10T12:00:00+00:00',
			),
			self::site()
		);

		self::assertSame( 'NewsArticle', $news['@type'] );
		self::assertSame( '2026-02-10T12:00:00+00:00', $news['datePublished'] );
		self::assertSame( self::SITE . '/#organization', self::branch( $news, 'publisher' )['@id'] );
	}

	/** An event carries its schedule, status, and attendance mode. */
	public function test_event_carries_schedule_and_status(): void {
		$event = StructuredData::event(
			self::SITE,
			'/pt-br/eventos/seminario/',
			array(
				'title'     => 'Seminário',
				'starts_at' => '2026-10-01T13:00:00+00:00',
				'ends_at'   => '2026-10-01T15:00:00+00:00',
				'state'     => 'cancelled',
				'venue'     => 'Sala C-201',
			)
		);

		self::assertSame( 'Event', $event['@type'] );
		self::assertSame( 'https://schema.org/EventCancelled', $event['eventStatus'] );
		self::assertSame( 'Sala C-201', self::branch( $event, 'location' )['name'] );
	}

	/** Genuine employment becomes a JobPosting. */
	public function test_genuine_employment_becomes_a_job_posting(): void {
		$posting = StructuredData::opportunity(
			self::SITE,
			'/pt-br/oportunidades/tecnico-de-laboratorio/',
			array(
				'title'     => 'Técnico de laboratório',
				'summary'   => 'Vaga efetiva de apoio técnico.',
				'type'      => 'employment',
				'opens_at'  => '2026-09-01T12:00:00+00:00',
				'closes_at' => '2026-10-01T12:00:00+00:00',
				'location'  => 'Rio de Janeiro',
			),
			self::site()
		);

		self::assertSame( 'JobPosting', $posting['@type'] );
		self::assertSame( self::SITE . '/#organization', self::branch( $posting, 'hiringOrganization' )['@id'] );
		self::assertArrayNotHasKey( 'baseSalary', $posting );
	}

	/** A scholarship is never described as employment. */
	public function test_scholarship_is_never_a_job_posting(): void {
		foreach ( array( 'scholarship', 'fellowship', 'internship', 'grant' ) as $type ) {
			$node = StructuredData::opportunity(
				self::SITE,
				'/pt-br/oportunidades/bolsa/',
				array(
					'title'     => 'Bolsa de doutorado',
					'summary'   => 'Bolsa de pesquisa.',
					'type'      => $type,
					'opens_at'  => '2026-09-01T12:00:00+00:00',
					'closes_at' => '2026-10-01T12:00:00+00:00',
				),
				self::site()
			);

			self::assertSame( 'EducationalOccupationalProgram', $node['@type'], $type );
			self::assertArrayNotHasKey( 'hiringOrganization', $node, $type );
			self::assertArrayNotHasKey( 'baseSalary', $node, $type );
		}
	}

	/** A graph refuses to publish two nodes under the same identifier. */
	public function test_graph_rejects_duplicate_node_identifiers(): void {
		$graph = StructuredData::graph(
			array(
				array(
					'@type' => 'WebPage',
					'@id'   => self::SITE . '/pt-br/#webpage',
					'name'  => 'Primeiro',
				),
				array(
					'@type' => 'WebPage',
					'@id'   => self::SITE . '/pt-br/#webpage',
					'name'  => 'Segundo',
				),
				array(),
			)
		);

		self::assertCount( 1, self::branch( $graph, '@graph' ) );
		self::assertSame( 'Primeiro', self::entry( self::branch( $graph, '@graph' ), 0 )['name'] );
		self::assertSame( 'https://schema.org', $graph['@context'] );
	}

	/** The encoded graph is valid, unescaped, single-document JSON. */
	public function test_encoded_graph_is_valid_json(): void {
		$json    = StructuredData::encode( StructuredData::graph( array( StructuredData::website( self::site() ) ) ) );
		$decoded = json_decode( $json, true );

		self::assertIsArray( $decoded );
		self::assertSame( JSON_ERROR_NONE, json_last_error() );
		self::assertStringNotContainsString( '</script', $json );
	}

	/**
	 * Returns one array branch of a node, proving it is an array first.
	 *
	 * @param array<string, mixed> $node Decoded node.
	 * @param string               $key  Branch key.
	 * @return array<mixed>
	 */
	private static function branch( array $node, string $key ): array {
		self::assertArrayHasKey( $key, $node );
		$branch = $node[ $key ];
		self::assertIsArray( $branch );
		return $branch;
	}

	/**
	 * Returns one entry of a list branch, proving it is an array first.
	 *
	 * @param array<mixed> $list  List branch.
	 * @param int          $index Entry offset.
	 * @return array<mixed>
	 */
	private static function entry( array $list, int $index ): array {
		self::assertArrayHasKey( $index, $list );
		$entry = $list[ $index ];
		self::assertIsArray( $entry );
		return $entry;
	}
}
