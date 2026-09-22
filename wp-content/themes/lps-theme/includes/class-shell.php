<?php
/**
 * Server-rendered institutional shell.
 *
 * @package LPS\Theme
 */

declare(strict_types=1);

namespace LPS\Theme;

use WP_Post;

require_once __DIR__ . '/class-searchsurfaces.php';

/** Owns the bilingual global shell and its native, no-JavaScript controls. */
final class Shell {
	/**
	 * Frozen sign-in path segments per locale.
	 *
	 * The sign-in surface and the professor area are one route family: the form
	 * posts back to itself and hands the visitor to the area, so both segments
	 * are declared together and never localized ad hoc.
	 *
	 * @var array<string, string>
	 */
	private const SIGNIN_SEGMENTS = array(
		'pt-br' => 'entrar',
		'en'    => 'sign-in',
	);

	/**
	 * Frozen professor-area path segments per locale.
	 *
	 * @var array<string, string>
	 */
	private const MEMBER_SEGMENTS = array(
		'pt-br' => 'area-do-professor',
		'en'    => 'faculty-area',
	);

	/**
	 * Frozen utility (task-level) destinations shown above the masthead.
	 *
	 * @var array<string, array<string, string>>
	 */
	private const UTILITY_LINKS = array(
		'pt-br' => array(
			'Publicações'    => '/pt-br/publicacoes/',
			'Infraestrutura' => '/pt-br/infraestrutura/',
			'Contato'        => '/pt-br/contato/',
			'Acessibilidade' => '/pt-br/acessibilidade/',
		),
		'en'    => array(
			'Publications'   => '/en/publications/',
			'Infrastructure' => '/en/infrastructure/',
			'Contact'        => '/en/contact/',
			'Accessibility'  => '/en/accessibility/',
		),
	);

	/**
	 * Returns the frozen primary navigation for one locale.
	 *
	 * @param string $locale Supported locale slug.
	 * @return array<string, array{label: string, url: string}>
	 */
	public static function navigation( string $locale ): array {
		$english = 'en' === $locale;
		return array(
			'about'          => array(
				'label' => $english ? 'About' : 'Sobre',
				'url'   => $english ? '/en/about/' : '/pt-br/sobre/',
			),
			'research'       => array(
				'label' => $english ? 'Research' : 'Pesquisa',
				'url'   => $english ? '/en/research/' : '/pt-br/pesquisa/',
			),
			'people'         => array(
				'label' => $english ? 'People' : 'Pessoas',
				'url'   => $english ? '/en/people/' : '/pt-br/pessoas/',
			),
			'publications'   => array(
				'label' => $english ? 'Publications' : 'Publicações',
				'url'   => $english ? '/en/publications/' : '/pt-br/publicacoes/',
			),
			'infrastructure' => array(
				'label' => $english ? 'Infrastructure' : 'Infraestrutura',
				'url'   => $english ? '/en/infrastructure/' : '/pt-br/infraestrutura/',
			),
			'opportunities'  => array(
				'label' => $english ? 'Opportunities' : 'Oportunidades',
				'url'   => $english ? '/en/opportunities/' : '/pt-br/oportunidades/',
			),
			'news'           => array(
				'label' => $english ? 'News' : 'Notícias',
				'url'   => $english ? '/en/news/' : '/pt-br/noticias/',
			),
		);
	}

	/**
	 * Returns the branded sign-in path of one locale.
	 *
	 * @param string $locale Supported locale slug.
	 */
	public static function signin_path( string $locale ): string {
		$segment = self::SIGNIN_SEGMENTS[ $locale ] ?? self::SIGNIN_SEGMENTS['pt-br'];
		return '/' . $locale . '/' . $segment . '/';
	}

	/**
	 * Returns the professor-area path of one locale.
	 *
	 * @param string $locale Supported locale slug.
	 */
	public static function member_path( string $locale ): string {
		$segment = self::MEMBER_SEGMENTS[ $locale ] ?? self::MEMBER_SEGMENTS['pt-br'];
		return '/' . $locale . '/' . $segment . '/';
	}

	/**
	 * Returns the task-level utility destinations of one locale.
	 *
	 * @param string $locale Supported locale slug.
	 * @return array<string, string> Label to path.
	 */
	public static function utility_links( string $locale ): array {
		return self::UTILITY_LINKS[ $locale ] ?? self::UTILITY_LINKS['pt-br'];
	}

	/**
	 * Builds the session link of the utility band.
	 *
	 * The link is the only place the public shell acknowledges the session: it
	 * names the sign-in surface while signed out and the professor area while
	 * signed in, so a returning professor reaches their own working surface
	 * without a second navigation model. Both states render the same element —
	 * only the destination and the state class differ — so a cookieless page
	 * cache never serves a layout that disagrees with the session.
	 *
	 * @param string $locale Supported locale slug.
	 */
	private static function session_link( string $locale ): string {
		$english = 'en' === $locale;
		$signed  = function_exists( 'is_user_logged_in' ) && is_user_logged_in();
		$label   = $signed
			? ( $english ? 'My area' : 'Minha área' )
			: ( $english ? 'Sign in' : 'Entrar' );
		$url     = $signed ? self::member_path( $locale ) : self::signin_path( $locale );
		return '<a class="lps-session-link' . ( $signed ? ' lps-session-link--active' : '' ) . '" href="'
			. self::escape( $url ) . '">' . self::escape( $label ) . '</a>';
	}

