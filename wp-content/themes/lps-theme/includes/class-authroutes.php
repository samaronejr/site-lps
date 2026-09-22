<?php
/**
 * Routes for the branded sign-in surface and the professor-area gateway.
 *
 * `AuthSurfaces` renders the document; this class owns the request lifecycle:
 * the GET renders the form (or the signed-in state), the POST verifies the
 * credentials through `wp_signon()` — keeping the core `authenticate` filters,
 * the `wp_login_failed`/`wp_login` hooks the audit ledger records, and the
 * content-model MFA capability stripping — and hands the session to the
 * destination the caller asked for.
 *
 * The sign-in surface and the professor area are one route family: the member
 * path is a gateway, not a document — a signed-in visitor is forwarded to the
 * task dashboard, an anonymous one to the sign-in surface with the requested
 * destination preserved so the flow returns them after the attempt.
 *
 * A `login_url` filter points every anonymous redirect — dashboard gates,
 * `auth_redirect` inside wp-admin, comment login links — at the branded
 * surface instead of `wp-login.php`, carrying the original `redirect_to` so a
 * successful attempt lands where the visitor actually wanted to go.
 *
 * Routes are claimed by a `do_parse_request` short-circuit so they work on a
 * fresh environment without a rewrite flush; canonical rewrite rules are
 * registered alongside for the flushed rule set.
 *
 * @package LPS\Theme
 */

declare(strict_types=1);

namespace LPS\Theme;

require_once __DIR__ . '/class-authsurfaces.php';
require_once __DIR__ . '/class-dashboardroutes.php';
require_once __DIR__ . '/class-googleoauth.php';
require_once __DIR__ . '/class-shell.php';

/** Binds the branded sign-in surface and the member gateway to their frozen locale routes. */
final class AuthRoutes {

	/**
	 * Registers the sign-in and member routes and the request short-circuit.
	 */
	public static function boot(): void {
		add_filter( 'rewrite_rules_array', array( self::class, 'register_routes' ), 997 );
		add_filter( 'query_vars', array( self::class, 'register_query_vars' ) );
		add_filter( 'do_parse_request', array( self::class, 'intercept_auth' ), 1, 3 );
		add_action( 'template_redirect', array( self::class, 'serve' ), 0 );
		add_filter( 'redirect_canonical', array( self::class, 'keep_auth_route' ), 10, 2 );
		add_filter( 'login_url', array( self::class, 'branded_login_url' ), 10, 3 );
	}

	/**
	 * Prepends the auth rewrite rules.
	 *
	 * @param array<string, string> $rules Registered rewrite rules.
	 * @return array<string, string>
	 */
	public static function register_routes( array $rules ): array {
		$auth = array();
		foreach ( array( 'pt-br', 'en' ) as $locale ) {
			foreach ( array( 'signin', 'member' ) as $kind ) {
				$segment = self::segment( $kind, $locale );
				if ( '' === $segment ) {
					continue;
				}
				$auth[ $locale . '/' . $segment . '/?$' ] = 'index.php?lps_auth=' . $kind . '&lang=' . $locale;
			}
			$signin = self::segment( 'signin', $locale );
			if ( '' !== $signin ) {
				$auth[ $locale . '/' . $signin . '/google/?$' ] = 'index.php?lps_auth=google&lang=' . $locale;
			}
		}
		return array_merge( $auth, $rules );
	}

	/**
	 * Registers the auth query vars.
	 *
	 * @param array<int, string> $vars Registered public query vars.
	 * @return array<int, string>
	 */
	public static function register_query_vars( array $vars ): array {
		$vars[] = 'lps_auth';
		return $vars;
	}

	/**
	 * Serves the auth routes before the request is parsed into a query.
	 *
	 * @param bool  $proceed Whether WordPress should parse the request.
	 * @param mixed $wp      Current WordPress environment instance.
	 * @param mixed $extra   Extra query variables.
	 */
	public static function intercept_auth( bool $proceed, mixed $wp, mixed $extra ): bool {
		unset( $wp, $extra );
		self::serve();
		return $proceed;
	}

