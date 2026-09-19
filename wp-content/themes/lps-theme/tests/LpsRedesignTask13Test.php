<?php
/**
 * Course directories, offerings, units and material views (task 13).
 *
 * @package LPS\Theme\Tests
 */

declare(strict_types=1);

namespace LPS\Theme\Tests;

use LPS\Theme\TeachingSurfaces;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/includes/class-discoverysurfaces.php';
require_once dirname( __DIR__ ) . '/includes/class-teachingsurfaces.php';

/**
 * Proves the task-13 teaching-surface contracts without WordPress.
 *
 * The canonical first-integrated-example fixture — one faculty profile, one
 * current and one completed offering, a co-teaching case — is assembled here
 * as the same record maps `TeachingRoutes` builds from governed metadata.
 * Record assembly, route resolution and the guarded download boundary are
 * exercised by the e2e spec against the real WordPress runtime.
 */
final class LpsRedesignTask13Test extends TestCase {
	/**
	 * Returns the canonical course record with both offerings.
	 *
	 * @return array{course: array<string, mixed>, offerings: array<int, array<string, mixed>>, current: array<string, mixed>, completed: array<string, mixed>}
	 */
	private static function fixture(): array {
		$current = array(
			'title'           => 'Sinais e Sistemas — Turma T01 (2026.2)',
			'url'             => '/pt-br/ensino/disciplinas/sinais-e-sistemas/2026-2-semester/t01/',
			'section_key'     => 't01',
			'temporal_status' => 'current',
			'term'            => array(
				'token'        => '2026-2-semester',
				'period_label' => '2026.2',
				'starts_on'    => '2026-08-01',
				'ends_on'      => '2026-12-15',
			),
		);
		$completed = array(
			'title'           => 'Sinais e Sistemas — Turma T01 (2025.2)',
			'url'             => '/pt-br/ensino/disciplinas/sinais-e-sistemas/2025-2-semester/t01/',
			'section_key'     => 't01',
			'temporal_status' => 'completed',
			'term'            => array(
				'token'        => '2025-2-semester',
				'period_label' => '2025.2',
				'starts_on'    => '2025-08-01',
				'ends_on'      => '2025-12-15',
			),
		);
		return array(
			'course'    => array(
				'title'         => 'Sinais e Sistemas',
				'url'           => '/pt-br/ensino/disciplinas/sinais-e-sistemas/',
				'code'          => 'EEL315',
				'level'         => 'undergraduate',
				'program'       => 'Engenharia Elétrica',
				'summary'       => 'Análise de sinais contínuos e discretos.',
				'body'          => '<p>Disciplina de fixture.</p>',
				'prerequisites' => 'Cálculo II',
				'syllabus'      => "Sinais e sistemas.\nTransformadas.",
			),
			'offerings' => array( $current, $completed ),
			'current'   => $current,
			'completed' => $completed,
		);
	}

