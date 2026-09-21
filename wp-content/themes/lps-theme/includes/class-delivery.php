<?php
/**
 * Runtime wiring for the Todo 21 delivery policies.
 *
 * @package LPS\Theme
 */

declare(strict_types=1);

namespace LPS\Theme;

use LPS\ContentModel\Relationships;
use LPS\ContentModel\TeachingContracts;
use LPS\ContentModel\TeachingRecords;
use LPS\ContentModel\Translations;

/**
 * Applies `AssetPolicy` and `CachePolicy` to live WordPress requests.
 *
 * Every hook returns early on admin, block-editor and REST requests, so editorial and
 * authenticated output is never trimmed and never enters a shared cache.
 */
final class Delivery {
	/** Option holding the last purge batch, so operators and tests can inspect invalidation. */
	public const PURGE_LOG_OPTION = 'lps_cache_last_purge';

	/** Registers every delivery hook. */
	public static function boot(): void {
		if ( ! function_exists( 'add_action' ) ) {
			return;
		}
		add_action( 'wp_enqueue_scripts', array( self::class, 'trim_front_end_assets' ), 100 );
		add_action( 'init', array( self::class, 'trim_core_head_output' ) );
		add_action( 'wp_head', array( self::class, 'print_font_preloads' ), 1 );
		add_filter( 'wp_resource_hints', array( self::class, 'filter_resource_hints' ), 10, 2 );
		add_filter( 'should_load_separate_core_block_assets', array( self::class, 'load_separate_block_assets' ) );
		add_action( 'template_redirect', array( self::class, 'send_cache_headers' ), 20 );
		add_action( 'transition_post_status', array( self::class, 'purge_on_transition' ), 10, 3 );
		add_action( 'deleted_post', array( self::class, 'purge_on_delete' ), 10, 2 );
		add_action( 'updated_post_meta', array( self::class, 'purge_on_lifecycle_meta' ), 10, 4 );
		add_action( 'added_post_meta', array( self::class, 'purge_on_lifecycle_meta' ), 10, 4 );
	}

	/**
	 * Teaching lifecycle metadata that changes public delivery without a post
	 * status transition: release, withdrawal, scheduling, cancellation,
	 * temporal status and version selection.
	 *
	 * @var array<int, string>
	 */
	private const LIFECYCLE_META_KEYS = array(
		'_lps_release_state',
		'_lps_release_at',
		'_lps_withdrawn_at',
		'_lps_cancelled',
		'_lps_temporal_status',
		'_lps_version_id',
	);

	/** Reports whether the current request is a public front-end response. */
	public static function is_public_request(): bool {
		if ( function_exists( 'is_admin' ) && is_admin() ) {
			return false;
		}
		if ( ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || ( defined( 'DOING_AJAX' ) && DOING_AJAX ) || ( defined( 'DOING_CRON' ) && DOING_CRON ) ) {
			return false;
		}

		return true;
	}

	/** Removes core payloads the rendered page does not use. */
	public static function trim_front_end_assets(): void {
		if ( ! self::is_public_request() ) {
			return;
		}

		$plan = AssetPolicy::dequeue_plan(
			array(
				'is_admin'     => false,
				'block_editor' => false,
				'blocks'       => self::rendered_blocks(),
			)
		);

		foreach ( $plan['styles'] as $handle ) {
			wp_dequeue_style( $handle );
			wp_deregister_style( $handle );
		}
		foreach ( $plan['scripts'] as $handle ) {
			wp_dequeue_script( $handle );
			wp_deregister_script( $handle );
		}
	}

	/** Removes the emoji detector and other unused core head output on public requests. */
	public static function trim_core_head_output(): void {
		if ( ! self::is_public_request() ) {
			return;
		}
		$plan = AssetPolicy::dequeue_plan(
			array(
				'is_admin'     => false,
				'block_editor' => false,
				'blocks'       => array(),
			)
		);
		if ( ! $plan['remove_emoji'] ) {
			return;
		}
		remove_action( 'wp_head', 'print_emoji_detection_script', 7 );
		remove_action( 'wp_print_styles', 'print_emoji_styles' );
		remove_action( 'wp_head', 'wp_emoji_styles' );
		remove_action( 'wp_head', 'wp_generator' );
		remove_action( 'wp_head', 'wlwmanifest_link' );
		remove_action( 'wp_head', 'rsd_link' );
		remove_filter( 'the_content_feed', 'wp_staticize_emoji' );
		remove_filter( 'comment_text_rss', 'wp_staticize_emoji' );
	}

