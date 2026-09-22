<?php
/**
 * Renderers for the canonical teaching surfaces.
 *
 * The offering page is the canonical teaching page and follows the Operate
 * mode of the design contract (DESIGN.md §7.3): term and section header with
 * the teaching team, syllabus snapshot, schedule, venue and the approved LMS
 * handoff, then ordered units with stable anchors and descriptive material
 * rows. A material row names its descriptive title, type, authored language,
 * byte size, checksum and update date; a withdrawn resource shows its
 * withdrawn state, a pending scan shows state text, and neither ever renders
 * a dead link. Storage keys, version IDs and raw private URLs never reach the
 * markup — downloads resolve only through the opaque guarded route.
 *
 * Every renderer returns escaped markup only; records are assembled by
 * `TeachingRoutes` from governed metadata. Escaping helpers are internal so
 * the renderers run in unit tests without WordPress loaded.
 *
 * @package LPS\Theme
 */

declare(strict_types=1);

namespace LPS\Theme;

/**
 * Renders the teaching landing, course, and offering surfaces.
 */
final class TeachingSurfaces {
	/**
	 * Renders the teaching landing surface.
	 *
	 * @param array<int, array<string, mixed>> $courses Published course records.
	 * @param string                           $locale  Supported locale slug.
	 */
	public static function landing( array $courses, string $locale ): string {
		$english = 'en' === $locale;
		$html    = '<section class="lps-teaching lps-teaching-landing" lang="' . self::esc( self::bcp47( $locale ) ) . '">';
		$html   .= '<h1>' . self::esc( $english ? 'Teaching' : 'Ensino' ) . '</h1>';
		$html   .= '<p class="lps-summary">' . self::esc( $english ? 'Published courses, sections and teaching materials.' : 'Disciplinas, turmas e materiais de ensino publicados.' ) . '</p>';
		if ( array() === $courses ) {
			$html .= '<p class="lps-empty">' . self::esc( $english ? 'No courses are published yet.' : 'Nenhuma disciplina publicada ainda.' ) . '</p>';
			return $html . '</section>';
		}
		$html .= '<ul class="lps-course-list">';
		foreach ( $courses as $course ) {
			$course = self::record( $course );
			$title  = self::text( $course['title'] ?? '' );
			if ( '' === $title ) {
				continue;
			}
			$url   = self::safe_url( self::text( $course['url'] ?? '' ) );
			$meta  = self::meta_parts(
				array(
					self::text( $course['code'] ?? '' ),
					self::level_label( self::text( $course['level'] ?? '' ), $locale ),
					self::text( $course['program'] ?? '' ),
				)
			);
			$html .= '<li class="lps-record">';
			if ( '' !== $meta ) {
				$html .= '<p class="lps-meta">' . $meta . '</p>';
			}
			$html   .= '<h2>' . ( '' === $url ? self::esc( $title ) : '<a href="' . self::esc( $url ) . '">' . self::esc( $title ) . '</a>' ) . self::translation_chip( $course, $locale ) . '</h2>';
			$summary = self::text( $course['summary'] ?? '' );
			if ( '' !== $summary ) {
				$html .= '<p>' . self::esc( $summary ) . '</p>';
			}
			$current     = self::record( $course['current_offering'] ?? null );
			$current_url = self::safe_url( self::text( $current['url'] ?? '' ) );
			if ( '' !== $current_url ) {
				$label = $english ? 'Current section' : 'Turma em andamento';
				$html .= '<p class="lps-meta"><a href="' . self::esc( $current_url ) . '">' . self::esc( $label . ': ' . self::text( $current['title'] ?? '' ) ) . '</a></p>';
			}
			$html .= '</li>';
		}
		return $html . '</ul></section>';
	}

