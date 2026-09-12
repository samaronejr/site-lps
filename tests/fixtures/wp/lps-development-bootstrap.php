<?php
/**
 * Deterministic local-only WordPress setup.
 *
 * @package LPS\Development
 */

declare(strict_types=1);

add_action(
	'after_setup_theme',
	static function (): void {
		switch_theme( 'lps-theme' );
	}
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
