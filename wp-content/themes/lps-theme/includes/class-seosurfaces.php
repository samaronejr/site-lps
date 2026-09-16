<?php
/**
 * Head metadata, sitemap, feed, and robots rendering.
 *
 * @package LPS\Theme
 */

declare(strict_types=1);

namespace LPS\Theme;

use LPS\ContentModel\SeoPolicy;
use LPS\ContentModel\StructuredData;

require_once dirname( __DIR__, 3 ) . '/plugins/lps-content-model/includes/class-seopolicy.php';
require_once dirname( __DIR__, 3 ) . '/plugins/lps-content-model/includes/class-structureddata.php';

/**
 * Renders every machine-readable document published by the public surface.
 *
 * The head carries exactly one title, description, canonical link, robots
 * directive, and JSON-LD block, so a crawler never receives two contradictory
 * statements about the same address.
 */
final class SeoSurfaces {
	/**
	 * Paths that must never be crawled.
	 *
	 * @var array<int, string>
	 */
	private const DISALLOWED = array(
		'/wp-admin/',
		'/wp-login.php',
		'/pt-br/busca/',
		'/en/search/',
		'/*?*',
	);

	/**
	 * Renders the complete head metadata block for one document.
	 *
	 * @param array<string, mixed> $document Document descriptor.
	 */
	public static function head_markup( array $document ): string {
		$site_url    = self::text( $document['site_url'] ?? '' );
		$locale      = self::text( $document['locale'] ?? SeoPolicy::DEFAULT_LOCALE );
		$path        = self::text( $document['path'] ?? '/' );
		$canonical   = SeoPolicy::canonical_url( $site_url, $path );
		$title       = self::text( $document['title'] ?? '' );
		$description = self::text( $document['description'] ?? '' );
		$state       = self::branch( $document['state'] ?? null );
		$variants    = self::variants( $document['variants'] ?? null );
		$og_type     = self::text( $document['og_type'] ?? '' );
		$site_name   = self::text( $document['site_name'] ?? 'LPS/UFRJ' );

		$markup = '';
		if ( false !== ( $document['title_tag'] ?? true ) ) {
			$markup .= '<title>' . self::escape( $title ) . '</title>';
		}
		if ( '' !== $description ) {
			$markup .= '<meta name="description" content="' . self::escape( $description ) . '">';
		}
		if ( false !== ( $document['robots'] ?? true ) ) {
			$markup .= '<meta name="robots" content="' . self::escape( SeoPolicy::robots_directive( $state ) ) . '">';
		}
		$markup .= '<link rel="canonical" href="' . self::escape( $canonical ) . '">';
		$markup .= self::icon_links();
		foreach ( SeoPolicy::alternates( $site_url, $variants ) as $alternate ) {
			$markup .= '<link rel="alternate" hreflang="' . self::escape( $alternate['hreflang'] )
				. '" href="' . self::escape( $alternate['href'] ) . '">';
		}
		$markup .= '<meta property="og:type" content="' . self::escape( '' === $og_type ? 'website' : $og_type ) . '">';
		$markup .= '<meta property="og:url" content="' . self::escape( $canonical ) . '">';
		$markup .= '<meta property="og:title" content="' . self::escape( $title ) . '">';
		if ( '' !== $description ) {
			$markup .= '<meta property="og:description" content="' . self::escape( $description ) . '">';
		}
		$markup .= '<meta property="og:site_name" content="' . self::escape( $site_name ) . '">';
		$markup .= '<meta property="og:locale" content="' . self::escape( SeoPolicy::open_graph_locale( $locale ) ) . '">';
		$image   = self::text( $document['image'] ?? '' );
		if ( '' !== $image ) {
			$markup .= '<meta property="og:image" content="' . self::escape( $image ) . '">';
			foreach ( array( 'width', 'height', 'alt' ) as $field ) {
				$value = self::text( $document[ 'image_' . $field ] ?? '' );
				if ( '' !== $value ) {
					$markup .= '<meta property="og:image:' . $field . '" content="' . self::escape( $value ) . '">';
				}
			}
		}
		$record = is_array( $document['record'] ?? null ) ? $document['record'] : array();
		foreach ( array( 'type', 'kind' ) as $key ) {
			$value = self::text( $record[ $key ] ?? '' );
			if ( '' !== $value ) {
				$markup .= '<meta name="lps:record-' . $key . '" content="' . self::escape( $value ) . '">';
			}
		}
		$graph = self::branch( $document['graph'] ?? null );
		if ( array() !== $graph ) {
			$markup .= '<script type="application/ld+json">' . StructuredData::encode( $graph ) . '</script>';
		}
		return $markup;
	}