	/**
	 * Resolves a supported locale from a public path.
	 *
	 * @param string $path Public request path.
	 */
	public static function locale_from_path( string $path ): string {
		return 1 === preg_match( '~^/en(?:/|$)~', $path ) ? 'en' : 'pt-br';
	}

	/**
	 * Builds the complete server-rendered header.
	 *
	 * Source order is reading order: affiliation, masthead identity,
	 * disclosure control, primary navigation, search, locale switcher,
	 * then the collaboration task link. The `<details>` disclosure keeps
	 * every destination reachable with scripting disabled; the two
	 * artwork sources swap by rendered width through native `srcset`
	 * instead of client code.
	 *
	 * @param string                     $locale   Supported locale slug.
	 * @param string                     $path     Current public path.
	 * @param array<string, string>|null $variants Published locale URLs.
	 */
	public static function header_markup( string $locale, string $path, ?array $variants = null ): string {
		$english         = 'en' === $locale;
		$skip            = $english ? 'Skip to content' : 'Pular para o conteúdo';
		$menu            = $english ? 'Menu and site tools' : 'Menu e ferramentas do site';
		$nav_label       = $english ? 'Primary navigation' : 'Navegação principal';
		$search_label    = $english ? 'Search the LPS website' : 'Buscar no site do LPS';
		$search_button   = $english ? 'Search' : 'Buscar';
		$search_action   = $english ? '/en/search/' : '/pt-br/busca/';
		$collaborate     = $english ? 'Collaborate' : 'Colabore';
		$collaborate_url = $english ? '/en/collaborate/' : '/pt-br/colabore/';
		$home            = $english ? '/en/' : '/pt-br/';
		$items           = '';
		foreach ( self::navigation( $locale ) as $item ) {
			$current = rtrim( $path, '/' ) === rtrim( $item['url'], '/' ) ? ' aria-current="page"' : '';
			$items  .= '<li><a' . $current . ' href="' . self::escape( $item['url'] ) . '">' . self::escape( $item['label'] ) . '</a></li>';
		}
		$locale_control = self::locale_markup(
			$locale,
			$variants ?? array(
				'pt-br' => '/pt-br/',
				'en'    => '/en/',
			)
		);
		$mark          = self::masthead_brand();
		$quick_label   = $english ? 'Quick access' : 'Acesso rápido';
		$utility_items = '';
		foreach ( self::utility_links( $locale ) as $label => $url ) {
			$current        = rtrim( $path, '/' ) === rtrim( $url, '/' ) ? ' aria-current="page"' : '';
			$utility_items .= '<li><a' . $current . ' href="' . self::escape( $url ) . '">' . self::escape( $label ) . '</a></li>';
		}
		return '<a class="lps-skip-link" href="#lps-main">' . self::escape( $skip ) . '</a>'
			. '<header class="lps-site-header">'
			. '<div class="lps-affiliation lps-utility-bar"><div class="lps-utility-inner lps-page-grid"><p lang="pt-BR">Laboratório de Processamento de Sinais <span aria-hidden="true">/</span> UFRJ <span aria-hidden="true">/</span> COPPE</p><p class="lps-meta" lang="pt-BR">Universidade Federal do Rio de Janeiro</p><nav aria-label="' . self::escape( $quick_label ) . '"><ul class="lps-utility-links">' . $utility_items . '</ul></nav>' . self::session_link( $locale ) . '</div></div>'
			// The masthead is the artwork alone: the lockup already sets the
			// laboratory name in type, so repeating it in HTML beside the mark would
			// say it twice and crowd the mark. The home link keeps its accessible
			// name, and the affiliation band above still names the institution in a
			// language-tagged Portuguese paragraph.
			. '<div class="lps-masthead lps-page-grid"><a class="lps-brand" href="' . $home . '" aria-label="LPS — ' . ( $english ? 'home' : 'início' ) . '">' . $mark . '</a></div>'
			. '<details class="lps-shell-disclosure"><summary>' . self::escape( $menu ) . '</summary><div class="lps-nav-panel lps-page-grid">'
			. '<nav class="lps-primary-nav" aria-label="' . self::escape( $nav_label ) . '"><ul>' . $items . '</ul></nav>'
			. '<div class="lps-shell-tools"><form class="lps-search" role="search" action="' . $search_action . '" method="get"><label for="lps-search-input">' . self::escape( $search_label ) . '</label><div><input id="lps-search-input" name="q" type="search" autocomplete="off"><button type="submit">' . self::escape( $search_button ) . '</button></div></form>'
			. $locale_control . '<a class="lps-button lps-button-primary" href="' . $collaborate_url . '">' . self::escape( $collaborate ) . '</a></div></div></details></header>';
	}