	/**
	 * Renders the course detail surface with its offering history.
	 *
	 * Offerings group into in-progress, upcoming and previous strata so a
	 * student distinguishes the live section from the record of completed
	 * terms; completed terms stay linked, never archived away.
	 *
	 * @param array<string, mixed>             $course    Course record.
	 * @param array<int, array<string, mixed>> $offerings Published offering records.
	 * @param string                           $locale    Supported locale slug.
	 */
	public static function course( array $course, array $offerings, string $locale ): string {
		$english       = 'en' === $locale;
		$html          = '<article class="lps-teaching lps-course" lang="' . self::esc( self::bcp47( $locale ) ) . '">';
		$html         .= '<h1>' . self::esc( self::text( $course['title'] ?? '' ) ) . '</h1>';
		$html         .= self::translation_notice( $course, $locale );
		$html         .= self::meta_line(
			array(
				self::text( $course['code'] ?? '' ),
				self::level_label( self::text( $course['level'] ?? '' ), $locale ),
				self::text( $course['program'] ?? '' ),
			)
		);
		$html         .= self::paragraph( self::text( $course['summary'] ?? '' ) );
		$html         .= self::body( self::text( $course['body'] ?? '' ) );
		$prerequisites = self::text( $course['prerequisites'] ?? '' );
		if ( '' !== $prerequisites ) {
			$html .= '<p class="lps-meta">' . self::esc( ( $english ? 'Prerequisites: ' : 'Pré-requisitos: ' ) . $prerequisites ) . '</p>';
		}
		$syllabus = self::text( $course['syllabus'] ?? '' );
		if ( '' !== $syllabus ) {
			$html .= '<h2>' . self::esc( $english ? 'Syllabus' : 'Ementa' ) . '</h2>';
			$html .= '<div class="lps-body">' . nl2br( self::esc( $syllabus ) ) . '</div>';
		}

		$current  = array();
		$upcoming = array();
		$previous = array();
		foreach ( $offerings as $offering ) {
			$offering = self::record( $offering );
			$status   = self::text( $offering['temporal_status'] ?? '' );
			if ( 'current' === $status ) {
				$current[] = $offering;
			} elseif ( 'upcoming' === $status ) {
				$upcoming[] = $offering;
			} else {
				$previous[] = $offering;
			}
		}
		if ( array() === $offerings ) {
			$html .= '<p class="lps-empty">' . self::esc( $english ? 'No published offerings for this course yet.' : 'Nenhuma turma publicada para esta disciplina ainda.' ) . '</p>';
			return $html . '</article>';
		}
		$html .= self::offering_group( $current, $english ? 'In progress' : 'Em andamento', $locale );
		$html .= self::offering_group( $upcoming, $english ? 'Upcoming offerings' : 'Próximas ofertas', $locale );
		$html .= self::offering_group( $previous, $english ? 'Previous offerings' : 'Ofertas anteriores', $locale );
		return $html . '</article>';
	}

