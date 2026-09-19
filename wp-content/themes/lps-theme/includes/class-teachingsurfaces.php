<?php
/**
 * Renderers for the canonical teaching surfaces.
 *
 * @package LPS\Theme
 */

declare(strict_types=1);

namespace LPS\Theme;

/**
 * Renders the teaching landing, course, and offering surfaces.
 *
 * The offering page is the canonical teaching page: it renders the course
 * identity, the term token, the teaching team, and the ordered units with
 * stable anchors. Every renderer returns escaped markup only — records are
 * assembled by `TeachingRoutes` from governed metadata.
 */
final class TeachingSurfaces {
	/**
	 * Renders the teaching landing surface.
	 *
	 * @param array<int, array<string, mixed>> $courses Published course records.
	 * @param string                           $locale  Supported locale slug.
	 */
	public static function landing( array $courses, string $locale ): string {
		$heading = 'en' === $locale ? 'Teaching' : 'Ensino';
		$empty   = 'en' === $locale ? 'No courses are published yet.' : 'Nenhuma disciplina publicada ainda.';
		$items   = '';
		foreach ( $courses as $course ) {
			$items .= '<li><a href="' . esc_url( self::text( $course['url'] ?? '' ) ) . '">'
				. esc_html( self::text( $course['title'] ?? '' ) ) . '</a>'
				. self::meta_line( self::text( $course['code'] ?? '' ), self::text( $course['level'] ?? '' ) )
				. '</li>';
		}
		$list = '' === $items
			? '<p>' . esc_html( $empty ) . '</p>'
			: '<ul class="lps-course-list">' . $items . '</ul>';
		return '<div class="lps-teaching lps-teaching-landing" lang="' . esc_attr( self::bcp47( $locale ) ) . '">'
			. '<h1>' . esc_html( $heading ) . '</h1>'
			. $list
			. '</div>';
	}

	/**
	 * Renders the course detail surface.
	 *
	 * @param array<string, mixed>             $course    Course record.
	 * @param array<int, array<string, mixed>> $offerings Published offering records.
	 * @param string                           $locale    Supported locale slug.
	 */
	public static function course( array $course, array $offerings, string $locale ): string {
		$offerings_label = 'en' === $locale ? 'Offerings' : 'Turmas';
		// Offerings are grouped by their derived temporal status so a visitor
		// can tell the current section from completed terms at a glance; the
		// grouping mirrors the faculty profile's teaching history.
		$groups = array(
			'current'   => 'en' === $locale ? 'In progress' : 'Em andamento',
			'upcoming'  => 'en' === $locale ? 'Upcoming offerings' : 'Próximas ofertas',
			'completed' => 'en' === $locale ? 'Previous offerings' : 'Ofertas anteriores',
		);
		$grouped = array();
		foreach ( $offerings as $offering ) {
			$status              = self::text( $offering['temporal_status'] ?? '' );
			$bucket              = isset( $groups[ $status ] ) ? $status : 'completed';
			$grouped[ $bucket ][] = $offering;
		}
		$list = '';
		foreach ( $groups as $status => $label ) {
			$rows = $grouped[ $status ] ?? array();
			if ( array() === $rows ) {
				continue;
			}
			$items = '';
			foreach ( $rows as $offering ) {
				$term       = self::record( $offering['term'] ?? null );
				$cancelled  = 'cancelled' === self::text( $offering['temporal_status'] ?? '' );
				$items     .= '<li' . ( $cancelled ? ' data-state="cancelled"' : '' ) . '><a href="' . esc_url( self::text( $offering['url'] ?? '' ) ) . '">'
					. esc_html( self::text( $offering['title'] ?? '' ) ) . '</a>'
					. ' <span class="lps-term-token">' . esc_html( self::text( $term['token'] ?? '' ) ) . '</span>'
					. ' <span class="lps-temporal-status">' . esc_html( self::temporal_label( self::text( $offering['temporal_status'] ?? '' ), $locale ) ) . '</span>'
					. '</li>';
			}
			$list .= '<section class="lps-offering-group" data-temporal="' . esc_attr( $status ) . '"><h3>' . esc_html( $label ) . '</h3><ul class="lps-offering-list">' . $items . '</ul></section>';
		}
		if ( '' !== $list ) {
			$list = '<h2>' . esc_html( $offerings_label ) . '</h2>' . $list;
		}
		return '<div class="lps-teaching lps-course" lang="' . esc_attr( self::bcp47( $locale ) ) . '">'
			. '<h1>' . esc_html( self::text( $course['title'] ?? '' ) ) . '</h1>'
			. self::meta_line( self::text( $course['code'] ?? '' ), self::text( $course['level'] ?? '' ) )
			. self::paragraph( self::text( $course['summary'] ?? '' ) )
			. self::body( self::text( $course['body'] ?? '' ) )
			. $list
			. '</div>';
	}

