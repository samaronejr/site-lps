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
require_once __DIR__ . '/includes/class-intranetroutes.php';
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
IntranetRoutes::boot();
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
		// Bust browser caches on content, not on the static theme version —
		// otherwise every deployed change stays invisible until a hard reload.
		$version = wp_get_theme()->get( 'Version' ) . '.' . filemtime( get_theme_file_path( 'assets/css/theme.css' ) );
		wp_enqueue_style( 'lps-theme', get_theme_file_uri( 'assets/css/theme.css' ), array(), $version );
		$version = wp_get_theme()->get( 'Version' ) . '.' . filemtime( get_theme_file_path( 'assets/js/theme.js' ) );
		wp_enqueue_script( 'lps-theme', get_theme_file_uri( 'assets/js/theme.js' ), array(), $version, true );
	}
);

/*
 * wp-login screens — the Two Factor challenge step in particular — otherwise
 * render stock WordPress chrome mid-flow. The site lockup, the anchor palette
 * and the home link keep the hand-off inside the laboratory's identity.
 */
add_action(
	'login_enqueue_scripts',
	static function (): void {
		$mark = get_theme_file_uri( 'assets/brand/lps_coppe_blue_lockup.svg' );
		wp_register_style( 'lps-login', false, array(), wp_get_theme()->get( 'Version' ) );
		wp_enqueue_style( 'lps-login' );
		wp_add_inline_style(
			'lps-login',
			'body.login{background:#0b1f33;background-image:none;color:#eaf1f8;}'
			. '#login h1 a{background-image:url(' . esc_url_raw( $mark ) . ');background-position:center center;background-repeat:no-repeat;background-size:contain;height:5.5rem;width:17rem;}'
			. '.login #backtoblog a,.login #nav a,.login .privacy-policy-page-link a{color:#b8d4ee;}'
			. '.login #backtoblog a:hover,.login #nav a:hover{color:#fff;}'
			. '.login form{background:#fff;border:1px solid #d4e2ef;box-shadow:0 10px 30px rgba(4,16,31,.28);}'
			. '.login .privacy-policy-page-link{color:#b8d4ee;}'
		);
	}
);

add_filter(
	'login_headerurl',
	static function (): string {
		return home_url( '/' );
	}
);