	/** Preloads the two self-hosted subset faces used by the first paint. */
	public static function print_font_preloads(): void {
		if ( ! self::is_public_request() ) {
			return;
		}
		foreach ( AssetPolicy::font_preloads( get_template_directory_uri() ) as $preload ) {
			printf(
				'<link rel="preload" href="%s" as="%s" type="%s"%s>' . "\n",
				esc_url( $preload['href'] ),
				esc_attr( $preload['as'] ),
				esc_attr( $preload['type'] ),
				$preload['crossorigin'] ? ' crossorigin' : ''
			);
		}
	}

	/**
	 * Drops the core `s.w.org` DNS prefetch: this theme loads no remote asset.
	 *
	 * @param array<int, string> $hints         Registered hints.
	 * @param string             $relation_type Hint relation.
	 *
	 * @return array<int, string>
	 */
	public static function filter_resource_hints( array $hints, string $relation_type ): array {
		if ( ! self::is_public_request() || 'dns-prefetch' !== $relation_type ) {
			return $hints;
		}

		return array_values(
			array_filter(
				$hints,
				static fn( string $hint ): bool => ! str_contains( $hint, 's.w.org' )
			)
		);
	}

	/**
	 * Ships per-block core CSS instead of the monolithic library on public requests.
	 *
	 * @param bool $enabled Core default.
	 */
	public static function load_separate_block_assets( bool $enabled ): bool {
		return self::is_public_request() ? true : $enabled;
	}

	/** Emits the cache decision for the current response. */
	public static function send_cache_headers(): void {
		if ( headers_sent() ) {
			return;
		}

		$decision = CachePolicy::decide( self::request_facts() );
		header( 'Cache-Control: ' . $decision['headers']['Cache-Control'], true );
		header( 'Vary: ' . $decision['headers']['Vary'], true );
		if ( $decision['cacheable'] && array() !== $decision['surrogate_keys'] ) {
			header( 'Surrogate-Key: ' . implode( ' ', $decision['surrogate_keys'] ), true );
			header( 'Surrogate-Control: max-age=' . $decision['ttl'], true );
		}
		header( 'X-LPS-Cache-Policy: ' . $decision['reason'], true );
	}

