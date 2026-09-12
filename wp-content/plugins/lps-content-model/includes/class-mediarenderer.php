<?php
/**
 * Accessible media HTML renderer.
 *
 * @package LPS\ContentModel
 */

declare(strict_types=1);

namespace LPS\ContentModel;

/** Produces deterministic server-rendered media markup from validated values. */
final class MediaRenderer {
	/**
	 * Renders one responsive image figure.
	 *
	 * @param array<string, mixed> $usage Per-use localized context.
	 * @param array<string, mixed> $asset Governed attachment values.
	 */
	public static function image( array $usage, array $asset ): string {
		$hero       = 'hero' === MediaPolicy::string_value( $usage['placement'] ?? '' );
		$decorative = true === ( $usage['decorative'] ?? false );
		$alt        = $decorative ? '' : MediaPolicy::string_value( $usage['alt'] ?? '' );
		$sizes      = $hero ? '(max-width: 768px) 100vw, 1280px' : '(max-width: 768px) 100vw, 800px';
		$loading    = $hero ? 'eager' : 'lazy';
		$priority   = $hero ? 'high' : 'auto';
		$srcset     = MediaPolicy::string_value( $asset['srcset'] ?? '' );
		$image      = sprintf(
			'<img src="%s"%s sizes="%s" width="%d" height="%d" alt="%s" loading="%s" fetchpriority="%s" decoding="async">',
			self::attribute( MediaPolicy::string_value( $asset['url'] ?? '' ) ),
			'' === $srcset ? '' : ' srcset="' . self::attribute( $srcset ) . '"',
			self::attribute( $sizes ),
			Policy::sanitize_integer( $asset['width'] ?? 0 ),
			Policy::sanitize_integer( $asset['height'] ?? 0 ),
			self::attribute( $alt ),
			$loading,
			$priority
		);
		$image      = self::picture( $image, $asset, $sizes );
		$caption    = MediaPolicy::string_value( $usage['caption'] ?? '' );
		$credit     = MediaPolicy::string_value( $asset['credit'] ?? '' );
		$license    = MediaPolicy::string_value( $asset['license'] ?? '' );
		return sprintf(
			'<figure class="lps-media lps-media--image">%s<figcaption>%s <span class="lps-media__credit">%s - %s</span></figcaption></figure>',
			$image,
			self::text( $caption ),
			self::text( $credit ),
			self::text( $license )
		);
	}

	/** Modern derivative formats offered before the stored original, in preference order. */
	private const MODERN_SOURCE_TYPES = array( 'image/avif', 'image/webp' );

	/**
	 * Wraps an image in a <picture> when governed modern derivatives exist.
	 *
	 * The <img> keeps its intrinsic width/height, so every candidate reserves the same
	 * layout box and no format negotiation can shift the page.
	 *
	 * @param string               $image Rendered fallback <img> markup.
	 * @param array<string, mixed> $asset Governed attachment values.
	 * @param string               $sizes Shared sizes attribute.
	 */
	private static function picture( string $image, array $asset, string $sizes ): string {
		$sources = array();
		$offered = is_array( $asset['sources'] ?? null ) ? $asset['sources'] : array();
		foreach ( self::MODERN_SOURCE_TYPES as $type ) {
			$srcset = MediaPolicy::string_value( $offered[ $type ] ?? '' );
			if ( '' === $srcset ) {
				continue;
			}
			$sources[] = sprintf(
				'<source type="%s" srcset="%s" sizes="%s">',
				self::attribute( $type ),
				self::attribute( $srcset ),
				self::attribute( $sizes )
			);
		}

		if ( array() === $sources ) {
			return $image;
		}

		return '<picture>' . implode( '', $sources ) . $image . '</picture>';
	}