	/**
	 * Renders the sitemap index referencing one sitemap per locale.
	 *
	 * @param string             $site_url Canonical site URL.
	 * @param array<int, string> $locales  Supported locale slugs.
	 */
	public static function sitemap_index( string $site_url, array $locales ): string {
		$origin = rtrim( trim( $site_url ), '/' );
		$body   = '';
		foreach ( $locales as $locale ) {
			$body .= '<sitemap><loc>' . self::escape( $origin . '/sitemap-' . $locale . '.xml' ) . '</loc></sitemap>';
		}
		return '<?xml version="1.0" encoding="UTF-8"?>'
			. '<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . $body . '</sitemapindex>';
	}

	/**
	 * Renders one locale sitemap.
	 *
	 * @param string                           $site_url Canonical site URL.
	 * @param array<int, array<string, mixed>> $entries  Indexable entries.
	 */
	public static function locale_sitemap( string $site_url, array $entries ): string {
		$body = '';
		foreach ( $entries as $entry ) {
			$path = self::text( $entry['path'] ?? '' );
			if ( '' === $path ) {
				continue;
			}
			$body    .= '<url><loc>' . self::escape( SeoPolicy::canonical_url( $site_url, $path ) ) . '</loc>';
			$modified = self::text( $entry['modified'] ?? '' );
			if ( '' !== $modified ) {
				$body .= '<lastmod>' . self::escape( $modified ) . '</lastmod>';
			}
			$body .= '</url>';
		}
		return '<?xml version="1.0" encoding="UTF-8"?>'
			. '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . $body . '</urlset>';
	}

	/**
	 * Renders an RSS 2.0 feed for one locale listing.
	 *
	 * @param array<string, mixed>             $channel Channel descriptor.
	 * @param array<int, array<string, mixed>> $items   Feed items.
	 */
	public static function feed( array $channel, array $items ): string {
		$site_url = self::text( $channel['site_url'] ?? '' );
		$path     = self::text( $channel['path'] ?? '/' );
		$self     = SeoPolicy::canonical_url( $site_url, $path );
		$language = SeoPolicy::bcp47( self::text( $channel['locale'] ?? SeoPolicy::DEFAULT_LOCALE ) );

		$body = '<title>' . self::escape( self::text( $channel['title'] ?? '' ) ) . '</title>'
			. '<link>' . self::escape( $self ) . '</link>'
			. '<description>' . self::escape( self::text( $channel['description'] ?? $channel['title'] ?? '' ) ) . '</description>'
			. '<language>' . self::escape( $language ) . '</language>'
			. '<atom:link href="' . self::escape( $self . 'feed/' ) . '" rel="self" type="application/rss+xml" />';

		foreach ( $items as $item ) {
			$item_path = self::text( $item['path'] ?? '' );
			if ( '' === $item_path ) {
				continue;
			}
			$link  = SeoPolicy::canonical_url( $site_url, $item_path );
			$body .= '<item><title>' . self::escape( self::text( $item['title'] ?? '' ) ) . '</title>'
				. '<link>' . self::escape( $link ) . '</link>'
				. '<guid isPermaLink="true">' . self::escape( $link ) . '</guid>'
				. '<description>' . self::escape( self::text( $item['description'] ?? '' ) ) . '</description>';
			$date  = self::rfc2822( self::text( $item['date'] ?? '' ) );
			if ( '' !== $date ) {
				$body .= '<pubDate>' . self::escape( $date ) . '</pubDate>';
			}
			$body .= '</item>';
		}

		return '<?xml version="1.0" encoding="UTF-8"?>'
			. '<rss version="2.0" xmlns:atom="http://www.w3.org/2005/Atom"><channel>' . $body . '</channel></rss>';
	}