	/**
	 * Renders the canonical offering surface.
	 *
	 * @param array<string, mixed> $offering Offering record.
	 * @param string               $locale   Supported locale slug.
	 */
	public static function offering( array $offering, string $locale ): string {
		$english   = 'en' === $locale;
		$course    = self::record( $offering['course'] ?? null );
		$term      = self::record( $offering['term'] ?? null );
		$team      = self::record( $offering['team'] ?? null );
		$units     = self::record( $offering['units'] ?? null );
		$materials = self::record( $offering['materials'] ?? null );
		$siblings  = self::record( $offering['siblings'] ?? null );
		$status    = self::text( $offering['temporal_status'] ?? '' );

		$materials_by_unit = array();
		$ungrouped         = array();
		foreach ( $materials as $material ) {
			$material = self::record( $material );
			$anchor   = self::text( $material['unit_anchor'] ?? '' );
			if ( '' !== $anchor ) {
				$materials_by_unit[ $anchor ][] = $material;
			} else {
				$ungrouped[] = $material;
			}
		}
		$render_materials_section = array() !== $ungrouped || array() === $materials;

		$course_url   = self::safe_url( self::text( $course['url'] ?? '' ) );
		$course_title = self::text( $course['title'] ?? '' );
		$course_code  = self::text( $course['code'] ?? '' );

		$html = '<article class="lps-teaching lps-offering" data-state="' . self::esc( $status ) . '" lang="' . self::esc( self::bcp47( $locale ) ) . '">';
		if ( '' !== $course_title ) {
			$context = '' === $course_code ? $course_title : $course_code . ' · ' . $course_title;
			$html   .= '<p class="lps-meta lps-offering-course">';
			$html   .= '' === $course_url ? self::esc( $context ) : '<a href="' . self::esc( $course_url ) . '">' . self::esc( $context ) . '</a>';
			$html   .= '</p>';
		}
		$html .= '<h1>' . self::esc( self::text( $offering['title'] ?? '' ) ) . '</h1>';
		$html .= self::translation_notice( $offering, $locale );
		$html .= '<p class="lps-meta lps-offering-identity">'
			. '<span class="lps-term-token">' . self::esc( self::text( $term['token'] ?? '' ) ) . '</span>'
			. ' <span class="lps-section">' . self::esc( self::text( $offering['section_key'] ?? '' ) ) . '</span>'
			. ' <span class="lps-temporal-status">' . self::esc( self::temporal_label( $status, $locale ) ) . '</span>'
			. '</p>';
		$html .= self::term_line( $term, $locale );
		$html .= self::meta_line(
			array(
				self::text( $offering['schedule'] ?? '' ),
				self::text( $offering['venue'] ?? '' ),
			)
		);
		$html .= self::paragraph( self::text( $offering['summary'] ?? '' ) );
		$html .= self::body( self::text( $offering['body'] ?? '' ) );

		if ( array() !== $team ) {
			$html .= '<h2>' . self::esc( $english ? 'Teaching team' : 'Equipe de ensino' ) . '</h2>';
			$html .= '<ul class="lps-teaching-team">';
			foreach ( $team as $member ) {
				$member = self::record( $member );
				$name   = self::text( $member['name'] ?? '' );
				if ( '' === $name ) {
					continue;
				}
				$role  = self::team_role_label( self::text( $member['role'] ?? '' ), $locale );
				$html .= '<li>' . self::esc( $name ) . ( '' === $role ? '' : ' <span class="lps-team-role">' . self::esc( $role ) . '</span>' ) . '</li>';
			}
			$html .= '</ul>';
		}

		$syllabus = self::text( $offering['syllabus'] ?? '' );
		if ( '' !== $syllabus ) {
			$html .= '<h2>' . self::esc( $english ? 'Syllabus' : 'Ementa' ) . '</h2>';
			$html .= '<div class="lps-body">' . nl2br( self::esc( $syllabus ) ) . '</div>';
		}

		$lms = self::safe_url( self::text( $offering['lms_url'] ?? '' ) );
		if ( '' !== $lms ) {
			$label = $english ? 'Virtual classroom (external link)' : 'Ambiente virtual (link externo)';
			$html .= '<p class="lps-lms"><a href="' . self::esc( $lms ) . '" rel="noopener noreferrer">' . self::esc( $label ) . '</a></p>';
		}

		// Materials-first navigation: the contents strip links every unit anchor
		// and the materials section so a long list stays reachable near the top.
		if ( array() !== $units || $render_materials_section ) {
			$html .= '<nav class="lps-offering-toc" aria-label="' . self::esc( $english ? 'Units and materials' : 'Unidades e materiais' ) . '">';
			$html .= '<h2>' . self::esc( $english ? 'Contents' : 'Conteúdo' ) . '</h2>';
			$html .= '<ul>';
			foreach ( $units as $unit ) {
				$unit   = self::record( $unit );
				$anchor = self::text( $unit['anchor'] ?? '' );
				$title  = self::text( $unit['title'] ?? '' );
				if ( '' === $anchor || '' === $title ) {
					continue;
				}
				$html .= '<li><a href="#' . self::esc( $anchor ) . '">' . self::esc( $title ) . '</a></li>';
			}
			if ( $render_materials_section ) {
				$html .= '<li><a href="#materiais">' . self::esc( $english ? 'Materials' : 'Materiais' ) . '</a></li>';
			}
			$html .= '</ul></nav>';
		}

		if ( array() !== $units ) {
			$html .= '<h2>' . self::esc( $english ? 'Units' : 'Unidades' ) . '</h2>';
			$html .= '<ol class="lps-units">';
			foreach ( $units as $unit ) {
				$unit   = self::record( $unit );
				$anchor = self::text( $unit['anchor'] ?? '' );
				$title  = self::text( $unit['title'] ?? '' );
				if ( '' === $anchor || '' === $title ) {
					continue;
				}
				$html .= '<li id="' . self::esc( $anchor ) . '"><h3>' . self::esc( $title ) . '</h3>';
				$topic = self::text( $unit['topic_date'] ?? '' );
				if ( '' !== $topic ) {
					$html .= '<p class="lps-meta"><time datetime="' . self::esc( $topic ) . '">' . self::esc( self::format_date( $topic, $locale ) ) . '</time></p>';
				}
				$html .= self::body( self::text( $unit['body'] ?? '' ) );
				$html .= self::materials_list( $materials_by_unit[ $anchor ] ?? array(), $locale );
				$html .= '</li>';
			}
			$html .= '</ol>';
		}

		if ( $render_materials_section ) {
			$html .= '<h2 id="materiais">' . self::esc( $english ? 'Materials' : 'Materiais' ) . '</h2>';
			if ( array() === $materials ) {
				$html .= '<p class="lps-empty">' . self::esc( $english ? 'Materials are not published yet.' : 'Materiais ainda não publicados.' ) . '</p>';
			} else {
				$html .= self::materials_list( $ungrouped, $locale );
			}
		}

		if ( array() !== $siblings ) {
			$html    .= '<nav class="lps-offering-history" aria-label="' . self::esc( $english ? 'Other offerings of this course' : 'Outras ofertas desta disciplina' ) . '">';
			$html    .= '<h2>' . self::esc( $english ? 'Other offerings' : 'Outras ofertas' ) . '</h2>';
			$html    .= '<ul class="lps-offering-list">';
			$self_url = self::safe_url( self::text( $offering['url'] ?? '' ) );
			foreach ( $siblings as $sibling ) {
				$sibling = self::record( $sibling );
				$html   .= self::offering_row( $sibling, $locale, self::safe_url( self::text( $sibling['url'] ?? '' ) ) === $self_url );
			}
			$html .= '</ul></nav>';
		}
		return $html . '</article>';
	}