	/**
	 * Renders the masthead home-link brand: responsive artwork with a
	 * text-wordmark fallback.
	 *
	 * The `<img>` carries both artwork sources — full lockup and compact
	 * variant — so engines pick by rendered slot width without client
	 * code. When neither bundled file resolves (for example a partial
	 * deploy), the text wordmark keeps the home link named and usable.
	 */
	private static function masthead_brand(): string {
		$approved = self::masthead_mark();
		if ( '' !== $approved ) {
			return $approved;
		}
		$sources = self::logo_sources();
		if ( '' === $sources['full'] || '' === $sources['compact'] ) {
			return 'LPS';
		}
		// The artwork is the only content of the home link, so it carries the
		// wordmark as its text alternative: an empty alt would leave the link
		// unnamed for assistive technology that ignores the link's aria-label.
		// The logo never claims a priority hint: fetchpriority is reserved for
		// the single LCP image so the brand mark cannot compete with it.
		return '<img class="lps-logo" src="' . self::escape( $sources['full'] ) . '" srcset="' . self::escape( $sources['compact'] ) . ' 1x, ' . self::escape( $sources['full'] ) . ' 2x" sizes="190px" alt="LPS" width="1622" height="804">';
	}

	/**
	 * Resolves the masthead artwork sources: the COPPE/UFRJ lockup in its
	 * tight-crop variant for both slots — a faithful swap, never a crop.
	 *
	 * The lockup (≈2.02:1) holds the descriptive COPPE · POLI · UFRJ
	 * lettering at the ~90px rendered height of the masthead slot, so one
	 * source serves both density descriptors.
	 *
	 * @return array{full: string, compact: string} Resolved artwork URLs;
	 *                                              empty when unresolvable.
	 */
	public static function logo_sources(): array {
		$base = self::brand_base_url();
		if ( '' === $base ) {
			return array(
				'full'    => '',
				'compact' => '',
			);
		}
		return array(
			'full'    => $base . 'lps_coppe_blue_lockup.svg',
			'compact' => $base . 'lps_coppe_blue_lockup.svg',
		);
	}


	/**
	 * Returns the public base URL of the bundled brand directory.
	 *
	 * The artwork ships once at the repository root (`assets/brand/`), one
	 * level above the WordPress content root, so the shell serves it
	 * through a theme rewrite endpoint instead of a second copy: the
	 * `lps-brand` endpoint (registered in `functions.php`) maps the two
	 * contract file names to their bytes, which keeps the source checksum
	 * intact. An empty resolution returns an empty string so the caller
	 * falls back to the text wordmark instead of an empty image.
	 */
	private static function brand_base_url(): string {
		// Root-relative on purpose: the artwork then inherits the page
		// origin, so the hardening CSP (`img-src 'self'`) cannot treat it
		// as cross-origin when the environment answers on several local
		// hostnames (localhost vs 127.0.0.1).
		return '/lps-brand/';
	}

	/**
	 * Returns the approved institutional mark override, if one is supplied.
	 *
	 * The unified-identity contract (DESIGN.md section 6, amended for this
	 * owner-supplied artwork) makes the bundled full-colour artwork the
	 * default masthead identity; UFRJ/COPPE marks stay text-only. The
	 * `lps_masthead_mark` filter remains as the override slot for a
	 * rights-owner-supplied replacement: a returned value replaces the
	 * bundled artwork inside the persistent home link, so the link keeps
	 * its accessible name either way.
	 */
	private static function masthead_mark(): string {
		if ( ! function_exists( 'apply_filters' ) ) {
			return '';
		}
		$mark = apply_filters( 'lps_masthead_mark', '' );
		return is_string( $mark ) ? $mark : '';
	}

	/**
	 * Builds the locale control without linking absent variants.
	 *
	 * @param string                $locale   Supported locale slug.
	 * @param array<string, string> $variants Published locale URLs.
	 */
	public static function locale_markup( string $locale, array $variants ): string {
		$label   = 'en' === $locale ? 'Language' : 'Idioma';
		$missing = 'en' === $locale ? 'translation unavailable' : 'tradução indisponível';
		$items   = '';
		foreach ( array(
			'pt-br' => array(
				'short'    => 'PT',
				'hreflang' => 'pt-BR',
			),
			'en'    => array(
				'short'    => 'EN',
				'hreflang' => 'en',
			),
		) as $slug => $definition ) {
			if ( isset( $variants[ $slug ] ) ) {
				$current = $slug === $locale ? ' aria-current="page"' : '';
				$items  .= '<li><a' . $current . ' hreflang="' . $definition['hreflang'] . '" lang="' . $definition['hreflang'] . '" href="' . self::escape( self::internal_href( $variants[ $slug ] ) ) . '">' . $definition['short'] . '</a></li>';
			} else {
				$items .= '<li><span aria-disabled="true">' . $definition['short'] . ' <span class="lps-visually-hidden">(' . self::escape( $missing ) . ')</span></span></li>';
			}
		}
		return '<nav class="lps-locale" aria-label="' . self::escape( $label ) . '"><ul>' . $items . '</ul></nav>';
	}

