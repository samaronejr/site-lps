<?php
/**
 * Opportunity, event, news, and institutional trust surface tests.
 *
 * @package LPS\Theme\Tests
 */

declare(strict_types=1);

namespace LPS\Theme\Tests;

require_once dirname( __DIR__ ) . '/includes/class-trustsurfaces.php';

use DateTimeImmutable;
use LPS\Theme\TrustSurfaces;
use PHPUnit\Framework\TestCase;

/** Contract tests for the trust surface rendering library. */
final class TrustSurfacesTest extends TestCase {
	/** A record detail page titles itself once, at H1, never repeating the page title. */
	public function test_record_detail_headings_are_a_single_h1(): void {
		// Given: the opportunity and event detail surfaces.
		$opportunity = TrustSurfaces::render_opportunity( self::open_opportunity(), 'pt-br', $this->now() );
		$event       = TrustSurfaces::render_event(
			array(
				'title'     => 'Seminário LPS 2026',
				'status'    => 'scheduled',
				'starts_at' => '2026-10-01T13:00:00+00:00',
				'ends_at'   => '2026-10-01T17:00:00+00:00',
				'venue'     => 'Auditório do LPS',
			),
			'pt-br',
			$this->now()
		);

		// Then: each renders its title once as the page H1 and never as a duplicate H2.
		foreach ( array( $opportunity, $event ) as $html ) {
			self::assertSame( 1, substr_count( $html, '<h1>' ), 'exactly one h1' );
			$title = (string) ( preg_match( '~<h1>(.*?)</h1>~', $html, $matches ) ? $matches[1] : '' );
			self::assertNotSame( '', $title );
			self::assertStringNotContainsString( '<h2>' . $title . '</h2>', $html );
		}
	}

	private const NOW = '2026-09-03T12:00:00+00:00';

	/**
	 * Returns a currently open opportunity fixture.
	 *
	 * @return array<string, mixed>
	 */
	private static function open_opportunity(): array {
		return array(
			'slug'                 => 'bolsa-doutorado-sinais',
			'title'                => 'Bolsa de doutorado em processamento de sinais',
			'summary'              => 'Bolsa para pesquisa em processamento de sinais.',
			'eligibility'          => 'Mestrado concluído em área correlata.',
			'instructions'         => 'Envie histórico e carta de motivação.',
			'opens_at'             => '2026-09-01T12:00:00+00:00',
			'closes_at'            => '2026-09-20T12:00:00+00:00',
			'contact'              => 'oportunidades@lps.ufrj.br',
			'contact_is_role'      => true,
			'application_url'      => '',
			'application_approved' => false,
		);
	}

	/** Returns the fixed evaluation instant shared by every scenario. */
	private function now(): DateTimeImmutable {
		return new DateTimeImmutable( self::NOW );
	}

	/** An open opportunity shows its state and its role contact handoff. */
	public function test_open_opportunity_renders_open_state_and_role_contact_handoff(): void {
		$html = TrustSurfaces::render_opportunity( self::open_opportunity(), 'pt-br', $this->now() );

		self::assertStringContainsString( 'data-state="open"', $html );
		self::assertStringContainsString( 'Inscrições abertas', $html );
		self::assertStringContainsString( 'mailto:oportunidades@lps.ufrj.br', $html );
		self::assertStringContainsString( '2026-09-20', $html );
	}

	/** A closed opportunity stays published but never reads as open. */
	public function test_closed_opportunity_is_stable_and_never_presented_as_open(): void {
		$record              = self::open_opportunity();
		$record['opens_at']  = '2026-08-01T12:00:00+00:00';
		$record['closes_at'] = '2026-09-02T12:00:00+00:00';

		$html = TrustSurfaces::render_opportunity( $record, 'pt-br', $this->now() );

		self::assertStringContainsString( 'data-state="closed"', $html );
		self::assertStringContainsString( 'Inscrições encerradas', $html );
		self::assertStringNotContainsString( 'Inscrições abertas', $html );
		self::assertStringNotContainsString( 'mailto:oportunidades@lps.ufrj.br', $html );
		self::assertStringContainsString( self::open_opportunity()['title'], $html );
	}

	/** Indexability changes only after the retention window. */
	public function test_closed_opportunity_becomes_noindex_only_after_ninety_days(): void {
		$record              = self::open_opportunity();
		$record['opens_at']  = '2026-01-01T12:00:00+00:00';
		$record['closes_at'] = '2026-09-02T12:00:00+00:00';
		$recent              = TrustSurfaces::render_opportunity( $record, 'pt-br', $this->now() );
		self::assertStringNotContainsString( 'noindex', $recent );

		$record['closes_at'] = '2026-01-31T12:00:00+00:00';
		$aged                = TrustSurfaces::render_opportunity( $record, 'pt-br', $this->now() );
		self::assertStringContainsString( 'noindex', $aged );
	}

