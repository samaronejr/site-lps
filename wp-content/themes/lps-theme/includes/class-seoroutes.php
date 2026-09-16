<?php
/**
 * Request-time canonical metadata, sitemaps, feeds, robots, and legacy redirects.
 *
 * @package LPS\Theme
 */

declare(strict_types=1);

namespace LPS\Theme;

use LPS\ContentModel\SeoPolicy;
use LPS\ContentModel\StructuredData;
use LPS\ContentModel\TrustSurfacePolicy;
use WP_Post;
use WP_Query;

require_once __DIR__ . '/class-seosurfaces.php';
require_once __DIR__ . '/class-discoveryroutes.php';
require_once __DIR__ . '/class-publicroutes.php';
require_once __DIR__ . '/class-searchroutes.php';
require_once __DIR__ . '/class-trustroutes.php';

/**
 * Publishes the machine-readable layer of the public surface.
 *
 * Every address describes itself once: one canonical link, one robots
 * directive, reciprocal alternates only for published pairs, and a single
 * JSON-LD graph. Non-indexable states never reach a sitemap or a feed, and a
 * legacy address always answers in one hop or a deliberate `410`.
 */
final class SeoRoutes {
	public const DOCUMENT_QUERY_VAR = 'lps_seo_document';

	/**
	 * Record types owned by the discovery routes.
	 *
	 * @var array<int, string>
	 */
	private const DISCOVERY_TYPES = array( 'lps_research_area', 'lps_project', 'lps_publication' );

	/**
	 * Record types owned by the people and infrastructure routes.
	 *
	 * @var array<int, string>
	 */
	private const PUBLIC_TYPES = array( 'lps_person', 'lps_organization', 'lps_infrastructure' );

	/**
	 * Record types owned by the trust routes.
	 *
	 * @var array<int, string>
	 */
	private const TRUST_TYPES = array( 'lps_opportunity', 'lps_event', 'lps_news' );

	/**
	 * Localized section label for each record type.
	 *
	 * @var array<string, array<string, string>>
	 */
	private const SECTIONS = array(
		'lps_research_area'  => array(
			'pt-br' => 'Pesquisa',
			'en'    => 'Research',
		),
		'lps_project'        => array(
			'pt-br' => 'Projetos',
			'en'    => 'Projects',
		),
		'lps_publication'    => array(
			'pt-br' => 'Publicações',
			'en'    => 'Publications',
		),
		'lps_person'         => array(
			'pt-br' => 'Pessoas',
			'en'    => 'People',
		),
		'lps_organization'   => array(
			'pt-br' => 'Organizações',
			'en'    => 'Organizations',
		),
		'lps_infrastructure' => array(
			'pt-br' => 'Infraestrutura',
			'en'    => 'Infrastructure',
		),
		'lps_opportunity'    => array(
			'pt-br' => 'Oportunidades',
			'en'    => 'Opportunities',
		),
		'lps_event'          => array(
			'pt-br' => 'Eventos',
			'en'    => 'Events',
		),
		'lps_news'           => array(
			'pt-br' => 'Notícias',
			'en'    => 'News',
		),
	);

	/**
	 * Feed definitions keyed by route path.
	 *
	 * @var array<string, array<string, string>>
	 */
	private const FEEDS = array(
		'/pt-br/noticias/feed/'      => array(
			'locale'    => 'pt-br',
			'post_type' => 'lps_news',
			'title'     => 'Notícias do LPS',
		),
		'/en/news/feed/'             => array(
			'locale'    => 'en',
			'post_type' => 'lps_news',
			'title'     => 'LPS news',
		),
		'/pt-br/eventos/feed/'       => array(
			'locale'    => 'pt-br',
			'post_type' => 'lps_event',
			'title'     => 'Eventos do LPS',
		),
		'/en/events/feed/'           => array(
			'locale'    => 'en',
			'post_type' => 'lps_event',
			'title'     => 'LPS events',
		),
	);

	/** Registers metadata rendering, machine documents, and redirect handling. */
	public static function boot(): void {
		add_filter( 'wp_sitemaps_enabled', '__return_false' );
		add_filter(
			'pll_rel_hreflang_attributes',
			static function ( mixed $hreflangs ): array {
				unset( $hreflangs );
				return array();
			},
			99
		);
		add_action(
			'wp_head',
			static function (): void {
				remove_action( 'wp_head', 'rel_canonical' );
			},
			0
		);
		remove_action( 'wp_head', 'wp_shortlink_wp_head', 10 );
		remove_action( 'wp_head', 'wp_oembed_add_discovery_links' );
		remove_action( 'wp_head', 'rest_output_link_wp_head', 10 );
		remove_action( 'wp_head', 'feed_links', 2 );
		remove_action( 'wp_head', 'feed_links_extra', 3 );
		add_filter( 'rewrite_rules_array', array( self::class, 'register_routes' ), 996 );
		add_filter( 'query_vars', array( self::class, 'register_query_vars' ) );
		add_filter( 'wp_robots', array( self::class, 'filter_robots' ), 5 );
		add_filter( 'robots_txt', array( self::class, 'filter_robots_txt' ), 10, 2 );
		add_action( 'template_redirect', array( self::class, 'serve_legacy_address' ), 0 );
		add_filter( 'do_parse_request', array( self::class, 'intercept_machine_document' ), 1, 3 );
		add_action( 'template_redirect', array( self::class, 'serve_machine_document' ), 1 );
		add_filter( 'pre_get_document_title', array( self::class, 'filter_document_title' ) );
		add_action( 'wp_head', array( self::class, 'render_head' ), 1 );
	}

