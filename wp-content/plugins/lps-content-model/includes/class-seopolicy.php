<?php
/**
 * Canonical URL, locale alternate, robots, sitemap, and redirect policy.
 *
 * @package LPS\ContentModel
 */

declare(strict_types=1);

namespace LPS\ContentModel;

/**
 * Owns every indexation decision taken by the public surface.
 *
 * Canonical addresses are derived from the request path so no page can point at
 * a different document than itself, alternates are emitted only when both sides
 * of a locale pair are actually published, and legacy sources always resolve in
 * a single hop or a deliberate `410`.
 */
final class SeoPolicy {
	/** Institutional suffix appended to every document title. */
	public const TITLE_SUFFIX = 'LPS/UFRJ';

	/** Separator between title segments. */
	private const TITLE_SEPARATOR = ' — ';

	/** Maximum rendered length of a meta description. */
	public const DESCRIPTION_MAX_LENGTH = 160;

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
	 * Open Graph locale for each supported locale slug.
	 *
	 * @var array<string, string>
	 */
	private const OPEN_GRAPH_LOCALES = array(
		'pt-br' => 'pt_BR',
		'en'    => 'en_US',
	);

	/** Locale whose published address answers `x-default`. */
	public const DEFAULT_LOCALE = 'pt-br';

	/**
	 * Document states that remove a page from the index.
	 *
	 * `unavailable` covers an address the public surface refuses to serve, such
	 * as a withheld person, a hidden partner, or an editorial page that was
	 * never published: listing one would advertise a 404 to every crawler.
	 *
	 * @var array<int, string>
	 */
	private const NONINDEXABLE_STATES = array( 'draft', 'preview', 'search', 'filtered', 'expired', 'private', 'unavailable' );

	/**
	 * Returns the supported locale slugs in authoritative order.
	 *
	 * @return array<int, string>
	 */
	public static function locales(): array {
		return array_keys( self::LANGUAGE_TAGS );
	}

	/**
	 * Returns the BCP47 language tag for a locale slug, or an empty string.
	 *
	 * @param string $locale Locale slug.
	 */
	public static function bcp47( string $locale ): string {
		return self::LANGUAGE_TAGS[ strtolower( trim( $locale ) ) ] ?? '';
	}

	/**
	 * Returns the Open Graph locale for a locale slug.
	 *
	 * @param string $locale Locale slug.
	 */
	public static function open_graph_locale( string $locale ): string {
		return self::OPEN_GRAPH_LOCALES[ strtolower( trim( $locale ) ) ] ?? self::OPEN_GRAPH_LOCALES[ self::DEFAULT_LOCALE ];
	}

	/**
	 * Builds the absolute canonical URL of a public path.
	 *
	 * The query string is dropped so no filtered or session-specific address can
	 * ever become canonical.
	 *
	 * @param string $site_url Canonical site URL.
	 * @param string $path     Public request path or absolute URL.
	 */
	public static function canonical_url( string $site_url, string $path ): string {
		$origin = rtrim( trim( $site_url ), '/' );
		return $origin . self::canonical_path( $path );
	}

	/**
	 * Normalizes a public path to its lowercase trailing-slashed form.
	 *
	 * @param string $path Public request path or absolute URL.
	 */
	public static function canonical_path( string $path ): string {
		$value = trim( $path );
		if ( str_starts_with( $value, 'http://' ) || str_starts_with( $value, 'https://' ) ) {
			$parsed = wp_parse_url( $value, PHP_URL_PATH );
			$value  = is_string( $parsed ) ? $parsed : '';
		}
		$value = (string) strtok( $value, '?#' );
		$value = strtolower( trim( $value ) );
		$value = '/' . trim( $value, '/' );
		if ( '/' === $value ) {
			return '/';
		}
		return str_contains( basename( $value ), '.' ) ? $value : $value . '/';
	}

	/**
	 * Returns reciprocal locale alternates, or nothing when the pair is broken.
	 *
	 * A single published locale cannot be reciprocal, so it publishes no
	 * `hreflang` at all rather than a self-referential half pair.
	 *
	 * @param string                              $site_url Canonical site URL.
	 * @param array<string, array<string, mixed>> $variants Locale-keyed variants.
	 * @return array<int, array{hreflang: string, href: string}>
	 */
	public static function alternates( string $site_url, array $variants ): array {
		$published = array();
		foreach ( self::locales() as $locale ) {
			$variant = $variants[ $locale ] ?? null;
			if ( ! is_array( $variant ) || true !== ( $variant['published'] ?? false ) ) {
				continue;
			}
			$path = is_string( $variant['path'] ?? null ) ? trim( $variant['path'] ) : '';
			if ( '' === $path ) {
				continue;
			}
			$published[ $locale ] = self::canonical_url( $site_url, $path );
		}
		if ( count( $published ) < 2 ) {
			return array();
		}
		$alternates = array();
		foreach ( $published as $locale => $href ) {
			$alternates[] = array(
				'hreflang' => self::bcp47( $locale ),
				'href'     => $href,
			);
		}
		$alternates[] = array(
			'hreflang' => 'x-default',
			'href'     => $published[ self::DEFAULT_LOCALE ] ?? reset( $published ),
		);
		return $alternates;
	}

