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
 * All surfaces compose in the showcase vocabulary (`showcase/institutional-
 * redesign/public/`): page headers, section bands with kicker-led heads,
 * fact lists, course tables inside `lps-table-scroll`, term chips, unit and
 * record lists, and alert bands — no class here is invented for the theme.
 *
 * Every renderer returns escaped markup only; records are assembled by
 * `TeachingRoutes` from governed metadata. Escaping helpers are internal so
 * the renderers run in unit tests without WordPress loaded.
 *
 * @package LPS\Theme
 */

declare(strict_types=1);

namespace LPS\Theme;

use LPS\ContentModel\Relationships;
use LPS\ContentModel\Translations;
use WP_Post;

/**
 * Renders the teaching landing, course, and offering surfaces.
 */
final class TeachingSurfaces {
	/**
	 * Renders the teaching landing surface.
	 *
	 * Mirrors the showcase `/ensino/` page: a page header, a journey strip
	 * that jumps to each level band, and one `lps-table-scroll` course table
	 * per level with code chips, linked professors, and level pills.
	 *
	 * @param array<int, array<string, mixed>> $courses Published course records.
	 * @param string                           $locale  Supported locale slug.
	 */
	public static function landing( array $courses, string $locale ): string {
		$english = 'en' === $locale;
		$html    = '<section class="lps-teaching lps-teaching-landing" lang="' . self::esc( self::bcp47( $locale ) ) . '">';
		$html   .= '<div class="lps-page-header"><div class="lps-page-header-inner lps-page-grid">';
		$html   .= '<p class="lps-kicker">' . self::esc( $english ? 'Teaching' : 'Ensino' ) . '</p>';
		$html   .= '<h1 class="lps-page-title">' . self::esc( $english ? 'Courses and materials' : 'Disciplinas e materiais' ) . '</h1>';
		$html   .= '<p class="lps-lead">' . self::esc( $english ? 'Courses taught by laboratory professors in the Electrical Engineering Program at COPPE and at the Polytechnic School of UFRJ, from instrumentation to deep learning and quantum machine learning.' : 'Disciplinas ministradas pelos professores do laboratório no Programa de Engenharia Elétrica da COPPE e na Escola Politécnica da UFRJ, da instrumentação ao aprendizado profundo e ao aprendizado de máquina quântico.' ) . '</p>';
		$html   .= '</div></div>';
		if ( array() === $courses ) {
			$html .= '<p class="lps-empty">' . self::esc( $english ? 'No courses are published yet.' : 'Nenhuma disciplina publicada ainda.' ) . '</p>';
			return $html . '</section>';
		}

		$groups = self::course_groups( $courses, $locale );
		$first  = true;
		foreach ( $groups as $group ) {
			$html .= '<section class="lps-section' . ( $first ? ' lps-section--flush' : '' ) . '" id="' . self::esc( $group['anchor'] ) . '" aria-labelledby="' . self::esc( $group['heading_id'] ) . '">';
			$first = false;
			$html .= '<div class="lps-section-head"><div>';
			if ( '' !== $group['kicker'] ) {
				$html .= '<p class="lps-kicker">' . self::esc( $group['kicker'] ) . '</p>';
			}
			$html .= '<h2 id="' . self::esc( $group['heading_id'] ) . '">' . self::esc( $group['heading'] ) . '</h2>';
			$html .= '</div>';
			if ( 'pos-graduacao' === $group['anchor'] ) {
				$html .= '<div><p class="lps-mt-4"><a class="lps-more" href="https://www.pee.ufrj.br/" rel="external">PEE/COPPE →</a></p></div>';
			}
			$html   .= '</div>';
			$html   .= '<div class="lps-table-scroll"><table>';
			$html   .= '<caption>' . self::esc( $group['caption'] ) . '</caption>';
			$html   .= '<thead><tr>'
				. '<th scope="col">' . self::esc( $english ? 'Code' : 'Código' ) . '</th>'
				. '<th scope="col">' . self::esc( $english ? 'Course' : 'Disciplina' ) . '</th>'
				. '<th scope="col">' . self::esc( $english ? 'Professor' : 'Professor' ) . '</th>'
				. '<th scope="col">' . self::esc( $english ? 'Level' : 'Nível' ) . '</th>'
				. '</tr></thead><tbody>';
			$missing = false;
			foreach ( $group['courses'] as $course ) {
				$course = self::record( $course );
				$title  = self::text( $course['title'] ?? '' );
				if ( '' === $title ) {
					continue;
				}
				$code  = self::text( $course['code'] ?? '' );
				$level = self::text( $course['level'] ?? '' );
				$html .= '<tr>';
				if ( '' !== $code ) {
					$html .= '<td><span class="lps-course-code">' . self::esc( $code ) . '</span></td>';
				} else {
					$missing = true;
					$html   .= '<td>—</td>';
				}
				$html .= '<th scope="row">';
				$url   = self::safe_url( self::text( $course['url'] ?? '' ) );
				$html .= '' === $url ? self::esc( $title ) : '<a href="' . self::esc( $url ) . '">' . self::esc( $title ) . '</a>';
				$html .= self::translation_chip( $course, $locale );
				$html .= '</th>';
				$team  = self::teaching_team_names( $course, $locale );
				$html .= '<td>' . ( array() !== $team ? implode( ' · ', $team ) : '—' ) . '</td>';
				$html .= '<td><span class="lps-level ' . self::level_variant( $level ) . '">' . self::esc( self::level_label( $level, $locale ) ) . '</span></td>';
				$html .= '</tr>';
			}
			$html .= '</tbody></table></div>';
			if ( $missing ) {
				$html .= '<p class="lps-meta lps-mt-4">' . self::esc( $english ? 'Courses marked with “—” have no official code in the public source.' : 'Os códigos marcados com “—” são disciplinas cujo código oficial não consta na fonte pública.' ) . '</p>';
			}
			$html .= '</section>';
		}
		$course_path = TeachingRoutes::course_path( $locale, $english ? 'cpe886-quantum-machine-learning-en' : 'cpe886-quantum-machine-learning' );
		$materials   = TrustSurfaces::record_card(
			array(
				'title'  => $english ? 'Course pages' : 'Páginas das disciplinas',
				'body'   => $english
					? 'Syllabi, problem sets and supporting material for the laboratory\'s documented courses are published on this domain, organized into per-offering units.'
					: 'Planos de aula, ementas, listas e material de apoio das disciplinas documentadas do laboratório são publicados neste domínio, organizados em unidades por oferta.',
				'action' => array(
					'href'  => $course_path,
					'label' => $english ? 'Example: CPE-886' : 'Exemplo: CPE-886',
				),
			)
		);
		$materials  .= TrustSurfaces::record_card(
			array(
				'title' => $english ? 'Continuing education' : 'Formação continuada',
				'body'  => $english
					? 'The laboratory works from junior research initiation with secondary and technical students, through undergraduate teaching at Poli/UFRJ, to post-doctoral supervision.'
					: 'A atuação do laboratório abrange a iniciação científica júnior com estudantes do ensino médio e técnico, a graduação na Poli/UFRJ e o pós-doutorado.',
			)
		);
		$html       .= '<section class="lps-section" id="materiais" aria-labelledby="teaching-materials"><div class="lps-section-head"><div>'
			. '<p class="lps-kicker">' . self::esc( $english ? 'Materials' : 'Materiais' ) . '</p>'
			. '<h2 id="teaching-materials">' . self::esc( $english ? 'Course material and support' : 'Material didático e apoio' ) . '</h2>'
			. '</div></div><div class="lps-grid lps-grid--2">' . $materials . '</div></section>';
		$html       .= '<section class="lps-section">' . TrustSurfaces::cta_band(
			$english ? 'Studying at LPS' : 'Estudar no LPS',
			$english
				? 'Admission to master and doctoral studies runs through the selection process of the Electrical Engineering Program at COPPE/UFRJ.'
				: 'O ingresso em mestrado e doutorado se dá pelo processo seletivo do Programa de Engenharia Elétrica da COPPE/UFRJ.',
			array(
				array(
					'href'  => TrustRoutes::archive_path( 'lps_opportunity', $locale ),
					'label' => $english ? 'Opportunities' : 'Oportunidades',
				),
				array(
					'href'  => 'https://www.pee.ufrj.br/',
					'label' => 'PEE/COPPE',
				),
			)
		) . '</section>';
		return $html . '</section>';
	}

