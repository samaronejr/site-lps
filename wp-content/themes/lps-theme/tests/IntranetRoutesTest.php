<?php
/**
 * Access-contract and shortcut-bar tests for the members-only intranet.
 *
 * @package LPS\Theme\Tests
 */

declare(strict_types=1);

namespace LPS\Theme\Tests;

require_once __DIR__ . '/stubs-wp-classes.php';
require_once __DIR__ . '/stubs-intranet.php';
require_once dirname( __DIR__ ) . '/includes/class-intranetroutes.php';

use LPS\Theme\IntranetRoutes;
use LPS\Theme\IntranetSurfaces;
use PHPUnit\Framework\Attributes\DataProvider;
use WP_Post;
use WP_User;

/** Gate and shortcut contract tests for the intranet. */
final class IntranetRoutesTest extends \PHPUnit\Framework\TestCase {
	protected function tearDown(): void {
		unset( $GLOBALS['lps_test_post_meta'], $GLOBALS['lps_test_user_meta'], $GLOBALS['lps_test_caps'], $GLOBALS['lps_test_posts'] );
	}

	private static function section( int $id, string $access, int $project = 0 ): WP_Post {
		$post             = new WP_Post();
		$post->ID         = $id;
		$post->post_name  = 'section-' . $id;
		$post->post_title = 'Section ' . $id;
		$GLOBALS['lps_test_post_meta'][ $id ]['_lps_intranet_access']  = $access;
		$GLOBALS['lps_test_post_meta'][ $id ]['_lps_intranet_project'] = $project;
		return $post;
	}

	/**
	 * @param array<int, string> $roles
	 * @param array<int, string> $caps
	 * @param array<int, int>    $projects
	 */
	private static function user( int $id, array $roles, array $caps = array(), array $projects = array() ): WP_User {
		$user                            = new WP_User();
		$user->ID                        = $id;
		$user->roles                     = $roles;
		$GLOBALS['lps_test_caps'][ $id ] = $caps;
		$GLOBALS['lps_test_user_meta'][ $id ]['_lps_intranet_projects'] = $projects;
		return $user;
	}

	public static function access_levels(): array {
		return array(
			'members'       => array( 'members', 'members' ),
			'project'       => array( 'project', 'project' ),
			'faculty'       => array( 'faculty', 'faculty' ),
			'unknown level' => array( 'bogus', 'members' ),
			'empty level'   => array( '', 'members' ),
		);
	}

	/**
	 * @param string $stored   Stored `_lps_intranet_access` value.
	 * @param string $expected Resolved level.
	 */
	#[DataProvider( 'access_levels' )]
	public function test_section_access_whitelists_known_levels( string $stored, string $expected ): void {
		$access = IntranetRoutes::section_access( self::section( 10, $stored ) );
		self::assertSame( $expected, $access['level'] );
	}

	public function test_administrator_opens_every_level(): void {
		$admin = self::user( 1, array( 'administrator' ), array( 'manage_options' ) );
		foreach ( array( 'members', 'faculty', 'project' ) as $level ) {
			self::assertTrue( IntranetRoutes::user_can_open( $admin, self::section( 20, $level, 7 ) ) );
		}
	}

	public function test_member_opens_members_but_not_faculty(): void {
		$member = self::user( 2, array( 'subscriber' ) );
		self::assertTrue( IntranetRoutes::user_can_open( $member, self::section( 30, 'members' ) ) );
		self::assertFalse( IntranetRoutes::user_can_open( $member, self::section( 31, 'faculty' ) ) );
	}

	public static function faculty_roles(): array {
		return array(
			'professor'          => array( array( 'lps_professor' ) ),
			'lps administrator'  => array( array( 'lps_administrator' ) ),
			'core administrator' => array( array( 'administrator' ) ),
			'professor + editor' => array( array( 'lps_professor', 'editor' ) ),
		);
	}

	/** @param array<int, string> $roles */
	#[DataProvider( 'faculty_roles' )]
	public function test_faculty_roles_open_faculty_sections( array $roles ): void {
		$user = self::user( 3, $roles );
		self::assertTrue( IntranetRoutes::user_can_open( $user, self::section( 40, 'faculty' ) ) );
	}

