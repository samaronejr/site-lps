<?php
/**
 * LPS Modern theme setup.
 *
 * Self-contained: the theme renders its own shell and homepage sections and
 * reads governed records from the lps-content-model plugin. It never requires
 * the sibling lps-theme directory.
 *
 * @package LPS\Modern
 */

declare(strict_types=1);

namespace LPS\Modern;

require_once __DIR__ . '/includes/class-modern-shell.php';
require_once __DIR__ . '/includes/class-modern-homepage.php';

add_action(
	'after_setup_theme',
	static function (): void {
		load_theme_textdomain( 'lps-modern', get_template_directory() . '/languages' );
		add_theme_support( 'editor-styles' );
		add_editor_style( 'assets/css/theme.css' );
		add_theme_support( 'wp-block-styles' );
		add_theme_support( 'responsive-embeds' );
		add_theme_support( 'title-tag' );
		remove_action( 'wp_footer', 'the_block_template_skip_link' );
	}
);

add_action(
	'wp_enqueue_scripts',
	static function (): void {
		$version = wp_get_theme()->get( 'Version' );
		wp_enqueue_style( 'lps-modern', get_theme_file_uri( 'assets/css/theme.css' ), array(), $version );
	}
);

add_action(
	'init',
	static function (): void {
		register_block_type(
			'lps-modern/home',
			array(
				'api_version'     => '3',
				'render_callback' => array( ModernHomepage::class, 'render' ),
				'attributes'      => array( 'section' => array( 'type' => 'string' ) ),
				'supports'        => array(
					'html'     => false,
					'lock'     => false,
					'reusable' => false,
					'inserter' => false,
				),
			)
		);
		$blocks = array(
			'lps-modern/header'        => array( ModernShell::class, 'render_header' ),
			'lps-modern/breadcrumbs'   => array( ModernShell::class, 'render_breadcrumbs' ),
			'lps-modern/post-metadata' => array( ModernShell::class, 'render_post_metadata' ),
			'lps-modern/footer'        => array( ModernShell::class, 'render_footer' ),
			'lps-modern/empty-state'   => array( ModernShell::class, 'render_empty_state' ),
			'lps-modern/index-title'   => array( ModernShell::class, 'render_index_title' ),
			'lps-modern/archive-title' => array( ModernShell::class, 'render_archive_title' ),
			'lps-modern/not-found'     => array( ModernShell::class, 'render_not_found' ),
		);
		foreach ( $blocks as $name => $callback ) {
			register_block_type(
				$name,
				array(
					'api_version'     => '3',
					'render_callback' => $callback,
					'supports'        => array(
						'html'     => false,
						'lock'     => false,
						'reusable' => false,
					),
				)
			);
		}
	}
);

add_filter( 'render_block', array( ModernShell::class, 'make_tables_scrollable_by_keyboard' ), 10, 2 );
add_filter( 'get_the_archive_title', array( ModernShell::class, 'localize_archive_title' ) );
add_filter( 'render_block', array( ModernShell::class, 'localize_search_results' ), 10, 2 );

add_filter(
	'language_attributes',
	static function ( string $output ): string {
		$locale = get_locale();
		$bcp47  = str_replace( '_', '-', $locale );
		$tagged = preg_replace( '/lang="[^"]*"/', 'lang="' . esc_attr( $bcp47 ) . '"', $output );
		return is_string( $tagged ) ? $tagged : $output;
	},
	100
);
