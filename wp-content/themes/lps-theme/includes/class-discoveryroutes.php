<?php
/**
 * Locale routes and citation downloads for discovery surfaces.
 *
 * @package LPS\Theme
 */

declare(strict_types=1);

namespace LPS\Theme;

use LPS\ContentModel\Relationships;
use LPS\ContentModel\Translations;
use wpdb;
use WP_Post;
use WP_Query;

require_once __DIR__ . '/class-discoverysurfaces.php';

/** Binds discovery rendering to locale routes and citation download endpoints. */
final class DiscoveryRoutes {
	public const LOCALE_QUERY_VAR   = 'lps_discovery_locale';
	public const CITATION_QUERY_VAR = 'lps_citation';

	/**
	 * Frozen locale path segment for each discovery record type.
	 *
	 * @var array<string, array<string, string>>
	 */
	private const SEGMENTS = array(
		'lps_research_area' => array(
			'pt-br' => 'pesquisa',
			'en'    => 'research',
		),
		'lps_project'       => array(
			'pt-br' => 'projetos',
			'en'    => 'projects',
		),
		'lps_publication'   => array(
			'pt-br' => 'publicacoes',
			'en'    => 'publications',
		),
	);

	/**
	 * BCP47 language tag for each supported locale slug.
	 *
	 * @var array<string, string>
	 */
	private const LANGUAGE_TAGS = array(
		'pt-br' => 'pt-BR',
		'en'    => 'en',
	);

	/**
	 * Download contract for each supported citation format.
	 *
	 * @var array<string, array{content_type: string, extension: string}>
	 */
	private const CITATION_FORMATS = array(
		'bibtex'   => array(
			'content_type' => 'application/x-bibtex; charset=utf-8',
			'extension'    => 'bib',
		),
		'csl-json' => array(
			'content_type' => 'application/vnd.citationstyles.csl+json; charset=utf-8',
			'extension'    => 'json',
		),
	);

	/**
	 * Returns the localized archive path for a discovery record type.
	 *
	 * @param string $post_type Discovery record type.
	 * @param string $locale    Supported locale slug.
	 */
	public static function archive_path( string $post_type, string $locale ): string {
		$segment = self::SEGMENTS[ $post_type ][ $locale ] ?? '';
		return '' === $segment ? '' : '/' . $locale . '/' . $segment . '/';
	}

	/**
	 * Returns the localized single-record path.
	 *
	 * @param string $post_type Discovery record type.
	 * @param string $locale    Supported locale slug.
	 * @param string $slug      Record slug.
	 */
	public static function single_path( string $post_type, string $locale, string $slug ): string {
		$archive = self::archive_path( $post_type, $locale );
		return '' === $archive || '' === $slug ? $archive : $archive . $slug . '/';
	}

	/**
	 * Resolves a public path back to its record type, locale, and slug.
	 *
	 * @param string $path Public request path.
	 * @return array{post_type: string, locale: string, slug: string}|null
	 */
	public static function match_path( string $path ): ?array {
		$clean = '/' . trim( $path, '/' ) . '/';
		if ( 1 !== preg_match( '#^/(pt-br|en)/([^/]+)/(?:([^/]+)/)?$#', $clean, $parts ) ) {
			return null;
		}
		foreach ( self::SEGMENTS as $post_type => $segments ) {
			if ( $segments[ $parts[1] ] === $parts[2] ) {
				return array(
					'post_type' => $post_type,
					'locale'    => $parts[1],
					'slug'      => $parts[3] ?? '',
				);
			}
		}
		return null;
	}

	/**
	 * Returns the BCP47 language tag for a supported locale slug.
	 *
	 * @param string $locale Supported locale slug.
	 */
	public static function bcp47( string $locale ): string {
		return self::LANGUAGE_TAGS[ $locale ] ?? self::LANGUAGE_TAGS['pt-br'];
	}

	/**
	 * Returns WordPress rewrite rules for every discovery route.
	 *
	 * @return array<string, string>
	 */
	public static function rewrite_rules(): array {
		$rules = array();
		foreach ( self::SEGMENTS as $post_type => $segments ) {
			foreach ( $segments as $locale => $segment ) {
				$base                                    = $locale . '/' . $segment;
				$query                                   = 'index.php?post_type=' . $post_type . '&' . self::LOCALE_QUERY_VAR . '=' . $locale . '&lang=' . $locale;
				$rules[ $base . '/page/([0-9]{1,})/?$' ] = $query . '&paged=$matches[1]';
				$rules[ $base . '/([^/]+)/?$' ]          = $query . '&name=$matches[1]';
				$rules[ $base . '/?$' ]                  = $query;
			}
		}
		return $rules;
	}

