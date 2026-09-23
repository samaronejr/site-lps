<?php
/**
 * Members-only intranet routes.
 *
 * The intranet is a private section of the public shell: it exists only for
 * signed-in accounts, so it is bound to frozen routes the way the task
 * dashboard is, and every request is gated on `is_user_logged_in()` before a
 * byte of markup is produced. Anonymous requests are forwarded to the branded
 * sign-in surface with the destination carried through `redirect_to`, exactly
 * like the dashboard gate.
 *
 * Sections are `page` records published with the `private` status — the same
 * records an editor manages under Pages — flagged by the `_lps_intranet_access`
 * meta. Private posts never resolve on the public site, so members-only
 * content cannot leak through a permalink, a feed or a sitemap; the gated
 * route below is the only renderer that sees them. A section's access level
 * is either `members` (any signed-in account — e.g. the cluster area) or
 * `project`, which additionally requires the project named in
 * `_lps_intranet_project` to appear in the account's `_lps_intranet_projects`
 * user meta (granted from the user's wp-admin profile).
 *
 * @package LPS\Theme
 */

declare(strict_types=1);

namespace LPS\Theme;

use WP_Post;
use WP_User;

if ( ! class_exists( IntranetSurfaces::class ) ) {
	require_once __DIR__ . '/class-intranetsurfaces.php';
}

/** Binds the intranet to its frozen locale routes. */
final class IntranetRoutes {
	/** Intranet base segment — the word is shared by both locales. */
	private const BASE = 'intranet';

	/** Maximum slug length accepted for a section segment. */
	private const SLUG_MAX = 60;

	/**
	 * Returns the intranet root path of one locale.
	 *
	 * @param string $locale Supported locale slug.
	 */
	public static function intranet_path( string $locale ): string {
		return '/' . ( 'en' === $locale ? 'en' : 'pt-br' ) . '/' . self::BASE . '/';
	}

	/**
	 * Returns the path of one intranet section in one locale.
	 *
	 * @param string $slug   Section slug.
	 * @param string $locale Supported locale slug.
	 */
	public static function section_path( string $slug, string $locale ): string {
		return self::intranet_path( $locale ) . $slug . '/';
	}

	/**
	 * Returns the BCP 47 tag of a locale for document markup.
	 *
	 * @param string $locale Supported locale slug.
	 */
	public static function bcp47( string $locale ): string {
		return 'en' === $locale ? 'en' : 'pt-BR';
	}

	/**
	 * Resolves a request path to an intranet route.
	 *
	 * @param string $path Sanitized request path.
	 * @return array{view: string, locale: string, slug: string}|null
	 */
	public static function match_path( string $path ): ?array {
		foreach ( array( 'pt-br', 'en' ) as $locale ) {
			$base = '/' . $locale . '/' . self::BASE;
			if ( ! str_starts_with( $path, $base . '/' ) && $path !== $base ) {
				continue;
			}
			$rest = substr( $path, strlen( $base ) );
			$rest = trim( $rest, '/' );
			if ( '' === $rest ) {
				return array(
					'view'   => 'home',
					'locale' => $locale,
					'slug'   => '',
				);
			}
			if ( preg_match( '~^[a-z0-9-]{1,' . self::SLUG_MAX . '}$~', $rest ) ) {
				return array(
					'view'   => 'section',
					'locale' => $locale,
					'slug'   => $rest,
				);
			}
			return null;
		}
		return null;
	}

	/**
	 * Finds the private page behind a section slug.
	 *
	 * Only `private` pages carrying the intranet flag are intranet sections —
	 * public posts of the same slug are unrelated content and never leak in.
	 *
	 * @param string $slug Section slug.
	 */
	public static function section_post( string $slug ): ?WP_Post {
		if ( ! function_exists( 'get_posts' ) ) {
			return null;
		}
		$posts = get_posts(
			array(
				'name'        => $slug,
				'post_type'   => 'page',
				'post_status' => 'private',
				'meta_key'    => '_lps_intranet_access',
				'numberposts' => 1,
			)
		);
		return isset( $posts[0] ) ? $posts[0] : null;
	}

	/**
	 * Resolves a section's access contract.
	 *
	 * @param WP_Post $post Private intranet page.
	 * @return array{level: string, project: int}
	 */
	public static function section_access( WP_Post $post ): array {
		$level   = get_post_meta( $post->ID, '_lps_intranet_access', true );
		$project = get_post_meta( $post->ID, '_lps_intranet_project', true );
		return array(
			'level'   => is_string( $level ) && 'project' === $level ? 'project' : 'members',
			'project' => is_numeric( $project ) ? (int) $project : 0,
		);
	}

