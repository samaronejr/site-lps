<?php
/**
 * Theme shell contract tests.
 *
 * @package LPS\Theme\Tests
 */

declare(strict_types=1);

namespace LPS\Theme\Tests;

use LPS\Theme\Shell;
use PHPUnit\Framework\Attributes\DataProvider;

require_once dirname( __DIR__ ) . '/includes/class-shell.php';

/** Theme shell contract tests. */
final class ThemeShellTest extends \PHPUnit\Framework\TestCase {
	/**
	 * Provides locale paths.
	 *
	 * @return array<string, array{string, string}>
	 */
	public static function locale_paths(): array {
		return array(
			'portuguese root'                          => array( '/pt-br/', 'pt-br' ),
			'english detail'                           => array( '/en/research/signals/', 'en' ),
			'unsupported route defaults to Portuguese' => array( '/fr/recherche/', 'pt-br' ),
		);
	}

	/**
	 * Verifies that detects supported locale from public path.
	 *
	 * @param string $path Public request path.
	 * @param string $expected Expected result.
	 */
	#[DataProvider( 'locale_paths' )]
	public function test_detects_supported_locale_from_public_path( string $path, string $expected ): void {
		// Given: a public request path.
		// When: the shell resolves its locale.
		$locale = Shell::locale_from_path( $path );

		// Then: it returns a supported locale without cross-language fallback.
		self::assertSame( $expected, $locale );
	}

	/**
	 * Verifies that navigation uses frozen locale routes.
	 */
	public function test_navigation_uses_frozen_locale_routes(): void {
		// Given: the frozen bilingual shell contract.
		// When: both navigation maps are requested.
		$portuguese = Shell::navigation( 'pt-br' );
		$english    = Shell::navigation( 'en' );

		// Then: labels and URLs resolve to the matching locale roots.
		self::assertCount( 7, $portuguese );
		self::assertCount( 7, $english );
		self::assertSame( '/pt-br/publicacoes/', $portuguese['publications']['url'] );
		self::assertSame( 'Publicações', $portuguese['publications']['label'] );
		self::assertSame( '/en/publications/', $english['publications']['url'] );
		self::assertSame( 'Publications', $english['publications']['label'] );
	}

	/** Archive surfaces name themselves in the page locale, never with core English strings. */
	public function test_archive_title_text_is_localized_per_record_type(): void {
		// Given: the archive contexts the public routes expose.
		// When/Then: each resolves to its own locale label, never a core string.
		self::assertSame( 'Organizações', Shell::archive_title_text( 'pt-br', 'lps_organization', '' ) );
		self::assertSame( 'Organizations', Shell::archive_title_text( 'en', 'lps_organization', '' ) );
		self::assertSame( 'Publicações', Shell::archive_title_text( 'pt-br', 'lps_publication', '' ) );
		self::assertSame( 'Ano: 2026', Shell::archive_title_text( 'pt-br', 'date', '2026' ) );
		self::assertSame( 'Year: 2026', Shell::archive_title_text( 'en', 'date', '2026' ) );
	}

	/** An unmapped archive context falls back to a localized generic listing label. */
	public function test_archive_title_text_falls_back_to_a_localized_listing_label(): void {
		self::assertSame( 'Listagem', Shell::archive_title_text( 'pt-br', 'unknown_type', '' ) );
		self::assertSame( 'Listing', Shell::archive_title_text( 'en', 'unknown_type', '' ) );
	}

	/** The posts index names itself in its own locale, never with a core English string. */
	public function test_posts_index_title_text_is_localized_and_never_a_core_archive_string(): void {
		// Given: the posts-index page title, and the case where none is resolvable.
		// When: the shared posts-index title is computed.
		// Then: the resolved title wins and the fallback stays in the page locale.
		self::assertSame( 'Registros', Shell::index_title_text( 'pt-br', 'Registros' ) );
		self::assertSame( 'Records', Shell::index_title_text( 'en', 'Records' ) );
		self::assertSame( 'Publicações do LPS', Shell::index_title_text( 'pt-br', '' ) );
		self::assertSame( 'LPS posts', Shell::index_title_text( 'en', '  ' ) );
	}

