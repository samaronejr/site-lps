<?php
/**
 * Opportunity, event, news, and institutional trust surfaces.
 *
 * @package LPS\Theme
 */

declare(strict_types=1);

namespace LPS\Theme;

use DateTimeImmutable;
use LPS\ContentModel\TrustSurfacePolicy;

require_once dirname( __DIR__, 3 ) . '/plugins/lps-content-model/includes/class-trustsurfacepolicy.php';

/**
 * Renders date-derived opportunity/event state and sourced institutional pages.
 *
 * Every public state is recomputed from stored dates at render time, so an
 * expired deadline can never be presented as open. Records stay at stable URLs
 * once closed or cancelled; only their status and indexability change.
 */
final class TrustSurfaces {
	/**
	 * Frozen locale path segment for each trust record type.
	 *
	 * @var array<string, array<string, string>>
	 */
	private const SEGMENTS = array(
		'lps_opportunity' => array(
			'pt-br' => 'oportunidades',
			'en'    => 'opportunities',
		),
		'lps_event'       => array(
			'pt-br' => 'eventos',
			'en'    => 'events',
		),
		'lps_news'        => array(
			'pt-br' => 'noticias',
			'en'    => 'news',
		),
	);

	/**
	 * Localized labels for each derived opportunity state.
	 *
	 * @var array<string, array<string, string>>
	 */
	private const OPPORTUNITY_LABELS = array(
		'upcoming' => array(
			'pt-br' => 'Inscrições em breve',
			'en'    => 'Applications opening soon',
		),
		'open'     => array(
			'pt-br' => 'Inscrições abertas',
			'en'    => 'Applications open',
		),
		'closed'   => array(
			'pt-br' => 'Inscrições encerradas',
			'en'    => 'Applications closed',
		),
	);

	/**
	 * Localized labels for each derived event state.
	 *
	 * @var array<string, array<string, string>>
	 */
	private const EVENT_LABELS = array(
		'upcoming'  => array(
			'pt-br' => 'Programado',
			'en'    => 'Upcoming',
		),
		'ongoing'   => array(
			'pt-br' => 'Em andamento',
			'en'    => 'In progress',
		),
		'past'      => array(
			'pt-br' => 'Encerrado',
			'en'    => 'Past',
		),
		'cancelled' => array(
			'pt-br' => 'Cancelado',
			'en'    => 'Cancelled',
		),
		'postponed' => array(
			'pt-br' => 'Adiado',
			'en'    => 'Postponed',
		),
	);

	/**
	 * Returns the localized archive path for a trust record type.
	 *
	 * @param string $post_type Trust record type.
	 * @param string $locale    Supported locale slug.
	 */
	public static function archive_path( string $post_type, string $locale ): string {
		$segment = self::SEGMENTS[ $post_type ][ $locale ] ?? '';
		return '' === $segment ? '' : '/' . $locale . '/' . $segment . '/';
	}

	/**
	 * Returns the localized single-record path.
	 *
	 * @param string $post_type Trust record type.
	 * @param string $locale    Supported locale slug.
	 * @param string $slug      Record slug.
	 */
	public static function single_path( string $post_type, string $locale, string $slug ): string {
		$archive = self::archive_path( $post_type, $locale );
		return '' === $archive || '' === $slug ? $archive : $archive . $slug . '/';
	}

	/**
	 * Renders one opportunity with its date-derived state.
	 *
	 * @param array<string, mixed> $record Opportunity record.
	 * @param string               $locale Supported locale slug.
	 * @param DateTimeImmutable    $now    Evaluation instant.
	 */
	public static function render_opportunity( array $record, string $locale, DateTimeImmutable $now ): string {
		$opens   = self::text( $record['opens_at'] ?? '' );
		$closes  = self::text( $record['closes_at'] ?? '' );
		$state   = TrustSurfacePolicy::opportunity_state( $opens, $closes, $now );
		$english = 'en' === $locale;

		$noindex = 'closed' === $state && TrustSurfacePolicy::opportunity_is_noindex( $closes, $now );
		$html    = '<article class="lps-opportunity" data-state="' . self::esc( $state ) . '"';
		$html   .= $noindex ? ' data-noindex="true"' : '';
		$html   .= '>';
		$html   .= '<div class="lps-page-header"><div class="lps-page-header-inner">';
		$html   .= '<p class="lps-kicker">' . self::esc( $english ? 'Opportunity' : 'Oportunidade' ) . '</p>';
		$html   .= '<h1>' . self::esc( self::text( $record['title'] ?? '' ) ) . '</h1>';
		$html   .= self::translation_notice( $record, $locale );
		$summary = self::text( $record['summary'] ?? '' );
		if ( '' !== $summary ) {
			$html .= '<p class="lps-lead">' . self::esc( $summary ) . '</p>';
		}
		$html .= '<p class="lps-meta lps-mt-6"><span class="lps-opportunity-state">' . self::esc( self::OPPORTUNITY_LABELS[ $state ][ $locale ] ?? '' ) . '</span>';
		if ( '' !== $closes ) {
			$html .= ' · <span class="lps-deadline">' . self::esc( $english ? 'Deadline' : 'Prazo' ) . ': <time datetime="' . self::esc( substr( $closes, 0, 10 ) ) . '">' . self::esc( substr( $closes, 0, 10 ) ) . '</time></span>';
		}
		$html .= '</p></div></div>';

		$html .= '<div class="lps-stack">';
		foreach ( array(
			'eligibility'  => $english ? 'Eligibility' : 'Elegibilidade',
			'instructions' => $english ? 'How to apply' : 'Como se inscrever',
		) as $key => $label ) {
			$value = self::text( $record[ $key ] ?? '' );
			if ( '' !== $value ) {
				// h2, not h3: these sections sit directly under the page h1, and a
				// skipped level breaks the heading outline (axe heading-order).
				$html .= '<section class="lps-section lps-section--flush lps-opportunity-' . self::esc( $key ) . '" aria-labelledby="lps-opportunity-' . self::esc( $key ) . '">';
				$html .= '<div class="lps-section-head"><div><p class="lps-kicker">' . self::esc( $english ? 'Opportunity' : 'Oportunidade' ) . '</p><h2 id="lps-opportunity-' . self::esc( $key ) . '">' . self::esc( $label ) . '</h2></div></div>';
				$html .= '<div class="lps-reading"><p>' . self::esc( $value ) . '</p></div></section>';
			}
		}

		$handoff = self::handoff(
			self::text( $record['contact'] ?? '' ),
			! empty( $record['contact_is_role'] ),
			self::text( $record['application_url'] ?? '' ),
			! empty( $record['application_approved'] ),
			$locale,
			'closed' !== $state
		);
		if ( '' !== $handoff ) {
			$html .= '<section class="lps-section" aria-labelledby="lps-opportunity-apply">';
			$html .= '<div class="lps-section-head"><div><p class="lps-kicker">' . self::esc( $english ? 'Apply' : 'Inscreva-se' ) . '</p><h2 id="lps-opportunity-apply">' . self::esc( $english ? 'Apply' : 'Inscreva-se' ) . '</h2></div></div>';
			$html .= $handoff . '</section>';
		}
		$html .= '</div>';

		return $html . '</article>';
	}

