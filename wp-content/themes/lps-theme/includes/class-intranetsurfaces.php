<?php
/**
 * Renderers for the members-only intranet.
 *
 * The intranet reuses the public site chrome — utility band, masthead,
 * footer — inside the standalone document shape the task dashboard uses, so
 * a signed-in member moves between the intranet and the task panel without
 * leaving the site's navigation model. Access badges name the contract up
 * front: `members` areas carry an "All members" tick, `project` areas carry
 * a lock and the project they belong to, and cards a user cannot open render
 * without a link — the existence of a restricted area is public knowledge,
 * its contents are not.
 *
 * Every renderer returns escaped markup only and depends on no runtime
 * WordPress state beyond the records passed in, so the surfaces exercise in
 * unit tests without a database.
 *
 * @package LPS\Theme
 */

declare(strict_types=1);

namespace LPS\Theme;

use WP_Post;
use WP_User;

if ( ! class_exists( SeoSurfaces::class ) ) {
	require_once __DIR__ . '/class-seosurfaces.php';
}
if ( ! class_exists( IntranetRoutes::class ) ) {
	require_once __DIR__ . '/class-intranetroutes.php';
}

/** Renders the intranet document and its views. */
final class IntranetSurfaces {
	/**
	 * Renders the complete HTML document for one intranet route.
	 *
	 * @param array{view: string, locale: string, slug: string, post?: WP_Post|null} $route Resolved route.
	 * @param WP_User                                                                $user  Signed-in account.
	 */
	public static function document( array $route, WP_User $user ): string {
		$locale  = $route['locale'];
		$english = 'en' === $locale;
		$path    = '' !== $route['slug']
			? IntranetRoutes::section_path( $route['slug'], $locale )
			: IntranetRoutes::intranet_path( $locale );
		$title   = self::view_title( $route, $locale );
		$body    = self::view( $route, $user, $locale );
		$header  = Shell::header_markup(
			$locale,
			$path,
			array(
				'pt-br' => '' !== $route['slug'] ? IntranetRoutes::section_path( $route['slug'], 'pt-br' ) : IntranetRoutes::intranet_path( 'pt-br' ),
				'en'    => '' !== $route['slug'] ? IntranetRoutes::section_path( $route['slug'], 'en' ) : IntranetRoutes::intranet_path( 'en' ),
			)
		);
		$footer  = Shell::footer_markup( $locale );
		$css     = function_exists( 'get_theme_file_uri' ) ? get_theme_file_uri( 'assets/css/theme.css' ) : '';
		$version = function_exists( 'wp_get_theme' ) ? (string) wp_get_theme()->get( 'Version' ) : '';
		$file    = function_exists( 'get_theme_file_path' ) ? get_theme_file_path( 'assets/css/theme.css' ) : '';
		if ( '' !== $file && is_file( $file ) ) {
			$version .= '.' . (string) filemtime( $file );
		}
		$css_url = '' !== $css ? $css . ( '' !== $version ? '?ver=' . rawurlencode( $version ) : '' ) : '';
		return '<!DOCTYPE html><html lang="' . self::esc( IntranetRoutes::bcp47( $locale ) ) . '"><head>'
			. '<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
			. '<meta name="robots" content="noindex, nofollow">'
			. '<title>' . self::esc( $title . ' — LPS' ) . '</title>'
			. SeoSurfaces::icon_links()
			// phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedStylesheet -- Standalone document outside wp_head; the theme stylesheet is emitted directly.
			. ( '' !== $css_url ? '<link rel="stylesheet" href="' . self::esc( $css_url ) . '">' : '' )
			. '</head><body>'
			. $header
			. '<main id="lps-main" class="lps-main-content">'
			. '<header class="lps-page-header"><div class="lps-page-header-inner lps-page-grid">'
			. '<p class="lps-meta"><span class="lps-pill">' . self::esc( $english ? 'Intranet' : 'Intranet' ) . '</span></p>'
			. '<h1 class="lps-page-title">' . self::esc( $title ) . '</h1>'
			. '</div></header>'
			. $body
			. '</main>'
			. $footer
			. '</body></html>';
	}