	/**
	 * Keeps auth routes stable instead of redirecting to a permalink.
	 *
	 * @param string|false $redirect_url  Proposed canonical URL.
	 * @param string       $requested_url Requested URL.
	 * @return string|false
	 */
	public static function keep_auth_route( string|false $redirect_url, string $requested_url ): string|false {
		$path = wp_parse_url( $requested_url, PHP_URL_PATH );
		if ( is_string( $path ) && null !== self::match_path( $path ) ) {
			return false;
		}
		return $redirect_url;
	}

	/**
	 * Points anonymous login redirects at the branded surface.
	 *
	 * Core computes login URLs for dashboard gates, `auth_redirect` and
	 * comment flows; the laboratory's own form replaces `wp-login.php` for
	 * every one of them while preserving the requested destination.
	 *
	 * @param string $login_url    Core login URL.
	 * @param string $redirect     Requested post-login destination.
	 * @param bool   $force_reauth Whether the flow demands re-authentication.
	 * @return string
	 */
	public static function branded_login_url( string $login_url, string $redirect, bool $force_reauth ): string {
		$locale  = self::locale_from_redirect( $redirect );
		$signin  = function_exists( 'home_url' ) ? home_url( Shell::signin_path( $locale ) ) : Shell::signin_path( $locale );
		$query   = array();
		$current = wp_parse_url( $login_url, PHP_URL_QUERY );
		if ( is_string( $current ) && '' !== $current ) {
			parse_str( $current, $parsed );
			$raw_requested = $parsed['redirect_to'] ?? '';
			$requested     = is_string( $raw_requested ) ? sanitize_url( $raw_requested ) : '';
			if ( '' !== $requested ) {
				$redirect = $requested;
			}
		}
		if ( '' !== $redirect ) {
			$query['redirect_to'] = $redirect;
		}
		if ( $force_reauth ) {
			$query['reauth'] = '1';
		}
		return array() === $query ? $signin : $signin . '?' . http_build_query( $query, '', '&', PHP_QUERY_RFC3986 );
	}

	/**
	 * Resolves a request path back to its auth route.
	 *
	 * @param string $path Public request path.
	 * @return array{kind: string, locale: string}|null
	 */
	public static function match_path( string $path ): ?array {
		$clean = '/' . trim( $path, '/' ) . '/';
		if ( 1 !== preg_match( '#^/(pt-br|en)/([^/]+?)(?:/(google))?/?$#', $clean, $parts ) ) {
			return null;
		}
		if ( isset( $parts[3] ) && self::segment( 'signin', $parts[1] ) === $parts[2] ) {
			return array(
				'kind'   => 'google',
				'locale' => $parts[1],
			);
		}
		foreach ( array( 'signin', 'member' ) as $kind ) {
			if ( self::segment( $kind, $parts[1] ) === $parts[2] ) {
				return array(
					'kind'   => $kind,
					'locale' => $parts[1],
				);
			}
		}
		return null;
	}

	/** Serves the auth surface for the current request. */
	public static function serve(): void {
		$route = self::match_path( self::request_path() );
		if ( null === $route ) {
			return;
		}
		header( 'Cache-Control: private, no-store' );
		header( 'X-Robots-Tag: noindex, nofollow' );
		if ( 'member' === $route['kind'] ) {
			self::serve_member( $route['locale'] );
			return;
		}
		if ( 'google' === $route['kind'] ) {
			GoogleOauth::serve( $route['locale'] );
			return;
		}
		if ( self::is_post() ) {
			self::serve_post( $route['locale'] );
			return;
		}
		self::render( $route['locale'], self::state() );
	}

	/**
	 * Forwards the member route to the task dashboard.
	 *
	 * An anonymous visitor goes to the sign-in surface carrying the member
	 * destination; a signed-in one continues to the dashboard, whose own gate
	 * already decides the account's working surface.
	 *
	 * @param string $locale Supported locale slug.
	 */
	private static function serve_member( string $locale ): void {
		if ( function_exists( 'is_user_logged_in' ) && is_user_logged_in() ) {
			$target = DashboardRoutes::dashboard_path( $locale );
		} else {
			$return = function_exists( 'home_url' ) ? home_url( self::request_path() ) : self::request_path();
			$target = Shell::signin_path( $locale ) . '?redirect_to=' . rawurlencode( $return );
		}
		if ( function_exists( 'wp_safe_redirect' ) ) {
			wp_safe_redirect( function_exists( 'home_url' ) ? home_url( $target ) : $target );
			exit;
		}
	}

