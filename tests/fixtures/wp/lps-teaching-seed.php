<?php
/**
 * Local-only teaching fixtures seeded through the canonical service boundary.
 *
 * Every record is created by `TeachingRecords` — the same boundary the REST
 * adapter exposes — so registry claims, relationships, temporal status, and
 * the bilingual publish contract all hold exactly as they do for authored
 * records. The seed is idempotent: existing fixture slugs are reused and the
 * version option only advances when the full fixture set resolves.
 *
 * @package LPS\Development
 */

declare(strict_types=1);

use LPS\ContentModel\TeachingRecords;
use LPS\ContentModel\Translations;

const LPS_TEACHING_SEED_VERSION = '1';
const LPS_TEACHING_SEED_OPTION  = 'lps_teaching_seed_version';

/**
 * Returns the published record for a fixture slug, or zero.
 *
 * @param string $post_type Record type.
 * @param string $slug      Fixture slug.
 */
function lps_teaching_seed_id( string $post_type, string $slug ): int {
	$post = get_page_by_path( $slug, OBJECT, $post_type );
	return $post instanceof WP_Post ? (int) $post->ID : 0;
}

/**
 * Creates or reuses one record through the canonical boundary.
 *
 * @param string               $post_type Record type.
 * @param string               $slug      Fixture slug.
 * @param array<string, mixed> $input     Boundary input.
 * @param callable             $creator   Service create method.
 */
function lps_teaching_seed_record( string $post_type, string $slug, array $input, callable $creator ): int {
	$existing = lps_teaching_seed_id( $post_type, $slug );
	if ( 0 < $existing ) {
		return $existing;
	}
	$result = $creator( $input );
	return is_wp_error( $result ) ? 0 : (int) ( $result['id'] ?? 0 );
}

/**
 * Publishes one record when it is not already published.
 *
 * @param int $post_id Record database ID.
 */
function lps_teaching_seed_publish( int $post_id ): void {
	if ( 0 < $post_id && 'publish' !== get_post_status( $post_id ) ) {
		TeachingRecords::publish_record( $post_id );
	}
}

/**
 * Publishes one bilingual pair: review the English variant against the
 * current source, publish English first, then the Portuguese authority.
 *
 * @param int $portuguese_id Authority record ID.
 * @param int $english_id    English variant ID.
 */
function lps_teaching_seed_publish_pair( int $portuguese_id, int $english_id ): void {
	if ( 0 === $portuguese_id || 0 === $english_id ) {
		return;
	}
	// The seed runs outside an authenticated request, so the review receipt is
	// written directly — the same value `Translations::review()` stores.
	$source_hash = get_post_meta( $english_id, '_lps_source_hash', true );
	update_post_meta( $english_id, '_lps_reviewed_source_hash', is_string( $source_hash ) ? $source_hash : '' );
	lps_teaching_seed_publish( $english_id );
	lps_teaching_seed_publish( $portuguese_id );
}

/** Returns whether both supported languages are registered and usable. */
function lps_teaching_seed_languages_ready(): bool {
	if ( ! function_exists( 'pll_languages_list' ) || ! function_exists( 'pll_save_post_translations' ) ) {
		return false;
	}
	$languages = pll_languages_list();
	if ( ! is_array( $languages ) ) {
		return false;
	}
	return in_array( 'pt-br', $languages, true ) && in_array( 'en', $languages, true );
}