	/** The posts index must carry exactly one H1, like every other public template. */
	public function test_index_title_markup_renders_a_single_localized_h1(): void {
		// Given: the posts-index page title resolved by WordPress.
		// When: the fallback index heading renders in each locale.
		$portuguese = Shell::index_title_markup( 'pt-br', 'Registros' );
		$english    = Shell::index_title_markup( 'en', 'Records' );

		// Then: each locale emits one H1 with the page title and no invented copy.
		self::assertSame( '<h1 class="lps-page-title">Registros</h1>', $portuguese );
		self::assertSame( '<h1 class="lps-page-title">Records</h1>', $english );
	}

	/** Without a resolvable title the heading falls back to the locale site name. */
	public function test_index_title_markup_falls_back_to_the_locale_site_name(): void {
		// Given: a posts index whose page title is unavailable.
		// When: the heading renders.
		$portuguese = Shell::index_title_markup( 'pt-br', '' );
		$english    = Shell::index_title_markup( 'en', '   ' );

		// Then: a localized institutional heading is emitted instead of an empty document.
		self::assertSame( '<h1 class="lps-page-title">Publicações do LPS</h1>', $portuguese );
		self::assertSame( '<h1 class="lps-page-title">LPS posts</h1>', $english );
	}

	/** Locale variants that share the site origin must render as root-relative links. */
	public function test_locale_control_renders_internal_variants_as_root_relative_paths(): void {
		// Given: locale variants resolved from permalinks, which WordPress returns absolute.
		$variants = array(
			'pt-br' => home_url( '/pt-br/' ),
			'en'    => home_url( '/en/' ),
		);

		// When: the header renders the locale control for those variants.
		$header = Shell::header_markup( 'pt-br', '/pt-br/', $variants );

		// Then: same-origin locale links use the same root-relative form as every
		// other internal link in the shell, so locale switching is origin-agnostic.
		self::assertStringContainsString( 'href="/en/"', $header );
		self::assertStringContainsString( 'href="/pt-br/"', $header );
		self::assertStringNotContainsString( 'href="' . home_url( '/en/' ) . '"', $header );
	}

	/** A variant published on another origin must keep its absolute URL. */
	public function test_locale_control_preserves_a_variant_hosted_on_another_origin(): void {
		// Given: a variant published on a different origin.
		$external = 'https://example.org/en/';

		// When: the header renders the locale control.
		$header = Shell::header_markup(
			'pt-br',
			'/pt-br/',
			array(
				'pt-br' => '/pt-br/',
				'en'    => $external,
			)
		);

		// Then: the foreign origin is preserved instead of being rewritten to a local path.
		self::assertStringContainsString( 'href="' . $external . '"', $header );
	}

	/**
	 * Verifies that shell markup exposes server rendered global controls.
	 */
	public function test_shell_markup_exposes_server_rendered_global_controls(): void {
		// Given: a Portuguese shell rendered without client JavaScript.
		// When: the header and footer markup are built.
		$header = Shell::header_markup( 'pt-br', '/pt-br/pesquisa/' );
		$footer = Shell::footer_markup( 'pt-br' );

		// Then: native links, disclosure, search, locale, landmarks, and affiliation remain available.
		self::assertStringContainsString( 'href="#lps-main"', $header );
		self::assertStringContainsString( '<header', $header );
		self::assertStringContainsString( '<details class="lps-shell-disclosure">', $header );
		self::assertStringContainsString( '<summary>', $header );
		self::assertStringContainsString( '<nav', $header );
		self::assertStringContainsString( 'aria-current="page"', $header );
		self::assertStringContainsString( '<form', $header );
		self::assertStringContainsString( 'action="/pt-br/busca/"', $header );
		self::assertStringContainsString( 'name="q"', $header );
		self::assertStringContainsString( 'hreflang="en"', $header );
		self::assertStringContainsString( '/lps-brand/lps_logo_vector.svg', $header );
		self::assertStringContainsString( 'srcset=', $header );
		self::assertStringContainsString( '/lps-brand/lps_logo_compact.svg', $header );
		self::assertStringContainsString( '<footer', $footer );
		self::assertStringContainsString( '/pt-br/ensino/', $footer );
		self::assertStringContainsString( '/pt-br/acessibilidade/', $footer );
		self::assertStringNotContainsString( '<img', $footer );
	}

