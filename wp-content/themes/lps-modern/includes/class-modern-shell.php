<?php
/**
 * Server-rendered modern institutional shell.
 *
 * @package LPS\Modern
 */

declare(strict_types=1);

namespace LPS\Modern;

/** Owns the bilingual modern shell: topbar, sticky header, footer, and helpers. */
final class ModernShell {
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
	 * @param string                     $locale   Supported locale slug.
	 * @param string                     $path     Current public path.
	 * @param array<string, string>|null $variants Published locale URLs.
	 */
	public static function header_markup( string $locale, string $path, ?array $variants = null ): string {
		$english         = 'en' === $locale;
		$skip            = $english ? 'Skip to content' : 'Pular para o conteúdo';
		$menu            = $english ? 'Menu' : 'Menu';
		$nav_label       = $english ? 'Primary navigation' : 'Navegação principal';
		$search_label    = $english ? 'Search the LPS website' : 'Buscar no site do LPS';
		$search_button   = $english ? 'Search' : 'Buscar';
		$collaborate     = $english ? 'Collaborate' : 'Colabore';
		$collaborate_url = $english ? '/en/collaborate/' : '/pt-br/colabore/';
		$home            = $english ? '/en/' : '/pt-br/';
		$home_name       = $english ? 'LPS - home' : 'LPS - início';
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
		$brand          = self::brand_markup( $home, $home_name, $english );
		return '<a class="lpsx-skip" href="#lpsx-main">' . self::escape( $skip ) . '</a>'
			. '<div class="lpsx-topbar"><div class="lpsx-wrap lpsx-topbar-inner"><p lang="pt-BR">UFRJ <span aria-hidden="true">·</span> COPPE <span aria-hidden="true">·</span> Programa de Engenharia Elétrica</p>'
			. $locale_control . '</div></div>'
			. '<header class="lpsx-header"><div class="lpsx-wrap lpsx-header-inner">'
			. $brand
			. '<details class="lpsx-menu"><summary><span class="lpsx-menu-icon" aria-hidden="true"></span>' . self::escape( $menu ) . '</summary>'
			. '<div class="lpsx-menu-panel"><nav class="lpsx-nav" aria-label="' . self::escape( $nav_label ) . '"><ul>' . $items . '</ul></nav>'
			. '<div class="lpsx-tools"><search aria-label="' . self::escape( $search_label ) . '"><form class="lpsx-search" action="' . $home . '" method="get"><label class="lpsx-visually-hidden" for="lpsx-search-input">' . self::escape( $search_label ) . '</label><input id="lpsx-search-input" name="s" type="search" autocomplete="off" placeholder="' . self::escape( $search_label ) . '"><button type="submit">' . self::escape( $search_button ) . '</button></form></search>'
			. '<a class="lpsx-btn lpsx-btn-primary" href="' . $collaborate_url . '">' . self::escape( $collaborate ) . '</a></div></div></details>'
			. '</div></header>';
	}

	/**
	 * Builds the brand home-link: compact horizontal lockup with a text fallback.
	 *
	 * @param string $home      Localized home URL.
	 * @param string $home_name Localized accessible name.
	 * @param bool   $english   Whether the locale is English.
	 */
	private static function brand_markup( string $home, string $home_name, bool $english ): string {
		$img = self::logo_img( 'lps-logo-compact.svg', 'lpsx-brand-mark', 240, 80 );
		$tag = $english ? 'Signal Processing Laboratory' : 'Laboratório de Processamento de Sinais';
		if ( '' === $img ) {
			return '<a class="lpsx-brand lpsx-brand-text" href="' . $home . '" aria-label="' . self::escape( $home_name ) . '"><strong>LPS</strong><small>' . self::escape( $tag ) . '</small></a>';
		}
		return '<a class="lpsx-brand" href="' . $home . '" aria-label="' . self::escape( $home_name ) . '">' . $img . '<span class="lpsx-brand-text"><strong>LPS</strong><small>' . self::escape( $tag ) . '</small></span></a>';
	}