	/**
	 * Renders one offering-history group, or nothing when the group is empty.
	 *
	 * @param array<int, array<string, mixed>> $offerings Offering records.
	 * @param string                           $heading   Localized group heading.
	 * @param string                           $locale    Supported locale slug.
	 */
	private static function offering_group( array $offerings, string $heading, string $locale ): string {
		if ( array() === $offerings ) {
			return '';
		}
		$html = '<h2>' . self::esc( $heading ) . '</h2><ul class="lps-offering-list">';
		foreach ( $offerings as $offering ) {
			$html .= self::offering_row( $offering, $locale );
		}
		return $html . '</ul>';
	}

	/**
	 * Renders one offering row: linked title, term token, section and status.
	 *
	 * @param array<string, mixed> $offering  Offering record.
	 * @param string               $locale    Supported locale slug.
	 * @param bool                 $is_current Whether the row is the page's own offering.
	 */
	private static function offering_row( array $offering, string $locale, bool $is_current = false ): string {
		$term   = self::record( $offering['term'] ?? null );
		$status = self::text( $offering['temporal_status'] ?? '' );
		$title  = self::text( $offering['title'] ?? '' );
		$url    = self::safe_url( self::text( $offering['url'] ?? '' ) );
		$label  = self::text( $term['period_label'] ?? '' );
		$token  = self::text( $term['token'] ?? '' );
		$meta   = self::meta_parts(
			array(
				'' !== $label ? $label : $token,
				self::text( $offering['section_key'] ?? '' ),
				self::temporal_label( $status, $locale ),
			)
		);
		$html   = '<li class="lps-record" data-state="' . self::esc( $status ) . '">';
		if ( '' !== $meta ) {
			$html .= '<p class="lps-meta">' . $meta . '</p>';
		}
		$link  = '' === $url ? self::esc( $title ) : '<a href="' . self::esc( $url ) . '">' . self::esc( $title ) . '</a>';
		$html .= '<h3>' . $link . ( $is_current ? ' <span class="lps-meta">' . self::esc( 'en' === $locale ? 'this page' : 'esta página' ) . '</span>' : '' ) . '</h3>';
		$html .= '</li>';
		return $html;
	}