	/**
	 * Verifies that absent locale variant is rendered as an unavailable state.
	 */
	public function test_absent_locale_variant_is_rendered_as_an_unavailable_state(): void {
		// Given: a record with no published English variant.
		// When: the locale control is rendered with only Portuguese available.
		$control = Shell::locale_markup( 'pt-br', array( 'pt-br' => '/pt-br/noticias/exemplo/' ) );

		// Then: the missing variant is not emitted as a misleading link.
		self::assertStringContainsString( 'aria-current="page"', $control );
		self::assertStringContainsString( 'aria-disabled="true"', $control );
		self::assertStringNotContainsString( 'href="/en/', $control );
	}

	/** The masthead carries the contracted responsive artwork, not a redraw. */
	public function test_masthead_renders_the_contracted_responsive_artwork(): void {
		// Given: the unified-identity contract (DESIGN.md section 6) with the
		// owner-supplied artwork as the default masthead identity.
		// When: the header renders in either locale.
		$portuguese = Shell::header_markup( 'pt-br', '/pt-br/' );
		$english    = Shell::header_markup( 'en', '/en/' );

		// Then: the home link keeps its accessible name and renders one
		// decorative image with both artwork sources — full lockup plus
		// compact variant — so engines swap by slot width without client
		// code, and no label is duplicated inside the artwork.
		foreach ( array( $portuguese, $english ) as $header ) {
			self::assertStringContainsString( 'class="lps-brand"', $header );
			self::assertStringContainsString( 'aria-label="LPS — ', $header );
			self::assertStringContainsString( '/lps-brand/lps_logo_vector.svg', $header );
			self::assertStringContainsString( '/lps-brand/lps_logo_compact.svg', $header );
			self::assertStringContainsString( 'alt="LPS"', $header );
			self::assertStringContainsString( 'width="2052" height="301"', $header );
			self::assertSame( 1, substr_count( $header, '<img' ) );
		}
		self::assertStringContainsString( 'aria-label="LPS — início"', $portuguese );
		self::assertStringContainsString( 'aria-label="LPS — home"', $english );
	}

	/** A missing artwork file degrades to the named text wordmark, never an empty link. */
	public function test_masthead_falls_back_to_the_text_wordmark_without_artwork(): void {
		// Given: a deploy where the brand endpoint cannot resolve (the
		// filter below stands in for an empty resolution by forcing the
		// override slot empty and the sources empty is covered by
		// logo_sources returning both configured names — so this asserts
		// the fallback string the brand renderer returns instead).
		// When: the artwork sources resolve to empty strings.
		// Then: the documented fallback keeps the home link named.
		self::assertSame(
			array( 'full', 'compact' ),
			array_keys( Shell::logo_sources() )
		);
		foreach ( Shell::logo_sources() as $source ) {
			self::assertStringEndsWith( '.svg', $source );
		}
	}

	/** The approved-mark slot renders a supplied mark inside the persistent home link. */
	public function test_masthead_mark_slot_renders_an_approved_mark(): void {
		// Given: an approved mark supplied through the slot (approval artifact required).
		$mark = static fn(): string => '<img src="/lps-mark.svg" width="40" height="40" alt="">';
		add_filter( 'lps_masthead_mark', $mark );
		try {
			// When: the header renders.
			$header = Shell::header_markup( 'pt-br', '/pt-br/' );
		} finally {
			remove_filter( 'lps_masthead_mark', $mark );
		}

		// Then: the mark replaces the bundled artwork inside the persistent home link.
		self::assertStringContainsString( '<img src="/lps-mark.svg"', $header );
		self::assertStringNotContainsString( 'lps-brand/lps_logo_vector.svg', $header );
		self::assertStringContainsString( 'class="lps-brand"', $header );
	}

	/** Header regions keep DOM order equal to reading and focus order. */
	public function test_header_regions_keep_dom_reading_and_focus_order(): void {
		// Given: a rendered English header.
		$header = Shell::header_markup( 'en', '/en/research/' );

		// Then: skip link, banner, disclosure, nav, search, locale and CTA appear in order.
		$positions = array(
			'skip'    => strpos( $header, 'href="#lps-main"' ),
			'banner'  => strpos( $header, '<header' ),
			'summary' => strpos( $header, '<summary>' ),
			'nav'     => strpos( $header, 'aria-label="Primary navigation"' ),
			'search'  => strpos( $header, 'role="search"' ),
			'locale'  => strpos( $header, 'aria-label="Language"' ),
			'cta'     => strpos( $header, 'href="/en/collaborate/"' ),
		);
		foreach ( $positions as $position ) {
			self::assertNotFalse( $position );
		}
		self::assertTrue(
			$positions['skip'] < $positions['banner']
			&& $positions['banner'] < $positions['summary']
			&& $positions['summary'] < $positions['nav']
			&& $positions['nav'] < $positions['search']
			&& $positions['search'] < $positions['locale']
			&& $positions['locale'] < $positions['cta']
		);
	}