	/**
	 * Returns the document title of a route.
	 *
	 * @param array{view: string, locale: string, slug: string, post?: WP_Post|null} $route Resolved route.
	 * @param string                                                                 $locale Supported locale slug.
	 */
	private static function view_title( array $route, string $locale ): string {
		$english = 'en' === $locale;
		if ( 'section' === $route['view'] && isset( $route['post'] ) && '' !== $route['post']->post_title ) {
			return $route['post']->post_title;
		}
		return match ( $route['view'] ) {
			'locked'  => $english ? 'Restricted area' : 'Área restrita',
			'missing' => $english ? 'Section not found' : 'Seção não encontrada',
			default   => 'Intranet',
		};
	}

	/**
	 * Renders one intranet view body.
	 *
	 * @param array{view: string, locale: string, slug: string, post?: WP_Post|null} $route Resolved route.
	 * @param WP_User                                                                $user   Signed-in account.
	 * @param string                                                                 $locale Supported locale slug.
	 */
	public static function view( array $route, WP_User $user, string $locale ): string {
		return match ( $route['view'] ) {
			'section' => self::section_view( $route['post'] ?? null, $locale ),
			'locked'  => self::locked_view( $route['post'] ?? null, $locale ),
			'missing' => self::missing_view( $locale ),
			default   => self::home_view( $user, $locale ),
		};
	}

	/**
	 * Renders the intranet hub: one card per section with its access badge.
	 *
	 * @param WP_User $user   Signed-in account.
	 * @param string  $locale Supported locale slug.
	 */
	private static function home_view( WP_User $user, string $locale ): string {
		$english  = 'en' === $locale;
		$sections = self::sections();
		$cards    = '';
		foreach ( $sections as $post ) {
			$access   = IntranetRoutes::section_access( $post );
			$can_open = IntranetRoutes::user_can_open( $user, $post );
			$badge    = self::access_badge( $access['level'], $can_open, $english );
			$card     = '<li class="lps-card' . ( $can_open ? '' : ' lps-card--locked' ) . '">'
				. '<span class="lps-meta">' . self::esc( $badge ) . '</span>'
				. '<h2 class="lps-card-title">'
				. ( $can_open
					? '<a href="' . self::esc( IntranetRoutes::section_path( $post->post_name, $locale ) ) . '">' . self::esc( $post->post_title ) . '</a>'
					: self::esc( $post->post_title ) )
				. '</h2>'
				. ( '' !== trim( $post->post_excerpt ) ? '<p class="lps-card-body">' . self::esc( $post->post_excerpt ) . '</p>' : '' )
				. '</li>';
			$cards   .= $card;
		}
		$html  = '<div class="lps-page-grid"><section class="lps-section">'
			. '<p class="lps-lead">' . self::esc(
				$english
				? 'Internal resources for laboratory members. Restricted areas open only to the members named on each project.'
				: 'Recursos internos para membros do laboratório. Áreas restritas abrem apenas para os membros designados em cada projeto.'
			) . '</p>';
		$html .= '' !== $cards
			? '<ul class="lps-card-grid">' . $cards . '</ul>'
			: '<div class="lps-alert lps-alert-info"><p>' . self::esc(
				$english
				? 'No intranet section is published yet.'
				: 'Nenhuma seção da intranet foi publicada ainda.'
			) . '</p></div>';
		return $html . '</section></div>';
	}

	/**
	 * Renders an open section: the private page's body inside the site chrome.
	 *
	 * @param WP_Post|null $post   Private intranet page.
	 * @param string       $locale Supported locale slug.
	 */
	private static function section_view( ?WP_Post $post, string $locale ): string {
		if ( null === $post ) {
			return self::missing_view( $locale );
		}
		$body = function_exists( 'apply_filters' ) ? apply_filters( 'the_content', $post->post_content ) : self::esc( $post->post_content );
		if ( ! is_string( $body ) ) {
			$body = '';
		}
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- the_content returns filtered page markup, not raw text.
		return '<div class="lps-page-grid">' . self::section_shortcuts( $post, $locale ) . '<div class="lps-body lps-intranet-body">' . $body . '</div></div>';
	}