	/**
	 * Returns the rewrite rules for sitemaps and feeds.
	 *
	 * @return array<string, string>
	 */
	public static function rewrite_rules(): array {
		$rules = array(
			'^sitemap\.xml$'          => 'index.php?' . self::DOCUMENT_QUERY_VAR . '=sitemap-index',
			'^sitemap-(pt-br|en)\.xml$' => 'index.php?' . self::DOCUMENT_QUERY_VAR . '=sitemap-$matches[1]',
		);
		foreach ( array_keys( self::FEEDS ) as $path ) {
			$rules[ '^' . ltrim( $path, '/' ) . '$' ] = 'index.php?' . self::DOCUMENT_QUERY_VAR . '=feed:' . trim( $path, '/' );
		}
		return $rules;
	}

	/**
	 * Prepends the machine-document rewrite rules.
	 *
	 * @param array<string, string> $rules Registered rewrite rules.
	 * @return array<string, string>
	 */
	public static function register_routes( array $rules ): array {
		return array_merge( self::rewrite_rules(), $rules );
	}

	/**
	 * Registers the machine-document query var.
	 *
	 * @param array<int, string> $vars Registered public query vars.
	 * @return array<int, string>
	 */
	public static function register_query_vars( array $vars ): array {
		$vars[] = self::DOCUMENT_QUERY_VAR;
		return $vars;
	}

	/**
	 * Adds the noindex directive for every non-indexable document state.
	 *
	 * @param array<string, mixed> $robots Current robots directives.
	 * @return array<string, mixed>
	 */
	public static function filter_robots( array $robots ): array {
		if ( SeoPolicy::is_indexable( self::current_state() ) ) {
			$robots['index']  = true;
			$robots['follow'] = true;
			return $robots;
		}
		$robots['noindex'] = true;
		$robots['follow']  = true;
		unset( $robots['index'] );
		return $robots;
	}

	/**
	 * Replaces robots.txt with the canonical institutional directives.
	 *
	 * @param string $output Proposed robots.txt body.
	 * @param string $public Whether the site is public.
	 */
	public static function filter_robots_txt( string $output, string $public ): string {
		unset( $output );
		if ( '1' !== (string) $public ) {
			return "User-agent: *\nDisallow: /\n";
		}
		return SeoSurfaces::robots_txt( self::site_url() );
	}

	/** Answers a legacy address with a single hop or a deliberate removal. */
	public static function serve_legacy_address(): void {
		if ( ! is_404() ) {
			return;
		}
		$path     = SeoPolicy::canonical_path( self::request_path() );
		$resolved = SeoPolicy::resolve_redirect( self::redirect_graph(), $path );
		if ( 410 === $resolved['status'] ) {
			status_header( 410 );
			nocache_headers();
			header( 'Content-Type: text/plain; charset=utf-8' );
			echo "410 Gone\n";
			exit;
		}
		if ( 301 !== $resolved['status'] ) {
			return;
		}
		wp_safe_redirect( self::site_url() . $resolved['target'], 301 );
		exit;
	}

	/**
	 * Serves a machine document before the request is parsed into a query.
	 *
	 * @param bool  $proceed  Whether WordPress should parse the request.
	 * @param mixed $wp       Current WordPress environment instance.
	 * @param mixed $extra    Extra query variables.
	 */
	public static function intercept_machine_document( bool $proceed, mixed $wp, mixed $extra ): bool {
		unset( $wp, $extra );
		self::serve_machine_document();
		return $proceed;
	}

	/** Serves the sitemap index, locale sitemaps, and locale feeds. */
	public static function serve_machine_document(): void {
		$document = get_query_var( self::DOCUMENT_QUERY_VAR );
		if ( ! is_string( $document ) || '' === $document ) {
			$document = self::machine_document_for_path( SeoPolicy::canonical_path( self::request_path() ) );
		}
		if ( '' === $document ) {
			return;
		}
		$site_url = self::site_url();
		status_header( 200 );
		if ( 'sitemap-index' === $document ) {
			self::send_xml( SeoSurfaces::sitemap_index( $site_url, SeoPolicy::locales() ) );
		}
		if ( str_starts_with( $document, 'sitemap-' ) ) {
			$locale = substr( $document, strlen( 'sitemap-' ) );
			if ( in_array( $locale, SeoPolicy::locales(), true ) ) {
				self::send_xml( SeoSurfaces::locale_sitemap( $site_url, self::sitemap_entries( $locale ) ) );
			}
			return;
		}
		if ( str_starts_with( $document, 'feed:' ) ) {
			$path       = '/' . substr( $document, strlen( 'feed:' ) ) . '/';
			$definition = self::FEEDS[ $path ] ?? null;
			if ( null === $definition ) {
				return;
			}
			self::send_xml(
				SeoSurfaces::feed(
					array(
						'site_url' => $site_url,
						'title'    => $definition['title'],
						'path'     => str_replace( 'feed/', '', $path ),
						'locale'   => $definition['locale'],
					),
					self::feed_items( $definition['post_type'], $definition['locale'] )
				)
			);
		}
	}