	/**
	 * Returns the canonical offering detail record: co-taught team, ordered
	 * units and a material set covering every render state.
	 *
	 * @return array<string, mixed>
	 */
	private static function offering_fixture(): array {
		$base = self::fixture();
		return array(
			'title'           => 'Sinais e Sistemas — Turma T01 (2026.2)',
			'summary'         => 'Turma do semestre corrente.',
			'body'            => '<p>Conteúdo da turma.</p>',
			'url'             => $base['current']['url'],
			'section_key'     => 't01',
			'schedule'        => 'Ter/Qui 10h-12h',
			'venue'           => 'Sala 201',
			'syllabus'        => "Sinais contínuos.\nSistemas lineares.",
			'lms_url'         => 'https://moodle.example.org/course/view.php?id=315',
			'temporal_status' => 'current',
			'course'          => array(
				'title' => 'Sinais e Sistemas',
				'url'   => '/pt-br/ensino/disciplinas/sinais-e-sistemas/',
				'code'  => 'EEL315',
			),
			'term'            => $base['current']['term'],
			'team'            => array(
				array(
					'name' => 'Adelaide Nogueira',
					'role' => 'lead',
				),
				array(
					'name' => 'Bento Carvalho',
					'role' => 'co-teacher',
				),
			),
			'units'           => array(
				array(
					'title'      => 'Unidade 1 — Sinais contínuos',
					'anchor'     => 'unidade-1',
					'position'   => 1,
					'topic_date' => '2026-08-10',
					'body'       => '<p>Introdução aos sinais.</p>',
				),
				array(
					'title'    => 'Unidade 2 — Sistemas lineares',
					'anchor'   => 'unidade-2',
					'position' => 2,
					'body'     => '',
				),
			),
			'materials'       => array(
				array(
					'title'                => 'Apostila de convolução',
					'summary'              => 'Notas completas da unidade 1.',
					'type'                 => 'document',
					'language'             => 'pt-br',
					'unit_anchor'          => 'unidade-1',
					'effective_state'      => 'released',
					'download_url'         => '/lps-resource/11111111-2222-3333-4444-555555555555/',
					'sha256'               => str_repeat( 'a', 64 ),
					'bytes'                => 1536,
					'mime'                 => 'application/pdf',
					'scan_state'           => 'clean',
					'rights_review'        => 'approved',
					'accessibility_review' => 'approved',
					'updated_at'           => '2026-09-01T12:00:00+00:00',
				),
				array(
					'title'                => 'Repositório de notebooks',
					'type'                 => 'notebook',
					'language'             => 'en',
					'unit_anchor'          => 'unidade-2',
					'effective_state'      => 'released',
					'external_url'         => 'https://example.org/notebooks',
					'updated_at'           => '',
				),
				array(
					'title'                => 'Prova de 2025',
					'type'                 => 'document',
					'language'             => 'pt-br',
					'unit_anchor'          => '',
					'effective_state'      => 'withdrawn',
					'download_url'         => '/lps-resource/99999999-8888-7777-6666-555555555555/',
					'updated_at'           => '2026-09-10T08:00:00+00:00',
				),
				array(
					'title'                => 'Slides agendados',
					'type'                 => 'slides',
					'language'             => 'pt-br',
					'unit_anchor'          => '',
					'effective_state'      => 'scheduled',
					'download_url'         => '/lps-resource/aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee/',
				),
				array(
					'title'                => 'Rascunho interno',
					'type'                 => 'document',
					'language'             => 'pt-br',
					'unit_anchor'          => '',
					'effective_state'      => 'draft',
					'download_url'         => '/lps-resource/dddddddd-eeee-ffff-0000-111111111111/',
				),
			),
			'siblings'        => $base['offerings'],
		);
	}

	public function test_landing_lists_courses_with_metadata_and_current_section(): void {
		$fixture = self::fixture();
		$course  = $fixture['course'] + array( 'current_offering' => $fixture['current'] );
		$html    = TeachingSurfaces::landing( array( $course ), 'pt-br' );

		self::assertStringContainsString( 'lps-teaching-landing', $html );
		self::assertStringContainsString( '<h1>Ensino</h1>', $html );
		self::assertStringContainsString( 'EEL315', $html );
		self::assertStringContainsString( 'Graduação', $html );
		self::assertStringContainsString( 'Engenharia Elétrica', $html );
		self::assertStringContainsString( 'Análise de sinais contínuos e discretos.', $html );
		// The current-offering shortcut links straight into the live section.
		self::assertStringContainsString( 'Turma em andamento', $html );
		self::assertStringContainsString( $fixture['current']['url'], $html );
	}

	public function test_landing_empty_state_is_explicit(): void {
		$html = TeachingSurfaces::landing( array(), 'pt-br' );
		self::assertStringContainsString( 'Nenhuma disciplina publicada ainda.', $html );
		self::assertStringNotContainsString( 'lps-course-list', $html );

		$en = TeachingSurfaces::landing( array(), 'en' );
		self::assertStringContainsString( 'No courses are published yet.', $en );
		self::assertStringContainsString( 'lang="en"', $en );
	}

	public function test_course_groups_offerings_by_temporal_status(): void {
		$fixture = self::fixture();
		$html    = TeachingSurfaces::course( $fixture['course'], $fixture['offerings'], 'pt-br' );

		self::assertStringContainsString( '<h1>Sinais e Sistemas</h1>', $html );
		self::assertStringContainsString( 'EEL315', $html );
		self::assertStringContainsString( 'Pré-requisitos: Cálculo II', $html );
		self::assertStringContainsString( 'Ementa', $html );

		// Current and previous offerings are distinguishable strata.
		self::assertStringContainsString( 'Em andamento', $html );
		self::assertStringContainsString( 'Ofertas anteriores', $html );
		self::assertStringContainsString( 'data-state="current"', $html );
		self::assertStringContainsString( 'data-state="completed"', $html );
		self::assertStringContainsString( '2026.2', $html );
		self::assertStringContainsString( '2025.2', $html );
		// The completed term stays linked, never archived away.
		self::assertStringContainsString( $fixture['completed']['url'], $html );
		self::assertStringContainsString( 'Concluída', $html );
	}