	/**
	 * Renders a same-origin URL in the root-relative form the rest of the shell uses.
	 *
	 * Locale variants come from permalinks, which WordPress returns absolute. Every
	 * other internal link in the shell is root-relative, so an absolute same-origin
	 * locale link would tie the rendered switch to one host. URLs on any other
	 * origin are preserved untouched.
	 *
	 * @param string $url Variant URL.
	 */
	private static function internal_href( string $url ): string {
		if ( ! function_exists( 'home_url' ) || 1 !== preg_match( '~^https?://~i', $url ) ) {
			return $url;
		}
		$home   = (string) home_url( '/' );
		$host   = function_exists( 'wp_parse_url' ) ? wp_parse_url( $home, PHP_URL_HOST ) : null;
		$origin = function_exists( 'wp_parse_url' ) ? wp_parse_url( $url, PHP_URL_HOST ) : null;
		if ( ! is_string( $host ) || ! is_string( $origin ) || strtolower( $host ) !== strtolower( $origin ) ) {
			return $url;
		}
		$path = function_exists( 'wp_parse_url' ) ? wp_parse_url( $url, PHP_URL_PATH ) : null;
		if ( ! is_string( $path ) || '' === $path ) {
			return $url;
		}
		$query    = function_exists( 'wp_parse_url' ) ? wp_parse_url( $url, PHP_URL_QUERY ) : null;
		$fragment = function_exists( 'wp_parse_url' ) ? wp_parse_url( $url, PHP_URL_FRAGMENT ) : null;
		return $path
			. ( is_string( $query ) && '' !== $query ? '?' . $query : '' )
			. ( is_string( $fragment ) && '' !== $fragment ? '#' . $fragment : '' );
	}

	/**
	 * Builds the institutional footer.
	 *
	 * The footer band carries the same lockup as the masthead in its
	 * reversed off-white variant, served through the brand endpoint at a
	 * smaller size; the full-colour artwork stays off dark surfaces. The
	 * teaching entrance keeps its canonical locale route beside the four
	 * utility links.
	 *
	 * @param string $locale Supported locale slug.
	 */
	public static function footer_markup( string $locale ): string {
		$english = 'en' === $locale;
		$links   = $english
			? array(
				'Teaching'      => '/en/teaching/',
				'Contact'       => '/en/contact/',
				'Events'        => '/en/events/',
				'Privacy'       => '/en/privacy/',
				'Accessibility' => '/en/accessibility/',
			)
			: array(
				'Ensino'         => '/pt-br/ensino/',
				'Contato'        => '/pt-br/contato/',
				'Eventos'        => '/pt-br/eventos/',
				'Privacidade'    => '/pt-br/privacidade/',
				'Acessibilidade' => '/pt-br/acessibilidade/',
			);
		$items   = '';
		foreach ( $links as $label => $url ) {
			$items .= '<li><a href="' . $url . '">' . self::escape( $label ) . '</a></li>';
		}
		$nav_label = $english ? 'Institutional information' : 'Informações institucionais';
		$statement = $english ? 'Part of COPPE at the Federal University of Rio de Janeiro.' : 'Parte da COPPE na Universidade Federal do Rio de Janeiro.';
		$home      = $english ? '/en/' : '/pt-br/';
		$home_name = $english ? 'LPS - home' : 'LPS - início';
		$logo      = '<img class="lps-logo lps-footer-logo" src="' . self::brand_base_url() . 'lps_coppe_reversed_lockup.svg" alt="" width="1622" height="804" loading="lazy" decoding="async">';
		return '<footer class="lps-site-footer"><div class="lps-footer-grid lps-page-grid"><a class="lps-wordmark lps-wordmark-light" href="' . $home . '" aria-label="' . self::escape( $home_name ) . '">' . $logo . '</a><div><p>' . self::escape( $statement ) . '</p><p class="lps-meta">UFRJ <span aria-hidden="true">/</span> COPPE <span aria-hidden="true">/</span> LPS</p></div><nav aria-label="' . self::escape( $nav_label ) . '"><ul>' . $items . '</ul></nav></div></footer>';
	}

	/**
	 * Makes an authored table region reachable and nameable by keyboard.
	 *
	 * A wide table scrolls horizontally on narrow viewports. A scroll container that
	 * no control can focus is unreachable by keyboard, so the wrapper receives
	 * `tabindex="0"`, a `region` role, and a name taken from its own caption.
	 *
	 * @param string               $content Rendered block markup.
	 * @param array<string, mixed> $block   Parsed block.
	 */
	public static function make_tables_scrollable_by_keyboard( string $content, array $block ): string {
		$name = isset( $block['blockName'] ) && is_string( $block['blockName'] ) ? $block['blockName'] : '';
		if ( 'core/table' !== $name || ! str_contains( $content, 'wp-block-table' ) || str_contains( $content, 'lps-table-scroll' ) ) {
			return $content;
		}
		$english = 'en' === self::current_locale( self::request_path() );
		$caption = '';
		if ( 1 === preg_match( '#<figcaption[^>]*>(.*?)</figcaption>#su', $content, $found ) ) {
			$caption = trim( wp_strip_all_tags( $found[1] ) );
		}
		$hint  = $english ? 'table, scrollable horizontally' : 'tabela, rolagem horizontal';
		$label = '' === $caption ? ucfirst( $hint ) : $caption . ' (' . $hint . ')';
		// The scroll region is an inner `div`, not the `figure` itself: a `figure`
		// that owns a `figcaption` may not take `role="region"` (axe
		// aria-allowed-role), while a `div` may. The caption stays a direct child
		// of the `figure`, so the figcaption association is preserved.
		$fixed = preg_replace(
			'#(<figure class="wp-block-table"[^>]*>)#',
			'$1<div class="lps-table-scroll" tabindex="0" role="region" aria-label="' . esc_attr( $label ) . '">',
			$content,
			1
		);
		if ( ! is_string( $fixed ) || $fixed === $content ) {
			return $content;
		}
		if ( str_contains( $fixed, '<figcaption' ) ) {
			$fixed = preg_replace( '#<figcaption#', '</div><figcaption', $fixed, 1 );
		} else {
			$fixed = preg_replace( '#</figure>#', '</div></figure>', $fixed, 1 );
		}
		return is_string( $fixed ) ? $fixed : $content;
	}