	/** A personal address is replaced by a documented unavailable state. */
	public function test_opportunity_without_public_handoff_renders_documented_absent_contact_state(): void {
		$record                    = self::open_opportunity();
		$record['contact']         = 'ana.pesquisadora@lps.ufrj.br';
		$record['contact_is_role'] = false;

		$html = TrustSurfaces::render_opportunity( $record, 'pt-br', $this->now() );

		self::assertStringNotContainsString( 'ana.pesquisadora@lps.ufrj.br', $html );
		self::assertStringContainsString( 'lps-contact-unavailable', $html );
	}

	/** Listing state is derived per record, never inherited. */
	public function test_opportunity_listing_separates_open_from_closed_without_masquerade(): void {
		$open                = self::open_opportunity();
		$closed              = self::open_opportunity();
		$closed['slug']      = 'estagio-encerrado';
		$closed['title']     = 'Estágio encerrado';
		$closed['opens_at']  = '2026-08-01T12:00:00+00:00';
		$closed['closes_at'] = '2026-09-02T12:00:00+00:00';

		$html = TrustSurfaces::render_opportunity_listing( array( $open, $closed ), 'pt-br', $this->now() );

		self::assertStringContainsString( '/pt-br/oportunidades/bolsa-doutorado-sinais/', $html );
		self::assertStringContainsString( '/pt-br/oportunidades/estagio-encerrado/', $html );
		self::assertSame( 1, substr_count( $html, 'data-state="open"' ) );
		self::assertSame( 1, substr_count( $html, 'data-state="closed"' ) );
	}

	/** Pinned event statuses survive date-based recomputation. */
	public function test_cancelled_and_postponed_events_keep_stable_explicit_status(): void {
		$event = array(
			'slug'      => 'seminario-2026',
			'title'     => 'Seminário LPS 2026',
			'summary'   => 'Seminário anual.',
			'starts_at' => '2026-09-10T12:00:00+00:00',
			'ends_at'   => '2026-09-10T14:00:00+00:00',
			'status'    => 'cancelled',
			'venue'     => 'Auditório do LPS',
		);

		$cancelled = TrustSurfaces::render_event( $event, 'pt-br', $this->now() );
		self::assertStringContainsString( 'data-state="cancelled"', $cancelled );
		self::assertStringContainsString( 'Cancelado', $cancelled );
		self::assertStringContainsString( 'Seminário LPS 2026', $cancelled );

		$event['status'] = 'postponed';
		$postponed       = TrustSurfaces::render_event( $event, 'pt-br', $this->now() );
		self::assertStringContainsString( 'data-state="postponed"', $postponed );
		self::assertStringContainsString( 'Adiado', $postponed );

		$event['status'] = 'scheduled';
		$upcoming        = TrustSurfaces::render_event( $event, 'pt-br', $this->now() );
		self::assertStringContainsString( 'data-state="upcoming"', $upcoming );
	}

	/** News items link to their frozen localized routes. */
	public function test_news_listing_renders_stable_localized_routes(): void {
		$html = TrustSurfaces::render_news_listing(
			array(
				array(
					'slug'    => 'novo-laboratorio',
					'title'   => 'Novo laboratório inaugurado',
					'summary' => 'Inauguração do novo espaço.',
					'date'    => '2026-08-20',
				),
			),
			'pt-br'
		);

		self::assertStringContainsString( '/pt-br/noticias/novo-laboratorio/', $html );
		self::assertStringContainsString( 'Novo laboratório inaugurado', $html );
	}

	/** Unsourced prestige claims are omitted from the page. */
	public function test_institutional_page_requires_source_and_review_for_every_claim(): void {
		$page = array(
			'key'         => 'about',
			'title'       => 'Sobre o LPS',
			'summary'     => 'Laboratório de Processamento de Sinais.',
			'affiliation' => 'COPPE/UFRJ',
			'location'    => 'Rio de Janeiro, Brasil',
			'reviewed_at' => '2026-08-01',
			'claims'      => array(
				array(
					'statement'   => 'O laboratório mantém convênios ativos com três agências de fomento.',
					'verified'    => true,
					'source_url'  => 'https://www.ufrj.br/convenios',
					'reviewed_at' => '2026-08-01',
				),
				array(
					'statement'   => 'O laboratório é o melhor do país.',
					'verified'    => true,
					'source_url'  => '',
					'reviewed_at' => '2026-08-01',
				),
			),
		);

		$html = TrustSurfaces::render_institutional_page( $page, 'pt-br', $this->now() );

		self::assertStringContainsString( 'convênios ativos com três agências', $html );
		self::assertStringContainsString( 'https://www.ufrj.br/convenios', $html );
		self::assertStringNotContainsString( 'o melhor do país', $html );
		self::assertStringContainsString( 'COPPE/UFRJ', $html );
		self::assertStringContainsString( '2026-08-01', $html );
	}

