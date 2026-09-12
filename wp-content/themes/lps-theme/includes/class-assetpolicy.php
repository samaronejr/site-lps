<?php
/**
 * Front-end asset policy: ship only what the rendered page actually needs.
 *
 * @package LPS\Theme
 */

declare(strict_types=1);

namespace LPS\Theme;

/**
 * Decides which WordPress payloads may be dropped from a public response.
 *
 * The rules are deliberately conservative: admin and block-editor requests are never
 * touched, the theme's own tokenized styles and `theme.json` global styles always stay
 * (they carry the approved design), and core block CSS is only dropped when no rendered
 * block depends on it.
 */
final class AssetPolicy {
	/** Core styles that only exist for payloads this theme does not use. */
	private const ALWAYS_REMOVABLE_STYLES = array( 'wp-block-library-theme', 'classic-theme-styles' );

	/** Core scripts with no counterpart in this server-rendered theme. */
	private const ALWAYS_REMOVABLE_SCRIPTS = array( 'wp-embed' );

	/** Core blocks whose appearance comes entirely from `theme.json` and the theme stylesheet. */
	private const BLOCKS_WITHOUT_CORE_CSS = array(
		'core/block',
		'core/column',
		'core/columns',
		'core/group',
		'core/heading',
		'core/html',
		'core/paragraph',
		'core/pattern',
		'core/post-content',
		'core/post-date',
		'core/post-excerpt',
		'core/post-template',
		'core/post-terms',
		'core/post-title',
		'core/query',
		'core/query-no-results',
		'core/query-title',
		'core/site-title',
		'core/spacer',
		'core/template-part',
	);

	/** Self-hosted subset faces preloaded for the first paint. */
	private const PRELOADED_FONTS = array(
		'assets/fonts/source-serif-4-regular.woff2',
		'assets/fonts/source-serif-4-semibold.woff2',
	);

	/**
	 * Builds the removal plan for one request.
	 *
	 * @param array{is_admin?: bool, block_editor?: bool, blocks?: array<int, string>} $context Request context.
	 *
	 * @return array{styles: array<int, string>, scripts: array<int, string>, remove_emoji: bool}
	 */
	public static function dequeue_plan( array $context ): array {
		$empty = array(
			'styles'       => array(),
			'scripts'      => array(),
			'remove_emoji' => false,
		);

		if ( true === ( $context['is_admin'] ?? false ) || true === ( $context['block_editor'] ?? false ) ) {
			return $empty;
		}

		$blocks = array_values( array_filter( (array) ( $context['blocks'] ?? array() ), 'is_string' ) );
		$styles = self::ALWAYS_REMOVABLE_STYLES;
		if ( ! self::needs_core_block_styles( $blocks ) ) {
			$styles[] = 'wp-block-library';
		}
		sort( $styles );

		return array(
			'styles'       => $styles,
			'scripts'      => self::ALWAYS_REMOVABLE_SCRIPTS,
			'remove_emoji' => true,
		);
	}

	/**
	 * Reports whether any rendered block still depends on the core block stylesheet.
	 *
	 * @param array<int, string> $blocks Rendered block names.
	 */
	public static function needs_core_block_styles( array $blocks ): bool {
		foreach ( $blocks as $block ) {
			if ( ! str_starts_with( $block, 'core/' ) ) {
				continue;
			}
			if ( ! in_array( $block, self::BLOCKS_WITHOUT_CORE_CSS, true ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Lists the front-end scripts this theme enqueues.
	 *
	 * The public site is fully server-rendered, so the list is empty by contract and
	 * `PerformancePolicyTest` fails the moment a script is added without a budget review.
	 *
	 * @return array<int, string>
	 */
	public static function front_end_scripts(): array {
		return array();
	}

	/**
	 * Builds the font preload descriptors for the first paint.
	 *
	 * @param string $theme_uri Absolute theme base URI, without a trailing slash.
	 *
	 * @return array<int, array{href: string, as: string, type: string, crossorigin: bool}>
	 */
	public static function font_preloads( string $theme_uri ): array {
		$base     = rtrim( $theme_uri, '/' );
		$preloads = array();
		foreach ( self::PRELOADED_FONTS as $font ) {
			$preloads[] = array(
				'href'        => $base . '/' . $font,
				'as'          => 'font',
				'type'        => 'font/woff2',
				'crossorigin' => true,
			);
		}

		return $preloads;
	}
}