	/**
	 * Renders the canonical offering surface.
	 *
	 * @param array<string, mixed> $offering Offering record.
	 * @param string               $locale   Supported locale slug.
	 */
	public static function offering( array $offering, string $locale ): string {
		$course = self::record( $offering['course'] ?? null );
		$term   = self::record( $offering['term'] ?? null );
		$team   = self::record( $offering['team'] ?? null );
		$units  = self::record( $offering['units'] ?? null );

		$team_items = '';
		foreach ( $team as $member ) {
			$member      = self::record( $member );
			$team_items .= '<li>' . esc_html( self::text( $member['name'] ?? '' ) )
				. ' <span class="lps-team-role">' . esc_html( self::team_role_label( self::text( $member['role'] ?? '' ), $locale ) ) . '</span></li>';
		}
		$team_markup = '' === $team_items ? '' : '<ul class="lps-teaching-team">' . $team_items . '</ul>';

		$unit_items = '';
		foreach ( $units as $unit ) {
			$unit        = self::record( $unit );
			$anchor      = self::text( $unit['anchor'] ?? '' );
			$unit_items .= '<li id="' . esc_attr( $anchor ) . '"><h3>' . esc_html( self::text( $unit['title'] ?? '' ) ) . '</h3>'
				. self::body( self::text( $unit['body'] ?? '' ) )
				. '</li>';
		}
		$units_markup = '' === $unit_items ? '' : '<ol class="lps-units">' . $unit_items . '</ol>';

		$lms        = self::text( $offering['lms_url'] ?? '' );
		$lms_markup = '' === $lms
			? ''
			: '<p class="lps-lms"><a href="' . esc_url( $lms ) . '" rel="noopener noreferrer">' . esc_html( $lms ) . '</a></p>';

		// A cancelled offering keeps its stable URL and announces the state
		// explicitly, the same contract the event surface follows.
		$cancelled_markup = ! empty( $offering['cancelled'] )
			? '<p class="lps-event-notice">' . esc_html( 'en' === $locale ? 'This offering was cancelled. The record is kept for reference.' : 'Esta oferta foi cancelada. O registro é mantido para referência.' ) . '</p>'
			: '';

		return '<div class="lps-teaching lps-offering" lang="' . esc_attr( self::bcp47( $locale ) ) . '">'
			. '<nav class="lps-breadcrumb"><a href="' . esc_url( self::text( $course['url'] ?? '' ) ) . '">'
			. esc_html( self::text( $course['title'] ?? '' ) ) . '</a></nav>'
			. '<h1>' . esc_html( self::text( $offering['title'] ?? '' ) ) . '</h1>'
			. $cancelled_markup
			. '<p class="lps-offering-identity">'
			. '<span class="lps-term-token">' . esc_html( self::text( $term['token'] ?? '' ) ) . '</span>'
			. ' <span class="lps-section">' . esc_html( self::text( $offering['section_key'] ?? '' ) ) . '</span>'
			. ' <span class="lps-temporal-status">' . esc_html( self::temporal_label( self::text( $offering['temporal_status'] ?? '' ), $locale ) ) . '</span>'
			. '</p>'
			. self::meta_line( self::text( $offering['schedule'] ?? '' ), self::text( $offering['venue'] ?? '' ) )
			. self::paragraph( self::text( $offering['summary'] ?? '' ) )
			. self::body( self::text( $offering['body'] ?? '' ) )
			. $team_markup
			. $lms_markup
			. $units_markup
			. '</div>';
	}

	/**
	 * Returns the localized label of one derived temporal status.
	 *
	 * @param string $status Stored temporal status key.
	 * @param string $locale Supported locale slug.
	 */
	private static function temporal_label( string $status, string $locale ): string {
		$english = 'en' === $locale;
		$labels  = array(
			'upcoming'  => $english ? 'Upcoming' : 'Próxima',
			'current'   => $english ? 'In progress' : 'Em andamento',
			'completed' => $english ? 'Completed' : 'Concluída',
			'cancelled' => $english ? 'Cancelled' : 'Cancelada',
		);
		return $labels[ $status ] ?? $status;
	}

	/**
	 * Returns the localized label of one canonical teaching-team role.
	 *
	 * @param string $role   Stored relationship role.
	 * @param string $locale Supported locale slug.
	 */
	private static function team_role_label( string $role, string $locale ): string {
		$english = 'en' === $locale;
		$labels  = array(
			'lead'       => $english ? 'Lead instructor' : 'Docente responsável',
			'co-teacher' => $english ? 'Co-teacher' : 'Codocente',
			'assistant'  => $english ? 'Teaching assistant' : 'Assistente de ensino',
		);
		return $labels[ $role ] ?? $role;
	}

	/**
	 * Renders one metadata line when at least one part is non-empty.
	 *
	 * @param string $first  First metadata value.
	 * @param string $second Second metadata value.
	 */
	private static function meta_line( string $first, string $second ): string {
		$parts = array_filter( array( $first, $second ), static fn( string $part ): bool => '' !== $part );
		return array() === $parts ? '' : '<p class="lps-meta">' . esc_html( implode( ' · ', $parts ) ) . '</p>';
	}

	/**
	 * Renders one summary paragraph when non-empty.
	 *
	 * @param string $summary Summary text.
	 */
	private static function paragraph( string $summary ): string {
		return '' === $summary ? '' : '<p class="lps-summary">' . esc_html( $summary ) . '</p>';
	}

	/**
	 * Renders governed body markup.
	 *
	 * @param string $body Body markup.
	 */
	private static function body( string $body ): string {
		return '' === $body ? '' : '<div class="lps-body">' . wp_kses_post( $body ) . '</div>';
	}

	/**
	 * Converts trusted boundary input to a string.
	 *
	 * @param mixed $value Boundary input.
	 */
	private static function text( mixed $value ): string {
		return is_scalar( $value ) ? (string) $value : '';
	}

	/**
	 * Narrows one boundary value to a record map.
	 *
	 * @param mixed $value Boundary input.
	 * @return array<mixed>
	 */
	private static function record( mixed $value ): array {
		return is_array( $value ) ? $value : array();
	}

	/**
	 * Returns the BCP47 language tag for a supported locale slug.
	 *
	 * @param string $locale Supported locale slug.
	 */
	private static function bcp47( string $locale ): string {
		return 'en' === $locale ? 'en' : 'pt-BR';
	}
}
