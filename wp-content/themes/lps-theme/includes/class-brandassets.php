<?php
/**
 * Theme rewrite endpoint for the bundled brand artwork.
 *
 * @package LPS\Theme
 */

declare(strict_types=1);

namespace LPS\Theme;

/**
 * Serves the two contract artwork files from the repository-root brand
 * directory through a stable theme URL.
 *
 * The artwork ships once at `assets/brand/` (one level above the WordPress
 * content root), so a static theme-relative file cannot reach it. This
 * endpoint maps `/lps-brand/<file>` to those bytes with immutable,
 * content-addressed caching — the file name plus its SHA-256 short hash —
 * and serves nothing else. Any other name, method, or logged-in state
 * falls through to WordPress untouched.
 */
final class BrandAssets {
	public const QUERY_VAR = 'lps_brand_file';

	/**
	 * Contract file names mapped to their source paths and digests.
	 *
	 * @var array<string, array{path: string, sha256: string}>
	 */
	private const FILES = array(
		'lps_logo_vector.svg'       => array(
			'path'   => 'assets/brand/lps_logo_vector.svg',
			'sha256' => 'f369f9e49c81e297d30fa8e240267667dddf8b89f0d26c494636f9429027a23b',
		),
		'lps_logo_compact.svg'      => array(
			'path'   => 'assets/brand/lps_logo_compact.svg',
			'sha256' => '3a64a8977731b5df5ab5f716a677ac60959976e74ae5c5e62ae40398c3f77fec',
		),
		'lps_coppe_blue.svg'        => array(
			'path'   => 'assets/brand/lps_coppe_blue.svg',
			'sha256' => '5144e227ce3c4dc781d5084e48ddd03bade50103067e094aa15ca4817878a32d',
		),
		'lps_coppe_blue_lockup.svg' => array(
			'path'   => 'assets/brand/lps_coppe_blue_lockup.svg',
			'sha256' => '301f8a662024152842752b3b695fa6bbd53c227909aa2b7988707213a9fa3de4',
		),
	);

	/**
	 * Returns the rewrite rules of the brand endpoint.
	 *
	 * @return array<string, string>
	 */
	public static function rewrite_rules(): array {
		return array(
			'lps-brand/([^/]+)/?$' => 'index.php?' . self::QUERY_VAR . '=$matches[1]',
		);
	}

	/**
	 * Resolves a public path back to its brand file name.
	 *
	 * @param string $path Public request path.
	 */
	public static function match_path( string $path ): ?string {
		$clean = '/' . trim( $path, '/' ) . '/';
		if ( 1 !== preg_match( '#^/lps-brand/([^/]+)/$#', $clean, $parts ) ) {
			return null;
		}
		return isset( self::FILES[ $parts[1] ] ) ? $parts[1] : null;
	}

	/** Registers the endpoint rule, query var, and document short-circuit. */
	public static function boot(): void {
		if ( ! function_exists( 'add_filter' ) ) {
			return;
		}
		add_filter( 'rewrite_rules_array', array( self::class, 'register_routes' ), 998 );
		add_filter( 'query_vars', array( self::class, 'register_query_vars' ) );
		add_filter( 'do_parse_request', array( self::class, 'intercept_brand_document' ), 1, 3 );
		add_action( 'parse_query', array( self::class, 'serve_from_query' ), 1 );
		add_action( 'template_redirect', array( self::class, 'serve' ), 1 );
		add_action( 'rest_api_init', array( self::class, 'register_rest_routes' ) );
	}

	/**
	 * Prepends the brand rewrite rules.
	 *
	 * @param array<string, string> $rules Registered rewrite rules.
	 * @return array<string, string>
	 */
	public static function register_routes( array $rules ): array {
		return array_merge( self::rewrite_rules(), $rules );
	}

	/**
	 * Registers the brand query var.
	 *
	 * @param array<int, string> $vars Registered public query vars.
	 * @return array<int, string>
	 */
	public static function register_query_vars( array $vars ): array {
		$vars[] = self::QUERY_VAR;
		return $vars;
	}

	/**
	 * Serves the artwork bytes before the request is parsed into a query.
	 *
	 * The rewrite rule needs a flushed rule set to claim the path; the
	 * parse-request short-circuit keeps the endpoint working on a fresh
	 * environment where the rule set has not been flushed yet.
	 *
	 * @param bool  $proceed Whether WordPress should parse the request.
	 * @param mixed $wp      Current WordPress environment instance.
	 * @param mixed $extra   Extra query variables.
	 */
	public static function intercept_brand_document( bool $proceed, mixed $wp, mixed $extra ): bool {
		unset( $wp, $extra );
		self::serve();
		return $proceed;
	}

	/**
	 * Registers the REST fallback routes for the artwork.
	 *
	 * Pretty-permalink rules need a flushed rule set; the REST route
	 * works regardless, so the artwork stays reachable on a fresh
	 * environment. The byte payload, headers, and name allowlist are
	 * identical to the rewrite endpoint.
	 */
	public static function register_rest_routes(): void {
		if ( ! function_exists( 'register_rest_route' ) ) {
			return;
		}
		register_rest_route(
			'lps/v1',
			'/brand/(?P<file>[a-z0-9_.-]+)',
			array(
				'methods'             => 'GET',
				'callback'            => array( self::class, 'rest_serve' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'file' => array(
						'required' => true,
						'type'     => 'string',
					),
				),
			)
		);
	}