	/**
	 * Resolves a machine document from its frozen path.
	 *
	 * Path dispatch keeps sitemaps and feeds reachable no matter how the
	 * surrounding locale rewrite rules are ordered.
	 *
	 * @param string $path Canonical request path.
	 */
	private static function machine_document_for_path( string $path ): string {
		if ( '/sitemap.xml' === $path ) {
			return 'sitemap-index';
		}
		if ( 1 === preg_match( '#^/sitemap-(pt-br|en)\\.xml$#', $path, $found ) ) {
			return 'sitemap-' . $found[1];
		}
		return isset( self::FEEDS[ $path ] ) ? 'feed:' . trim( $path, '/' ) : '';
	}

	/**
	 * Returns the canonical document title so WordPress prints it exactly once.
	 *
	 * @param string $title Proposed document title.
	 */
	public static function filter_document_title( string $title ): string {
		$document = self::current_document();
		$computed = is_string( $document['title'] ?? null ) ? $document['title'] : '';
		return '' === $computed ? $title : $computed;
	}

	/** Prints the canonical metadata block for the current request. */
	public static function render_head(): void {
		$document = self::current_document();
		if ( array() === $document ) {
			return;
		}
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- SeoSurfaces escapes every value it renders.
		echo SeoSurfaces::head_markup( $document ) . "\n";
	}

	/**
	 * Builds the descriptor of the current request.
	 *
	 * @return array<string, mixed>
	 */
	public static function current_document(): array {
		$path   = SeoPolicy::canonical_path( self::request_path() );
		$locale = self::current_locale( $path );
		$state  = self::current_state();
		$post   = is_singular() ? get_queried_object() : null;
		$post   = $post instanceof WP_Post ? $post : null;

		$site      = self::site_identity( $locale );
		$site_url  = self::site_url();
		$section   = null === $post ? self::archive_section( $path, $locale ) : ( self::SECTIONS[ $post->post_type ][ $locale ] ?? '' );
		$title     = null === $post ? self::archive_title( $path, $locale ) : (string) get_the_title( $post );
		$summary   = null === $post ? self::archive_summary( $path, $locale ) : (string) $post->post_excerpt;
		$nodes     = array( StructuredData::website( $site ), StructuredData::research_organization( $site ) );
		$page_type = null === $post ? 'CollectionPage' : 'WebPage';
		$og_type   = null === $post ? 'website' : 'article';

		if ( null !== $post ) {
			$record = self::record_node( $post, $path, $locale, $site );
			if ( 'lps_person' === $post->post_type ) {
				$page_type = 'ProfilePage';
			}
			if ( array() !== $record ) {
				$nodes[] = $record;
			}
			$og_type = 'lps_person' === $post->post_type ? 'profile' : 'article';
		}
		if ( 'ProfilePage' !== $page_type ) {
			$nodes[] = StructuredData::page(
				$site_url,
				$path,
				self::page_type( $path, $page_type ),
				array(
					'title'       => $title,
					'description' => SeoPolicy::meta_description( $summary ),
					'locale'      => $locale,
				)
			);
		}
		$breadcrumbs = StructuredData::breadcrumbs( $site_url, $path, self::breadcrumb_trail( $path, $locale, $section, $title ) );
		if ( array() !== $breadcrumbs ) {
			$nodes[] = $breadcrumbs;
		}

		return array(
			'site_url'     => $site_url,
			'site_name'    => 'LPS/UFRJ',
			'locale'       => $locale,
			'path'         => $path,
			'title'        => SeoPolicy::document_title( $title, $section, $locale ),
			'description'  => SeoPolicy::meta_description( self::description( $summary, $post, $title, $section, $locale ) ),
			'og_type'      => $og_type,
			'state'        => $state,
			'robots'       => false,
			'title_tag'    => false,
			'record'       => self::record_marker( $post ),
			'variants'     => self::variants( $post, $path, $locale ),
			'graph'        => StructuredData::graph( $nodes ),
			'image'        => $site_url . '/wp-content/themes/lps-theme/assets/img/mark/lps-mark-social.png',
			'image_width'  => '1200',
			'image_height' => '630',
			'image_alt'    => 'en' === $locale
				? 'LPS — Signal Processing Laboratory'
				: 'LPS — Laboratório de Processamento de Sinais',
		);
	}

	/**
	 * Returns the machine-readable record marker for the current document.
	 *
	 * @param WP_Post|null $post Queried record.
	 * @return array<string, string>
	 */
	private static function record_marker( ?WP_Post $post ): array {
		if ( null === $post ) {
			return array();
		}
		$marker = array( 'type' => $post->post_type );
		if ( 'lps_opportunity' === $post->post_type ) {
			$marker['kind'] = self::text( get_post_meta( self::source_id( $post ), '_lps_opportunity_type', true ) );
		}
		return $marker;
	}

