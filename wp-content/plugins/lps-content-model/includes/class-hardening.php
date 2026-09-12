<?php
/**
 * Early-loading privacy and production security boundary.
 *
 * @package LPS\ContentModel
 */

declare(strict_types=1);

namespace LPS\ContentModel;

use WP_Error;

/** Loaded by the must-use plugin, before any optional plugin executes. */
final class Hardening {
	public const PLUGINS = array( 'lps-content-model/lps-content-model.php', 'polylang/polylang.php', 'two-factor/two-factor.php' );

	/**
	 * Per-response CSP token, never persisted.
	 *
	 * @var string
	 */
	private static string $nonce = '';

	/** Installs controls independently of the optional plugin activation state. */
	public static function boot(): void {
		if ( ! defined( 'PLL_COOKIE' ) ) {
			define( 'PLL_COOKIE', false );
		}
		add_filter( 'option_active_plugins', array( self::class, 'allowed_plugins' ), PHP_INT_MAX );
		add_filter( 'site_option_active_sitewide_plugins', array( self::class, 'network_plugins' ), PHP_INT_MAX );
		add_filter( 'pre_update_option_active_plugins', array( self::class, 'allowed_plugins' ), PHP_INT_MAX );
		add_filter( 'xmlrpc_enabled', '__return_false' );
		add_filter( 'xmlrpc_methods', '__return_empty_array' );
		add_filter( 'wp_is_application_passwords_available', '__return_false', PHP_INT_MAX );
		add_filter( 'pre_option_show_avatars', '__return_zero' );
		add_filter( 'wp_resource_hints', array( self::class, 'resource_hints' ) );
		add_filter( 'wp_inline_script_attributes', array( self::class, 'script_attributes' ) );
		add_filter( 'wp_script_attributes', array( self::class, 'script_attributes' ) );
		add_filter( 'pre_http_request', array( self::class, 'runtime_http' ), 10, 3 );
		add_filter( 'map_meta_cap', array( self::class, 'deny_runtime_install' ), PHP_INT_MAX, 2 );
		add_filter( 'render_block_lps-theme/trust', array( self::class, 'append_privacy_notice' ) );
		add_filter( 'rest_pre_dispatch', array( self::class, 'protect_accounts' ), 5, 3 );
		add_action( 'init', array( self::class, 'send_headers' ), 0 );
		// Core re-sends weaker frame/referrer headers on the credential and editor surfaces after `init`.
		add_action( 'login_init', array( self::class, 'send_headers' ), PHP_INT_MAX );
		add_action( 'admin_init', array( self::class, 'send_headers' ), PHP_INT_MAX );
		add_action( 'init', array( self::class, 'production_gate' ), 0 );
		add_action( 'init', array( self::class, 'remove_unused_features' ) );
		add_action( 'init', array( self::class, 'register_public_rest' ), 20 );
	}

	/**
	 * Filters before WordPress includes the active plugin files.
	 *
	 * @param array<int, string> $plugins Configured plugin paths.
	 * @return array<int, string>
	 */
	public static function allowed_plugins( array $plugins ): array {
		return array_values( array_intersect( $plugins, self::PLUGINS ) );
	}

	/**
	 * Enforces the same allowlist for network activation.
	 *
	 * @param array<string, int> $plugins Network plugin timestamps.
	 * @return array<string, int>
	 */
	public static function network_plugins( array $plugins ): array {
		return array_intersect_key( $plugins, array_flip( self::PLUGINS ) );
	}

	/**
	 * No DNS prefetch or preconnect to third parties.
	 *
	 * @return array<int, string>
	 */
	public static function resource_hints(): array {
		return array();
	}

	/** Removes public WordPress integrations unused by this site. */
	public static function remove_unused_features(): void {
		remove_action( 'wp_head', 'print_emoji_detection_script', 7 );
		remove_action( 'wp_enqueue_scripts', 'wp_enqueue_emoji_styles' );
		remove_action( 'wp_head', 'wp_generator' );
		add_filter( 'the_generator', '__return_empty_string' );
		remove_action( 'wp_head', 'rsd_link' );
		remove_action( 'wp_head', 'wlwmanifest_link' );
		remove_action( 'wp_head', 'wp_oembed_add_discovery_links' );
		remove_action( 'wp_head', 'wp_oembed_add_host_js' );
		remove_filter( 'the_content', array( $GLOBALS['wp_embed'], 'autoembed' ), 8 );
	}

