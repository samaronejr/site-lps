<?php
/**
 * Authenticated faculty task-dashboard routes.
 *
 * The dashboard is the Operate-mode surface of the design contract
 * (DESIGN.md §7.4): a compact, task-first shell behind authentication that
 * lists the account's scoped work and renders the structured forms the
 * plugin's `TaskDashboard` handlers accept. Every route is private — the
 * response is `no-store`, `noindex`, and anonymous requests redirect to the
 * login flow with a return path.
 *
 * Routes are claimed by a `do_parse_request` short-circuit so they work on a
 * fresh environment without a rewrite flush; canonical rewrite rules are
 * registered alongside for the flushed rule set.
 *
 * @package LPS\Theme
 */

declare(strict_types=1);

namespace LPS\Theme;

require_once __DIR__ . '/class-dashboardsurfaces.php';
require_once __DIR__ . '/class-shell.php';

if ( ! class_exists( \LPS\ContentModel\TaskDashboard::class ) ) {
	require_once dirname( __DIR__, 3 ) . '/plugins/lps-content-model/includes/class-taskdashboard.php';
}

/** Binds the authenticated task dashboard to its frozen locale routes. */
final class DashboardRoutes {
	/**
	 * Frozen dashboard path segment per locale.
	 *
	 * @var array<string, string>
	 */
	private const SEGMENTS = array(
		'pt-br' => 'painel',
		'en'    => 'dashboard',
	);

	/**
	 * Frozen sub-path segments per view and locale.
	 *
	 * @var array<string, array<string, string>>
	 */
	private const VIEWS = array(
		'offering' => array(
			'pt-br' => 'ofertas',
			'en'    => 'offerings',
		),
		'news'     => array(
			'pt-br' => 'noticias',
			'en'    => 'news',
		),
		'profile'  => array(
			'pt-br' => 'perfil',
			'en'    => 'profile',
		),
		'review'   => array(
			'pt-br' => 'revisao',
			'en'    => 'review',
		),
		'create'   => array(
			'pt-br' => 'nova-oferta',
			'en'    => 'new-offering',
		),
	);

	/**
	 * BCP47 language tag for each supported locale slug.
	 *
	 * @var array<string, string>
	 */
	private const LANGUAGE_TAGS = array(
		'pt-br' => 'pt-BR',
		'en'    => 'en',
	);

	/**
	 * Returns the dashboard root path of one locale.
	 *
	 * @param string $locale Supported locale slug.
	 */
	public static function dashboard_path( string $locale ): string {
		$segment = self::SEGMENTS[ $locale ] ?? '';
		return '' === $segment ? '' : '/' . $locale . '/' . $segment . '/';
	}

	/**
	 * Returns the path of one dashboard view in one locale.
	 *
	 * @param string $view   View key (`home`, `offering`, `news`, `profile`, `review`, `create`).
	 * @param string $locale Supported locale slug.
	 * @param int    $id     Offering record ID for the workspace view.
	 */
	public static function view_path( string $view, string $locale, int $id = 0 ): string {
		$base = self::dashboard_path( $locale );
		if ( '' === $base ) {
			return '';
		}
		if ( 'home' === $view ) {
			return $base;
		}
		$segment = self::VIEWS[ $view ][ $locale ] ?? '';
		if ( '' === $segment ) {
			return '';
		}
		return 'offering' === $view ? $base . $segment . '/' . $id . '/' : $base . $segment . '/';
	}

	/**
	 * Resolves a request path back to its dashboard route.
	 *
	 * @param string $path Public request path.
	 * @return array{locale: string, view: string, id: int}|null
	 */
	public static function match_path( string $path ): ?array {
		$clean = '/' . trim( $path, '/' ) . '/';
		if ( 1 !== preg_match( '#^/(pt-br|en)/([^/]+?)(?:/([^/]+?))?(?:/([0-9]{1,10}))?/?$#', $clean, $parts ) ) {
			return null;
		}
		$locale  = $parts[1];
		$segment = $parts[2];
		if ( self::SEGMENTS[ $locale ] !== $segment ) {
			return null;
		}
		$sub  = isset( $parts[3] ) && '' !== $parts[3] ? $parts[3] : '';
		$id   = isset( $parts[4] ) ? (int) $parts[4] : 0;
		$view = 'home';
		if ( '' !== $sub ) {
			$view = '';
			foreach ( self::VIEWS as $key => $segments ) {
				if ( $segments[ $locale ] === $sub ) {
					$view = $key;
					break;
				}
			}
			if ( '' === $view ) {
				return null;
			}
			if ( 'offering' !== $view && 0 < $id ) {
				return null;
			}
			if ( 'offering' === $view && 0 >= $id ) {
				return null;
			}
		}
		return array(
			'locale' => $locale,
			'view'   => $view,
			'id'     => $id,
		);
	}

