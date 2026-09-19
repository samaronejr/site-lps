<?php
/**
 * Immutable teaching-resource versions and the guarded download boundary.
 *
 * Resource records (`lps_resource`) point at immutable asset versions held in
 * the `{prefix}lps_resource_version_registry` table. A version row is the
 * persisted form of a `TeachingStorage` record: opaque `lps-file-*` storage
 * key, SHA-256, byte size, detected MIME, scan state and verdict. A correction
 * mints a new version; selecting it on a resource is an explicit, audited
 * write, so a prior selection keeps serving its prior bytes until replaced.
 *
 * The public download route resolves every request through the same
 * evaluation: resource visibility, parent-offering visibility, release state
 * and time, version identity, storage/scan state, and rights/accessibility
 * review. Storage paths, raw record IDs and version IDs never bypass the
 * resolver, and every denial is the same anonymous 404 — no titles, storage
 * keys, paths or typed codes leak to clients.
 *
 * @package LPS\ContentModel
 */

declare(strict_types=1);

namespace LPS\ContentModel;

use WP_Error;
use WP_Post;
use wpdb;

require_once __DIR__ . '/class-policy.php';
require_once __DIR__ . '/class-contracts.php';
require_once __DIR__ . '/class-migrations.php';
require_once __DIR__ . '/class-relationships.php';
require_once __DIR__ . '/class-teachingcontracts.php';
require_once __DIR__ . '/class-teachingmigrations.php';
require_once __DIR__ . '/class-teachingstorage.php';
require_once __DIR__ . '/class-teachingpolicy.php';
require_once __DIR__ . '/class-translationpolicy.php';
require_once __DIR__ . '/class-audit.php';

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Version-registry claims and fresh resolver reads must bypass object caches.
/**
 * Owns version minting, scoped release decisions and guarded byte delivery.
 */
final class TeachingResources {
	/** Public query var carrying the opaque download token. */
	public const DOWNLOAD_QUERY_VAR = 'lps_resource_download';

	/** Public route prefix for guarded downloads. */
	public const ROUTE_PREFIX = 'lps-resource';

	/** Record-ID prefix `Plugin::complete_record` assigns to resources. */
	private const RECORD_ID_PREFIX = 'lps:resource:';

	/** Metadata keys a create request may write on the Portuguese authority. */
	private const AUTHORITY_META = array(
		'_lps_resource_type',
		'_lps_resource_language',
		'_lps_external_url',
		'_lps_rights_review',
		'_lps_accessibility_review',
	);

	/** Registers the download route, canonical vetoes and the delivery hook. */
	public static function boot(): void {
		add_filter( 'rewrite_rules_array', array( self::class, 'register_routes' ), 996 );
		add_filter( 'query_vars', array( self::class, 'register_query_vars' ) );
		add_action( 'template_redirect', array( self::class, 'maybe_serve_download' ), 0 );
		add_filter( 'redirect_canonical', array( self::class, 'keep_download_route' ), 10, 2 );
		add_filter( 'pll_check_canonical_url', array( self::class, 'keep_download_canonical' ), 10, 2 );
		add_filter( 'do_redirect_guess_404_permalink', array( self::class, 'keep_download_404' ) );
	}

	/**
	 * Prepends the guarded download rewrite rule.
	 *
	 * @param array<string, string> $rules Registered rewrite rules.
	 * @return array<string, string>
	 */
	public static function register_routes( array $rules ): array {
		return array_merge( self::rewrite_rules(), $rules );
	}

	/**
	 * Returns the download rewrite rules.
	 *
	 * The token is the opaque UUID portion of the resource's `_lps_record_id`;
	 * raw post IDs, storage keys and version IDs match nothing.
	 *
	 * @return array<string, string>
	 */
	public static function rewrite_rules(): array {
		return array(
			self::ROUTE_PREFIX . '/([0-9a-f-]{36})/?$' => 'index.php?' . self::DOWNLOAD_QUERY_VAR . '=$matches[1]',
		);
	}

	/**
	 * Registers the download query var.
	 *
	 * @param array<int, string> $vars Registered public query vars.
	 * @return array<int, string>
	 */
	public static function register_query_vars( array $vars ): array {
		$vars[] = self::DOWNLOAD_QUERY_VAR;
		return $vars;
	}

	/**
	 * Vetoes WordPress canonical redirects on download paths.
	 *
	 * A denied download must stay a real 404: canonical guessing and locale
	 * redirects would turn it into a misleading 301.
	 *
	 * @param string|false $redirect_url  Proposed canonical URL.
	 * @param string       $requested_url Requested URL.
	 * @return string|false
	 */
	public static function keep_download_route( string|false $redirect_url, string $requested_url ): string|false {
		$path = wp_parse_url( $requested_url, PHP_URL_PATH );
		if ( is_string( $path ) && self::is_download_path( $path ) ) {
			return false;
		}
		return $redirect_url;
	}

	/**
	 * Vetoes Polylang's canonical redirect on download paths.
	 *
	 * @param string|false $redirect_url Proposed canonical URL.
	 * @param mixed        $language     Detected Polylang language.
	 * @return string|false
	 */
	public static function keep_download_canonical( string|false $redirect_url, mixed $language ): string|false {
		unset( $language );
		return self::is_download_path( self::request_path() ) ? false : $redirect_url;
	}

	/**
	 * Vetoes 404 permalink guessing on download paths.
	 *
	 * @param bool $do_redirect_guess Whether WordPress may guess a permalink.
	 */
	public static function keep_download_404( bool $do_redirect_guess ): bool {
		return self::is_download_path( self::request_path() ) ? false : $do_redirect_guess;
	}

