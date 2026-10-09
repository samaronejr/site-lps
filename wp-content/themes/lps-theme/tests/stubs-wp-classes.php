<?php
/**
 * Minimal WP_Post/WP_User stubs for the intranet unit tests.
 *
 * @package LPS\Theme\Tests
 */

declare(strict_types=1);

if ( ! class_exists( 'WP_Post' ) ) {
	/** Minimal post stub for the intranet contract. */
	class WP_Post {
		/** Post ID. */
		public int $ID = 0;
		/** Post slug. */
		public string $post_name = '';
		/** Post title. */
		public string $post_title = '';
		/** Post excerpt. */
		public string $post_excerpt = '';
		/** Post body. */
		public string $post_content = '';
		/** Post status. */
		public string $post_status = 'private';
	}
}

if ( ! class_exists( 'WP_User' ) ) {
	/** Minimal user stub carrying the role list the gate reads. */
	class WP_User {
		/** User ID. */
		public int $ID = 0;
		/** Role slugs.
		 *
		 * @var array<int, string>
		 */
		public array $roles = array();
	}
}
