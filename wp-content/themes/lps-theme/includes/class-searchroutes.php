<?php
/**
 * Frozen locale search routes, request state, and canonical URLs.
 *
 * @package LPS\Theme
 */

declare(strict_types=1);

namespace LPS\Theme;

use LPS\ContentModel\SearchIndex;
use LPS\ContentModel\SearchPolicy;
use LPS\ContentModel\SearchStorage;
use LPS\ContentModel\WpdbSearchStorage;
use wpdb;

require_once __DIR__ . '/class-searchsurfaces.php';

if ( ! class_exists( SearchPolicy::class ) ) {
	require_once dirname( __DIR__, 3 ) . '/plugins/lps-content-model/includes/class-searchindex.php';
}

/** Binds the server-rendered search surface to its frozen locale routes. */
final class SearchRoutes {
	public const LOCALE_QUERY_VAR = 'lps_search_locale';

	/**
	 * Frozen search path segment per locale.
	 *
	 * @var array<string, string>
	 */
	private const SEGMENTS = array(
		'pt-br' => 'busca',
		'en'    => 'search',
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
	 * Returns the frozen search path of one locale.
	 *
	 * @param string $locale Supported locale slug.
	 */
	public static function search_path( string $locale ): string {
		$segment = self::SEGMENTS[ $locale ] ?? '';
		return '' === $segment ? '' : '/' . $locale . '/' . $segment . '/';
	}

	/**
	 * Resolves a request path back to its search locale.
	 *
	 * @param string $path Public request path.
	 * @return array{locale: string, page: int}|null
	 */
	public static function match_path( string $path ): ?array {
		$clean = '/' . trim( $path, '/' ) . '/';
		if ( 1 !== preg_match( '#^/(pt-br|en)/([^/]+)/(?:page/([0-9]{1,})/)?$#', $clean, $parts ) ) {
			return null;
		}
		if ( self::SEGMENTS[ $parts[1] ] !== $parts[2] ) {
			return null;
		}
		return array(
			'locale' => $parts[1],
			'page'   => isset( $parts[3] ) ? max( 1, (int) $parts[3] ) : 1,
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
	 * Returns the rewrite rules of both search routes.
	 *
	 * @return array<string, string>
	 */
	public static function rewrite_rules(): array {
		$rules = array();
		foreach ( self::SEGMENTS as $locale => $segment ) {
			$base                                    = $locale . '/' . $segment;
			$query                                   = 'index.php?pagename=' . $segment . '&' . self::LOCALE_QUERY_VAR . '=' . $locale . '&lang=' . $locale;
			$rules[ $base . '/page/([0-9]{1,})/?$' ] = $query . '&paged=$matches[1]';
			$rules[ $base . '/?$' ]                  = $query;
		}
		return $rules;
	}

	/**
	 * Builds the sanitized search state of one request.
	 *
	 * Unknown parameters, unapproved facet values, and out-of-range pages are
	 * dropped here, so no request can widen the crawlable surface.
	 *
	 * @param array<mixed, mixed> $request Raw request parameters.
	 * @param string              $locale  Supported locale slug.
	 * @return array{locale: string, query: string, record: string, facets: array<string, array<int, string>>, page: int, error: string}
	 */
	public static function state_from_request( array $request, string $locale ): array {
		$raw    = is_string( $request['q'] ?? null ) ? $request['q'] : '';
		$query  = trim( (string) preg_replace( '/[\x00-\x1F\x7F]/', '', $raw ) );
		$record = is_string( $request['record'] ?? null ) ? strtolower( trim( $request['record'] ) ) : '';
		if ( ! in_array( $record, SearchPolicy::faceted_post_types(), true ) ) {
			$record = '';
		}
		$page = 1;
		if ( isset( $request['lps_page'] ) && is_string( $request['lps_page'] ) && 1 === preg_match( '/^[0-9]{1,4}$/', $request['lps_page'] ) ) {
			$page = max( 1, (int) $request['lps_page'] );
		}
		$error = '' === trim( $raw ) ? '' : (string) SearchPolicy::query_error( $raw );

		return array(
			'locale' => $locale,
			'query'  => $query,
			'record' => $record,
			'facets' => SearchPolicy::sanitize_facets( $request, SearchPolicy::facet_definitions( $record ) ),
			'page'   => $page,
			'error'  => $error,
		);
	}

	/**
	 * Builds the canonical, shareable URL of one search state.
	 *
	 * @param string               $base  Route path.
	 * @param array<string, mixed> $state Sanitized search state.
	 * @param int|null             $page  Page override.
	 */
	public static function canonical_url( string $base, array $state, ?int $page = null ): string {
		$parts  = array();
		$query  = is_string( $state['query'] ?? null ) ? trim( $state['query'] ) : '';
		$record = is_string( $state['record'] ?? null ) ? $state['record'] : '';
		$facets = self::facet_state( $state['facets'] ?? null );
		$number = null === $page ? self::number( $state['page'] ?? 1 ) : $page;

		if ( '' !== $query ) {
			$parts[] = 'q=' . rawurlencode( $query );
		}
		if ( '' !== $record ) {
			$parts[] = 'record=' . rawurlencode( $record );
		}
		ksort( $facets );
		foreach ( $facets as $facet => $values ) {
			sort( $values );
			foreach ( $values as $value ) {
				$parts[] = rawurlencode( $facet ) . '%5B%5D=' . rawurlencode( $value );
			}
		}
		// WordPress reserves the `page` query argument for paginated singular
		// content, so pagination uses the routed path segment instead.
		$path = 1 < $number ? $base . 'page/' . $number . '/' : $base;
		return array() === $parts ? $path : $path . '?' . implode( '&', $parts );
	}

	/**
	 * Returns the robots directive of one search state.
	 *
	 * @param array<string, mixed> $state Sanitized search state.
	 */
	public static function robots_directive( array $state ): string {
		$query  = is_string( $state['query'] ?? null ) ? $state['query'] : '';
		$facets = self::facet_state( $state['facets'] ?? null );
		$record = is_string( $state['record'] ?? null ) ? $state['record'] : '';
		$page   = self::number( $state['page'] ?? 1 );
		if ( '' !== $record ) {
			return 'noindex,follow';
		}
		return SearchPolicy::robots_directive( $query, $facets, $page );
	}

	/**
	 * Replaces the document language tag on search routes.
	 *
	 * @param string $output Rendered language attributes.
	 * @param string $path   Public request path.
	 */
	public static function language_attributes_for_path( string $output, string $path ): string {
		$route = self::match_path( $path );
		if ( null === $route ) {
			return $output;
		}
		$tagged = preg_replace( '/lang="[^"]*"/', 'lang="' . self::bcp47( $route['locale'] ) . '"', $output );
		return is_string( $tagged ) ? $tagged : $output;
	}

	/** Registers the search routes, query var, canonical behavior, and robots rule. */
	public static function boot(): void {
		add_filter( 'rewrite_rules_array', array( self::class, 'register_routes' ), 997 );
		add_filter( 'query_vars', array( self::class, 'register_query_vars' ) );
		add_filter( 'redirect_canonical', array( self::class, 'keep_locale_route' ), 10, 2 );
		add_filter( 'language_attributes', array( self::class, 'route_language_attributes' ), 220 );
		add_filter( 'wp_robots', array( self::class, 'route_robots' ) );
		add_action( 'parse_request', array( self::class, 'alias_core_search_param' ) );
	}

	/**
	 * Prepends the search rewrite rules.
	 *
	 * @param array<string, string> $rules Registered rewrite rules.
	 * @return array<string, string>
	 */
	public static function register_routes( array $rules ): array {
		return array_merge( self::rewrite_rules(), $rules );
	}

	/**
	 * Registers the search locale query var.
	 *
	 * @param array<int, string> $vars Registered public query vars.
	 * @return array<int, string>
	 */
	public static function register_query_vars( array $vars ): array {
		$vars[] = self::LOCALE_QUERY_VAR;
		return $vars;
	}

	/**
	 * Keeps search routes stable instead of redirecting to the page permalink.
	 *
	 * @param string|false $redirect_url  Proposed canonical URL.
	 * @param string       $requested_url Requested URL.
	 * @return string|false
	 */
	public static function keep_locale_route( string|false $redirect_url, string $requested_url ): string|false {
		$path = wp_parse_url( $requested_url, PHP_URL_PATH );
		if ( is_string( $path ) && null !== self::match_path( $path ) ) {
			return false;
		}
		return $redirect_url;
	}

	/**
	 * Aliases the core `s` parameter onto the custom search state on search routes.
	 *
	 * The locale search surface speaks `q`; a bare `s` keeps the same meaning for
	 * tools and visitors arriving through WordPress's native search convention.
	 */
	public static function alias_core_search_param(): void {
		$route = self::match_path( self::request_path() );
		if ( null === $route ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only public search alias.
		$core = isset( $_GET['s'] ) && is_string( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
		if ( '' === $core ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only public search alias.
		$unslashed = wp_unslash( $_GET );
		$request   = array();
		foreach ( $unslashed as $key => $value ) {
			$request[ (string) $key ] = $value;
		}
		if ( 1 < $route['page'] ) {
			$request['lps_page'] = (string) $route['page'];
		}
		if ( ! is_string( $request['q'] ?? null ) || '' === trim( $request['q'] ) ) {
			$request['q'] = $core;
		}
		$state  = self::state_from_request( $request, $route['locale'] );
		$target = home_url( self::canonical_url( self::search_path( $route['locale'] ), $state ) );
		if ( function_exists( 'wp_safe_redirect' ) ) {
			wp_safe_redirect( $target, 301 );
			exit;
		}
	}

	/**
	 * Applies the route language tag to the current request.
	 *
	 * @param string $output Rendered language attributes.
	 */
	public static function route_language_attributes( string $output ): string {
		return self::language_attributes_for_path( $output, self::request_path() );
	}

	/**
	 * Keeps filtered and paginated search states out of the crawlable index.
	 *
	 * @param array<string, mixed> $robots Robots directives.
	 * @return array<string, mixed>
	 */
	public static function route_robots( array $robots ): array {
		$route = self::match_path( self::request_path() );
		if ( null === $route ) {
			return $robots;
		}
		$state = self::current_state( $route['locale'], $route['page'] );
		if ( 'noindex,follow' === self::robots_directive( $state ) ) {
			$robots['noindex'] = true;
			$robots['follow']  = true;
		}
		return $robots;
	}

	/** Renders the search surface for the current request. */
	public static function render_block(): string {
		$route = self::match_path( self::request_path() );
		if ( null === $route ) {
			return '';
		}
		$locale  = $route['locale'];
		$state   = self::current_state( $locale, $route['page'] );
		$action  = self::search_path( $locale );
		$storage = self::storage();
		if ( null === $storage ) {
			return SearchSurfaces::render( $state, self::empty_result( $state ), array(), SearchPolicy::facet_definitions( $state['record'] ), $locale, $action );
		}
		if ( '' !== $state['error'] || '' === $state['query'] ) {
			$rows = $storage->rows_for( $locale, $state['record'] );
			return SearchSurfaces::render( $state, self::empty_result( $state ), SearchPolicy::facet_counts( $rows, SearchPolicy::facet_definitions( $state['record'] ) ), SearchPolicy::facet_definitions( $state['record'] ), $locale, $action );
		}
		$rows   = $storage->rows_for( $locale, $state['record'] );
		$result = SearchPolicy::search_records( $rows, $state['query'], $locale, $state['facets'], $state['page'], SearchPolicy::PER_PAGE );
		$counts = SearchPolicy::facet_counts( $rows, SearchPolicy::facet_definitions( $state['record'] ) );
		return SearchSurfaces::render( $state, $result, $counts, SearchPolicy::facet_definitions( $state['record'] ), $locale, $action );
	}

	/**
	 * Returns the canonical link URL of the current search request.
	 *
	 * @param string $canonical Proposed canonical URL.
	 */
	public static function canonical_link( string $canonical ): string {
		$route = self::match_path( self::request_path() );
		if ( null === $route ) {
			return $canonical;
		}
		$state = self::current_state( $route['locale'], $route['page'] );
		return home_url( self::canonical_url( self::search_path( $route['locale'] ), $state ) );
	}

	/**
	 * Returns the sanitized state of the current request.
	 *
	 * @param string $locale Supported locale slug.
	 * @param int    $page   Page taken from the route path.
	 * @return array{locale: string, query: string, record: string, facets: array<string, array<int, string>>, page: int, error: string}
	 */
	private static function current_state( string $locale, int $page ): array {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only public search state.
		$unslashed = wp_unslash( $_GET );
		$request   = array();
		foreach ( $unslashed as $key => $value ) {
			$request[ (string) $key ] = $value;
		}
		if ( 1 < $page ) {
			$request['lps_page'] = (string) $page;
		}
		return self::state_from_request( $request, $locale );
	}

	/**
	 * Returns an empty result payload for one state.
	 *
	 * @param array<string, mixed> $state Sanitized search state.
	 * @return array{items: array<int, array<string, mixed>>, total: int, page: int, per_page: int, pages: int, error: string|null}
	 */
	private static function empty_result( array $state ): array {
		$error = is_string( $state['error'] ?? null ) && '' !== $state['error'] ? $state['error'] : null;
		return array(
			'items'    => array(),
			'total'    => 0,
			'page'     => self::number( $state['page'] ?? 1 ),
			'per_page' => SearchPolicy::PER_PAGE,
			'pages'    => 0,
			'error'    => $error,
		);
	}

	/** Returns the plugin-owned index storage, or null when it is unavailable. */
	private static function storage(): ?SearchStorage {
		global $wpdb;
		if ( ! class_exists( SearchIndex::class ) || ! $wpdb instanceof wpdb ) {
			return null;
		}
		if ( ! class_exists( WpdbSearchStorage::class ) ) {
			require_once dirname( __DIR__, 3 ) . '/plugins/lps-content-model/includes/class-wpdbsearchstorage.php';
		}
		return new WpdbSearchStorage( $wpdb );
	}

	/**
	 * Converts boundary input to int.
	 *
	 * @param mixed $value Boundary input.
	 */
	private static function number( mixed $value ): int {
		return is_numeric( $value ) ? (int) $value : 0;
	}

	/**
	 * Normalizes untrusted facet state.
	 *
	 * @param mixed $value Boundary input.
	 * @return array<string, array<int, string>>
	 */
	private static function facet_state( mixed $value ): array {
		if ( ! is_array( $value ) ) {
			return array();
		}
		$state = array();
		foreach ( $value as $facet => $values ) {
			if ( ! is_string( $facet ) || ! is_array( $values ) ) {
				continue;
			}
			$list = array();
			foreach ( $values as $item ) {
				if ( is_string( $item ) ) {
					$list[] = $item;
				}
			}
			$state[ $facet ] = $list;
		}
		return $state;
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
