<?php
/**
 * Plugin Name: LPS Teaching Storage Test Fixture
 * Description: Deploys the teaching storage constants, registers the test scanner adapter, and exposes test-only endpoints for the resource-version e2e suite. Test-only.
 *
 * @package LPS
 */

if ( ! defined( 'LPS_TEACHING_STORAGE_ROOT' ) ) {
	define( 'LPS_TEACHING_STORAGE_ROOT', '/tmp/lps-teaching-storage' );
}
if ( ! defined( 'LPS_TEACHING_PUBLIC_ROOT' ) ) {
	define( 'LPS_TEACHING_PUBLIC_ROOT', WP_CONTENT_DIR );
}

// The storage root must exist and be writable for the configuration gate.
if ( ! is_dir( LPS_TEACHING_STORAGE_ROOT ) ) {
	wp_mkdir_p( LPS_TEACHING_STORAGE_ROOT );
}

/**
 * Test scanner adapter: clean verdict unless the `lps_test_scanner_down`
 * option is set, in which case it reports `error` — the fail-closed path.
 *
 * @param array<string, mixed> $request Scan request (path, key, mime, bytes, checksum).
 */
function lps_test_teaching_scanner_adapter( array $request ): string {
	unset( $request );
	return get_option( 'lps_test_scanner_down', '' ) ? 'error' : 'clean';
}
add_filter(
	'lps_teaching_scanner_adapter',
	static function () {
		return 'lps_test_teaching_scanner_adapter';
	}
);

/**
 * Test-only copy-forward failure adapter: when the `lps_test_copy_fail_step`
 * option names a step, the operation boundary receives a WP_Error at that
 * step and must roll the partial graph back. Test-only.
 *
 * @param mixed  $failure       Current failure value.
 * @param string $step          Step identifier.
 * @param string $operation_id  Operation identifier.
 * @return mixed
 */
function lps_test_copy_step_error( $failure, string $step, string $operation_id ): mixed {
	unset( $operation_id );
	if ( $failure instanceof WP_Error ) {
		return $failure;
	}
	$armed = get_option( 'lps_test_copy_fail_step', '' );
	if ( is_string( $armed ) && '' !== $armed && $armed === $step ) {
		return new WP_Error(
			'lps_test_copy_step_failed',
			'The test fixture interrupted the copy-forward operation.',
			array( 'status' => 500 )
		);
	}
	return $failure;
}
add_filter( 'lps_teaching_copy_step_error', 'lps_test_copy_step_error', 10, 3 );

add_action(
	'rest_api_init',
	static function (): void {
		register_rest_route(
			'lps/v1',
			'/test/scanner',
			array(
				'methods'             => 'POST',
				'callback'            => static function ( WP_REST_Request $request ): WP_REST_Response {
					update_option( 'lps_test_scanner_down', 'down' === $request->get_param( 'state' ) ? '1' : '' );
					return rest_ensure_response( array( 'state' => get_option( 'lps_test_scanner_down', '' ) ? 'down' : 'up' ) );
				},
				'permission_callback' => static function (): bool {
					return current_user_can( 'edit_posts' );
				},
			)
		);
		register_rest_route(
			'lps/v1',
			'/test/grants',
			array(
				'methods'             => 'POST',
				'callback'            => static function ( WP_REST_Request $request ): WP_REST_Response|WP_Error {
					$user_id     = absint( $request->get_param( 'user_id' ) );
					$user_login  = sanitize_user( (string) $request->get_param( 'user_login' ) );
					$offering_id = absint( $request->get_param( 'offering_id' ) );
					$role        = sanitize_key( (string) $request->get_param( 'role' ) );
					$expires_at  = sanitize_text_field( (string) $request->get_param( 'expires_at' ) );
					if ( 0 >= $user_id && '' !== $user_login ) {
						$account = get_user_by( 'login', $user_login );
						$user_id = $account instanceof WP_User ? (int) $account->ID : 0;
					}
					if ( 0 >= $user_id || 0 >= $offering_id || ! in_array( $role, array( 'professor', 'delegate' ), true ) ) {
						return new WP_Error( 'lps_test_grant_invalid', 'user_id or user_login, offering_id and a scoped role are required.', array( 'status' => 400 ) );
					}
					$result = \LPS\ContentModel\Roles::grant_scope( $user_id, 'offering', $offering_id, $role, $expires_at );
					if ( $result instanceof WP_Error ) {
						return $result;
					}
					return rest_ensure_response( array( 'granted' => true ) );
				},
				'permission_callback' => static function (): bool {
					return current_user_can( 'manage_options' );
				},
			)
		);
		register_rest_route(
			'lps/v1',
			'/test/copy-fail',
			array(
				'methods'             => 'POST',
				'callback'            => static function ( WP_REST_Request $request ): WP_REST_Response {
					$step = sanitize_key( (string) $request->get_param( 'step' ) );
					update_option( 'lps_test_copy_fail_step', $step );
					return rest_ensure_response( array( 'armed_step' => $step ) );
				},
				'permission_callback' => static function (): bool {
					return current_user_can( 'edit_posts' );
				},
			)
		);
		register_rest_route(
			'lps/v1',
			'/test/cache-purge',
			array(
				'methods'             => 'GET',
				'callback'            => static function (): WP_REST_Response {
					return rest_ensure_response( get_option( 'lps_cache_last_purge', array() ) );
				},
				'permission_callback' => static function (): bool {
					return current_user_can( 'edit_posts' );
				},
			)
		);
		register_rest_route(
			'lps/v1',
			'/test/audit',
			array(
				'methods'             => 'GET',
				'callback'            => static function ( WP_REST_Request $request ): WP_REST_Response {
					$post_id = absint( $request->get_param( 'post_id' ) );
					$entries = array_values(
						array_filter(
							\LPS\ContentModel\Audit::entries( 500 ),
							static fn( array $entry ): bool => (int) $entry['object_id'] === $post_id
						)
					);
					return rest_ensure_response( $entries );
				},
				'permission_callback' => static function (): bool {
					return current_user_can( 'manage_options' );
				},
			)
		);
	}
);