	/**
	 * Returns download headers for a citation format, or an empty list when unsupported.
	 *
	 * @param string $format Requested citation format.
	 * @param string $key    Citation key used as the file name.
	 * @return array<string, string>
	 */
	public static function citation_headers( string $format, string $key ): array {
		$definition = self::CITATION_FORMATS[ $format ] ?? null;
		if ( null === $definition ) {
			return array();
		}
		$name = '' === $key ? 'citation' : $key;
		return array(
			'Content-Type'           => $definition['content_type'],
			'Content-Disposition'    => 'attachment; filename="' . $name . '.' . $definition['extension'] . '"',
			'X-Content-Type-Options' => 'nosniff',
		);
	}

	/**
	 * Builds the citation payload, or null when the format is unsupported.
	 *
	 * @param string               $format  Requested citation format.
	 * @param array<string, mixed> $record  Publication record.
	 * @param array<int, mixed>    $authors Ordered authors.
	 */
	public static function citation_body( string $format, array $record, array $authors ): ?string {
		if ( 'bibtex' === $format ) {
			return DiscoverySurfaces::bibtex( $record, $authors );
		}
		if ( 'csl-json' === $format ) {
			return DiscoverySurfaces::csl_json( $record, $authors );
		}
		return null;
	}

	/**
	 * Returns the citation download URL for one publication.
	 *
	 * @param string $locale Supported locale slug.
	 * @param string $slug   Publication slug.
	 * @param string $format Requested citation format.
	 */
	public static function citation_url( string $locale, string $slug, string $format ): string {
		return self::single_path( 'lps_publication', $locale, $slug ) . '?' . self::CITATION_QUERY_VAR . '=' . $format;
	}

	/**
	 * Replaces the document language tag on discovery routes.
	 *
	 * The route locale is authoritative there, so the document tag matches the
	 * hreflang the locale switcher advertises for the same record.
	 *
	 * @param string $output Rendered language attributes.
	 * @param string $path   Public request path.
	 */
	public static function language_attributes_for_path( string $output, string $path ): string {
		$route = self::match_path( $path );
		if ( null === $route ) {
			return $output;
		}
		$tagged = preg_replace( '/lang="[^"]*"/', 'lang="' . self::bcp47( $route['locale'] ) . '"', $output );
		return is_string( $tagged ) ? $tagged : $output;
	}

	/** Registers routes, query vars, downloads, listing filters, and the discovery block. */
	public static function boot(): void {
		add_filter( 'rewrite_rules_array', array( self::class, 'register_routes' ), 999 );
		add_filter( 'query_vars', array( self::class, 'register_query_vars' ) );
		add_action( 'template_redirect', array( self::class, 'serve_citation' ), 0 );
		add_filter( 'redirect_canonical', array( self::class, 'keep_locale_route' ), 10, 2 );
		add_filter( 'language_attributes', array( self::class, 'route_language_attributes' ), 200 );
		add_action( 'pre_get_posts', array( self::class, 'filter_archive_query' ) );
	}

	/**
	 * Prepends the discovery rewrite rules after every other rule filter has run.
	 *
	 * Registering them this late keeps the frozen locale prefixes intact instead of
	 * letting the translation plugin prefix them a second time.
	 *
	 * @param array<string, string> $rules Registered rewrite rules.
	 * @return array<string, string>
	 */
	public static function register_routes( array $rules ): array {
		return array_merge( self::rewrite_rules(), $rules );
	}

	/**
	 * Registers the discovery query vars.
	 *
	 * @param array<int, string> $vars Registered public query vars.
	 * @return array<int, string>
	 */
	public static function register_query_vars( array $vars ): array {
		$vars[] = self::LOCALE_QUERY_VAR;
		$vars[] = self::CITATION_QUERY_VAR;
		return $vars;
	}

	/**
	 * Keeps locale routes stable instead of redirecting to the default permalink.
	 *
	 * @param string|false $redirect_url  Proposed canonical URL.
	 * @param string       $requested_url Requested URL.
	 * @return string|false
	 */
	public static function keep_locale_route( string|false $redirect_url, string $requested_url ): string|false {
		$path = wp_parse_url( $requested_url, PHP_URL_PATH );
		if ( is_string( $path ) && null !== self::match_path( $path ) ) {
			return false;
		}
		return $redirect_url;
	}

	/**
	 * Applies the route language tag to the current request.
	 *
	 * @param string $output Rendered language attributes.
	 */
	public static function route_language_attributes( string $output ): string {
		return self::language_attributes_for_path( $output, self::request_path() );
	}