	/**
	 * Renders the course detail surface with its offering history.
	 *
	 * Mirrors the showcase course page: kicker-led page header, a `lps-facts`
	 * about band with professor and program, the syllabus as term chips, and
	 * offering strata as named record lists. Offerings group into in-progress,
	 * upcoming and previous strata so a student distinguishes the live
	 * section from the record of completed terms; completed terms stay
	 * linked, never archived away.
	 *
	 * @param array<string, mixed>             $course    Course record.
	 * @param array<int, array<string, mixed>> $offerings Published offering records.
	 * @param string                           $locale    Supported locale slug.
	 */
	public static function course( array $course, array $offerings, string $locale ): string {
		$english = 'en' === $locale;
		$level   = self::text( $course['level'] ?? '' );
		$kicker  = self::meta_parts(
			array(
				self::text( $course['code'] ?? '' ),
				self::level_label( $level, $locale ),
			)
		);
		$html    = '<article class="lps-teaching lps-course" lang="' . self::esc( self::bcp47( $locale ) ) . '">';
		$html   .= '<div class="lps-page-header"><div class="lps-page-header-inner lps-page-grid">';
		if ( '' !== $kicker ) {
			$html .= '<p class="lps-kicker">' . $kicker . '</p>';
		}
		$html   .= '<h1 class="lps-page-title">' . self::esc( self::text( $course['title'] ?? '' ) ) . '</h1>';
		$html   .= self::translation_notice( $course, $locale );
		$summary = self::text( $course['summary'] ?? '' );
		if ( '' !== $summary ) {
			$html .= '<p class="lps-lead">' . self::esc( $summary ) . '</p>';
		}
		$html .= '</div></div>';

		$current_offering = array();
		foreach ( $offerings as $candidate ) {
			$candidate = self::record( $candidate );
			if ( 'current' === self::text( $candidate['temporal_status'] ?? '' ) ) {
				$current_offering = $candidate;
				break;
			}
		}

		$facts         = '';
		$professors    = self::teaching_team_names( $course, $locale );
		$prerequisites = self::text( $course['prerequisites'] ?? '' );
		if ( array() !== $professors ) {
			$facts .= '<dt>' . self::esc( $english ? 'Professor' : 'Professor' ) . '</dt><dd>' . implode( ' · ', $professors ) . '</dd>';
		}
		$program = self::text( $course['program'] ?? '' );
		if ( '' !== $program ) {
			$facts .= '<dt>' . self::esc( $english ? 'Program' : 'Programa' ) . '</dt><dd>' . self::esc( $program ) . '</dd>';
		}
		if ( array() !== $current_offering ) {
			$term    = self::record( $current_offering['term'] ?? null );
			$token   = self::text( $term['token'] ?? '' );
			$section = self::text( $current_offering['section_key'] ?? '' );
			$oferta  = trim( $token . ' · ' . $section, ' ·' );
			if ( '' !== $oferta ) {
				$facts .= '<dt>' . self::esc( $english ? 'Offering' : 'Oferta' ) . '</dt><dd>' . self::esc( $oferta ) . ' · <em>' . self::esc( self::temporal_label( 'current', $locale ) ) . '</em></dd>';
			}
			$label  = self::text( $term['period_label'] ?? '' );
			$starts = self::text( $term['starts_on'] ?? '' );
			$ends   = self::text( $term['ends_on'] ?? '' );
			$period = '';
			if ( '' !== $starts && '' !== $ends ) {
				$to     = $english ? ' to ' : ' a ';
				$period = '<time datetime="' . self::esc( $starts ) . '">' . self::esc( self::format_date( $starts, $locale ) ) . '</time>'
					. $to . '<time datetime="' . self::esc( $ends ) . '">' . self::esc( self::format_date( $ends, $locale ) ) . '</time>';
			}
			$period = implode( ' · ', array_filter( array( self::esc( $label ), $period ), static fn( string $part ): bool => '' !== $part ) );
			if ( '' !== $period ) {
				$facts .= '<dt>' . self::esc( $english ? 'Period' : 'Período' ) . '</dt><dd>' . $period . '</dd>';
			}
			$venue = self::text( $current_offering['venue'] ?? '' );
			if ( '' !== $venue ) {
				$facts .= '<dt>' . self::esc( $english ? 'Venue' : 'Local' ) . '</dt><dd>' . self::esc( $venue ) . '</dd>';
			}
		}
		$body = self::body( self::text( $course['body'] ?? '' ), ' lps-mt-8' );
		if ( '' !== $facts || '' !== $body || '' !== $prerequisites ) {
			$html .= '<section class="lps-section lps-section--flush" aria-labelledby="course-about">';
			$html .= '<div class="lps-section-head"><div>'
				. '<p class="lps-kicker">' . self::esc( $english ? 'Course' : 'Disciplina' ) . '</p>'
				. '<h2 id="course-about">' . self::esc( $english ? 'About the course' : 'Sobre a disciplina' ) . '</h2>'
				. '</div></div>';
			if ( '' !== $facts ) {
				$html .= '<dl class="lps-facts">' . $facts . '</dl>';
			}
			$html .= $body;
			if ( '' !== $prerequisites ) {
				$html .= '<p class="lps-meta">' . self::esc( ( $english ? 'Prerequisites: ' : 'Pré-requisitos: ' ) . $prerequisites ) . '</p>';
			}
			$html .= '</section>';
		}

		$syllabus = self::text( $course['syllabus'] ?? '' );
		if ( '' !== $syllabus ) {
			$html .= '<section class="lps-section" aria-labelledby="course-syllabus">';
			$html .= '<div class="lps-section-head"><div>'
				. '<p class="lps-kicker">' . self::esc( $english ? 'Syllabus' : 'Ementa' ) . '</p>'
				. '<h2 id="course-syllabus">' . self::esc( $english ? 'Course contents' : 'Conteúdo da disciplina' ) . '</h2>'
				. '</div></div>';
			$html .= self::syllabus_list( $syllabus );
			$html .= '</section>';
		}

		if ( array() !== $current_offering ) {
			$units             = self::record( $current_offering['units'] ?? null );
			$materials         = self::record( $current_offering['materials'] ?? null );
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
			if ( array() !== $units ) {
				$html .= '<section class="lps-section" aria-labelledby="course-units">';
				$html .= '<div class="lps-section-head"><div>'
					. '<p class="lps-kicker">' . self::esc( $english ? 'Units' : 'Unidades' ) . '</p>'
					. '<h2 id="course-units">' . self::esc( $english ? 'Units and materials' : 'Unidades e materiais' ) . '</h2>'
					. '</div></div>';
				$html .= '<ol class="lps-course-units">';
				$index = 0;
				foreach ( $units as $unit ) {
					$unit   = self::record( $unit );
					$anchor = self::text( $unit['anchor'] ?? '' );
					$title  = self::text( $unit['title'] ?? '' );
					if ( '' === $title ) {
						continue;
					}
					++$index;
					$html .= '<li' . ( '' !== $anchor ? ' id="' . self::esc( $anchor ) . '"' : '' ) . '><h3>' . self::esc( $index . '. ' . $title ) . '</h3>';
					$html .= self::body( self::text( $unit['body'] ?? '' ) );
					$html .= self::materials_list( $materials_by_unit[ $anchor ] ?? array(), $locale );
					$html .= '</li>';
				}
				$html .= '</ol></section>';
			}
			if ( array() !== $ungrouped ) {
				$html .= '<section class="lps-section" aria-labelledby="course-materials">';
				$html .= '<div class="lps-section-head"><div>'
					. '<p class="lps-kicker">' . self::esc( $english ? 'Materials' : 'Materiais' ) . '</p>'
					. '<h2 id="course-materials">' . self::esc( $english ? 'Course materials' : 'Materiais da disciplina' ) . '</h2>'
					. '</div></div>';
				$html .= self::materials_list( $ungrouped, $locale );
				$html .= '</section>';
			}
		} else {
			$html .= '<div class="lps-alert lps-alert-info"><p>' . self::esc( $english ? 'There is no active offering registered for this course; the material is kept for reference.' : 'Não há oferta ativa registrada para esta disciplina; o material é mantido para consulta.' ) . '</p></div>';
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
			$html .= '<div class="lps-alert lps-alert-info"><p>' . self::esc( $english ? 'No published offerings for this course yet.' : 'Nenhuma turma publicada para esta disciplina ainda.' ) . '</p></div>';
			return $html . '</article>';
		}
		$html .= '<section class="lps-section" aria-labelledby="course-offerings">';
		$html .= '<div class="lps-section-head"><div>'
			. '<p class="lps-kicker">' . self::esc( $english ? 'Sections' : 'Turmas' ) . '</p>'
			. '<h2 id="course-offerings">' . self::esc( $english ? 'Sections' : 'Turmas' ) . '</h2>'
			. '</div></div>';
		$html .= self::offering_group( $current, $english ? 'In progress' : 'Em andamento', $locale );
		$html .= self::offering_group( $upcoming, $english ? 'Upcoming offerings' : 'Próximas ofertas', $locale );
		$html .= self::offering_group( $previous, $english ? 'Previous offerings' : 'Ofertas anteriores', $locale );
		$html .= '</section>';
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
		// A material anchored to an unknown unit cannot be dropped: it falls
		// back to the trailing materials section like an unanchored record.
		$unit_anchors = array();
		foreach ( $units as $unit ) {
			$anchor = self::text( self::record( $unit )['anchor'] ?? '' );
			if ( '' !== $anchor ) {
				$unit_anchors[ $anchor ] = true;
			}
		}
		foreach ( $materials_by_unit as $anchor => $rows ) {
			if ( isset( $unit_anchors[ $anchor ] ) ) {
				continue;
			}
			foreach ( $rows as $row ) {
				$ungrouped[] = $row;
			}
			unset( $materials_by_unit[ $anchor ] );
		}
		$render_materials_section = array() !== $ungrouped || array() === $materials;
		$notices                  = self::record( $offering['notices'] ?? null );

		$course_url   = self::safe_url( self::text( $course['url'] ?? '' ) );
		$course_title = self::text( $course['title'] ?? '' );
		$course_code  = self::text( $course['code'] ?? '' );

		$html  = '<article class="lps-teaching lps-offering" data-state="' . self::esc( $status ) . '" lang="' . self::esc( self::bcp47( $locale ) ) . '">';
		$html .= '<div class="lps-page-header"><div class="lps-page-header-inner lps-page-grid">';
		if ( '' !== $course_title ) {
			$context = '' === $course_code ? $course_title : $course_code . ' · ' . $course_title;
			$html   .= '<p class="lps-meta lps-offering-course">';
			$html   .= '' === $course_url ? self::esc( $context ) : '<a href="' . self::esc( $course_url ) . '">' . self::esc( $context ) . '</a>';
			$html   .= '</p>';
		}
		$html   .= '<h1>' . self::esc( self::text( $offering['title'] ?? '' ) ) . '</h1>';
		$html   .= self::translation_notice( $offering, $locale );
		$html   .= '<p class="lps-meta lps-offering-identity">'
			. '<span class="lps-term-token">' . self::esc( self::text( $term['token'] ?? '' ) ) . '</span>'
			. ' <span class="lps-section">' . self::esc( self::text( $offering['section_key'] ?? '' ) ) . '</span>'
			. ' <span class="lps-temporal-status">' . self::esc( self::temporal_label( $status, $locale ) ) . '</span>'
			. '</p>';
		$summary = self::text( $offering['summary'] ?? '' );
		if ( '' !== $summary ) {
			$html .= '<p class="lps-lead">' . self::esc( $summary ) . '</p>';
		}
		$html .= '</div></div>';

		$facts  = '';
		$period = '';
		$label  = self::text( $term['period_label'] ?? '' );
		$starts = self::text( $term['starts_on'] ?? '' );
		$ends   = self::text( $term['ends_on'] ?? '' );
		if ( '' !== $starts && '' !== $ends ) {
			// A word separator, not a spaced hyphen: wptexturize would rewrite
			// ` - ` into an en dash entity inside the rendered template.
			$to     = $english ? ' to ' : ' a ';
			$period = '<time datetime="' . self::esc( $starts ) . '">' . self::esc( self::format_date( $starts, $locale ) ) . '</time>'
				. $to . '<time datetime="' . self::esc( $ends ) . '">' . self::esc( self::format_date( $ends, $locale ) ) . '</time>';
		}
		$period   = implode( ' · ', array_filter( array( self::esc( $label ), $period ), static fn( string $part ): bool => '' !== $part ) );
		$venue    = self::text( $offering['venue'] ?? '' );
		$schedule = self::text( $offering['schedule'] ?? '' );
		if ( '' !== $schedule ) {
			$facts .= '<dt>' . self::esc( $english ? 'Schedule' : 'Horário' ) . '</dt><dd>' . self::esc( $schedule ) . '</dd>';
		}
		if ( '' !== $period ) {
			$facts .= '<dt>' . self::esc( $english ? 'Period' : 'Período' ) . '</dt><dd>' . $period . '</dd>';
		}
		if ( '' !== $venue ) {
			$facts .= '<dt>' . self::esc( $english ? 'Venue' : 'Local' ) . '</dt><dd>' . self::esc( $venue ) . '</dd>';
		}
		$body = self::body( self::text( $offering['body'] ?? '' ), ' lps-mt-8' );
		$lms  = self::safe_url( self::text( $offering['lms_url'] ?? '' ) );
		if ( '' !== $facts || '' !== $body || '' !== $lms ) {
			$html .= '<section class="lps-section lps-section--flush" aria-labelledby="offering-about">';
			$html .= '<div class="lps-section-head"><div>'
				. '<p class="lps-kicker">' . self::esc( $english ? 'Section' : 'Turma' ) . '</p>'
				. '<h2 id="offering-about">' . self::esc( $english ? 'About this section' : 'Sobre esta turma' ) . '</h2>'
				. '</div></div>';
			if ( '' !== $facts ) {
				$html .= '<dl class="lps-facts">' . $facts . '</dl>';
			}
			$html .= $body;
			if ( '' !== $lms ) {
				$label_lms = $english ? 'Virtual classroom (external link)' : 'Ambiente virtual (link externo)';
				$html     .= '<p class="lps-mt-4"><a class="lps-more" href="' . self::esc( $lms ) . '" rel="noopener noreferrer">' . self::esc( $label_lms ) . '</a></p>';
			}
			$html .= '</section>';
		}

		// The avisos stream is public and PT-first by design: it renders here
		// the moment a professor posts, on both locale routes, newest first.
		if ( array() !== $notices ) {
			$items = '';
			foreach ( $notices as $notice ) {
				$notice = self::record( $notice );
				$body   = self::text( $notice['body'] ?? '' );
				if ( '' === $body ) {
					continue;
				}
				$items  .= '<li class="lps-record">';
				$created = substr( self::text( $notice['created_at'] ?? '' ), 0, 10 );
				if ( '' !== $created ) {
					$items .= '<p class="lps-meta"><time datetime="' . self::esc( $created ) . '">' . self::esc( self::format_date( $created, $locale ) ) . '</time></p>';
				}
				$items .= self::body( $body );
				$items .= '</li>';
			}
			if ( '' !== $items ) {
				$html .= '<section class="lps-section" aria-labelledby="avisos">';
				$html .= '<div class="lps-section-head"><div>'
					. '<p class="lps-kicker">' . self::esc( $english ? 'Latest' : 'Mural' ) . '</p>'
					. '<h2 id="avisos">' . self::esc( $english ? 'Announcements' : 'Avisos' ) . '</h2>'
					. '</div></div>';
				$html .= '<ul class="lps-record-list">' . $items . '</ul>';
				$html .= '</section>';
			}
		}

		if ( array() !== $team ) {
			$members = '';
			foreach ( $team as $member ) {
				$member = self::record( $member );
				$name   = self::text( $member['name'] ?? '' );
				if ( '' === $name ) {
					continue;
				}
				$role     = self::team_role_label( self::text( $member['role'] ?? '' ), $locale );
				$members .= '<li>' . self::member_link( $member, $locale ) . ( '' === $role ? '' : ' <span class="lps-team-role">' . self::esc( $role ) . '</span>' ) . '</li>';
			}
			if ( '' !== $members ) {
				$html .= '<section class="lps-section" aria-labelledby="offering-team">';
				$html .= '<div class="lps-section-head"><div>'
					. '<p class="lps-kicker">' . self::esc( $english ? 'Faculty' : 'Docentes' ) . '</p>'
					. '<h2 id="offering-team">' . self::esc( $english ? 'Teaching team' : 'Equipe de ensino' ) . '</h2>'
					. '</div></div>';
				$html .= '<ul class="lps-teaching-team">' . $members . '</ul>';
				$html .= '</section>';
			}
		}

		$syllabus = self::text( $offering['syllabus'] ?? '' );
		if ( '' !== $syllabus ) {
			$html .= '<section class="lps-section" aria-labelledby="offering-syllabus">';
			$html .= '<div class="lps-section-head"><div>'
				. '<p class="lps-kicker">' . self::esc( $english ? 'Syllabus' : 'Ementa' ) . '</p>'
				. '<h2 id="offering-syllabus">' . self::esc( $english ? 'Course contents' : 'Conteúdo da disciplina' ) . '</h2>'
				. '</div></div>';
			$html .= self::syllabus_list( $syllabus );
			$html .= '</section>';
		}

		// Materials-first navigation: the contents strip links every unit anchor
		// and the materials section so a long list stays reachable near the top.
		if ( array() !== $units || $render_materials_section ) {
			$html .= '<nav class="lps-toc" aria-label="' . self::esc( $english ? 'Units and materials' : 'Unidades e materiais' ) . '">';
			$html .= '<h2>' . self::esc( $english ? 'Contents' : 'Conteúdo' ) . '</h2>';
			$html .= '<ul>';
			if ( array() !== $notices ) {
				$html .= '<li><a href="#avisos">' . self::esc( $english ? 'Announcements' : 'Avisos' ) . '</a></li>';
			}
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
			$html .= '<section class="lps-section" aria-labelledby="offering-units">';
			$html .= '<div class="lps-section-head"><div>'
				. '<p class="lps-kicker">' . self::esc( $english ? 'Units' : 'Unidades' ) . '</p>'
				. '<h2 id="offering-units">' . self::esc( $english ? 'Units' : 'Unidades' ) . '</h2>'
				. '</div></div>';
			$html .= '<ol class="lps-course-units">';
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
			$html .= '</ol></section>';
		}

		if ( $render_materials_section ) {
			$html .= '<section class="lps-section" aria-labelledby="materiais">';
			$html .= '<div class="lps-section-head"><div>'
				. '<p class="lps-kicker">' . self::esc( $english ? 'Materials' : 'Materiais' ) . '</p>'
				. '<h2 id="materiais">' . self::esc( $english ? 'Materials' : 'Materiais' ) . '</h2>'
				. '</div></div>';
			if ( array() === $materials ) {
				$html .= '<p class="lps-empty">' . self::esc( $english ? 'Materials are not published yet.' : 'Materiais ainda não publicados.' ) . '</p>';
			} else {
				$html .= self::materials_list( $ungrouped, $locale );
			}
			$html .= '</section>';
		}

		if ( array() !== $siblings ) {
			$html    .= '<nav class="lps-offering-history" aria-label="' . self::esc( $english ? 'Other offerings of this course' : 'Outras ofertas desta disciplina' ) . '">';
			$html    .= '<h2>' . self::esc( $english ? 'Other offerings' : 'Outras ofertas' ) . '</h2>';
			$html    .= '<ul class="lps-record-list">';
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
		$html = '<h3>' . self::esc( $heading ) . '</h3><ul class="lps-record-list">';
		foreach ( $offerings as $offering ) {
			$html .= self::offering_row( $offering, $locale );
		}
		return $html . '</ul>';
	}

	/**
	 * Renders one offering row: linked title, term token, section and status.
	 *
	 * @param array<string, mixed> $offering   Offering record.
	 * @param string               $locale     Supported locale slug.
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
		$html   = '<li data-state="' . self::esc( $status ) . '">';
		$link   = '' === $url ? self::esc( $title ) : '<a href="' . self::esc( $url ) . '">' . self::esc( $title ) . '</a>';
		$html  .= '<h3>' . $link . ( $is_current ? ' <span class="lps-meta">' . self::esc( 'en' === $locale ? 'this page' : 'esta página' ) . '</span>' : '' ) . '</h3>';
		if ( '' !== $meta ) {
			$html .= '<p class="lps-meta">' . $meta . '</p>';
		}
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
		return '' === $items ? '' : '<ul class="lps-record-list">' . $items . '</ul>';
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
		$html  = '<li data-state="' . self::esc( $state ) . '">';
		$html .= '<h3>';
		if ( 'download' === $state ) {
			$html .= '<a href="' . self::esc( self::safe_url( self::text( $material['download_url'] ?? '' ) ) ) . '">' . self::esc( $title ) . '</a>';
		} elseif ( 'external' === $state ) {
			$html .= '<a href="' . self::esc( self::safe_url( self::text( $material['external_url'] ?? '' ) ) ) . '" rel="noopener noreferrer">' . self::esc( $title ) . '</a>';
			$html .= ' <span class="lps-meta">' . self::esc( $english ? 'external' : 'externo' ) . '</span>';
		} else {
			$html .= self::esc( $title );
		}
		$html   .= '</h3>';
		$summary = self::text( $material['summary'] ?? '' );
		if ( '' !== $summary ) {
			$html .= '<p class="lps-summary">' . self::esc( $summary ) . '</p>';
		}
		if ( '' !== $meta ) {
			$html .= '<p class="lps-meta">' . $meta . '</p>';
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
	 * Renders a syllabus as the showcase term-chip list, one item per line.
	 *
	 * @param string $syllabus Stored syllabus text.
	 */
	private static function syllabus_list( string $syllabus ): string {
		$lines = preg_split( '/\r\n|\r|\n|;/', $syllabus );
		if ( ! is_array( $lines ) ) {
			$lines = array();
		}
		$html = '<ul class="lps-term-token">';
		foreach ( $lines as $line ) {
			$line = trim( (string) $line );
			if ( '' !== $line ) {
				$html .= '<li>' . self::esc( $line ) . '</li>';
			}
		}
		return $html . '</ul>';
	}

	/**
	 * Buckets courses into their level groups in showcase order.
	 *
	 * Graduate leads, then undergraduate, extension, and any unmapped level
	 * last; each group carries the anchor the homepage journeys link into.
	 *
	 * @param array<int, array<string, mixed>> $courses Published course records.
	 * @param string                           $locale  Supported locale slug.
	 * @return array<int, array{anchor: string, heading_id: string, kicker: string, journey: string, heading: string, caption: string, courses: array<int, array<string, mixed>>}>
	 */
	private static function course_groups( array $courses, string $locale ): array {
		$english = 'en' === $locale;
		$keys    = array( 'graduate', 'undergraduate', 'other' );
		$buckets = array();
		foreach ( $keys as $key ) {
			$buckets[ $key ] = array();
		}
		foreach ( $courses as $course ) {
			$course = self::record( $course );
			$level  = self::text( $course['level'] ?? '' );
			// Extension courses list under the graduate band, matching the
			// showcase's two-tier teaching composition.
			$key               = 'extension' === $level ? 'graduate' : ( in_array( $level, array( 'graduate', 'undergraduate' ), true ) ? $level : 'other' );
			$buckets[ $key ][] = $course;
		}
		$labels = array(
			'graduate'      => array(
				'anchor'     => 'pos-graduacao',
				'heading_id' => 'teaching-graduate',
				'journey'    => $english ? 'Graduate' : 'Pós-graduação',
				'heading'    => $english ? 'Graduate courses' : 'Disciplinas de pós-graduação',
				'caption'    => $english ? 'Graduate courses offered by the laboratory' : 'Disciplinas de pós-graduação oferecidas pelo laboratório',
			),
			'undergraduate' => array(
				'anchor'     => 'graduacao',
				'heading_id' => 'teaching-undergraduate',
				'journey'    => $english ? 'Undergraduate' : 'Graduação',
				'heading'    => $english ? 'Undergraduate courses' : 'Disciplinas de graduação',
				'caption'    => $english ? 'Undergraduate courses taught by laboratory professors' : 'Disciplinas de graduação ministradas por professores do laboratório',
			),
			'extension'     => array(
				'anchor'     => 'extensao',
				'heading_id' => 'teaching-extension',
				'journey'    => $english ? 'Extension' : 'Extensão',
				'heading'    => $english ? 'Extension courses' : 'Disciplinas de extensão',
				'caption'    => $english ? 'Extension courses offered by the laboratory' : 'Disciplinas de extensão oferecidas pelo laboratório',
			),
			'other'         => array(
				'anchor'     => 'outras-disciplinas',
				'heading_id' => 'teaching-other',
				'journey'    => $english ? 'Other' : 'Outras',
				'heading'    => $english ? 'Other courses' : 'Outras disciplinas',
				'caption'    => $english ? 'Courses offered by the laboratory' : 'Disciplinas oferecidas pelo laboratório',
			),
		);
		$groups = array();
		foreach ( $buckets as $key => $rows ) {
			if ( array() === $rows ) {
				continue;
			}
			$programs = array();
			foreach ( $rows as $row ) {
				$program = self::program_short_label( self::text( $row['program'] ?? '' ) );
				if ( '' !== $program ) {
					$programs[ $program ] = true;
				}
			}
			$groups[] = $labels[ $key ] + array(
				'kicker'  => implode( ' · ', array_keys( $programs ) ),
				'courses' => $rows,
			);
		}
		return $groups;
	}

	/**
	 * Returns the compact brand label for a stored program name — the landing
	 * kickers read 'COPPE · PEE' and 'Poli/UFRJ', not the full unit titles.
	 * Unrecognized programs render verbatim.
	 *
	 * @param string $program Stored program name.
	 */
	private static function program_short_label( string $program ): string {
		if ( '' === $program ) {
			return '';
		}
		if ( false !== stripos( $program, 'engenharia elétrica' ) || false !== stripos( $program, 'electrical engineering' ) ) {
			return 'COPPE · PEE';
		}
		if ( false !== stripos( $program, 'poli' ) || false !== stripos( $program, 'eletrônica' ) || false !== stripos( $program, 'electronic' ) ) {
			return 'Poli/UFRJ';
		}
		return $program;
	}

	/**
	 * Returns rendered name/link strings for one record's teaching team.
	 *
	 * `teaching_team` relationships are looked up through
	 * `Relationships::for_source()` whenever the record exposes a post id and
	 * the content model is loaded; record-carried team entries (name plus an
	 * optional url) fill in otherwise. Callers emit the professor cell only
	 * when the list is non-empty.
	 *
	 * @param array<string, mixed> $record Course or offering record.
	 * @param string               $locale Supported locale slug.
	 * @return array<int, string>
	 */
	private static function teaching_team_names( array $record, string $locale ): array {
		$named = array();
		self::collect_team_names( $record, $locale, $named );
		$current = self::record( $record['current_offering'] ?? null );
		self::collect_team_names( $current, $locale, $named );
		return array_values( $named );
	}

	/**
	 * Adds one record's teaching-team names to the deduplicated map.
	 *
	 * @param array<string, mixed>  $record Record that may carry team data.
	 * @param string                $locale Supported locale slug.
	 * @param array<string, string> $named  Accumulator keyed by display name; a
	 *                                      linked rendering replaces a plain one.
	 */
	private static function collect_team_names( array $record, string $locale, array &$named ): void {
		foreach ( self::record_source_ids( $record ) as $source_id ) {
			if ( ! class_exists( Relationships::class ) || ! function_exists( 'get_post' ) ) {
				break;
			}
			foreach ( Relationships::for_source( $source_id, 'teaching_team' ) as $row ) {
				if ( empty( $row['public_visibility'] ) ) {
					continue;
				}
				$person = self::localized_person( self::num( $row['target_post_id'] ), $locale );
				if ( $person instanceof WP_Post && '' !== $person->post_title ) {
					$named[ $person->post_title ] = self::person_link( $person, $locale );
				}
			}
		}
		foreach ( array( 'team', 'teaching_team', 'professors' ) as $key ) {
			foreach ( self::record( $record[ $key ] ?? null ) as $member ) {
				$member = self::record( $member );
				$name   = self::text( $member['name'] ?? '' );
				if ( '' === $name ) {
					continue;
				}
				$link = self::member_link( $member, $locale );
				if ( ! isset( $named[ $name ] ) || ( false === strpos( $named[ $name ], 'href=' ) && false !== strpos( $link, 'href=' ) ) ) {
					$named[ $name ] = $link;
				}
			}
		}
	}

	/**
	 * Returns the post ids one record exposes for relationship lookups.
	 *
	 * @param array<string, mixed> $record Boundary record.
	 * @return array<int, int>
	 */
	private static function record_source_ids( array $record ): array {
		$ids = array();
		foreach ( array( 'id', 'post_id', 'course_id', 'authority_id' ) as $key ) {
			$id = self::num( $record[ $key ] ?? 0 );
			if ( 0 < $id ) {
				$ids[ $id ] = true;
			}
		}
		return array_keys( $ids );
	}

	/**
	 * Returns the localized person record for one post id, or null.
	 *
	 * @param int    $post_id Person record ID.
	 * @param string $locale  Supported locale slug.
	 */
	private static function localized_person( int $post_id, string $locale ): ?WP_Post {
		$post = 0 < $post_id && function_exists( 'get_post' ) ? get_post( $post_id ) : null;
		if ( ! $post instanceof WP_Post ) {
			return null;
		}
		if ( ! class_exists( Translations::class ) || Translations::locale( $post->ID ) === $locale ) {
			return $post;
		}
		$variants = Translations::variants( $post->ID );
		$variant  = isset( $variants[ $locale ] ) ? get_post( $variants[ $locale ] ) : null;
		return $variant instanceof WP_Post ? $variant : null;
	}

	/**
	 * Renders one person as a profile link when published, else as text.
	 *
	 * A draft or archived person keeps the name but never a live link, the
	 * same gate `DiscoveryRoutes` applies to project members.
	 *
	 * @param WP_Post $person Person record.
	 * @param string  $locale Supported locale slug.
	 */
	private static function person_link( WP_Post $person, string $locale ): string {
		$name     = $person->post_title;
		$archived = 'lps_archived' === $person->post_status
			|| 'archived' === ( function_exists( 'get_post_meta' ) ? self::text( get_post_meta( $person->ID, '_lps_state', true ) ) : '' );
		$url      = '';
		if ( ! $archived && 'publish' === $person->post_status && class_exists( PublicRoutes::class ) ) {
			$url = self::safe_url( PublicRoutes::single_path( 'lps_person', $locale, $person->post_name ) );
		}
		return '' === $url ? self::esc( $name ) : '<a href="' . self::esc( $url ) . '">' . self::esc( $name ) . '</a>';
	}

	/**
	 * Renders one team member: a link when the entry resolves, else text.
	 *
	 * @param array<string, mixed> $member Team member entry.
	 * @param string               $locale Supported locale slug.
	 */
	private static function member_link( array $member, string $locale ): string {
		$name = self::text( $member['name'] ?? '' );
		$url  = self::safe_url( self::text( $member['url'] ?? '' ) );
		if ( '' === $url ) {
			foreach ( array( 'person_id', 'id' ) as $key ) {
				$person = self::localized_person( self::num( $member[ $key ] ?? 0 ), $locale );
				if ( $person instanceof WP_Post ) {
					return self::person_link( $person, $locale );
				}
			}
		}
		return '' === $url ? self::esc( $name ) : '<a href="' . self::esc( $url ) . '">' . self::esc( $name ) . '</a>';
	}

	/**
	 * Returns the showcase level-pill variant class for one level key.
	 *
	 * Only `lps-level-grad` and `lps-level-undergrad` exist in the theme
	 * stylesheet — the canonical showcase mapping (components.mjs) sends
	 * `graduate` to `lps-level-grad` and every other level to
	 * `lps-level-undergrad`.
	 *
	 * @param string $level Stored level key.
	 */
	private static function level_variant( string $level ): string {
		return 'graduate' === $level ? 'lps-level-grad' : 'lps-level-undergrad';
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
	 * Renders governed body markup in the shared reading container.
	 *
	 * @param string $body  Body markup.
	 * @param string $extra Extra container classes, leading space included.
	 */
	private static function body( string $body, string $extra = '' ): string {
		return '' === $body ? '' : '<div class="lps-body lps-reading' . self::esc( $extra ) . '">' . self::rich( $body ) . '</div>';
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