	/**
	 * Renders the opportunity listing with each record in its derived state.
	 *
	 * @param array<int, mixed> $records Opportunity records.
	 * @param string            $locale  Supported locale slug.
	 * @param DateTimeImmutable $now     Evaluation instant.
	 */
	public static function render_opportunity_listing( array $records, string $locale, DateTimeImmutable $now ): string {
		$english = 'en' === $locale;
		$items   = '';
		foreach ( $records as $record ) {
			if ( ! is_array( $record ) ) {
				continue;
			}
			$title = self::text( $record['title'] ?? '' );
			$slug  = self::text( $record['slug'] ?? '' );
			if ( '' === $title || '' === $slug ) {
				continue;
			}
			$opens  = self::text( $record['opens_at'] ?? '' );
			$closes = self::text( $record['closes_at'] ?? '' );
			$state  = TrustSurfacePolicy::opportunity_state( $opens, $closes, $now );
			$url    = self::single_path( 'lps_opportunity', $locale, $slug );
			$date   = '' !== $closes ? $closes : $opens;
			$stamp  = substr( $date, 0, 10 );
			$items .= '<li data-state="' . self::esc( $state ) . '">';
			$items .= '<div class="lps-event-date"><strong>';
			$items .= '' !== $stamp ? '<time datetime="' . self::esc( $stamp ) . '">' . self::esc( $stamp ) . '</time>' : '—';
			$items .= '</strong><span>' . self::esc( '' !== $closes ? ( $english ? 'Deadline' : 'Prazo' ) : ( $english ? 'Opens' : 'Abertura' ) ) . '</span></div>';
			$items .= '<div><h3><a href="' . self::esc( $url ) . '">' . self::esc( $title ) . '</a></h3>';
			$items .= '<p><span class="lps-opportunity-state">' . self::esc( self::OPPORTUNITY_LABELS[ $state ][ $locale ] ?? '' ) . '</span>' . self::translation_chip( $record, $locale ) . '</p>';
			$items .= '</div>';
			$items .= '<a class="lps-more" href="' . self::esc( $url ) . '">' . self::esc( $english ? 'Know more' : 'Saiba mais' ) . '</a>';
			$items .= '</li>';
		}
		$calls = '';
		if ( '' !== $items ) {
			$calls = '<ol class="lps-agenda">' . $items . '</ol>';
		} else {
			$calls = self::alert_band(
				'warning',
				$english
					? 'No call is open at the moment. The opportunities page of the previous institutional site reads "coming soon".'
					: 'Não há chamada aberta publicada no momento. A página de oportunidades do site institucional anterior registra "em breve".',
				$english ? 'No open call right now' : 'Nenhuma chamada aberta no momento'
			);
		}
		$html   = self::editorial_section(
			'lps-opp-status',
			$english ? 'Current status' : 'Situação atual',
			$english ? 'Open calls' : 'Chamadas abertas',
			$calls,
			true
		);
		$tracks = array(
			array(
				$english ? 'Undergraduate research' : 'Iniciação científica',
				$english
					? 'For UFRJ undergraduate students interested in signal processing, machine learning and instrumentation.'
					: 'Para estudantes de graduação da UFRJ interessados em processamento de sinais, aprendizado de máquina e instrumentação.',
			),
			array(
				$english ? "Master's and doctoral programs" : 'Mestrado e doutorado',
				$english
					? 'Selection through the Electrical Engineering Program at COPPE, with supervision available in the Computational Intelligence area.'
					: 'Seleção pelo Programa de Engenharia Elétrica da COPPE, com possibilidade de orientação na área de Inteligência Computacional.',
			),
			array(
				$english ? 'Junior research initiation' : 'Iniciação científica júnior',
				$english
					? 'The laboratory works with secondary and technical students in training activities.'
					: 'O laboratório atua com estudantes do ensino médio e técnico em atividades de formação.',
			),
			array(
				$english ? 'Post-doctorate and collaborations' : 'Pós-doutorado e colaborações',
				$english
					? 'Post-doctoral researchers take part in the laboratory activities, with funding from research agencies.'
					: 'Pesquisadores de pós-doutorado participam das atividades do laboratório, com financiamento de agências de fomento.',
			),
		);
		$cards  = '';
		foreach ( $tracks as $track ) {
			$cards .= self::editorial_card( $track[0], $track[1], true );
		}
		$html      .= self::editorial_section(
			'lps-opp-tracks',
			$english ? 'Tracks' : 'Trilhas',
			$english ? 'Where the laboratory takes people in' : 'Por onde o laboratório recebe pessoas',
			'<div class="lps-grid lps-grid--2">' . $cards . '</div>'
		);
		$steps      = array(
			$english
				? 'Follow the calls published on this page and on the PEE/COPPE institutional channels.'
				: 'Acompanhe as chamadas publicadas nesta página e nos canais institucionais do PEE/COPPE.',
			$english
				? 'Contact the laboratory office to check availability and project requirements.'
				: 'Contate a secretaria do laboratório para verificar disponibilidade de vagas e requisitos do projeto.',
			$english
				? 'For master or doctoral admission, the formal route is the selection process of the Electrical Engineering Program at COPPE/UFRJ.'
				: 'Para ingresso em mestrado ou doutorado, o caminho formal é a seleção do Programa de Engenharia Elétrica da COPPE/UFRJ.',
		);
		$steps_html = '<ol class="lps-timeline">';
		foreach ( $steps as $index => $step ) {
			$steps_html .= '<li><span class="lps-timeline-year">' . sprintf( '%02d', $index + 1 ) . '</span><div><p>' . self::esc( $step ) . '</p></div></li>';
		}
		$steps_html .= '</ol>';
		$steps_html .= '<div class="lps-mt-10">' . self::cta_band(
			$english ? 'Talk to the laboratory office' : 'Fale com a secretaria do laboratório',
			$english
				? 'The office answers questions on availability, requirements and documents before you apply.'
				: 'A secretaria responde dúvidas sobre disponibilidade, requisitos e documentos antes da candidatura.',
			array(
				array(
					'href'  => 'mailto:secretaria@lps.ufrj.br',
					'label' => 'secretaria@lps.ufrj.br',
				),
				array(
					'href'  => TrustRoutes::page_path( 'contact', $locale ),
					'label' => $english ? 'All contacts' : 'Todos os contatos',
				),
			)
		) . '</div>';
		$html       .= self::editorial_section(
			'lps-opp-how',
			$english ? 'How to apply' : 'Como se candidatar',
			$english ? 'Three steps' : 'Três passos',
			$steps_html
		);
		return $html;
	}

	/**
	 * Renders one event with its explicit or derived state.
	 *
	 * @param array<string, mixed> $record Event record.
	 * @param string               $locale Supported locale slug.
	 * @param DateTimeImmutable    $now    Evaluation instant.
	 */
	public static function render_event( array $record, string $locale, DateTimeImmutable $now ): string {
		$state   = TrustSurfacePolicy::event_state(
			self::text( $record['status'] ?? '' ),
			self::text( $record['starts_at'] ?? '' ),
			self::text( $record['ends_at'] ?? '' ),
			$now
		);
		$english = 'en' === $locale;
		$starts  = self::text( $record['starts_at'] ?? '' );

		$venue = self::text( $record['venue'] ?? '' );
		$html  = '<article class="lps-event" data-state="' . self::esc( $state ) . '">';
		$html .= '<div class="lps-page-header"><div class="lps-page-header-inner">';
		$html .= '<p class="lps-kicker">' . self::esc( $english ? 'Event' : 'Evento' ) . '</p>';
		$html .= '<h1>' . self::esc( self::text( $record['title'] ?? '' ) ) . '</h1>';
		$html .= self::translation_notice( $record, $locale );
		$html .= '<p class="lps-event-state">' . self::esc( self::EVENT_LABELS[ $state ][ $locale ] ?? '' ) . '</p>';
		if ( in_array( $state, array( 'cancelled', 'postponed' ), true ) ) {
			$html .= '<p class="lps-event-notice">' . self::esc(
				'cancelled' === $state
					? ( $english ? 'This event will not take place. The record is kept for reference.' : 'Este evento não será realizado. O registro é mantido para referência.' )
					: ( $english ? 'This event was postponed. A new date will be published here.' : 'Este evento foi adiado. Uma nova data será publicada aqui.' )
			) . '</p>';
		}
		$summary = self::text( $record['summary'] ?? '' );
		if ( '' !== $summary ) {
			$html .= '<p class="lps-lead">' . self::esc( $summary ) . '</p>';
		}
		$html .= '</div></div>';

		if ( '' !== $starts || '' !== $venue ) {
			$html .= '<section class="lps-section lps-section--flush" aria-labelledby="lps-event-details">';
			$html .= '<div class="lps-section-head"><div><p class="lps-kicker">' . self::esc( $english ? 'Schedule' : 'Agenda' ) . '</p><h2 id="lps-event-details">' . self::esc( $english ? 'Details' : 'Detalhes' ) . '</h2></div></div>';
			$html .= '<div class="lps-split"><div>';
			$html .= '<div class="lps-event-date"><strong>';
			$html .= '' !== $starts ? '<time datetime="' . self::esc( substr( $starts, 0, 10 ) ) . '">' . self::esc( substr( $starts, 0, 10 ) ) . '</time>' : '—';
			$html .= '</strong><span>' . self::esc( $english ? 'Event' : 'Evento' ) . '</span></div>';
			$html .= '</div><div>';
			if ( '' !== $venue ) {
				$html .= '<p class="lps-event-venue">' . self::esc( $venue ) . '</p>';
			}
			$html .= '</div></div></section>';
		}
		return $html . '</article>';
	}

	/**
	 * Renders the event listing.
	 *
	 * @param array<int, mixed> $records Event records.
	 * @param string            $locale  Supported locale slug.
	 * @param DateTimeImmutable $now     Evaluation instant.
	 */
	public static function render_event_listing( array $records, string $locale, DateTimeImmutable $now ): string {
		$english = 'en' === $locale;
		$items   = self::event_agenda_items( $records, $locale, $now );
		return self::editorial_section(
			'lps-events-agenda',
			$english ? 'Agenda' : 'Agenda',
			$english ? 'Laboratory events' : 'Eventos do laboratório',
			'' !== $items
				? '<ol class="lps-agenda">' . $items . '</ol>'
				: self::events_empty_state( $locale ),
			true
		);
	}