	/** Serves BibTeX and CSL-JSON downloads before any template renders. */
	public static function serve_citation(): void {
		$format = self::request_value( self::CITATION_QUERY_VAR );
		if ( '' === $format ) {
			return;
		}
		$publication = self::requested_publication();
		$headers     = array();
		$body        = null;
		if ( $publication instanceof WP_Post ) {
			$record  = self::publication_record( $publication, self::locale_for_post( $publication ) );
			$authors = self::publication_authors( $publication, self::locale_for_post( $publication ) );
			$headers = self::citation_headers( $format, 'lps' . $publication->ID );
			$body    = self::citation_body( $format, $record, $authors );
		}
		if ( array() === $headers || null === $body ) {
			status_header( 404 );
			nocache_headers();
			header( 'Content-Type: text/plain; charset=utf-8' );
			echo "Citation not available\n";
			exit;
		}
		nocache_headers();
		foreach ( $headers as $name => $value ) {
			header( $name . ': ' . $value );
		}
		echo $body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- citation download payload, not markup.
		exit;
	}

	/** Renders the discovery block for the current query. */
	public static function render_block(): string {
		$locale = self::current_locale();
		if ( is_singular( array_keys( self::SEGMENTS ) ) ) {
			$post = get_queried_object();
			return $post instanceof WP_Post ? self::render_single( $post, $locale ) : '';
		}
		return self::render_archive( $locale );
	}

	/**
	 * Renders one discovery record.
	 *
	 * @param WP_Post $post   Queried record.
	 * @param string  $locale Supported locale slug.
	 */
	private static function render_single( WP_Post $post, string $locale ): string {
		if ( 'lps_publication' === $post->post_type ) {
			return DiscoverySurfaces::render_publication(
				self::publication_record( $post, $locale ),
				self::publication_authors( $post, $locale ),
				self::publication_relations( $post, $locale ),
				$locale
			);
		}
		if ( 'lps_project' === $post->post_type ) {
			return DiscoverySurfaces::render_project(
				array(
					'title'      => $post->post_title,
					'summary'    => $post->post_excerpt,
					'body'       => $post->post_content,
					'status'     => self::shared_meta( $post, '_lps_project_status' ),
					'start_date' => self::shared_meta( $post, '_lps_start_date' ),
					'end_date'   => self::shared_meta( $post, '_lps_end_date' ),
				),
				self::project_relationships( $post, $locale ),
				$locale
			);
		}
		return DiscoverySurfaces::render_area(
			array(
				'title'   => $post->post_title,
				'summary' => $post->post_excerpt,
				'body'    => $post->post_content,
			),
			self::area_projects( $post, $locale ),
			$locale
		);
	}

	/**
	 * Renders the listing for the current archive query.
	 *
	 * @param string $locale Supported locale slug.
	 */
	private static function render_archive( string $locale ): string {
		$query = $GLOBALS['wp_query'] ?? null;
		if ( ! $query instanceof WP_Query ) {
			return '';
		}
		$post_type = $query->get( 'post_type' );
		$post_type = is_string( $post_type ) ? $post_type : '';
		if ( ! isset( self::SEGMENTS[ $post_type ] ) ) {
			return '';
		}
		$items = array();
		foreach ( $query->posts as $post ) {
			if ( ! $post instanceof WP_Post ) {
				continue;
			}
			$items[] = array(
				'title'   => self::display_title( $post ),
				'url'     => self::single_path( $post_type, $locale, $post->post_name ),
				'summary' => $post->post_excerpt,
				'meta'    => self::listing_meta( $post, $locale ),
				'topics'  => self::record_topics( $post ),
			);
		}
		$paths = array(
			'projects'       => self::archive_path( 'lps_project', $locale ),
			'publications'   => self::archive_path( 'lps_publication', $locale ),
			'infrastructure' => PublicRoutes::archive_path( 'lps_infrastructure', $locale ),
			'contact'        => TrustRoutes::page_path( 'contact', $locale ),
		);
		$kind  = self::listing_kind( $post_type );
		if ( 'research' === $kind ) {
			return DiscoverySurfaces::render_research_landing( $items, self::project_items( $locale, 3 ), $locale, $paths );
		}
		if ( 'projects' === $kind ) {
			return DiscoverySurfaces::render_projects_landing( $items, $locale, $paths );
		}
		if ( 'publications' === $kind ) {
			return DiscoverySurfaces::render_publications_landing( $items, $locale, $paths );
		}
		$paged = $query->get( 'paged' );
		return DiscoverySurfaces::render_listing(
			$kind,
			$items,
			self::filter_specs( $post_type, $locale ),
			array(
				'current'  => is_numeric( $paged ) && 0 < (int) $paged ? (int) $paged : 1,
				'total'    => max( 1, (int) $query->max_num_pages ),
				'base_url' => self::archive_path( $post_type, $locale ),
			),
			$locale
		);
	}

