<?php
/**
 * Canonical locale routes for the teaching surfaces.
 *
 * @package LPS\Theme
 */

declare(strict_types=1);

namespace LPS\Theme;

use LPS\ContentModel\Relationships;
use LPS\ContentModel\TaskDashboard;
use LPS\ContentModel\TeachingContracts;
use LPS\ContentModel\TeachingRecords;
use LPS\ContentModel\TeachingResources;
use LPS\ContentModel\Translations;
use WP_Post;
use WP_Query;

require_once __DIR__ . '/class-teachingsurfaces.php';

/**
 * Binds the frozen teaching routes to the canonical domain records.
 *
 * The offering page is canonical: `/pt-br/ensino/disciplinas/{course}/{term-token}/{section}/`
 * and `/en/teaching/courses/{course}/{term-token}/{section}/` resolve through
 * the plugin's identity registries, never through slug guessing. Existing
 * non-teaching URLs are untouched — these rules only prepend new locale
 * prefixes and keep the default post-type permalinks working.
 */
final class TeachingRoutes {
	public const LOCALE_QUERY_VAR  = 'lps_teaching_locale';
	public const COURSE_QUERY_VAR  = 'lps_teaching_course';
	public const TERM_QUERY_VAR    = 'lps_teaching_term';
	public const SECTION_QUERY_VAR = 'lps_teaching_section';