	/**
	 * Builds one agenda row per event record.
	 *
	 * @param array<int, mixed>  $records Event records.
	 * @param string             $locale  Supported locale slug.
	 * @param DateTimeImmutable  $now     Evaluation instant.
	 * @param array<int, string> $states  When non-empty, only these states are listed.
	 */
	private static function event_agenda_items( array $records, string $locale, DateTimeImmutable $now, array $states = array() ): string {
		$english = 'en' === $locale;
		$items   = '';
		foreach ( $records as $record ) {
			if ( ! is_array( $record ) ) {
				continue;
			}
			$title = self::text( $record['title'] ?? '' );
			$slug  = self::text( $record['slug'] ?? '' );
			if ( '' === $title || '' === $slug ) {
				continue;
			}
			$state = TrustSurfacePolicy::event_state(
				self::text( $record['status'] ?? '' ),
				self::text( $record['starts_at'] ?? '' ),
				self::text( $record['ends_at'] ?? '' ),
				$now
			);
			if ( array() !== $states && ! in_array( $state, $states, true ) ) {
				continue;
			}
			$starts = self::text( $record['starts_at'] ?? '' );
			$venue  = self::text( $record['venue'] ?? '' );
			$stamp  = substr( $starts, 0, 10 );
			$url    = self::single_path( 'lps_event', $locale, $slug );
			$items .= '<li data-state="' . self::esc( $state ) . '">';
			$items .= '<div class="lps-event-date"><strong>';
			$items .= '' !== $stamp ? '<time datetime="' . self::esc( $stamp ) . '">' . self::esc( $stamp ) . '</time>' : '—';
			$items .= '</strong><span>' . self::esc( self::EVENT_LABELS[ $state ][ $locale ] ?? '' ) . '</span></div>';
			$items .= '<div><h3><a href="' . self::esc( $url ) . '">' . self::esc( $title ) . '</a></h3>';
			$items .= '<p>';
			$items .= '<span class="lps-event-state">' . self::esc( self::EVENT_LABELS[ $state ][ $locale ] ?? '' ) . '</span>';
			if ( '' !== $venue ) {
				$items .= ' <span class="lps-event-venue">' . self::esc( $venue ) . '</span>';
			}
			$items .= self::translation_chip( $record, $locale ) . '</p>';
			$items .= '</div>';
			$items .= '<a class="lps-more" href="' . self::esc( $url ) . '">' . self::esc( $english ? 'Know more' : 'Saiba mais' ) . '</a>';
			$items .= '</li>';
		}
		return $items;
	}

	/**
	 * Renders the empty state the agenda shows when nothing is scheduled.
	 *
	 * @param string $locale Supported locale slug.
	 */
	private static function events_empty_state( string $locale ): string {
		$english = 'en' === $locale;
		return '<div class="lps-empty-state">'
			. '<p class="lps-kicker">' . self::esc( $english ? 'NO SCHEDULED EVENTS' : 'SEM EVENTOS AGENDADOS' ) . '</p>'
			. '<h2 id="lps-agenda-empty">' . self::esc( $english ? 'No event is currently scheduled' : 'Nenhum evento agendado no momento' ) . '</h2>'
			. '<p>' . self::esc(
				$english
					? 'The public source does not record a calendar of future events. When a call, seminar or defence is scheduled, it is published here.'
					: 'A fonte pública não registra uma agenda de eventos futuros. Quando houver chamada, seminário ou defesa agendada, ela é publicada aqui.'
			) . '</p></div>';
	}

	/**
	 * Renders one news record as a dated reading page.
	 *
	 * @param array<string, mixed> $record News record.
	 * @param string               $locale Supported locale slug.
	 */
	public static function render_news( array $record, string $locale ): string {
		$english = 'en' === $locale;
		$html    = '<article class="lps-news">';
		$html   .= '<div class="lps-page-header"><div class="lps-page-header-inner">';
		$html   .= '<p class="lps-kicker">' . self::esc( $english ? 'News' : 'Notícia' ) . '</p>';
		$html   .= '<h1 class="lps-page-title">' . self::esc( self::text( $record['title'] ?? '' ) ) . '</h1>';
		$date    = self::text( $record['date'] ?? '' );
		if ( '' !== $date ) {
			$html .= '<p class="lps-meta"><time datetime="' . self::esc( substr( $date, 0, 10 ) ) . '">' . self::esc( substr( $date, 0, 10 ) ) . '</time></p>';
		}
		$summary = self::text( $record['summary'] ?? '' );
		if ( '' !== $summary ) {
			$html .= '<p class="lps-lead">' . self::esc( $summary ) . '</p>';
		}
		$html .= self::translation_notice( $record, $locale );
		$html .= '</div></div>';
		$body  = self::text( $record['body'] ?? '' );
		if ( '' !== $body ) {
			$rendered = function_exists( 'do_blocks' ) ? (string) do_blocks( $body ) : $body;
			$html    .= '<div class="lps-body lps-reading">' . ( function_exists( 'wp_kses_post' ) ? wp_kses_post( $rendered ) : $rendered ) . '</div>';
		}
		return $html . '</article>';
	}

	/**
	 * Renders the news listing: dated records, then the events agenda, then the
	 * legacy-records band.
	 *
	 * @param array<int, mixed>      $records News records.
	 * @param string                 $locale  Supported locale slug.
	 * @param array<int, mixed>      $events  Event records for the agenda band.
	 * @param DateTimeImmutable|null $now     Evaluation instant; defaults to now.
	 */
	public static function render_news_listing( array $records, string $locale, array $events = array(), ?DateTimeImmutable $now = null ): string {
		$english = 'en' === $locale;
		$now     = $now instanceof DateTimeImmutable ? $now : new DateTimeImmutable( 'now' );
		$items   = '';
		foreach ( $records as $record ) {
			if ( ! is_array( $record ) ) {
				continue;
			}
			$title = self::text( $record['title'] ?? '' );
			$slug  = self::text( $record['slug'] ?? '' );
			if ( '' === $title || '' === $slug ) {
				continue;
			}
			$url     = self::single_path( 'lps_news', $locale, $slug );
			$date    = self::text( $record['date'] ?? '' );
			$label   = self::agenda_date_label( $date );
			$items  .= '<li>';
			$items  .= '<div class="lps-event-date"><strong>' . self::esc( '' !== $label ? $label : '—' ) . '</strong><span>' . self::esc( $english ? 'News' : 'Notícia' ) . '</span></div>';
			$items  .= '<div><h3><a href="' . self::esc( $url ) . '">' . self::esc( $title ) . '</a></h3>';
			$summary = self::text( $record['summary'] ?? '' );
			if ( '' !== $summary ) {
				$items .= '<p>' . self::esc( $summary ) . '</p>';
			}
			$meta = '';
			if ( 1 === preg_match( '/^\d{4}-\d{2}-\d{2}/', $date ) ) {
				$meta .= '<time datetime="' . self::esc( substr( $date, 0, 10 ) ) . '">' . self::esc( substr( $date, 0, 10 ) ) . '</time>';
			}
			$meta .= self::translation_chip( $record, $locale );
			if ( '' !== $meta ) {
				$items .= '<p class="lps-meta lps-mt-4">' . $meta . '</p>';
			}
			$items .= '</div>';
			$items .= '<span class="lps-meta">' . self::esc( '' !== $label ? $label : '—' ) . '</span>';
			$items .= '</li>';
		}
		$html  = '<section class="lps-section lps-section--flush" aria-labelledby="lps-news-list">';
		$html .= '<div class="lps-section-head"><div><p class="lps-kicker">' . self::esc( $english ? 'Records' : 'Registros' ) . '</p><h2 id="lps-news-list">' . self::esc( $english ? 'Institutional timeline' : 'Linha do tempo institucional' ) . '</h2></div></div>';
		$html .= '' !== $items
			? '<ol class="lps-agenda">' . $items . '</ol>'
			: '<p class="lps-empty">' . self::esc( $english ? 'No published news' : 'Nenhuma notícia publicada' ) . '</p>';
		$html .= '</section>';

		$event_items = self::event_agenda_items( $events, $locale, $now, array( 'upcoming', 'ongoing', 'postponed' ) );
		$html       .= '<section class="lps-section" id="agenda" aria-labelledby="lps-news-agenda">';
		$html       .= '<div class="lps-section-head"><div><p class="lps-kicker">' . self::esc( 'Agenda' ) . '</p><h2 id="lps-news-agenda">' . self::esc( $english ? 'Upcoming events' : 'Próximos eventos' ) . '</h2></div></div>';
		$html       .= '' !== $event_items ? '<ol class="lps-agenda">' . $event_items . '</ol>' : self::events_empty_state( $locale );
		$html       .= '</section>';

		$html .= self::editorial_section(
			'lps-news-legacy',
			$english ? 'Previous site' : 'Site anterior',
			$english ? 'Where the older records live' : 'Onde ficam os registros antigos',
			'<div class="lps-grid lps-grid--2">'
				. self::editorial_card(
					$english ? 'The previous institutional site' : 'Site institucional anterior',
					$english
						? 'The laboratory site hosted on Google Sites remains the record for older material, including opportunities announcements and professor pages.'
						: 'O site do laboratório hospedado no Google Sites permanece como registro do material antigo, incluindo avisos de oportunidades e páginas de professores.'
				)
				. self::editorial_card(
					$english ? 'Lossless redirects are planned' : 'Redirecionamentos sem perda estão planejados',
					$english
						? 'Every legacy URL inventoried for migration keeps a recorded disposition, so no published address is silently dropped.'
						: 'Cada URL legada inventariada para migração mantém uma destinação registrada, de modo que nenhum endereço publicado é descartado em silêncio.'
				)
				. '</div>'
		);
		return $html;
	}

	/**
	 * Renders the agenda tile label: the year of an ISO date, or the stored
	 * editorial label ("Desde 1988", "2021-2022") verbatim.
	 *
	 * @param string $date Stored date or label.
	 */
	private static function agenda_date_label( string $date ): string {
		return 1 === preg_match( '/^\d{4}(?:-\d{2}-\d{2}|[\sT]|$)/', $date ) ? substr( $date, 0, 4 ) : $date;
	}