	/**
	 * Builds the schema node describing one record.
	 *
	 * @param WP_Post              $post   Queried record.
	 * @param string               $path   Canonical path.
	 * @param string               $locale Locale slug.
	 * @param array<string, mixed> $site   Site identity.
	 * @return array<string, mixed>
	 */
	private static function record_node( WP_Post $post, string $path, string $locale, array $site ): array {
		unset( $locale );
		$site_url = self::site_url();
		$source   = self::source_id( $post );
		$meta     = static fn ( string $key ): string => self::text( get_post_meta( $source, $key, true ) );
		switch ( $post->post_type ) {
			case 'lps_person':
				return StructuredData::profile_page(
					$site_url,
					$path,
					array(
						'name'             => '' === $meta( '_lps_canonical_name' ) ? (string) get_the_title( $post ) : $meta( '_lps_canonical_name' ),
						'summary'          => (string) $post->post_excerpt,
						'roles'            => self::meta_list( $source, '_lps_roles' ),
						'orcid'            => $meta( '_lps_orcid' ),
						'lattes_url'       => $meta( '_lps_lattes_url' ),
						'scholar_url'      => $meta( '_lps_scholar_url' ),
						'website_url'      => $meta( '_lps_website_url' ),
						'public_email'     => $meta( '_lps_public_email' ),
						'privacy_reviewed' => (bool) get_post_meta( $source, '_lps_privacy_reviewed', true ),
					)
				);
			case 'lps_project':
				return StructuredData::research_project(
					$site_url,
					$path,
					array(
						'name'    => (string) get_the_title( $post ),
						'summary' => (string) $post->post_excerpt,
						'status'  => $meta( '_lps_project_status' ),
						'start'   => $meta( '_lps_start_date' ),
						'end'     => $meta( '_lps_end_date' ),
						'funders' => self::related_titles( $source, '_lps_funder_ids' ),
						'members' => self::related_titles( $source, '_lps_member_ids' ),
					)
				);
			case 'lps_publication':
				return StructuredData::publication(
					$site_url,
					$path,
					array(
						'title'           => '' === $meta( '_lps_authoritative_title' ) ? (string) get_the_title( $post ) : $meta( '_lps_authoritative_title' ),
						'type'            => $meta( '_lps_publication_type' ),
						'date'            => $meta( '_lps_publication_date' ),
						'language'        => $meta( '_lps_language' ),
						'doi'             => $meta( '_lps_doi' ),
						'venue'           => $meta( '_lps_venue' ),
						'license'         => $meta( '_lps_license' ),
						'canonical_url'   => $meta( '_lps_canonical_url' ),
						'open_access_url' => $meta( '_lps_open_access_url' ),
						'code_url'        => $meta( '_lps_code_url' ),
						'data_url'        => $meta( '_lps_data_url' ),
						'authors'         => self::related_titles( $source, '_lps_author_ids' ),
					)
				);
			case 'lps_news':
				return StructuredData::news_article(
					$site_url,
					$path,
					array(
						'title'    => (string) get_the_title( $post ),
						'summary'  => (string) $post->post_excerpt,
						'date'     => $meta( '_lps_canonical_date' ),
						'modified' => (string) get_post_modified_time( 'c', true, $post ),
					),
					$site
				);
			case 'lps_event':
				return StructuredData::event(
					$site_url,
					$path,
					array(
						'title'      => (string) get_the_title( $post ),
						'summary'    => (string) $post->post_excerpt,
						'starts_at'  => $meta( '_lps_starts_at' ),
						'ends_at'    => $meta( '_lps_ends_at' ),
						'state'      => TrustSurfacePolicy::event_state( $meta( '_lps_event_status' ), $meta( '_lps_starts_at' ), $meta( '_lps_ends_at' ), TrustRoutes::now() ),
						'venue'      => $meta( '_lps_venue' ),
						'online_url' => $meta( '_lps_online_url' ),
					)
				);
			case 'lps_opportunity':
				return StructuredData::opportunity(
					$site_url,
					$path,
					array(
						'title'       => (string) get_the_title( $post ),
						'summary'     => (string) $post->post_excerpt,
						'type'        => $meta( '_lps_opportunity_type' ),
						'opens_at'    => $meta( '_lps_opens_at' ),
						'closes_at'   => $meta( '_lps_closes_at' ),
						'location'    => $meta( '_lps_location' ),
						'eligibility' => $meta( '_lps_eligibility' ),
						'positions'   => self::integer( get_post_meta( $source, '_lps_positions', true ) ),
					),
					$site
				);
			default:
				return array();
		}
	}

