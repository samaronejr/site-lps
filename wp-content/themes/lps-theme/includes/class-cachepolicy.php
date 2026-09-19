<?php
/**
 * Anonymous response cache policy and publish-time invalidation targets.
 *
 * @package LPS\Theme
 */

declare(strict_types=1);

namespace LPS\Theme;

/**
 * Decides what a shared cache may store and what a publish must purge.
 *
 * Nothing personalized is ever shareable: authenticated requests, admin screens, login,
 * previews, nonce-bearing URLs, unsafe methods and error responses are all marked
 * `private, no-store`. Only anonymous public HTML and the approved query/facet space are
 * cacheable, and every cacheable response carries surrogate keys so a publish can expire
 * exactly the affected URLs instead of flushing the site.
 */
final class CachePolicy {
	/** Shared TTL for anonymous HTML documents, in seconds. */
	public const HTML_TTL = 300;

	/** Shared TTL for anonymous query, facet and search results, in seconds. */
	public const QUERY_TTL = 60;

	/** Shared TTL for localized 404 responses, in seconds. */
	public const NOT_FOUND_TTL = 60;

	/** Stale-while-revalidate window offered to the shared cache, in seconds. */
	public const STALE_WHILE_REVALIDATE = 60;

	/** Query keys that address a real public state and may therefore be cached. */
	private const ALLOWED_QUERY_KEYS = array( 'category', 'domain', 'page', 'q', 'record', 'status', 'type', 'year', 'area', 'term', 'level', 'instructor', 'language' );

	/** Query keys that always mark a personalized or unpublished view. */
	private const PRIVATE_QUERY_KEYS = array( '_wpnonce', 'preview', 'preview_id', 'preview_nonce', 'p', 'customize_changeset_uuid' );

	/** Cookie prefixes that identify a known browser. */
	private const PRIVATE_COOKIE_PREFIXES = array( 'wordpress_logged_in_', 'wordpress_sec_', 'comment_author_', 'wp-postpass_' );

	/** Path prefixes that must never be shared. */
	private const PRIVATE_PATHS = array( '/wp-admin/', '/wp-login.php', '/wp-json/wp/v2/users', '/wp-cron.php' );

	/** Locale-specific search entry points purged with every publish. */
	private const SEARCH_ENTRY = array(
		'pt-br' => 'busca',
		'en'    => 'search',
	);

	/**
	 * Evaluates one response for shared cacheability.
	 *
	 * @param array{method?: string, path?: string, status?: int, query?: array<string, mixed>, cookies?: array<int, string>, is_admin?: bool, logged_in?: bool} $request Request facts.
	 *
	 * @return array{cacheable: bool, reason: string, ttl: int, headers: array<string, string>, surrogate_keys: array<int, string>}
	 */
	public static function decide( array $request ): array {
		$method  = strtoupper( (string) ( $request['method'] ?? 'GET' ) );
		$path    = (string) ( $request['path'] ?? '/' );
		$status  = (int) ( $request['status'] ?? 200 );
		$query   = (array) ( $request['query'] ?? array() );
		$cookies = array_values( array_filter( (array) ( $request['cookies'] ?? array() ), 'is_string' ) );

		if ( ! in_array( $method, array( 'GET', 'HEAD' ), true ) ) {
			return self::private_decision( 'unsafe-method' );
		}
		if ( true === ( $request['is_admin'] ?? false ) || self::is_private_path( $path ) ) {
			return self::private_decision( 'private-request' );
		}
		if ( true === ( $request['logged_in'] ?? false ) || self::has_private_cookie( $cookies ) ) {
			return self::private_decision( 'authenticated' );
		}
		foreach ( self::PRIVATE_QUERY_KEYS as $key ) {
			if ( array_key_exists( $key, $query ) ) {
				return self::private_decision( 'preview' );
			}
		}
		if ( ! in_array( $status, array( 200, 404 ), true ) ) {
			return self::private_decision( 'error-status' );
		}
		foreach ( array_keys( $query ) as $key ) {
			if ( ! in_array( (string) $key, self::ALLOWED_QUERY_KEYS, true ) ) {
				return self::private_decision( 'unapproved-query' );
			}
		}

		if ( 404 === $status ) {
			return self::shared_decision( 'not-found', self::NOT_FOUND_TTL, $path );
		}
		if ( array() !== $query ) {
			return self::shared_decision( 'anonymous-query', self::QUERY_TTL, $path );
		}

		return self::shared_decision( 'anonymous-html', self::HTML_TTL, $path );
	}