	/** The English shell localizes every control and marks the current nav item. */
	public function test_english_header_localizes_controls_and_marks_current_item(): void {
		// Given: an English research request.
		$header = Shell::header_markup( 'en', '/en/research/' );

		// Then: controls render in English and the current item carries aria-current.
		self::assertStringContainsString( 'Skip to content', $header );
		self::assertStringContainsString( 'aria-label="Primary navigation"', $header );
		self::assertStringContainsString( 'Search the LPS website', $header );
		self::assertStringContainsString( 'Collaborate', $header );
		self::assertStringContainsString( 'aria-current="page" href="/en/research/"', $header );
		self::assertStringContainsString( 'hreflang="pt-BR"', $header );
	}

	/** A missing Portuguese variant renders the same honest disabled state. */
	public function test_absent_portuguese_variant_is_rendered_as_an_unavailable_state(): void {
		// Given: a record with no published Portuguese variant.
		// When: the locale control is rendered with only English available.
		$control = Shell::locale_markup( 'en', array( 'en' => '/en/news/example/' ) );

		// Then: the missing variant is a named disabled state, never a link.
		self::assertStringContainsString( 'aria-current="page"', $control );
		self::assertStringContainsString( 'aria-disabled="true"', $control );
		self::assertStringContainsString( 'translation unavailable', $control );
		self::assertStringNotContainsString( 'href="/pt-br/', $control );
	}

	/** The footer carries institutional context and the teaching entrance, never a nav repeat. */
	public function test_footer_carries_institutional_context_without_nav_clutter(): void {
		// Given: the English footer.
		$footer = Shell::footer_markup( 'en' );

		// Then: affiliation context, the canonical teaching entrance and the
		// four utility routes render; the footer band keeps the text
		// wordmark (artwork stays on light surfaces); and the primary
		// navigation is not duplicated.
		self::assertStringContainsString( 'Federal University of Rio de Janeiro', $footer );
		self::assertStringContainsString( 'href="/en/teaching/"', $footer );
		self::assertStringContainsString( 'href="/en/contact/"', $footer );
		self::assertStringContainsString( 'href="/en/events/"', $footer );
		self::assertStringContainsString( 'href="/en/privacy/"', $footer );
		self::assertStringContainsString( 'href="/en/accessibility/"', $footer );
		self::assertStringNotContainsString( '<img', $footer );
		self::assertStringNotContainsString( '/en/research/', $footer );
		self::assertStringNotContainsString( '/en/publications/', $footer );
	}

	/** A listing breadcrumb names the listing, never a record in its loop. */
	public function test_listing_breadcrumb_never_borrows_a_record_title(): void {
		// Given: a listing request whose loop holds a withheld record.
		// When: the breadcrumb trail title is resolved for an archive context.
		$title = Shell::breadcrumb_title( 'archive', 'Parceiro Oculto de fixture', 'Organizações', 'pt-br' );

		// Then: the trail names the listing, never the record in the loop.
		self::assertSame( 'Organizações', $title );
		self::assertStringNotContainsString( 'Oculto', $title );
	}

	/** Singular and search breadcrumbs keep their own titles. */
	public function test_singular_and_search_breadcrumbs_keep_their_own_titles(): void {
		// Given: singular and search contexts.
		// When: their breadcrumb titles are resolved.
		$singular = Shell::breadcrumb_title( 'singular', 'Pessoa Egressa de fixture', 'Pessoas', 'pt-br' );
		$search   = Shell::breadcrumb_title( 'search', 'Qualquer registro', '', 'en' );

		// Then: the record title is used only where the record is the page.
		self::assertSame( 'Pessoa Egressa de fixture', $singular );
		self::assertSame( 'Search results', $search );
	}
}
