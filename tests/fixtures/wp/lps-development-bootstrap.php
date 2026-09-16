<?php
/**
 * Deterministic local-only WordPress setup.
 *
 * @package LPS\Development
 */

declare(strict_types=1);

$lps_wordpress_default_content_identity = __DIR__ . '/lps-wordpress-default-content.php';
if ( is_readable( $lps_wordpress_default_content_identity ) ) {
	require_once $lps_wordpress_default_content_identity;
}
unset( $lps_wordpress_default_content_identity );

add_action(
	'after_setup_theme',
	static function (): void {
		switch_theme( 'lps-theme' );
	}
);

/*
 * WordPress publishes "Hello world!" and an English "Sample Page" during
 * install. The locale assignment below adopts every unassigned record into
 * pt-br, which would publish that untouched English placeholder copy on the
 * Portuguese site. Remove the install defaults first, on every request, so no
 * fresh provision can reintroduce them and nothing downstream inherits them.
 */
add_action(
	'init',
	static function (): void {
		if ( ! function_exists( 'lps_wordpress_default_content_remove' ) ) {
			trigger_error( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_trigger_error -- Local fixture misconfiguration must be loud.
				'LPS fixture: the WordPress default-content identity table is unavailable, so install defaults were left in place.',
				E_USER_WARNING
			);
			return;
		}
		lps_wordpress_default_content_record( lps_wordpress_default_content_remove() );
	},
	0
);

add_action(
	'init',
	static function (): void {
		if ( ! function_exists( 'PLL' ) || ! isset( PLL()->model ) ) {
			return;
		}

		$languages = array(
			array(
				'name'       => 'Português',
				'slug'       => 'pt-br',
				'locale'     => 'pt_BR',
				'term_group' => 0,
			),
			array(
				'name'       => 'English',
				'slug'       => 'en',
				'locale'     => 'en_US',
				'term_group' => 1,
			),
		);
		foreach ( $languages as $language ) {
			if ( ! PLL()->model->get_language( $language['slug'] ) ) {
				PLL()->model->add_language( $language );
			}
		}

		if ( function_exists( 'pll_set_post_language' ) ) {
			$posts = get_posts(
				array(
					'numberposts' => -1,
					'post_status' => 'any',
					'post_type'   => 'any',
				)
			);
			foreach ( $posts as $post ) {
				if ( ! pll_get_post_language( $post->ID ) ) {
					pll_set_post_language( $post->ID, 'pt-br' );
				}
			}
		}
	},
	1
);