	/**
	 * Renders an institutional page, omitting unsourced or stale claims.
	 *
	 * @param array<string, mixed> $page   Institutional page record.
	 * @param string               $locale Supported locale slug.
	 * @param DateTimeImmutable    $now    Evaluation instant.
	 */
	public static function render_institutional_page( array $page, string $locale, DateTimeImmutable $now ): string {
		$english = 'en' === $locale;
		$key     = self::text( $page['key'] ?? '' );

		// The page template already renders the record title as the single `h1`.
		// Repeating it here produced two level-one headings on every institutional
		// page, so the article is named instead of re-titled.
		$title   = self::text( $page['title'] ?? '' );
		$html    = '<article class="lps-institutional" data-page="' . self::esc( $key ) . '"';
		$html   .= '' === $title ? '' : ' aria-label="' . self::esc( $title ) . '"';
		$html   .= '>';
		$summary = self::text( $page['summary'] ?? '' );
		$html   .= '<div class="lps-with-aside"><div class="lps-stack">';
		if ( '' !== $summary ) {
			$html .= '<p class="lps-summary lps-lead">' . self::esc( $summary ) . '</p>';
		}
		$html .= self::translation_notice( $page, $locale );

		$facts = '';
		foreach ( array(
			'affiliation' => $english ? 'Affiliation' : 'Vínculo institucional',
			'governance'  => $english ? 'Governance' : 'Governança',
			'location'    => $english ? 'Location' : 'Localização',
			'funding'     => $english ? 'Funding and partners' : 'Financiamento e parceiros',
		) as $field => $label ) {
			$value = self::text( $page[ $field ] ?? '' );
			if ( '' !== $value ) {
				$facts .= '<dt>' . self::esc( $label ) . '</dt><dd class="lps-fact lps-fact-' . self::esc( $field ) . '">' . self::esc( $value ) . '</dd>';
			}
		}
		if ( '' !== $facts ) {
			$html .= '<dl class="lps-facts">' . $facts . '</dl>';
		}
		$html .= self::institutional_sections( $key, $locale );
		$html .= '</div>';

		// The verification aside carries every sourced claim with its review date,
		// the reporting contact, and the page review stamp. Claims that fail the
		// source/review policy are never rendered; their absence is announced as an
		// explicit warning instead of a silent gap, and a page without a recorded
		// review says so rather than implying one.
		$claims  = is_array( $page['claims'] ?? null ) ? $page['claims'] : array();
		$items   = '';
		$dropped = 0;
		foreach ( $claims as $claim ) {
			if ( ! is_array( $claim ) ) {
				continue;
			}
			$statement = self::text( $claim['statement'] ?? '' );
			if ( '' === $statement ) {
				continue;
			}
			$errors = TrustSurfacePolicy::claim_errors(
				array(
					'_lps_claim_verified'    => ! empty( $claim['verified'] ),
					'_lps_claim_source_url'  => self::text( $claim['source_url'] ?? '' ),
					'_lps_claim_reviewed_at' => self::text( $claim['reviewed_at'] ?? '' ),
				),
				$now
			);
			if ( array() !== $errors ) {
				++$dropped;
				continue;
			}
			$source = self::safe_url( self::text( $claim['source_url'] ?? '' ) );
			$items .= '<li>' . self::esc( $statement );
			if ( '' !== $source ) {
				$items .= ' <a class="lps-claim-source lps-breakable" href="' . self::esc( $source ) . '" rel="nofollow noopener">' . self::esc( $source ) . '</a>';
			}
			$claim_reviewed = self::text( $claim['reviewed_at'] ?? '' );
			if ( '' !== $claim_reviewed ) {
				$items .= ' <span class="lps-meta">' . self::esc( $english ? 'reviewed ' : 'revisado em ' ) . '<time datetime="' . self::esc( $claim_reviewed ) . '">' . self::esc( $claim_reviewed ) . '</time></span>';
			}
			$items .= '</li>';
		}

		$aside  = '<aside class="lps-verification" aria-labelledby="lps-verification-title">';
		$aside .= '<h2 id="lps-verification-title">' . self::esc( $english ? 'Verification' : 'Verificação' ) . '</h2>';
		if ( '' !== $items ) {
			$aside .= '<section class="lps-claims"><h3>' . self::esc( $english ? 'Verified institutional context' : 'Contexto institucional verificado' ) . '</h3><ul>' . $items . '</ul></section>';
		}
		if ( 0 < $dropped ) {
			$aside .= '<p class="lps-claims-warning" role="status">' . self::esc( $english ? 'Some institutional claims are omitted pending source or review.' : 'Algumas afirmações institucionais foram omitidas por falta de fonte ou revisão.' ) . '</p>';
		}

		$report = self::text( $page['report_contact'] ?? '' );
		if ( '' !== $report && self::is_email( $report ) ) {
			$aside .= '<p class="lps-report-contact"><a class="lps-breakable" href="mailto:' . self::esc( $report ) . '">' . self::esc( $report ) . '</a></p>';
		}

		$reviewed = self::text( $page['reviewed_at'] ?? '' );
		if ( '' !== $reviewed ) {
			$aside .= '<p class="lps-reviewed-at">' . self::esc( $english ? 'Last reviewed' : 'Última revisão' ) . ': <time datetime="' . self::esc( $reviewed ) . '">' . self::esc( $reviewed ) . '</time></p>';
		} else {
			$aside .= '<p class="lps-claims-warning" role="status">' . self::esc( $english ? 'No recorded content review.' : 'Nenhuma revisão de conteúdo registrada.' ) . '</p>';
		}

		return $html . $aside . '</aside></div></article>';
	}

	/**
	 * Renders the editorial sections owned by an institutional page key.
	 *
	 * Each institutional composition is versioned here rather than stored in
	 * post content, so the localized copy ships with the theme and survives
	 * content reimports.
	 *
	 * @param string $key    Institutional page key.
	 * @param string $locale Supported locale slug.
	 */
	private static function institutional_sections( string $key, string $locale ): string {
		if ( 'about' === $key ) {
			return self::about_sections( $locale );
		}
		if ( 'privacy' === $key ) {
			return self::privacy_sections( $locale );
		}
		if ( 'accessibility' === $key ) {
			return self::accessibility_sections( $locale );
		}
		if ( 'visual-identity' === $key ) {
			return self::identity_sections( $locale );
		}
		return '';
	}