	/**
	 * Returns the localized archive title of one record type.
	 *
	 * The record types are registered with English labels, so a Portuguese archive
	 * rendered its heading and breadcrumb in English. The public heading follows the
	 * locale of the route instead of the registration language.
	 *
	 * @param string $title     Core archive title.
	 * @param string $post_type Queried record type.
	 * @param string $locale    Supported locale slug.
	 */
	public static function archive_title_for( string $title, string $post_type, string $locale ): string {
		if ( '' === $post_type ) {
			return $title;
		}
		$label = SearchSurfaces::type_label( $post_type, $locale );
		return '' === $label ? $title : $label;
	}

	/**
	 * Applies the localized archive title to the current WordPress request.
	 *
	 * @param string $title Core archive title.
	 */
	public static function localize_archive_title( string $title ): string {
		if ( ! function_exists( 'is_post_type_archive' ) || ! is_post_type_archive() ) {
			return $title;
		}
		$queried   = function_exists( 'get_queried_object' ) ? get_queried_object() : null;
		$post_type = is_object( $queried ) && isset( $queried->name ) && is_string( $queried->name ) ? $queried->name : '';
		return self::archive_title_for( $title, $post_type, self::current_locale( self::request_path() ) );
	}

	/**
	 * Builds the localized search heading together with its announced result count.
	 *
	 * The core search template renders its heading in the site language, which left
	 * an English heading on Portuguese routes, and announced no result count at all.
	 *
	 * @param string $term   Submitted search term.
	 * @param int    $total  Matched record count.
	 * @param string $locale Supported locale slug.
	 */
	public static function search_results_markup( string $term, int $total, string $locale ): string {
		$english = 'en' === $locale;
		$heading = ( $english ? 'Search results for: ' : 'Resultados da busca para: ' ) . $term;
		return '<h1 class="wp-block-query-title">' . self::escape( $heading ) . '</h1>'
			. '<p class="lps-result-count" role="status">' . self::escape( SearchSurfaces::count_label( $total, $locale ) ) . '</p>';
	}

	/**
	 * Applies the localized search heading and count to the current request.
	 *
	 * @param string               $content Rendered block markup.
	 * @param array<string, mixed> $block   Parsed block.
	 */
	public static function localize_search_results( string $content, array $block ): string {
		$name = isset( $block['blockName'] ) && is_string( $block['blockName'] ) ? $block['blockName'] : '';
		if ( 'core/query-title' !== $name || ! function_exists( 'is_search' ) || ! is_search() ) {
			return $content;
		}
		$term  = function_exists( 'get_search_query' ) ? (string) get_search_query() : '';
		$total = 0;
		$query = $GLOBALS['wp_query'] ?? null;
		if ( is_object( $query ) && isset( $query->found_posts ) && is_numeric( $query->found_posts ) ) {
			$total = (int) $query->found_posts;
		}
		return self::search_results_markup( $term, $total, self::current_locale( self::request_path() ) );
	}

	/** Renders the dynamic header block. */
	public static function render_header(): string {
		$path   = self::request_path();
		$locale = self::current_locale( $path );
		return self::header_markup( $locale, $path, self::current_variants() );
	}

	/** Renders the dynamic footer block. */
	public static function render_footer(): string {
		$path = self::request_path();
		return self::footer_markup( self::current_locale( $path ) );
	}

	/** Renders core-post metadata through the public shell's locale path. */
	public static function render_post_metadata(): string {
		$raw_date = get_the_date( 'Y-m-d' );
		$raw_time = get_the_date( 'c' );
		// WordPress can return false without a post, or a non-string from date filters.
		if ( ! is_string( $raw_date ) || ! is_string( $raw_time ) ) {
			return '';
		}
		$locale           = self::current_locale( self::request_path() );
		$labels           = array();
		$default_category = get_option( 'default_category' );
		foreach ( get_the_category() as $category ) {
			// Default CMS taxonomy is a missing-category state, not a public archive.
			$labels[] = is_numeric( $default_category ) && (int) $default_category === $category->term_id
				? ( 'en' === $locale ? 'Uncategorized' : 'Sem categoria' )
				: $category->name;
		}
		return self::post_metadata_markup( $raw_date, $raw_time, $labels, $locale );
	}