	/**
	 * Renders one material list; hidden states never produce a row.
	 *
	 * @param array<int, array<string, mixed>> $materials Material records.
	 * @param string                           $locale    Supported locale slug.
	 */
	private static function materials_list( array $materials, string $locale ): string {
		$items = '';
		foreach ( $materials as $material ) {
			$row = self::material_row( self::record( $material ), $locale );
			if ( '' !== $row ) {
				$items .= $row;
			}
		}
		return '' === $items ? '' : '<ul class="lps-materials">' . $items . '</ul>';
	}

	/**
	 * Renders one descriptive material row.
	 *
	 * Released resources link their title to the guarded download or the
	 * external destination; withdrawn, pending-scan and undeliverable rows
	 * show their state text and never a dead link.
	 *
	 * @param array<string, mixed> $material Material record.
	 * @param string               $locale   Supported locale slug.
	 */
	private static function material_row( array $material, string $locale ): string {
		$english = 'en' === $locale;
		$state   = self::material_state( $material );
		if ( 'hidden' === $state ) {
			return '';
		}
		$title = self::text( $material['title'] ?? '' );
		if ( '' === $title ) {
			return '';
		}
		$meta  = self::meta_parts(
			array(
				self::type_label( self::text( $material['type'] ?? '' ), $locale ),
				self::language_label( self::text( $material['language'] ?? '' ), $locale ),
				self::byte_size( self::num( $material['bytes'] ?? 0 ), $locale ),
				self::text( $material['mime'] ?? '' ),
			)
		);
		$html  = '<li class="lps-material" data-state="' . self::esc( $state ) . '">';
		$html .= '<p class="lps-material-title">';
		if ( 'download' === $state ) {
			$html .= '<a href="' . self::esc( self::safe_url( self::text( $material['download_url'] ?? '' ) ) ) . '">' . self::esc( $title ) . '</a>';
		} elseif ( 'external' === $state ) {
			$html .= '<a href="' . self::esc( self::safe_url( self::text( $material['external_url'] ?? '' ) ) ) . '" rel="noopener noreferrer">' . self::esc( $title ) . '</a>';
			$html .= ' <span class="lps-meta">' . self::esc( $english ? 'external' : 'externo' ) . '</span>';
		} else {
			$html .= self::esc( $title );
		}
		$html .= '</p>';
		if ( '' !== $meta ) {
			$html .= '<p class="lps-meta">' . $meta . '</p>';
		}
		$summary = self::text( $material['summary'] ?? '' );
		if ( '' !== $summary ) {
			$html .= '<p class="lps-material-summary">' . self::esc( $summary ) . '</p>';
		}
		$checksum = self::text( $material['sha256'] ?? '' );
		if ( 'download' === $state && '' !== $checksum ) {
			$html .= '<p class="lps-meta lps-checksum">' . self::esc( 'SHA-256 ' ) . '<code class="lps-breakable">' . self::esc( $checksum ) . '</code></p>';
		}
		$updated = self::text( $material['updated_at'] ?? '' );
		if ( 'withdrawn' === $state ) {
			$notice = $english ? 'Withdrawn material.' : 'Material retirado.';
			if ( '' !== $updated ) {
				$notice .= ' ' . ( $english ? 'Withdrawn on ' : 'Retirado em ' ) . self::format_date( $updated, $locale );
			}
			$html .= '<p class="lps-material-note lps-material-note-warning">' . self::esc( $notice ) . '</p>';
		} elseif ( 'scan-pending' === $state ) {
			$html .= '<p class="lps-material-note">' . self::esc( $english ? 'Security scan pending; the download is not available yet.' : 'Verificação de segurança pendente; o download ainda não está disponível.' ) . '</p>';
		} elseif ( 'unavailable' === $state ) {
			$html .= '<p class="lps-material-note">' . self::esc( $english ? 'This material is temporarily unavailable.' : 'Este material está temporariamente indisponível.' ) . '</p>';
		} elseif ( '' !== $updated ) {
			$html .= '<p class="lps-meta">' . self::esc( ( $english ? 'Updated on ' : 'Atualizado em ' ) . self::format_date( $updated, $locale ) ) . '</p>';
		}
		return $html . '</li>';
	}