	/**
	 * Returns the state flags of the current request.
	 *
	 * @return array<string, bool>
	 */
	private static function current_state(): array {
		$state = array();
		$path  = SeoPolicy::canonical_path( self::request_path() );
		if ( null !== SearchRoutes::match_path( $path ) ) {
			$state['search'] = true;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only indexation decision.
		if ( array() !== $_GET ) {
			$state['filtered'] = true;
		}
		if ( function_exists( 'is_preview' ) && is_preview() ) {
			$state['preview'] = true;
		}
		if ( function_exists( 'is_paged' ) && is_paged() ) {
			$state['filtered'] = true;
		}
		$post = is_singular() ? get_queried_object() : null;
		if ( $post instanceof WP_Post ) {
			if ( 'publish' !== $post->post_status ) {
				$state['draft'] = true;
			}
			if ( 'lps_opportunity' === $post->post_type ) {
				$closes = self::text( get_post_meta( self::source_id( $post ), '_lps_closes_at', true ) );
				if ( TrustSurfacePolicy::opportunity_is_noindex( $closes, TrustRoutes::now() ) ) {
					$state['expired'] = true;
				}
			}
		}
		return $state;
	}

	/**
	 * Returns the published locale variants of the current document.
	 *
	 * @param WP_Post|null $post   Queried record.
	 * @param string       $path   Canonical path.
	 * @param string       $locale Locale slug.
	 * @return array<string, array<string, mixed>>
	 */
	private static function variants( ?WP_Post $post, string $path, string $locale ): array {
		if ( null !== $post ) {
			$variants = array();
			foreach ( self::translations( $post ) as $slug => $translated ) {
				$variants[ $slug ] = array(
					'path'      => self::record_path( $translated, $slug ),
					'published' => 'publish' === $translated->post_status,
				);
			}
			return $variants;
		}
		$counterpart = self::counterpart_path( $path, $locale );
		if ( '' === $counterpart ) {
			return array();
		}
		$other = 'pt-br' === $locale ? 'en' : 'pt-br';
		return array(
			$locale => array(
				'path'      => $path,
				'published' => true,
			),
			$other  => array(
				'path'      => $counterpart,
				'published' => true,
			),
		);
	}

	/**
	 * Returns every locale variant of a record, including unpublished ones.
	 *
	 * @param WP_Post $post Queried record.
	 * @return array<string, WP_Post>
	 */
	private static function translations( WP_Post $post ): array {
		$result = array();
		if ( ! function_exists( 'pll_get_post_translations' ) ) {
			$locale = self::text( get_post_meta( $post->ID, '_lps_locale', true ) );
			return '' === $locale ? array() : array( $locale => $post );
		}
		$translations = pll_get_post_translations( $post->ID );
		if ( ! is_array( $translations ) ) {
			return array();
		}
		foreach ( $translations as $slug => $post_id ) {
			if ( ! is_int( $post_id ) || ! in_array( $slug, SeoPolicy::locales(), true ) ) {
				continue;
			}
			$translated = get_post( $post_id );
			if ( $translated instanceof WP_Post ) {
				$result[ $slug ] = $translated;
			}
		}
		return $result;
	}

	/**
	 * Returns the public path of a record in its locale.
	 *
	 * @param WP_Post $post   Record.
	 * @param string  $locale Locale slug.
	 */
	public static function record_path( WP_Post $post, string $locale ): string {
		if ( in_array( $post->post_type, self::DISCOVERY_TYPES, true ) ) {
			return DiscoveryRoutes::single_path( $post->post_type, $locale, $post->post_name );
		}
		if ( in_array( $post->post_type, self::PUBLIC_TYPES, true ) ) {
			return PublicRoutes::single_path( $post->post_type, $locale, $post->post_name );
		}
		if ( in_array( $post->post_type, self::TRUST_TYPES, true ) ) {
			return TrustRoutes::single_path( $post->post_type, $locale, $post->post_name );
		}
		if ( 'page' === $post->post_type ) {
			return TrustRoutes::page_path( self::text( get_post_meta( $post->ID, '_lps_page_key', true ) ), $locale );
		}
		return '';
	}

	/**
	 * Returns the counterpart locale path of a non-record route.
	 *
	 * @param string $path   Canonical path.
	 * @param string $locale Locale slug.
	 */
	private static function counterpart_path( string $path, string $locale ): string {
		$other = 'pt-br' === $locale ? 'en' : 'pt-br';
		if ( '/' . $locale . '/' === $path ) {
			return '/' . $other . '/';
		}
		foreach ( array_merge( self::DISCOVERY_TYPES, self::PUBLIC_TYPES, self::TRUST_TYPES ) as $post_type ) {
			if ( self::archive_path( $post_type, $locale ) === $path ) {
				return self::archive_path( $post_type, $other );
			}
		}
		return '';
	}

	/**
	 * Returns the localized archive path of a record type.
	 *
	 * @param string $post_type Record type.
	 * @param string $locale    Locale slug.
	 */
	public static function archive_path( string $post_type, string $locale ): string {
		if ( in_array( $post_type, self::DISCOVERY_TYPES, true ) ) {
			return DiscoveryRoutes::archive_path( $post_type, $locale );
		}
		if ( in_array( $post_type, self::PUBLIC_TYPES, true ) ) {
			return PublicRoutes::archive_path( $post_type, $locale );
		}
		if ( in_array( $post_type, self::TRUST_TYPES, true ) ) {
			return TrustRoutes::archive_path( $post_type, $locale );
		}
		return '';
	}

	/**
	 * Returns the localized section label of an archive path.
	 *
	 * @param string $path   Canonical path.
	 * @param string $locale Locale slug.
	 */
	private static function archive_section( string $path, string $locale ): string {
		foreach ( self::SECTIONS as $post_type => $labels ) {
			if ( self::archive_path( $post_type, $locale ) === $path ) {
				return $labels[ $locale ] ?? '';
			}
		}
		return '';
	}

	/**
	 * Returns the localized title of a non-record route.
	 *
	 * @param string $path   Canonical path.
	 * @param string $locale Locale slug.
	 */
	private static function archive_title( string $path, string $locale ): string {
		$section = self::archive_section( $path, $locale );
		if ( '' !== $section ) {
			return $section;
		}
		if ( '/' . $locale . '/' === $path ) {
			return 'en' === $locale
				? 'Signal Processing Laboratory'
				: 'Laboratório de Processamento de Sinais';
		}
		$title = wp_strip_all_tags( (string) wp_title( '', false ) );
		return '' === trim( $title ) ? ( 'en' === $locale ? 'LPS' : 'LPS' ) : trim( $title );
	}

	/**
	 * Returns the localized summary of a non-record route.
	 *
	 * @param string $path   Canonical path.
	 * @param string $locale Locale slug.
	 */
	private static function archive_summary( string $path, string $locale ): string {
		$section = self::archive_section( $path, $locale );
		if ( '' !== $section ) {
			return 'en' === $locale
				? sprintf( '%s published by the Signal Processing Laboratory at COPPE/UFRJ.', $section )
				: sprintf( '%s publicadas pelo Laboratório de Processamento de Sinais da COPPE/UFRJ.', $section );
		}
		return self::fallback_summary( $locale );
	}

	/**
	 * Returns a description that belongs to this document alone.
	 *
	 * A record without an editorial summary falls back to its own body, and
	 * finally to a sentence built from its own title and section, so two
	 * different addresses can never share one boilerplate description.
	 *
	 * @param string       $summary Editorial summary.
	 * @param WP_Post|null $post    Queried record.
	 * @param string       $title   Document title.
	 * @param string       $section Localized section label.
	 * @param string       $locale  Locale slug.
	 */
	private static function description( string $summary, ?WP_Post $post, string $title, string $section, string $locale ): string {
		if ( '' !== trim( $summary ) ) {
			return $summary;
		}
		if ( null !== $post ) {
			$body = trim( wp_strip_all_tags( $post->post_content ) );
			if ( '' !== $body ) {
				return $body;
			}
		}
		if ( '' !== trim( $title ) ) {
			$where = '' === trim( $section ) ? 'LPS' : $section;
			return 'en' === $locale
				? sprintf( '%s - %s record published by LPS at COPPE/UFRJ.', $title, $where )
				: sprintf( '%s - registro de %s publicado pelo LPS na COPPE/UFRJ.', $title, $where );
		}
		return self::fallback_summary( $locale );
	}

	/**
	 * Returns the institutional description used when a record has no summary.
	 *
	 * @param string $locale Locale slug.
	 */
	private static function fallback_summary( string $locale ): string {
		return 'en' === $locale
			? 'Signal Processing Laboratory at COPPE/UFRJ: research, people, projects, publications, and infrastructure.'
			: 'Laboratório de Processamento de Sinais da COPPE/UFRJ: pesquisa, pessoas, projetos, publicações e infraestrutura.';
	}

	/**
	 * Returns the Schema.org page type of a route.
	 *
	 * @param string $path     Canonical path.
	 * @param string $fallback Proposed page type.
	 */
	private static function page_type( string $path, string $fallback ): string {
		if ( null !== SearchRoutes::match_path( $path ) ) {
			return 'SearchResultsPage';
		}
		foreach ( SeoPolicy::locales() as $locale ) {
			if ( '/' . $locale . '/' === $path ) {
				return 'WebPage';
			}
		}
		return $fallback;
	}

	/**
	 * Builds the breadcrumb trail of a document.
	 *
	 * @param string $path    Canonical path.
	 * @param string $locale  Locale slug.
	 * @param string $section Localized section label.
	 * @param string $title   Document title.
	 * @return array<int, array<string, string>>
	 */
	private static function breadcrumb_trail( string $path, string $locale, string $section, string $title ): array {
		$home  = '/' . $locale . '/';
		$trail = array(
			array(
				'name' => 'en' === $locale ? 'Home' : 'Início',
				'path' => $home,
			),
		);
		if ( $path === $home ) {
			return $trail;
		}
		foreach ( self::SECTIONS as $post_type => $labels ) {
			$archive = self::archive_path( $post_type, $locale );
			if ( '' !== $archive && str_starts_with( $path, $archive ) ) {
				$trail[] = array(
					'name' => $labels[ $locale ] ?? $section,
					'path' => $archive,
				);
				break;
			}
		}
		$trail[] = array(
			'name' => $title,
			'path' => $path,
		);
		return $trail;
	}

	/**
	 * Returns every indexable entry of one locale sitemap.
	 *
	 * @param string $locale Locale slug.
	 * @return array<int, array<string, string>>
	 */
	public static function sitemap_entries( string $locale ): array {
		$site_url = self::site_url();
		$entries  = array(
			array(
				'path'     => '/' . $locale . '/',
				'modified' => gmdate( 'c' ),
			),
		);
		foreach ( array_keys( self::SECTIONS ) as $post_type ) {
			$archive = self::archive_path( $post_type, $locale );
			if ( '' !== $archive && self::archive_is_served( $post_type, $locale ) ) {
				$entries[] = array(
					'path'     => $archive,
					'modified' => gmdate( 'c' ),
				);
			}
		}
		$addressable = self::addressable_slugs( $locale );
		foreach ( self::published_records( array_keys( self::SECTIONS ), $locale ) as $post ) {
			$path = self::record_path( $post, $locale );
			if ( '' === $path ) {
				continue;
			}
			$state = array();
			if ( 'lps_opportunity' === $post->post_type ) {
				$closes = self::text( get_post_meta( self::source_id( $post ), '_lps_closes_at', true ) );
				if ( TrustSurfacePolicy::opportunity_is_noindex( $closes, TrustRoutes::now() ) ) {
					$state['expired'] = true;
				}
			}
			if ( 'lps_person' === $post->post_type && ! get_post_meta( $post->ID, '_lps_privacy_reviewed', true ) ) {
				$state['private'] = true;
			}
			if ( isset( $addressable[ $post->post_type ] ) && ! in_array( $post->post_name, $addressable[ $post->post_type ], true ) ) {
				$state['unavailable'] = true;
			}
			$candidate = array(
				'path'      => $path,
				'canonical' => SeoPolicy::canonical_url( $site_url, $path ),
				'state'     => $state,
			);
			if ( ! SeoPolicy::in_sitemap( $site_url, $candidate ) ) {
				continue;
			}
			$entries[] = array(
				'path'     => $path,
				'modified' => (string) get_post_modified_time( 'c', true, $post ),
			);
		}
		return $entries;
	}

	/**
	 * Returns the slugs the public routes answer, keyed by record type.
	 *
	 * People and organizations are published behind a consent and a public
	 * profile gate, so their records exist while their addresses answer `404`.
	 * The sitemap has to ask the same gate the router asks.
	 *
	 * @param string $locale Locale slug.
	 * @return array<string, array<int, string>>
	 */
	private static function addressable_slugs( string $locale ): array {
		$slugs = array();
		$sets  = array(
			'lps_person'       => PublicRoutes::people( $locale ),
			'lps_organization' => PublicRoutes::organizations( $locale ),
		);
		foreach ( $sets as $post_type => $records ) {
			$slugs[ $post_type ] = array();
			foreach ( $records as $record ) {
				$slug = is_string( $record['slug'] ?? null ) ? $record['slug'] : '';
				if ( '' !== $slug ) {
					$slugs[ $post_type ][] = $slug;
				}
			}
		}
		return $slugs;
	}

	/**
	 * Reports whether a locale archive answers with a document.
	 *
	 * Record archives are generated from their type and always answer. The
	 * infrastructure archive resolves to an editorial page, so it only belongs
	 * in a sitemap once that page is actually published.
	 *
	 * @param string $post_type Record type.
	 * @param string $locale    Locale slug.
	 */
	private static function archive_is_served( string $post_type, string $locale ): bool {
		if ( 'lps_infrastructure' !== $post_type ) {
			return true;
		}
		$segment = trim( str_replace( '/' . $locale . '/', '', self::archive_path( $post_type, $locale ) ), '/' );
		if ( '' === $segment ) {
			return false;
		}
		$page = get_page_by_path( $segment );
		return $page instanceof WP_Post && 'publish' === $page->post_status;
	}

	/**
	 * Returns the published feed items of one record type and locale.
	 *
	 * @param string $post_type Record type.
	 * @param string $locale    Locale slug.
	 * @return array<int, array<string, string>>
	 */
	public static function feed_items( string $post_type, string $locale ): array {
		$items = array();
		foreach ( self::published_records( array( $post_type ), $locale ) as $post ) {
			$path = self::record_path( $post, $locale );
			if ( '' === $path ) {
				continue;
			}
			$source = self::source_id( $post );
			$date   = 'lps_event' === $post_type
				? self::text( get_post_meta( $source, '_lps_starts_at', true ) )
				: self::text( get_post_meta( $source, '_lps_canonical_date', true ) );
			$items[] = array(
				'title'       => (string) get_the_title( $post ),
				'path'        => $path,
				'description' => SeoPolicy::meta_description( (string) $post->post_excerpt ),
				'date'        => '' === $date ? (string) get_post_time( 'c', true, $post ) : $date,
			);
		}
		return $items;
	}

	/**
	 * Returns every published record of the given types in one locale.
	 *
	 * @param array<int, string> $post_types Record types.
	 * @param string             $locale     Locale slug.
	 * @return array<int, WP_Post>
	 */
	private static function published_records( array $post_types, string $locale ): array {
		$query = new WP_Query(
			array(
				'post_type'              => $post_types,
				'post_status'            => 'publish',
				'posts_per_page'         => 500,
				'orderby'                => 'date',
				'order'                  => 'DESC',
				'no_found_rows'          => true,
				'update_post_term_cache' => false,
				'lang'                   => $locale,
				'meta_query'             => array(
					array(
						'key'   => '_lps_locale',
						'value' => $locale,
					),
				),
			)
		);
		$posts = array();
		foreach ( $query->posts as $post ) {
			if ( $post instanceof WP_Post ) {
				$posts[] = $post;
			}
		}
		return $posts;
	}

	/**
	 * Returns the redirect graph published by the content model.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function redirect_graph(): array {
		$query = new WP_Query(
			array(
				'post_type'              => 'lps_redirect',
				'post_status'            => 'publish',
				'posts_per_page'         => 500,
				'no_found_rows'          => true,
				'update_post_term_cache' => false,
			)
		);
		$graph = array();
		foreach ( $query->posts as $post ) {
			if ( ! $post instanceof WP_Post ) {
				continue;
			}
			$source = SeoPolicy::canonical_path( self::text( get_post_meta( $post->ID, '_lps_redirect_source', true ) ) );
			if ( '/' === $source ) {
				continue;
			}
			$gone            = (bool) get_post_meta( $post->ID, '_lps_redirect_gone', true );
			$graph[ $source ] = $gone
				? array( 'status' => 410 )
				: array(
					'status' => 301,
					'target' => SeoPolicy::canonical_path( self::text( get_post_meta( $post->ID, '_lps_redirect_target', true ) ) ),
				);
		}
		return $graph;
	}

	/**
	 * Returns the site identity used by every structured-data graph.
	 *
	 * @param string $locale Locale slug.
	 * @return array<string, mixed>
	 */
	private static function site_identity( string $locale ): array {
		return array(
			'site_url' => self::site_url(),
			'name'     => 'en' === $locale ? 'Signal Processing Laboratory' : 'Laboratório de Processamento de Sinais',
			'acronym'  => 'LPS',
			'parents'  => array( 'COPPE', 'UFRJ' ),
			'locale'   => $locale,
			'address'  => 'Rio de Janeiro, RJ, Brasil',
			'logo'     => array(
				'url'    => self::site_url() . '/wp-content/themes/lps-theme/assets/img/mark/lps-mark-social.png',
				'width'  => 1200,
				'height' => 630,
			),
		);
	}

	/**
	 * Returns the authoritative record identifier of a translated record.
	 *
	 * @param WP_Post $post Record.
	 */
	private static function source_id( WP_Post $post ): int {
		if ( ! class_exists( '\\LPS\\ContentModel\\Translations' ) ) {
			return $post->ID;
		}
		$source = \LPS\ContentModel\Translations::source_id( $post->ID );
		return is_int( $source ) ? $source : $post->ID;
	}

	/**
	 * Returns a stored list meta value as strings.
	 *
	 * @param int    $post_id Record identifier.
	 * @param string $key     Meta key.
	 * @return array<int, string>
	 */
	private static function meta_list( int $post_id, string $key ): array {
		$value = get_post_meta( $post_id, $key, true );
		if ( ! is_array( $value ) ) {
			return array();
		}
		$result = array();
		foreach ( $value as $item ) {
			if ( is_scalar( $item ) && '' !== trim( (string) $item ) ) {
				$result[] = trim( (string) $item );
			}
		}
		return $result;
	}

	/**
	 * Returns the titles of related records referenced by a meta key.
	 *
	 * @param int    $post_id Record identifier.
	 * @param string $key     Meta key.
	 * @return array<int, string>
	 */
	private static function related_titles( int $post_id, string $key ): array {
		$value = get_post_meta( $post_id, $key, true );
		if ( ! is_array( $value ) ) {
			return array();
		}
		$titles = array();
		foreach ( $value as $related_id ) {
			$related = get_post( self::integer( $related_id ) );
			if ( $related instanceof WP_Post && 'publish' === $related->post_status ) {
				$titles[] = (string) get_the_title( $related );
			}
		}
		return $titles;
	}

	/** Returns the canonical site origin. */
	public static function site_url(): string {
		$configured = self::text( get_option( 'home' ) );
		return rtrim( '' === $configured ? (string) home_url( '/' ) : $configured, '/' );
	}

	/**
	 * Resolves the locale of the current request.
	 *
	 * @param string $path Canonical path.
	 */
	private static function current_locale( string $path ): string {
		return 1 === preg_match( '~^/en(?:/|$)~', $path ) ? 'en' : 'pt-br';
	}

	/** Returns the sanitized current request path. */
	private static function request_path(): string {
		if ( ! isset( $_SERVER['REQUEST_URI'] ) || ! is_string( $_SERVER['REQUEST_URI'] ) ) {
			return '/';
		}
		$path = wp_parse_url( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ), PHP_URL_PATH );
		return is_string( $path ) ? $path : '/';
	}

	/**
	 * Sends one XML document and ends the request.
	 *
	 * @param string $body Rendered XML.
	 */
	private static function send_xml( string $body ): void {
		header( 'Content-Type: application/xml; charset=utf-8' );
		header( 'X-Robots-Tag: noindex, follow' );
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- SeoSurfaces escapes every value it renders.
		echo $body;
		exit;
	}

	/**
	 * Narrows an untyped meta or option value to a trimmed string.
	 *
	 * WordPress returns `mixed` from meta and option reads, including `false`
	 * for a missing key and an array for a multi-value key. Both collapse to an
	 * empty string so a caller never renders `Array` into a public document.
	 *
	 * @param mixed $value Untyped stored value.
	 */
	private static function text( mixed $value ): string {
		return is_scalar( $value ) ? trim( (string) $value ) : '';
	}

	/**
	 * Narrows an untyped meta or option value to an integer.
	 *
	 * @param mixed $value Untyped stored value.
	 */
	private static function integer( mixed $value ): int {
		return is_numeric( $value ) ? (int) $value : 0;
	}
}