	/**
	 * Builds the localized core-post metadata markup.
	 *
	 * @param string             $raw_date   Stored publication date.
	 * @param string             $raw_time   Stored publication timestamp.
	 * @param array<int, string> $categories Public category labels, as stored by WordPress.
	 * @param string             $locale     Supported locale slug.
	 */
	public static function post_metadata_markup( string $raw_date, string $raw_time, array $categories, string $locale ): string {
		$date   = DiscoverySurfaces::format_date( $raw_date, 'day', $locale );
		$html   = '<div class="wp-block-post-date"><time datetime="' . esc_attr( $raw_time ) . '">' . self::escape( $date ) . '</time></div>';
		$labels = array();
		foreach ( $categories as $category ) {
			// WordPress stores term names entity-encoded, like titles; decode before output escaping.
			$labels[] = html_entity_decode( $category, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		}
		if ( array() !== $labels ) {
			$html .= '<div class="wp-block-post-terms">' . self::escape( implode( ', ', $labels ) ) . '</div>';
		}
		return $html;
	}

	/** Renders breadcrumbs outside front-page and 404 contexts. */
	public static function render_breadcrumbs(): string {
		if ( ! function_exists( 'is_front_page' ) || is_front_page() || is_404() ) {
			return '';
		}
		$path    = self::request_path();
		$locale  = self::current_locale( $path );
		if ( null !== TeachingRoutes::match_path( $path ) ) {
			// Teaching routes carry their full context: landing, course, and the
			// offering's section crumb — the same trail the JSON-LD graph emits.
			$label  = 'en' === $locale ? 'Breadcrumb' : 'Trilha de navegação';
			$items  = SeoRoutes::breadcrumb_items( $path );
			$markup = '<nav class="lps-breadcrumbs lps-page-grid" aria-label="' . self::escape( $label ) . '"><ol>';
			$last   = count( $items ) - 1;
			foreach ( $items as $index => $item ) {
				if ( $index === $last ) {
					$markup .= '<li aria-current="page">' . self::escape( $item['name'] ) . '</li>';
					continue;
				}
				$markup .= '<li><a href="' . self::escape( $item['path'] ) . '">' . self::escape( $item['name'] ) . '</a></li>';
			}
			return $markup . '</ol></nav>';
		}
		$english = 'en' === $locale;
		$home    = $english ? '/en/' : '/pt-br/';
		$label   = $english ? 'Breadcrumb' : 'Trilha de navegação';
		$context = 'singular';
		if ( function_exists( 'is_search' ) && is_search() ) {
			$context = 'search';
		} elseif ( function_exists( 'is_singular' ) && ! is_singular() ) {
			$context = 'archive';
		}
		$archive = '';
		if ( 'archive' === $context && function_exists( 'is_home' ) && is_home() ) {
			// The posts index is titled by the theme, not by the core `Archives` string.
			$archive = self::index_title_text( $locale, self::posts_page_title() );
		} elseif ( 'archive' === $context && function_exists( 'is_date' ) && is_date() ) {
			$year    = self::query_string_var( 'year' );
			$archive = self::archive_title_text( $locale, 'date', $year );
		} elseif ( 'archive' === $context && function_exists( 'is_post_type_archive' ) && is_post_type_archive() ) {
			$type    = self::query_string_var( 'post_type' );
			$archive = self::archive_title_text( $locale, $type, '' );
		} elseif ( 'archive' === $context && function_exists( 'get_the_archive_title' ) ) {
			$archive = function_exists( 'wp_strip_all_tags' )
				? wp_strip_all_tags( (string) get_the_archive_title() )
				: (string) get_the_archive_title();
		}
		$title = self::breadcrumb_title(
			$context,
			function_exists( 'get_the_title' ) ? (string) get_the_title() : '',
			$archive,
			$locale
		);
		// WordPress title filters already encode entities; decode before output escaping.
		return '<nav class="lps-breadcrumbs lps-page-grid" aria-label="' . self::escape( $label ) . '"><ol><li><a href="' . $home . '">' . ( $english ? 'Home' : 'Início' ) . '</a></li><li aria-current="page">' . self::escape( html_entity_decode( $title, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) . '</li></ol></nav>';
	}

	/**
	 * Returns the breadcrumb title of one request context.
	 *
	 * A listing names itself: the record that happens to lead the loop may be
	 * withheld, so its title never reaches the trail.
	 *
	 * @param string $context       One of singular, archive, or search.
	 * @param string $post_title    Title of the record in the loop.
	 * @param string $archive_title Title of the current listing.
	 * @param string $locale        Supported locale slug.
	 */
	public static function breadcrumb_title( string $context, string $post_title, string $archive_title, string $locale ): string {
		$english = 'en' === $locale;
		if ( 'search' === $context ) {
			return $english ? 'Search results' : 'Resultados da busca';
		}
		if ( 'archive' === $context ) {
			if ( '' !== trim( $archive_title ) ) {
				return $archive_title;
			}
			return $english ? 'Listing' : 'Listagem';
		}
		return $post_title;
	}

	/** Renders the localized empty-result state. */
	public static function render_empty_state(): string {
		$path    = self::request_path();
		$english = 'en' === self::current_locale( $path );
		return '<section class="lps-empty-state" aria-labelledby="lps-empty-title"><p class="lps-kicker">' . ( $english ? 'NO RECORDS' : 'SEM REGISTROS' ) . '</p><h2 id="lps-empty-title">' . ( $english ? 'Nothing matched this view' : 'Nenhum conteúdo corresponde a esta vista' ) . '</h2><p>' . ( $english ? 'Review the search terms or return to the locale home page.' : 'Revise os termos de busca ou volte ao início deste idioma.' ) . '</p></section>';
	}

	/**
	 * Builds the posts-index heading.
	 *
	 * The block-theme fallback template has no core block that titles the posts
	 * index: `query-title` only renders for archives and search, which would leave
	 * that public surface with no H1 and a heading level jump straight to H2.
	 *
	 * @param string $locale Supported locale slug.
	 * @param string $title  Resolved page title for the posts index.
	 */
	public static function index_title_markup( string $locale, string $title ): string {
		return '<h1 class="lps-page-title">' . self::escape( self::index_title_text( $locale, $title ) ) . '</h1>';
	}

	/**
	 * Returns the localized posts-index title shared by the heading and the trail.
	 *
	 * WordPress titles the posts index with the core `Archives` string, which is
	 * rendered in the site locale rather than the page locale and would put English
	 * chrome on a Portuguese page. The theme therefore names this surface itself.
	 *
	 * @param string $locale Supported locale slug.
	 * @param string $title  Resolved page title for the posts index.
	 */
	public static function index_title_text( string $locale, string $title ): string {
		$text = trim( $title );
		if ( '' !== $text ) {
			return $text;
		}
		return 'en' === $locale ? 'LPS posts' : 'Publicações do LPS';
	}

	/**
	 * Returns the localized title of an archive surface.
	 *
	 * WordPress derives archive titles from post-type labels and core strings in the
	 * site locale, which put English chrome ("Organizations", "Year: 2026") on
	 * Portuguese routes. The theme owns these labels instead.
	 *
	 * @param string $locale  Supported locale slug.
	 * @param string $context Post type name, or `date` for a date archive.
	 * @param string $value   Context value, such as the year of a date archive.
	 */
	public static function archive_title_text( string $locale, string $context, string $value ): string {
		$english = 'en' === $locale;
		if ( 'date' === $context ) {
			return ( $english ? 'Year: ' : 'Ano: ' ) . $value;
		}
		$labels = array(
			'lps_research_area' => array( 'Pesquisa', 'Research' ),
			'lps_project'       => array( 'Projetos', 'Projects' ),
			'lps_publication'   => array( 'Publicações', 'Publications' ),
			'lps_person'        => array( 'Pessoas', 'People' ),
			'lps_news'          => array( 'Notícias', 'News' ),
			'lps_event'         => array( 'Eventos', 'Events' ),
			'lps_opportunity'   => array( 'Oportunidades', 'Opportunities' ),
			'lps_organization'  => array( 'Organizações', 'Organizations' ),
		);
		if ( isset( $labels[ $context ] ) ) {
			return $labels[ $context ][ $english ? 1 : 0 ];
		}
		return $english ? 'Listing' : 'Listagem';
	}

	/**
	 * Reads one query variable as a plain string.
	 *
	 * @param string $name Query variable name.
	 */
	private static function query_string_var( string $name ): string {
		if ( ! function_exists( 'get_query_var' ) ) {
			return '';
		}
		$value = get_query_var( $name );
		return is_scalar( $value ) ? (string) $value : '';
	}

	/** Renders the localized archive heading for the current query. */
	public static function render_archive_title(): string {
		if ( ! function_exists( 'is_post_type_archive' ) ) {
			return '';
		}
		$locale = self::current_locale( self::request_path() );
		if ( function_exists( 'is_date' ) && is_date() && function_exists( 'get_query_var' ) ) {
			return '<h1 class="lps-page-title">' . self::escape( self::archive_title_text( $locale, 'date', self::query_string_var( 'year' ) ) ) . '</h1>';
		}
		if ( ! is_post_type_archive() ) {
			return '';
		}
		return '<h1 class="lps-page-title">' . self::escape( self::archive_title_text( $locale, self::query_string_var( 'post_type' ), '' ) ) . '</h1>';
	}

	/** Resolves the posts-index page title from WordPress, when one is configured. */
	private static function posts_page_title(): string {
		if ( ! function_exists( 'get_option' ) || ! function_exists( 'get_the_title' ) ) {
			return '';
		}
		$option     = get_option( 'page_for_posts' );
		$posts_page = is_scalar( $option ) ? (int) $option : 0;
		if ( 0 === $posts_page ) {
			return '';
		}
		return (string) get_the_title( $posts_page );
	}

	/** Renders the posts-index heading only where WordPress titles nothing itself. */
	public static function render_index_title(): string {
		if ( ! function_exists( 'is_home' ) || ! is_home() ) {
			return '';
		}
		$path = self::request_path();
		return self::index_title_markup( self::current_locale( $path ), self::posts_page_title() );
	}

	/** Renders the localized 404 state. */
	public static function render_not_found(): string {
		$path    = self::request_path();
		$english = 'en' === self::current_locale( $path );
		$home    = $english ? '/en/' : '/pt-br/';
		return '<section class="lps-not-found"><p class="lps-meta">HTTP 404</p><h1>' . ( $english ? 'Page not found' : 'Página não encontrada' ) . '</h1><p>' . ( $english ? 'The address may have changed or the translation may not be published.' : 'O endereço pode ter mudado ou a tradução pode não estar publicada.' ) . '</p><a class="lps-button lps-button-primary" href="' . $home . '">' . ( $english ? 'Return home' : 'Voltar ao início' ) . '</a></section>';
	}

	/**
	 * Resolves the current locale from Polylang or the route.
	 *
	 * @param string $path Current public path.
	 */
	private static function current_locale( string $path ): string {
		if ( function_exists( 'pll_current_language' ) ) {
			$value = pll_current_language( 'slug' );
			if ( 'pt-br' === $value || 'en' === $value ) {
				return $value;
			}
		}
		return self::locale_from_path( $path );
	}

	/**
	 * Returns the published locale variants of the current page.
	 *
	 * Singular records link to their canonical locale route — never the
	 * default permalink — and only when the public surface actually serves
	 * the variant. Non-singular routes link to their locale counterpart
	 * (landing, listing or search), preserving the search query; anything
	 * without a counterpart falls back to the locale home.
	 *
	 * @return array<string, string>
	 */
	private static function current_variants(): array {
		$path = self::request_path();
		if ( null !== SearchRoutes::match_path( $path ) ) {
			// The search page is a singular page record, but its language switch
			// belongs to the route: it preserves the query, never the page slug.
			$locale      = self::current_locale( $path );
			$counterpart = SearchRoutes::search_path( 'pt-br' === $locale ? 'en' : 'pt-br' );
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only propagation of the current search query.
			$query = isset( $_GET['q'] ) && is_string( $_GET['q'] ) ? trim( sanitize_text_field( wp_unslash( $_GET['q'] ) ) ) : '';
			$suffix = '' === $query ? '' : '?q=' . rawurlencode( $query );
			return array(
				$locale => $path . $suffix,
				'pt-br' === $locale ? 'en' : 'pt-br' => $counterpart . $suffix,
			);
		}
		if ( function_exists( 'is_singular' ) && is_singular() && function_exists( 'pll_get_post_translations' ) && function_exists( 'get_queried_object_id' ) ) {
			$result       = array();
			$translations = pll_get_post_translations( get_queried_object_id() );
			if ( is_array( $translations ) ) {
				foreach ( $translations as $slug => $post_id ) {
					if ( ! is_int( $post_id ) || ( 'pt-br' !== $slug && 'en' !== $slug ) ) {
						continue;
					}
					$post = get_post( $post_id );
					if ( ! $post instanceof WP_Post || 'publish' !== $post->post_status ) {
						continue;
					}
					if ( ! SeoRoutes::publicly_visible( $post, $slug ) ) {
						continue;
					}
					$canonical = SeoRoutes::record_path( $post, $slug );
					if ( '' !== $canonical ) {
						$result[ $slug ] = $canonical;
						continue;
					}
					$url = get_permalink( $post_id );
					if ( is_string( $url ) ) {
						$result[ $slug ] = $url;
					}
				}
			}
			return $result;
		}
		$locale      = self::current_locale( $path );
		$counterpart = SeoRoutes::counterpart_path( $path, $locale );
		if ( '' !== $counterpart ) {
			$variants = array(
				$locale => $path,
				'pt-br' === $locale ? 'en' : 'pt-br' => $counterpart,
			);
			// The search form state survives the language switch so a reader
			// never loses the term they typed.
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only propagation of the current search query.
			$query = isset( $_GET['q'] ) && is_string( $_GET['q'] ) ? trim( sanitize_text_field( wp_unslash( $_GET['q'] ) ) ) : '';
			if ( '' !== $query && null !== SearchRoutes::match_path( $path ) ) {
				foreach ( $variants as $slug => $variant_path ) {
					$variants[ $slug ] = $variant_path . '?q=' . rawurlencode( $query );
				}
			}
			return $variants;
		}
		return array(
			'pt-br' => '/pt-br/',
			'en'    => '/en/',
		);
	}

	/** Returns the sanitized current request path. */
	private static function request_path(): string {
		if ( ! isset( $_SERVER['REQUEST_URI'] ) || ! is_string( $_SERVER['REQUEST_URI'] ) ) {
			return '/pt-br/';
		}
		$request = sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) );
		$path    = wp_parse_url( $request, PHP_URL_PATH );
		return is_string( $path ) ? $path : '/pt-br/';
	}

	/**
	 * Escapes one text value for markup.
	 *
	 * @param string $value Untrusted text value.
	 */
	private static function escape( string $value ): string {
		return htmlspecialchars( $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );
	}
}
