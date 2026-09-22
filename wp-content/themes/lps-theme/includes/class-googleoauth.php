<?php
/**
 * Google Workspace sign-in (OpenID Connect) for the branded surface.
 *
 * `/{locale}/{signin}/google/` is a single endpoint with two moments: a bare
 * GET starts the authorization-code flow (state + nonce parked in a
 * transient, then a redirect to Google), and the same URL receiving `code`
 * and `state` is the registered redirect URI where the flow returns. The
 * exchange happens server-side; the ID token is verified against Google's
 * JWKS before any session is issued, and only identities whose hosted domain
 * is `lps.ufrj.br` continue.
 *
 * The flow signs in an existing WordPress account matched by verified e-mail
 * — the laboratory provisions accounts explicitly, so an @lps.ufrj.br
 * identity with no matching user is refused rather than auto-provisioned —
 * and issues the session with the same `wp_login` hooks `wp_signon` fires,
 * keeping the audit ledger and the content-model MFA capability gate intact.
 *
 * Configuration lives in constants so no credential touches the database or
 * the rendered page: define `LPS_GOOGLE_CLIENT_ID` and
 * `LPS_GOOGLE_CLIENT_SECRET` in wp-config.php (or the environment shim). The
 * button only renders while both exist — an unconfigured environment simply
 * keeps the password form alone.
 *
 * @package LPS\Theme
 */

declare(strict_types=1);

namespace LPS\Theme;

/** Google Workspace authorization-code flow for the branded sign-in route. */
final class GoogleOauth {

	/** Hosted domain the laboratory accepts. */
	private const HOSTED_DOMAIN = 'lps.ufrj.br';

	/** Google authorization endpoint (OIDC). */
	private const AUTHORIZE_URL = 'https://accounts.google.com/o/oauth2/v2/auth';

	/** Google token endpoint. */
	private const TOKEN_URL = 'https://oauth2.googleapis.com/token';

	/** Google JWKS endpoint for ID-token signatures. */
	private const JWKS_URL = 'https://www.googleapis.com/oauth2/v3/certs';

	/** Transient TTL for the parked state/nonce pair, in seconds. */
	private const STATE_TTL = 600;

	/** Transient TTL for the cached JWKS document, in seconds. */
	private const JWKS_TTL = 21600;

	/** Whether the flow has credentials configured. */
	public static function configured(): bool {
		return '' !== self::client_id() && '' !== self::client_secret();
	}

	/** The OAuth client ID from wp-config, or empty when unset. */
	private static function client_id(): string {
		$id = defined( 'LPS_GOOGLE_CLIENT_ID' ) ? constant( 'LPS_GOOGLE_CLIENT_ID' ) : '';
		return is_string( $id ) ? $id : '';
	}

	/** The OAuth client secret from wp-config, or empty when unset. */
	private static function client_secret(): string {
		$secret = defined( 'LPS_GOOGLE_CLIENT_SECRET' ) ? constant( 'LPS_GOOGLE_CLIENT_SECRET' ) : '';
		return is_string( $secret ) ? $secret : '';
	}

	/**
	 * Returns the endpoint the sign-in surface links to.
	 *
	 * The requested destination travels inside the parked state rather than
	 * the query string, so the public URL stays opaque.
	 *
	 * @param string $locale       Supported locale slug.
	 * @param string $redirect_to  Requested post-login destination.
	 */
	public static function start_url( string $locale, string $redirect_to = '' ): string {
		$path = self::endpoint_path( $locale );
		if ( '' === $redirect_to ) {
			return $path;
		}
		return $path . '?redirect_to=' . rawurlencode( $redirect_to );
	}

	/**
	 * Serves the OAuth endpoint: starts the flow or finishes the callback.
	 *
	 * @param string $locale Supported locale slug.
	 */
	public static function serve( string $locale ): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- The OAuth callback carries the protocol's own anti-CSRF `state`, verified against the parked transient below; sanitized as scalars next.
		$raw_code = isset( $_GET['code'] ) ? wp_unslash( $_GET['code'] ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Same: `state` is the anti-CSRF value of the flow, verified against the transient.
		$raw_state = isset( $_GET['state'] ) ? wp_unslash( $_GET['state'] ) : '';
		$code      = is_string( $raw_code ) ? sanitize_text_field( $raw_code ) : '';
		$state     = is_string( $raw_state ) ? sanitize_text_field( $raw_state ) : '';
		if ( '' === $code && '' === $state ) {
			self::start( $locale );
			return;
		}
		self::callback( $locale, $code, $state );
	}