	/**
	 * Frozen locale path segments for the teaching surfaces.
	 *
	 * @var array<string, array{landing: string, courses: string}>
	 */
	private const SEGMENTS = array(
		'pt-br' => array(
			'landing' => 'ensino',
			'courses' => 'disciplinas',
		),
		'en'    => array(
			'landing' => 'teaching',
			'courses' => 'courses',
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
	 * Returns the canonical landing path for one locale.
	 *
	 * @param string $locale Supported locale slug.
	 */
	public static function landing_path( string $locale ): string {
		$segments = self::SEGMENTS[ $locale ] ?? self::SEGMENTS['pt-br'];
		return '/' . $locale . '/' . $segments['landing'] . '/';
	}

	/**
	 * Returns the canonical course path for one locale.
	 *
	 * @param string $locale Supported locale slug.
	 * @param string $slug   Course slug in that locale.
	 */
	public static function course_path( string $locale, string $slug ): string {
		$segments = self::SEGMENTS[ $locale ] ?? self::SEGMENTS['pt-br'];
		$base     = '/' . $locale . '/' . $segments['landing'] . '/' . $segments['courses'] . '/';
		return '' === $slug ? $base : $base . $slug . '/';
	}

	/**
	 * Returns the canonical offering path for one locale.
	 *
	 * @param string $locale      Supported locale slug.
	 * @param string $course_slug Course slug in that locale.
	 * @param string $term_token  Immutable calendar-qualified term token.
	 * @param string $section_key Normalized section key.
	 */
	public static function offering_path( string $locale, string $course_slug, string $term_token, string $section_key ): string {
		$base = self::course_path( $locale, $course_slug );
		return '' === $term_token || '' === $section_key ? $base : $base . $term_token . '/' . $section_key . '/';
	}

	/**
	 * Resolves a public path back to its teaching view.
	 *
	 * @param string $path Public request path.
	 * @return array{view: string, locale: string, course: string, term: string, section: string}|null
	 */
	public static function match_path( string $path ): ?array {
		$clean = '/' . trim( $path, '/' ) . '/';
		if ( 1 !== preg_match( '#^/(pt-br|en)/([^/]+)(?:/([^/]+)(?:/([^/]+)(?:/([^/]+)(?:/([^/]+))?)?)?)?/$#', $clean, $parts ) ) {
			return null;
		}
		$locale   = $parts[1];
		$segments = self::SEGMENTS[ $locale ];
		if ( $segments['landing'] !== $parts[2] ) {
			return null;
		}
		$course_segment = $parts[3] ?? '';
		if ( '' === $course_segment ) {
			return array(
				'view'    => 'landing',
				'locale'  => $locale,
				'course'  => '',
				'term'    => '',
				'section' => '',
			);
		}
		if ( $segments['courses'] !== $course_segment ) {
			return null;
		}
		$slug = $parts[4] ?? '';
		if ( '' === $slug ) {
			return null;
		}
		$term    = $parts[5] ?? '';
		$section = $parts[6] ?? '';
		if ( '' === $term && '' === $section ) {
			return array(
				'view'    => 'course',
				'locale'  => $locale,
				'course'  => $slug,
				'term'    => '',
				'section' => '',
			);
		}
		if ( '' !== $term && '' !== $section ) {
			return array(
				'view'    => 'offering',
				'locale'  => $locale,
				'course'  => $slug,
				'term'    => $term,
				'section' => $section,
			);
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
	 * Returns WordPress rewrite rules for every teaching route.
	 *
	 * The offering rule precedes the course rule so the deeper canonical route
	 * always wins; both resolve through query vars the resolver reads.
	 *
	 * @return array<string, string>
	 */
	public static function rewrite_rules(): array {
		$rules = array();
		foreach ( self::SEGMENTS as $locale => $segments ) {
			$landing = $locale . '/' . $segments['landing'];
			$courses = $landing . '/' . $segments['courses'];
			$suffix  = '&' . self::LOCALE_QUERY_VAR . '=' . $locale . '&lang=' . $locale;

			$rules[ $courses . '/([^/]+)/([^/]+)/([^/]+)/?$' ] = 'index.php?post_type=lps_offering'
				. $suffix
				. '&' . self::COURSE_QUERY_VAR . '=$matches[1]'
				. '&' . self::TERM_QUERY_VAR . '=$matches[2]'
				. '&' . self::SECTION_QUERY_VAR . '=$matches[3]';
			$rules[ $courses . '/([^/]+)/?$' ]                 = 'index.php?post_type=lps_course&name=$matches[1]' . $suffix;
			$rules[ $landing . '/page/([0-9]{1,})/?$' ]        = 'index.php?post_type=lps_course' . $suffix . '&paged=$matches[1]';
			$rules[ $landing . '/?$' ]                         = 'index.php?post_type=lps_course' . $suffix;
		}
		return $rules;
	}

	/**
	 * Replaces the document language tag on teaching routes.
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

	/** Registers routes, query vars, resolution, and the language tag. */
	public static function boot(): void {
		add_filter( 'rewrite_rules_array', array( self::class, 'register_routes' ), 997 );
		add_filter( 'query_vars', array( self::class, 'register_query_vars' ) );
		add_action( 'pre_get_posts', array( self::class, 'resolve_query' ) );
		add_filter( 'redirect_canonical', array( self::class, 'keep_locale_route' ), 10, 2 );
		add_filter( 'pll_check_canonical_url', array( self::class, 'keep_teaching_canonical' ), 10, 2 );
		add_filter( 'language_attributes', array( self::class, 'route_language_attributes' ), 205 );
	}

	/**
	 * Prepends the teaching rewrite rules.
	 *
	 * @param array<string, string> $rules Registered rewrite rules.
	 * @return array<string, string>
	 */
	public static function register_routes( array $rules ): array {
		return array_merge( self::rewrite_rules(), $rules );
	}

	/**
	 * Registers the teaching query vars.
	 *
	 * @param array<int, string> $vars Registered public query vars.
	 * @return array<int, string>
	 */
	public static function register_query_vars( array $vars ): array {
		$vars[] = self::LOCALE_QUERY_VAR;
		$vars[] = self::COURSE_QUERY_VAR;
		$vars[] = self::TERM_QUERY_VAR;
		$vars[] = self::SECTION_QUERY_VAR;
		return $vars;
	}

	/**
	 * Resolves the canonical offering route and constrains course lookups.
	 *
	 * Offering routes resolve through the plugin's identity registries: the
	 * course slug maps to its locale variant, the term token maps through the
	 * term registry, and the section key completes the offering identity. A
	 * route that resolves to no published record becomes a real 404 — never a
	 * guessed or cross-locale record. Course routes pin the slug lookup to the
	 * route locale so identical slugs in the other locale cannot collide.
	 *
	 * @param WP_Query $query Main query.
	 */
	public static function resolve_query( WP_Query $query ): void {
		if ( ( function_exists( 'is_admin' ) && is_admin() ) || ! $query->is_main_query() ) {
			return;
		}
		$locale = $query->get( self::LOCALE_QUERY_VAR );
		if ( 'pt-br' !== $locale && 'en' !== $locale ) {
			// No teaching rewrite rule matched. A path under a teaching prefix
			// that no rule claims is a real 404, never the localized home page:
			// Polylang's `parse_main_query` marks the bare `lang` query as home
			// before `pre_get_posts` runs, so the flags must be forced here.
			if ( self::is_teaching_path( self::request_path() ) ) {
				$query->set( 'post_type', 'lps_offering' );
				$query->set( 'name', 'lps-no-such-offering' );
				$query->is_single            = true;
				$query->is_singular          = true;
				$query->is_archive           = false;
				$query->is_post_type_archive = false;
				$query->is_home              = false;
			}
			return;
		}
		$course_slug = $query->get( self::COURSE_QUERY_VAR );
		$term_token  = $query->get( self::TERM_QUERY_VAR );
		$section     = $query->get( self::SECTION_QUERY_VAR );
		if ( is_string( $course_slug ) && '' !== $course_slug && is_string( $term_token ) && '' !== $term_token && is_string( $section ) && '' !== $section ) {
			$resolved = class_exists( TeachingRecords::class )
				? TeachingRecords::resolve_offering_route( $course_slug, $term_token, $section, $locale )
				: null;
			if ( null !== $resolved ) {
				$query->set( 'p', $resolved['offering_id'] );
				$query->set( 'post_type', 'lps_offering' );
				// `pre_get_posts` runs after `parse_query` computed the conditional
				// flags from the archive-shaped query vars, so the resolved single
				// record must be reflected in the flags directly — otherwise the
				// template hierarchy keeps the archive surface.
				$query->is_single            = true;
				$query->is_singular          = true;
				$query->is_archive           = false;
				$query->is_post_type_archive = false;
				$query->is_home              = false;
				$query->is_404               = false;
				return;
			}
			// An unresolved canonical route is a real 404, never a fallback.
			$query->set( 'post_type', 'lps_offering' );
			$query->set( 'name', 'lps-no-such-offering' );
			$query->is_single            = true;
			$query->is_singular          = true;
			$query->is_archive           = false;
			$query->is_post_type_archive = false;
			$query->is_home              = false;
			return;
		}
		$name = $query->get( 'name' );
		if ( is_string( $name ) && '' !== $name && 'lps_course' === $query->get( 'post_type' ) ) {
			$query->set(
				'meta_query',
				array(
					array(
						'key'   => '_lps_locale',
						'value' => $locale,
					),
				)
			);
		}
	}

	/**
	 * Keeps teaching locale routes stable instead of redirecting to the default permalink.
	 *
	 * @param string|false $redirect_url  Proposed canonical URL.
	 * @param string       $requested_url Requested URL.
	 * @return string|false
	 */
	public static function keep_locale_route( string|false $redirect_url, string $requested_url ): string|false {
		$path = wp_parse_url( $requested_url, PHP_URL_PATH );
		// Canonical guessing must never move a teaching path: complete routes
		// resolve through `resolve_query`, and anything else under the prefix
		// is a real 404, not a similar-permalink guess.
		if ( is_string( $path ) && self::is_teaching_path( $path ) ) {
			return false;
		}
		return $redirect_url;
	}

	/**
	 * Vetoes Polylang's canonical redirect on every teaching path.
	 *
	 * Polylang maps an unresolved locale request to the localized home page;
	 * under a teaching prefix that would turn a real 404 into a misleading
	 * 301. Complete routes already resolve through `resolve_query`, so the
	 * veto only ever changes unresolved paths — and those must stay 404.
	 *
	 * @param string|false $redirect_url Proposed canonical URL.
	 * @param mixed        $language     Detected Polylang language.
	 * @return string|false
	 */
	public static function keep_teaching_canonical( string|false $redirect_url, mixed $language ): string|false {
		unset( $language );
		return self::is_teaching_path( self::request_path() ) ? false : $redirect_url;
	}

	/**
	 * Returns whether a path sits under a teaching landing prefix.
	 *
	 * @param string $path Public request path.
	 */
	private static function is_teaching_path( string $path ): bool {
		foreach ( self::SEGMENTS as $locale => $segments ) {
			if ( str_starts_with( $path, '/' . $locale . '/' . $segments['landing'] . '/' ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Applies the route language tag to the current request.
	 *
	 * @param string $output Rendered language attributes.
	 */
	public static function route_language_attributes( string $output ): string {
		return self::language_attributes_for_path( $output, self::request_path() );
	}

	/** Renders the teaching surface addressed by the current request. */
	public static function render_block(): string {
		$route = self::match_path( self::request_path() );
		if ( null === $route ) {
			// The default post-type permalinks stay live: a non-locale request
			// still renders the same canonical surface instead of a blank page.
			$object = get_queried_object();
			if ( $object instanceof WP_Post && 'lps_course' === $object->post_type ) {
				$locale = self::locale_for_post( $object );
				return TeachingSurfaces::course( self::course_record( $object, $locale ), self::course_offerings( $object, $locale, true ), $locale );
			}
			if ( $object instanceof WP_Post && 'lps_offering' === $object->post_type ) {
				$locale = self::locale_for_post( $object );
				return TeachingSurfaces::offering( self::offering_record( $object, $locale, true ), $locale );
			}
			$query = $GLOBALS['wp_query'] ?? null;
			if ( $query instanceof WP_Query && 'lps_course' === $query->get( 'post_type' ) && $query->is_archive() ) {
				return TeachingSurfaces::landing( self::courses( 'pt-br' ), 'pt-br' );
			}
			return '';
		}
		$locale = $route['locale'];
		if ( 'landing' === $route['view'] ) {
			return TeachingSurfaces::landing( self::courses( $locale ), $locale );
		}
		if ( 'course' === $route['view'] ) {
			$course = get_queried_object();
			return $course instanceof WP_Post
				? TeachingSurfaces::course( self::course_record( $course, $locale ), self::course_offerings( $course, $locale, true ), $locale )
				: '';
		}
		$offering = get_queried_object();
		if ( ! $offering instanceof WP_Post ) {
			return '';
		}
		$record = self::offering_record( $offering, $locale, true );
		// Sibling offerings power the term-switch navigation so a student can
		// move between the live section and the completed-term record.
		$course_id = 0;
		if ( class_exists( TeachingRecords::class ) ) {
			$identity  = TeachingRecords::offering_identity_for( self::authority_id( $offering ) );
			$course_id = null !== $identity ? (int) $identity['course_id'] : 0;
		}
		$course_authority = 0 < $course_id ? get_post( $course_id ) : null;
		if ( $course_authority instanceof WP_Post ) {
			$record['siblings'] = self::course_offerings( $course_authority, $locale );
		}
		return TeachingSurfaces::offering( $record, $locale );
	}

	/**
	 * Returns the published courses of one locale as listing records.
	 *
	 * @param string $locale Supported locale slug.
	 * @return array<int, array<string, mixed>>
	 */
	private static function courses( string $locale ): array {
		$query   = new WP_Query(
			array(
				'post_type'              => 'lps_course',
				'post_status'            => 'publish',
				// phpcs:ignore WordPress.WP.PostsPerPage.posts_per_page_posts_per_page -- Bounded catalogue listing, capped for the institutional site.
				'posts_per_page'         => 200,
				'orderby'                => 'title',
				'order'                  => 'ASC',
				'no_found_rows'          => true,
				'update_post_term_cache' => false,
				'meta_query'             => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- locale is the routing key of this surface.
					array(
						'key'   => '_lps_locale',
						'value' => $locale,
					),
				),
			)
		);
		$courses = array();
		foreach ( $query->posts as $post ) {
			if ( $post instanceof WP_Post && 'published' === self::meta_string( $post->ID, '_lps_state' ) ) {
				$record = self::course_record( $post, $locale );
				// The landing row links straight into the live section so a
				// student reaches current materials in one hop.
				foreach ( self::course_offerings( $post, $locale ) as $offering ) {
					if ( 'current' === self::text( $offering['temporal_status'] ?? '' ) ) {
						$record['current_offering'] = $offering;
						break;
					}
				}
				$courses[] = $record;
			}
		}
		return $courses;
	}

	/**
	 * Builds the publishable course record for the renderer.
	 *
	 * @param WP_Post $post   Course record.
	 * @param string  $locale Supported locale slug.
	 * @return array<string, mixed>
	 */
	private static function course_record( WP_Post $post, string $locale ): array {
		$authority = self::authority_id( $post );
		return array(
			'title'         => $post->post_title,
			'summary'       => $post->post_excerpt,
			'body'          => $post->post_content,
			'url'           => self::course_path( $locale, $post->post_name ),
			'code'          => self::meta_string( $authority, '_lps_course_code' ),
			'level'         => self::meta_string( $authority, '_lps_course_level' ),
			'program'       => self::meta_string( $authority, '_lps_program' ),
			'prerequisites' => self::meta_string( $post->ID, '_lps_prerequisites' ),
			'syllabus'      => self::meta_string( $post->ID, '_lps_syllabus' ),
			'stale'         => self::is_stale_translation( $post ),
			'offerings'     => array(),
		);
	}

	/**
	 * Returns the published offerings of one course, newest term first.
	 *
	 * @param WP_Post $post           Course record.
	 * @param string  $locale         Supported locale slug.
	 * @param bool    $with_materials Whether to include offering materials.
	 * @return array<int, array<string, mixed>>
	 */
	private static function course_offerings( WP_Post $post, string $locale, bool $with_materials = false ): array {
		if ( ! class_exists( TeachingRecords::class ) ) {
			return array();
		}
		$authority = self::authority_id( $post );
		$rows      = Relationships::reverse_for( $authority, 'offering_course' );
		$offerings = array();
		foreach ( $rows as $row ) {
			$offering_authority = $row['source_post_id'];
			$variant            = self::localized_post( $offering_authority, $locale );
			if ( ! $variant instanceof WP_Post || 'publish' !== $variant->post_status ) {
				continue;
			}
			$offerings[] = self::offering_record( $variant, $locale, $with_materials );
		}
		usort(
			$offerings,
			static function ( array $left, array $right ): int {
				$by_term = self::nested_text( $right, 'term', 'starts_on' ) <=> self::nested_text( $left, 'term', 'starts_on' );
				return 0 !== $by_term ? $by_term : strcasecmp( self::text( $left['title'] ?? '' ), self::text( $right['title'] ?? '' ) );
			}
		);
		return $offerings;
	}

	/**
	 * Builds the publishable offering record for the renderer.
	 *
	 * @param WP_Post $post           Offering record.
	 * @param string  $locale         Supported locale slug.
	 * @param bool    $with_materials Whether to assemble the material rows.
	 * @return array<string, mixed>
	 */
	private static function offering_record( WP_Post $post, string $locale, bool $with_materials = false ): array {
		$authority = self::authority_id( $post );
		$identity  = class_exists( TeachingRecords::class ) ? TeachingRecords::offering_identity_for( $authority ) : null;
		$course    = null !== $identity ? self::localized_post( $identity['course_id'], $locale ) : null;
		$term      = null !== $identity && 0 < $identity['term_id'] ? get_post( $identity['term_id'] ) : null;
		$team      = array();
		if ( class_exists( Relationships::class ) ) {
			foreach ( Relationships::for_source( $authority, 'teaching_team' ) as $row ) {
				if ( ! $row['public_visibility'] ) {
					continue;
				}
				$person = self::localized_post( $row['target_post_id'], $locale );
				if ( ! $person instanceof WP_Post ) {
					continue;
				}
				$team[] = array(
					'name' => $person->post_title,
					'role' => $row['relationship_role'],
				);
			}
		}
		$units = array();
		if ( class_exists( Relationships::class ) ) {
			foreach ( Relationships::reverse_for( $authority, 'unit_offering' ) as $row ) {
				$unit = get_post( $row['source_post_id'] );
				if ( ! $unit instanceof WP_Post || 'publish' !== $unit->post_status ) {
					continue;
				}
				$units[] = array(
					'title'    => $unit->post_title,
					'anchor'   => self::meta_string( $unit->ID, '_lps_anchor' ),
					'position' => (int) self::meta_string( $unit->ID, '_lps_position' ),
					'body'     => $unit->post_content,
				);
			}
			usort(
				$units,
				static fn( array $left, array $right ): int => (int) $left['position'] <=> (int) $right['position']
			);
		}
		$token   = $term instanceof WP_Post ? self::meta_string( $term->ID, '_lps_term_token' ) : '';
		$section = null !== $identity ? $identity['section_key'] : self::meta_string( $authority, '_lps_section_key' );
		return array(
			'title'           => $post->post_title,
			'summary'         => $post->post_excerpt,
			'body'            => $post->post_content,
			'url'             => $course instanceof WP_Post && '' !== $token ? self::offering_path( $locale, $course->post_name, $token, $section ) : '',
			'section_key'     => $section,
			'schedule'        => self::meta_string( $authority, '_lps_schedule' ),
			'venue'           => self::meta_string( $authority, '_lps_venue' ),
			'syllabus'        => self::meta_string( $post->ID, '_lps_syllabus_snapshot' ),
			'lms_url'         => self::flag( $authority, '_lps_lms_url_approved' ) ? self::meta_string( $authority, '_lps_lms_url' ) : '',
			'temporal_status' => self::meta_string( $authority, '_lps_temporal_status' ),
			'cancelled'       => self::flag( $authority, '_lps_cancelled' ),
			'stale'           => self::is_stale_translation( $post ),
			'course'          => array(
				'title' => $course instanceof WP_Post ? $course->post_title : '',
				'url'   => $course instanceof WP_Post ? self::course_path( $locale, $course->post_name ) : '',
				'code'  => $course instanceof WP_Post ? self::meta_string( self::authority_id( $course ), '_lps_course_code' ) : '',
			),
			'term'            => array(
				'token'        => $token,
				'period_label' => $term instanceof WP_Post ? self::meta_string( $term->ID, '_lps_period_label' ) : '',
				'starts_on'    => $term instanceof WP_Post ? self::meta_string( $term->ID, '_lps_starts_on' ) : '',
				'ends_on'      => $term instanceof WP_Post ? self::meta_string( $term->ID, '_lps_ends_on' ) : '',
			),
			'team'            => $team,
			'units'           => $units,
			'materials'       => $with_materials ? self::offering_materials( $authority ) : array(),
			// The authority-side notice stream: one read serves both locale
			// routes, and PT-first posts render without an EN variant.
			'notices'         => class_exists( TaskDashboard::class ) ? TaskDashboard::notices_for_offering( $authority ) : array(),
			'siblings'        => array(),
		);
	}

	/**
	 * Returns the published material rows of one offering.
	 *
	 * Rows mirror the download resolver's visibility contract: the resource
	 * must be published and its offering relationship publicly visible. The
	 * effective release state is evaluated per request through the shared
	 * contract, so a due scheduled release appears without a scheduler run
	 * and a withdrawn row keeps its notice instead of a dead link.
	 *
	 * @param int $authority Offering authority record ID.
	 * @return array<int, array<string, mixed>>
	 */
	private static function offering_materials( int $authority ): array {
		if ( ! class_exists( Relationships::class ) ) {
			return array();
		}
		$now = class_exists( TeachingContracts::class ) ? TeachingContracts::today() : gmdate( 'Y-m-d' );

		// Batch the per-row reads before the loop: one relationship query for
		// every resource_unit edge and one post/meta cache prime for every
		// resource and unit record. Without this the list issues several
		// queries per material row, so a large resource list would grow
		// pathologically instead of staying flat.
		$rows = Relationships::reverse_for( $authority, 'resource_offering' );
		if ( function_exists( '_prime_post_caches' ) ) {
			_prime_post_caches( array_map( 'intval', array_column( $rows, 'source_post_id' ) ), true, true );
		}
		$visible     = array();
		$authorities = array();
		foreach ( $rows as $row ) {
			if ( ! $row['public_visibility'] ) {
				continue;
			}
			$resource = get_post( $row['source_post_id'] );
			if ( ! $resource instanceof WP_Post || 'publish' !== $resource->post_status ) {
				continue;
			}
			$resource_authority                 = self::authority_id( $resource );
			$visible[]                          = array( $resource, $resource_authority );
			$authorities[ $resource_authority ] = true;
		}
		if ( array() === $visible ) {
			return array();
		}

		$authority_ids = array_map( 'intval', array_keys( $authorities ) );
		$unit_edges    = Relationships::for_sources( $authority_ids, 'resource_unit' );
		$unit_ids      = array();
		foreach ( $unit_edges as $edge_rows ) {
			if ( isset( $edge_rows[0] ) ) {
				$unit_ids[] = (int) $edge_rows[0]['target_post_id'];
			}
		}
		if ( function_exists( '_prime_post_caches' ) ) {
			_prime_post_caches( array_merge( $authority_ids, $unit_ids ), true, true );
		}

		$materials = array();
		foreach ( $visible as $entry ) {
			list( $resource, $resource_authority ) = $entry;
			if ( 'published' !== self::meta_string( $resource_authority, '_lps_state' ) ) {
				continue;
			}
			$unit_anchor = '';
			$unit_rows   = $unit_edges[ $resource_authority ] ?? array();
			if ( isset( $unit_rows[0] ) ) {
				$unit_anchor = self::meta_string( (int) $unit_rows[0]['target_post_id'], '_lps_anchor' );
			}
			$release_state = self::meta_string( $resource_authority, '_lps_release_state' );
			$release_at    = self::meta_string( $resource_authority, '_lps_release_at' );
			$materials[]   = array(
				'title'                => $resource->post_title,
				'summary'              => $resource->post_excerpt,
				'type'                 => self::meta_string( $resource_authority, '_lps_resource_type' ),
				'language'             => self::meta_string( $resource_authority, '_lps_resource_language' ),
				'unit_anchor'          => $unit_anchor,
				'effective_state'      => class_exists( TeachingContracts::class )
					? TeachingContracts::effective_release_state( $release_state, $release_at, $now )
					: $release_state,
				'external_url'         => self::meta_string( $resource_authority, '_lps_external_url' ),
				'download_url'         => class_exists( TeachingResources::class ) ? TeachingResources::download_url( $resource_authority ) : '',
				'sha256'               => self::meta_string( $resource_authority, '_lps_sha256' ),
				'bytes'                => (int) self::meta_string( $resource_authority, '_lps_byte_size' ),
				'mime'                 => self::meta_string( $resource_authority, '_lps_mime_type' ),
				'scan_state'           => self::meta_string( $resource_authority, '_lps_scan_state' ),
				'rights_review'        => self::meta_string( $resource_authority, '_lps_rights_review' ),
				'accessibility_review' => self::meta_string( $resource_authority, '_lps_accessibility_review' ),
				'updated_at'           => 'withdrawn' === $release_state
					? self::meta_string( $resource_authority, '_lps_withdrawn_at' )
					: self::meta_string( $resource_authority, '_lps_updated_at' ),
			);
		}
		return $materials;
	}

	/**
	 * Returns the locale variant of a related record, or null when absent.
	 *
	 * @param int    $post_id Related record database ID.
	 * @param string $locale  Supported locale slug.
	 */
	private static function localized_post( int $post_id, string $locale ): ?WP_Post {
		$post = 0 < $post_id ? get_post( $post_id ) : null;
		if ( ! $post instanceof WP_Post || ! class_exists( Translations::class ) ) {
			return $post instanceof WP_Post ? $post : null;
		}
		if ( Translations::locale( $post->ID ) === $locale ) {
			return $post;
		}
		$variants = Translations::variants( $post->ID );
		$variant  = isset( $variants[ $locale ] ) ? get_post( $variants[ $locale ] ) : null;
		return $variant instanceof WP_Post ? $variant : null;
	}

	/**
	 * Answers whether one record is an English variant awaiting re-review.
	 *
	 * @param WP_Post $post Record.
	 */
	private static function is_stale_translation( WP_Post $post ): bool {
		if ( ! class_exists( Translations::class ) ) {
			return false;
		}
		return 'en' === Translations::locale( $post->ID ) && Translations::is_stale( $post->ID );
	}

	/**
	 * Returns the database ID of the Portuguese authority record.
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
	 * Returns the recorded locale of a record.
	 *
	 * @param WP_Post $post Record.
	 */
	private static function locale_for_post( WP_Post $post ): string {
		$locale = self::meta_string( $post->ID, '_lps_locale' );
		return 'en' === $locale ? 'en' : 'pt-br';
	}

	/**
	 * Converts trusted boundary input to a string.
	 *
	 * @param mixed $value Boundary input.
	 */
	private static function text( mixed $value ): string {
		return is_scalar( $value ) ? (string) $value : '';
	}

	/**
	 * Reads one nested record field as a string.
	 *
	 * @param array<string, mixed> $record Assembled record.
	 * @param string               $group  Nested record key.
	 * @param string               $key    Field key inside the nested record.
	 */
	private static function nested_text( array $record, string $group, string $key ): string {
		$nested = $record[ $group ] ?? null;
		return is_array( $nested ) ? self::text( $nested[ $key ] ?? '' ) : '';
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
	 * Reads one metadata value as a boolean flag.
	 *
	 * @param int    $post_id Record database ID.
	 * @param string $key     Metadata key.
	 */
	private static function flag( int $post_id, string $key ): bool {
		$value = get_post_meta( $post_id, $key, true );
		if ( is_bool( $value ) ) {
			return $value;
		}
		if ( is_int( $value ) ) {
			return 0 !== $value;
		}
		return is_string( $value ) && in_array( strtolower( $value ), array( '1', 'true', 'yes' ), true );
	}

	/** Returns the sanitized path of the current request. */
	private static function request_path(): string {
		if ( ! isset( $_SERVER['REQUEST_URI'] ) || ! is_string( $_SERVER['REQUEST_URI'] ) ) {
			return '/';
		}
		$path = wp_parse_url( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ), PHP_URL_PATH );
		return is_string( $path ) ? $path : '/';
	}
}
