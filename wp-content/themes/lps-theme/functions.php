<?php
/**
 * LPS theme setup.
 *
 * @package LPS\Theme
 */

declare(strict_types=1);

namespace LPS\Theme;

require_once __DIR__ . '/includes/class-shell.php';
require_once __DIR__ . '/includes/class-homepage.php';
require_once __DIR__ . '/includes/class-discoverysurfaces.php';
require_once __DIR__ . '/includes/class-publicsurfaces.php';
require_once __DIR__ . '/includes/class-discoveryroutes.php';
require_once __DIR__ . '/includes/class-teachingroutes.php';
require_once __DIR__ . '/includes/class-publicroutes.php';
require_once __DIR__ . '/includes/class-searchroutes.php';
require_once __DIR__ . '/includes/class-trustroutes.php';
require_once __DIR__ . '/includes/class-seoroutes.php';
require_once __DIR__ . '/includes/class-authsurfaces.php';
require_once __DIR__ . '/includes/class-googleoauth.php';
require_once __DIR__ . '/includes/class-authroutes.php';
require_once __DIR__ . '/includes/class-dashboardroutes.php';
require_once __DIR__ . '/includes/class-assetpolicy.php';
require_once __DIR__ . '/includes/class-cachepolicy.php';
require_once __DIR__ . '/includes/class-delivery.php';

require_once __DIR__ . '/includes/class-brandassets.php';

DiscoveryRoutes::boot();
TeachingRoutes::boot();
PublicRoutes::boot();
SearchRoutes::boot();
TrustRoutes::boot();
SeoRoutes::boot();
DashboardRoutes::boot();
AuthRoutes::boot();
BrandAssets::boot();
Delivery::boot();

add_action(
	'after_setup_theme',
	static function (): void {
		load_theme_textdomain( 'lps-theme', get_template_directory() . '/languages' );
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
		wp_enqueue_style( 'lps-theme', get_theme_file_uri( 'assets/css/theme.css' ), array(), $version );
	}
);

add_action(
	'init',
	static function (): void {
		register_block_type(
			'lps-theme/homepage',
			array(
				'api_version'     => '3',
				'render_callback' => array( Homepage::class, 'render' ),
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
			'lps-theme/discovery'       => array( DiscoveryRoutes::class, 'render_block' ),
			'lps-theme/teaching'        => array( TeachingRoutes::class, 'render_block' ),
			'lps-theme/public-surfaces' => array( PublicRoutes::class, 'render_block' ),
			'lps-theme/search'          => array( SearchRoutes::class, 'render_block' ),
			'lps-theme/trust'           => array( TrustRoutes::class, 'render_block' ),
			'lps-theme/header'          => array( Shell::class, 'render_header' ),
			'lps-theme/breadcrumbs'     => array( Shell::class, 'render_breadcrumbs' ),
			'lps-theme/post-metadata'   => array( Shell::class, 'render_post_metadata' ),
			'lps-theme/footer'          => array( Shell::class, 'render_footer' ),
			'lps-theme/empty-state'     => array( Shell::class, 'render_empty_state' ),
			'lps-theme/index-title'     => array( Shell::class, 'render_index_title' ),
			'lps-theme/archive-title'   => array( Shell::class, 'render_archive_title' ),
			'lps-theme/not-found'       => array( Shell::class, 'render_not_found' ),
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

add_filter( 'render_block', array( Shell::class, 'make_tables_scrollable_by_keyboard' ), 10, 2 );

add_filter( 'get_the_archive_title', array( Shell::class, 'localize_archive_title' ) );
add_filter( 'render_block', array( Shell::class, 'localize_search_results' ), 10, 2 );

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
