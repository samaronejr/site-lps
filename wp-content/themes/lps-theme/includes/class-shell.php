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
	 * Items with `children` render as disclosure menus matching the showcase's
	 * grouped information architecture.
	 *
	 * @param string $locale Supported locale slug.
	 * @return array<string, array{label: string, url: string, children?: array<int, array{label: string, url: string}>}>
	 */
	public static function navigation( string $locale ): array {
		$english = 'en' === $locale;
		return array(
			'about'         => array(
				'label'    => $english ? 'About' : 'Sobre',
				'url'      => $english ? '/en/about/' : '/pt-br/sobre/',
				'children' => array(
					array(
						'label' => $english ? 'History' : 'História',
						'url'   => ( $english ? '/en/about/' : '/pt-br/sobre/' ) . '#historia',
					),
					array(
						'label' => $english ? 'Mission, vision and values' : 'Missão, visão e valores',
						'url'   => ( $english ? '/en/about/' : '/pt-br/sobre/' ) . '#missao',
					),
					array(
						'label' => $english ? 'Infrastructure' : 'Infraestrutura',
						'url'   => $english ? '/en/infrastructure/' : '/pt-br/infraestrutura/',
					),
					array(
						'label' => $english ? 'Visual identity' : 'Identidade visual',
						'url'   => $english ? '/en/visual-identity/' : '/pt-br/identidade-visual/',
					),
				),
			),
			'research'      => array(
				'label'    => $english ? 'Research' : 'Pesquisa',
				'url'      => $english ? '/en/research/' : '/pt-br/pesquisa/',
				'children' => array(
					array(
						'label' => $english ? 'Research areas' : 'Linhas de pesquisa',
						'url'   => ( $english ? '/en/research/' : '/pt-br/pesquisa/' ) . '#linhas',
					),
					array(
						'label' => $english ? 'Projects' : 'Projetos',
						'url'   => $english ? '/en/projects/' : '/pt-br/projetos/',
					),
					array(
						'label' => $english ? 'Publications' : 'Publicações',
						'url'   => $english ? '/en/publications/' : '/pt-br/publicacoes/',
					),
				),
			),
			'people'        => array(
				'label' => $english ? 'People' : 'Pessoas',
				'url'   => $english ? '/en/people/' : '/pt-br/pessoas/',
			),
			'teaching'      => array(
				'label'    => $english ? 'Teaching' : 'Ensino',
				'url'      => $english ? '/en/teaching/' : '/pt-br/ensino/',
				'children' => array(
					array(
						'label' => $english ? 'Graduate' : 'Pós-graduação',
						'url'   => ( $english ? '/en/teaching/' : '/pt-br/ensino/' ) . '#pos-graduacao',
					),
					array(
						'label' => $english ? 'Undergraduate' : 'Graduação',
						'url'   => ( $english ? '/en/teaching/' : '/pt-br/ensino/' ) . '#graduacao',
					),
					array(
						'label' => $english ? 'Course materials' : 'Materiais didáticos',
						'url'   => ( $english ? '/en/teaching/' : '/pt-br/ensino/' ) . '#materiais',
					),
				),
			),
			'opportunities' => array(
				'label' => $english ? 'Opportunities' : 'Oportunidades',
				'url'   => $english ? '/en/opportunities/' : '/pt-br/oportunidades/',
			),
			'news'          => array(
				'label' => $english ? 'News and events' : 'Notícias e eventos',
				'url'   => $english ? '/en/news/' : '/pt-br/noticias/',
			),
		);
	}

	/**
	 * Renders one primary-navigation list: dropdown groups for items that carry
	 * children, plain links otherwise.
	 *
	 * @param string $locale Supported locale slug.
	 * @param string $path   Current request path for `aria-current`.
	 * @return string Navigation list markup.
	 */
	private static function navigation_markup( string $locale, string $path ): string {
		$items = '';
		foreach ( self::navigation( $locale ) as $item ) {
			$current  = rtrim( $path, '/' ) === rtrim( $item['url'], '/' ) ? ' aria-current="page"' : '';
			$items   .= '<li class="lps-nav-item"><a class="lps-nav-link"' . $current . ' href="' . self::escape( $item['url'] ) . '">' . self::escape( $item['label'] );
			$children = $item['children'] ?? array();
			if ( array() !== $children ) {
				$items .= '<span class="lps-nav-caret" aria-hidden="true"></span></a><ul class="lps-nav-menu">';
				foreach ( $children as $child ) {
					$child_current = rtrim( $path, '/' ) === rtrim( $child['url'], '/' ) ? ' aria-current="page"' : '';
					$items        .= '<li><a' . $child_current . ' href="' . self::escape( $child['url'] ) . '">' . self::escape( $child['label'] ) . '</a></li>';
				}
				$items .= '</ul></li>';
			} else {
				$items .= '</a></li>';
			}
		}
		return '<ul>' . $items . '</ul>';
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
		$items           = self::navigation_markup( $locale, $path );
		$locale_switch   = self::locale_switch_markup(
			$locale,
			$variants ?? array(
				'pt-br' => '/pt-br/',
				'en'    => '/en/',
			)
		);
		$search_tools    = static function ( string $input_id ) use ( $search_action, $search_label, $search_button ): string {
			return '<form class="lps-search" role="search" action="' . $search_action . '" method="get"><label for="' . $input_id . '">' . self::escape( $search_label ) . '</label><div><input id="' . $input_id . '" name="q" type="search" autocomplete="off"><button class="lps-button" type="submit">' . self::escape( $search_button ) . '</button></div></form>';
		};
		$collaborate_cta = '<a class="lps-button lps-button-primary" href="' . $collaborate_url . '">' . self::escape( $collaborate ) . '</a>';
		$mark            = self::masthead_brand();
		$quick_label     = $english ? 'Quick access' : 'Acesso rápido';
		$utility_items   = '';
		foreach ( self::utility_links( $locale ) as $label => $url ) {
			$current        = rtrim( $path, '/' ) === rtrim( $url, '/' ) ? ' aria-current="page"' : '';
			$utility_items .= '<li><a' . $current . ' href="' . self::escape( $url ) . '">' . self::escape( $label ) . '</a></li>';
		}
		$affiliation = $english ? 'Signal Processing Laboratory · UFRJ · COPPE' : 'Laboratório de Processamento de Sinais · UFRJ · COPPE';
		return '<a class="lps-skip-link" href="#lps-main">' . self::escape( $skip ) . '</a>'
			. '<header class="lps-site-header">'
			. '<div class="lps-utility-bar"><div class="lps-utility-inner lps-page-grid"><p>' . self::escape( $affiliation ) . '</p><nav aria-label="' . self::escape( $quick_label ) . '"><ul class="lps-utility-links">' . $utility_items . '</ul></nav>' . $locale_switch . self::session_link( $locale ) . '</div></div>'
			// The masthead pairs the artwork with the visitor's primary tools —
			// search and the collaborate entrance — the way the showcase pins them
			// beside the mark instead of hiding them inside the disclosure.
			. '<div class="lps-masthead lps-page-grid"><a class="lps-brand" href="' . $home . '" aria-label="LPS — ' . ( $english ? 'home' : 'início' ) . '">' . $mark . '</a><div class="lps-shell-tools">' . $search_tools( 'lps-search-input-1' ) . $collaborate_cta . '</div></div>'
			. '<div class="lps-masthead-nav lps-page-grid"><nav class="lps-primary-nav" aria-label="' . self::escape( $nav_label ) . '">' . $items . '</nav></div>'
			. '<details class="lps-shell-disclosure"><summary>' . self::escape( $menu ) . '</summary><div class="lps-nav-panel lps-page-grid">'
			. '<nav class="lps-primary-nav" aria-label="' . self::escape( $nav_label ) . '">' . $items . '</nav>'
			. '<div class="lps-shell-tools">' . $search_tools( 'lps-search-input-2' ) . $collaborate_cta . '</div></div></details></header>';
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
			return '<span class="lps-logo-slot">' . $approved . '</span>';
		}
		$sources = self::logo_sources();
		if ( '' === $sources['full'] ) {
			return 'LPS';
		}
		// The artwork sits inside the logo slot the showcase defines and keeps
		// an empty alternative: the link's aria-label already names the home
		// destination, so a wordmark alt would announce the brand twice.
		// The logo never claims a priority hint: fetchpriority is reserved for
		// the single LCP image so the brand mark cannot compete with it.
		return '<span class="lps-logo-slot"><img class="lps-logo" src="' . self::escape( $sources['full'] ) . '" alt="" width="1622" height="804" decoding="async"></span>';
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
	 * Renders the locale dropdown shown in the utility band — the same
	 * variant set as `locale_markup`, as a no-JS disclosure with flag
	 * artwork, like the reference institution's language control.
	 *
	 * @param string                $locale   Supported locale slug.
	 * @param array<string, string> $variants Published locale URLs.
	 * @return string Locale switch markup.
	 */
	private static function locale_switch_markup( string $locale, array $variants ): string {
		$label   = 'en' === $locale ? 'Language' : 'Idioma';
		$missing = 'en' === $locale ? 'translation unavailable' : 'tradução indisponível';
		$locales = array(
			'pt-br' => array(
				'short'    => 'PT',
				'full'     => 'Português',
				'hreflang' => 'pt-BR',
			),
			'en'    => array(
				'short'    => 'EN',
				'full'     => 'English',
				'hreflang' => 'en',
			),
		);
		$items = '';
		foreach ( $locales as $slug => $definition ) {
			$entry = self::locale_flag( $slug ) . '<span>' . $definition['full'] . '</span>';
			if ( ! isset( $variants[ $slug ] ) ) {
				$items .= '<li><span aria-disabled="true">' . $entry . ' <span class="lps-visually-hidden">(' . self::escape( $missing ) . ')</span></span></li>';
				continue;
			}
			$current = $slug === $locale ? ' aria-current="page"' : '';
			$items  .= '<li><a' . $current . ' hreflang="' . $definition['hreflang'] . '" lang="' . $definition['hreflang'] . '" href="' . self::escape( self::internal_href( $variants[ $slug ] ) ) . '">' . $entry . '</a></li>';
		}
		$short = isset( $locales[ $locale ] ) ? $locales[ $locale ]['short'] : 'PT';
		return '<nav class="lps-locale-switch" aria-label="' . self::escape( $label ) . '"><details>'
			. '<summary>' . self::locale_flag( $locale ) . '<span>' . self::escape( $short ) . '</span><span class="lps-locale-caret" aria-hidden="true"></span></summary>'
			. '<ul class="lps-locale-menu">' . $items . '</ul></details></nav>';
	}

	/**
	 * Returns the inline flag artwork for one locale.
	 *
	 * @param string $slug Locale slug (pt-br|en).
	 * @return string SVG markup, empty for unknown locales.
	 */
	private static function locale_flag( string $slug ): string {
		switch ( $slug ) {
			case 'pt-br':
				return '<svg class="lps-flag" viewBox="0 0 18 13" aria-hidden="true" focusable="false"><rect width="18" height="13" fill="#009C3B"/><path d="M9 2 16.2 6.5 9 11 1.8 6.5Z" fill="#FFDF00"/><circle cx="9" cy="6.5" r="2.1" fill="#002776"/></svg>';
			case 'en':
				return '<svg class="lps-flag" viewBox="0 0 18 13" aria-hidden="true" focusable="false"><rect width="18" height="13" fill="#fff"/><path d="M0 1h18M0 3h18M0 5h18M0 7h18M0 9h18M0 11h18" stroke="#B22234"/><rect width="8" height="7" fill="#3C3B6E"/></svg>';
			default:
				return '';
		}
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
		$base    = $english ? '/en' : '/pt-br';
		$groups  = $english
			? array(
				'The laboratory' => array(
					'About'           => $base . '/about/',
					'History'         => $base . '/about/#historia',
					'People'          => $base . '/people/',
					'Infrastructure'  => $base . '/infrastructure/',
					'Visual identity' => $base . '/visual-identity/',
				),
				'Research'       => array(
					'Research areas' => $base . '/research/',
					'Projects'       => $base . '/projects/',
					'Publications'   => $base . '/publications/',
					'Teaching'       => $base . '/teaching/',
					'News'           => $base . '/news/',
				),
				'Take part'      => array(
					'Opportunities' => $base . '/opportunities/',
					'Search'        => $base . '/search/',
					'Contact'       => $base . '/contact/',
					'Accessibility' => $base . '/accessibility/',
					'Privacy'       => $base . '/privacy/',
				),
			)
			: array(
				'O laboratório' => array(
					'Sobre'             => $base . '/sobre/',
					'História'          => $base . '/sobre/#historia',
					'Pessoas'           => $base . '/pessoas/',
					'Infraestrutura'    => $base . '/infraestrutura/',
					'Identidade visual' => $base . '/identidade-visual/',
				),
				'Pesquisa'      => array(
					'Linhas de pesquisa' => $base . '/pesquisa/',
					'Projetos'           => $base . '/projetos/',
					'Publicações'        => $base . '/publicacoes/',
					'Ensino'             => $base . '/ensino/',
					'Notícias e eventos' => $base . '/noticias/',
				),
				'Participe'     => array(
					'Oportunidades'  => $base . '/oportunidades/',
					'Busca'          => $base . '/busca/',
					'Contato'        => $base . '/contato/',
					'Acessibilidade' => $base . '/acessibilidade/',
					'Privacidade'    => $base . '/privacidade/',
				),
			);
		$columns = '';
		foreach ( $groups as $group_label => $links ) {
			$items = '';
			foreach ( $links as $label => $url ) {
				$items .= '<li><a href="' . self::escape( $url ) . '">' . self::escape( $label ) . '</a></li>';
			}
			$columns .= '<div><h2>' . self::escape( $group_label ) . '</h2><ul>' . $items . '</ul></div>';
		}
		$home      = $english ? '/en/' : '/pt-br/';
		$home_name = $english ? 'LPS — home' : 'LPS — início';
		$alt       = $english ? 'LPS — Signal Processing Laboratory' : 'LPS — Laboratório de Processamento de Sinais';
		$logo      = '<img class="lps-logo" src="' . self::brand_base_url() . 'lps_coppe_reversed_lockup.svg" alt="' . self::escape( $alt ) . '" width="1622" height="804" loading="lazy" decoding="async">';
		// The postal address stays verbatim in both locales — only the phone
		// extension word localizes, matching the showcase footer contract.
		$address   = '<address>'
			. 'Av. Athos da Silveira Ramos, 149<br>'
			. 'Centro de Tecnologia, Bloco H, sala 220<br>Cidade Universitária, Ilha do Fundão<br>Rio de Janeiro — RJ, CEP 21941-914<br>'
			. '<a href="tel:+552139388205">(21) 3938-8205</a> · ' . ( $english ? 'Extension 8205' : 'Ramal 8205' ) . '<br>'
			. '<a href="mailto:secretaria@lps.ufrj.br">secretaria@lps.ufrj.br</a>'
			. '</address>';
		$copyright = $english
			? '© 2026 Signal Processing Laboratory. All rights reserved.'
			: '© 2026 Laboratório de Processamento de Sinais. Todos os direitos reservados.';
		return '<footer class="lps-site-footer"><div class="lps-footer-inner lps-page-grid">'
			. '<div class="lps-footer-brand"><a class="lps-wordmark lps-wordmark-light" href="' . $home . '" aria-label="' . self::escape( $home_name ) . '">' . $logo . '</a>' . $address . '</div>'
			. $columns
			. '</div><div class="lps-footer-bottom lps-page-grid"><p>' . self::escape( $copyright ) . '</p>'
			. '<ul><li><a href="https://www.pee.ufrj.br/">PEE/COPPE</a></li><li><a href="https://coppe.ufrj.br/">COPPE</a></li><li><a href="https://ufrj.br/">UFRJ</a></li></ul></div></footer>';
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
		$path   = self::request_path();
		$locale = self::current_locale( $path );
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
		return self::archive_header_markup( $locale, self::query_string_var( 'post_type' ) );
	}

	/**
	 * Builds the page-header band one record-type archive opens with.
	 *
	 * Every listing follows the showcase contract: a kicker, the heading, a
	 * lead line and — where the listing states its provenance — a meta line.
	 * Types without a band keep the plain localized title.
	 *
	 * @param string $locale    Supported locale slug.
	 * @param string $post_type Queried record type.
	 */
	public static function archive_header_markup( string $locale, string $post_type ): string {
		$english = 'en' === $locale;
		$headers = array(
			'lps_research_area' => array(
				'kicker' => $english ? 'Research' : 'Pesquisa',
				'title'  => $english ? 'Research areas' : 'Linhas de pesquisa',
				'lead'   => $english
					? 'The main areas of activity are digital signal processing, supervised and unsupervised data modelling, feature engineering, recommender systems, time-series analysis and fault, fraud and novelty detection.'
					: 'As principais áreas de atuação são o processamento digital de sinais, a modelagem de dados supervisionada e não supervisionada, a engenharia de características, os sistemas de recomendação, a análise de séries temporais e a detecção de falhas, fraudes e novidades.',
			),
			'lps_project'       => array(
				'kicker' => $english ? 'Research' : 'Pesquisa',
				'title'  => $english ? 'Projects' : 'Projetos',
				'lead'   => $english
					? 'Research and development projects recorded in the laboratory public material, with the partner institutions they were built with.'
					: 'Projetos de pesquisa e desenvolvimento registrados no material público do laboratório, com as instituições parceiras com que foram construídos.',
				'meta'   => $english
					? 'Source: the laboratory public pages, January 2025.'
					: 'Fonte: páginas públicas do laboratório, janeiro de 2025.',
			),
			'lps_publication'   => array(
				'kicker' => $english ? 'Research' : 'Pesquisa',
				'title'  => $english ? 'Publications' : 'Publicações',
				'lead'   => $english
					? 'The scientific output associated with the laboratory, and how to consult it today.'
					: 'A produção científica associada ao laboratório, e como consultá-la hoje.',
			),
			'lps_person'        => array(
				'kicker' => $english ? 'People' : 'Pessoas',
				'title'  => $english ? 'The laboratory team' : 'A equipe do laboratório',
				'lead'   => $english
					? 'Four full-time professors — two of them full professors — coordinate the laboratory together with post-doctoral researchers and graduate and undergraduate students.'
					: 'Quatro professores em tempo integral — dois deles titulares — coordenam o laboratório junto com pesquisadores de pós-doutorado e estudantes de pós-graduação e graduação.',
			),
			'lps_news'          => array(
				'kicker' => $english ? 'News and events' : 'Notícias e eventos',
				'title'  => $english ? 'News and events' : 'Notícias e eventos',
				'lead'   => $english
					? 'Dated institutional records about the laboratory, each traceable to the public source it came from.'
					: 'Registros institucionais datados sobre o laboratório, cada um rastreável à fonte pública de origem.',
			),
			'lps_opportunity'   => array(
				'kicker' => $english ? 'Take part' : 'Participe',
				'title'  => $english ? 'Opportunities' : 'Oportunidades',
				'lead'   => $english
					? 'Research initiation, master and doctoral places, post-doctorate and project collaboration at the Signal Processing Laboratory.'
					: 'Iniciação científica, vagas de mestrado e doutorado, pós-doutorado e colaboração em projetos no Laboratório de Processamento de Sinais.',
			),
			'lps_organization'  => array(
				'kicker' => $english ? 'Partners and funders' : 'Parceiros e financiadores',
				'title'  => $english ? 'Organizations' : 'Organizações',
				'lead'   => $english
					? 'Companies, funding agencies and the international collaborations that support laboratory projects.'
					: 'Empresas, agências de fomento e as colaborações internacionais que sustentam os projetos do laboratório.',
			),
			'lps_event'         => array(
				'kicker' => $english ? 'News and events' : 'Notícias e eventos',
				'title'  => $english ? 'Events' : 'Eventos',
			),
		);
		$header  = $headers[ $post_type ] ?? null;
		if ( null === $header ) {
			return '<h1 class="lps-page-title">' . self::escape( self::archive_title_text( $locale, $post_type, '' ) ) . '</h1>';
		}
		return self::page_header_markup( $header, $locale );
	}

	/**
	 * Renders one page-header band from kicker, title, lead and meta parts.
	 *
	 * @param array<string, string> $header Localized band copy; any part may be absent.
	 * @param string                $locale Supported locale slug.
	 */
	public static function page_header_markup( array $header, string $locale ): string { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- The band contract carries the locale for localized parts; every caller already passes it.
		$html = '<div class="lps-page-header"><div class="lps-page-header-inner lps-page-grid">';
		if ( isset( $header['kicker'] ) && '' !== $header['kicker'] ) {
			$html .= '<p class="lps-kicker">' . self::escape( $header['kicker'] ) . '</p>';
		}
		$html .= '<h1 class="lps-page-title">' . self::escape( $header['title'] ?? '' ) . '</h1>';
		if ( isset( $header['lead'] ) && '' !== $header['lead'] ) {
			$html .= '<p class="lps-lead">' . self::escape( $header['lead'] ) . '</p>';
		}
		if ( isset( $header['meta'] ) && '' !== $header['meta'] ) {
			$html .= '<p class="lps-meta lps-mt-6">' . self::escape( $header['meta'] ) . '</p>';
		}
		return $html . '</div></div>';
	}

	/**
	 * Replaces the bare page title with the page-header band on governed pages.
	 *
	 * Institutional records carry a `_lps_page_key` that ties them to a frozen
	 * route family; those pages open with the same band every archive does —
	 * kicker, heading, lead — instead of the raw `core/post-title` output.
	 * Pages without a key keep the core title untouched.
	 *
	 * @param string               $content Rendered block markup.
	 * @param array<string, mixed> $block   Parsed block.
	 */
	public static function institutional_page_title( string $content, array $block ): string {
		if ( 'core/post-title' !== ( $block['blockName'] ?? '' ) ) {
			return $content;
		}
		if ( ! function_exists( 'is_singular' ) || ! is_singular( 'page' ) || ! function_exists( 'get_post' ) ) {
			return $content;
		}
		$post = get_post();
		if ( ! $post instanceof WP_Post ) {
			return $content;
		}
		$shared    = class_exists( \LPS\ContentModel\TranslationPolicy::class ) ? \LPS\ContentModel\TranslationPolicy::shared_meta_keys( $post->post_type ) : array();
		$source_id = class_exists( \LPS\ContentModel\Translations::class ) ? \LPS\ContentModel\Translations::source_id( $post->ID ) ?? $post->ID : $post->ID;
		$key_id    = in_array( '_lps_page_key', $shared, true ) ? $source_id : $post->ID;
		$raw_key   = function_exists( 'get_post_meta' ) ? get_post_meta( $key_id, '_lps_page_key', true ) : '';
		$key       = is_string( $raw_key ) ? $raw_key : '';
		if ( '' === $key ) {
			return $content;
		}
		$locale = self::current_locale( self::request_path() );
		return self::page_header_markup(
			self::institutional_header_copy( $key, (string) get_the_title( $post ), (string) $post->post_excerpt, $locale ),
			$locale
		);
	}

	/**
	 * Returns the page-header copy for one governed page key.
	 *
	 * Pages that appear in the showcase carry its exact kicker, heading, lead
	 * and meta line; other governed pages fall back to the record title and
	 * summary so they still open with the band.
	 *
	 * @param string $key     Institutional page key.
	 * @param string $title   Record title fallback.
	 * @param string $summary Record summary fallback.
	 * @param string $locale  Supported locale slug.
	 * @return array<string, string>
	 */
	private static function institutional_header_copy( string $key, string $title, string $summary, string $locale ): array {
		$english = 'en' === $locale;
		$copy    = array(
			'about'           => array(
				'kicker' => $english ? 'About' : 'Sobre',
				'title'  => $english ? 'About LPS' : 'Sobre o LPS',
				'lead'   => $english
					? 'Founded in 1996 at the Federal University of Rio de Janeiro, the Signal Processing Laboratory works in teaching, research and extension, from junior research initiation to post-doctorate.'
					: 'Fundado em 1996 na Universidade Federal do Rio de Janeiro, o Laboratório de Processamento de Sinais atua em ensino, pesquisa e extensão, da iniciação científica júnior ao pós-doutorado.',
				'meta'   => $english
					? 'Founded 1996 · UFRJ · COPPE · Electrical Engineering Program'
					: 'Fundação 1996 · UFRJ · COPPE · Programa de Engenharia Elétrica',
			),
			'contact'         => array(
				'kicker' => $english ? 'Contact' : 'Contato',
				'title'  => $english ? 'Contact the laboratory' : 'Fale com o laboratório',
				'lead'   => $english
					? 'The laboratory office is the first stop for administrative matters, projects, technical visits and press requests.'
					: 'A secretaria do laboratório é o primeiro caminho para assuntos administrativos, projetos, visitas técnicas e pedidos de imprensa.',
			),
			'privacy'         => array(
				'kicker' => $english ? 'Privacy' : 'Privacidade',
				'title'  => $english ? 'Privacy on this site' : 'Privacidade neste site',
				'lead'   => $english
					? 'This site is built to collect as little as possible: no cookies for anonymous visitors, no third-party requests and no public forms.'
					: 'Este site é construído para coletar o mínimo possível: nenhum cookie para visitantes anônimos, nenhuma requisição a terceiros e nenhum formulário público.',
			),
			'accessibility'   => array(
				'kicker' => $english ? 'Accessibility' : 'Acessibilidade',
				'title'  => $english ? 'Accessibility statement' : 'Declaração de acessibilidade',
				'lead'   => $english
					? 'The LPS website is published with the goal of meeting WCAG 2.2 level AA and eMAG: minimum 4.5:1 contrast for text, visible focus, full keyboard navigation, respect for reduced-motion preference and reflow at 320 CSS px with 200% zoom. Verification runs on every release.'
					: 'O site do LPS é publicado com o objetivo de atender à WCAG 2.2 nível AA e ao eMAG: contraste mínimo de 4,5:1 para texto, foco visível, navegação completa por teclado, respeito à preferência de movimento reduzido e reflow em 320 CSS px com 200% de zoom. A verificação é feita a cada publicação.',
			),
			'visual-identity' => array(
				'kicker' => $english ? 'Identity' : 'Identidade',
				'title'  => $english ? 'Visual identity' : 'Identidade visual',
				'lead'   => $english
					? 'The laboratory mark pairs a signal with the LPS lettering, backed by the full name and the Computational Intelligence descriptor.'
					: 'A marca do laboratório une um sinal à sigla LPS, acompanhados do nome por extenso e do descritor Inteligência Computacional.',
				'meta'   => $english
					? 'Source artwork supplied by the laboratory; vectorised with the lettering outlined.'
					: 'Arte-fonte fornecida pelo laboratório; vetorizada com as letras em curvas.',
			),
			'infrastructure'  => array(
				'kicker' => $english ? 'Infrastructure' : 'Infraestrutura',
				'title'  => $english ? 'Facilities and capabilities' : 'Instalações e capacidades',
				'lead'   => $english
					? 'Headquartered in Building H, room 220 of the UFRJ Technology Centre, the laboratory runs its own computing infrastructure: the Caloba SLURM cluster with CPU and GPU partitions, Singularity containers and the Maestro workload-orchestration stack.'
					: 'Com sede no Bloco H, sala 220 do Centro de Tecnologia da UFRJ, o laboratório mantém infraestrutura computacional própria: o cluster SLURM Caloba com partições CPU e GPU, contêineres Singularity e a pilha de orquestração Maestro.',
				'meta'   => $english
					? 'Source: LPS datacenter documentation (lps-ufrj-br.github.io/datacenter).'
					: 'Fonte: documentação do datacenter do LPS (lps-ufrj-br.github.io/datacenter).',
			),
			'collaboration'   => array(
				'kicker' => $english ? 'Take part' : 'Participe',
			),
		);
		$header  = $copy[ $key ] ?? array();
		if ( ! isset( $header['title'] ) ) {
			$header['title'] = '' !== trim( $title ) ? trim( $title ) : self::archive_title_text( $locale, '', '' );
		}
		if ( ! isset( $header['lead'] ) && '' !== trim( $summary ) ) {
			$header['lead'] = trim( $summary );
		}
		return $header;
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
			$query  = isset( $_GET['q'] ) && is_string( $_GET['q'] ) ? trim( sanitize_text_field( wp_unslash( $_GET['q'] ) ) ) : '';
			$suffix = '' === $query ? '' : '?q=' . rawurlencode( $query );
			return array(
				$locale                              => $path . $suffix,
				'pt-br' === $locale ? 'en' : 'pt-br' => $counterpart . $suffix,
			);
		}
		if ( function_exists( 'is_singular' ) && is_singular() && function_exists( 'pll_get_post_translations' ) && function_exists( 'get_queried_object_id' ) ) {
			$result = array();
			foreach ( pll_get_post_translations( get_queried_object_id() ) as $slug => $post_id ) {
				if ( 'pt-br' !== $slug && 'en' !== $slug ) {
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
			return $result;
		}
		$locale      = self::current_locale( $path );
		$counterpart = SeoRoutes::counterpart_path( $path, $locale );
		if ( '' !== $counterpart ) {
			return array(
				$locale                              => $path,
				'pt-br' === $locale ? 'en' : 'pt-br' => $counterpart,
			);
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
