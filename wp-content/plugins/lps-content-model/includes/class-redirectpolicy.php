<?php
/**
 * Deterministic one-hop redirect graph validation.
 *
 * @package LPS\ContentModel
 */

declare(strict_types=1);

namespace LPS\ContentModel;

/**
 * Validates redirects and deliberate HTTP 410 routes without network access.
 *
 * @phpstan-type RedirectError array{code: string, source: string, target: string}
 */
final class RedirectPolicy {
	/**
	 * Returns every graph violation in deterministic order.
	 *
	 * @param array<int, array<string, mixed>> $redirects Candidate routes.
	 * @param string                           $site_url Canonical site URL.
	 * @return array<int, array{code: string, source: string, target: string}>
	 * @phpstan-return list<RedirectError>
	 */
	public static function verify( array $redirects, string $site_url ): array {
		/**
		 * Graph errors.
		 *
		 * @var list<RedirectError> $errors
		 */
		$errors = array();
		/**
		 * One-hop graph indexed by normalized source.
		 *
		 * @var array<string, string> $graph
		 */
		$graph = array();
		$seen  = array();
		foreach ( $redirects as $redirect ) {
			$source = self::source( self::text( $redirect['source'] ?? '' ) );
			$target = self::target( self::text( $redirect['target'] ?? '' ), $site_url );
			$gone   = true === ( $redirect['gone'] ?? false ) || 410 === Policy::sanitize_integer( $redirect['status'] ?? 0 );
			if ( '' === $source ) {
				$errors[] = self::error( 'lps_redirect_invalid_source', $source, $target );
				continue;
			}
			if ( isset( $seen[ $source ] ) ) {
				$errors[] = self::error( 'lps_redirect_duplicate_source', $source, $target );
			}
			$seen[ $source ] = true;
			if ( $gone ) {
				if ( '' !== self::text( $redirect['target'] ?? '' ) || 410 !== Policy::sanitize_integer( $redirect['status'] ?? 0 ) ) {
					$errors[] = self::error( 'lps_redirect_invalid_gone', $source, $target );
				}
				continue;
			}
			if ( '' === $target ) {
				$errors[] = self::error( 'lps_redirect_unsafe_target', $source, self::text( $redirect['target'] ?? '' ) );
				continue;
			}
			if ( 301 !== Policy::sanitize_integer( $redirect['status'] ?? 0 ) ) {
				$errors[] = self::error( 'lps_redirect_invalid_status', $source, $target );
			}
			if ( ! isset( $graph[ $source ] ) ) {
				$graph[ $source ] = $target;
			}
		}
		foreach ( $graph as $source => $target ) {
			if ( isset( $graph[ $target ] ) ) {
				$errors[] = self::error( 'lps_redirect_chain', $source, $target );
			}
			if ( self::reaches( $target, $source, $graph ) ) {
				$errors[] = self::error( 'lps_redirect_loop', $source, $target );
			}
		}
		usort( $errors, static fn ( array $left, array $right ): int => array( $left['code'], $left['source'], $left['target'] ) <=> array( $right['code'], $right['source'], $right['target'] ) );
		return $errors;
	}

	/**
	 * Normalizes a redirect source to lowercase host-independent route form.
	 *
	 * @param string $source Source.
	 */
	public static function source( string $source ): string {
		if ( str_starts_with( $source, 'http://' ) || str_starts_with( $source, 'https://' ) ) {
			$parts = wp_parse_url( $source );
			if ( ! is_array( $parts ) ) {
				return '';
			}
			$source = self::text( $parts['path'] ?? '/' ) . ( isset( $parts['query'] ) ? '?' . self::text( $parts['query'] ) : '' );
		}
		if ( ! str_starts_with( $source, '/' ) || str_contains( $source, "\n" ) || str_contains( $source, "\r" ) ) {
			return '';
		}
		$parts = explode( '?', $source, 2 );
		$path  = MigrationPolicy::normalize_path( $parts[0] );
		if ( '/' !== $path && ! str_contains( basename( $path ), '.' ) ) {
			$path = rtrim( $path, '/' ) . '/';
		}
		return $path . ( isset( $parts[1] ) && '' !== $parts[1] ? '?' . $parts[1] : '' );
	}

	/**
	 * Returns a safe local target, converting canonical-host absolute URLs to paths.
	 *
	 * @param string $target   Target.
	 * @param string $site_url Site URL.
	 */
	public static function target( string $target, string $site_url ): string {
		if ( str_starts_with( $target, '/' ) ) {
			return self::source( $target );
		}
		$target_parts = wp_parse_url( $target );
		$site_parts   = wp_parse_url( $site_url );
		if ( ! is_array( $target_parts ) || ! is_array( $site_parts ) ) {
			return '';
		}
		$target_host = strtolower( self::text( $target_parts['host'] ?? '' ) );
		$site_host   = strtolower( self::text( $site_parts['host'] ?? '' ) );
		if ( '' === $target_host || '' === $site_host || ! hash_equals( $site_host, $target_host ) ) {
			return '';
		}
		return self::source( self::text( $target_parts['path'] ?? '/' ) . ( isset( $target_parts['query'] ) ? '?' . self::text( $target_parts['query'] ) : '' ) );
	}

	/**
	 * Checks graph reachability.
	 *
	 * @param string                $current Current node.
	 * @param string                $wanted  Wanted node.
	 * @param array<string, string> $graph   Redirect graph.
	 */
	private static function reaches( string $current, string $wanted, array $graph ): bool {
		$visited = array();
		while ( isset( $graph[ $current ] ) ) {
			if ( hash_equals( $current, $wanted ) ) {
				return true;
			}
			if ( isset( $visited[ $current ] ) ) {
				return false;
			}
			$visited[ $current ] = true;
			$current             = $graph[ $current ];
		}
		return hash_equals( $current, $wanted );
	}

	/**
	 * Builds a graph error.
	 *
	 * @param string $code   Code.
	 * @param string $source Source.
	 * @param string $target Target.
	 * @return array{code: string, source: string, target: string}
	 */
	private static function error( string $code, string $source, string $target ): array {
		return array(
			'code'   => $code,
			'source' => $source,
			'target' => $target,
		);
	}

	/**
	 * Converts a boundary scalar.
	 *
	 * @param mixed $value Value.
	 */
	private static function text( mixed $value ): string {
		return is_scalar( $value ) ? trim( (string) $value ) : '';
	}
}