	/**
	 * Reports whether a document state may enter the index.
	 *
	 * @param array<string, mixed> $state Document state flags.
	 */
	public static function is_indexable( array $state ): bool {
		foreach ( self::NONINDEXABLE_STATES as $flag ) {
			if ( true === ( $state[ $flag ] ?? false ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Returns the robots directive for a document state.
	 *
	 * @param array<string, mixed> $state Document state flags.
	 */
	public static function robots_directive( array $state ): string {
		return self::is_indexable( $state ) ? 'index, follow' : 'noindex, follow';
	}

	/**
	 * Reports whether a document belongs in a locale sitemap.
	 *
	 * @param string               $site_url Canonical site URL.
	 * @param array<string, mixed> $document Document descriptor.
	 */
	public static function in_sitemap( string $site_url, array $document ): bool {
		$state = self::flags( $document['state'] ?? null );
		if ( ! self::is_indexable( $state ) ) {
			return false;
		}
		$path      = is_string( $document['path'] ?? null ) ? $document['path'] : '';
		$canonical = is_string( $document['canonical'] ?? null ) ? $document['canonical'] : '';
		if ( '' === $path || '' === $canonical ) {
			return false;
		}
		return self::canonical_url( $site_url, $path ) === $canonical;
	}

	/**
	 * Resolves a legacy source into a single hop or a deliberate removal.
	 *
	 * A chain is flattened to its final destination so a crawler never follows
	 * two hops, and a cycle refuses to resolve instead of looping.
	 *
	 * @param array<string, array<string, mixed>> $graph  Source-keyed redirect graph.
	 * @param string                              $source Requested legacy source.
	 * @return array{status: int, target: string, hops: int}
	 */
	public static function resolve_redirect( array $graph, string $source ): array {
		$current = $source;
		$rule    = $graph[ $current ] ?? null;
		if ( ! is_array( $rule ) ) {
			return array(
				'status' => 404,
				'target' => '',
				'hops'   => 0,
			);
		}
		if ( 410 === self::status( $rule['status'] ?? 0 ) ) {
			return array(
				'status' => 410,
				'target' => '',
				'hops'   => 0,
			);
		}
		$visited = array( $current => true );
		$target  = is_string( $rule['target'] ?? null ) ? trim( $rule['target'] ) : '';
		while ( '' !== $target && isset( $graph[ $target ] ) ) {
			if ( isset( $visited[ $target ] ) ) {
				return array(
					'status' => 404,
					'target' => '',
					'hops'   => 0,
				);
			}
			$visited[ $target ] = true;
			$next               = $graph[ $target ];
			if ( 410 === self::status( $next['status'] ?? 0 ) ) {
				return array(
					'status' => 410,
					'target' => '',
					'hops'   => 0,
				);
			}
			$target = is_string( $next['target'] ?? null ) ? trim( $next['target'] ) : '';
		}
		if ( '' === $target ) {
			return array(
				'status' => 404,
				'target' => '',
				'hops'   => 0,
			);
		}
		return array(
			'status' => 301,
			'target' => $target,
			'hops'   => 1,
		);
	}

	/**
	 * Builds a unique localized document title.
	 *
	 * @param string $record_title Record or page title.
	 * @param string $section      Localized section label.
	 * @param string $locale       Locale slug.
	 */
	public static function document_title( string $record_title, string $section, string $locale ): string {
		unset( $locale );
		$segments = array();
		foreach ( array( $record_title, $section, self::TITLE_SUFFIX ) as $segment ) {
			$clean = self::collapse( $segment );
			if ( '' !== $clean && ! in_array( $clean, $segments, true ) ) {
				$segments[] = $clean;
			}
		}
		return implode( self::TITLE_SEPARATOR, $segments );
	}

	/**
	 * Builds a single-line bounded meta description.
	 *
	 * @param string $summary Editorial summary.
	 * @param int    $maximum Maximum rendered length.
	 */
	public static function meta_description( string $summary, int $maximum = self::DESCRIPTION_MAX_LENGTH ): string {
		$clean = self::collapse( $summary );
		if ( mb_strlen( $clean ) <= $maximum ) {
			return $clean;
		}
		$cut     = mb_substr( $clean, 0, $maximum - 1 );
		$boundary = mb_strrpos( $cut, ' ' );
		if ( false !== $boundary && $boundary > 0 ) {
			$cut = mb_substr( $cut, 0, $boundary );
		}
		return rtrim( $cut, " \t\n\r\0\x0B.,;:" ) . '…';
	}

	/**
	 * Collapses whitespace and strips markup from a boundary string.
	 *
	 * @param string $value Untrusted text.
	 */
	private static function collapse( string $value ): string {
		$stripped = wp_strip_all_tags( $value );
		$collapsed = preg_replace( '/\s+/u', ' ', $stripped );
		return trim( is_string( $collapsed ) ? $collapsed : $stripped );
	}

	/**
	 * Narrows an untyped descriptor branch to string-keyed flags.
	 *
	 * @param mixed $value Untyped descriptor branch.
	 * @return array<string, mixed>
	 */
	private static function flags( mixed $value ): array {
		if ( ! is_array( $value ) ) {
			return array();
		}
		$flags = array();
		foreach ( $value as $key => $flag ) {
			$flags[ (string) $key ] = $flag;
		}
		return $flags;
	}

	/**
	 * Narrows an untyped redirect status to an integer.
	 *
	 * @param mixed $value Untyped status.
	 */
	private static function status( mixed $value ): int {
		return is_numeric( $value ) ? (int) $value : 0;
	}
}