	/**
	 * Renders the About composition: timeline, purpose, values, identity,
	 * partner marquee and location facts.
	 *
	 * @param string $locale Supported locale slug.
	 */
	private static function about_sections( string $locale ): string {
		$english  = 'en' === $locale;
		$history  = array(
			array(
				'1988',
				$english
					? 'Start of the UFRJ–CERN collaboration on signal processing for high-energy physics, started and coordinated for ten years by Prof. Luiz Pereira Calôba.'
					: 'Início da colaboração UFRJ–CERN em processamento de sinais para física de altas energias, iniciada e coordenada por dez anos pelo Prof. Luiz Pereira Calôba.',
			),
			array(
				'1989',
				$english
					? 'Neural network courses begin, with strong dissemination of the field across Brazilian engineering.'
					: 'Cursos de redes neurais iniciados, com forte atuação de divulgação da área nas engenharias no Brasil.',
			),
			array(
				'1996',
				$english
					? 'Founding of the Signal Processing Laboratory at UFRJ, dedicated to teaching, research and extension.'
					: 'Fundação do Laboratório de Processamento de Sinais na UFRJ, dedicado a ensino, pesquisa e extensão.',
			),
			array(
				'2018',
				$english
					? 'LPS begins signing ATLAS experiment (CERN) publications and works on online electron classification with neural networks.'
					: 'O LPS passa a assinar publicações do experimento ATLAS (CERN) e atua na classificação online de elétrons com redes neurais.',
			),
			array(
				'2021',
				$english
					? 'Coordination of the international ATLAS online filtering group for electrons and photons.'
					: 'Coordenação do grupo internacional de filtragem online para elétrons e fótons do ATLAS.',
			),
			array(
				'2025',
				$english
					? 'A laboratory lecturer joins the Department of Electronic and Computer Engineering at Poli/UFRJ as adjunct professor.'
					: 'Docente do laboratório assume como professor adjunto no Departamento de Engenharia Eletrônica e de Computação da Poli/UFRJ.',
			),
		);
		$timeline = '<ol class="lps-timeline">';
		foreach ( $history as $entry ) {
			$timeline .= '<li><span class="lps-timeline-year">' . self::esc( $entry[0] ) . '</span><div><p>' . self::esc( $entry[1] ) . '</p></div></li>';
		}
		$timeline .= '</ol>';
		$html      = self::editorial_section(
			'about-history',
			$english ? 'History' : 'História',
			$english ? 'A timeline of the laboratory' : 'Linha do tempo do laboratório',
			$timeline,
			true
		);
		$mission   = $english
			? 'To generate knowledge and technological innovation through teaching, research and extension, training highly qualified professionals at every level from secondary school to post-doctorate, and acting as an agent of scientific and technological advance in strategic partnerships with the public and private sectors.'
			: 'Gerar conhecimento e inovação tecnológica por meio de atividades de ensino, pesquisa e extensão, formando profissionais altamente qualificados em todos os níveis, do ensino médio ao pós-doutorado, e atuando como agente de avanço científico e tecnológico em parcerias estratégicas com os setores público e privado.';
		$vision    = $english
			? 'To be a national and international reference laboratory in the development of innovative engineering solutions, focused on computational intelligence, signal processing and software development, applying machine learning models in complex environments — critical systems, noisy scenarios and applications demanding robustness and interpretability.'
			: 'Ser um laboratório de referência nacional e internacional no desenvolvimento de soluções inovadoras em engenharia, com foco em inteligência computacional, processamento de sinais e desenvolvimento de software, aplicando modelos de aprendizado de máquina em ambientes complexos — sistemas críticos, cenários ruidosos e aplicações que exigem robustez e interpretabilidade.';
		$html     .= self::editorial_section(
			'about-mission',
			$english ? 'Purpose' : 'Propósito',
			$english ? 'Mission and vision' : 'Missão e visão',
			'<div class="lps-grid lps-grid--2">'
				. self::editorial_card( $english ? 'Mission' : 'Missão', $mission, true )
				. self::editorial_card( $english ? 'Vision' : 'Visão', $vision )
				. '</div>'
		);
		$values    = array(
			array(
				$english ? 'Innovation' : 'Inovação',
				$english
					? 'A vocation for innovation in electrical engineering and computing, including the creation of technology-based companies.'
					: 'Vocação para a inovação em engenharia elétrica e computação, inclusive com a criação de empresas de base tecnológica.',
			),
			array(
				$english ? 'Collaboration' : 'Colaboração',
				$english
					? 'Solid partnerships with national and international researchers and institutions in energy, defence, medicine and physics.'
					: 'Parcerias sólidas com pesquisadores e instituições nacionais e internacionais em energia, defesa, medicina e física.',
			),
			array(
				$english ? 'Excellence' : 'Excelência',
				$english
					? 'Pursuit of technical, scientific and innovation excellence in every activity.'
					: 'Busca da excelência técnica, científica e de inovação em todas as atividades.',
			),
			array(
				$english ? 'Education' : 'Formação',
				$english
					? 'Preparation of highly qualified professionals for academia and industry, in a stimulating environment.'
					: 'Preparação de profissionais altamente qualificados para a academia e a indústria, em ambiente estimulante.',
			),
		);
		$cards     = '<div class="lps-grid lps-grid--4">';
		foreach ( $values as $value ) {
			$cards .= self::editorial_card( $value[0], $value[1] );
		}
		$cards  .= '</div>';
		$html   .= self::editorial_section(
			'about-values',
			$english ? 'Values' : 'Valores',
			$english ? 'What guides the work' : 'O que orienta o trabalho',
			$cards
		);
		$html   .= self::about_identity_section( $locale );
		$marquee = '';
		if ( class_exists( Homepage::class ) ) {
			$marquee = Homepage::partner_marquee_markup( Homepage::partner_logos( $locale ) );
		}
		if ( '' !== $marquee ) {
			$html .= self::editorial_section(
				'about-partners',
				$english ? 'Partners' : 'Parceiros',
				$english ? 'Institutions working with the laboratory' : 'Instituições que atuam com o laboratório',
				$marquee
			);
		}
		$address = 'Av. Athos da Silveira Ramos, 149 — Centro de Tecnologia, Bloco H, sala 220 — Ilha do Fundão — Rio de Janeiro/RJ — CEP 21941-914';
		$phone   = '(21) 3938-8205 — ' . ( $english ? 'Extension 8205' : 'Ramal 8205' );
		$office  = 'secretaria@lps.ufrj.br';
		$body    = '<div><dl class="lps-facts">'
			. '<dt>' . self::esc( $english ? 'Address' : 'Endereço' ) . '</dt><dd>' . self::esc( $address ) . '</dd>'
			. '<dt>' . self::esc( $english ? 'Phone' : 'Telefone' ) . '</dt><dd>' . self::esc( $phone ) . '</dd>'
			. '<dt>' . self::esc( $english ? 'Administrative office' : 'Secretaria' ) . '</dt><dd>' . self::esc( $office ) . '</dd>'
			. '</dl>'
			. '<p><a class="lps-more" href="https://www.google.com/maps/search/?api=1&amp;query=' . self::esc( rawurlencode( $address ) ) . '" target="_blank" rel="noopener">' . self::esc( $english ? 'Open in Google Maps' : 'Abrir no Google Maps' ) . '</a></p></div>';
		// The content security policy forbids framed documents, so the map is a
		// plain link instead of the showcase's embedded iframe.
		$html .= self::editorial_section(
			'about-location',
			$english ? 'Location' : 'Localização',
			$english ? 'Where the laboratory is' : 'Onde o laboratório está',
			$body
		);
		return $html;
	}

	/**
	 * Renders the About identity section: meaning paragraphs and the mark card.
	 *
	 * @param string $locale Supported locale slug.
	 */
	private static function about_identity_section( string $locale ): string {
		$english    = 'en' === $locale;
		$paragraphs = $english
			? array(
				'The laboratory mark is made of a signal (the waveform) and the LPS lettering, accompanied by the full name and the Computational Intelligence descriptor.',
				"The signal represents the laboratory's object of work: reading, treating and interpreting signals. Blue is the institutional colour inherited from the original identity; the gradient follows the amplitude variation of the waveform.",
				'The official applications — blue and white signal, blue and white full mark, plus the complete brand package — are maintained by those responsible for laboratory communications.',
			)
			: array(
				'A marca do laboratório é composta por um sinal (a forma de onda) e pela sigla LPS, acompanhadas do nome por extenso e do descritor Inteligência Computacional.',
				'O sinal representa o objeto de trabalho do laboratório: a leitura, o tratamento e a interpretação de sinais. O azul é a cor institucional herdada da identidade original; o degradê acompanha a variação de amplitude da forma de onda.',
				'As aplicações oficiais — sinal em azul e branco, marca completa em azul e branco, além do pacote completo da marca — são mantidas pelos responsáveis pela comunicação do laboratório.',
			);
		$flow       = '<div class="lps-flow">';
		foreach ( $paragraphs as $paragraph ) {
			$flow .= '<p class="lps-reading">' . self::esc( $paragraph ) . '</p>';
		}
		$identity_url = TrustRoutes::page_path( 'visual-identity', $locale );
		if ( '' !== $identity_url ) {
			$flow .= '<p><a class="lps-more" href="' . self::esc( $identity_url ) . '">' . self::esc( $english ? 'Mark applications and files' : 'Aplicações e arquivos da marca' ) . '</a></p>';
		}
		$flow .= '</div>';
		$card  = '<div class="lps-card lps-card--flush"><div class="lps-card-media lps-card-media--plain" style="min-block-size:14rem;background:var(--color-surface)" aria-hidden="true">'
			. '<img src="' . self::esc( self::theme_uri( 'assets/brand/lps_coppe_blue_lockup.svg' ) ) . '" alt="" width="1622" height="804" loading="lazy" decoding="async" style="object-fit:contain;padding:var(--space-6);background:transparent">'
			. '</div></div>';
		return self::editorial_section(
			'about-identity',
			$english ? 'Identity' : 'Identidade',
			$english ? 'The laboratory mark' : 'A marca do laboratório',
			'<div class="lps-grid lps-grid--2">' . $flow . $card . '</div>'
		);
	}

	/**
	 * Renders the Privacy composition: four technical facts and a scope note.
	 *
	 * @param string $locale Supported locale slug.
	 */
	private static function privacy_sections( string $locale ): string {
		$english = 'en' === $locale;
		$facts   = $english
			? array(
				'No cookie is set for anonymous visitors.',
				'No third-party request is made at runtime: fonts, images and scripts are served from this domain.',
				'No analytics, advertising or tracking tool is embedded.',
				'No personal data is collected from visitors: there is no public form on this site.',
			)
			: array(
				'Nenhum cookie é gravado para visitantes anônimos.',
				'Nenhuma requisição a terceiros é feita em tempo de execução: fontes, imagens e scripts são servidos pelo próprio domínio.',
				'Nenhuma ferramenta de análise, publicidade ou rastreamento é embarcada.',
				'Dados pessoais de visitantes não são coletados: não há formulário público neste site.',
			);
		$items   = '<ul class="lps-record-list">';
		foreach ( $facts as $fact ) {
			$items .= '<li>' . self::esc( $fact ) . '</li>';
		}
		$items .= '</ul>';
		$html   = self::editorial_section(
			'privacy-facts',
			$english ? 'Technical behaviour' : 'Comportamento técnico',
			$english ? 'Four facts' : 'Quatro fatos',
			$items,
			true
		);
		$note   = $english
			? "This page describes the technical behaviour of the site. The legal basis for personal-data processing in other institutional contexts is the university's responsibility."
			: 'Esta página descreve o comportamento técnico do site. A base legal para o tratamento de dados pessoais em outros contextos institucionais é de responsabilidade da universidade.';
		$html  .= self::editorial_section(
			'privacy-note',
			$english ? 'Scope' : 'Escopo',
			$english ? 'What this page covers' : 'O que esta página cobre',
			'<div class="lps-alert lps-alert-info"><p>' . self::esc( $note ) . '</p></div>'
		);
		return $html;
	}