	public static function non_faculty_roles(): array {
		return array(
			'subscriber' => array( array( 'subscriber' ) ),
			'delegate'   => array( array( 'lps_delegate' ) ),
			'editor'     => array( array( 'editor' ) ),
			'none'       => array( array() ),
		);
	}

	/** @param array<int, string> $roles */
	#[DataProvider( 'non_faculty_roles' )]
	public function test_non_faculty_roles_are_locked_out_of_faculty_sections( array $roles ): void {
		$user = self::user( 4, $roles );
		self::assertFalse( IntranetRoutes::user_can_open( $user, self::section( 50, 'faculty' ) ) );
	}

	public function test_project_sections_still_require_the_grant(): void {
		$member = self::user( 5, array( 'subscriber' ), array(), array( 7 ) );
		self::assertTrue( IntranetRoutes::user_can_open( $member, self::section( 60, 'project', 7 ) ) );
		self::assertFalse( IntranetRoutes::user_can_open( $member, self::section( 61, 'project', 9 ) ) );
	}

	public function test_shortcut_bar_renders_groups_and_drops_bad_links(): void {
		$post = self::section( 70, 'faculty' );
		$GLOBALS['lps_test_post_meta'][70]['_lps_intranet_links'] = json_encode(
			array(
				array(
					'label' => 'Queues',
					'items' => array(
						array(
							'label' => 'GPU',
							'url'   => 'https://cluster.example/gpu',
						),
						array(
							'label' => 'CPU',
							'url'   => 'https://cluster.example/cpu',
						),
						array(
							'label' => 'Bad',
							'url'   => 'javascript:alert(1)',
						),
					),
				),
				array(
					'label' => array(
						'pt-br' => 'Serviços',
						'en'    => 'Services',
					),
					'items' => array(
						array(
							'label' => 'Storage',
							'url'   => 'https://storage.example',
						),
					),
				),
			)
		);
		$user = self::user( 6, array( 'lps_professor' ) );
		$html = IntranetSurfaces::view(
			array(
				'view'   => 'section',
				'locale' => 'pt-br',
				'slug'   => 'cluster',
				'post'   => $post,
			),
			$user,
			'pt-br'
		);
		self::assertStringContainsString( 'lps-shortcut-bar', $html );
		self::assertStringContainsString( 'Queues', $html );
		self::assertStringContainsString( 'Serviços', $html );
		self::assertStringContainsString( 'https://cluster.example/gpu', $html );
		self::assertStringNotContainsString( 'javascript:', $html );
		self::assertStringNotContainsString( '>Bad<', $html );
	}

	public function test_shortcut_bar_uses_english_group_labels(): void {
		$post = self::section( 71, 'faculty' );
		$GLOBALS['lps_test_post_meta'][71]['_lps_intranet_links'] = json_encode(
			array(
				array(
					'label' => array(
						'pt-br' => 'Filas',
						'en'    => 'Queues',
					),
					'items' => array(
						array(
							'label' => 'GPU',
							'url'   => 'https://cluster.example/gpu',
						),
					),
				),
			)
		);
		$user = self::user( 7, array( 'lps_professor' ) );
		$html = IntranetSurfaces::view(
			array(
				'view'   => 'section',
				'locale' => 'en',
				'slug'   => 'cluster',
				'post'   => $post,
			),
			$user,
			'en'
		);
		self::assertStringContainsString( 'Queues', $html );
		self::assertStringNotContainsString( 'Filas', $html );
	}

	public function test_section_without_links_meta_has_no_bar(): void {
		$post = self::section( 72, 'faculty' );
		$user = self::user( 8, array( 'lps_professor' ) );
		$html = IntranetSurfaces::view(
			array(
				'view'   => 'section',
				'locale' => 'pt-br',
				'slug'   => 'cluster',
				'post'   => $post,
			),
			$user,
			'pt-br'
		);
		self::assertStringNotContainsString( 'lps-shortcut-bar', $html );
	}
}