	/**
	 * Renders governed native video.
	 *
	 * @param array<string, mixed> $usage Contextual use.
	 * @param array<string, mixed> $asset Attachment values.
	 */
	public static function video( array $usage, array $asset ): string {
		$label            = self::attribute( MediaPolicy::string_value( $usage['label'] ?? '' ) );
		$transcript_url   = MediaPolicy::string_value( $usage['transcript_url'] ?? '' );
		$transcript_label = 'pt-br' === ( $usage['locale'] ?? '' ) ? 'Transcricao' : 'Transcript';
		$transcript       = '' === $transcript_url ? '' : sprintf( '<a href="%s">%s</a>', self::attribute( $transcript_url ), $transcript_label );
		$captions_url     = MediaPolicy::string_value( $usage['captions_url'] ?? '' );
		$language         = 'pt-br' === ( $usage['locale'] ?? '' ) ? 'pt-BR' : 'en';
		$captions_label   = 'pt-br' === ( $usage['locale'] ?? '' ) ? 'Legendas' : 'Captions';
		$track            = sprintf( '<track kind="captions" src="%s" srclang="%s" label="%s" default>', self::attribute( $captions_url ), $language, $captions_label );
		return sprintf(
			'<figure class="lps-media lps-media--video"><video controls preload="metadata" aria-label="%s" width="%d" height="%d"><source src="%s" type="%s">%s</video><figcaption>%s <span class="lps-media__credit">%s - %s</span> %s</figcaption></figure>',
			$label,
			Policy::sanitize_integer( $asset['width'] ?? 0 ),
			Policy::sanitize_integer( $asset['height'] ?? 0 ),
			self::attribute( MediaPolicy::string_value( $asset['url'] ?? '' ) ),
			self::attribute( MediaPolicy::string_value( $asset['mime'] ?? '' ) ),
			$track,
			self::text( MediaPolicy::string_value( $usage['caption'] ?? '' ) ),
			self::text( MediaPolicy::string_value( $asset['credit'] ?? '' ) ),
			self::text( MediaPolicy::string_value( $asset['license'] ?? '' ) ),
			$transcript
		);
	}

	/**
	 * Renders a locally hosted labeled iframe.
	 *
	 * @param array<string, mixed> $usage Contextual use.
	 * @param array<string, mixed> $asset Attachment values.
	 */
	public static function iframe( array $usage, array $asset ): string {
		return sprintf(
			'<iframe src="%s" title="%s" loading="lazy" width="960" height="720"></iframe>',
			self::attribute( MediaPolicy::string_value( $asset['url'] ?? '' ) ),
			self::attribute( MediaPolicy::string_value( $usage['label'] ?? '' ) )
		);
	}

	/**
	 * Renders a document with its HTML equivalent.
	 *
	 * @param array<string, mixed> $usage Contextual use.
	 * @param array<string, mixed> $asset Attachment values.
	 */
	public static function document( array $usage, array $asset ): string {
		$html = MediaPolicy::string_value( $usage['html_equivalent'] ?? '' );
		$html = function_exists( 'wp_kses_post' ) ? wp_kses_post( $html ) : strip_tags( $html, '<p><ul><ol><li><strong><em><h2><h3>' );
		return sprintf(
			'<section class="lps-media lps-media--document" aria-label="%s"><div class="lps-media__equivalent">%s</div><p><a href="%s">%s (PDF)</a> <span class="lps-media__credit">%s - %s</span></p></section>',
			self::attribute( MediaPolicy::string_value( $usage['label'] ?? '' ) ),
			$html,
			self::attribute( MediaPolicy::string_value( $asset['url'] ?? '' ) ),
			self::text( MediaPolicy::string_value( $usage['label'] ?? '' ) ),
			self::text( MediaPolicy::string_value( $asset['credit'] ?? '' ) ),
			self::text( MediaPolicy::string_value( $asset['license'] ?? '' ) )
		);
	}

	/**
	 * Escapes an HTML attribute without depending on WordPress.
	 *
	 * @param string $value Attribute value.
	 */
	private static function attribute( string $value ): string {
		return htmlspecialchars( $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );
	}

	/**
	 * Escapes text without depending on WordPress.
	 *
	 * @param string $value Text value.
	 */
	private static function text( string $value ): string {
		return htmlspecialchars( $value, ENT_NOQUOTES | ENT_SUBSTITUTE, 'UTF-8' );
	}
}