	/** Claims past their review window are omitted. */
	public function test_institutional_page_drops_claims_whose_review_went_stale(): void {
		$page = array(
			'key'         => 'governance',
			'title'       => 'Governança',
			'summary'     => 'Estrutura de governança.',
			'reviewed_at' => '2026-08-01',
			'claims'      => array(
				array(
					'statement'   => 'Reconhecido internacionalmente desde 1990.',
					'verified'    => true,
					'source_url'  => 'https://www.ufrj.br/historico',
					'reviewed_at' => '2024-01-01',
				),
			),
		);

		$html = TrustSurfaces::render_institutional_page( $page, 'pt-br', $this->now() );

		self::assertStringNotContainsString( 'Reconhecido internacionalmente', $html );
	}

	/**
	 * Provides every institutional page key.
	 *
	 * @return array<string, array{string}>
	 */
	public static function institutional_keys(): array {
		return array(
			'about'         => array( 'about' ),
			'history'       => array( 'history' ),
			'governance'    => array( 'governance' ),
			'collaboration' => array( 'collaboration' ),
			'contact'       => array( 'contact' ),
			'privacy'       => array( 'privacy' ),
			'accessibility' => array( 'accessibility' ),
		);
	}

	/**
	 * Institutional pages never render a data-collection control.
	 *
	 * @param string $key Institutional page key.
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider( 'institutional_keys' )]
	public function test_institutional_pages_never_collect_personal_data( string $key ): void {
		$html = TrustSurfaces::render_institutional_page(
			array(
				'key'         => $key,
				'title'       => 'Página',
				'summary'     => 'Resumo institucional.',
				'reviewed_at' => '2026-08-01',
				'claims'      => array(),
			),
			'pt-br',
			$this->now()
		);

		self::assertStringNotContainsString( '<form', $html );
		self::assertStringNotContainsString( '<input', $html );
		self::assertStringNotContainsString( 'cookie', strtolower( $html ) );
	}

	/** Personal addresses never reach the contact page. */
	public function test_contact_page_exposes_only_public_role_addresses(): void {
		$html = TrustSurfaces::render_contact_page(
			array(
				array(
					'role'    => 'Coordenação',
					'email'   => 'coordenacao@lps.ufrj.br',
					'is_role' => true,
				),
				array(
					'role'    => 'Pesquisadora',
					'email'   => 'ana.pesquisadora@lps.ufrj.br',
					'is_role' => false,
				),
			),
			'pt-br'
		);

		self::assertStringContainsString( 'mailto:coordenacao@lps.ufrj.br', $html );
		self::assertStringNotContainsString( 'ana.pesquisadora@lps.ufrj.br', $html );
		self::assertStringNotContainsString( '<form', $html );
	}

	/** Both notices publish a reachable reporting address. */
	public function test_privacy_and_accessibility_pages_expose_barrier_reporting_route(): void {
		$privacy = TrustSurfaces::render_institutional_page(
			array(
				'key'            => 'privacy',
				'title'          => 'Privacidade',
				'summary'        => 'Aviso de privacidade.',
				'reviewed_at'    => '2026-08-01',
				'claims'         => array(),
				'report_contact' => 'privacidade@lps.ufrj.br',
			),
			'pt-br',
			$this->now()
		);
		self::assertStringContainsString( 'mailto:privacidade@lps.ufrj.br', $privacy );

		$accessibility = TrustSurfaces::render_institutional_page(
			array(
				'key'            => 'accessibility',
				'title'          => 'Acessibilidade',
				'summary'        => 'Declaração de acessibilidade.',
				'reviewed_at'    => '2026-08-01',
				'claims'         => array(),
				'report_contact' => 'acessibilidade@lps.ufrj.br',
			),
			'pt-br',
			$this->now()
		);
		self::assertStringContainsString( 'mailto:acessibilidade@lps.ufrj.br', $accessibility );
	}

	/** Unsafe destinations are dropped from collaboration journeys. */
	public function test_collaboration_routes_handoffs_to_owned_destinations_only(): void {
		$html = TrustSurfaces::render_collaboration_page(
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
			),
			'pt-br'
		);

		self::assertStringContainsString( 'mailto:oportunidades@lps.ufrj.br', $html );
		self::assertStringNotContainsString( 'javascript:', $html );
	}
}