	/**
	 * Builds a decorative logo image tag when the asset ships with the theme.
	 *
	 * @param string $file   Logo file name under assets/img/logo/.
	 * @param string $class  Image class attribute.
	 * @param int    $width  Intrinsic display width.
	 * @param int    $height Intrinsic display height.
	 */
	private static function logo_img( string $file, string $class, int $width, int $height ): string {
		$path = dirname( __DIR__ ) . '/assets/img/logo/' . $file;
		if ( ! is_readable( $path ) ) {
			return '';
		}
		$src = function_exists( 'get_theme_file_uri' )
			? (string) get_theme_file_uri( 'assets/img/logo/' . $file )
			: 'assets/img/logo/' . $file;
		return '<img class="' . $class . '" src="' . self::escape( $src ) . '" alt="" width="' . $width . '" height="' . $height . '" fetchpriority="high">';
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
				$items .= '<li><span aria-disabled="true">' . $definition['short'] . ' <span class="lpsx-visually-hidden">(' . self::escape( $missing ) . ')</span></span></li>';
			}
		}
		return '<nav class="lpsx-locale" aria-label="' . self::escape( $label ) . '"><ul>' . $items . '</ul></nav>';
	}

	/**
	 * Renders a same-origin URL in root-relative form.
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
	 * @param string $locale Supported locale slug.
	 */
	public static function footer_markup( string $locale ): string {
		$english = 'en' === $locale;
		$home    = $english ? '/en/' : '/pt-br/';
		$columns = $english
			? array(
				'Laboratory' => array(
					'About'       => '/en/about/',
					'People'      => '/en/people/',
					'News'        => '/en/news/',
					'Events'      => '/en/events/',
					'Contact'     => '/en/contact/',
				),
				'Research'   => array(
					'Research areas' => '/en/research/',
					'Projects'       => '/en/projects/',
					'Publications'   => '/en/publications/',
					'Infrastructure' => '/en/infrastructure/',
				),
				'Join us'    => array(
					'Opportunities' => '/en/opportunities/',
					'Collaborate'   => '/en/collaborate/',
					'Privacy'       => '/en/privacy/',
					'Accessibility' => '/en/accessibility/',
				),
			)
			: array(
				'Laboratório' => array(
					'Sobre'    => '/pt-br/sobre/',
					'Pessoas'  => '/pt-br/pessoas/',
					'Notícias' => '/pt-br/noticias/',
					'Eventos'  => '/pt-br/eventos/',
					'Contato'  => '/pt-br/contato/',
				),
				'Pesquisa'    => array(
					'Áreas de pesquisa' => '/pt-br/pesquisa/',
					'Projetos'          => '/pt-br/projetos/',
					'Publicações'       => '/pt-br/publicacoes/',
					'Infraestrutura'    => '/pt-br/infraestrutura/',
				),
				'Participe'   => array(
					'Oportunidades'  => '/pt-br/oportunidades/',
					'Colabore'       => '/pt-br/colabore/',
					'Privacidade'    => '/pt-br/privacidade/',
					'Acessibilidade' => '/pt-br/acessibilidade/',
				),
			);
		$navs    = '';
		foreach ( $columns as $heading => $links ) {
			$items = '';
			foreach ( $links as $label => $url ) {
				$items .= '<li><a href="' . $url . '">' . self::escape( $label ) . '</a></li>';
			}
			$navs .= '<nav aria-label="' . self::escape( $heading ) . '"><h2>' . self::escape( $heading ) . '</h2><ul>' . $items . '</ul></nav>';
		}
		$address   = $english
			? 'Av. Athos da Silveira Ramos, 149, Centro de Tecnologia, Bloco H, Room 220, Ilha do Fundão, Rio de Janeiro - RJ, 21941-914, Brazil.'
			: 'Av. Athos da Silveira Ramos, 149, Centro de Tecnologia, Bloco H, Sala 220, Ilha do Fundão, Rio de Janeiro - RJ, CEP 21941-914.';
		$statement = $english ? 'Part of COPPE at the Federal University of Rio de Janeiro.' : 'Parte da COPPE na Universidade Federal do Rio de Janeiro.';
		$home_name = $english ? 'LPS - home' : 'LPS - início';
		$logo      = self::logo_img( 'lps-logo-compact-reversed.svg', 'lpsx-footer-mark', 220, 74 );
		$brand     = '' === $logo
			? '<a class="lpsx-footer-brand" href="' . $home . '" aria-label="' . self::escape( $home_name ) . '"><strong>LPS</strong></a>'
			: '<a class="lpsx-footer-brand" href="' . $home . '" aria-label="' . self::escape( $home_name ) . '">' . $logo . '</a>';
		$legal     = $english ? 'Signal Processing Laboratory (LPS) / UFRJ / COPPE.' : 'Laboratório de Processamento de Sinais (LPS) / UFRJ / COPPE.';
		return '<footer class="lpsx-footer"><div class="lpsx-wrap lpsx-footer-grid"><div class="lpsx-footer-identity">'
			. $brand . '<p>' . self::escape( $statement ) . '</p><p class="lpsx-footer-address">' . self::escape( $address ) . '</p></div>'
			. $navs . '</div><div class="lpsx-footer-bar"><div class="lpsx-wrap"><p>' . self::escape( $legal ) . '</p></div></div></footer>';
	}

	/**
	 * Makes an authored table region reachable and nameable by keyboard.
	 *
	 * @param string               $content Rendered block markup.
	 * @param array<string, mixed> $block   Parsed block.
	 */
	public static function make_tables_scrollable_by_keyboard( string $content, array $block ): string {
		$name = isset( $block['blockName'] ) && is_string( $block['blockName'] ) ? $block['blockName'] : '';
		if ( 'core/table' !== $name || ! str_contains( $content, 'wp-block-table' ) || str_contains( $content, 'lpsx-table-scroll' ) ) {
			return $content;
		}
		$english = 'en' === self::current_locale( self::request_path() );
		$caption = '';
		if ( 1 === preg_match( '#<figcaption[^>]*>(.*?)</figcaption>#su', $content, $found ) ) {
			$caption = function_exists( 'wp_strip_all_tags' ) ? trim( wp_strip_all_tags( $found[1] ) ) : trim( strip_tags( $found[1] ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags -- fallback when WP is not loaded.
		}
		$hint  = $english ? 'table, scrollable horizontally' : 'tabela, rolagem horizontal';
		$label = '' === $caption ? ucfirst( $hint ) : $caption . ' (' . $hint . ')';
		$fixed = preg_replace(
			'#(<figure class="wp-block-table".*?</figure>)#su',
			'<div class="lpsx-table-scroll" tabindex="0" role="region" aria-label="' . self::escape( $label ) . '">$1</div>',
			$content,
			1
		);
		return is_string( $fixed ) ? $fixed : $content;
	}

	/**
	 * Localized labels for governed record types (standalone map; no cross-theme dependency).
	 *
	 * @param string $post_type Record post type.
	 * @param string $locale    Supported locale slug.
	 */
	public static function type_label( string $post_type, string $locale ): string {
		$english = 'en' === $locale;
		return match ( $post_type ) {
			'lps_research_area' => $english ? 'Research areas' : 'Áreas de pesquisa',
			'lps_project' => $english ? 'Projects' : 'Projetos',
			'lps_person' => $english ? 'People' : 'Pessoas',
			'lps_publication' => $english ? 'Publications' : 'Publicações',
			'lps_news' => $english ? 'News' : 'Notícias',
			'lps_event' => $english ? 'Events' : 'Eventos',
			'lps_opportunity' => $english ? 'Opportunities' : 'Oportunidades',
			'lps_organization' => $english ? 'Organizations' : 'Organizações',
			default => '',
		};
	}

	/**
	 * Returns the localized archive title of one record type.
	 *
	 * @param string $title     Core archive title.
	 * @param string $post_type Queried record type.
	 * @param string $locale    Supported locale slug.
	 */
	public static function archive_title_for( string $title, string $post_type, string $locale ): string {
		if ( '' === $post_type ) {
			return $title;
		}
		$label = self::type_label( $post_type, $locale );
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
	 * @param string $term   Submitted search term.
	 * @param int    $total  Matched record count.
	 * @param string $locale Supported locale slug.
	 */
	public static function search_results_markup( string $term, int $total, string $locale ): string {
		$english = 'en' === $locale;
		$heading = ( $english ? 'Search results for: ' : 'Resultados da busca para: ' ) . $term;
		if ( function_exists( '_n' ) ) {
			$count = $english
				? sprintf( _n( '%d result', '%d results', $total, 'lps-modern' ), $total ) // phpcs:ignore WordPress.WP.I18n.MissingTranslatorsComment -- count-only plural with no placeholder ambiguity.
				: sprintf( _n( '%d resultado', '%d resultados', $total, 'lps-modern' ), $total ); // phpcs:ignore WordPress.WP.I18n.MissingTranslatorsComment -- count-only plural with no placeholder ambiguity.
		} else {
			$count = $english ? "{$total} results" : "{$total} resultados";
		}
		return '<h1 class="wp-block-query-title">' . self::escape( $heading ) . '</h1>'
			. '<p class="lpsx-result-count" role="status">' . self::escape( $count ) . '</p>';
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

	/** Renders core-post metadata through the modern shell's locale path. */
	public static function render_post_metadata(): string {
		if ( ! function_exists( 'get_the_date' ) ) {
			return '';
		}
		$raw_date = get_the_date( 'Y-m-d' );
		$raw_time = get_the_date( 'c' );
		if ( ! is_string( $raw_date ) || ! is_string( $raw_time ) ) {
			return '';
		}
		$locale = self::current_locale( self::request_path() );
		$labels = array();
		$terms  = function_exists( 'get_the_category' ) ? get_the_category() : array();
		foreach ( $terms as $category ) {
			if ( isset( $category->name ) && is_string( $category->name ) ) {
				$labels[] = $category->name;
			}
		}
		return self::post_metadata_markup( $raw_date, $raw_time, $labels, $locale );
	}

	/**
	 * Builds the localized core-post metadata markup.
	 *
	 * @param string             $raw_date   Stored publication date.
	 * @param string             $raw_time   Stored publication timestamp.
	 * @param array<int, string> $categories Public category labels.
	 * @param string             $locale     Supported locale slug.
	 */
	public static function post_metadata_markup( string $raw_date, string $raw_time, array $categories, string $locale ): string {
		$published = 'en' === $locale ? 'Published' : 'Publicado em';
		$html      = '<div class="wp-block-post-date"><span>' . self::escape( $published ) . ' </span><time datetime="' . self::escape( $raw_time ) . '">' . self::escape( $raw_date ) . '</time></div>';
		$labels    = array();
		foreach ( $categories as $category ) {
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
		$locale  = self::current_locale( self::request_path() );
		$english = 'en' === $locale;
		$home    = $english ? '/en/' : '/pt-br/';
		$label   = $english ? 'Breadcrumb' : 'Trilha de navegação';
		$here    = $english ? 'Home' : 'Início';
		$title   = '';
		if ( function_exists( 'is_search' ) && is_search() ) {
			$title = $english ? 'Search' : 'Busca';
		} elseif ( function_exists( 'get_the_title' ) ) {
			$title = (string) get_the_title();
		}
		$items = '<li><a href="' . $home . '">' . self::escape( $here ) . '</a></li>';
		if ( '' !== trim( $title ) ) {
			$items .= '<li><span aria-current="page">' . self::escape( trim( wp_strip_all_tags( $title ) ) ) . '</span></li>';
		}
		return '<nav class="lpsx-breadcrumbs" aria-label="' . self::escape( $label ) . '"><ol>' . $items . '</ol></nav>';
	}

	/** Renders the localized empty-query state. */
	public static function render_empty_state(): string {
		$locale  = self::current_locale( self::request_path() );
		$english = 'en' === $locale;
		$heading = $english ? 'Nothing published here yet' : 'Nada publicado aqui ainda';
		$body    = $english ? 'Reviewed information has not been published for this listing.' : 'Informações revisadas ainda não foram publicadas nesta listagem.';
		return '<div class="lpsx-empty"><h2>' . self::escape( $heading ) . '</h2><p>' . self::escape( $body ) . '</p></div>';
	}

	/**
	 * Builds the localized index heading.
	 *
	 * @param string $locale Supported locale slug.
	 * @param string $title  Core title text.
	 */
	public static function index_title_markup( string $locale, string $title ): string {
		$fallback = 'en' === $locale ? 'Latest' : 'Recentes';
		$text     = '' === trim( $title ) ? $fallback : $title;
		return '<h1 class="wp-block-query-title">' . self::escape( $text ) . '</h1>';
	}

	/** Renders the localized index heading for the current request. */
	public static function render_index_title(): string {
		$title = function_exists( 'single_post_title' ) ? (string) single_post_title( '', false ) : '';
		return self::index_title_markup( self::current_locale( self::request_path() ), $title );
	}

	/** Renders the localized archive heading for the current request. */
	public static function render_archive_title(): string {
		$locale  = self::current_locale( self::request_path() );
		$title   = function_exists( 'get_the_archive_title' ) ? (string) get_the_archive_title() : '';
		$english = 'en' === $locale;
		$text    = '' === trim( $title ) ? ( $english ? 'Archive' : 'Arquivo' ) : $title;
		return '<h1 class="wp-block-query-title">' . self::escape( trim( wp_strip_all_tags( $text ) ) ) . '</h1>';
	}

	/** Renders the localized 404 state. */
	public static function render_not_found(): string {
		$locale  = self::current_locale( self::request_path() );
		$english = 'en' === $locale;
		$home    = $english ? '/en/' : '/pt-br/';
		$heading = $english ? 'Page not found' : 'Página não encontrada';
		$body    = $english ? 'The address may be wrong or the page may have moved.' : 'O endereço pode estar errado ou a página pode ter mudado.';
		$cta     = $english ? 'Back to the homepage' : 'Voltar para a página inicial';
		return '<div class="lpsx-empty"><h1>' . self::escape( $heading ) . '</h1><p>' . self::escape( $body ) . '</p><p><a class="lpsx-btn lpsx-btn-primary" href="' . $home . '">' . self::escape( $cta ) . '</a></p></div>';
	}

	/**
	 * Resolves the request locale, defaulting to Portuguese.
	 *
	 * @param string $path Public request path.
	 */
	private static function current_locale( string $path ): string {
		if ( function_exists( 'get_locale' ) ) {
			$wp_locale = (string) get_locale();
			if ( str_starts_with( $wp_locale, 'en' ) ) {
				return 'en';
			}
		}
		return self::locale_from_path( $path );
	}

	/**
	 * Resolves published locale variants for the current request.
	 *
	 * @return array<string, string>
	 */
	private static function current_variants(): array {
		$path   = self::request_path();
		$locale = self::locale_from_path( $path );
		if ( class_exists( '\\LPS\\ContentModel\\Translations' ) && function_exists( 'get_queried_object_id' ) ) {
			$id       = get_queried_object_id();
			$variants = \LPS\ContentModel\Translations::variants( $id );
			$urls     = array();
			foreach ( array( 'pt-br', 'en' ) as $slug ) {
				$target = $variants[ $slug ] ?? 0;
				if ( is_numeric( $target ) && (int) $target > 0 && function_exists( 'get_permalink' ) ) {
					$link = get_permalink( (int) $target );
					if ( is_string( $link ) && '' !== $link ) {
						$urls[ $slug ] = $link;
					}
				}
			}
			if ( array() !== $urls ) {
				return $urls;
			}
		}
		return 'en' === $locale
			? array(
				'pt-br' => '/pt-br/',
				'en'    => $path,
			)
			: array(
				'pt-br' => $path,
				'en'    => '/en/',
			);
	}

	/** Returns the current public request path. */
	private static function request_path(): string {
		$uri  = $_SERVER['REQUEST_URI'] ?? '/'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only request routing.
		$path = is_string( $uri ) ? parse_url( $uri, PHP_URL_PATH ) : null;
		return is_string( $path ) && '' !== $path ? $path : '/';
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
