<?php
/**
 * Access-contract and shortcut-bar tests for the members-only intranet.
 *
 * @package LPS\Theme\Tests
 */

declare(strict_types=1);

namespace LPS\Theme\Tests;

use LPS\Theme\IntranetRoutes;
use LPS\Theme\IntranetSurfaces;
use PHPUnit\Framework\Attributes\DataProvider;
use WP_Post;
use WP_User;

/** Gate and shortcut contract tests for the intranet. */
final class IntranetRoutesTest extends \PHPUnit\Framework\TestCase {
	public static function setUpBeforeClass(): void {
		// Loaded lazily so the WP_Post stand-in never reaches the foundation
		// suite: PHPUnit includes every test file up front, and WpSeedRuntime
		// relies on class_alias('WP_Post') while no real class exists yet.
		$stubs = dirname( __DIR__, 4 ) . '/tests/theme-unit';
		require_once $stubs . '/stubs-wp-classes.php';
		require_once $stubs . '/stubs-intranet.php';
		require_once dirname( __DIR__ ) . '/includes/class-intranetroutes.php';
	}

	protected function tearDown(): void {
		unset( $GLOBALS['lps_test_post_meta'], $GLOBALS['lps_test_user_meta'], $GLOBALS['lps_test_caps'], $GLOBALS['lps_test_posts'] );
	}

	/**
	 * Writes one entry into the test post-meta store.
	 */
	private static function set_post_meta( int $post_id, string $key, mixed $value ): void {
		$store = $GLOBALS['lps_test_post_meta'] ?? array();
		if ( ! is_array( $store ) ) {
			$store = array();
		}
		$record = $store[ $post_id ] ?? array();
		if ( ! is_array( $record ) ) {
			$record = array();
		}
		$record[ $key ]                = $value;
		$store[ $post_id ]             = $record;
		$GLOBALS['lps_test_post_meta'] = $store;
	}

	private static function section( int $id, string $access, int $project = 0 ): WP_Post {
		$post             = new WP_Post( new \stdClass() );
		$post->ID         = $id;
		$post->post_name  = 'section-' . $id;
		$post->post_title = 'Section ' . $id;
		self::set_post_meta( $id, '_lps_intranet_access', $access );
		self::set_post_meta( $id, '_lps_intranet_project', $project );
		return $post;
	}

	/**
	 * @param array<int, string> $roles
	 * @param array<int, string> $caps
	 * @param array<int, int>    $projects
	 */
	private static function user( int $id, array $roles, array $caps = array(), array $projects = array() ): WP_User {
		$user        = new WP_User();
		$user->ID    = $id;
		$user->roles = $roles;
		$caps_store  = $GLOBALS['lps_test_caps'] ?? array();
		if ( is_array( $caps_store ) ) {
			$caps_store[ $id ]        = $caps;
			$GLOBALS['lps_test_caps'] = $caps_store;
		}
		$meta_store = $GLOBALS['lps_test_user_meta'] ?? array();
		if ( is_array( $meta_store ) ) {
			$meta_store[ $id ]             = array( '_lps_intranet_projects' => $projects );
			$GLOBALS['lps_test_user_meta'] = $meta_store;
		}
		return $user;
	}

	/**
	 * Stored access values and their resolved levels.
	 *
	 * @return array<string, array{string, string}>
	 */
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

	/**
	 * Role lists that pass the faculty gate.
	 *
	 * @return array<string, array{array<int, string>}>
	 */
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

	/**
	 * Role lists the faculty gate locks out.
	 *
	 * @return array<string, array{array<int, string>}>
	 */
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
		self::set_post_meta(
			70,
			'_lps_intranet_links',
			json_encode(
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
		self::set_post_meta(
			71,
			'_lps_intranet_links',
			json_encode(
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
