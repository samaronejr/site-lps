<?php
/**
 * Delegate-prepared draft surfaces on the faculty dashboard.
 *
 * A delegate drafts records inside a shared scope; the professor's workspace
 * and news lane show them with a prepared-by badge while the lifecycle chips
 * stay unchanged. These tests pin the badge contract — bilingual label,
 * delegate tone, delegate id in the data attribute — and the resubmit gate
 * that only offers the edit-and-resubmit form to accounts that may adopt
 * the draft.
 *
 * @package LPS\Theme\Tests
 */

declare(strict_types=1);

namespace LPS\Theme\Tests;

use LPS\Theme\DashboardSurfaces;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/includes/class-dashboardroutes.php';

/**
 * Proves the delegate-prepared badge and adoption affordances without WordPress.
 */
final class DelegateDraftSurfacesTest extends TestCase {
	/**
	 * One offering workspace row with a delegate-prepared unit and material.
	 *
	 * @return array<string, mixed>
	 */
	private static function offering_model(): array {
		return array(
			'offerings' => array(
				array(
					'id'          => 42,
					'title'       => 'Sinais e Sistemas — T01',
					'status'      => 'publish',
					'state'       => 'public',
					'identity'    => array(
						'course_title' => 'Sinais e Sistemas',
						'term_label'   => '2026.2',
						'section_key'  => 't01',
					),
					'team'        => array(),
					'units'       => array(
						array(
							'id'               => 501,
							'title'            => 'Unidade preparada',
							'status'           => 'draft',
							'state'            => 'draft',
							'anchor'           => 'unidade-1',
							'position'         => 1,
							'prepared_by'      => 77,
							'prepared_by_name' => 'Ana Delegada',
							'edit_url'         => '/wp-admin/post.php?post=501&action=edit',
						),
						array(
							'id'       => 502,
							'title'    => 'Unidade própria',
							'status'   => 'publish',
							'state'    => 'public',
							'anchor'   => 'unidade-2',
							'position' => 2,
						),
					),
					'resources'   => array(
						array(
							'id'                => 601,
							'title'             => 'Apostila preparada',
							'status'            => 'draft',
							'state'             => 'draft',
							'resource_type'     => 'notes',
							'resource_language' => 'pt-br',
							'prepared_by'       => 77,
							'prepared_by_name'  => 'Ana Delegada',
							'edit_url'          => '/wp-admin/post.php?post=601&action=edit',
						),
					),
					'can_edit'    => true,
					'can_publish' => true,
					'can_copy'    => false,
					'role'        => 'professor',
				),
			),
		);
	}

	/**
	 * One news lane with a delegate-prepared draft the viewer may adopt.
	 *
	 * @return array<string, mixed>
	 */
	private static function news_model(): array {
		return array(
			'news_scope' => true,
			'news'       => array(
				array(
					'id'               => 701,
					'title'            => 'Notícia preparada',
					'status'           => 'draft',
					'state'            => 'draft',
					'date'             => '2026-03-01T10:00:00+00:00',
					'summary'          => 'Resumo',
					'content'          => 'Conteúdo',
					'note'             => '',
					'prepared_by'      => 77,
					'prepared_by_name' => 'Ana Delegada',
					'can_resubmit'     => true,
					'public_url'       => '',
					'edit_url'         => '/wp-admin/post.php?post=701&action=edit',
				),
				array(
					'id'           => 702,
					'title'        => 'Notícia de outra pessoa',
					'status'       => 'draft',
					'state'        => 'draft',
					'can_resubmit' => false,
				),
			),
		);
	}

	/** The workspace lists delegate drafts with the badge in both locales. */
	public function test_offering_workspace_marks_prepared_units_and_materials(): void {
		$pt = DashboardSurfaces::offering_view( self::offering_model(), 'pt-br', 42 );
		self::assertStringContainsString( 'lps-status-delegate', $pt );
		self::assertStringContainsString( 'data-prepared-by="77"', $pt );
		self::assertStringContainsString( 'Preparado por: Ana Delegada', $pt );
		self::assertSame( 2, substr_count( $pt, 'lps-status-delegate' ), 'unit and material both carry the badge' );
		// The professor's edit affordance reaches the delegate draft rows.
		self::assertStringContainsString( '/wp-admin/post.php?post=501', $pt );
		self::assertStringContainsString( '>Editar<', $pt );
		// The publish affordance stays on the draft for the adopting professor.
		self::assertStringContainsString( 'data-action="publish-unit"', $pt );

		$en = DashboardSurfaces::offering_view( self::offering_model(), 'en', 42 );
		self::assertStringContainsString( 'Prepared by: Ana Delegada', $en );
		self::assertStringNotContainsString( 'Preparado por', $en );
	}

	/** The news lane badges prepared items and offers adoption resubmits. */
	public function test_news_lane_badges_prepared_items_and_gates_resubmit(): void {
		$html = DashboardSurfaces::news_view( self::news_model(), 'pt-br' );
		self::assertStringContainsString( 'lps-status-delegate', $html );
		self::assertStringContainsString( 'Preparado por: Ana Delegada', $html );
		// The adoptable prepared draft reopens for edit; the foreign draft does not.
		self::assertSame( 1, substr_count( $html, 'lps-dashboard-edit' ), 'only the adoptable draft offers resubmit' );
	}

	/** The home lane carries the same badge on the shared news list. */
	public function test_home_lane_marks_prepared_news_items(): void {
		$model          = self::news_model();
		$model['tasks'] = array( 'profile', 'offerings', 'news' );
		$html           = DashboardSurfaces::home_view( $model, 'en' );
		self::assertStringContainsString( 'lps-status-delegate', $html );
		self::assertStringContainsString( 'Prepared by: Ana Delegada', $html );
	}

	/**
	 * A fresh course form offers two member rows: a co-teacher submitting for
	 * someone else's lead needs one row for themselves plus one for the lead.
	 */
	public function test_course_form_renders_room_for_co_teacher_team(): void {
		$model = array(
			'may_course' => true,
			'courses'    => array(),
			'terms'      => array(
				array(
					'id'    => 55,
					'label' => '2026.1',
				),
			),
			'people'     => array(
				array(
					'id'    => 53,
					'title' => 'Professora Teste',
				),
				array(
					'id'    => 54,
					'title' => 'Ana Delegada',
				),
			),
		);
		$html  = DashboardSurfaces::course_view( $model, 'pt-br' );
		self::assertSame( 2, substr_count( $html, 'lps-team-row' ), 'fresh course form needs a row for the submitter and one for the lead' );
	}
}