	/**
	 * Renders the hub-card badge naming a section's access contract.
	 *
	 * @param string $level    Resolved access level.
	 * @param bool   $can_open Whether the viewer may open the section.
	 * @param bool   $english  Whether to render English copy.
	 */
	private static function access_badge( string $level, bool $can_open, bool $english ): string {
		if ( ! $can_open ) {
			return $english ? 'Restricted' : 'Restrito';
		}
		return match ( $level ) {
			'faculty' => $english ? 'Faculty & administrators' : 'Corpo docente e administradores',
			'project' => $english ? 'Project members' : 'Membros do projeto',
			default   => $english ? 'All members' : 'Todos os membros',
		};
	}

	/**
	 * Renders the shortcut bar of a section: grouped dropdown menus of useful
	 * links, like the queue and storage shortcuts on the cluster area.
	 *
	 * The groups live in the section's `_lps_intranet_links` meta as a JSON
	 * array — `[ { "label": "Queues", "items": [ { "label": "GPU", "url":
	 * "https://…" } ] } ]` — so the laboratory edits the links from wp-admin
	 * without a code change. A label may be a string or a `{"pt-br": …,
	 * "en": …}` pair; items with no items array render as a plain shortcut
	 * button when the group itself carries a url. Only http/https links
	 * render; anything else is dropped.
	 *
	 * @param WP_Post $post   Private intranet page.
	 * @param string  $locale Supported locale slug.
	 */
	private static function section_shortcuts( WP_Post $post, string $locale ): string {
		$raw = get_post_meta( $post->ID, '_lps_intranet_links', true );
		if ( ! is_string( $raw ) || '' === trim( $raw ) ) {
			return '';
		}
		$groups = json_decode( $raw, true );
		if ( ! is_array( $groups ) ) {
			return '';
		}
		$bar = '';
		foreach ( $groups as $group ) {
			if ( ! is_array( $group ) ) {
				continue;
			}
			$label = self::shortcut_label( $group['label'] ?? '', $locale );
			if ( '' === $label ) {
				continue;
			}
			$items = isset( $group['items'] ) && is_array( $group['items'] ) ? $group['items'] : array();
			$links = '';
			foreach ( $items as $item ) {
				if ( ! is_array( $item ) ) {
					continue;
				}
				$item_label = self::shortcut_label( $item['label'] ?? '', $locale );
				$item_url   = self::shortcut_url( $item['url'] ?? '' );
				if ( '' === $item_label || '' === $item_url ) {
					continue;
				}
				$links .= '<li><a href="' . self::esc( $item_url ) . '">' . self::esc( $item_label ) . '</a></li>';
			}
			if ( '' !== $links ) {
				$bar .= '<details class="lps-shortcut-group" name="lps-shortcut"><summary>' . self::esc( $label ) . '<span class="lps-nav-caret" aria-hidden="true"></span></summary>'
					. '<ul class="lps-shortcut-menu">' . $links . '</ul></details>';
				continue;
			}
			$group_url = self::shortcut_url( $group['url'] ?? '' );
			if ( '' === $group_url ) {
				continue;
			}
			$bar .= '<a class="lps-shortcut-link" href="' . self::esc( $group_url ) . '">' . self::esc( $label ) . '</a>';
		}
		if ( '' === $bar ) {
			return '';
		}
		$english = 'en' === $locale;
		return '<nav class="lps-shortcut-bar" aria-label="' . self::esc( $english ? 'Shortcuts' : 'Atalhos' ) . '">' . $bar . '</nav>';
	}