	/**
	 * Returns the project IDs an account may enter inside the intranet.
	 *
	 * @param WP_User $user Signed-in account.
	 * @return array<int, int>
	 */
	public static function user_projects( WP_User $user ): array {
		$grants = get_user_meta( $user->ID, '_lps_intranet_projects', true );
		if ( ! is_array( $grants ) ) {
			return array();
		}
		return array_values( array_map( static fn( mixed $grant ): int => is_numeric( $grant ) ? (int) $grant : 0, $grants ) );
	}

	/**
	 * Checks whether an account may open a section.
	 *
	 * Administrators always pass; `members` sections accept any signed-in
	 * account; `project` sections additionally require the grant.
	 *
	 * @param WP_User $user Signed-in account.
	 * @param WP_Post $post Private intranet page.
	 */
	public static function user_can_open( WP_User $user, WP_Post $post ): bool {
		if ( user_can( $user, 'manage_options' ) ) {
			return true;
		}
		$access = self::section_access( $post );
		if ( 'project' !== $access['level'] || $access['project'] <= 0 ) {
			return true;
		}
		return in_array( $access['project'], self::user_projects( $user ), true );
	}

	/** Registers the request short-circuit, mirroring the dashboard boot. */
	public static function boot(): void {
		add_filter( 'do_parse_request', array( self::class, 'intercept_intranet' ), 1, 3 );
		add_action( 'template_redirect', array( self::class, 'serve' ), 0 );
		add_filter( 'redirect_canonical', array( self::class, 'keep_intranet_route' ), 10, 2 );
	}

	/**
	 * Serves the intranet before the request is parsed into a query.
	 *
	 * @param bool  $proceed Whether WordPress should parse the request.
	 * @param mixed $wp      Current WordPress environment instance.
	 * @param mixed $extra   Extra query variables.
	 */
	public static function intercept_intranet( bool $proceed, mixed $wp, mixed $extra ): bool {
		unset( $wp, $extra );
		self::serve();
		return $proceed;
	}

	/**
	 * Keeps intranet routes stable instead of redirecting to a permalink.
	 *
	 * @param string|false $redirect_url  Proposed canonical URL.
	 * @param string       $requested_url Requested URL.
	 * @return string|false
	 */
	public static function keep_intranet_route( string|false $redirect_url, string $requested_url ): string|false {
		$path = wp_parse_url( $requested_url, PHP_URL_PATH );
		if ( is_string( $path ) && null !== self::match_path( $path ) ) {
			return false;
		}
		return $redirect_url;
	}

	/** Serves the intranet for the current request. */
	public static function serve(): void {
		$route = self::match_path( self::request_path() );
		if ( null === $route ) {
			return;
		}
		if ( ! function_exists( 'is_user_logged_in' ) || ! is_user_logged_in() ) {
			$target = function_exists( 'home_url' ) ? home_url( self::request_path() ) : self::request_path();
			if ( function_exists( 'wp_login_url' ) && function_exists( 'wp_safe_redirect' ) ) {
				wp_safe_redirect( wp_login_url( $target ) );
				exit;
			}
			return;
		}
		$user = wp_get_current_user();
		if ( 'section' === $route['view'] ) {
			$post          = self::section_post( $route['slug'] );
			$route['post'] = $post;
			if ( null === $post ) {
				$route['view'] = 'missing';
			} elseif ( ! self::user_can_open( $user, $post ) ) {
				$route['view'] = 'locked';
			}
		}
		if ( function_exists( 'status_header' ) ) {
			status_header( 'locked' === $route['view'] || 'missing' === $route['view'] ? 403 : 200 );
		}
		header( 'Content-Type: text/html; charset=utf-8' );
		header( 'Cache-Control: private, no-store' );
		header( 'X-Robots-Tag: noindex, nofollow' );
		echo IntranetSurfaces::document( $route, $user ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- the renderer returns fully escaped markup.
		exit;
	}

	/** Returns the sanitized path of the current request. */
	private static function request_path(): string {
		if ( ! isset( $_SERVER['REQUEST_URI'] ) || ! is_string( $_SERVER['REQUEST_URI'] ) ) {
			return '/';
		}
		$path = wp_parse_url( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ), PHP_URL_PATH );
		return is_string( $path ) ? $path : '/';
	}
}