	/**
	 * Returns published project rows for the research landing's selected band.
	 *
	 * @param string $locale Supported locale slug.
	 * @param int    $limit  Maximum number of rows.
	 * @return array<int, array<string, mixed>>
	 */
	private static function project_items( string $locale, int $limit ): array {
		$posts = get_posts(
			array(
				'post_type'        => 'lps_project',
				'post_status'      => 'publish',
				'numberposts'      => $limit,
				'orderby'          => 'date',
				'order'            => 'DESC',
				'suppress_filters' => false,
				'meta_query'       => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- locale is the routing key of this surface.
					array(
						'key'     => '_lps_locale',
						'value'   => $locale,
						'compare' => '=',
					),
				),
			)
		);
		$items = array();
		foreach ( $posts as $post ) {
			$items[] = array(
				'title'   => self::display_title( $post ),
				'url'     => self::single_path( 'lps_project', $locale, $post->post_name ),
				'summary' => $post->post_excerpt,
				'meta'    => self::listing_meta( $post, $locale ),
				'topics'  => self::record_topics( $post ),
			);
		}
		return $items;
	}

	/**
	 * Returns the stored topic tokens of one record.
	 *
	 * @param WP_Post $post Record post.
	 * @return array<int, string>
	 */
	private static function record_topics( WP_Post $post ): array {
		$topics = get_post_meta( $post->ID, '_lps_topics', true );
		if ( ! is_array( $topics ) ) {
			return array();
		}
		$clean = array();
		foreach ( $topics as $topic ) {
			$topic = is_scalar( $topic ) ? trim( (string) $topic ) : '';
			if ( '' !== $topic ) {
				$clean[] = $topic;
			}
		}
		return $clean;
	}

	/**
	 * Returns the listing kind label key for a record type.
	 *
	 * @param string $post_type Discovery record type.
	 */
	private static function listing_kind( string $post_type ): string {
		if ( 'lps_publication' === $post_type ) {
			return 'publications';
		}
		return 'lps_research_area' === $post_type ? 'research' : 'projects';
	}

	/**
	 * Returns the GET filter keys each discovery listing exposes.
	 *
	 * Keys are a subset of the approved facet contract (`SearchPolicy::FACETS`):
	 * only fields backed by authority-owned metadata or the controlled taxonomy
	 * cache are listed, so every rendered control actually filters. Relationship
	 * facets (person, project) stay search-only because a listing visitor cannot
	 * be expected to type a record slug.
	 *
	 * @param string $post_type Discovery record type.
	 * @return array<int, string>
	 */
	private static function filter_fields( string $post_type ): array {
		return match ( $post_type ) {
			'lps_publication' => array( 'q', 'year', 'type', 'area' ),
			'lps_project'     => array( 'q', 'status', 'area', 'domain' ),
			default           => array( 'q' ),
		};
	}

	/**
	 * Returns the sanitized active filters of the current request.
	 *
	 * Values are validated at the boundary: `q` is a bounded search term, `year`
	 * is a four-digit shape, and closed-vocabulary filters accept only approved
	 * keys. Anything else is dropped instead of reaching a query.
	 *
	 * @param string $post_type Discovery record type.
	 * @return array<string, string>
	 */
	public static function active_filters( string $post_type ): array {
		$active = array();
		foreach ( self::filter_fields( $post_type ) as $key ) {
			$value = self::request_value( $key );
			if ( '' === $value ) {
				continue;
			}
			if ( 'q' === $key ) {
				$active['q'] = mb_substr( trim( $value ), 0, 100 );
				continue;
			}
			if ( 'year' === $key ) {
				if ( 1 === preg_match( '/^\d{4}$/', $value ) ) {
					$active['year'] = $value;
				}
				continue;
			}
			if ( 'type' === $key ) {
				if ( 1 === preg_match( '/^[a-z0-9][a-z0-9-]{0,63}$/', $value ) ) {
					$active['type'] = $value;
				}
				continue;
			}
			$options = DiscoverySurfaces::filter_options( $key, 'pt-br' );
			if ( isset( $options[ $value ] ) ) {
				$active[ $key ] = $value;
			}
		}
		return $active;
	}

	/**
	 * Returns the filter field specifications the listing form renders.
	 *
	 * Closed vocabularies become localized selects; open vocabularies stay text
	 * inputs, except publication type, whose options are the distinct values
	 * already present in the corpus.
	 *
	 * @param string $post_type Discovery record type.
	 * @param string $locale    Supported locale slug.
	 * @return array<string, mixed>
	 */
	private static function filter_specs( string $post_type, string $locale ): array {
		$active = self::active_filters( $post_type );
		$specs  = array();
		foreach ( self::filter_fields( $post_type ) as $key ) {
			$options = DiscoverySurfaces::filter_options( $key, $locale );
			if ( 'type' === $key ) {
				$corpus  = self::publication_type_options();
				$options = array() === $corpus ? array() : (array) array_combine( $corpus, $corpus );
			}
			$specs[ $key ] = array() !== $options
				? array(
					'value'   => $active[ $key ] ?? '',
					'options' => $options,
				)
				: ( $active[ $key ] ?? '' );
		}
		return $specs;
	}

	/**
	 * Applies the sanitized GET filters to the main discovery archive query.
	 *
	 * `q` maps to the native search clause, which is locale-isolated by the
	 * route's own language constraint and accent-insensitive under the
	 * case-insensitive index collation. Facet filters resolve against the
	 * Portuguese authority records — shared metadata and the taxonomy cache
	 * live there — and the matching IDs are mapped back to the route locale.
	 *
	 * @param WP_Query $query Main archive query.
	 */
	public static function filter_archive_query( WP_Query $query ): void {
		if ( ( function_exists( 'is_admin' ) && is_admin() ) || ! $query->is_main_query() || $query->is_singular() ) {
			return;
		}
		$post_type = $query->get( 'post_type' );
		$post_type = is_string( $post_type ) ? $post_type : '';
		if ( ! isset( self::SEGMENTS[ $post_type ] ) ) {
			return;
		}
		$locale = $query->get( self::LOCALE_QUERY_VAR );
		$locale = 'en' === $locale ? 'en' : 'pt-br';
		$active = self::active_filters( $post_type );
		if ( isset( $active['q'] ) ) {
			$query->set( 's', $active['q'] );
		}
		// `year` is a core date-archive query var; the publication filter owns the
		// parameter here, so the core date clause and date flags are neutralized.
		$query->set( 'year', 0 );
		$query->is_date = false;
		$query->is_year = false;
		$ids            = self::filtered_variant_ids( $post_type, $locale, $active );
		if ( null !== $ids ) {
			$query->set( 'post__in', $ids );
		}
	}

	/**
	 * Resolves facet filters to the IDs of the records visible in one locale.
	 *
	 * Shared metadata (`_lps_publication_date`, `_lps_publication_type`,
	 * `_lps_project_status`) and the controlled-taxonomy cache are written only
	 * on the Portuguese authority record, so the facet query runs against
	 * authority posts and each hit is mapped to its variant in the route locale.
	 * A filter with no matches yields `array( 0 )`, the honest empty set.
	 *
	 * @param string                $post_type Discovery record type.
	 * @param string                $locale    Supported locale slug.
	 * @param array<string, string> $active    Sanitized active filters.
	 * @return array<int, int>|null Variant IDs, or null when no facet applies.
	 */
	private static function filtered_variant_ids( string $post_type, string $locale, array $active ): ?array {
		$meta_query = array(
			'relation' => 'AND',
			array(
				'key'     => '_lps_locale',
				'value'   => 'pt-br',
				'compare' => '=',
			),
		);
		$tax_query  = array( 'relation' => 'AND' );
		$needed     = false;
		if ( 'lps_publication' === $post_type ) {
			if ( isset( $active['year'] ) ) {
				$meta_query[] = array(
					'key'     => '_lps_publication_date',
					'value'   => '^' . $active['year'],
					'compare' => 'REGEXP',
				);
				$needed       = true;
			}
			if ( isset( $active['type'] ) ) {
				$meta_query[] = array(
					'key'     => '_lps_publication_type',
					'value'   => $active['type'],
					'compare' => '=',
				);
				$needed       = true;
			}
			if ( isset( $active['area'] ) ) {
				$tax_query[] = array(
					'taxonomy' => 'lps_research_area_key',
					'field'    => 'slug',
					'terms'    => array( $active['area'] ),
				);
				$needed      = true;
			}
		}
		if ( 'lps_project' === $post_type ) {
			if ( isset( $active['status'] ) ) {
				$meta_query[] = array(
					'key'     => '_lps_project_status',
					'value'   => $active['status'],
					'compare' => '=',
				);
				$needed       = true;
			}
			if ( isset( $active['area'] ) ) {
				$tax_query[] = array(
					'taxonomy' => 'lps_research_area_key',
					'field'    => 'slug',
					'terms'    => array( $active['area'] ),
				);
				$needed      = true;
			}
			if ( isset( $active['domain'] ) ) {
				$tax_query[] = array(
					'taxonomy' => 'lps_application_domain',
					'field'    => 'slug',
					'terms'    => array( $active['domain'] ),
				);
				$needed      = true;
			}
		}
		if ( ! $needed ) {
			return null;
		}
		$args = array(
			'post_type'              => $post_type,
			'post_status'            => 'publish',
			'fields'                 => 'ids',
			'posts_per_page'         => -1, // phpcs:ignore WordPress.WP.PostsPerPage.posts_per_page_posts_per_page -- facet resolution must see the full authority set; the outer archive query still paginates.
			'no_found_rows'          => true,
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
			'meta_query'             => $meta_query, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- authority-owned facet fields are the routing keys of this surface.
		);
		if ( count( $tax_query ) > 1 ) {
			$args['tax_query'] = $tax_query; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- controlled taxonomy cache is the approved facet source.
		}
		$authority = new WP_Query( $args );
		$ids       = array();
		/** `fields => 'ids'` makes WP_Query return raw IDs; the WP_Post branch keeps
		 * the loop safe if a filter ever swaps the shape back to objects.
		 *
		 * @var array<int, int|WP_Post> $authority_posts
		 */
		$authority_posts = $authority->posts;
		foreach ( $authority_posts as $authority_post ) {
			$authority_id = $authority_post instanceof WP_Post ? $authority_post->ID : (int) $authority_post;
			if ( 0 === $authority_id ) {
				continue;
			}
			$variants = class_exists( Translations::class ) ? Translations::variants( $authority_id ) : array();
			$ids[]    = isset( $variants[ $locale ] ) ? (int) $variants[ $locale ] : $authority_id;
		}
		return array() === $ids ? array( 0 ) : array_values( array_unique( $ids ) );
	}

	/**
	 * Returns the distinct publication types present in the corpus.
	 *
	 * The `publication-type` facet is an open vocabulary, so the select lists
	 * the values editors actually recorded on authority records instead of an
	 * invented taxonomy.
	 *
	 * @return array<int, string>
	 */
	private static function publication_type_options(): array {
		global $wpdb;
		if ( ! $wpdb instanceof wpdb ) {
			return array();
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- read-only distinct list of an open facet vocabulary.
		$values  = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT meta_value FROM %i WHERE meta_key = %s AND meta_value != '' ORDER BY meta_value ASC",
				$wpdb->postmeta,
				'_lps_publication_type'
			)
		);
		$options = array();
		foreach ( $values as $value ) {
			$value = is_string( $value ) ? trim( $value ) : '';
			if ( '' !== $value && 1 === preg_match( '/^[a-z0-9][a-z0-9-]{0,63}$/', $value ) ) {
				$options[] = $value;
			}
		}
		return $options;
	}

	/**
	 * Returns the honest listing meta line for one record.
	 *
	 * @param WP_Post $post   Listed record.
	 * @param string  $locale Supported locale slug.
	 */
	private static function listing_meta( WP_Post $post, string $locale ): string {
		if ( 'lps_publication' === $post->post_type ) {
			return DiscoverySurfaces::format_date(
				self::shared_meta( $post, '_lps_publication_date' ),
				self::date_precision( $post ),
				$locale
			);
		}
		if ( 'lps_project' === $post->post_type ) {
			return DiscoverySurfaces::project_status_label( self::shared_meta( $post, '_lps_project_status' ), $locale );
		}
		return '';
	}

	/**
	 * Returns the recorded date precision for a publication.
	 *
	 * @param WP_Post $post Publication record.
	 */
	private static function date_precision( WP_Post $post ): string {
		$precision = self::shared_meta( $post, '_lps_date_precision' );
		return '' === $precision ? 'unknown' : $precision;
	}

	/**
	 * Builds the publication record consumed by the renderer.
	 *
	 * @param WP_Post $post   Publication record.
	 * @param string  $locale Supported locale slug.
	 * @return array<string, mixed>
	 */
	private static function publication_record( WP_Post $post, string $locale ): array {
		return array(
			'id'              => $post->ID,
			'title'           => self::display_title( $post ),
			'summary'         => $post->post_excerpt,
			'abstract'        => $post->post_content,
			'date'            => self::shared_meta( $post, '_lps_publication_date' ),
			'date_precision'  => self::date_precision( $post ),
			'doi'             => self::shared_meta( $post, '_lps_doi' ),
			'venue'           => self::shared_meta( $post, '_lps_venue' ),
			'type'            => self::shared_meta( $post, '_lps_publication_type' ),
			'canonical_url'   => self::shared_meta( $post, '_lps_canonical_url' ),
			'open_access_url' => self::shared_meta( $post, '_lps_open_access_url' ),
			'pdf_url'         => self::shared_meta( $post, '_lps_pdf_url' ),
			'record_url'      => self::single_path( 'lps_publication', $locale, $post->post_name ),
			'code_url'        => self::shared_meta( $post, '_lps_code_url' ),
			'data_url'        => self::shared_meta( $post, '_lps_data_url' ),
			'locale'          => $locale,
		);
	}

	/**
	 * Returns ordered publication authors with person links.
	 *
	 * @param WP_Post $post   Publication record.
	 * @param string  $locale Supported locale slug.
	 * @return array<int, array<string, mixed>>
	 */
	private static function publication_authors( WP_Post $post, string $locale ): array {
		if ( ! class_exists( Relationships::class ) ) {
			return array();
		}
		$authors = array();
		foreach ( Relationships::authors_for_publication( self::authority_id( $post ) ) as $row ) {
			$person    = self::localized_post( $row['author_post_id'], $locale );
			$authors[] = array(
				'name'  => $person instanceof WP_Post ? $person->post_title : $row['display_name'],
				'url'   => $person instanceof WP_Post ? self::person_path( $locale, $person->post_name ) : '',
				'orcid' => $person instanceof WP_Post ? self::meta_string( $person->ID, '_lps_orcid' ) : $row['orcid'],
			);
		}
		return $authors;
	}

	/**
	 * Returns reverse relationships shown on a publication.
	 *
	 * @param WP_Post $post   Publication record.
	 * @param string  $locale Supported locale slug.
	 * @return array<int, array<string, mixed>>
	 */
	private static function publication_relations( WP_Post $post, string $locale ): array {
		if ( ! class_exists( Relationships::class ) ) {
			return array();
		}
		$relations = array();
		foreach ( Relationships::for_source( self::authority_id( $post ), 'publication_project' ) as $row ) {
			$project = self::localized_post( $row['target_post_id'], $locale );
			if ( ! $project instanceof WP_Post ) {
				continue;
			}
			$relations[] = array(
				'title' => $project->post_title,
				'url'   => self::single_path( 'lps_project', $locale, $project->post_name ),
				'role'  => $row['relationship_role'],
			);
		}
		return $relations;
	}

	/**
	 * Returns derived project relationships.
	 *
	 * @param WP_Post $post   Project record.
	 * @param string  $locale Supported locale slug.
	 * @return array<string, array<int, array<string, mixed>>>
	 */
	private static function project_relationships( WP_Post $post, string $locale ): array {
		if ( ! class_exists( Relationships::class ) ) {
			return array();
		}
		$members = array();
		foreach ( Relationships::for_source( self::authority_id( $post ), 'project_member' ) as $row ) {
			$person = self::localized_post( $row['target_post_id'], $locale );
			if ( ! $person instanceof WP_Post ) {
				continue;
			}
			$archived  = 'lps_archived' === $person->post_status || 'archived' === self::meta_string( $person->ID, '_lps_state' );
			$members[] = array(
				'name'     => $person->post_title,
				'url'      => $archived ? '' : self::person_path( $locale, $person->post_name ),
				'archived' => $archived,
			);
		}
		$publications = array();
		foreach ( Relationships::reverse_for( self::authority_id( $post ), 'publication_project' ) as $row ) {
			$publication = self::localized_post( $row['source_post_id'], $locale );
			if ( ! $publication instanceof WP_Post ) {
				continue;
			}
			$publications[] = array(
				'title' => self::display_title( $publication ),
				'url'   => self::single_path( 'lps_publication', $locale, $publication->post_name ),
			);
		}
		$funders = array();
		foreach ( Relationships::for_source( self::authority_id( $post ), 'project_funder' ) as $row ) {
			$funder = self::localized_post( $row['target_post_id'], $locale );
			if ( ! $funder instanceof WP_Post ) {
				continue;
			}
			$funders[] = array(
				'name' => $funder->post_title,
				'url'  => self::meta_string( $funder->ID, '_lps_canonical_url' ),
			);
		}
		return array(
			'members'      => $members,
			'funders'      => $funders,
			'publications' => $publications,
		);
	}

	/**
	 * Returns published projects assigned to one research area.
	 *
	 * @param WP_Post $post   Research area record.
	 * @param string  $locale Supported locale slug.
	 * @return array<int, array<string, mixed>>
	 */
	private static function area_projects( WP_Post $post, string $locale ): array {
		if ( ! class_exists( Relationships::class ) ) {
			return array();
		}
		$projects = array();
		foreach ( Relationships::reverse_for( self::authority_id( $post ), 'research_area' ) as $row ) {
			$source = self::localized_post( $row['source_post_id'], $locale );
			if ( ! $source instanceof WP_Post || 'lps_project' !== $source->post_type || 'publish' !== $source->post_status ) {
				continue;
			}
			$projects[] = array(
				'title'   => $source->post_title,
				'url'     => self::single_path( 'lps_project', $locale, $source->post_name ),
				'summary' => $source->post_excerpt,
			);
		}
		return $projects;
	}

	/**
	 * Returns the variant of a related record that belongs to the current locale.
	 *
	 * @param int    $post_id Related record database ID.
	 * @param string $locale  Supported locale slug.
	 */
	private static function localized_post( int $post_id, string $locale ): ?WP_Post {
		$post = self::post( $post_id );
		if ( ! $post instanceof WP_Post || ! class_exists( Translations::class ) ) {
			return $post;
		}
		$variants = Translations::variants( $post->ID );
		$variant  = isset( $variants[ $locale ] ) ? self::post( $variants[ $locale ] ) : null;
		return $variant instanceof WP_Post ? $variant : $post;
	}

	/**
	 * Returns one record, or null when the ID does not resolve to a record.
	 *
	 * @param int $post_id Record database ID.
	 */
	private static function post( int $post_id ): ?WP_Post {
		if ( 0 >= $post_id ) {
			return null;
		}
		$post = get_post( $post_id );
		return $post instanceof WP_Post ? $post : null;
	}

	/**
	 * Reads one metadata value as a string.
	 *
	 * @param int    $post_id Record database ID.
	 * @param string $key     Metadata key.
	 */
	private static function meta_string( int $post_id, string $key ): string {
		$value = get_post_meta( $post_id, $key, true );
		return is_scalar( $value ) ? (string) $value : '';
	}

	/**
	 * Returns the database ID of the Portuguese authority record.
	 *
	 * Identifiers, dates, and relationships are owned by the Portuguese record;
	 * an English variant reads them from its source instead of restating them.
	 *
	 * @param WP_Post $post Record.
	 */
	private static function authority_id( WP_Post $post ): int {
		if ( ! class_exists( Translations::class ) ) {
			return $post->ID;
		}
		$source = Translations::source_id( $post->ID );
		return null === $source ? $post->ID : $source;
	}

	/**
	 * Reads one Portuguese-authoritative metadata value.
	 *
	 * @param WP_Post $post Record.
	 * @param string  $key  Metadata key.
	 */
	private static function shared_meta( WP_Post $post, string $key ): string {
		$value = self::meta_string( $post->ID, $key );
		if ( '' !== $value ) {
			return $value;
		}
		$authority = self::authority_id( $post );
		return $authority === $post->ID ? '' : self::meta_string( $authority, $key );
	}

	/**
	 * Returns the person profile path for one locale.
	 *
	 * @param string $locale Supported locale slug.
	 * @param string $slug   Person slug.
	 */
	private static function person_path( string $locale, string $slug ): string {
		return ( 'en' === $locale ? '/en/people/' : '/pt-br/pessoas/' ) . $slug . '/';
	}

	/**
	 * Returns the authoritative title when the record carries one.
	 *
	 * @param WP_Post $post Record.
	 */
	private static function display_title( WP_Post $post ): string {
		$authoritative = self::meta_string( $post->ID, '_lps_authoritative_title' );
		return '' === trim( $authoritative ) ? $post->post_title : $authoritative;
	}

	/** Returns the locale for the current request. */
	private static function current_locale(): string {
		$locale = get_query_var( self::LOCALE_QUERY_VAR );
		if ( 'pt-br' === $locale || 'en' === $locale ) {
			return $locale;
		}
		$post = get_queried_object();
		return $post instanceof WP_Post ? self::locale_for_post( $post ) : 'pt-br';
	}

	/**
	 * Returns the recorded locale of a record.
	 *
	 * @param WP_Post $post Record.
	 */
	private static function locale_for_post( WP_Post $post ): string {
		$locale = self::meta_string( $post->ID, '_lps_locale' );
		return 'en' === $locale ? 'en' : 'pt-br';
	}

	/** Returns the sanitized path of the current request. */
	private static function request_path(): string {
		if ( ! isset( $_SERVER['REQUEST_URI'] ) || ! is_string( $_SERVER['REQUEST_URI'] ) ) {
			return '/';
		}
		$path = wp_parse_url( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ), PHP_URL_PATH );
		return is_string( $path ) ? $path : '/';
	}

	/** Returns the publication addressed by the current citation request. */
	private static function requested_publication(): ?WP_Post {
		$queried = get_queried_object();
		if ( $queried instanceof WP_Post && 'lps_publication' === $queried->post_type ) {
			return $queried;
		}
		$id = self::request_value( 'id' );
		if ( '' === $id || ! ctype_digit( $id ) ) {
			return null;
		}
		$post = self::post( (int) $id );
		return $post instanceof WP_Post && 'lps_publication' === $post->post_type ? $post : null;
	}

	/**
	 * Returns one sanitized read-only request value.
	 *
	 * @param string $key Query argument name.
	 */
	private static function request_value( string $key ): string {
		$value = get_query_var( $key );
		if ( is_string( $value ) && '' !== $value ) {
			return sanitize_text_field( $value );
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only public download selector.
		if ( ! isset( $_GET[ $key ] ) || ! is_string( $_GET[ $key ] ) ) {
			return '';
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only public download selector.
		return sanitize_text_field( wp_unslash( $_GET[ $key ] ) );
	}
}