	/**
	 * Returns the render state of one material row.
	 *
	 * `download` and `external` are the only states that render a link;
	 * `hidden` rows (draft, scheduled-not-due, unpublished) never reach the
	 * markup. The effective release state is computed by the route layer
	 * through `TeachingContracts::effective_release_state`, so the surface
	 * evaluates the identical rule the download resolver enforces.
	 *
	 * @param array<string, mixed> $material Material record.
	 */
	public static function material_state( array $material ): string {
		$effective = self::text( $material['effective_state'] ?? '' );
		if ( 'released' !== $effective ) {
			return 'withdrawn' === $effective ? 'withdrawn' : 'hidden';
		}
		$external = self::text( $material['external_url'] ?? '' );
		if ( '' !== $external ) {
			return 'external';
		}
		if ( '' !== self::text( $material['download_url'] ?? '' ) ) {
			if ( 'clean' !== self::text( $material['scan_state'] ?? '' ) ) {
				return 'scan-pending';
			}
			if ( 'approved' !== self::text( $material['rights_review'] ?? '' )
				|| 'approved' !== self::text( $material['accessibility_review'] ?? '' ) ) {
				return 'unavailable';
			}
			return 'download';
		}
		return 'unavailable';
	}

	/**
	 * Renders the term period line: label plus boundary dates.
	 *
	 * @param array<string, mixed> $term   Term record.
	 * @param string               $locale Supported locale slug.
	 */
	private static function term_line( array $term, string $locale ): string {
		$label  = self::text( $term['period_label'] ?? '' );
		$starts = self::text( $term['starts_on'] ?? '' );
		$ends   = self::text( $term['ends_on'] ?? '' );
		$dates  = '';
		if ( '' !== $starts && '' !== $ends ) {
			// A word separator, not a spaced hyphen: wptexturize would rewrite
			// ` - ` into an en dash entity inside the rendered template.
			$to    = 'en' === $locale ? ' to ' : ' a ';
			$dates = '<time datetime="' . self::esc( $starts ) . '">' . self::esc( self::format_date( $starts, $locale ) ) . '</time>'
				. $to . '<time datetime="' . self::esc( $ends ) . '">' . self::esc( self::format_date( $ends, $locale ) ) . '</time>';
		}
		$parts = array_filter( array( $label, $dates ), static fn( string $part ): bool => '' !== $part );
		return array() === $parts ? '' : '<p class="lps-meta lps-term-period">' . implode( ' · ', $parts ) . '</p>';
	}