	public function test_course_without_offerings_shows_an_explicit_state(): void {
		$fixture = self::fixture();
		$html    = TeachingSurfaces::course( $fixture['course'], array(), 'pt-br' );
		self::assertStringContainsString( 'Nenhuma turma publicada para esta disciplina ainda.', $html );
		self::assertStringNotContainsString( 'Ofertas anteriores', $html );
		self::assertStringNotContainsString( 'Em andamento', $html );
	}

	public function test_offering_renders_identity_team_syllabus_and_lms(): void {
		$html = TeachingSurfaces::offering( self::offering_fixture(), 'pt-br' );

		// Course context, identity tokens and the localized status label.
		self::assertStringContainsString( 'EEL315 · Sinais e Sistemas', $html );
		self::assertStringContainsString( 'data-state="current"', $html );
		self::assertStringContainsString( '<span class="lps-term-token">2026-2-semester</span>', $html );
		self::assertStringContainsString( '<span class="lps-section">t01</span>', $html );
		self::assertStringContainsString( '<span class="lps-temporal-status">Em andamento</span>', $html );
		self::assertStringContainsString( '2026.2', $html );
		self::assertStringContainsString( '<time datetime="2026-08-01">', $html );
		self::assertStringContainsString( '<time datetime="2026-12-15">', $html );

		// Schedule and venue context.
		self::assertStringContainsString( 'Ter/Qui 10h-12h', $html );
		self::assertStringContainsString( 'Sala 201', $html );

		// The co-teaching case: both members render with localized roles.
		self::assertStringContainsString( 'Adelaide Nogueira', $html );
		self::assertStringContainsString( 'responsável', $html );
		self::assertStringContainsString( 'Bento Carvalho', $html );
		self::assertStringContainsString( 'co-docente', $html );

		// Syllabus snapshot and the labeled LMS handoff — never a raw URL.
		self::assertStringContainsString( 'Ementa', $html );
		self::assertStringContainsString( 'Sinais contínuos.', $html );
		self::assertStringContainsString( 'Ambiente virtual (link externo)', $html );
		self::assertStringContainsString( 'rel="noopener noreferrer"', $html );
		self::assertStringNotContainsString( '>https://moodle.example.org', $html );
	}

	public function test_offering_units_keep_stable_anchors_and_toc(): void {
		$html = TeachingSurfaces::offering( self::offering_fixture(), 'pt-br' );

		// Unit anchors are the stored keys, independent of ordering.
		self::assertStringContainsString( 'id="unidade-1"', $html );
		self::assertStringContainsString( 'id="unidade-2"', $html );
		// The contents strip links every anchor plus the materials section.
		self::assertStringContainsString( 'href="#unidade-1"', $html );
		self::assertStringContainsString( 'href="#unidade-2"', $html );
		self::assertStringContainsString( 'href="#materiais"', $html );
		self::assertStringContainsString( 'id="materiais"', $html );
		self::assertStringContainsString( 'aria-label="Unidades e materiais"', $html );
		// Topic dates render as real time elements.
		self::assertStringContainsString( '<time datetime="2026-08-10">', $html );
	}