	/**
	 * Serves or denies the download addressed by the current request.
	 *
	 * Every denial is the same anonymous 404: the query is marked not-found
	 * and the normal template pipeline renders the theme's 404 surface. A
	 * granted request streams the selected version's bytes with attachment
	 * disposition, nosniff, no-store and single-range support, then exits.
	 */
	public static function maybe_serve_download(): void {
		$token = get_query_var( self::DOWNLOAD_QUERY_VAR );
		if ( ! is_string( $token ) || '' === $token ) {
			// The route owns its whole prefix: a request under /lps-resource/
			// that did not resolve to a download token is a guessed path and
			// must die here as the same anonymous 404, before the SEO legacy
			// redirect resolver or the template pipeline can answer it.
			if ( self::is_download_path( self::request_path() ) ) {
				self::deny();
			}
			return;
		}
		$method = isset( $_SERVER['REQUEST_METHOD'] ) && is_string( $_SERVER['REQUEST_METHOD'] )
			? strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) )
			: 'GET';
		if ( 'GET' !== $method && 'HEAD' !== $method ) {
			self::deny();
			return;
		}
		$resolved = self::resolve( $token );
		if ( null !== $resolved['denial'] || null === $resolved['version'] || null === $resolved['path'] ) {
			self::deny();
			return;
		}
		self::stream( $resolved['version'], $resolved['path'], 'HEAD' === $method );
	}

	/**
	 * Evaluates the complete download authorization for one token.
	 *
	 * The resolver is the only path to bytes: it re-reads the resource, its
	 * parent offering, the selected version row, the release clock and the
	 * storage configuration on every call, so a stopped scheduler, a revoked
	 * parent or a scanner outage can never reuse a cached grant.
	 *
	 * @param string $token Opaque download token.
	 * @return array{denial: string|null, version: array<string, mixed>|null, path: string|null}
	 */
	public static function resolve( string $token ): array {
		$empty = array(
			'denial'  => 'lps_resource_not_found',
			'version' => null,
			'path'    => null,
		);
		$post  = self::resource_for_token( $token );
		if ( ! $post instanceof WP_Post ) {
			return $empty;
		}
		$resource = array(
			'post_status'          => $post->post_status,
			'state'                => Policy::scalar_string( get_post_meta( $post->ID, '_lps_state', true ) ),
			'version_id'           => Policy::scalar_string( get_post_meta( $post->ID, '_lps_version_id', true ) ),
			'external_url'         => Policy::scalar_string( get_post_meta( $post->ID, '_lps_external_url', true ) ),
			'storage_key'          => Policy::scalar_string( get_post_meta( $post->ID, '_lps_storage_key', true ) ),
			'release_state'        => Policy::scalar_string( get_post_meta( $post->ID, '_lps_release_state', true ) ),
			'release_at'           => Policy::scalar_string( get_post_meta( $post->ID, '_lps_release_at', true ) ),
			'rights_review'        => Policy::scalar_string( get_post_meta( $post->ID, '_lps_rights_review', true ) ),
			'accessibility_review' => Policy::scalar_string( get_post_meta( $post->ID, '_lps_accessibility_review', true ) ),
		);
		$offering = null;
		$rows     = Relationships::for_source( $post->ID, 'resource_offering' );
		$parent   = isset( $rows[0] ) ? get_post( Policy::sanitize_integer( $rows[0]['target_post_id'] ) ) : null;
		if ( $parent instanceof WP_Post ) {
			$offering = array(
				'post_status'       => $parent->post_status,
				'state'             => Policy::scalar_string( get_post_meta( $parent->ID, '_lps_state', true ) ),
				'public_visibility' => (bool) $rows[0]['public_visibility'],
			);
		}
		$config        = TeachingStorage::config_from_constants();
		$version       = '' !== $resource['version_id'] ? self::version_for_id( $resource['version_id'] ) : null;
		$storage_error = null === $version
			? 'lps_resource_version_missing'
			: TeachingStorage::release_error( $version, $config, self::is_production() );
		$denial        = self::release_denial( $resource, $offering, $version, $storage_error, gmdate( 'c' ) );
		if ( null !== $denial || null === $version ) {
			return array(
				'denial'  => $denial ?? 'lps_resource_version_missing',
				'version' => null,
				'path'    => null,
			);
		}
		$path = TeachingStorage::storage_path( Policy::scalar_string( $config['storage_root'] ?? '' ), $version );
		if ( ! is_file( $path ) || ! is_readable( $path ) || filesize( $path ) !== Policy::sanitize_integer( $version['bytes'] ?? 0 ) ) {
			return array(
				'denial'  => 'lps_storage_object_missing',
				'version' => null,
				'path'    => null,
			);
		}
		return array(
			'denial'  => null,
			'version' => $version,
			'path'    => $path,
		);
	}

	/**
	 * Returns the first download denial, or null when bytes may be served.
	 *
	 * Pure evaluation shared by the resolver and tests. The order is
	 * structural integrity, release state and time, storage/scan state, then
	 * rights review; every code maps to the same anonymous 404.
	 *
	 * @param array<string, mixed>|null $resource_view Resource meta view.
	 * @param array<string, mixed>|null $offering      Parent-offering view.
	 * @param array<string, mixed>|null $version       Selected version row.
	 * @param string|null               $storage_error `TeachingStorage::release_error` result.
	 * @param string                    $now           Reference time (ISO-8601).
	 */
	public static function release_denial( ?array $resource_view, ?array $offering, ?array $version, ?string $storage_error, string $now ): ?string {
		if ( null === $resource_view
			|| 'publish' !== Policy::scalar_string( $resource_view['post_status'] ?? '' )
			|| 'published' !== Policy::scalar_string( $resource_view['state'] ?? '' )
		) {
			return 'lps_resource_not_found';
		}
		if ( null === $offering
			|| 'publish' !== Policy::scalar_string( $offering['post_status'] ?? '' )
			|| 'published' !== Policy::scalar_string( $offering['state'] ?? '' )
			|| true !== ( $offering['public_visibility'] ?? false )
		) {
			return 'lps_resource_offering_not_public';
		}
		if ( '' !== Policy::scalar_string( $resource_view['external_url'] ?? '' ) ) {
			return 'lps_resource_external';
		}
		$version_id = Policy::scalar_string( $resource_view['version_id'] ?? '' );
		if ( '' === $version_id ) {
			return 'lps_resource_version_missing';
		}
		if ( null === $version ) {
			return 'lps_resource_version_missing';
		}
		if ( Policy::scalar_string( $resource_view['storage_key'] ?? '' ) !== Policy::scalar_string( $version['key'] ?? '' ) ) {
			return 'lps_resource_version_mismatch';
		}
		$effective = self::effective_release_state(
			Policy::scalar_string( $resource_view['release_state'] ?? '' ),
			Policy::scalar_string( $resource_view['release_at'] ?? '' ),
			$now
		);
		if ( 'withdrawn' === $effective ) {
			return 'lps_resource_withdrawn';
		}
		if ( 'released' !== $effective ) {
			return 'lps_resource_not_released';
		}
		if ( null !== $storage_error ) {
			return $storage_error;
		}
		if ( 'approved' !== Policy::scalar_string( $resource_view['rights_review'] ?? '' ) ) {
			return 'lps_resource_rights_not_approved';
		}
		if ( 'approved' !== Policy::scalar_string( $resource_view['accessibility_review'] ?? '' ) ) {
			return 'lps_resource_accessibility_not_approved';
		}
		return null;
	}

	/**
	 * Returns the release state in effect at one instant.
	 *
	 * `released` and `withdrawn` are authoritative immediately; `scheduled`
	 * becomes effective only when its release time has passed — evaluated on
	 * every request, so a stopped scheduler can never release early and a due
	 * release never waits on cron. Unknown states fail closed to `draft`.
	 *
	 * @param string $release_state Stored release state.
	 * @param string $release_at    Scheduled release timestamp.
	 * @param string $now           Reference time (ISO-8601).
	 */
	public static function effective_release_state( string $release_state, string $release_at, string $now ): string {
		if ( 'released' === $release_state || 'withdrawn' === $release_state ) {
			return $release_state;
		}
		if ( 'scheduled' === $release_state ) {
			$release_time = strtotime( TeachingPolicy::normalize_datetime( $release_at ) );
			$now_time     = strtotime( TeachingPolicy::normalize_datetime( $now ) );
			if ( false !== $release_time && false !== $now_time && $release_time <= $now_time ) {
				return 'released';
			}
			return 'scheduled';
		}
		return 'draft';
	}

	/**
	 * Parses one HTTP Range header into a satisfiable single range.
	 *
	 * Only `bytes=start-end`, `bytes=start-` and `bytes=-suffix` are honored;
	 * multi-range requests and malformed or unsatisfiable ranges are answered
	 * 416 rather than silently ignored, and non-`bytes` units fall back to a
	 * full 200 response.
	 *
	 * @param string $header Raw Range header value.
	 * @param int    $size   Total byte size.
	 * @return array{status: string, start: int, end: int}
	 */
	public static function parse_range( string $header, int $size ): array {
		$header = trim( $header );
		if ( '' === $header || ! str_starts_with( strtolower( $header ), 'bytes=' ) ) {
			return array(
				'status' => 'none',
				'start'  => 0,
				'end'    => 0,
			);
		}
		$spec = trim( substr( $header, 6 ) );
		if ( str_contains( $spec, ',' ) || ! str_contains( $spec, '-' ) || $size <= 0 ) {
			return array(
				'status' => 'unsatisfiable',
				'start'  => 0,
				'end'    => 0,
			);
		}
		$parts = explode( '-', $spec, 2 );
		$first = trim( $parts[0] );
		$last  = trim( (string) ( $parts[1] ?? '' ) );
		$start = 0;
		$end   = $size - 1;
		if ( '' === $first ) {
			if ( '' === $last || ! ctype_digit( $last ) ) {
				return array(
					'status' => 'unsatisfiable',
					'start'  => 0,
					'end'    => 0,
				);
			}
			$suffix = (int) $last;
			if ( 0 >= $suffix ) {
				return array(
					'status' => 'unsatisfiable',
					'start'  => 0,
					'end'    => 0,
				);
			}
			$start = max( 0, $size - $suffix );
		} else {
			if ( ! ctype_digit( $first ) || ( '' !== $last && ! ctype_digit( $last ) ) ) {
				return array(
					'status' => 'unsatisfiable',
					'start'  => 0,
					'end'    => 0,
				);
			}
			$start = (int) $first;
			if ( '' !== $last ) {
				$end = min( (int) $last, $size - 1 );
			}
		}
		if ( $start > $end || $start >= $size ) {
			return array(
				'status' => 'unsatisfiable',
				'start'  => 0,
				'end'    => 0,
			);
		}
		return array(
			'status' => 'ok',
			'start'  => $start,
			'end'    => $end,
		);
	}

	/**
	 * Returns the immutable version identifier for a stored record.
	 *
	 * The ID is deterministic for one storage key/checksum pair — minting the
	 * same version twice is idempotent — and opaque: it carries no name, path,
	 * time or sequence content.
	 *
	 * @param string $storage_key Opaque `lps-file-*` storage key.
	 * @param string $sha256      Version SHA-256 checksum.
	 */
	public static function version_id_for( string $storage_key, string $sha256 ): string {
		return 'lpsver:' . hash( 'sha256', 'lps-version|' . $storage_key . '|' . $sha256 );
	}

	/**
	 * Returns the version registry row for one immutable version ID.
	 *
	 * @param string $version_id Immutable version identifier.
	 * @return array<string, mixed>|null
	 */
	public static function version_for_id( string $version_id ): ?array {
		$version_id = TeachingContracts::normalize_version_id( $version_id );
		if ( '' === $version_id ) {
			return null;
		}
		$wpdb  = self::database();
		$table = TeachingMigrations::table_names( $wpdb )['version_registry'];
		if ( ! self::table_exists( $table, $wpdb ) ) {
			return null;
		}
		/**
		 * Registry row for the addressed version.
		 *
		 * @var array<string, mixed>|null $row
		 */
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE version_id = %s LIMIT 1', $table, $version_id ), 'ARRAY_A' );
		return is_array( $row ) ? self::row_to_record( $row ) : null;
	}

	/**
	 * Returns the version registry row owning a storage key.
	 *
	 * @param string $storage_key Opaque `lps-file-*` storage key.
	 * @return array<string, mixed>|null
	 */
	public static function version_for_key( string $storage_key ): ?array {
		if ( ! TeachingStorage::key_valid( $storage_key ) ) {
			return null;
		}
		$wpdb  = self::database();
		$table = TeachingMigrations::table_names( $wpdb )['version_registry'];
		if ( ! self::table_exists( $table, $wpdb ) ) {
			return null;
		}
		/**
		 * Registry row owning the addressed storage key.
		 *
		 * @var array<string, mixed>|null $row
		 */
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE storage_key = %s LIMIT 1', $table, $storage_key ), 'ARRAY_A' );
		return is_array( $row ) ? self::row_to_record( $row ) : null;
	}

	/**
	 * Stores an upload in quarantine and mints its immutable version row.
	 *
	 * The version exists from intake so a resource can select it, but it never
	 * delivers bytes until the scan boundary clears it. `scan_error` reports
	 * the first scan attempt's outcome without failing the mint.
	 *
	 * @param string               $name        Client-supplied filename.
	 * @param string               $tmp_name    Temporary upload path.
	 * @param array<string, mixed> $config      Storage adapter configuration.
	 * @param bool                 $scan        Whether to run the scanner now.
	 * @return array{error: string|null, version: array<string, mixed>|null}
	 */
	public static function upload_version( string $name, string $tmp_name, array $config, bool $scan = true ): array {
		$stored = TeachingStorage::store( $name, $tmp_name, $config );
		if ( null !== $stored['error'] || null === $stored['record'] ) {
			return array(
				'error'   => $stored['error'] ?? 'lps_teaching_read_failed',
				'version' => null,
			);
		}
		$record     = $stored['record'];
		$version_id = self::registry_insert( $record );
		if ( null === $version_id ) {
			return array(
				'error'   => 'lps_teaching_registry_error',
				'version' => null,
			);
		}
		$record['version_id'] = $version_id;
		$scan_error           = null;
		if ( $scan ) {
			$scanned    = self::scan_record( $record, $config );
			$record     = $scanned['record'];
			$scan_error = $scanned['error'];
		}
		return array(
			'error'   => null,
			'version' => array_merge( self::version_response( $record ), array( 'scan_error' => $scan_error ) ),
		);
	}

	/**
	 * Drives one minted version through the configured scanner.
	 *
	 * Fail-closed: `pending` keeps `scanning`, `error`/exceptions/invalid
	 * verdicts return to `quarantined`, `infected` fails, and only `clean`
	 * reaches `cleared`. The registry row tracks the storage record's state so
	 * the resolver always sees the current scan position.
	 *
	 * @param string               $version_id Immutable version identifier.
	 * @param array<string, mixed> $config     Storage adapter configuration.
	 * @return array{error: string|null, version: array<string, mixed>|null}
	 */
	public static function scan_version( string $version_id, array $config ): array {
		$version = self::version_for_id( $version_id );
		if ( null === $version ) {
			return array(
				'error'   => 'lps_resource_version_missing',
				'version' => null,
			);
		}
		$scanned = self::scan_record( $version, $config );
		return array(
			'error'   => $scanned['error'],
			'version' => self::version_response( $scanned['record'] ),
		);
	}

	/**
	 * Creates one resource record attached to an existing offering.
	 *
	 * Authority-only (`pt-br`): resources keep their authored language and are
	 * never translated. The offering and optional unit are validated against
	 * persisted records, the canonical `resource_offering`/`resource_unit`
	 * rows are written, and an initial version selection is explicit.
	 *
	 * @param array<string, mixed> $input Boundary input.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function create_resource( array $input ): array|WP_Error {
		$locale = strtolower( trim( Policy::scalar_string( $input['locale'] ?? '' ) ) );
		$locale = isset( TranslationPolicy::locales()[ $locale ] ) ? $locale : TranslationPolicy::SOURCE_LOCALE;
		if ( TranslationPolicy::SOURCE_LOCALE !== $locale || 0 < Policy::sanitize_integer( $input['translation_of'] ?? 0 ) ) {
			return self::error( 'lps_teaching_locale_forbidden', 'Resources exist only on the Portuguese authority.', 'locale' );
		}
		$offering_id = TeachingContracts::authoritative_id( Policy::sanitize_integer( $input['offering_id'] ?? 0 ) );
		$offering    = 0 < $offering_id ? get_post( $offering_id ) : null;
		if ( ! $offering instanceof WP_Post || 'lps_offering' !== $offering->post_type || 'trash' === $offering->post_status ) {
			return self::error( 'lps_teaching_offering_invalid', 'The resource requires an existing offering record.', 'offering_id' );
		}
		$unit_id = Policy::sanitize_integer( $input['unit_id'] ?? 0 );
		if ( 0 < $unit_id ) {
			$unit = get_post( $unit_id );
			if ( ! $unit instanceof WP_Post || 'lps_unit' !== $unit->post_type || 'trash' === $unit->post_status ) {
				return self::error( 'lps_teaching_unit_invalid', 'The resource unit must be an existing unit record.', 'unit_id' );
			}
			$unit_rows     = Relationships::for_source( $unit_id, 'unit_offering' );
			$unit_offering = Policy::sanitize_integer( $unit_rows[0]['target_post_id'] ?? 0 );
			$unit_mismatch = TeachingContracts::resource_unit_error( $offering_id, $unit_offering );
			if ( null !== $unit_mismatch ) {
				return self::error( $unit_mismatch, 'The resource unit must belong to the resource offering.', 'unit_id' );
			}
		}
		$version_id   = TeachingContracts::normalize_version_id( $input['version_id'] ?? '' );
		$external_url = Policy::sanitize_url( $input['external_url'] ?? '' );
		if ( '' !== $version_id && '' !== $external_url ) {
			return self::error( 'lps_resource_version_and_url_conflict', 'A resource is one local version or one external URL, never both.', 'external_url' );
		}
		if ( '' !== $version_id && null === self::version_for_id( $version_id ) ) {
			return self::error( 'lps_resource_version_missing', 'The selected version does not exist.', 'version_id' );
		}
		$meta = self::sanitize_meta( self::meta_input( $input ) );
		if ( '' !== $external_url ) {
			$meta['_lps_external_url'] = $external_url;
		}
		$meta['_lps_locale']        = TranslationPolicy::SOURCE_LOCALE;
		$meta['_lps_release_state'] = 'draft';
		$postarr                    = array(
			'post_type'    => 'lps_resource',
			'post_status'  => 'draft',
			'post_title'   => Policy::sanitize_text( $input['title'] ?? '' ),
			'post_excerpt' => Policy::sanitize_text( $input['excerpt'] ?? '' ),
			'post_content' => Policy::scalar_string( $input['content'] ?? '' ),
			'meta_input'   => $meta,
		);
		$slug                       = sanitize_title( Policy::scalar_string( $input['slug'] ?? '' ) );
		if ( '' !== $slug ) {
			$postarr['post_name'] = $slug;
		}
		// The insert and the `wp_after_insert_post` identity completion write
		// system-owned fields (`_lps_locale`, `_lps_record_id`, provenance and
		// timestamps) that the scoped-role field guard correctly denies to
		// direct writes; the service lifts that one guard for its own write.
		remove_filter( 'update_post_metadata', array( Plugin::class, 'protect_role_meta' ), 11 );
		$post_id = wp_insert_post( $postarr, true );
		add_filter( 'update_post_metadata', array( Plugin::class, 'protect_role_meta' ), 11, 5 );
		if ( $post_id instanceof WP_Error ) {
			return $post_id;
		}
		$post_id = (int) $post_id;
		$rows    = array(
			'resource_offering' => array( self::relationship_row( $offering_id, 'attached-to', 1 ) ),
		);
		if ( 0 < $unit_id ) {
			$rows['resource_unit'] = array( self::relationship_row( $unit_id, 'supports', 1 ) );
		}
		foreach ( $rows as $relationship_type => $row_set ) {
			$written = Relationships::replace( $post_id, $relationship_type, $row_set );
			if ( $written instanceof WP_Error ) {
				self::cleanup_record( $post_id );
				return $written;
			}
		}
		if ( '' !== $version_id ) {
			$selected = self::select_version( $post_id, $version_id );
			if ( $selected instanceof WP_Error ) {
				self::cleanup_record( $post_id );
				return $selected;
			}
		}
		return self::resource_response( $post_id );
	}

	/**
	 * Replaces the version a resource serves, explicitly and audibly.
	 *
	 * The write is the only way a resource's selected bytes change: it copies
	 * the version row's storage identity into the resource's system metadata
	 * and appends an `edit` audit entry carrying both version IDs. Selecting
	 * the already-selected version is an idempotent no-op.
	 *
	 * @param int    $post_id    Resource record ID.
	 * @param string $version_id Candidate immutable version identifier.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function select_version( int $post_id, string $version_id ): array|WP_Error {
		$post = get_post( $post_id );
		if ( ! $post instanceof WP_Post || 'lps_resource' !== $post->post_type ) {
			return self::error( 'lps_teaching_record_invalid', 'The record does not exist or is not a resource.', 'id', 404 );
		}
		$normalized = TeachingContracts::normalize_version_id( $version_id );
		if ( '' === $normalized ) {
			return self::error( 'lps_invalid_version_id', 'The version identifier is malformed.', 'version_id' );
		}
		$version = self::version_for_id( $normalized );
		if ( null === $version ) {
			return self::error( 'lps_resource_version_missing', 'The selected version does not exist.', 'version_id' );
		}
		$previous = Policy::scalar_string( get_post_meta( $post_id, '_lps_version_id', true ) );
		if ( $previous === $normalized ) {
			return self::resource_response( $post_id );
		}
		self::system_meta( $post_id, '_lps_version_id', $normalized );
		self::system_meta( $post_id, '_lps_storage_key', Policy::scalar_string( $version['key'] ?? '' ) );
		self::system_meta( $post_id, '_lps_sha256', Policy::scalar_string( $version['checksum'] ?? '' ) );
		self::system_meta( $post_id, '_lps_byte_size', Policy::sanitize_integer( $version['bytes'] ?? 0 ) );
		self::system_meta( $post_id, '_lps_mime_type', Policy::scalar_string( $version['mime'] ?? '' ) );
		self::system_meta( $post_id, '_lps_scan_state', self::scan_state_for( $version ) );
		self::system_meta( $post_id, '_lps_scan_version', Policy::scalar_string( $version['scan_version'] ?? '' ) );
		self::system_meta( $post_id, '_lps_uploader_user_id', get_current_user_id() );
		self::system_meta( $post_id, '_lps_external_url', '' );
		Audit::record(
			'edit',
			$post_id,
			0,
			array(
				'field'               => '_lps_version_id',
				'previous_version_id' => $previous,
				'version_id'          => $normalized,
				'sha256'              => Policy::scalar_string( $version['checksum'] ?? '' ),
			)
		);
		return self::resource_response( $post_id );
	}

	/**
	 * Applies a scoped release or scheduling decision to one resource.
	 *
	 * `released` and `scheduled` both require approved rights/accessibility
	 * reviews and — for local versions — a cleared, clean-scanned version plus
	 * a complete storage configuration. The decision is audited as `publish`
	 * with the effective state and version identity.
	 *
	 * @param int                  $post_id    Resource record ID.
	 * @param string               $state      `released` or `scheduled`.
	 * @param string               $release_at Scheduled release timestamp (required for `scheduled`).
	 * @param array<string, mixed> $config     Storage adapter configuration.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function release_resource( int $post_id, string $state, string $release_at, array $config ): array|WP_Error {
		$post = get_post( $post_id );
		if ( ! $post instanceof WP_Post || 'lps_resource' !== $post->post_type ) {
			return self::error( 'lps_teaching_record_invalid', 'The record does not exist or is not a resource.', 'id', 404 );
		}
		if ( ! in_array( $state, array( 'released', 'scheduled' ), true ) ) {
			return self::error( 'lps_invalid_release_state', 'The release state must be released or scheduled.', 'release_state' );
		}
		$release_at = TeachingPolicy::normalize_datetime( $release_at );
		if ( 'scheduled' === $state && '' === $release_at ) {
			return self::error( 'lps_release_at_required', 'A scheduled release requires a release timestamp.', 'release_at' );
		}
		if ( 'approved' !== Policy::scalar_string( get_post_meta( $post_id, '_lps_rights_review', true ) ) ) {
			return self::error( 'lps_resource_rights_not_approved', 'The rights review must be approved before release.', 'rights_review' );
		}
		if ( 'approved' !== Policy::scalar_string( get_post_meta( $post_id, '_lps_accessibility_review', true ) ) ) {
			return self::error( 'lps_resource_accessibility_not_approved', 'The accessibility review must be approved before release.', 'accessibility_review' );
		}
		$version_id   = Policy::scalar_string( get_post_meta( $post_id, '_lps_version_id', true ) );
		$external_url = Policy::scalar_string( get_post_meta( $post_id, '_lps_external_url', true ) );
		if ( '' === $version_id && '' === $external_url ) {
			return self::error( 'lps_resource_version_or_url_required', 'The resource needs a version or an external URL before release.', 'version_id' );
		}
		if ( '' !== $version_id ) {
			$version = self::version_for_id( $version_id );
			if ( null === $version ) {
				return self::error( 'lps_resource_version_missing', 'The selected version does not exist.', 'version_id' );
			}
			$storage_error = TeachingStorage::release_error( $version, $config, self::is_production() );
			if ( null !== $storage_error ) {
				return self::error( $storage_error, 'The selected version is not cleared for release.', 'version_id' );
			}
		}
		self::system_meta( $post_id, '_lps_release_state', $state );
		self::system_meta( $post_id, '_lps_release_at', 'scheduled' === $state ? $release_at : '' );
		self::system_meta( $post_id, '_lps_withdrawn_at', '' );
		Audit::record(
			'publish',
			$post_id,
			0,
			array(
				'decision'    => 'release',
				'state'       => $state,
				'release_at'  => 'scheduled' === $state ? $release_at : '',
				'version_id'  => $version_id,
				'offering_id' => self::resource_offering_id( $post_id ),
			)
		);
		return self::resource_response( $post_id );
	}

	/**
	 * Applies a scoped withdrawal decision to one resource.
	 *
	 * Withdrawal is authoritative immediately — the next request denies
	 * delivery regardless of caches or scheduler state — and is audited as
	 * `unpublish` with the withdrawal timestamp.
	 *
	 * @param int $post_id Resource record ID.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function withdraw_resource( int $post_id ): array|WP_Error {
		$post = get_post( $post_id );
		if ( ! $post instanceof WP_Post || 'lps_resource' !== $post->post_type ) {
			return self::error( 'lps_teaching_record_invalid', 'The record does not exist or is not a resource.', 'id', 404 );
		}
		$withdrawn_at = gmdate( 'c' );
		self::system_meta( $post_id, '_lps_release_state', 'withdrawn' );
		self::system_meta( $post_id, '_lps_withdrawn_at', $withdrawn_at );
		Audit::record(
			'unpublish',
			$post_id,
			0,
			array(
				'decision'     => 'withdraw',
				'withdrawn_at' => $withdrawn_at,
				'version_id'   => Policy::scalar_string( get_post_meta( $post_id, '_lps_version_id', true ) ),
				'offering_id'  => self::resource_offering_id( $post_id ),
			)
		);
		return self::resource_response( $post_id );
	}

	/**
	 * Returns the public download path for one resource, or empty.
	 *
	 * The URL carries only the opaque record-ID token; whether it serves
	 * bytes is decided by the resolver on every request.
	 *
	 * @param int $post_id Resource record ID.
	 */
	public static function download_url( int $post_id ): string {
		$token = self::download_token( Policy::scalar_string( get_post_meta( $post_id, '_lps_record_id', true ) ) );
		return '' === $token ? '' : '/' . self::ROUTE_PREFIX . '/' . $token . '/';
	}

	/**
	 * Returns the opaque download token inside a record ID, or empty.
	 *
	 * @param string $record_id Stored `_lps_record_id`.
	 */
	public static function download_token( string $record_id ): string {
		if ( ! str_starts_with( $record_id, self::RECORD_ID_PREFIX ) ) {
			return '';
		}
		$token = substr( $record_id, strlen( self::RECORD_ID_PREFIX ) );
		return 1 === preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $token ) ? $token : '';
	}

	/**
	 * Returns the portable version response; never a path or storage root.
	 *
	 * @param array<string, mixed> $version Version record.
	 * @return array<string, mixed>
	 */
	public static function version_response( array $version ): array {
		return array(
			'version_id'    => Policy::scalar_string( $version['version_id'] ?? '' ),
			'state'         => Policy::scalar_string( $version['state'] ?? '' ),
			'scan_verdict'  => Policy::scalar_string( $version['scan_verdict'] ?? '' ),
			'sha256'        => Policy::scalar_string( $version['checksum'] ?? '' ),
			'bytes'         => Policy::sanitize_integer( $version['bytes'] ?? 0 ),
			'mime'          => Policy::scalar_string( $version['mime'] ?? '' ),
			'detected_mime' => Policy::scalar_string( $version['detected_mime'] ?? '' ),
			'download_name' => Policy::scalar_string( $version['download_name'] ?? '' ),
			'created_at'    => Policy::scalar_string( $version['created_at'] ?? '' ),
		);
	}

	/**
	 * Returns the portable resource response for one stored record.
	 *
	 * @param int $post_id Resource record ID.
	 * @return array<string, mixed>
	 */
	public static function resource_response( int $post_id ): array {
		$post = get_post( $post_id );
		return array(
			'id'                   => $post_id,
			'post_type'            => $post instanceof WP_Post ? $post->post_type : '',
			'status'               => $post instanceof WP_Post ? $post->post_status : '',
			'slug'                 => $post instanceof WP_Post ? $post->post_name : '',
			'title'                => $post instanceof WP_Post ? $post->post_title : '',
			'locale'               => Policy::scalar_string( get_post_meta( $post_id, '_lps_locale', true ) ),
			'state'                => Policy::scalar_string( get_post_meta( $post_id, '_lps_state', true ) ),
			'record_id'            => Policy::scalar_string( get_post_meta( $post_id, '_lps_record_id', true ) ),
			'offering_id'          => self::resource_offering_id( $post_id ),
			'resource_type'        => Policy::scalar_string( get_post_meta( $post_id, '_lps_resource_type', true ) ),
			'resource_language'    => Policy::scalar_string( get_post_meta( $post_id, '_lps_resource_language', true ) ),
			'version_id'           => Policy::scalar_string( get_post_meta( $post_id, '_lps_version_id', true ) ),
			'external_url'         => Policy::scalar_string( get_post_meta( $post_id, '_lps_external_url', true ) ),
			'release_state'        => Policy::scalar_string( get_post_meta( $post_id, '_lps_release_state', true ) ),
			'release_at'           => Policy::scalar_string( get_post_meta( $post_id, '_lps_release_at', true ) ),
			'withdrawn_at'         => Policy::scalar_string( get_post_meta( $post_id, '_lps_withdrawn_at', true ) ),
			'scan_state'           => Policy::scalar_string( get_post_meta( $post_id, '_lps_scan_state', true ) ),
			'rights_review'        => Policy::scalar_string( get_post_meta( $post_id, '_lps_rights_review', true ) ),
			'accessibility_review' => Policy::scalar_string( get_post_meta( $post_id, '_lps_accessibility_review', true ) ),
			'download_url'         => self::download_url( $post_id ),
		);
	}

	/**
	 * Returns the deployed storage configuration for the resource boundary.
	 *
	 * @return array<string, mixed>
	 */
	public static function storage_config(): array {
		return TeachingStorage::config_from_constants();
	}

	/**
	 * Streams a granted version's bytes and exits.
	 *
	 * @param array<string, mixed> $version  Granted version record.
	 * @param string               $path     Server-side storage path.
	 * @param bool                 $head_only Whether only headers are served.
	 */
	private static function stream( array $version, string $path, bool $head_only ): void {
		$size    = Policy::sanitize_integer( $version['bytes'] ?? 0 );
		$headers = TeachingStorage::download_headers( $version );
		$range   = self::parse_range(
			isset( $_SERVER['HTTP_RANGE'] ) && is_string( $_SERVER['HTTP_RANGE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_RANGE'] ) ) : '',
			$size
		);
		if ( 'unsatisfiable' === $range['status'] ) {
			status_header( 416 );
			header( 'Content-Range: bytes */' . $size );
			header( 'Cache-Control: no-store' );
			header( 'X-Content-Type-Options: nosniff' );
			exit;
		}
		$start  = 0;
		$end    = $size - 1;
		$status = 200;
		if ( 'ok' === $range['status'] ) {
			$start  = $range['start'];
			$end    = $range['end'];
			$status = 206;
		}
		status_header( $status );
		foreach ( $headers as $name => $value ) {
			if ( 'Content-Length' === $name ) {
				continue;
			}
			header( $name . ': ' . $value );
		}
		header( 'Accept-Ranges: bytes' );
		header( 'Content-Length: ' . ( $end - $start + 1 ) );
		if ( 206 === $status ) {
			header( 'Content-Range: bytes ' . $start . '-' . $end . '/' . $size );
		}
		$checksum = Policy::scalar_string( $version['checksum'] ?? '' );
		if ( 1 === preg_match( '/^[0-9a-f]{64}$/', $checksum ) ) {
			header( 'Digest: sha-256=:' . base64_encode( (string) hex2bin( $checksum ) ) . ':' );
		}
		if ( $head_only ) {
			exit;
		}
		$handle = fopen( $path, 'rb' );
		if ( false === $handle ) {
			exit;
		}
		if ( 0 < $start ) {
			fseek( $handle, $start );
		}
		$remaining = $end - $start + 1;
		while ( $remaining > 0 && ! feof( $handle ) ) {
			$chunk = fread( $handle, min( 8192, $remaining ) );
			if ( false === $chunk ) {
				break;
			}
			$remaining -= strlen( $chunk );
			echo $chunk; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Binary download stream.
		}
		fclose( $handle );
		exit;
	}

	/**
	 * Marks the request a real 404 and lets the template pipeline render it.
	 */
	private static function deny(): void {
		global $wp_query;
		if ( $wp_query instanceof \WP_Query ) {
			$wp_query->set_404();
		}
		status_header( 404 );
		nocache_headers();
	}

	/**
	 * Returns the resource post addressed by a download token, or null.
	 *
	 * @param string $token Opaque download token.
	 */
	private static function resource_for_token( string $token ): ?WP_Post {
		if ( 1 !== preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $token ) ) {
			return null;
		}
		$ids     = get_posts(
			array(
				'post_type'      => 'lps_resource',
				'post_status'    => array( 'publish', 'draft', 'pending', 'future', 'private', 'lps_archived' ),
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- the opaque record ID is the public download key.
					array(
						'key'   => '_lps_record_id',
						'value' => self::RECORD_ID_PREFIX . $token,
					),
				),
			)
		);
		$post_id = Policy::sanitize_integer( $ids[0] ?? 0 );
		$post    = 0 < $post_id ? get_post( $post_id ) : null;
		return $post instanceof WP_Post ? $post : null;
	}

	/**
	 * Runs the storage scanner for a minted version and syncs the registry row.
	 *
	 * @param array<string, mixed> $record Version record.
	 * @param array<string, mixed> $config Storage adapter configuration.
	 * @return array{error: string|null, record: array<string, mixed>}
	 */
	private static function scan_record( array $record, array $config ): array {
		$scanned = TeachingStorage::scan( $record, $config, self::is_production() );
		$record  = $scanned['record'];
		self::registry_update_state( $record );
		return array(
			'error'  => $scanned['error'],
			'record' => $record,
		);
	}

	/**
	 * Inserts one version row for a stored record; idempotent on replay.
	 *
	 * @param array<string, mixed> $record Stored record.
	 */
	private static function registry_insert( array $record ): ?string {
		$version_id = self::version_id_for(
			Policy::scalar_string( $record['key'] ?? '' ),
			Policy::scalar_string( $record['checksum'] ?? '' )
		);
		$existing   = self::version_for_id( $version_id );
		if ( null !== $existing ) {
			return $version_id;
		}
		$wpdb     = self::database();
		$table    = TeachingMigrations::table_names( $wpdb )['version_registry'];
		$inserted = $wpdb->insert(
			$table,
			array(
				'version_id'    => $version_id,
				'storage_key'   => Policy::scalar_string( $record['key'] ?? '' ),
				'state'         => Policy::scalar_string( $record['state'] ?? 'quarantined' ),
				'scan_verdict'  => Policy::scalar_string( $record['scan_verdict'] ?? 'none' ),
				'sha256'        => Policy::scalar_string( $record['checksum'] ?? '' ),
				'byte_size'     => Policy::sanitize_integer( $record['bytes'] ?? 0 ),
				'mime_type'     => Policy::scalar_string( $record['mime'] ?? '' ),
				'detected_mime' => Policy::scalar_string( $record['detected_mime'] ?? '' ),
				'extension'     => Policy::scalar_string( $record['extension'] ?? '' ),
				'original_name' => Policy::scalar_string( $record['original_name'] ?? '' ),
				'download_name' => Policy::scalar_string( $record['download_name'] ?? '' ),
				'created_at'    => Policy::scalar_string( $record['created_at'] ?? '' ),
			),
			array( '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s' )
		);
		return false === $inserted ? null : $version_id;
	}

	/**
	 * Syncs the mutable scan fields of a version row after a scan transition.
	 *
	 * Only `state` and `scan_verdict` ever change; the version's identity,
	 * checksum, size and names are immutable for the row's lifetime.
	 *
	 * @param array<string, mixed> $record Version record.
	 */
	private static function registry_update_state( array $record ): void {
		$version_id = Policy::scalar_string( $record['version_id'] ?? '' );
		if ( '' === $version_id ) {
			return;
		}
		$wpdb = self::database();
		$wpdb->update(
			TeachingMigrations::table_names( $wpdb )['version_registry'],
			array(
				'state'        => Policy::scalar_string( $record['state'] ?? 'quarantined' ),
				'scan_verdict' => Policy::scalar_string( $record['scan_verdict'] ?? 'none' ),
			),
			array( 'version_id' => $version_id ),
			array( '%s', '%s' ),
			array( '%s' )
		);
	}

	/**
	 * Maps a registry row back to the storage-record shape.
	 *
	 * @param array<string, mixed> $row Registry row.
	 * @return array<string, mixed>
	 */
	private static function row_to_record( array $row ): array {
		return array(
			'version_id'    => Policy::scalar_string( $row['version_id'] ?? '' ),
			'key'           => Policy::scalar_string( $row['storage_key'] ?? '' ),
			'kind'          => TeachingStorage::KIND_TEACHING,
			'state'         => Policy::scalar_string( $row['state'] ?? 'quarantined' ),
			'scan_verdict'  => Policy::scalar_string( $row['scan_verdict'] ?? 'none' ),
			'checksum'      => Policy::scalar_string( $row['sha256'] ?? '' ),
			'bytes'         => Policy::sanitize_integer( $row['byte_size'] ?? 0 ),
			'mime'          => Policy::scalar_string( $row['mime_type'] ?? '' ),
			'detected_mime' => Policy::scalar_string( $row['detected_mime'] ?? '' ),
			'extension'     => Policy::scalar_string( $row['extension'] ?? '' ),
			'original_name' => Policy::scalar_string( $row['original_name'] ?? '' ),
			'download_name' => Policy::scalar_string( $row['download_name'] ?? '' ),
			'created_at'    => Policy::scalar_string( $row['created_at'] ?? '' ),
		);
	}

	/**
	 * Maps a version's storage state/verdict to the resource scan-state field.
	 *
	 * @param array<string, mixed> $version Version record.
	 */
	private static function scan_state_for( array $version ): string {
		$state   = Policy::scalar_string( $version['state'] ?? '' );
		$verdict = Policy::scalar_string( $version['scan_verdict'] ?? '' );
		if ( 'cleared' === $state && 'clean' === $verdict ) {
			return 'clean';
		}
		if ( 'failed' === $state ) {
			return 'suspect';
		}
		if ( 'error' === $verdict ) {
			return 'error';
		}
		return 'pending';
	}

	/**
	 * Returns the offering record ID owning a resource, or zero.
	 *
	 * @param int $post_id Resource record ID.
	 */
	private static function resource_offering_id( int $post_id ): int {
		$rows = Relationships::for_source( $post_id, 'resource_offering' );
		return Policy::sanitize_integer( $rows[0]['target_post_id'] ?? 0 );
	}

	/**
	 * Writes system-owned metadata past the scoped-role field guard.
	 *
	 * Version identity, storage keys, scan mirrors and uploader linkage are
	 * boundary-written: the scoped-role allowlist correctly denies them to
	 * direct writes, so the service lifts that one guard for its own audited
	 * writes and restores it immediately.
	 *
	 * @param int    $post_id Record ID.
	 * @param string $key     Metadata key.
	 * @param mixed  $value   Sanitized value.
	 */
	private static function system_meta( int $post_id, string $key, mixed $value ): void {
		remove_filter( 'update_post_metadata', array( Plugin::class, 'protect_role_meta' ), 11 );
		update_post_meta( $post_id, $key, $value );
		add_filter( 'update_post_metadata', array( Plugin::class, 'protect_role_meta' ), 11, 5 );
	}

	/**
	 * Removes every trace of a failed create: relationships, then the post.
	 *
	 * @param int $post_id Record database ID.
	 */
	private static function cleanup_record( int $post_id ): void {
		$wpdb  = self::database();
		$table = Migrations::table_names( $wpdb )['relationships'];
		$wpdb->delete( $table, array( 'source_post_id' => $post_id ), array( '%d' ) );
		wp_delete_post( $post_id, true );
	}

	/**
	 * Merges the `meta` object with top-level unprefixed field names.
	 *
	 * @param array<string, mixed> $input Boundary input.
	 * @return array<string, mixed>
	 */
	private static function meta_input( array $input ): array {
		$meta = array();
		if ( is_array( $input['meta'] ?? null ) ) {
			foreach ( $input['meta'] as $key => $value ) {
				if ( is_string( $key ) ) {
					$meta[ $key ] = $value;
				}
			}
		}
		foreach ( self::AUTHORITY_META as $key ) {
			$plain = substr( $key, 5 );
			if ( ! array_key_exists( $key, $meta ) && array_key_exists( $plain, $input ) ) {
				$meta[ $key ] = $input[ $plain ];
			}
		}
		return $meta;
	}

	/**
	 * Sanitizes boundary metadata through the registered field normalizers.
	 *
	 * @param array<string, mixed> $input Boundary meta input.
	 * @return array<string, mixed>
	 */
	private static function sanitize_meta( array $input ): array {
		$fields = Contracts::meta_fields()['lps_resource'] ?? array();
		$meta   = array();
		foreach ( self::AUTHORITY_META as $key ) {
			if ( ! array_key_exists( $key, $input ) ) {
				continue;
			}
			$meta[ $key ] = isset( $fields[ $key ]['sanitize_callback'] )
				? call_user_func( $fields[ $key ]['sanitize_callback'], $input[ $key ] )
				: Policy::scalar_string( $input[ $key ] );
		}
		return $meta;
	}

	/**
	 * Builds one canonical relationship row.
	 *
	 * @param int    $target_post_id Target record ID.
	 * @param string $role           Relationship role.
	 * @param int    $sort_order     Ordered position.
	 * @return array{target_post_id: int, relationship_role: string, sort_order: int, start_date: string, end_date: string, public_visibility: bool}
	 */
	private static function relationship_row( int $target_post_id, string $role, int $sort_order ): array {
		return array(
			'target_post_id'    => $target_post_id,
			'relationship_role' => $role,
			'sort_order'        => $sort_order,
			'start_date'        => '',
			'end_date'          => '',
			'public_visibility' => true,
		);
	}

	/**
	 * Returns whether the deployed environment enforces production gates.
	 */
	private static function is_production(): bool {
		return function_exists( 'wp_get_environment_type' ) && 'production' === wp_get_environment_type();
	}

	/**
	 * Returns whether a path sits under the download route prefix.
	 *
	 * @param string $path Public request path.
	 */
	private static function is_download_path( string $path ): bool {
		return str_starts_with( $path, '/' . self::ROUTE_PREFIX . '/' );
	}

	/** Returns the sanitized path of the current request. */
	private static function request_path(): string {
		if ( ! isset( $_SERVER['REQUEST_URI'] ) || ! is_string( $_SERVER['REQUEST_URI'] ) ) {
			return '/';
		}
		$path = wp_parse_url( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ), PHP_URL_PATH );
		return is_string( $path ) ? $path : '/';
	}

	/**
	 * Checks one registry table without mutating it.
	 *
	 * @param string $table    Fully qualified table name.
	 * @param wpdb   $database WordPress database adapter.
	 */
	private static function table_exists( string $table, wpdb $database ): bool {
		$pattern = $database->esc_like( $table );
		$found   = $database->get_var( $database->prepare( 'SHOW TABLES LIKE %s', $pattern ) );
		return is_string( $found ) && $table === $found;
	}

	/**
	 * Returns the initialized WordPress database adapter.
	 *
	 * @throws \RuntimeException When WordPress has not initialized wpdb.
	 */
	private static function database(): wpdb {
		global $wpdb;
		if ( ! $wpdb instanceof wpdb ) {
			throw new \RuntimeException( 'WordPress database adapter is unavailable.' );
		}
		return $wpdb;
	}

	/**
	 * Builds one typed boundary error.
	 *
	 * @param string $code    Stable machine error code.
	 * @param string $message Human-readable message.
	 * @param string $field   Machine field name.
	 * @param int    $status  HTTP status.
	 */
	private static function error( string $code, string $message, string $field, int $status = 400 ): WP_Error {
		return new WP_Error(
			$code,
			$message,
			array(
				'status' => $status,
				'field'  => $field,
			)
		);
	}
}
// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