	/**
	 * Renders one metadata line when at least one part is non-empty.
	 *
	 * @param array<int, string> $parts Metadata values.
	 */
	private static function meta_line( array $parts ): string {
		$meta = self::meta_parts( $parts );
		return '' === $meta ? '' : '<p class="lps-meta">' . $meta . '</p>';
	}

	/**
	 * Joins non-empty metadata parts with the middot separator.
	 *
	 * @param array<int, string> $parts Metadata values.
	 */
	private static function meta_parts( array $parts ): string {
		$clean = array();
		foreach ( $parts as $part ) {
			if ( '' !== $part ) {
				$clean[] = self::esc( $part );
			}
		}
		return implode( ' · ', $clean );
	}

	/**
	 * Renders one summary paragraph when non-empty.
	 *
	 * @param string $summary Summary text.
	 */
	private static function paragraph( string $summary ): string {
		return '' === $summary ? '' : '<p class="lps-summary">' . self::esc( $summary ) . '</p>';
	}

	/**
	 * Renders governed body markup.
	 *
	 * @param string $body Body markup.
	 */
	private static function body( string $body ): string {
		return '' === $body ? '' : '<div class="lps-body">' . self::rich( $body ) . '</div>';
	}

	/**
	 * Renders the explicit stale-translation warning of one record.
	 *
	 * A stale English variant stays at its own URL and announces that its review
	 * trails the Portuguese source; the notice is part of the record, never a
	 * silent substitution.
	 *
	 * @param array<mixed,mixed> $record Teaching record.
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
	 * @param array<mixed,mixed> $record Teaching record.
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
	 * Returns the localized label of one temporal status key.
	 *
	 * @param string $status Stored status key.
	 * @param string $locale Supported locale slug.
	 */
	public static function temporal_label( string $status, string $locale ): string {
		$english = 'en' === $locale;
		$labels  = array(
			'current'   => $english ? 'In progress' : 'Em andamento',
			'upcoming'  => $english ? 'Upcoming' : 'Próxima',
			'completed' => $english ? 'Completed' : 'Concluída',
			'cancelled' => $english ? 'Cancelled' : 'Cancelada',
		);
		return $labels[ $status ] ?? $status;
	}

	/**
	 * Returns the localized label of one teaching-team role key.
	 *
	 * @param string $role   Stored role key.
	 * @param string $locale Supported locale slug.
	 */
	private static function team_role_label( string $role, string $locale ): string {
		$english = 'en' === $locale;
		$labels  = array(
			'lead'       => $english ? 'lead' : 'responsável',
			'co-teacher' => $english ? 'co-teacher' : 'co-docente',
			'assistant'  => $english ? 'assistant' : 'assistente',
		);
		return $labels[ $role ] ?? $role;
	}

	/**
	 * Returns the localized label of one course level key.
	 *
	 * @param string $level  Stored level key.
	 * @param string $locale Supported locale slug.
	 */
	private static function level_label( string $level, string $locale ): string {
		$english = 'en' === $locale;
		$labels  = array(
			'undergraduate' => $english ? 'Undergraduate' : 'Graduação',
			'graduate'      => $english ? 'Graduate' : 'Pós-graduação',
			'extension'     => $english ? 'Extension' : 'Extensão',
		);
		return $labels[ $level ] ?? $level;
	}

	/**
	 * Returns the localized label of one resource type key.
	 *
	 * @param string $type   Stored type key.
	 * @param string $locale Supported locale slug.
	 */
	private static function type_label( string $type, string $locale ): string {
		$english = 'en' === $locale;
		$labels  = array(
			'document' => $english ? 'document' : 'documento',
			'slides'   => 'slides',
			'notebook' => 'notebook',
			'dataset'  => $english ? 'dataset' : 'conjunto de dados',
			'link'     => $english ? 'link' : 'link',
			'other'    => $english ? 'other' : 'outro',
		);
		return $labels[ $type ] ?? $type;
	}