	/**
	 * Renders the Accessibility composition: commitments checklist, reporting
	 * note and the release-verification band.
	 *
	 * @param string $locale Supported locale slug.
	 */
	private static function accessibility_sections( string $locale ): string {
		$english   = 'en' === $locale;
		$checklist = $english
			? array(
				'Text contrast of at least 4.5:1, verified by a computed audit rather than by eye.',
				'Visible focus on every interactive element, with a 3:1 focus indicator.',
				'Full keyboard operation, including navigation, search and the mobile menu.',
				'Reduced-motion preference switches every transition off.',
				'Reflow at 320 CSS pixels and 200% zoom without horizontal scrolling.',
				'One H1 per page, sequential headings, landmarks and a skip link.',
				'44px minimum target size for controls.',
				'The site works without JavaScript: navigation and search are native form and disclosure elements.',
			)
			: array(
				'Contraste de texto de ao menos 4,5:1, verificado por auditoria computada e não a olho.',
				'Foco visível em todos os elementos interativos, com indicador de 3:1.',
				'Operação completa por teclado, incluindo navegação, busca e menu móvel.',
				'A preferência de movimento reduzido desliga todas as transições.',
				'Refluxo em 320 CSS pixels e 200% de zoom sem rolagem horizontal.',
				'Um H1 por página, títulos sequenciais, marcos de navegação e link de salto.',
				'Tamanho mínimo de 44px para alvos de toque.',
				'O site funciona sem JavaScript: navegação e busca são elementos nativos de formulário e disclosure.',
			);
		$items     = '<ul class="lps-record-list">';
		foreach ( $checklist as $item ) {
			$items .= '<li>' . self::esc( $item ) . '</li>';
		}
		$items .= '</ul>';
		$html   = self::editorial_section(
			'a11y-checklist',
			$english ? 'Commitments' : 'Compromissos',
			$english ? 'What this site guarantees' : 'O que este site garante',
			$items,
			true
		);
		$report = $english
			? 'A formal channel for reporting accessibility problems has not yet been named by the institution. Until it exists, the laboratory office receives reports at secretaria@lps.ufrj.br.'
			: 'Um canal formal de relato de problemas de acessibilidade ainda não foi nomeado pela instituição. Até que exista, a secretaria do laboratório recebe relatos pelo e-mail secretaria@lps.ufrj.br.';
		$note   = $english
			? 'Reports are answered by the laboratory office while a named channel is pending institutional decision.'
			: 'Os relatos são respondidos pela secretaria do laboratório enquanto o canal nomeado aguarda decisão institucional.';
		$html  .= self::editorial_section(
			'a11y-report',
			$english ? 'Reporting' : 'Relato',
			$english ? 'Found a barrier?' : 'Encontrou uma barreira?',
			'<div class="lps-alert lps-alert-warning"><p>' . self::esc( $report ) . '</p></div>'
				. '<p class="lps-mt-6">' . self::esc( $note ) . '</p>'
		);
		$cta    = '<div class="lps-cta-band lps-cta-band--split"><div><h2>'
			. self::esc( $english ? 'Accessibility is verified on every release' : 'A acessibilidade é verificada a cada publicação' )
			. '</h2><p>'
			. self::esc( $english ? 'Contrast, keyboard operation, reflow and reduced motion are part of the release checks, together with link and schema validation.' : 'Contraste, operação por teclado, refluxo e movimento reduzido fazem parte das checagens de publicação, junto com validação de links e de esquema.' )
			. '</p></div><div class="lps-button-row">'
			. '<a class="lps-button lps-button-primary" href="' . self::esc( TrustRoutes::page_path( 'privacy', $locale ) ) . '">' . self::esc( $english ? 'Privacy' : 'Privacidade' ) . '</a>'
			. '</div></div>';
		$html  .= '<section class="lps-section">' . $cta . '</section>';
		return $html;
	}

	/**
	 * Renders the Visual identity composition: meaning, approved variants and
	 * the usage rules.
	 *
	 * @param string $locale Supported locale slug.
	 */
	private static function identity_sections( string $locale ): string {
		$english    = 'en' === $locale;
		$paragraphs = $english
			? array(
				'The laboratory mark is made of a signal (the waveform) and the LPS lettering, accompanied by the full name and the Computational Intelligence descriptor.',
				"The signal represents the laboratory's object of work: reading, treating and interpreting signals. Blue is the institutional colour inherited from the original identity; the gradient follows the amplitude variation of the waveform.",
				'The official applications — blue and white signal, blue and white full mark, plus the complete brand package — are maintained by those responsible for laboratory communications.',
			)
			: array(
				'A marca do laboratório é composta por um sinal (a forma de onda) e pela sigla LPS, acompanhadas do nome por extenso e do descritor Inteligência Computacional.',
				'O sinal representa o objeto de trabalho do laboratório: a leitura, o tratamento e a interpretação de sinais. O azul é a cor institucional herdada da identidade original; o degradê acompanha a variação de amplitude da forma de onda.',
				'As aplicações oficiais — sinal em azul e branco, marca completa em azul e branco, além do pacote completo da marca — são mantidas pelos responsáveis pela comunicação do laboratório.',
			);
		$flow       = '<div class="lps-flow lps-reading">';
		foreach ( $paragraphs as $paragraph ) {
			$flow .= '<p>' . self::esc( $paragraph ) . '</p>';
		}
		$flow .= '</div>';
		$html  = self::editorial_section(
			'identity-meaning',
			$english ? 'Meaning' : 'Significado',
			$english ? 'Why the mark looks like this' : 'Por que a marca é assim',
			$flow,
			true
		);
		$marks = array(
			array( 'assets/img/mark/lps-mark-full.svg', $english ? 'Full mark, colour' : 'Marca completa, colorida', $english ? 'Light surfaces only. Minimum 240px wide in the masthead.' : 'Somente sobre superfícies claras. Mínimo de 240px de largura no cabeçalho.', false, 2052, 301 ),
			array( 'assets/brand/lps_logo_compact.svg', $english ? 'Compact mark, colour' : 'Marca compacta, colorida', $english ? 'Constrained places: mobile masthead, cards, e-mail signatures.' : 'Locais restritos: cabeçalho móvel, cartões, assinaturas de e-mail.', false, 2052, 301 ),
			array( 'assets/brand/lps_coppe_reversed_lockup.svg', $english ? 'Full mark, reversed' : 'Marca completa, reversa', $english ? 'Dark navy surfaces: footer, covers, presentation closing slides.' : 'Superfícies azul-escuras: rodapé, capas, slides de encerramento.', true, 2052, 301 ),
			array( 'assets/img/mark/lps-mark-favicon.svg', $english ? 'Signal only, mono' : 'Somente o sinal, monocromático', $english ? 'Decorative or ruled contexts where colour cannot be printed.' : 'Contextos decorativos ou impressos sem cor.', false, 1040, 520 ),
			array( 'assets/brand/lps_coppe_blue.svg', $english ? 'COPPE/Poli/UFRJ lockup' : 'Marca conjunta COPPE/Poli/UFRJ', $english ? 'Official institutional artwork pairing the LPS mark with COPPE, Poli and UFRJ lettering.' : 'Arte institucional oficial que une a marca LPS ao letreiro COPPE, Poli e UFRJ.', false, 2047, 1448 ),
		);
		$cards = '<div class="lps-grid lps-grid--2">';
		foreach ( $marks as $mark ) {
			$surface = $mark[3] ? 'var(--color-anchor)' : 'var(--color-surface)';
			$cards  .= '<article class="lps-card lps-card--flush"><div class="lps-card-media lps-card-media--plain" style="min-block-size:11rem;background:' . $surface . '" aria-hidden="true">'
				. '<img src="' . self::esc( self::theme_uri( $mark[0] ) ) . '" alt="" width="' . self::esc( (string) $mark[4] ) . '" height="' . self::esc( (string) $mark[5] ) . '" loading="lazy" decoding="async" style="object-fit:contain;padding:1.5rem;background:transparent">'
				. '</div><div class="lps-card-body" style="padding:var(--space-6)"><h3 class="lps-card-title">' . self::esc( $mark[1] ) . '</h3><p>' . self::esc( $mark[2] ) . '</p></div></article>';
		}
		$cards .= '</div>';
		$html  .= self::editorial_section(
			'identity-applications',
			$english ? 'Applications' : 'Aplicações',
			$english ? 'Approved variants' : 'Variantes aprovadas',
			$cards
		);
		$rules  = $english
			? array(
				'Clear space around the mark equals the height of the letter L in every direction.',
				'Never recolour, stretch, rotate or add effects to the mark; the waveform gradient is part of the artwork.',
				'On dark navy surfaces use the reversed variant; on light surfaces use the colour variant.',
				'The mark never replaces the institutional marks of UFRJ or COPPE, which stay text-only beside it.',
			)
			: array(
				'O espaço livre ao redor da marca equivale à altura da letra L em todas as direções.',
				'Nunca recolorir, esticar, rotacionar ou aplicar efeitos à marca; o degradê da forma de onda faz parte da arte.',
				'Sobre superfícies azul-escuras use a variante reversa; sobre superfícies claras use a variante colorida.',
				'A marca nunca substitui as marcas institucionais da UFRJ ou da COPPE, que permanecem em texto ao lado dela.',
			);
		$split  = '<div class="lps-split"><div class="lps-flow">';
		foreach ( $rules as $rule ) {
			$split .= '<p class="lps-reading">' . self::esc( $rule ) . '</p>';
		}
		$split .= '</div><div>' . self::editorial_card(
			$english ? 'Brand package' : 'Pacote da marca',
			$english
				? 'The laboratory keeps the official files — blue and white signal, blue and white full mark, and the complete brand package — in its own drive, available through the administrative office.'
				: 'O laboratório mantém os arquivos oficiais — sinal azul e branco, marca completa azul e branca, e o pacote completo da marca — em seu próprio drive, disponibilizado pela secretaria administrativa.',
			false,
			array( 'mailto:secretaria@lps.ufrj.br', $english ? 'Request the files' : 'Solicitar os arquivos' )
		) . '</div></div>';
		$html  .= self::editorial_section(
			'identity-rules',
			$english ? 'Usage' : 'Uso',
			$english ? 'Rules that keep the mark legible' : 'Regras que mantêm a marca legível',
			$split
		);
		return $html;
	}