	public function test_offering_materials_render_descriptive_rows(): void {
		$html = TeachingSurfaces::offering( self::offering_fixture(), 'pt-br' );

		// The released download row: linked title, type, language, size,
		// checksum and update date — no filename guessing.
		self::assertStringContainsString( '<a href="/lps-resource/11111111-2222-3333-4444-555555555555/">Apostila de convolução</a>', $html );
		self::assertStringContainsString( 'documento', $html );
		self::assertStringContainsString( 'Português', $html );
		self::assertStringContainsString( '1,5 KB', $html );
		self::assertStringContainsString( 'application/pdf', $html );
		self::assertStringContainsString( 'SHA-256 <code class="lps-breakable">' . str_repeat( 'a', 64 ) . '</code>', $html );
		self::assertStringContainsString( 'Atualizado em 1 de setembro de 2026', $html );
		self::assertStringContainsString( 'Notas completas da unidade 1.', $html );

		// The external resource is identified as external.
		self::assertStringContainsString( 'href="https://example.org/notebooks"', $html );
		self::assertStringContainsString( 'externo', $html );

		// The withdrawn row shows its notice and never a link.
		self::assertStringContainsString( 'Material retirado.', $html );
		self::assertStringContainsString( 'Retirado em 10 de setembro de 2026', $html );
		self::assertStringNotContainsString( '99999999-8888-7777-6666-555555555555', $html );

		// Scheduled and draft materials never reach the markup.
		self::assertStringNotContainsString( 'Slides agendados', $html );
		self::assertStringNotContainsString( 'Rascunho interno', $html );
		self::assertStringNotContainsString( 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee', $html );
		self::assertStringNotContainsString( 'dddddddd-eeee-ffff-0000-111111111111', $html );

		// Private fields never leak.
		self::assertStringNotContainsString( 'lps-file-', $html );
		self::assertStringNotContainsString( 'lpsver:', $html );
		self::assertStringNotContainsString( '_lps_', $html );
	}

	public function test_offering_materials_group_under_their_unit_anchors(): void {
		$html = TeachingSurfaces::offering( self::offering_fixture(), 'pt-br' );

		// The unit-1 material renders inside the unit-1 list item.
		$unit_one = strpos( $html, 'id="unidade-1"' );
		$unit_two = strpos( $html, 'id="unidade-2"' );
		$apostila = strpos( $html, 'Apostila de convolução' );
		$notebook = strpos( $html, 'Repositório de notebooks' );
		$materials_section = strpos( $html, 'id="materiais"' );
		self::assertIsInt( $unit_one );
		self::assertIsInt( $unit_two );
		self::assertIsInt( $apostila );
		self::assertIsInt( $notebook );
		self::assertIsInt( $materials_section );
		self::assertGreaterThan( $unit_one, $apostila );
		self::assertLessThan( $unit_two, $apostila );
		self::assertGreaterThan( $unit_two, $notebook );
		self::assertLessThan( $materials_section, $notebook );
		// The withdrawn ungrouped material lands in the materials section.
		$withdrawn = strpos( $html, 'Prova de 2025' );
		self::assertIsInt( $withdrawn );
		self::assertGreaterThan( $materials_section, $withdrawn );
	}

	public function test_offering_without_materials_shows_the_explicit_notice(): void {
		$fixture             = self::offering_fixture();
		$fixture['materials'] = array();
		$html                = TeachingSurfaces::offering( $fixture, 'pt-br' );
		self::assertStringContainsString( 'Materiais ainda não publicados.', $html );
		self::assertStringContainsString( 'id="materiais"', $html );
	}

	public function test_offering_siblings_render_the_term_switch_navigation(): void {
		$html = TeachingSurfaces::offering( self::offering_fixture(), 'pt-br' );
		self::assertStringContainsString( 'aria-label="Outras ofertas desta disciplina"', $html );
		self::assertStringContainsString( 'Outras ofertas', $html );
		self::assertStringContainsString( '/pt-br/ensino/disciplinas/sinais-e-sistemas/2025-2-semester/t01/', $html );
		self::assertStringContainsString( 'data-state="completed"', $html );
	}

	public function test_offering_english_labels_and_language_tag(): void {
		$html = TeachingSurfaces::offering( self::offering_fixture(), 'en' );
		self::assertStringContainsString( 'lang="en"', $html );
		self::assertStringContainsString( 'In progress', $html );
		self::assertStringContainsString( 'Teaching team', $html );
		self::assertStringContainsString( 'co-teacher', $html );
		self::assertStringContainsString( 'Virtual classroom (external link)', $html );
		self::assertStringContainsString( 'Withdrawn material.', $html );
		self::assertStringContainsString( 'English', $html );
	}

	public function test_material_state_evaluation_matches_the_delivery_contract(): void {
		$released_download = array(
			'effective_state'      => 'released',
			'download_url'         => '/lps-resource/x/',
			'scan_state'           => 'clean',
			'rights_review'        => 'approved',
			'accessibility_review' => 'approved',
		);
		self::assertSame( 'download', TeachingSurfaces::material_state( $released_download ) );
		self::assertSame( 'external', TeachingSurfaces::material_state( array( 'effective_state' => 'released', 'external_url' => 'https://x.example' ) ) );
		self::assertSame( 'scan-pending', TeachingSurfaces::material_state( array_merge( $released_download, array( 'scan_state' => 'pending' ) ) ) );
		self::assertSame( 'unavailable', TeachingSurfaces::material_state( array_merge( $released_download, array( 'rights_review' => 'pending' ) ) ) );
		self::assertSame( 'unavailable', TeachingSurfaces::material_state( array( 'effective_state' => 'released' ) ) );
		self::assertSame( 'withdrawn', TeachingSurfaces::material_state( array( 'effective_state' => 'withdrawn' ) ) );
		self::assertSame( 'hidden', TeachingSurfaces::material_state( array( 'effective_state' => 'scheduled' ) ) );
		self::assertSame( 'hidden', TeachingSurfaces::material_state( array( 'effective_state' => 'draft' ) ) );
		self::assertSame( 'hidden', TeachingSurfaces::material_state( array() ) );
	}

	public function test_scan_pending_and_unavailable_rows_show_state_text_without_links(): void {
		$fixture = self::offering_fixture();
		$fixture['materials'] = array(
			array(
				'title'                => 'Arquivo em verificação',
				'type'                 => 'document',
				'language'             => 'pt-br',
				'unit_anchor'          => '',
				'effective_state'      => 'released',
				'download_url'         => '/lps-resource/scanpending-0000-0000-0000-000000000000/',
				'scan_state'           => 'pending',
				'rights_review'        => 'approved',
				'accessibility_review' => 'approved',
			),
			array(
				'title'                => 'Arquivo sem revisão',
				'type'                 => 'document',
				'language'             => 'pt-br',
				'unit_anchor'          => '',
				'effective_state'      => 'released',
				'download_url'         => '/lps-resource/unreviewed-0000-0000-0000-000000000000/',
				'scan_state'           => 'clean',
				'rights_review'        => 'pending',
				'accessibility_review' => 'approved',
			),
		);
		$html = TeachingSurfaces::offering( $fixture, 'pt-br' );
		self::assertStringContainsString( 'Verificação de segurança pendente', $html );
		self::assertStringContainsString( 'temporariamente indisponível', $html );
		self::assertStringNotContainsString( 'scanpending-0000', $html );
		self::assertStringNotContainsString( 'unreviewed-0000', $html );
	}

	public function test_untrusted_markup_is_escaped_everywhere(): void {
		$fixture = self::offering_fixture();
		$fixture['title']               = 'Turma <script>alert(1)</script>';
		$fixture['materials'][0]['title']   = 'Apostila <img src=x onerror=alert(1)>';
		$fixture['materials'][0]['summary'] = 'Resumo <b>seguro</b>';
		$fixture['team'][0]['name']         = 'Docente <em>nome</em>';
		$html = TeachingSurfaces::offering( $fixture, 'pt-br' );
		self::assertStringNotContainsString( '<script>', $html );
		self::assertStringNotContainsString( '<img', $html );
		self::assertStringContainsString( 'Turma &lt;script&gt;', $html );
		self::assertStringContainsString( 'Apostila &lt;img', $html );
		self::assertStringContainsString( 'Resumo &lt;b&gt;seguro&lt;/b&gt;', $html );
		self::assertStringContainsString( 'Docente &lt;em&gt;nome&lt;/em&gt;', $html );
	}

	public function test_temporal_labels_cover_every_status_in_both_locales(): void {
		self::assertSame( 'Em andamento', TeachingSurfaces::temporal_label( 'current', 'pt-br' ) );
		self::assertSame( 'Próxima', TeachingSurfaces::temporal_label( 'upcoming', 'pt-br' ) );
		self::assertSame( 'Concluída', TeachingSurfaces::temporal_label( 'completed', 'pt-br' ) );
		self::assertSame( 'Cancelada', TeachingSurfaces::temporal_label( 'cancelled', 'pt-br' ) );
		self::assertSame( 'In progress', TeachingSurfaces::temporal_label( 'current', 'en' ) );
		self::assertSame( 'Completed', TeachingSurfaces::temporal_label( 'completed', 'en' ) );
		// Unknown keys pass through so a new status never renders blank.
		self::assertSame( 'future-key', TeachingSurfaces::temporal_label( 'future-key', 'en' ) );
	}
}