	/**
	 * Returns the display label of one authored resource language.
	 *
	 * @param string $language Stored language key.
	 * @param string $locale   Supported locale slug.
	 */
	private static function language_label( string $language, string $locale ): string {
		$normalized = strtolower( trim( $language ) );
		if ( '' === $normalized ) {
			return '';
		}
		if ( 'pt-br' === $normalized || 'pt' === $normalized ) {
			return 'en' === $locale ? 'Portuguese' : 'Português';
		}
		if ( 'en' === $normalized ) {
			return 'en' === $locale ? 'English' : 'Inglês';
		}
		return $language;
	}

	/**
	 * Formats a byte size for the metadata line, or empty when unknown.
	 *
	 * @param int    $bytes  Byte count.
	 * @param string $locale Supported locale slug.
	 */
	private static function byte_size( int $bytes, string $locale ): string {
		if ( 0 >= $bytes ) {
			return '';
		}
		$english = 'en' === $locale;
		if ( 1048576 <= $bytes ) {
			$mb = $bytes / 1048576;
			return ( $english ? number_format( $mb, 1, '.', '' ) : number_format( $mb, 1, ',', '' ) ) . ' MB';
		}
		if ( 1024 <= $bytes ) {
			$kb = $bytes / 1024;
			return ( $english ? number_format( $kb, 1, '.', '' ) : number_format( $kb, 1, ',', '' ) ) . ' KB';
		}
		return $bytes . ' B';
	}

	/**
	 * Formats one ISO date or datetime for the surface locale.
	 *
	 * Delegates to the shared discovery formatter when it is loaded; the
	 * standalone fallback keeps the raw date so unit tests stay hermetic.
	 *
	 * @param string $value  ISO date or datetime.
	 * @param string $locale Supported locale slug.
	 */
	private static function format_date( string $value, string $locale ): string {
		$date = substr( trim( $value ), 0, 10 );
		if ( class_exists( DiscoverySurfaces::class ) ) {
			return DiscoverySurfaces::format_date( $date, 'day', $locale );
		}
		return $date;
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
	 * Converts boundary input to int.
	 *
	 * @param mixed $value Boundary input.
	 */
	private static function num( mixed $value ): int {
		return is_numeric( $value ) ? (int) $value : 0;
	}

	/**
	 * Narrows one boundary value to a record map.
	 *
	 * @param mixed $value Boundary input.
	 * @return array<string, mixed>
	 */
	private static function record( mixed $value ): array {
		/**
		 * WordPress record payloads are string-keyed maps by contract.
		 *
		 * @var array<string, mixed> $record
		 */
		$record = is_array( $value ) ? $value : array();
		return $record;
	}

	/**
	 * Renders stored editorial content as sanitised HTML.
	 *
	 * @param string $content Stored post content.
	 */
	private static function rich( string $content ): string {
		$rendered = function_exists( 'do_blocks' ) ? (string) do_blocks( $content ) : $content;
		if ( function_exists( 'wp_kses_post' ) ) {
			return (string) wp_kses_post( $rendered );
		}
		// Unit-test seam without WordPress: keep the allowed inline set only.
		return strip_tags( $rendered, '<p><a><em><strong><ul><ol><li><h2><h3><h4><blockquote><code><br><figure><figcaption><img><table><thead><tbody><tr><th><td><caption><time><abbr><sup><sub><span>' );
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
	 * Returns the URL only when its scheme is safe to render as a link.
	 *
	 * @param string $url Untrusted URL value.
	 */
	private static function safe_url( string $url ): string {
		$clean = trim( $url );
		if ( '' === $clean ) {
			return '';
		}
		$scheme = parse_url( $clean, PHP_URL_SCHEME ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- this renderer runs without WordPress loaded.
		if ( is_string( $scheme ) ) {
			return in_array( strtolower( $scheme ), array( 'http', 'https', 'mailto' ), true ) ? $clean : '';
		}
		return 1 === preg_match( '#^/(?!/)#', $clean ) ? $clean : '';
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
