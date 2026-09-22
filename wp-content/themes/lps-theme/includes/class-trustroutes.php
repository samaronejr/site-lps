<?php
/**
 * Locale routes for opportunity, event, news, and institutional surfaces.
 *
 * @package LPS\Theme
 */

declare(strict_types=1);

namespace LPS\Theme;

use DateTimeImmutable;
use LPS\ContentModel\Policy;
use LPS\ContentModel\TranslationPolicy;
use LPS\ContentModel\Translations;
use LPS\ContentModel\TrustSurfacePolicy;
use WP_Post;

require_once __DIR__ . '/class-trustsurfaces.php';

/** Binds trust surfaces to their frozen locale routes. */
final class TrustRoutes {
	public const LOCALE_QUERY_VAR = 'lps_trust_locale';

	/**
	 * Frozen locale path segment for each trust record type.
	 *
	 * @var array<string, array<string, string>>
	 */
	private const SEGMENTS = array(
		'lps_opportunity' => array(
			'pt-br' => 'oportunidades',
			'en'    => 'opportunities',
		),
		'lps_event'       => array(
			'pt-br' => 'eventos',
			'en'    => 'events',
		),
		'lps_news'        => array(
			'pt-br' => 'noticias',
			'en'    => 'news',
		),
	);

	/**
	 * Frozen locale path for each institutional page key.
	 *
	 * @var array<string, array<string, string>>
	 */
	private const PAGES = array(
		'about'           => array(
			'pt-br' => '/pt-br/sobre/',
			'en'    => '/en/about/',
		),
		'history'         => array(
			'pt-br' => '/pt-br/sobre/historia/',
			'en'    => '/en/about/history/',
		),
		'governance'      => array(
			'pt-br' => '/pt-br/sobre/governanca/',
			'en'    => '/en/about/governance/',
		),
		'collaboration'   => array(
			'pt-br' => '/pt-br/colabore/',
			'en'    => '/en/collaborate/',
		),
		'contact'         => array(
			'pt-br' => '/pt-br/contato/',
			'en'    => '/en/contact/',
		),
		'privacy'         => array(
			'pt-br' => '/pt-br/privacidade/',
			'en'    => '/en/privacy/',
		),
		'accessibility'   => array(
			'pt-br' => '/pt-br/acessibilidade/',
			'en'    => '/en/accessibility/',
		),
		'visual-identity' => array(
			'pt-br' => '/pt-br/identidade-visual/',
			'en'    => '/en/visual-identity/',
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
	 * Returns the localized archive path for a trust record type.
	 *
	 * @param string $post_type Trust record type.
	 * @param string $locale    Supported locale slug.
	 */
	public static function archive_path( string $post_type, string $locale ): string {
		$segment = self::SEGMENTS[ $post_type ][ $locale ] ?? '';
		return '' === $segment ? '' : '/' . $locale . '/' . $segment . '/';
	}

	/**
	 * Returns the localized single-record path.
	 *
	 * @param string $post_type Trust record type.
	 * @param string $locale    Supported locale slug.
	 * @param string $slug      Record slug.
	 */
	public static function single_path( string $post_type, string $locale, string $slug ): string {
		$archive = self::archive_path( $post_type, $locale );
		return '' === $archive || '' === $slug ? $archive : $archive . $slug . '/';
	}

	/**
	 * Returns the localized institutional page path.
	 *
	 * @param string $key    Institutional page key.
	 * @param string $locale Supported locale slug.
	 */
	public static function page_path( string $key, string $locale ): string {
		return self::PAGES[ $key ][ $locale ] ?? '';
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
	 * Returns WordPress rewrite rules for every trust route.
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
	 * Returns the BCP47 language tag for a supported locale slug.
	 *
	 * @param string $locale Supported locale slug.
	 */
	public static function bcp47( string $locale ): string {
		return self::LANGUAGE_TAGS[ $locale ] ?? self::LANGUAGE_TAGS['pt-br'];
	}

	/**
	 * Returns the robots directive for a trust record, or an empty string.
	 *
	 * Only opportunities age out of the index, and only after the closing date
	 * passes the retention window; the record itself stays published.
	 *
	 * @param string            $post_type Trust record type.
	 * @param string            $closes_at Application closing timestamp.
	 * @param DateTimeImmutable $now       Evaluation instant.
	 */
	public static function robots_directive( string $post_type, string $closes_at, DateTimeImmutable $now ): string {
		if ( 'lps_opportunity' !== $post_type ) {
			return '';
		}
		return TrustSurfacePolicy::opportunity_is_noindex( $closes_at, $now ) ? 'noindex, follow' : '';
	}

	/** Registers trust routes, query variables, and rendering hooks. */
	public static function boot(): void {
		add_filter( 'rewrite_rules_array', array( self::class, 'register_routes' ), 999 );
		add_filter( 'query_vars', array( self::class, 'register_query_vars' ) );
		add_filter( 'redirect_canonical', array( self::class, 'keep_locale_route' ), 10, 2 );
		add_filter( 'wp_robots', array( self::class, 'filter_robots' ) );
		add_filter( 'language_attributes', array( self::class, 'route_language_attributes' ), 200 );
		add_filter( 'the_content', array( self::class, 'suppress_institutional_content' ), 1 );
	}

	/**
	 * Drops stored body copy on institutional pages.
	 *
	 * The trust block renders the page composition, so paragraphs imported
	 * with the record must not print above it. Suppressing the content early
	 * keeps the record editable in wp-admin while the renderer stays the
	 * single source of the public body.
	 *
	 * @param string $content Rendered post content.
	 */
	public static function suppress_institutional_content( string $content ): string {
		if ( ! is_page() ) {
			return $content;
		}
		$post = get_post();
		if ( ! $post instanceof WP_Post || 'page' !== $post->post_type ) {
			return $content;
		}
		$shared    = class_exists( TranslationPolicy::class ) ? TranslationPolicy::shared_meta_keys( $post->post_type ) : array();
		$source_id = class_exists( Translations::class ) ? Translations::source_id( $post->ID ) ?? $post->ID : $post->ID;
		$key       = Policy::scalar_string( get_post_meta( in_array( '_lps_page_key', $shared, true ) ? $source_id : $post->ID, '_lps_page_key', true ) );
		return isset( self::PAGES[ $key ] ) ? '' : $content;
	}

	/**
	 * Keeps trust locale routes from being canonicalized away.
	 *
	 * @param string|false $redirect_url  Proposed redirect target.
	 * @param string       $requested_url Originally requested URL.
	 */
	public static function keep_locale_route( string|false $redirect_url, string $requested_url ): string|false {
		$path = wp_parse_url( $requested_url, PHP_URL_PATH );
		$path = is_string( $path ) ? $path : '';
		return null === self::match_path( $path ) ? $redirect_url : false;
	}

	/**
	 * Adds the noindex directive for an aged closed opportunity.
	 *
	 * WordPress owns the single robots meta tag, so the directive is merged into
	 * the core directive list instead of emitting a second tag.
	 *
	 * @param array<string, mixed> $robots Current robots directives.
	 * @return array<string, mixed>
	 */
	public static function filter_robots( array $robots ): array {
		if ( ! is_singular( 'lps_opportunity' ) ) {
			return $robots;
		}
		$post = get_queried_object();
		if ( ! $post instanceof WP_Post ) {
			return $robots;
		}
		$shared    = TranslationPolicy::shared_meta_keys( 'lps_opportunity' );
		$source_id = in_array( '_lps_closes_at', $shared, true )
			? ( Translations::source_id( $post->ID ) ?? $post->ID )
			: $post->ID;
		$directive = self::robots_directive(
			'lps_opportunity',
			Policy::scalar_string( get_post_meta( $source_id, '_lps_closes_at', true ) ),
			self::now()
		);
		if ( '' === $directive ) {
			return $robots;
		}
		$robots['noindex'] = true;
		$robots['follow']  = true;
		unset( $robots['index'] );
		return $robots;
	}

	/**
	 * Replaces the document language tag on trust routes with the route locale.
	 *
	 * @param string $output Rendered language attributes.
	 */
	public static function route_language_attributes( string $output ): string {
		if ( ! isset( $_SERVER['REQUEST_URI'] ) || ! is_string( $_SERVER['REQUEST_URI'] ) ) {
			return $output;
		}
		$path = wp_parse_url( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ), PHP_URL_PATH );
		return self::language_attributes_for_path( $output, is_string( $path ) ? $path : '' );
	}

	/**
	 * Applies the route locale language tag to rendered attributes.
	 *
	 * @param string $output Rendered language attributes.
	 * @param string $path   Public request path.
	 */
	public static function language_attributes_for_path( string $output, string $path ): string {
		$locale = self::locale_for_path( $path );
		if ( '' === $locale ) {
			return $output;
		}
		$tagged = preg_replace( '/lang="[^"]*"/', 'lang="' . esc_attr( self::bcp47( $locale ) ) . '"', $output );
		return is_string( $tagged ) ? $tagged : $output;
	}

	/**
	 * Returns the locale owning a trust or institutional path, or an empty string.
	 *
	 * @param string $path Public request path.
	 */
	public static function locale_for_path( string $path ): string {
		$matched = self::match_path( $path );
		if ( null !== $matched ) {
			return $matched['locale'];
		}
		$clean = '/' . trim( $path, '/' ) . '/';
		foreach ( self::PAGES as $routes ) {
			foreach ( $routes as $locale => $route ) {
				if ( $route === $clean ) {
					return $locale;
				}
			}
		}
		return '';
	}

	/** Returns the evaluation instant used for every derived state. */
	public static function now(): DateTimeImmutable {
		return new DateTimeImmutable( 'now' );
	}

	/**
	 * Returns the locale slug for the current trust request.
	 */
	public static function current_locale(): string {
		$locale = get_query_var( self::LOCALE_QUERY_VAR );
		if ( is_string( $locale ) && isset( self::LANGUAGE_TAGS[ $locale ] ) ) {
			return $locale;
		}
		if ( ! isset( $_SERVER['REQUEST_URI'] ) || ! is_string( $_SERVER['REQUEST_URI'] ) ) {
			return 'pt-br';
		}
		$path   = wp_parse_url( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ), PHP_URL_PATH );
		$locale = self::locale_for_path( is_string( $path ) ? $path : '' );
		return '' === $locale ? 'pt-br' : $locale;
	}

	/**
	 * Maps a stored record to the trust surface view model.
	 *
	 * @param WP_Post $post Trust record.
	 * @return array<string, mixed>
	 */
	private static function record( WP_Post $post ): array {
		$shared    = TranslationPolicy::shared_meta_keys( $post->post_type );
		$source_id = Translations::source_id( $post->ID ) ?? $post->ID;
		$meta      = static function ( string $key ) use ( $post, $shared, $source_id ): string {
			$id = in_array( $key, $shared, true ) ? $source_id : $post->ID;
			return Policy::scalar_string( get_post_meta( $id, $key, true ) );
		};
		return array(
			'slug'                 => $post->post_name,
			'title'                => get_the_title( $post ),
			'summary'              => $post->post_excerpt,
			'body'                 => $post->post_content,
			'eligibility'          => $meta( '_lps_eligibility' ),
			'instructions'         => $meta( '_lps_application_instructions' ),
			'opens_at'             => $meta( '_lps_opens_at' ),
			'closes_at'            => $meta( '_lps_closes_at' ),
			'contact'              => $meta( '_lps_contact' ),
			'contact_is_role'      => '' !== $meta( '_lps_contact_is_role' ),
			'application_url'      => $meta( '_lps_application_url' ),
			'application_approved' => '' !== $meta( '_lps_application_url_approved' ),
			'starts_at'            => $meta( '_lps_starts_at' ),
			'ends_at'              => $meta( '_lps_ends_at' ),
			'status'               => $meta( '_lps_event_status' ),
			'venue'                => $meta( '_lps_venue' ),
			'date'                 => $meta( '_lps_canonical_date' ),
			'stale'                => self::is_stale_translation( $post ),
		);
	}

	/**
	 * Builds the institutional page view model from stored metadata.
	 *
	 * @param WP_Post $post Institutional page record.
	 * @return array{key: string, title: string, summary: string, affiliation: string, governance: string, location: string, funding: string, report_contact: string, reviewed_at: string, claims: array<int, mixed>, role_contacts: array<int, mixed>, journeys: array<int, mixed>}
	 */
	private static function institutional_page( WP_Post $post ): array {
		$shared    = TranslationPolicy::shared_meta_keys( $post->post_type );
		$source_id = Translations::source_id( $post->ID ) ?? $post->ID;
		$meta      = static function ( string $key ) use ( $post, $shared, $source_id ): string {
			$id = in_array( $key, $shared, true ) ? $source_id : $post->ID;
			return Policy::scalar_string( get_post_meta( $id, $key, true ) );
		};
		$list      = static function ( string $key ) use ( $post ): array {
			$value = get_post_meta( $post->ID, $key, true );
			if ( is_array( $value ) ) {
				return array_values( $value );
			}
			$decoded = is_string( $value ) && '' !== $value ? json_decode( $value, true ) : null;
			return is_array( $decoded ) ? array_values( $decoded ) : array();
		};
		return array(
			'key'            => $meta( '_lps_page_key' ),
			'title'          => get_the_title( $post ),
			'summary'        => $post->post_excerpt,
			'affiliation'    => $meta( '_lps_affiliation' ),
			'governance'     => $meta( '_lps_governance' ),
			'location'       => $meta( '_lps_location' ),
			'funding'        => $meta( '_lps_funding' ),
			'report_contact' => $meta( '_lps_report_contact' ),
			'reviewed_at'    => $meta( '_lps_review_date' ),
			'claims'         => $list( '_lps_claims' ),
			'role_contacts'  => $list( '_lps_role_contacts' ),
			'journeys'       => $list( '_lps_journeys' ),
			'stale'          => self::is_stale_translation( $post ),
		);
	}

	/**
	 * Reports whether an English record trails its reviewed Portuguese source.
	 *
	 * Staleness is a source-hash comparison owned by the translation policy, never
	 * a timestamp guess: the flag is set only for English variants whose reviewed
	 * hash no longer matches the authority record.
	 *
	 * @param WP_Post $post Record.
	 */
	private static function is_stale_translation( WP_Post $post ): bool {
		if ( ! class_exists( Translations::class ) ) {
			return false;
		}
		return 'en' === Translations::locale( $post->ID ) && Translations::is_stale( $post->ID );
	}

	/** Renders the trust surface for the current request. */
	public static function render_block(): string {
		$locale = self::current_locale();
		$now    = self::now();
		if ( is_page() ) {
			$post = get_queried_object();
			if ( ! $post instanceof WP_Post ) {
				return '';
			}
			$page = self::institutional_page( $post );
			if ( '' === self::page_path( $page['key'], $locale ) ) {
				return '';
			}
			$html = TrustSurfaces::render_institutional_page( $page, $locale, $now );
			if ( 'contact' === $page['key'] ) {
				$html .= TrustSurfaces::render_contact_page( $page['role_contacts'], $locale );
			}
			if ( 'collaboration' === $page['key'] ) {
				$html .= TrustSurfaces::render_collaboration_page( $page['journeys'], $locale );
			}
			return $html;
		}
		if ( is_singular( array_keys( self::SEGMENTS ) ) ) {
			$post = get_queried_object();
			if ( ! $post instanceof WP_Post ) {
				return '';
			}
			$record = self::record( $post );
			return match ( $post->post_type ) {
				'lps_opportunity' => TrustSurfaces::render_opportunity( $record, $locale, $now ),
				'lps_event' => TrustSurfaces::render_event( $record, $locale, $now ),
				default => TrustSurfaces::render_news( $record, $locale ),
			};
		}
		$post_type = get_query_var( 'post_type' );
		$post_type = is_string( $post_type ) && isset( self::SEGMENTS[ $post_type ] ) ? $post_type : 'lps_news';
		$records   = array();
		foreach ( self::query_records( $post_type, $locale ) as $post ) {
			$records[] = self::record( $post );
		}
		if ( 'lps_news' === $post_type ) {
			$events = array();
			foreach ( self::query_records( 'lps_event', $locale ) as $post ) {
				$events[] = self::record( $post );
			}
			return TrustSurfaces::render_news_listing( $records, $locale, $events, $now );
		}
		return match ( $post_type ) {
			'lps_opportunity' => TrustSurfaces::render_opportunity_listing( $records, $locale, $now ),
			default => TrustSurfaces::render_event_listing( $records, $locale, $now ),
		};
	}

	/**
	 * Returns published records of one trust type for a locale.
	 *
	 * @param string $post_type Trust record type.
	 * @param string $locale    Supported locale slug.
	 * @return array<int, WP_Post>
	 */
	private static function query_records( string $post_type, string $locale ): array {
		return get_posts(
			array(
				'post_type'        => $post_type,
				'post_status'      => 'publish',
				'numberposts'      => 50,
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
	}

	/**
	 * Prepends trust routes to the WordPress rewrite table.
	 *
	 * @param array<string, string> $rules Existing rewrite rules.
	 * @return array<string, string>
	 */
	public static function register_routes( array $rules ): array {
		return array_merge( self::rewrite_rules(), $rules );
	}

	/**
	 * Registers the trust locale query variable.
	 *
	 * @param array<int, string> $vars Registered query variables.
	 * @return array<int, string>
	 */
	public static function register_query_vars( array $vars ): array {
		$vars[] = self::LOCALE_QUERY_VAR;
		return $vars;
	}
}