	/**
	 * Parks a fresh state/nonce pair and redirects the visitor to Google.
	 *
	 * @param string $locale Supported locale slug.
	 */
	private static function start( string $locale ): void {
		if ( ! self::configured() ) {
			self::deny( $locale, 'sso_unavailable' );
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- A read-only destination parameter; the state binds it to this attempt below.
		$raw_redirect = isset( $_GET['redirect_to'] ) ? wp_unslash( $_GET['redirect_to'] ) : '';
		$redirect     = is_string( $raw_redirect ) ? sanitize_url( $raw_redirect ) : '';
		$state        = function_exists( 'wp_generate_password' )
			? wp_generate_password( 32, false )
			: bin2hex( random_bytes( 16 ) );
		if ( function_exists( 'set_transient' ) ) {
			set_transient(
				'lps_oauth_' . $state,
				array(
					'locale'      => $locale,
					'redirect_to' => $redirect,
					'created'     => time(),
				),
				self::STATE_TTL
			);
		}
		$query = array(
			'client_id'     => self::client_id(),
			'redirect_uri'  => self::callback_uri( $locale ),
			'response_type' => 'code',
			'scope'         => 'openid email profile',
			'state'         => $state,
			'nonce'         => $state,
			'hd'            => self::HOSTED_DOMAIN,
			'access_type'   => 'online',
			'prompt'        => 'select_account',
		);
		self::redirect( self::AUTHORIZE_URL . '?' . http_build_query( $query, '', '&', PHP_QUERY_RFC3986 ) );
	}

	/**
	 * Exchanges the returned code, verifies the ID token and signs in.
	 *
	 * @param string $locale Supported locale slug.
	 * @param string $code   Authorization code from Google.
	 * @param string $state  State returned by Google.
	 */
	private static function callback( string $locale, string $code, string $state ): void {
		if ( ! self::configured() || '' === $code || '' === $state ) {
			self::deny( $locale, 'sso_unavailable' );
		}
		$parked = function_exists( 'get_transient' ) ? get_transient( 'lps_oauth_' . $state ) : false;
		if ( function_exists( 'delete_transient' ) ) {
			delete_transient( 'lps_oauth_' . $state );
		}
		if ( ! is_array( $parked ) ) {
			self::deny( $locale, 'sso_state' );
		}
		$stored_locale = is_string( $parked['locale'] ?? null ) ? $parked['locale'] : $locale;
		$redirect      = is_string( $parked['redirect_to'] ?? null ) ? $parked['redirect_to'] : '';
		$id_token      = self::exchange_code( $code, $locale );
		if ( null === $id_token ) {
			self::deny( $locale, 'sso' );
		}
		$claims = self::verify_id_token( $id_token );
		if ( null === $claims ) {
			self::deny( $locale, 'sso' );
		}
		$nonce = is_string( $claims->nonce ?? null ) ? $claims->nonce : '';
		if ( $nonce !== $state ) {
			self::deny( $locale, 'sso' );
		}
		$email    = is_string( $claims->email ?? null ) ? $claims->email : '';
		$verified = self::claim_truthy( $claims->email_verified ?? null );
		$hosted   = is_string( $claims->hd ?? null ) ? $claims->hd : '';
		$ours     = '' !== $email && str_ends_with( strtolower( $email ), '@' . self::HOSTED_DOMAIN );
		if ( ! $verified || '' === $email || ( self::HOSTED_DOMAIN !== $hosted && ! $ours ) ) {
			self::deny( $locale, 'sso_domain' );
		}
		$user = function_exists( 'get_user_by' ) ? get_user_by( 'email', $email ) : false;
		if ( ! $user instanceof \WP_User ) {
			self::deny( $locale, 'sso_unlinked' );
		}
		self::issue_session( $user, $redirect, $stored_locale );
	}

	/**
	 * Trades the authorization code for the token bundle.
	 *
	 * @param string $code   Authorization code from Google.
	 * @param string $locale Supported locale slug.
	 * @return string|null The raw ID token, or null on any failure.
	 */
	private static function exchange_code( string $code, string $locale ): ?string {
		if ( ! function_exists( 'wp_remote_post' ) ) {
			return null;
		}
		$response = wp_remote_post(
			self::TOKEN_URL,
			array(
				'timeout' => 15,
				'body'    => array(
					'code'          => $code,
					'client_id'     => self::client_id(),
					'client_secret' => self::client_secret(),
					'redirect_uri'  => self::callback_uri( $locale ),
					'grant_type'    => 'authorization_code',
				),
			)
		);
		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			return null;
		}
		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $body ) ) {
			return null;
		}
		$token = $body['id_token'] ?? null;
		return is_string( $token ) ? $token : null;
	}

	/**
	 * Verifies the ID token signature and claims against Google's JWKS.
	 *
	 * @param string $jwt Compact JWT.
	 * @return \stdClass|null Decoded claims object when everything checks out.
	 */
	private static function verify_id_token( string $jwt ): ?\stdClass {
		$segments = explode( '.', $jwt );
		if ( 3 !== count( $segments ) ) {
			return null;
		}
		$header  = json_decode( self::base64url_decode( $segments[0] ) ?? '' );
		$payload = json_decode( self::base64url_decode( $segments[1] ) ?? '' );
		if ( ! $header instanceof \stdClass || ! $payload instanceof \stdClass ) {
			return null;
		}
		if ( 'RS256' !== ( $header->alg ?? '' ) ) {
			return null;
		}
		$issuer = is_string( $payload->iss ?? null ) ? $payload->iss : '';
		if ( ! in_array( $issuer, array( 'accounts.google.com', 'https://accounts.google.com' ), true ) ) {
			return null;
		}
		if ( self::client_id() !== ( $payload->aud ?? '' ) ) {
			return null;
		}
		$expires = is_numeric( $payload->exp ?? null ) ? (int) $payload->exp : 0;
		if ( $expires <= time() ) {
			return null;
		}
		$kid = is_string( $header->kid ?? null ) ? $header->kid : '';
		$pem = self::public_key_for( $kid );
		if ( null === $pem ) {
			return null;
		}
		$signature = self::base64url_decode( $segments[2] );
		if ( null === $signature ) {
			return null;
		}
		$checked = openssl_verify( $segments[0] . '.' . $segments[1], $signature, $pem, 'sha256' );
		if ( 1 !== $checked ) {
			return null;
		}
		return $payload;
	}

	/**
	 * Resolves the Google RSA key for the token's `kid` as PEM.
	 *
	 * The JWKS document is fetched once and cached; a miss for the current
	 * `kid` forces one refetch so a rotated key does not strand sign-ins.
	 *
	 * @param string $kid Key ID from the JWT header.
	 */
	private static function public_key_for( string $kid ): ?string {
		if ( '' === $kid ) {
			return null;
		}
		$keys = self::jwks();
		if ( is_array( $keys ) && isset( $keys[ $kid ] ) ) {
			return self::jwk_to_pem( $keys[ $kid ] );
		}
		if ( function_exists( 'delete_transient' ) ) {
			delete_transient( 'lps_google_jwks' );
		}
		$keys = self::jwks();
		if ( is_array( $keys ) && isset( $keys[ $kid ] ) ) {
			return self::jwk_to_pem( $keys[ $kid ] );
		}
		return null;
	}

	/**
	 * Fetches (and caches) Google's public RSA keys keyed by `kid`.
	 *
	 * @return array<string, array<string, string>>|null
	 */
	private static function jwks(): ?array {
		$cached = function_exists( 'get_transient' ) ? get_transient( 'lps_google_jwks' ) : false;
		if ( is_array( $cached ) ) {
			$keys = array();
			foreach ( $cached as $cached_kid => $cached_jwk ) {
				if ( is_string( $cached_kid ) && is_array( $cached_jwk )
					&& is_string( $cached_jwk['n'] ?? null ) && is_string( $cached_jwk['e'] ?? null ) ) {
					$keys[ $cached_kid ] = array(
						'n' => $cached_jwk['n'],
						'e' => $cached_jwk['e'],
					);
				}
			}
			if ( array() !== $keys ) {
				return $keys;
			}
		}
		if ( ! function_exists( 'wp_remote_get' ) ) {
			return null;
		}
		$response = wp_remote_get( self::JWKS_URL, array( 'timeout' => 15 ) );
		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			return null;
		}
		$document = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $document ) || ! is_array( $document['keys'] ?? null ) ) {
			return null;
		}
		$keys = array();
		foreach ( $document['keys'] as $jwk ) {
			if ( ! is_array( $jwk ) || 'RSA' !== ( $jwk['kty'] ?? '' ) ) {
				continue;
			}
			$kid  = $jwk['kid'] ?? null;
			$mod  = $jwk['n'] ?? null;
			$expo = $jwk['e'] ?? null;
			if ( is_string( $kid ) && is_string( $mod ) && is_string( $expo ) ) {
				$keys[ $kid ] = array(
					'n' => $mod,
					'e' => $expo,
				);
			}
		}
		if ( array() === $keys ) {
			return null;
		}
		if ( function_exists( 'set_transient' ) ) {
			set_transient( 'lps_google_jwks', $keys, self::JWKS_TTL );
		}
		return $keys;
	}

	/**
	 * Converts a JWK RSA public key to PEM for `openssl_verify`.
	 *
	 * @param array<string, string> $jwk JWK with `n` and `e`.
	 */
	private static function jwk_to_pem( array $jwk ): ?string {
		$modulus  = self::base64url_decode( $jwk['n'] );
		$exponent = self::base64url_decode( $jwk['e'] );
		if ( null === $modulus || null === $exponent ) {
			return null;
		}
		$modulus  = self::der_integer( $modulus );
		$exponent = self::der_integer( $exponent );
		if ( null === $modulus || null === $exponent ) {
			return null;
		}
		$rsa_key   = self::der_sequence( $modulus . $exponent );
		$algorithm = self::der_sequence( hex2bin( '06092a864886f70d010101' ) . "\x05\x00" );
		$subject   = self::der_sequence( $algorithm . "\x03" . self::der_length( strlen( $rsa_key ) + 1 ) . "\x00" . $rsa_key );
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- PEM transport encoding of a public key, not obfuscation.
		return "-----BEGIN PUBLIC KEY-----\n" . chunk_split( base64_encode( $subject ), 64, "\n" ) . "-----END PUBLIC KEY-----\n";
	}

	/**
	 * DER-encodes an INTEGER, prepending 0x00 when the top bit is set.
	 *
	 * @param string $bytes Raw big-endian bytes.
	 */
	private static function der_integer( string $bytes ): ?string {
		if ( '' === $bytes ) {
			return null;
		}
		if ( ord( $bytes[0] ) > 0x7f ) {
			$bytes = "\x00" . $bytes;
		}
		return "\x02" . self::der_length( strlen( $bytes ) ) . $bytes;
	}

	/**
	 * DER-encodes a SEQUENCE around encoded contents.
	 *
	 * @param string $contents Encoded members.
	 */
	private static function der_sequence( string $contents ): string {
		return "\x30" . self::der_length( strlen( $contents ) ) . $contents;
	}

	/**
	 * DER-encodes a length octet string.
	 *
	 * @param int $length Content length.
	 */
	private static function der_length( int $length ): string {
		if ( $length < 128 ) {
			return chr( $length );
		}
		$encoded = '';
		$value   = $length;
		while ( $value > 0 ) {
			$encoded = chr( $value & 0xff ) . $encoded;
			$value >>= 8;
		}
		return chr( 0x80 | strlen( $encoded ) ) . $encoded;
	}

	/**
	 * Decodes unpadded base64url.
	 *
	 * @param string $data Base64url input.
	 */
	private static function base64url_decode( string $data ): ?string {
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- JWT/JWK transport decoding, not obfuscation.
		$decoded = base64_decode( strtr( $data, '-_', '+/' ), true );
		return false === $decoded ? null : $decoded;
	}

	/**
	 * Reads the loose booleans Google uses in ID-token claims.
	 *
	 * @param mixed $claim Claim value.
	 */
	private static function claim_truthy( mixed $claim ): bool {
		return true === $claim || 'true' === $claim || 1 === $claim;
	}

	/**
	 * Issues the WordPress session and forwards to the stored destination.
	 *
	 * `wp_login` fires exactly as `wp_signon` would, so the audit ledger and
	 * the content-model MFA capability gate see the same event.
	 *
	 * @param \WP_User $user      Matched account.
	 * @param string   $redirect  Requested post-login destination.
	 * @param string   $locale    Locale parked with the state.
	 */
	private static function issue_session( \WP_User $user, string $redirect, string $locale ): void {
		if ( function_exists( 'wp_set_current_user' ) ) {
			wp_set_current_user( $user->ID );
		}
		if ( function_exists( 'wp_set_auth_cookie' ) ) {
			wp_set_auth_cookie( $user->ID );
		}
		do_action( 'wp_login', $user->user_login, $user );
		$target = function_exists( 'wp_validate_redirect' )
			? wp_validate_redirect( $redirect, AuthRoutes::default_target( $user, $locale ) )
			: AuthRoutes::default_target( $user, $locale );
		self::redirect( $target );
	}

	/**
	 * Sends the visitor back to the sign-in surface with an error key.
	 *
	 * @param string $locale Supported locale slug.
	 * @param string $key    Surface error key.
	 */
	private static function deny( string $locale, string $key ): never {
		$path = Shell::signin_path( $locale ) . '?sso_error=' . rawurlencode( $key );
		self::redirect( function_exists( 'home_url' ) ? home_url( $path ) : $path );
	}

	/**
	 * Emits the redirect and ends the request.
	 *
	 * @param string $url Absolute destination.
	 */
	private static function redirect( string $url ): never {
		if ( function_exists( 'wp_safe_redirect' ) ) {
			wp_safe_redirect( $url );
		} else {
			header( 'Location: ' . $url );
		}
		exit;
	}

	/**
	 * The public path of the OAuth endpoint for one locale.
	 *
	 * @param string $locale Supported locale slug.
	 */
	private static function endpoint_path( string $locale ): string {
		return Shell::signin_path( $locale ) . 'google/';
	}

	/**
	 * The registered redirect URI Google calls back to.
	 *
	 * @param string $locale Supported locale slug.
	 */
	private static function callback_uri( string $locale ): string {
		return function_exists( 'home_url' )
			? home_url( self::endpoint_path( $locale ) )
			: self::endpoint_path( $locale );
	}
}