	/**
	 * Runtime cannot install code or write unfiltered HTML/uploads.
	 *
	 * @param array<int, string> $caps Required capabilities.
	 * @param string             $cap Requested capability.
	 * @return array<int, string>
	 */
	public static function deny_runtime_install( array $caps, string $cap ): array {
		if ( in_array( $cap, array( 'install_plugins', 'update_plugins', 'delete_plugins', 'activate_plugins', 'install_themes', 'update_themes', 'delete_themes', 'edit_plugins', 'edit_themes', 'update_core', 'unfiltered_html', 'unfiltered_upload' ), true ) ) {
			return array( 'do_not_allow' );
		}
		return $caps;
	}

	/**
	 * Public and CMS web requests are self-hosted; maintenance runs outside web requests.
	 *
	 * @param mixed                $result Existing short circuit.
	 * @param array<string, mixed> $args HTTP arguments.
	 * @param string               $url Destination.
	 * @return mixed
	 */
	public static function runtime_http( mixed $result, array $args, string $url ): mixed {
		unset( $args );
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			return $result;
		}
		$origin = wp_parse_url( home_url(), PHP_URL_HOST );
		if ( wp_parse_url( $url, PHP_URL_HOST ) === $origin && in_array( wp_parse_url( $url, PHP_URL_SCHEME ), array( 'http', 'https' ), true ) ) {
			return $result;
		}
		return new WP_Error( 'lps_external_runtime_request_denied', 'External runtime HTTP requests are not permitted.' );
	}

	/** Generates the CSP token once per request. */
	private static function nonce(): string {
		if ( '' === self::$nonce ) {
			self::$nonce = bin2hex( random_bytes( 24 ) );
		}
		return self::$nonce;
	}

	/**
	 * Adds the nonce to WordPress-generated script tags, never to stored content.
	 *
	 * @param array<string, mixed> $attributes Script attributes.
	 * @return array<string, mixed>
	 */
	public static function script_attributes( array $attributes ): array {
		$attributes['nonce'] = self::nonce();
		return $attributes;
	}

	/**
	 * Policy shared by the live response and tests. Inline styles are required by core blocks.
	 *
	 * @param bool   $admin Whether same-origin editor frames are needed.
	 * @param bool   $tls Whether the request is HTTPS.
	 * @param string $nonce Generated script nonce.
	 * @return array<string, string>
	 */
	public static function headers( bool $admin, bool $tls, string $nonce ): array {
		$frames  = $admin ? "'self'" : "'none'";
		$headers = array(
			'Content-Security-Policy'           => "default-src 'none'; script-src 'self' 'nonce-$nonce'; script-src-attr 'none'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; font-src 'self'; media-src 'self'; connect-src 'self'; frame-src $frames; frame-ancestors 'none'; base-uri 'none'; form-action 'self'; object-src 'none'",
			'X-Content-Type-Options'            => 'nosniff',
			'X-Frame-Options'                   => 'DENY',
			'Referrer-Policy'                   => 'no-referrer',
			'Permissions-Policy'                => 'camera=(), microphone=(), geolocation=(), payment=(), usb=(), browsing-topics=()',
			'X-Permitted-Cross-Domain-Policies' => 'none',
		);
		if ( $tls ) {
			$headers['Strict-Transport-Security'] = 'max-age=31536000';
		}
		return $headers;
	}

	/** Emits headers on public, REST, admin and login responses. */
	public static function send_headers(): void {
		foreach ( self::headers( is_admin(), is_ssl(), self::nonce() ) as $name => $value ) {
			header( $name . ': ' . $value );
		}
	}

	/**
	 * Reads the environment the deployment actually declares.
	 *
	 * `wp_get_environment_type()` answers `production` when nothing is declared, so it cannot
	 * distinguish a real production host from a fresh install that has declared nothing at all.
	 */
	public static function declared_environment(): string {
		if ( defined( 'WP_ENVIRONMENT_TYPE' ) && is_string( WP_ENVIRONMENT_TYPE ) ) {
			return WP_ENVIRONMENT_TYPE;
		}
		$declared = getenv( 'WP_ENVIRONMENT_TYPE' );
		return is_string( $declared ) ? $declared : '';
	}

	/**
	 * Only a positively declared production environment is enforced; anything else, including an
	 * undeclared fresh install, must remain installable.
	 *
	 * @param string $declared Declared environment name.
	 */
	public static function enforces_production( string $declared ): bool {
		return 'production' === strtolower( trim( $declared ) );
	}

	/** Reports whether the production security prerequisites are provably in place. */
	public static function provisioning_ready(): bool {
		$option = get_option( 'active_plugins', array() );
		$active = is_array( $option ) ? array_filter( $option, 'is_string' ) : array();
		return array() === array_diff( self::PLUGINS, $active )
			&& defined( 'POLYLANG_VERSION' ) && '3.8.7' === POLYLANG_VERSION
			&& defined( 'TWO_FACTOR_VERSION' ) && '0.16.0' === TWO_FACTOR_VERSION
			&& defined( 'LPS_EDGE_SECURITY_VERIFIED' ) && true === LPS_EDGE_SECURITY_VERIFIED
			&& defined( 'DISALLOW_FILE_MODS' ) && DISALLOW_FILE_MODS
			&& defined( 'DISALLOW_FILE_EDIT' ) && DISALLOW_FILE_EDIT
			&& defined( 'WP_DEBUG' ) && ! WP_DEBUG
			&& defined( 'WP_DEBUG_DISPLAY' ) && ! WP_DEBUG_DISPLAY;
	}

	/** Fails closed instead of claiming that unprovisioned production infrastructure is safe. */
	public static function production_gate(): void {
		if ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST ) {
			wp_die( 'XML-RPC is disabled.', 'Forbidden', array( 'response' => 403 ) );
		}
		if ( ! self::enforces_production( self::declared_environment() ) ) {
			return;
		}
		if ( ! self::provisioning_ready() ) {
			wp_die( 'Production security provisioning is incomplete.', 'Service unavailable', array( 'response' => 503 ) );
		}
	}

	/**
	 * Denies executable extensions anywhere in the basename, not only the last suffix.
	 *
	 * @param string $name Untrusted filename.
	 */
	public static function safe_upload_name( string $name ): bool {
		return 1 !== preg_match( '/(?:^|\.)(?:php\d*|phtml|pht|phar|cgi|pl|py|sh|html?|shtml|svg|js)(?:\.|$)/i', $name )
			&& ! str_contains( $name, "\0" );
	}

	/**
	 * Rejects disguised documents and active PDF/VTT payloads before public storage.
	 *
	 * @param string $mime Checked MIME type.
	 * @param string $bytes Document bytes within existing size limits.
	 */
	public static function safe_document( string $mime, string $bytes ): bool {
		if ( 'application/pdf' === $mime ) {
			$decoded = preg_replace_callback( '/#([0-9a-f]{2})/i', static fn( array $m ): string => chr( intval( $m[1], 16 ) ), $bytes );
			return str_starts_with( $bytes, '%PDF-' ) && is_string( $decoded )
				&& 1 !== preg_match( '~/\s*(?:JavaScript|JS|OpenAction|AA|Launch|EmbeddedFile|RichMedia|XFA)\b~i', $decoded );
		}
		if ( 'text/vtt' === $mime ) {
			return str_starts_with( ltrim( $bytes, "\xEF\xBB\xBF" ), 'WEBVTT' )
				&& 1 !== preg_match( '/<(?:script|iframe|object|embed)|<\?php|on\w+\s*=|javascript:/i', $bytes );
		}
		return true;
	}

	/**
	 * Machine-consumed inventory shipped with the privacy notice.
	 *
	 * @throws \RuntimeException When the deployed inventory cannot be read.
	 * @return array{plugins: list<string>, public_cookies: list<string>, public_storage: list<string>, third_party_runtime: list<string>, notices: list<array{en: string, pt: string}>}
	 */
	public static function inventory(): array {
		$file  = new \SplFileObject( dirname( __DIR__ ) . '/privacy-inventory.json', 'r' );
		$bytes = $file->fread( $file->getSize() );
		if ( false === $bytes ) {
			throw new \RuntimeException( 'Cannot read the deployed privacy inventory.' );
		}
		/**
		 * Shipped, machine-validated inventory shape.
		 *
		 * @var array{plugins: list<string>, public_cookies: list<string>, public_storage: list<string>, third_party_runtime: list<string>, notices: list<array{en: string, pt: string}>} $inventory
		 */
		$inventory = json_decode( $bytes, true, 512, JSON_THROW_ON_ERROR );
		return $inventory;
	}

	/** Registers privacy response filtering after the content plugin has loaded. */
	public static function register_public_rest(): void {
		if ( ! class_exists( Contracts::class ) ) {
			return;
		}
		foreach ( array_keys( Contracts::post_types() ) as $type ) {
			add_filter( 'rest_prepare_' . $type, array( self::class, 'public_rest' ), 100, 3 );
		}
	}

	/**
	 * Applies privacy even to logged-in readers; only authorized edit context is private.
	 *
	 * @param \WP_REST_Response $response Prepared response.
	 * @param \WP_Post          $post Record.
	 * @param \WP_REST_Request  $request Request context.
	 */
	public static function public_rest( \WP_REST_Response $response, \WP_Post $post, \WP_REST_Request $request ): \WP_REST_Response {
		if ( 'edit' === $request->get_param( 'context' ) && current_user_can( 'edit_post', $post->ID ) ) {
			return $response;
		}
		$data = $response->get_data();
		if ( ! is_array( $data ) ) {
			return $response;
		}
		foreach ( array_merge( SecurityPolicy::private_fields(), array( 'author' ) ) as $key ) {
			unset( $data[ $key ] );
			if ( isset( $data['meta'] ) && is_array( $data['meta'] ) ) {
				unset( $data['meta'][ $key ] );
			}
		}
		$source = Translations::source_id( $post->ID ) ?? $post->ID;
		if ( 'lps_person' === $post->post_type && ! get_post_meta( $source, '_lps_privacy_reviewed', true ) && isset( $data['meta'] ) && is_array( $data['meta'] ) ) {
			unset( $data['meta']['_lps_public_email'] );
		}
		$response->remove_link( 'author' );
		$response->set_data( $data );
		return $response;
	}

	/**
	 * Keeps account identities separate from public Person records.
	 *
	 * @param mixed            $result Existing dispatch result.
	 * @param mixed            $server REST server.
	 * @param \WP_REST_Request $request Current request.
	 * @return mixed
	 */
	public static function protect_accounts( mixed $result, mixed $server, \WP_REST_Request $request ): mixed {
		unset( $server );
		if ( str_starts_with( $request->get_route(), '/wp/v2/users' ) && ! current_user_can( 'list_users' ) ) {
			return new WP_Error( 'lps_accounts_private', 'Account information is restricted.', array( 'status' => 403 ) );
		}
		return $result;
	}

	/**
	 * Adds generated inventory only to the actual privacy page block.
	 *
	 * @param string $html Rendered institutional block.
	 */
	public static function append_privacy_notice( string $html ): string {
		if ( ! is_page() ) {
			return $html;
		}
		$id     = get_queried_object_id();
		$source = Translations::source_id( $id ) ?? $id;
		if ( 'privacy' !== get_post_meta( $source, '_lps_page_key', true ) ) {
			return $html;
		}
		$locale = get_post_meta( $id, '_lps_locale', true );
		return $html . self::privacy_notice( 'en' === $locale ? 'en' : 'pt-br' );
	}

	/**
	 * Generates notice prose from the deployed inventory, never from unreviewed editor HTML.
	 *
	 * @param string $locale Public locale.
	 */
	public static function privacy_notice( string $locale ): string {
		$html = '<section class="lps-privacy-inventory" data-inventory="2026-09-06">';
		foreach ( self::inventory()['notices'] as $notice ) {
			$html .= '<p>' . esc_html( $notice[ 'en' === $locale ? 'en' : 'pt' ] ) . '</p>';
		}
		return $html . '</section>';
	}
}