/** Seeds the teaching fixtures exactly once per environment. */
function lps_teaching_seed(): void {
	if ( ! class_exists( TeachingRecords::class ) ) {
		return;
	}

	// A bilingual published person anchors the teaching team.
	$person_pt = lps_teaching_seed_id( 'lps_person', 'docente-sinais-fixture' );
	$person_en = lps_teaching_seed_id( 'lps_person', 'signals-faculty-fixture' );
	if ( 0 === $person_pt ) {
		$person_pt = wp_insert_post(
			array(
				'post_type'    => 'lps_person',
				'post_status'  => 'draft',
				'post_title'   => 'Docente de Sinais (fixture de QA)',
				'post_name'    => 'docente-sinais-fixture',
				'post_excerpt' => 'Perfil de teste local para a equipe de ensino.',
				'post_content' => 'Fixture local. Não representa uma pessoa real.',
				'meta_input'   => array(
					'_lps_locale'         => 'pt-br',
					'_lps_canonical_name' => 'Docente de Sinais (fixture de QA)',
					'_lps_person_status'  => 'active',
				),
			),
			true
		);
		$person_pt = is_wp_error( $person_pt ) ? 0 : (int) $person_pt;
	}
	if ( 0 === $person_en && 0 < $person_pt ) {
		$person_en = wp_insert_post(
			array(
				'post_type'    => 'lps_person',
				'post_status'  => 'draft',
				'post_title'   => 'Signals Faculty (QA fixture)',
				'post_name'    => 'signals-faculty-fixture',
				'post_excerpt' => 'Local test profile for the teaching team.',
				'post_content' => 'Local fixture. It does not represent a real person.',
				'meta_input'   => array( '_lps_locale' => 'en' ),
			),
			true
		);
		$person_en = is_wp_error( $person_en ) ? 0 : (int) $person_en;
		if ( 0 < $person_en && is_wp_error( Translations::associate( $person_pt, $person_en ) ) ) {
			wp_delete_post( $person_en, true );
			$person_en = 0;
		}
	}
	lps_teaching_seed_publish_pair( $person_pt, $person_en );

	// Terms: one current semester, one completed semester.
	$term_current = lps_teaching_seed_record(
		'lps_term',
		'2026-2-semester',
		array(
			'title'        => '2026.2',
			'slug'         => '2026-2-semester',
			'calendar_key' => 'semester',
			'term_code'    => '2026-2',
			'meta'         => array(
				'_lps_period_label' => '2026.2',
				'_lps_period_type'  => 'semester',
				'_lps_starts_on'    => '2026-08-01',
				'_lps_ends_on'      => '2026-12-15',
			),
		),
		array( TeachingRecords::class, 'create_term' )
	);
	lps_teaching_seed_publish( $term_current );
	$term_completed = lps_teaching_seed_record(
		'lps_term',
		'2025-2-semester',
		array(
			'title'        => '2025.2',
			'slug'         => '2025-2-semester',
			'calendar_key' => 'semester',
			'term_code'    => '2025-2',
			'meta'         => array(
				'_lps_period_label' => '2025.2',
				'_lps_period_type'  => 'semester',
				'_lps_starts_on'    => '2025-08-01',
				'_lps_ends_on'      => '2025-12-15',
			),
		),
		array( TeachingRecords::class, 'create_term' )
	);
	lps_teaching_seed_publish( $term_completed );

	// Bilingual published course.
	$course_pt = lps_teaching_seed_record(
		'lps_course',
		'sinais-e-sistemas-fixture',
		array(
			'title'   => 'Sinais e Sistemas (fixture de QA)',
			'slug'    => 'sinais-e-sistemas-fixture',
			'excerpt' => 'Disciplina de teste local para as rotas de ensino.',
			'content' => 'Fixture local. Não representa uma ementa institucional.',
			'meta'    => array(
				'_lps_course_code'  => 'LPS-101',
				'_lps_course_level' => 'undergraduate',
				'_lps_calendar_key' => 'semester',
			),
		),
		array( TeachingRecords::class, 'create_course' )
	);
	$course_en = lps_teaching_seed_id( 'lps_course', 'signals-and-systems-fixture' );
	if ( 0 === $course_en && 0 < $course_pt ) {
		$variant = TeachingRecords::create_course(
			array(
				'title'          => 'Signals and Systems (QA fixture)',
				'slug'           => 'signals-and-systems-fixture',
				'locale'         => 'en',
				'translation_of' => $course_pt,
				'excerpt'        => 'Local test course for the teaching routes.',
				'content'        => 'Local fixture. It states no institutional syllabus.',
			)
		);
		$course_en = is_wp_error( $variant ) ? 0 : (int) ( $variant['id'] ?? 0 );
	}
	lps_teaching_seed_publish_pair( $course_pt, $course_en );

	if ( 0 === $course_pt || 0 === $term_current || 0 === $term_completed || 0 === $person_pt ) {
		return;
	}

	// Offerings: one current section and one completed section.
	$team = array(
		array(
			'person_id' => $person_pt,
			'role'      => 'lead',
		),
	);
	$offering_current = lps_teaching_seed_record(
		'lps_offering',
		'sinais-e-sistemas-2026-2-t01',
		array(
			'title'     => 'Sinais e Sistemas — Turma T01 (2026.2)',
			'slug'      => 'sinais-e-sistemas-2026-2-t01',
			'excerpt'   => 'Turma de teste local do semestre corrente.',
			'content'   => 'Fixture local. Não representa uma turma real.',
			'course_id' => $course_pt,
			'term_id'   => $term_current,
			'section'   => 't01',
			'team'      => $team,
			'meta'      => array(
				'_lps_schedule' => 'Ter/Qui 10h-12h',
				'_lps_venue'    => 'Sala 201',
			),
		),
		array( TeachingRecords::class, 'create_offering' )
	);
	$offering_current_en = 0;
	if ( 0 < $offering_current ) {
		$variants            = Translations::variants( $offering_current );
		$offering_current_en = (int) ( $variants['en'] ?? 0 );
		if ( 0 === $offering_current_en ) {
			$variant = TeachingRecords::create_offering(
				array(
					'title'          => 'Signals and Systems — Section T01 (2026.2)',
					'slug'           => 'signals-and-systems-2026-2-t01',
					'locale'         => 'en',
					'translation_of' => $offering_current,
					'excerpt'        => 'Local test section for the current term.',
					'content'        => 'Local fixture. It does not represent a real section.',
				)
			);
			$offering_current_en = is_wp_error( $variant ) ? 0 : (int) ( $variant['id'] ?? 0 );
		}
	}
	lps_teaching_seed_publish_pair( $offering_current, $offering_current_en );

	$offering_completed = lps_teaching_seed_record(
		'lps_offering',
		'sinais-e-sistemas-2025-2-t01',
		array(
			'title'     => 'Sinais e Sistemas — Turma T01 (2025.2)',
			'slug'      => 'sinais-e-sistemas-2025-2-t01',
			'excerpt'   => 'Turma de teste local do semestre concluído.',
			'content'   => 'Fixture local. Não representa uma turma real.',
			'course_id' => $course_pt,
			'term_id'   => $term_completed,
			'section'   => 't01',
			'team'      => $team,
		),
		array( TeachingRecords::class, 'create_offering' )
	);
	$offering_completed_en = 0;
	if ( 0 < $offering_completed ) {
		$variants              = Translations::variants( $offering_completed );
		$offering_completed_en = (int) ( $variants['en'] ?? 0 );
		if ( 0 === $offering_completed_en ) {
			$variant = TeachingRecords::create_offering(
				array(
					'title'          => 'Signals and Systems — Section T01 (2025.2)',
					'slug'           => 'signals-and-systems-2025-2-t01',
					'locale'         => 'en',
					'translation_of' => $offering_completed,
					'excerpt'        => 'Local test section for the completed term.',
					'content'        => 'Local fixture. It does not represent a real section.',
				)
			);
			$offering_completed_en = is_wp_error( $variant ) ? 0 : (int) ( $variant['id'] ?? 0 );
		}
	}
	lps_teaching_seed_publish_pair( $offering_completed, $offering_completed_en );

	// Two ordered units on the current offering.
	if ( 0 < $offering_current ) {
		foreach ( array(
			array(
				'title'    => 'Unidade 1 — Sinais contínuos',
				'slug'     => 'unidade-1-sinais-continuos',
				'anchor'   => 'unidade-1',
				'position' => 1,
			),
			array(
				'title'    => 'Unidade 2 — Sistemas lineares',
				'slug'     => 'unidade-2-sistemas-lineares',
				'anchor'   => 'unidade-2',
				'position' => 2,
			),
		) as $unit ) {
			$unit_id = lps_teaching_seed_record(
				'lps_unit',
				$unit['slug'],
				array(
					'title'       => $unit['title'],
					'slug'        => $unit['slug'],
					'offering_id' => $offering_current,
					'meta'        => array(
						'_lps_anchor'   => $unit['anchor'],
						'_lps_position' => $unit['position'],
					),
				),
				array( TeachingRecords::class, 'create_unit' )
			);
			lps_teaching_seed_publish( $unit_id );
		}
	}
}

add_action(
	'init',
	static function (): void {
		if ( LPS_TEACHING_SEED_VERSION === get_option( LPS_TEACHING_SEED_OPTION ) ) {
			return;
		}
		if ( ! lps_teaching_seed_languages_ready() ) {
			// Polylang registers its languages during this same boot; seeding before
			// the association back end is usable would leave unlinked drafts.
			return;
		}
		lps_teaching_seed();
		// The canonical teaching rewrite rules ship with this fixture set; the
		// shared database may predate them, so the version bump regenerates the
		// cached rule set exactly once per seed version.
		flush_rewrite_rules();
		update_option( LPS_TEACHING_SEED_OPTION, LPS_TEACHING_SEED_VERSION );
	},
	45
);