	/**
	 * Wraps section content in the editorial section shell, with an optional
	 * lead line and/or a "more" action link beside the heading.
	 *
	 * @param string                                    $id     Heading id used as the label reference.
	 * @param string                                    $kicker Section kicker.
	 * @param string                                    $title  Section title.
	 * @param string                                    $inner  Prepared inner markup.
	 * @param bool                                      $flush  Whether the section drops its top padding.
	 * @param array{href?: string, label?: string}|null $action Optional section-head action link.
	 * @param string                                    $lead   Optional section-head lead line.
	 */
	public static function editorial_section( string $id, string $kicker, string $title, string $inner, bool $flush = false, ?array $action = null, string $lead = '' ): string {
		$head = '<div class="lps-section-head"><div><p class="lps-kicker">' . self::esc( $kicker ) . '</p><h2 id="' . self::esc( $id ) . '">' . self::esc( $title ) . '</h2></div>';
		if ( null !== $action || '' !== $lead ) {
			$head .= '<div>';
			$head .= '' !== $lead ? '<p class="lps-lead">' . self::esc( $lead ) . '</p>' : '';
			if ( null !== $action ) {
				$head .= '<p class="lps-mt-4"><a class="lps-more" href="' . self::esc( self::text( $action['href'] ?? '' ) ) . '">' . self::esc( self::text( $action['label'] ?? '' ) ) . '</a></p>';
			}
			$head .= '</div>';
		}
		$head .= '</div>';
		return '<section class="lps-section' . ( $flush ? ' lps-section--flush' : '' ) . '" aria-labelledby="' . self::esc( $id ) . '">' . $head . $inner . '</section>';
	}

	/**
	 * Renders a simple editorial card with an optional foot action.
	 *
	 * @param string                           $title  Card title.
	 * @param string                           $body   Card body text.
	 * @param bool                             $accent Whether the card is accented.
	 * @param array{0: string, 1: string}|null $action Optional [href, label] foot action.
	 */
	public static function editorial_card( string $title, string $body, bool $accent = false, ?array $action = null ): string {
		$card = '<article class="lps-card' . ( $accent ? ' lps-card--accent' : '' ) . '"><div class="lps-card-body">'
			. '<h3 class="lps-card-title">' . self::esc( $title ) . '</h3><p>' . self::esc( $body ) . '</p></div>';
		if ( null !== $action ) {
			$card .= '<div class="lps-card-foot"><span></span><a class="lps-more" href="' . self::esc( $action[0] ) . '">' . self::esc( $action[1] ) . '</a></div>';
		}
		return $card . '</article>';
	}

	/**
	 * Renders a record card: the showcase `card()` component, with optional
	 * linked title, tag tokens, meta line, numbered index, media slot and a
	 * foot action or prepared foot markup.
	 *
	 * @param array<string, mixed> $opts Card options. Recognized keys:
	 *                                 `title`, `body`, `href` (linked title), `tags` (string list),
	 *                                 `meta` (foot meta line), `action` (`[href, label]` pair),
	 *                                 `foot_html` (prepared markup when `action` is absent),
	 *                                 `accent`, `numbered` (index string), `media` (empty slot).
	 */
	public static function record_card( array $opts ): string {
		$title    = self::text( $opts['title'] ?? '' );
		$body     = self::text( $opts['body'] ?? '' );
		$href     = self::text( $opts['href'] ?? '' );
		$meta     = self::text( $opts['meta'] ?? '' );
		$numbered = self::text( $opts['numbered'] ?? '' );
		$foot     = self::text( $opts['foot_html'] ?? '' );
		$action   = isset( $opts['action'] ) && is_array( $opts['action'] ) ? $opts['action'] : null;
		$tags     = isset( $opts['tags'] ) && is_array( $opts['tags'] ) ? $opts['tags'] : array();

		$card  = '<article class="lps-card' . ( ! empty( $opts['accent'] ) ? ' lps-card--accent' : '' ) . ( '' !== $numbered ? ' lps-card--numbered' : '' ) . '">';
		$card .= ! empty( $opts['media'] ) ? '<div class="lps-card-media" aria-hidden="true"></div>' : '';
		$card .= '<div class="lps-card-body">';
		$card .= '' !== $numbered ? '<span class="lps-card-index" aria-hidden="true">' . self::esc( $numbered ) . '</span>' : '';
		if ( '' !== $title ) {
			$card .= '<h3 class="lps-card-title">' . ( '' !== $href ? '<a href="' . self::esc( $href ) . '">' : '' ) . self::esc( $title ) . ( '' !== $href ? '</a>' : '' ) . '</h3>';
		}
		$card .= '' !== $body ? '<p>' . self::esc( $body ) . '</p>' : '';
		if ( array() !== $tags ) {
			$card .= '<ul class="lps-term-token">';
			foreach ( $tags as $tag ) {
				$tag = self::text( $tag );
				if ( '' !== $tag ) {
					$card .= '<li>' . self::esc( $tag ) . '</li>';
				}
			}
			$card .= '</ul>';
		}
		$card        .= '</div>';
		$action_href  = null !== $action ? self::text( $action['href'] ?? '' ) : '';
		$action_label = null !== $action ? self::text( $action['label'] ?? '' ) : '';
		if ( '' !== $action_href || '' !== $meta || '' !== $foot ) {
			$card .= '<div class="lps-card-foot">' . ( '' !== $meta ? '<span class="lps-meta">' . self::esc( $meta ) . '</span>' : '<span></span>' );
			$card .= '' !== $action_href ? '<a class="lps-more" href="' . self::esc( $action_href ) . '">' . self::esc( $action_label ) . '</a>' : $foot;
			$card .= '</div>';
		}
		return $card . '</article>';
	}

	/**
	 * Renders the call-to-action band with a primary then ghost button row.
	 *
	 * @param string                          $title   Band title.
	 * @param string                          $body    Band body.
	 * @param array<int, array<string,mixed>> $actions Action descriptors (`href`, `label`).
	 */
	public static function cta_band( string $title, string $body, array $actions ): string {
		$buttons = '';
		$index   = 0;
		foreach ( $actions as $action ) {
			$href  = self::text( $action['href'] ?? '' );
			$label = self::text( $action['label'] ?? '' );
			if ( '' === $href || '' === $label ) {
				continue;
			}
			$external = str_starts_with( $href, 'http' ) ? ' rel="external noopener"' : '';
			$buttons .= '<a class="lps-button ' . ( 0 === $index ? 'lps-button-primary' : 'lps-button-ghost' ) . '" href="' . self::esc( $href ) . '"' . $external . '>' . self::esc( $label ) . '</a>';
			++$index;
		}
		return '<div class="lps-cta-band lps-cta-band--split"><div><h2>' . self::esc( $title ) . '</h2><p>' . self::esc( $body ) . '</p></div><div class="lps-button-row">' . $buttons . '</div></div>';
	}

	/**
	 * Renders an info or warning alert panel.
	 *
	 * @param string $tone  Alert tone: `info` (default) or `warning`.
	 * @param string $body  Alert body.
	 * @param string $title Optional alert title.
	 */
	public static function alert_band( string $tone, string $body, string $title = '' ): string {
		$html  = '<div class="lps-alert ' . ( 'warning' === $tone ? 'lps-alert-warning' : 'lps-alert-info' ) . '">';
		$html .= '' !== $title ? '<h2>' . self::esc( $title ) . '</h2>' : '';
		$html .= '<p>' . self::esc( $body ) . '</p></div>';
		return $html;
	}

	/**
	 * Returns the theme URI of a bundled asset, or an empty string off-WordPress.
	 *
	 * @param string $relative Asset path relative to the theme root.
	 */
	public static function theme_uri( string $relative ): string {
		return function_exists( 'get_template_directory_uri' ) ? get_template_directory_uri() . '/' . ltrim( $relative, '/' ) : '';
	}

	/**
	 * Renders the contact page with public role addresses only.
	 *
	 * @param array<int, mixed> $contacts Role contact records.
	 * @param string            $locale   Supported locale slug.
	 */
	public static function render_contact_page( array $contacts, string $locale ): string {
		$english = 'en' === $locale;
		$items   = '';
		foreach ( $contacts as $contact ) {
			if ( ! is_array( $contact ) || empty( $contact['is_role'] ) ) {
				continue;
			}
			$email = self::text( $contact['email'] ?? '' );
			$role  = self::text( $contact['role'] ?? '' );
			if ( '' === $role || ! self::is_email( $email ) ) {
				continue;
			}
			$items .= '<article class="lps-card"><div class="lps-card-body"><h3 class="lps-card-title">' . self::esc( $role ) . '</h3>';
			$items .= '<p><a class="lps-breakable" href="mailto:' . self::esc( $email ) . '">' . self::esc( $email ) . '</a></p></div>';
			$items .= '<div class="lps-card-foot"><span></span><a class="lps-more" href="mailto:' . self::esc( $email ) . '">' . self::esc( $english ? 'Write an email' : 'Escrever e-mail' ) . '</a></div></article>';
		}
		$html = '';
		if ( '' !== $items ) {
			$html .= '<section class="lps-section lps-section--flush" aria-labelledby="lps-contact-roles"><div class="lps-section-head"><div><p class="lps-kicker">' . self::esc( $english ? 'Channels' : 'Canais' ) . '</p><h2 id="lps-contact-roles">' . self::esc( $english ? 'Who to write to' : 'Para quem escrever' ) . '</h2></div></div><div class="lps-grid lps-grid--3">' . $items . '</div></section>';
		}
		$html .= self::contact_places_section( $locale ) . self::contact_pending_section( $locale );
		return $html;
	}