	/**
	 * Returns the BCP47 language tag for a supported locale slug.
	 *
	 * @param string $locale Supported locale slug.
	 */
	public static function bcp47( string $locale ): string {
		return self::LANGUAGE_TAGS[ $locale ] ?? self::LANGUAGE_TAGS['pt-br'];
	}

	/**
	 * Returns the rewrite rules of both dashboard routes.
	 *
	 * The rules map the frozen paths onto a query var so a flushed rule set
	 * resolves them through the normal query pipeline; the parse-request
	 * short-circuit already serves them before that.
	 *
	 * @return array<string, string>
	 */
	public static function rewrite_rules(): array {
		$rules = array();
		foreach ( self::SEGMENTS as $locale => $segment ) {
			$base = $locale . '/' . $segment;
			foreach ( self::VIEWS as $view => $segments ) {
				$sub = $segments[ $locale ];
				if ( 'offering' === $view ) {
					$rules[ $base . '/' . $sub . '/([0-9]{1,10})/?$' ] = 'index.php?lps_dashboard=' . $view . '&lps_dashboard_id=$matches[1]&lang=' . $locale;
					continue;
				}
				$rules[ $base . '/' . $sub . '/?$' ] = 'index.php?lps_dashboard=' . $view . '&lang=' . $locale;
			}
			$rules[ $base . '/?$' ] = 'index.php?lps_dashboard=home&lang=' . $locale;
		}
		return $rules;
	}

	/** Registers the dashboard routes and the request short-circuit. */
	public static function boot(): void {
		add_filter( 'rewrite_rules_array', array( self::class, 'register_routes' ), 998 );
		add_filter( 'query_vars', array( self::class, 'register_query_vars' ) );
		add_filter( 'do_parse_request', array( self::class, 'intercept_dashboard' ), 1, 3 );
		add_action( 'template_redirect', array( self::class, 'serve' ), 0 );
		add_filter( 'redirect_canonical', array( self::class, 'keep_dashboard_route' ), 10, 2 );
	}

	/**
	 * Prepends the dashboard rewrite rules.
	 *
	 * @param array<string, string> $rules Registered rewrite rules.
	 * @return array<string, string>
	 */
	public static function register_routes( array $rules ): array {
		return array_merge( self::rewrite_rules(), $rules );
	}

	/**
	 * Registers the dashboard query vars.
	 *
	 * @param array<int, string> $vars Registered public query vars.
	 * @return array<int, string>
	 */
	public static function register_query_vars( array $vars ): array {
		$vars[] = 'lps_dashboard';
		$vars[] = 'lps_dashboard_id';
		return $vars;
	}

	/**
	 * Serves the dashboard before the request is parsed into a query.
	 *
	 * @param bool  $proceed Whether WordPress should parse the request.
	 * @param mixed $wp      Current WordPress environment instance.
	 * @param mixed $extra   Extra query variables.
	 */
	public static function intercept_dashboard( bool $proceed, mixed $wp, mixed $extra ): bool {
		unset( $wp, $extra );
		self::serve();
		return $proceed;
	}

	/**
	 * Keeps dashboard routes stable instead of redirecting to a permalink.
	 *
	 * @param string|false $redirect_url  Proposed canonical URL.
	 * @param string       $requested_url Requested URL.
	 * @return string|false
	 */
	public static function keep_dashboard_route( string|false $redirect_url, string $requested_url ): string|false {
		$path = wp_parse_url( $requested_url, PHP_URL_PATH );
		if ( is_string( $path ) && null !== self::match_path( $path ) ) {
			return false;
		}
		return $redirect_url;
	}

	/** Serves the dashboard for the current request. */
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
		if ( function_exists( 'status_header' ) ) {
			status_header( 200 );
		}
		header( 'Content-Type: text/html; charset=utf-8' );
		header( 'Cache-Control: private, no-store' );
		header( 'X-Robots-Tag: noindex, nofollow' );
		$user = wp_get_current_user();
		echo DashboardSurfaces::document( $route['view'], $route['locale'], $user, $route['id'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- the renderer returns fully escaped markup.
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