	/**
	 * Verifies the posted credentials through core and redirects on success.
	 *
	 * A rejected attempt re-renders the surface — never a redirect — with the
	 * generic error key, the attempted username preserved, and the password
	 * cleared: the contract's error state asks for text on the same page and
	 * the uniform message keeps the form from enumerating accounts.
	 *
	 * @param string $locale Supported locale slug.
	 */
	private static function serve_post( string $locale ): void {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized as a scalar on the next line before verification.
		$raw_nonce = isset( $_POST['_lps_signin_nonce'] ) ? wp_unslash( $_POST['_lps_signin_nonce'] ) : '';
		$nonce     = is_string( $raw_nonce ) ? sanitize_text_field( $raw_nonce ) : '';
		$state     = self::state();
		if ( ! function_exists( 'wp_verify_nonce' ) || ! wp_verify_nonce( $nonce, 'lps_signin' ) ) {
			$state['error'] = 'credentials';
			self::render( $locale, $state );
			return;
		}
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized as a scalar on the next line.
		$raw_log = isset( $_POST['log'] ) ? wp_unslash( $_POST['log'] ) : '';
		$log     = is_string( $raw_log ) ? sanitize_text_field( $raw_log ) : '';
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Passwords are verified raw by `wp_signon`, exactly like core; sanitizing would corrupt valid credentials.
		$raw_pwd = isset( $_POST['pwd'] ) ? wp_unslash( $_POST['pwd'] ) : '';
		$pwd     = is_string( $raw_pwd ) ? $raw_pwd : '';
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized as a scalar on the next line.
		$raw_remember = isset( $_POST['rememberme'] ) ? wp_unslash( $_POST['rememberme'] ) : '';
		$remember     = 'forever' === ( is_string( $raw_remember ) ? sanitize_key( $raw_remember ) : '' );
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized as a scalar on the next line.
		$raw_target           = isset( $_POST['redirect_to'] ) ? wp_unslash( $_POST['redirect_to'] ) : '';
		$redirect             = is_string( $raw_target ) ? sanitize_url( $raw_target ) : '';
		$state['redirect_to'] = $redirect;
		if ( '' === $log || '' === $pwd ) {
			$state['error']     = 'empty';
			$state['attempted'] = $log;
			self::render( $locale, $state );
			return;
		}
		$signed = function_exists( 'is_user_logged_in' ) && is_user_logged_in();
		$user   = $signed ? wp_get_current_user() : wp_signon(
			array(
				'user_login'    => $log,
				'user_password' => $pwd,
				'remember'      => $remember,
			),
			is_ssl()
		);
		if ( is_wp_error( $user ) ) {
			$state['error']     = 'credentials';
			$state['attempted'] = $log;
			self::render( $locale, $state );
			return;
		}
		$target = function_exists( 'wp_validate_redirect' )
			? wp_validate_redirect( $redirect, self::default_target( $user, $locale ) )
			: self::default_target( $user, $locale );
		if ( function_exists( 'wp_safe_redirect' ) ) {
			wp_safe_redirect( $target );
			exit;
		}
		self::render( $locale, $state );
	}