	/**
	 * Resolves one shortcut label to the request locale.
	 *
	 * @param mixed  $label  Label string or `{"pt-br": …, "en": …}` map.
	 * @param string $locale Supported locale slug.
	 */
	private static function shortcut_label( mixed $label, string $locale ): string {
		if ( is_string( $label ) ) {
			return trim( $label );
		}
		if ( is_array( $label ) ) {
			$picked = $label[ $locale ] ?? $label['pt-br'] ?? reset( $label );
			return is_string( $picked ) ? trim( $picked ) : '';
		}
		return '';
	}

	/**
	 * Returns an http/https URL or empty string for anything else.
	 *
	 * @param mixed $url Candidate URL.
	 */
	private static function shortcut_url( mixed $url ): string {
		if ( ! is_string( $url ) || '' === trim( $url ) ) {
			return '';
		}
		$url    = trim( $url );
		$scheme = wp_parse_url( $url, PHP_URL_SCHEME );
		if ( ! in_array( $scheme, array( 'http', 'https' ), true ) ) {
			return '';
		}
		return $url;
	}

	/**
	 * Renders the restricted-area surface for a section the user cannot open.
	 *
	 * @param WP_Post|null $post   Private intranet page (null when unknown).
	 * @param string       $locale Supported locale slug.
	 */
	private static function locked_view( ?WP_Post $post, string $locale ): string {
		$english = 'en' === $locale;
		$project = '';
		if ( null !== $post ) {
			$access = IntranetRoutes::section_access( $post );
			if ( $access['project'] > 0 && function_exists( 'get_the_title' ) ) {
				$project = (string) get_the_title( $access['project'] );
			}
		}
		$faculty = null !== $post && 'faculty' === IntranetRoutes::section_access( $post )['level'];
		$note    = $faculty
			? ( $english
				? 'This area is restricted to the faculty and administrators. An administrator can grant your account the faculty role.'
				: 'Esta área é restrita ao corpo docente e aos administradores. Um administrador pode atribuir o perfil de docente à sua conta.' )
			: ( '' !== $project
				? sprintf(
				/* translators: %s is the project the area belongs to. */
					$english ? 'This area is restricted to members of %s. An administrator can grant your account access to it.' : 'Esta área é restrita aos membros de %s. Um administrador pode conceder acesso à sua conta.',
					$project
				)
				: ( $english ? 'This area is restricted. An administrator can grant your account access to it.' : 'Esta área é restrita. Um administrador pode conceder acesso à sua conta.' ) );
		return '<div class="lps-page-grid"><div class="lps-alert lps-alert-warning" role="status"><p>' . self::esc( $note ) . '</p></div></div>';
	}

	/**
	 * Renders the not-found surface for an unknown section slug.
	 *
	 * @param string $locale Supported locale slug.
	 */
	private static function missing_view( string $locale ): string {
		$english = 'en' === $locale;
		return '<div class="lps-page-grid"><div class="lps-alert lps-alert-info" role="status"><p>' . self::esc(
			$english
			? 'There is no intranet section at this address.'
			: 'Não existe seção da intranet neste endereço.'
		) . '</p></div></div>';
	}

	/**
	 * Lists every intranet section (private pages carrying the flag).
	 *
	 * @return array<int, WP_Post>
	 */
	private static function sections(): array {
		if ( ! function_exists( 'get_posts' ) ) {
			return array();
		}
		$posts = get_posts(
			array(
				'post_type'      => 'page',
				'post_status'    => 'private',
				'meta_key'       => '_lps_intranet_access', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- a handful of private intranet pages, queried once per signed-in hit.
				'orderby'        => 'menu_order title',
				'order'          => 'ASC',
				'posts_per_page' => 50,
			)
		);
		return array_values( $posts );
	}

	/**
	 * Escapes text for HTML context, delegating to WordPress when loaded.
	 *
	 * @param string $value Raw text.
	 */
	private static function esc( string $value ): string {
		return function_exists( 'esc_html' ) ? esc_html( $value ) : htmlspecialchars( $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );
	}
}