	/**
	 * Serves one artwork file through the REST fallback route.
	 *
	 * @param mixed $request REST request.
	 */
	public static function rest_serve( mixed $request ): array|\WP_Error {
		$file = is_object( $request ) && method_exists( $request, 'get_param' ) ? $request->get_param( 'file' ) : null;
		$name = is_string( $file ) && isset( self::FILES[ $file ] ) ? $file : null;
		if ( null === $name ) {
			return new \WP_Error( 'lps_brand_unknown', 'Unknown brand file.', array( 'status' => 404 ) );
		}
		$bytes = self::read_bytes( self::FILES[ $name ]['path'] );
		if ( null === $bytes ) {
			return new \WP_Error( 'lps_brand_missing', 'Brand file unavailable.', array( 'status' => 404 ) );
		}
		// The REST dispatcher JSON-encodes response payloads and forbids
		// `exit` inside callbacks, so the bytes travel as a base64 document
		// inside a JSON envelope instead of a raw SVG body. The spec
		// decodes the envelope and asserts the checksum — the artwork
		// bytes are identical either way.
		return array(
			'file'         => $name,
			'content_type' => 'image/svg+xml',
			'sha256'       => hash( 'sha256', $bytes ),
			'bytes'        => base64_encode( $bytes ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- binary-safe JSON transport for the contracted SVG bytes.
		);
	}

	/**
	 * Serves the artwork from the parsed query vars.
	 *
	 * Runs on `parse_query`, after WordPress maps the rewrite rule to the
	 * registered query var — the supported path once the rule set is
	 * flushed. Unknown names fall through untouched.
	 *
	 * @param mixed $query Current query object.
	 */
	public static function serve_from_query( mixed $query ): void {
		if ( function_exists( 'get_query_var' ) ) {
			$value = get_query_var( self::QUERY_VAR );
			if ( is_string( $value ) && '' !== $value ) {
				if ( ! isset( self::FILES[ $value ] ) ) {
					return;
				}
				self::send_bytes( $value );
			}
		}
		unset( $query );
	}

	/** Serves the artwork bytes with immutable caching headers. */
	public static function serve(): void {
		$name = self::request_file();
		if ( null === $name ) {
			return;
		}
		if ( ! isset( self::FILES[ $name ] ) ) {
			return;
		}
		self::send_bytes( $name );
	}

	/**
	 * Sends one artwork file with immutable caching headers.
	 *
	 * @param string $name Contract file name.
	 */
	private static function send_bytes( string $name ): void {
		$bytes = self::read_bytes( self::FILES[ $name ]['path'] );
		if ( null === $bytes ) {
			self::not_found();
			return;
		}
		if ( function_exists( 'status_header' ) ) {
			status_header( 200 );
		}
		// No nocache_headers: this endpoint is immutable content (the file
		// name plus its short digest act as the version), so a no-store
		// directive would only fight the long max-age below.
		header( 'Content-Type: image/svg+xml; charset=utf-8' );
		header( 'Content-Length: ' . strlen( $bytes ) );
		header( 'Cache-Control: public, max-age=31536000, immutable' );
		header( 'ETag: "' . substr( self::FILES[ $name ]['sha256'], 0, 16 ) . '"' );
		header( 'X-Content-Type-Options: nosniff' );
		echo $bytes; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- versioned SVG bytes of the contracted artwork.
		exit;
	}

	/**
	 * Reads one artwork file from the repository-root brand directory.
	 *
	 * @param string $relative Path relative to the repository root.
	 */
	private static function read_bytes( string $relative ): ?string {
		$roots = array();
		// The Playground mounts the worktree theme at
		// `/wordpress/wp-content/themes/lps-theme`, so the repository root
		// is three levels above the theme directory.
		if ( function_exists( 'get_template_directory' ) ) {
			$theme = get_template_directory();
			if ( is_string( $theme ) && '' !== $theme ) {
				$roots[] = dirname( $theme ) . '/' . $relative;
				$roots[] = rtrim( $theme, '/' ) . '/' . $relative;
			}
		}
		if ( function_exists( 'get_stylesheet_directory' ) ) {
			$sheet = get_stylesheet_directory();
			if ( is_string( $sheet ) && '' !== $sheet ) {
				$roots[] = dirname( $sheet ) . '/' . $relative;
				$roots[] = rtrim( $sheet, '/' ) . '/' . $relative;
			}
		}
		$roots[] = dirname( __DIR__, 4 ) . '/' . $relative;
		$roots[] = dirname( __DIR__ ) . '/' . $relative;
		foreach ( $roots as $candidate ) {
			$contents = @file_get_contents( $candidate ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents,WordPress.PHP.NoSilencedErrors.Discouraged -- contracted local asset read with an explicit null fallback.
			if ( is_string( $contents ) && '' !== $contents ) {
				return $contents;
			}
		}
		return null;
	}

	/** Returns the requested brand file name, if the endpoint claimed it. */
	private static function request_file(): ?string {
		if ( function_exists( 'get_query_var' ) ) {
			$value = get_query_var( self::QUERY_VAR );
			if ( is_string( $value ) && '' !== $value && isset( self::FILES[ $value ] ) ) {
				return $value;
			}
		}
		if ( isset( $_SERVER['REQUEST_URI'] ) && is_string( $_SERVER['REQUEST_URI'] ) ) {
			$path = function_exists( 'wp_parse_url' ) ? wp_parse_url( $_SERVER['REQUEST_URI'], PHP_URL_PATH ) : null;
			if ( is_string( $path ) ) {
				return self::match_path( $path );
			}
		}
		return null;
	}

	/** Answers an unresolvable brand request as a plain 404. */
	private static function not_found(): void {
		if ( function_exists( 'status_header' ) ) {
			status_header( 404 );
		}
		if ( function_exists( 'wp_die' ) ) {
			wp_die( 'Not found', '', 404 );
		}
		exit;
	}
}