	/**
	 * Renders robots.txt for the canonical host.
	 *
	 * @param string $site_url Canonical site URL.
	 */
	public static function robots_txt( string $site_url ): string {
		$origin = rtrim( trim( $site_url ), '/' );
		$lines  = array( 'User-agent: *' );
		foreach ( self::DISALLOWED as $path ) {
			$lines[] = 'Disallow: ' . $path;
		}
		$lines[] = 'Allow: /wp-admin/admin-ajax.php';
		$lines[] = '';
		$lines[] = 'Sitemap: ' . $origin . '/sitemap.xml';
		return implode( "\n", $lines ) . "\n";
	}

	/**
	 * Returns the browser and OS chrome icon links for the institutional mark.
	 *
	 * The favicon and app-icon variants are reserved for these slots alone
	 * (DESIGN.md §9): they never appear in page content. Every URL is
	 * root-relative so the head stays origin-agnostic like the rest of the shell.
	 */
	private static function icon_links(): string {
		$base = '/wp-content/themes/lps-theme/assets/img/mark';
		return '<link rel="icon" href="' . $base . '/lps-mark-favicon.svg" type="image/svg+xml">'
			. '<link rel="icon" href="' . $base . '/favicon-32.png" type="image/png" sizes="32x32">'
			. '<link rel="apple-touch-icon" href="' . $base . '/apple-touch-icon.png">'
			. '<link rel="manifest" href="' . $base . '/site.webmanifest">';
	}

	/**
	 * Converts a stored timestamp to the RFC 2822 form required by RSS.
	 *
	 * @param string $value Stored timestamp.
	 */
	private static function rfc2822( string $value ): string {
		if ( '' === $value ) {
			return '';
		}
		$parsed = date_create_immutable( $value );
		return false === $parsed ? '' : $parsed->format( 'D, d M Y H:i:s O' );
	}

	/**
	 * Converts a boundary scalar to trimmed text.
	 *
	 * @param mixed $value Boundary value.
	 */
	private static function text( mixed $value ): string {
		return is_scalar( $value ) ? trim( (string) $value ) : '';
	}

	/**
	 * Escapes one untrusted value for markup or XML.
	 *
	 * @param string $value Untrusted value.
	 */
	private static function escape( string $value ): string {
		return htmlspecialchars( $value, ENT_QUOTES | ENT_SUBSTITUTE | ENT_XML1, 'UTF-8' );
	}

	/**
	 * Narrows an untyped descriptor branch to a string-keyed array.
	 *
	 * @param mixed $value Untyped descriptor branch.
	 * @return array<string, mixed>
	 */
	private static function branch( mixed $value ): array {
		if ( ! is_array( $value ) ) {
			return array();
		}
		$branch = array();
		foreach ( $value as $key => $entry ) {
			$branch[ (string) $key ] = $entry;
		}
		return $branch;
	}

	/**
	 * Narrows the locale variant map to nested string-keyed arrays.
	 *
	 * @param mixed $value Untyped variant map.
	 * @return array<string, array<string, mixed>>
	 */
	private static function variants( mixed $value ): array {
		$variants = array();
		foreach ( self::branch( $value ) as $locale => $variant ) {
			$variants[ $locale ] = self::branch( $variant );
		}
		return $variants;
	}
}