	/**
	 * Lists the canonical public URLs a publish must expire.
	 *
	 * @param array{permalink?: string, archives?: array<int, string>, translations?: array<int, string>, terms?: array<int, string>, related?: array<int, string>, locales?: array<int, string>} $record Changed record.
	 *
	 * @return array<int, string>
	 */
	public static function purge_targets( array $record ): array {
		$permalink = trim( (string) ( $record['permalink'] ?? '' ) );
		if ( '' === $permalink ) {
			return array();
		}

		$origin  = self::origin( $permalink );
		$targets = array( $permalink );
		foreach ( array( 'archives', 'translations', 'terms', 'related' ) as $group ) {
			foreach ( $record[ $group ] ?? array() as $url ) {
				if ( '' !== trim( $url ) ) {
					$targets[] = trim( $url );
				}
			}
		}
		foreach ( $record['locales'] ?? array() as $raw_locale ) {
			$locale = strtolower( $raw_locale );
			if ( '' === $locale || ! isset( self::SEARCH_ENTRY[ $locale ] ) ) {
				continue;
			}
			$targets[] = $origin . '/' . $locale . '/';
			$targets[] = $origin . '/' . $locale . '/' . self::SEARCH_ENTRY[ $locale ] . '/';
		}
		$targets[] = $origin . '/sitemap.xml';

		$targets = array_values(
			array_unique(
				array_filter(
					$targets,
					static fn( string $url ): bool => ! str_contains( $url, '/wp-admin/' ) && ! str_contains( $url, '/wp-login.php' ) && ! str_contains( $url, '?' )
				)
			)
		);
		sort( $targets );

		return $targets;
	}

	/**
	 * Builds the surrogate keys attached to a shared response.
	 *
	 * @param string $path Public request path.
	 *
	 * @return array<int, string>
	 */
	public static function surrogate_keys( string $path ): array {
		return array( 'lps-html', 'lps-locale-' . self::locale_of( $path ) );
	}

	/**
	 * Extracts the locale segment addressed by a public path.
	 *
	 * @param string $path Public request path.
	 */
	public static function locale_of( string $path ): string {
		$first   = strtok( ltrim( $path, '/' ), '/' );
		$segment = strtolower( is_string( $first ) ? $first : '' );

		return isset( self::SEARCH_ENTRY[ $segment ] ) ? $segment : 'pt-br';
	}

	/**
	 * Builds a shareable decision.
	 *
	 * @param string $reason Decision reason.
	 * @param int    $ttl    Shared TTL in seconds.
	 * @param string $path   Public request path.
	 *
	 * @return array{cacheable: bool, reason: string, ttl: int, headers: array<string, string>, surrogate_keys: array<int, string>}
	 */
	private static function shared_decision( string $reason, int $ttl, string $path ): array {
		return array(
			'cacheable'      => true,
			'reason'         => $reason,
			'ttl'            => $ttl,
			'headers'        => array(
				'Cache-Control' => sprintf( 'public, max-age=0, s-maxage=%d, stale-while-revalidate=%d', $ttl, self::STALE_WHILE_REVALIDATE ),
				'Vary'          => 'Accept-Encoding',
			),
			'surrogate_keys' => self::surrogate_keys( $path ),
		);
	}

	/**
	 * Builds a never-shared decision.
	 *
	 * @param string $reason Decision reason.
	 *
	 * @return array{cacheable: bool, reason: string, ttl: int, headers: array<string, string>, surrogate_keys: array<int, string>}
	 */
	private static function private_decision( string $reason ): array {
		return array(
			'cacheable'      => false,
			'reason'         => $reason,
			'ttl'            => 0,
			'headers'        => array(
				'Cache-Control' => 'private, no-store, max-age=0',
				'Vary'          => 'Accept-Encoding, Cookie',
			),
			'surrogate_keys' => array(),
		);
	}

	/**
	 * Reports whether the path belongs to a never-shared surface.
	 *
	 * @param string $path Public request path.
	 */
	private static function is_private_path( string $path ): bool {
		foreach ( self::PRIVATE_PATHS as $prefix ) {
			if ( str_starts_with( $path, $prefix ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Reports whether any cookie identifies a known browser.
	 *
	 * @param array<int, string> $cookies Cookie names.
	 */
	private static function has_private_cookie( array $cookies ): bool {
		foreach ( $cookies as $cookie ) {
			foreach ( self::PRIVATE_COOKIE_PREFIXES as $prefix ) {
				if ( str_starts_with( $cookie, $prefix ) ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Extracts the scheme and host of a canonical URL.
	 *
	 * @param string $url Canonical URL.
	 */
	private static function origin( string $url ): string {
		$parts  = wp_parse_url( $url );
		$parts  = is_array( $parts ) ? $parts : array();
		$scheme = isset( $parts['scheme'] ) && is_string( $parts['scheme'] ) ? $parts['scheme'] : 'https';
		$host   = isset( $parts['host'] ) && is_string( $parts['host'] ) ? $parts['host'] : '';
		$port   = isset( $parts['port'] ) && is_int( $parts['port'] ) ? ':' . (string) $parts['port'] : '';

		return '' === $host ? '' : $scheme . '://' . $host . $port;
	}
}