	/**
	 * Builds the surface state for the current request.
	 *
	 * @return array<string, mixed>
	 */
	private static function state(): array {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- A read-only destination parameter needs no nonce (same contract as core's redirect_to) and is sanitized on the next line.
		$raw_redirect = isset( $_GET['redirect_to'] ) ? wp_unslash( $_GET['redirect_to'] ) : '';
		$redirect     = is_string( $raw_redirect ) ? sanitize_url( $raw_redirect ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- A read-only notice key, whitelisted below.
		$raw_sso   = isset( $_GET['sso_error'] ) ? wp_unslash( $_GET['sso_error'] ) : '';
		$sso_error = is_string( $raw_sso ) ? sanitize_key( $raw_sso ) : '';
		$sso_keys  = array( 'sso', 'sso_domain', 'sso_unlinked', 'sso_state', 'sso_unavailable' );
		$state     = array(
			'error'       => in_array( $sso_error, $sso_keys, true ) ? $sso_error : '',
			'attempted'   => '',
			'redirect_to' => $redirect,
			'signed_in'   => false,
			'user_name'   => '',
			'user_role'   => '',
			'can_admin'   => false,
		);
		if ( ! function_exists( 'is_user_logged_in' ) || ! is_user_logged_in() ) {
			return $state;
		}
		$user               = wp_get_current_user();
		$roles              = array_map( 'translate_user_role', $user->roles );
		$state['signed_in'] = true;
		$state['user_name'] = $user->display_name;
		$state['user_role'] = implode( ', ', array_filter( array_map( 'strval', $roles ) ) );
		$state['can_admin'] = function_exists( 'current_user_can' ) && current_user_can( 'manage_options' );
		return $state;
	}

	/**
	 * Returns the post-login destination when the caller asked for none.
	 *
	 * Administrators continue to wp-admin — the form's audience copy names it
	 * as their destination; every other account lands on the task dashboard.
	 *
	 * @param mixed  $user   Signed-in account.
	 * @param string $locale Supported locale slug.
	 */
	public static function default_target( mixed $user, string $locale ): string {
		if ( $user instanceof \WP_User && function_exists( 'user_can' ) && user_can( $user, 'manage_options' ) ) {
			return function_exists( 'admin_url' ) ? admin_url() : '/wp-admin/';
		}
		return function_exists( 'home_url' )
			? home_url( DashboardRoutes::dashboard_path( $locale ) )
			: DashboardRoutes::dashboard_path( $locale );
	}

	/**
	 * Sends the rendered document with the surface's privacy headers.
	 *
	 * @param string               $locale Supported locale slug.
	 * @param array<string, mixed> $state  Surface state.
	 */
	private static function render( string $locale, array $state ): void {
		if ( function_exists( 'status_header' ) ) {
			status_header( 200 );
		}
		header( 'Content-Type: text/html; charset=utf-8' );
		echo AuthSurfaces::document( $locale, $state ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- the renderer returns fully escaped markup.
		exit;
	}

	/**
	 * Returns the frozen path segment of one route kind.
	 *
	 * @param string $kind   Route kind (`signin` or `member`).
	 * @param string $locale Supported locale slug.
	 */
	private static function segment( string $kind, string $locale ): string {
		$path = 'member' === $kind ? Shell::member_path( $locale ) : Shell::signin_path( $locale );
		return trim( $path, '/' ) === '' ? '' : (string) array_slice( explode( '/', trim( $path, '/' ) ), -1 )[0];
	}

	/**
	 * Guesses the locale a redirect destination belongs to.
	 *
	 * @param string $redirect Requested post-login destination.
	 */
	private static function locale_from_redirect( string $redirect ): string {
		$path = wp_parse_url( $redirect, PHP_URL_PATH );
		return is_string( $path ) && preg_match( '#^/en/#', $path ) ? 'en' : 'pt-br';
	}

	/** Whether the current request is a POST. */
	private static function is_post(): bool {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized as a scalar on the next line.
		$raw = isset( $_SERVER['REQUEST_METHOD'] ) ? wp_unslash( $_SERVER['REQUEST_METHOD'] ) : '';
		return is_string( $raw ) && 'POST' === strtoupper( sanitize_text_field( $raw ) );
	}

	/** Returns the sanitized path of the current request. */
	private static function request_path(): string {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized as a scalar on the next line.
		$raw = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '';
		$uri = is_string( $raw ) ? $raw : '';
		if ( '' === $uri ) {
			return '/';
		}
		$path = wp_parse_url( sanitize_text_field( $uri ), PHP_URL_PATH );
		return is_string( $path ) ? $path : '/';
	}
}