	/**
	 * Renders the documented laboratory addresses with their map links.
	 *
	 * @param string $locale Supported locale slug.
	 */
	private static function contact_places_section( string $locale ): string {
		$english   = 'en' === $locale;
		$buildings = array(
			array(
				$english ? 'Laboratory headquarters' : 'Sede do laboratório',
				'Av. Athos da Silveira Ramos, 149 — Centro de Tecnologia, Bloco H, sala 220 — Ilha do Fundão — Rio de Janeiro/RJ — CEP 21941-914',
				'https://www.google.com/maps/search/?api=1&query=Centro+de+Tecnologia+Bloco+H+UFRJ',
			),
			array(
				$english ? 'PEE/COPPE reference' : 'Referência do PEE/COPPE',
				'Av. Horácio Macedo, 2030 — Centro de Tecnologia, Bloco H — Cidade Universitária — Rio de Janeiro/RJ — CEP 21941-598',
				'https://www.google.com/maps/search/?api=1&query=COPPE+UFRJ+Bloco+H',
			),
		);
		$cards     = '';
		foreach ( $buildings as $building ) {
			$cards .= self::editorial_card( $building[0], $building[1], false, array( $building[2], $english ? 'Open in maps' : 'Abrir no mapa' ) );
		}
		return self::editorial_section(
			'contact-address',
			$english ? 'Addresses' : 'Endereços',
			$english ? 'Where to find the laboratory' : 'Onde encontrar o laboratório',
			'<div class="lps-grid lps-grid--2">' . $cards . '</div>'
				. '<p class="lps-meta lps-mt-6">' . self::esc( '(21) 3938-8205 · ' . ( $english ? 'Extension 8205' : 'Ramal 8205' ) . ' · ' )
				. '<a href="mailto:secretaria@lps.ufrj.br">secretaria@lps.ufrj.br</a></p>'
		);
	}

	/**
	 * Renders the contacts that still need a named owner — stated as pendings,
	 * not hidden.
	 *
	 * @param string $locale Supported locale slug.
	 */
	private static function contact_pending_section( string $locale ): string {
		$english = 'en' === $locale;
		return self::editorial_section(
			'contact-pending',
			$english ? 'Pending' : 'Pendências',
			$english ? 'Contacts still to be named' : 'Contatos ainda a nomear',
			'<div class="lps-grid lps-grid--2">'
				. self::editorial_card(
					$english ? 'Accessibility reporting' : 'Relato de acessibilidade',
					$english
						? 'No formal accessibility reporting channel has been named. Until it is, the office receives accessibility reports and forwards them to the responsible team.'
						: 'Nenhum canal formal de relato de acessibilidade foi nomeado. Até que exista, a secretaria recebe os relatos e os encaminha à equipe responsável.'
				)
				. self::editorial_card(
					$english ? 'Privacy and personal data' : 'Privacidade e dados pessoais',
					$english
						? 'No privacy contact and no documented legal basis for processing personal data exist yet. Both are launch blockers recorded in the migration material, not oversights hidden by this redesign.'
						: 'Ainda não existem contato de privacidade nem base legal documentada para tratamento de dados pessoais. Ambos são bloqueios de lançamento registrados no material de migração, não omissões escondidas por este redesenho.'
				)
				. '</div>'
		);
	}

	/**
	 * Renders Join/Collaborate/Partner handoffs to owned destinations only.
	 *
	 * @param array<int, mixed> $journeys Handoff journey records.
	 * @param string            $locale   Supported locale slug.
	 */
	public static function render_collaboration_page( array $journeys, string $locale ): string {
		$english = 'en' === $locale;
		$items   = '';
		foreach ( $journeys as $journey ) {
			if ( ! is_array( $journey ) ) {
				continue;
			}
			$label = self::text( $journey['label'] ?? '' );
			if ( '' === $label ) {
				continue;
			}
			$items .= '<li data-journey="' . self::esc( self::text( $journey['key'] ?? '' ) ) . '">';
			$items .= '<h3 class="lps-journey-label">' . self::esc( $label ) . '</h3>';
			$items .= self::handoff(
				self::text( $journey['contact'] ?? '' ),
				! empty( $journey['is_role'] ),
				self::text( $journey['url'] ?? '' ),
				! empty( $journey['approved'] ),
				$locale,
				true
			);
			$items .= '</li>';
		}
		if ( '' === $items ) {
			return '<p class="lps-empty">' . self::esc( $english ? 'No collaboration route is published yet' : 'Nenhuma rota de colaboração publicada' ) . '</p>';
		}
		// The section heading reuses the homepage journeys label so the same
		// participation intent carries one name across both surfaces.
		$heading = $english ? 'Take part in LPS' : 'Participe do LPS';
		return '<section class="lps-section" aria-labelledby="lps-journeys-heading"><div class="lps-section-head"><div><p class="lps-kicker">' . self::esc( $english ? 'Collaboration' : 'Colaboração' ) . '</p><h2 id="lps-journeys-heading">' . self::esc( $heading ) . '</h2></div></div><ul class="lps-collaboration-journeys">' . $items . '</ul></section>';
	}

	/**
	 * Renders a public handoff, or a documented unavailable state.
	 *
	 * @param string $contact         Stored contact address.
	 * @param bool   $contact_is_role Whether the address is a role account.
	 * @param string $url             External application URL.
	 * @param bool   $url_approved    Whether the URL is editorially approved.
	 * @param string $locale          Supported locale slug.
	 * @param bool   $actionable      Whether the handoff is still actionable.
	 */
	private static function handoff( string $contact, bool $contact_is_role, string $url, bool $url_approved, string $locale, bool $actionable ): string {
		$english = 'en' === $locale;
		if ( ! $actionable ) {
			return '';
		}
		if ( ! TrustSurfacePolicy::has_public_handoff( $contact, $contact_is_role, $url, $url_approved ) ) {
			return '<p class="lps-contact-unavailable">' . self::esc( $english ? 'No public contact is published for this route yet' : 'Nenhum contato público publicado para esta rota' ) . '</p>';
		}
		if ( $contact_is_role && self::is_email( $contact ) ) {
			return '<p class="lps-handoff"><a class="lps-breakable" href="mailto:' . self::esc( $contact ) . '">' . self::esc( $contact ) . '</a></p>';
		}
		$safe = self::safe_url( $url );
		return '<p class="lps-handoff"><a href="' . self::esc( $safe ) . '" rel="noopener">' . self::esc( $english ? 'Apply on the official site' : 'Inscreva-se no site oficial' ) . '</a></p>';
	}

	/**
	 * Renders the explicit stale-translation warning of one record.
	 *
	 * A stale English variant stays at its own URL and announces that its review
	 * trails the Portuguese source; the surface never silently substitutes the
	 * source text for the requested locale.
	 *
	 * @param array<mixed,mixed> $record Trust record.
	 * @param string             $locale Supported locale slug.
	 */
	private static function translation_notice( array $record, string $locale ): string {
		if ( empty( $record['stale'] ) ) {
			return '';
		}
		$message = 'en' === $locale
			? 'This English translation is under review: the Portuguese source changed since the last review.'
			: 'Esta tradução está em revisão: a fonte em português mudou desde a última revisão.';
		return '<p class="lps-translation-notice" role="status">' . self::esc( $message ) . '</p>';
	}

	/**
	 * Renders the compact stale-translation marker used inside listing rows.
	 *
	 * @param array<mixed,mixed> $record Trust record.
	 * @param string             $locale Supported locale slug.
	 */
	private static function translation_chip( array $record, string $locale ): string {
		if ( empty( $record['stale'] ) ) {
			return '';
		}
		$label = 'en' === $locale ? 'Translation under review' : 'Tradução em revisão';
		return ' <span class="lps-status lps-status-warning">' . self::esc( $label ) . '</span>';
	}

	/**
	 * Converts trusted boundary input to a trimmed string.
	 *
	 * @param mixed $value Boundary input.
	 */
	private static function text( mixed $value ): string {
		return is_scalar( $value ) ? trim( (string) $value ) : '';
	}

	/**
	 * Escapes a value for HTML output.
	 *
	 * @param string $value Raw value.
	 */
	private static function esc( string $value ): string {
		return htmlspecialchars( $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );
	}

	/**
	 * Reports whether a value is a usable email address.
	 *
	 * @param string $value Stored address.
	 */
	private static function is_email( string $value ): bool {
		return false !== filter_var( $value, FILTER_VALIDATE_EMAIL );
	}

	/**
	 * Returns the URL when it uses an allowed scheme, otherwise an empty string.
	 *
	 * @param string $value Stored URL.
	 */
	private static function safe_url( string $value ): string {
		if ( '' === $value ) {
			return '';
		}
		$scheme = wp_parse_url( $value, PHP_URL_SCHEME );
		$scheme = is_string( $scheme ) ? strtolower( $scheme ) : '';
		if ( ! in_array( $scheme, array( 'http', 'https' ), true ) ) {
			return '';
		}
		$host = wp_parse_url( $value, PHP_URL_HOST );
		return is_string( $host ) && '' !== $host ? $value : '';
	}
}
