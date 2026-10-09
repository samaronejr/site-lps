<?php
/**
 * Namespaced meta/capability seams for the intranet unit tests.
 *
 * The intranet classes call `get_post_meta`, `get_user_meta`, `user_can` and
 * `get_posts` unqualified inside `LPS\Theme`, so these declarations win over
 * the WordPress globals — when this file is loaded the calls are steered
 * through $GLOBALS instead of touching a database.
 *
 * @package LPS\Theme\Tests
 */

declare(strict_types=1);

namespace LPS\Theme;

if ( ! function_exists( __NAMESPACE__ . '\\get_post_meta' ) ) {
	/**
	 * Reads a test post-meta value.
	 *
	 * @return mixed
	 */
	function get_post_meta( int $post_id, string $key, bool $single = false ): mixed {
		unset( $single );
		return $GLOBALS['lps_test_post_meta'][ $post_id ][ $key ] ?? '';
	}

	/**
	 * Reads a test user-meta value.
	 *
	 * @return mixed
	 */
	function get_user_meta( int $user_id, string $key, bool $single = false ): mixed {
		unset( $single );
		return $GLOBALS['lps_test_user_meta'][ $user_id ][ $key ] ?? '';
	}

	/**
	 * Checks a test capability grant.
	 */
	function user_can( mixed $user, string $capability ): bool {
		$id = is_object( $user ) && isset( $user->ID ) ? (int) $user->ID : (int) $user;
		return in_array( $capability, $GLOBALS['lps_test_caps'][ $id ] ?? array(), true );
	}

	/**
	 * Returns the test post list.
	 *
	 * @param array<string, mixed> $args Unused query arguments.
	 * @return array<int, mixed>
	 */
	function get_posts( array $args = array() ): array {
		unset( $args );
		return $GLOBALS['lps_test_posts'] ?? array();
	}
}
