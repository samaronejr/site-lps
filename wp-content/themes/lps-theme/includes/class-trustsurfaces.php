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
		$html   .= '<h1>' . self::esc( self::text( $record['title'] ?? '' ) ) . '</h1>';
		$html   .= self::translation_notice( $record, $locale );
		$html   .= '<p class="lps-opportunity-state">' . self::esc( self::OPPORTUNITY_LABELS[ $state ][ $locale ] ?? '' ) . '</p>';

		$summary = self::text( $record['summary'] ?? '' );
		if ( '' !== $summary ) {
			$html .= '<p class="lps-summary">' . self::esc( $summary ) . '</p>';
		}
		if ( '' !== $closes ) {
			$html .= '<p class="lps-deadline">' . self::esc( $english ? 'Deadline' : 'Prazo' ) . ': <time datetime="' . self::esc( substr( $closes, 0, 10 ) ) . '">' . self::esc( substr( $closes, 0, 10 ) ) . '</time></p>';
		}
		foreach ( array(
			'eligibility'  => $english ? 'Eligibility' : 'Elegibilidade',
			'instructions' => $english ? 'How to apply' : 'Como se inscrever',
		) as $key => $label ) {
			$value = self::text( $record[ $key ] ?? '' );
			if ( '' !== $value ) {
				// h2, not h3: these sections sit directly under the page h1, and a
				// skipped level breaks the heading outline (axe heading-order).
				$html .= '<section class="lps-opportunity-' . self::esc( $key ) . '"><h2>' . self::esc( $label ) . '</h2><p>' . self::esc( $value ) . '</p></section>';
			}
		}

		$html .= self::handoff(
			self::text( $record['contact'] ?? '' ),
			! empty( $record['contact_is_role'] ),
			self::text( $record['application_url'] ?? '' ),
			! empty( $record['application_approved'] ),
			$locale,
			'closed' !== $state
		);

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
			$state  = TrustSurfacePolicy::opportunity_state(
				self::text( $record['opens_at'] ?? '' ),
				self::text( $record['closes_at'] ?? '' ),
				$now
			);
			$url    = self::single_path( 'lps_opportunity', $locale, $slug );
			$items .= '<li data-state="' . self::esc( $state ) . '">';
			$items .= '<a href="' . self::esc( $url ) . '">' . self::esc( $title ) . '</a>';
			$items .= '<span class="lps-opportunity-state">' . self::esc( self::OPPORTUNITY_LABELS[ $state ][ $locale ] ?? '' ) . '</span>';
			$closes = self::text( $record['closes_at'] ?? '' );
			if ( '' !== $closes ) {
				$items .= '<span class="lps-deadline">' . self::esc( $english ? 'Deadline' : 'Prazo' ) . ' <time datetime="' . self::esc( substr( $closes, 0, 10 ) ) . '">' . self::esc( substr( $closes, 0, 10 ) ) . '</time></span>';
			}
			$items .= self::translation_chip( $record, $locale );
			$items .= '</li>';
		}
		if ( '' === $items ) {
			return '<p class="lps-empty">' . self::esc( $english ? 'No published opportunities' : 'Nenhuma oportunidade publicada' ) . '</p>';
		}
		return '<ul class="lps-opportunity-listing">' . $items . '</ul>';
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

		$html  = '<article class="lps-event" data-state="' . self::esc( $state ) . '">';
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
			$html .= '<p class="lps-summary">' . self::esc( $summary ) . '</p>';
		}
		if ( '' !== $starts ) {
			$html .= '<p class="lps-event-date"><time datetime="' . self::esc( substr( $starts, 0, 10 ) ) . '">' . self::esc( substr( $starts, 0, 10 ) ) . '</time></p>';
		}
		$venue = self::text( $record['venue'] ?? '' );
		if ( '' !== $venue ) {
			$html .= '<p class="lps-event-venue">' . self::esc( $venue ) . '</p>';
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
			$state  = TrustSurfacePolicy::event_state(
				self::text( $record['status'] ?? '' ),
				self::text( $record['starts_at'] ?? '' ),
				self::text( $record['ends_at'] ?? '' ),
				$now
			);
			$items .= '<li data-state="' . self::esc( $state ) . '">';
			$items .= '<a href="' . self::esc( self::single_path( 'lps_event', $locale, $slug ) ) . '">' . self::esc( $title ) . '</a>';
			$items .= '<span class="lps-event-state">' . self::esc( self::EVENT_LABELS[ $state ][ $locale ] ?? '' ) . '</span>';
			$starts = self::text( $record['starts_at'] ?? '' );
			if ( '' !== $starts ) {
				$items .= '<span class="lps-event-date"><time datetime="' . self::esc( substr( $starts, 0, 10 ) ) . '">' . self::esc( substr( $starts, 0, 10 ) ) . '</time></span>';
			}
			$venue = self::text( $record['venue'] ?? '' );
			if ( '' !== $venue ) {
				$items .= '<span class="lps-event-venue">' . self::esc( $venue ) . '</span>';
			}
			$items .= self::translation_chip( $record, $locale );
			$items .= '</li>';
		}
		if ( '' === $items ) {
			return '<p class="lps-empty">' . self::esc( $english ? 'No published events' : 'Nenhum evento publicado' ) . '</p>';
		}
		return '<ul class="lps-event-listing">' . $items . '</ul>';
	}

	/**
	 * Renders one news record as a dated reading page.
	 *
	 * @param array<string, mixed> $record News record.
	 * @param string               $locale Supported locale slug.
	 */
	public static function render_news( array $record, string $locale ): string {
		$html  = '<article class="lps-news">';
		$html .= '<h1>' . self::esc( self::text( $record['title'] ?? '' ) ) . '</h1>';
		$html .= self::translation_notice( $record, $locale );
		$date  = self::text( $record['date'] ?? '' );
		if ( '' !== $date ) {
			$html .= '<p class="lps-meta"><time datetime="' . self::esc( substr( $date, 0, 10 ) ) . '">' . self::esc( substr( $date, 0, 10 ) ) . '</time></p>';
		}
		$summary = self::text( $record['summary'] ?? '' );
		if ( '' !== $summary ) {
			$html .= '<p class="lps-summary">' . self::esc( $summary ) . '</p>';
		}
		$body = self::text( $record['body'] ?? '' );
		if ( '' !== $body ) {
			$rendered = function_exists( 'do_blocks' ) ? (string) do_blocks( $body ) : $body;
			$html    .= '<div class="lps-body lps-reading">' . ( function_exists( 'wp_kses_post' ) ? wp_kses_post( $rendered ) : $rendered ) . '</div>';
		}
		return $html . '</article>';
	}

	/**
	 * Renders the news listing.
	 *
	 * @param array<int, mixed> $records News records.
	 * @param string            $locale  Supported locale slug.
	 */
	public static function render_news_listing( array $records, string $locale ): string {
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
			$items .= '<li>';
			$items .= '<a href="' . self::esc( self::single_path( 'lps_news', $locale, $slug ) ) . '">' . self::esc( $title ) . '</a>';
			$items .= self::translation_chip( $record, $locale );
			$date   = self::text( $record['date'] ?? '' );
			if ( '' !== $date ) {
				$items .= '<time datetime="' . self::esc( substr( $date, 0, 10 ) ) . '">' . self::esc( substr( $date, 0, 10 ) ) . '</time>';
			}
			$summary = self::text( $record['summary'] ?? '' );
			if ( '' !== $summary ) {
				$items .= '<p>' . self::esc( $summary ) . '</p>';
			}
			$items .= '</li>';
		}
		if ( '' === $items ) {
			return '<p class="lps-empty">' . self::esc( $english ? 'No published news' : 'Nenhuma notícia publicada' ) . '</p>';
		}
		return '<ul class="lps-news-listing">' . $items . '</ul>';
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
		if ( '' !== $summary ) {
			$html .= '<p class="lps-summary">' . self::esc( $summary ) . '</p>';
		}
		$html .= self::translation_notice( $page, $locale );

		foreach ( array(
			'affiliation' => $english ? 'Affiliation' : 'Vínculo institucional',
			'governance'  => $english ? 'Governance' : 'Governança',
			'location'    => $english ? 'Location' : 'Localização',
			'funding'     => $english ? 'Funding and partners' : 'Financiamento e parceiros',
		) as $field => $label ) {
			$value = self::text( $page[ $field ] ?? '' );
			if ( '' !== $value ) {
				$html .= '<p class="lps-fact lps-fact-' . self::esc( $field ) . '"><strong>' . self::esc( $label ) . ':</strong> ' . self::esc( $value ) . '</p>';
			}
		}

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

		return $html . $aside . '</aside></article>';
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
			$items .= '<li><span class="lps-contact-role">' . self::esc( $role ) . '</span> ';
			$items .= '<a class="lps-breakable" href="mailto:' . self::esc( $email ) . '">' . self::esc( $email ) . '</a></li>';
		}
		if ( '' === $items ) {
			return '<p class="lps-contact-unavailable">' . self::esc( $english ? 'No public role contact is published yet' : 'Nenhum contato institucional público publicado' ) . '</p>';
		}
		return '<ul class="lps-contact-roles">' . $items . '</ul>';
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
		return '<section class="lps-journeys" aria-labelledby="lps-journeys-heading"><h2 id="lps-journeys-heading">' . self::esc( $heading ) . '</h2><ul class="lps-collaboration-journeys">' . $items . '</ul></section>';
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