add_filter(
	'login_headertext',
	static function (): string {
		return 'Laboratório de Processamento de Sinais — UFRJ/COPPE';
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
add_filter( 'render_block', array( Shell::class, 'institutional_page_title' ), 10, 2 );

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

/*
 * Intranet authoring UI — the intranet sections are private `page` records
 * flagged by `_lps_intranet_access`; the metabox on Pages keeps the two flags
 * out of the raw custom-fields panel, and the user-profile panel grants
 * project-level access per account. IntranetRoutes owns the gated surface.
 */
add_action(
	'add_meta_boxes',
	static function (): void {
		add_meta_box(
			'lps-intranet-access',
			'Intranet',
			static function ( \WP_Post $post ): void {
				wp_nonce_field( 'lps_intranet_page', 'lps_intranet_page_nonce' );
				$raw_access  = get_post_meta( $post->ID, '_lps_intranet_access', true );
				$raw_project = get_post_meta( $post->ID, '_lps_intranet_project', true );
				$access      = is_string( $raw_access ) ? $raw_access : '';
				$project     = is_numeric( $raw_project ) ? (int) $raw_project : 0;
				$projects    = get_posts(
					array(
						'post_type'      => 'lps_project',
						'post_status'    => 'publish',
						'orderby'        => 'title',
						'order'          => 'ASC',
						'posts_per_page' => 100,
					)
				);
				echo '<p><label for="lps_intranet_access"><strong>Intranet section</strong></label><br />'
					. '<select id="lps_intranet_access" name="lps_intranet_access">'
					. '<option value="">' . esc_html__( 'Not an intranet section', 'lps-theme' ) . '</option>'
					. '<option value="members"' . selected( 'members', $access, false ) . '>' . esc_html__( 'All members', 'lps-theme' ) . '</option>'
					. '<option value="project"' . selected( 'project', $access, false ) . '>' . esc_html__( 'Restricted to project', 'lps-theme' ) . '</option>'
					. '</select></p>'
					. '<p><label for="lps_intranet_project"><strong>' . esc_html__( 'Project', 'lps-theme' ) . '</strong></label><br />'
					. '<select id="lps_intranet_project" name="lps_intranet_project"><option value="">—</option>';
				foreach ( $projects as $candidate ) {
					echo '<option value="' . esc_attr( (string) $candidate->ID ) . '"' . selected( $candidate->ID, $project, false ) . '>' . esc_html( $candidate->post_title ) . '</option>';
				}
				echo '</select></p>'
					. '<p class="description">' . esc_html__( 'Intranet sections must stay Private — they are only reachable inside /intranet/ for signed-in members.', 'lps-theme' ) . '</p>';
			},
			'page',
			'side'
		);
	}
);

add_action(
	'save_post_page',
	static function ( int $post_id ): void {
		$raw_nonce = isset( $_POST['lps_intranet_page_nonce'] ) && is_string( $_POST['lps_intranet_page_nonce'] )
			? sanitize_text_field( wp_unslash( $_POST['lps_intranet_page_nonce'] ) )
			: '';
		if ( '' === $raw_nonce || ! wp_verify_nonce( $raw_nonce, 'lps_intranet_page' ) ) {
			return;
		}
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( ! current_user_can( 'edit_page', $post_id ) ) {
			return;
		}
		$raw    = isset( $_POST['lps_intranet_access'] ) && is_string( $_POST['lps_intranet_access'] )
			? sanitize_key( wp_unslash( $_POST['lps_intranet_access'] ) )
			: '';
		$access = in_array( $raw, array( 'members', 'project' ), true ) ? $raw : '';
		if ( '' === $access ) {
			delete_post_meta( $post_id, '_lps_intranet_access' );
			delete_post_meta( $post_id, '_lps_intranet_project' );
			return;
		}
		update_post_meta( $post_id, '_lps_intranet_access', $access );
		$project = isset( $_POST['lps_intranet_project'] ) && is_scalar( $_POST['lps_intranet_project'] ) && is_numeric( $_POST['lps_intranet_project'] )
			? (int) $_POST['lps_intranet_project']
			: 0;
		if ( 'project' === $access && $project > 0 ) {
			update_post_meta( $post_id, '_lps_intranet_project', $project );
		} else {
			delete_post_meta( $post_id, '_lps_intranet_project' );
		}
	}
);

add_action(
	'edit_user_profile',
	static function ( \WP_User $user ): void {
		if ( ! current_user_can( 'edit_user', $user->ID ) ) {
			return;
		}
		$raw_meta = get_user_meta( $user->ID, '_lps_intranet_projects', true );
		$grants   = is_array( $raw_meta ) ? array_map( static fn( mixed $grant ): int => is_numeric( $grant ) ? (int) $grant : 0, $raw_meta ) : array();
		$projects = get_posts(
			array(
				'post_type'      => 'lps_project',
				'post_status'    => 'publish',
				'orderby'        => 'title',
				'order'          => 'ASC',
				'posts_per_page' => 100,
			)
		);
		wp_nonce_field( 'lps_intranet_grants', 'lps_intranet_grants_nonce' );
		echo '<h2>Intranet</h2><table class="form-table" role="presentation"><tr>'
			. '<th>' . esc_html__( 'Project access', 'lps-theme' ) . '</th><td><fieldset>';
		foreach ( $projects as $project ) {
			echo '<label><input type="checkbox" name="lps_intranet_projects[]" value="' . esc_attr( (string) $project->ID ) . '"' . checked( true, in_array( $project->ID, $grants, true ), false ) . ' /> ' . esc_html( $project->post_title ) . '</label><br />';
		}
		echo '</fieldset><p class="description">' . esc_html__( 'Restricted intranet areas this account may open. Members-only areas (e.g. the cluster) need no grant.', 'lps-theme' ) . '</p></td></tr></table>';
	}
);

add_action(
	'edit_user_profile_update',
	static function ( int $user_id ): void {
		$raw_nonce = isset( $_POST['lps_intranet_grants_nonce'] ) && is_string( $_POST['lps_intranet_grants_nonce'] )
			? sanitize_text_field( wp_unslash( $_POST['lps_intranet_grants_nonce'] ) )
			: '';
		if ( '' === $raw_nonce || ! wp_verify_nonce( $raw_nonce, 'lps_intranet_grants' ) ) {
			return;
		}
		if ( ! current_user_can( 'edit_user', $user_id ) ) {
			return;
		}
		$raw_projects = isset( $_POST['lps_intranet_projects'] ) && is_array( $_POST['lps_intranet_projects'] )
			? array_map(
				static fn( mixed $grant ): int => is_scalar( $grant ) ? (int) sanitize_text_field( (string) $grant ) : 0,
				wp_unslash( $_POST['lps_intranet_projects'] ) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- elements are sanitized inside the callback.
			)
			: array();
		update_user_meta( $user_id, '_lps_intranet_projects', array_values( array_filter( $raw_projects ) ) );
	}
);
