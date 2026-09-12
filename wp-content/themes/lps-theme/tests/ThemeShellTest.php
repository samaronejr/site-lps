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
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/includes/class-shell.php';

final class ThemeShellTest extends TestCase {
	/** @return array<string, array{string, string}> */
	public static function locale_paths(): array {
		return array(
			'portuguese root'                          => array( '/pt-br/', 'pt-br' ),
			'english detail'                           => array( '/en/research/signals/', 'en' ),
			'unsupported route defaults to Portuguese' => array( '/fr/recherche/', 'pt-br' ),
		);
	}

	#[DataProvider( 'locale_paths' )]
	public function test_detects_supported_locale_from_public_path( string $path, string $expected ): void {
		// Given: a public request path.
		// When: the shell resolves its locale.
		$locale = Shell::locale_from_path( $path );

		// Then: it returns a supported locale without cross-language fallback.
		self::assertSame( $expected, $locale );
	}

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

	public function test_shell_markup_exposes_server_rendered_global_controls(): void {
		// Given: a Portuguese shell rendered without client JavaScript.
		// When: the header and footer markup are built.
		$header = Shell::header_markup( 'pt-br', '/pt-br/pesquisa/' );
		$footer = Shell::footer_markup( 'pt-br' );

		// Then: native links, disclosure, search, locale, landmarks, and affiliation remain available.
		self::assertStringContainsString( 'href="#lps-main"', $header );
		self::assertStringContainsString( '<header', $header );
		self::assertStringContainsString( '<details class="lps-shell-disclosure" open>', $header );
		self::assertStringContainsString( '<nav', $header );
		self::assertStringContainsString( 'aria-current="page"', $header );
		self::assertStringContainsString( '<form', $header );
		self::assertStringContainsString( 'name="s"', $header );
		self::assertStringContainsString( 'hreflang="en"', $header );
		self::assertStringNotContainsString( '<img', $header );
		self::assertStringContainsString( '<footer', $footer );
		self::assertStringContainsString( '/pt-br/acessibilidade/', $footer );
	}

	public function test_absent_locale_variant_is_rendered_as_an_unavailable_state(): void {
		// Given: a record with no published English variant.
		// When: the locale control is rendered with only Portuguese available.
		$control = Shell::locale_markup( 'pt-br', array( 'pt-br' => '/pt-br/noticias/exemplo/' ) );

		// Then: the missing variant is not emitted as a misleading link.
		self::assertStringContainsString( 'aria-current="page"', $control );
		self::assertStringContainsString( 'aria-disabled="true"', $control );
		self::assertStringNotContainsString( 'href="/en/', $control );
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