	/**
	 * Collects the request facts `CachePolicy` needs.
	 *
	 * @return array{method: string, path: string, status: int, query: array<string, mixed>, cookies: array<int, string>, is_admin: bool, logged_in: bool}
	 */
	public static function request_facts(): array {
		$query = array();
		foreach ( array_keys( $_GET ) as $key ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$query[ (string) $key ] = true;
		}

		$method = isset( $_SERVER['REQUEST_METHOD'] ) && is_string( $_SERVER['REQUEST_METHOD'] )
			? strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) )
			: 'GET';
		$path   = '/';
		if ( isset( $_SERVER['REQUEST_URI'] ) && is_string( $_SERVER['REQUEST_URI'] ) ) {
			$parsed = wp_parse_url( esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ), PHP_URL_PATH );
			$path   = is_string( $parsed ) ? $parsed : '/';
		}

		return array(
			'method'    => $method,
			'path'      => $path,
			'status'    => self::current_status(),
			'query'     => $query,
			'cookies'   => array_map( 'strval', array_keys( $_COOKIE ) ),
			'is_admin'  => function_exists( 'is_admin' ) && is_admin(),
			'logged_in' => function_exists( 'is_user_logged_in' ) && is_user_logged_in(),
		);
	}

	/** Resolves the status code WordPress is about to send. */
	private static function current_status(): int {
		if ( function_exists( 'is_404' ) && is_404() ) {
			return 404;
		}
		$code = http_response_code();

		return is_int( $code ) ? $code : 200;
	}

	/**
	 * Purges the affected public URLs when a record enters or leaves publication.
	 *
	 * @param string $new_status New status.
	 * @param string $old_status Previous status.
	 * @param mixed  $post       Changed post.
	 */
	public static function purge_on_transition( string $new_status, string $old_status, $post ): void {
		if ( 'publish' !== $new_status && 'publish' !== $old_status ) {
			return;
		}
		self::purge( $post );
	}

	/**
	 * Purges the affected public URLs when a record is deleted.
	 *
	 * @param int   $post_id Deleted post id.
	 * @param mixed $post    Deleted post.
	 */
	public static function purge_on_delete( int $post_id, $post ): void {
		unset( $post_id );
		self::purge( $post );
	}

	/**
	 * Purges the affected public URLs when a lifecycle metadata write lands.
	 *
	 * Release, withdrawal, scheduling, cancellation and version selection are
	 * pure metadata writes: `transition_post_status` never fires for them, so
	 * this hook is the only invalidation signal. The purge is idempotent —
	 * the same transition may legitimately write several keys — and only
	 * published records have public URLs to expire.
	 *
	 * @param int    $meta_id    Metadata row ID.
	 * @param int    $post_id    Record ID.
	 * @param string $meta_key   Written metadata key.
	 * @param mixed  $meta_value Written value.
	 */
	public static function purge_on_lifecycle_meta( int $meta_id, int $post_id, string $meta_key, mixed $meta_value ): void {
		unset( $meta_id, $meta_value );
		if ( ! in_array( $meta_key, self::LIFECYCLE_META_KEYS, true ) ) {
			return;
		}
		$post = get_post( $post_id );
		if ( ! $post instanceof \WP_Post || 'publish' !== $post->post_status ) {
			return;
		}
		self::purge( $post );
	}

	/**
	 * Computes and dispatches the purge batch for one record.
	 *
	 * @param mixed $post Changed post.
	 */
	private static function purge( $post ): void {
		if ( ! $post instanceof \WP_Post || ! function_exists( 'get_permalink' ) ) {
			return;
		}
		if ( function_exists( 'wp_is_post_revision' ) && wp_is_post_revision( $post->ID ) ) {
			return;
		}

		$permalink = get_permalink( $post->ID );
		if ( ! is_string( $permalink ) || '' === $permalink ) {
			// A deleted record has no permalink; the site root anchors the
			// purge origin so related and locale targets still resolve.
			$permalink = home_url( '/' );
		}
		$targets   = CachePolicy::purge_targets(
			array(
				'permalink'    => is_string( $permalink ) ? $permalink : '',
				'archives'     => self::archive_urls( $post ),
				'translations' => self::translation_urls( $post ),
				'terms'        => self::term_urls( $post ),
				'related'      => self::related_urls( $post ),
				'locales'      => array( 'pt-br', 'en' ),
				'records'      => self::record_urls( $post ),
			)
		);

		if ( array() === $targets ) {
			return;
		}

		update_option(
			self::PURGE_LOG_OPTION,
			array(
				'post'    => (int) $post->ID,
				'time'    => time(),
				'targets' => $targets,
			),
			false
		);

		/**
		 * Fires with the exact public URLs a CDN or reverse proxy must expire.
		 *
		 * Hosts wire this to their purge API; see `docs/operations/performance-hosting.md`.
		 *
		 * @param array<int, string> $targets Canonical URLs to expire.
		 * @param \WP_Post           $post    Changed record.
		 */
		do_action( 'lps_cache_purge', $targets, $post );
	}

	/**
	 * Lists the teaching-surface URLs affected by a record.
	 *
	 * Canonical teaching routes are not permalinks, so the purge must name
	 * them explicitly: the landing and course pages, every offering route
	 * derived from the changed record, the faculty pages of its teaching
	 * team, and — through the `locales` group — the locale home and search
	 * entry points that already accompany every publish.
	 *
	 * @param \WP_Post $post Changed post.
	 *
	 * @return array<int, string>
	 */
	private static function related_urls( \WP_Post $post ): array {
		if ( ! class_exists( TeachingContracts::class ) || ! class_exists( TeachingRecords::class ) || ! class_exists( Relationships::class ) ) {
			return array();
		}
		$urls      = array();
		$authority = TeachingContracts::authoritative_id( $post->ID );
		$locales   = array( 'pt-br', 'en' );
		switch ( $post->post_type ) {
			case 'lps_course':
				foreach ( $locales as $locale ) {
					$urls[] = home_url( TeachingRecords::landing_path( $locale ) );
					$course = self::localized_post( $authority, $locale );
					if ( $course instanceof \WP_Post ) {
						$urls[] = home_url( TeachingRecords::course_path( $locale, $course->post_name ) );
					}
				}
				foreach ( Relationships::reverse_for( $authority, 'offering_course' ) as $row ) {
					$urls = array_merge( $urls, self::offering_urls( (int) ( $row['source_post_id'] ?? 0 ) ) );
				}
				break;
			case 'lps_term':
				foreach ( $locales as $locale ) {
					$urls[] = home_url( TeachingRecords::landing_path( $locale ) );
				}
				foreach ( Relationships::reverse_for( $authority, 'offering_term' ) as $row ) {
					$urls = array_merge( $urls, self::offering_urls( (int) ( $row['source_post_id'] ?? 0 ) ) );
				}
				break;
			case 'lps_offering':
				foreach ( $locales as $locale ) {
					$urls[] = home_url( TeachingRecords::landing_path( $locale ) );
				}
				$urls = array_merge( $urls, self::offering_urls( $authority ), self::course_urls_for_offering( $authority ), self::team_urls( $authority ) );
				break;
			case 'lps_unit':
				foreach ( Relationships::for_source( $authority, 'unit_offering' ) as $row ) {
					$urls = array_merge( $urls, self::offering_urls( (int) ( $row['target_post_id'] ?? 0 ) ) );
				}
				break;
			case 'lps_resource':
				foreach ( Relationships::for_source( $authority, 'resource_offering' ) as $row ) {
					$urls = array_merge( $urls, self::offering_urls( (int) ( $row['target_post_id'] ?? 0 ) ) );
				}
				break;
			case 'lps_person':
				foreach ( Relationships::reverse_for( $authority, 'teaching_team' ) as $row ) {
					$urls = array_merge( $urls, self::offering_urls( (int) ( $row['source_post_id'] ?? 0 ) ) );
				}
				break;
			default:
				break;
		}
		return array_values( array_filter( $urls, 'is_string' ) );
	}

	/**
	 * Lists the canonical offering URLs of one offering in both locales.
	 *
	 * @param int $offering_id Any associated offering variant ID.
	 *
	 * @return array<int, string>
	 */
	private static function offering_urls( int $offering_id ): array {
		$urls = array();
		foreach ( array( 'pt-br', 'en' ) as $locale ) {
			$path = TeachingRecords::offering_url( $offering_id, $locale );
			if ( '' !== $path ) {
				$urls[] = home_url( $path );
			}
		}
		return $urls;
	}

	/**
	 * Lists the canonical course URLs owning one offering in both locales.
	 *
	 * @param int $offering_id Authoritative offering ID.
	 *
	 * @return array<int, string>
	 */
	private static function course_urls_for_offering( int $offering_id ): array {
		$identity  = TeachingRecords::offering_identity_for( $offering_id );
		$course_id = (int) ( $identity['course_id'] ?? 0 );
		$urls      = array();
		foreach ( array( 'pt-br', 'en' ) as $locale ) {
			$course = self::localized_post( $course_id, $locale );
			if ( $course instanceof \WP_Post ) {
				$urls[] = home_url( TeachingRecords::course_path( $locale, $course->post_name ) );
			}
		}
		return $urls;
	}

	/**
	 * Lists the faculty permalinks of one offering's teaching team.
	 *
	 * @param int $offering_id Authoritative offering ID.
	 *
	 * @return array<int, string>
	 */
	private static function team_urls( int $offering_id ): array {
		$urls = array();
		foreach ( Relationships::for_source( $offering_id, 'teaching_team' ) as $member ) {
			foreach ( array( 'pt-br', 'en' ) as $locale ) {
				$person = self::localized_post( (int) ( $member['target_post_id'] ?? 0 ), $locale );
				if ( $person instanceof \WP_Post ) {
					$permalink = get_permalink( $person->ID );
					if ( is_string( $permalink ) && '' !== $permalink ) {
						$urls[] = $permalink;
					}
				}
			}
		}
		return $urls;
	}

	/**
	 * Returns the locale variant of a record, or null when absent.
	 *
	 * @param int    $post_id Any associated variant ID.
	 * @param string $locale  Supported locale slug.
	 */
	private static function localized_post( int $post_id, string $locale ): ?\WP_Post {
		$post = 0 < $post_id ? get_post( $post_id ) : null;
		if ( ! $post instanceof \WP_Post ) {
			return null;
		}
		if ( ! class_exists( Translations::class ) ) {
			return $post;
		}
		if ( Translations::locale( $post->ID ) === $locale ) {
			return $post;
		}
		$variants = Translations::variants( $post->ID );
		$variant  = isset( $variants[ $locale ] ) ? get_post( $variants[ $locale ] ) : null;
		return $variant instanceof \WP_Post ? $variant : null;
	}

	/**
	 * Lists the archive URLs affected by a record.
	 *
	 * @param \WP_Post $post Changed post.
	 *
	 * @return array<int, string>
	 */
	private static function archive_urls( \WP_Post $post ): array {
		$urls = array();
		foreach ( array( 'pt-br', 'en' ) as $locale ) {
			$path = SeoRoutes::archive_path( $post->post_type, $locale );
			if ( '' !== $path ) {
				$urls[] = $path;
			}
		}
		return $urls;
	}

	/**
	 * Lists the translated permalinks affected by a record.
	 *
	 * @param \WP_Post $post Changed post.
	 *
	 * @return array<int, string>
	 */
	private static function translation_urls( \WP_Post $post ): array {
		if ( ! function_exists( 'pll_get_post_translations' ) || ! function_exists( 'pll_get_post_language' ) ) {
			return array();
		}
		$urls = array();
		foreach ( (array) pll_get_post_translations( $post->ID ) as $translated_id ) {
			if ( ! is_numeric( $translated_id ) || (int) $translated_id === $post->ID ) {
				continue;
			}
			$translated = get_post( (int) $translated_id );
			$locale     = $translated instanceof \WP_Post ? pll_get_post_language( $translated->ID, 'slug' ) : false;
			if ( $translated instanceof \WP_Post && is_string( $locale ) && '' !== $locale ) {
				$path = SeoRoutes::record_path( $translated, $locale );
				if ( '' !== $path ) {
					$urls[] = $path;
				}
			}
		}

		return $urls;
	}

	/**
	 * Lists the taxonomy archive URLs affected by a record.
	 *
	 * @param \WP_Post $post Changed post.
	 *
	 * @return array<int, string>
	 */
	private static function term_urls( \WP_Post $post ): array {
		if ( ! function_exists( 'get_object_taxonomies' ) ) {
			return array();
		}
		$urls = array();
		foreach ( get_object_taxonomies( $post->post_type ) as $taxonomy ) {
			$terms = get_the_terms( $post->ID, (string) $taxonomy );
			if ( ! is_array( $terms ) ) {
				continue;
			}
			foreach ( $terms as $term ) {
				$link = get_term_link( $term );
				if ( is_string( $link ) && '' !== $link ) {
					$urls[] = $link;
				}
			}
		}

		return $urls;
	}

	/**
	 * Lists the localized public route URLs of a governed record.
	 *
	 * Governed records serve on frozen localized routes (e.g.
	 * `/pt-br/noticias/<slug>/`) that never equal the native permalink, so the
	 * canonical URL the sitemap advertises must be purged explicitly.
	 *
	 * @param \WP_Post $post Changed post.
	 *
	 * @return array<int, string>
	 */
	private static function record_urls( \WP_Post $post ): array {
		$urls = array();
		foreach ( array( 'pt-br', 'en' ) as $locale ) {
			$target = $post;
			if ( function_exists( 'pll_get_post' ) ) {
				$translated_id = pll_get_post( $post->ID, $locale );
				if ( is_numeric( $translated_id ) && (int) $translated_id !== $post->ID ) {
					$translated = get_post( (int) $translated_id );
					if ( $translated instanceof \WP_Post ) {
						$target = $translated;
					}
				}
			}
			$path = SeoRoutes::record_path( $target, $locale );
			if ( '' !== $path ) {
				$urls[] = $path;
			}
		}
		return $urls;
	}

	/**
	 * Collects the block names rendered by the current request.
	 *
	 * @return array<int, string>
	 */
	private static function rendered_blocks(): array {
		$content = '';
		if ( function_exists( 'get_the_ID' ) && function_exists( 'get_post_field' ) ) {
			$post_id = get_the_ID();
			if ( is_int( $post_id ) && $post_id > 0 ) {
				$field   = get_post_field( 'post_content', $post_id );
				$content = is_string( $field ) ? $field : '';
			}
		}
		$blocks = array();
		if ( '' !== $content && function_exists( 'parse_blocks' ) ) {
			foreach ( parse_blocks( $content ) as $block ) {
				if ( isset( $block['blockName'] ) && '' !== $block['blockName'] ) {
					$blocks[] = $block['blockName'];
				}
			}
		}
		foreach ( self::template_blocks() as $block ) {
			$blocks[] = $block;
		}

		return array_values( array_unique( $blocks ) );
	}

	/**
	 * Collects the block names used by the block template resolved for this request.
	 *
	 * WordPress stores the resolved template in `$_wp_current_template_content` during
	 * `template_include`, which runs before `wp_head` and therefore before this decision.
	 *
	 * @return array<int, string>
	 */
	private static function template_blocks(): array {
		$content = $GLOBALS['_wp_current_template_content'] ?? '';
		if ( ! is_string( $content ) || '' === $content || ! function_exists( 'parse_blocks' ) ) {
			return array();
		}
		$blocks = array();
		foreach ( parse_blocks( $content ) as $block ) {
			if ( isset( $block['blockName'] ) && '' !== $block['blockName'] ) {
				$blocks[] = $block['blockName'];
			}
		}

		return $blocks;
	}
}
