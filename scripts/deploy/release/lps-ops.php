<?php
/**
 * Plugin Name: LPS Staging Operations
 * Description: Staging operations surface: health endpoint, mail capture, structured logs, PHP error routing and the cache-purge queue the edge consumes.
 *
 * @package LPS
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Directory the runtime mounts for operations state (outside the web root).
 */
function lps_ops_dir(): string {
	return defined( 'LPS_OPS_DIR' ) ? LPS_OPS_DIR : '/lps-ops';
}

/**
 * Directory the runtime mounts for cache state (outside the web root).
 */
function lps_ops_cache_dir(): string {
	return defined( 'LPS_OPS_CACHE_DIR' ) ? LPS_OPS_CACHE_DIR : '/lps-cache';
}

// PHP errors go to the ops volume, never to the response and never into the
// web root. display_errors stays off regardless of WP_DEBUG. The runtime's own
// mu-plugin re-points error_log at wp-content/debug.log after site mu-plugins
// load, so the routing is re-asserted on plugins_loaded.
function lps_ops_error_routing(): void {
	ini_set( 'log_errors', '1' );
	ini_set( 'display_errors', '0' );
	ini_set( 'error_log', lps_ops_dir() . '/logs/php-error.log' );
}
lps_ops_error_routing();
add_action( 'plugins_loaded', 'lps_ops_error_routing', PHP_INT_MAX );

/**
 * Appends one structured event to the application log.
 *
 * @param string               $event  Event name.
 * @param array<string, mixed> $fields Event fields.
 */
function lps_ops_log( string $event, array $fields = array() ): void {
	$dir = lps_ops_dir() . '/logs';
	if ( ! is_dir( $dir ) ) {
		wp_mkdir_p( $dir );
	}
	$line = wp_json_encode( array_merge( array( 'ts' => gmdate( 'c' ), 'event' => $event ), $fields ) );
	file_put_contents( $dir . '/app.jsonl', $line . "\n", FILE_APPEND | LOCK_EX );
}

// Staging never sends real mail: every wp_mail() call is captured to the
// outbox log and reported as accepted, so mail-link and notification behavior
// is verifiable without an MTA.
add_filter(
	'pre_wp_mail',
	static function ( mixed $return, array $atts ): bool {
		unset( $return );
		$dir = lps_ops_dir() . '/logs';
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}
		$to      = $atts['to'] ?? '';
		$subject = $atts['subject'] ?? '';
		$message = $atts['message'] ?? '';
		$headers = $atts['headers'] ?? array();
		$entry   = array(
			'ts'            => gmdate( 'c' ),
			'to'            => is_array( $to ) ? implode( ',', $to ) : (string) $to,
			'subject'       => (string) $subject,
			'message_sha'   => hash( 'sha256', (string) $message ),
			'message_bytes' => strlen( (string) $message ),
			// The body and headers ride along so verification can assert the
			// localized copy that was emitted, not only that mail was called.
			'message'       => (string) $message,
			'headers'       => is_array( $headers ) ? implode( "\n", array_map( 'strval', $headers ) ) : (string) $headers,
		);
		file_put_contents( $dir . '/mail-outbox.jsonl', wp_json_encode( $entry ) . "\n", FILE_APPEND | LOCK_EX );
		lps_ops_log( 'mail_captured', array( 'to' => $entry['to'], 'subject' => $entry['subject'] ) );
		return true;
	},
	10,
	2
);

// Publish invalidation: the application computes exact targets and fires
// lps_cache_purge; the staging edge consumes this queue file the way a
// production host would consume its CDN purge API.
add_action(
	'lps_cache_purge',
	static function ( array $targets, WP_Post $post ): void {
		$dir = lps_ops_cache_dir();
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}
		$entry = array(
			'ts'      => gmdate( 'c' ),
			'post'    => (int) $post->ID,
			'targets' => array_values( $targets ),
		);
		file_put_contents( $dir . '/purge-queue.jsonl', wp_json_encode( $entry ) . "\n", FILE_APPEND | LOCK_EX );
		lps_ops_log( 'cache_purge_queued', array( 'post' => (int) $post->ID, 'targets' => count( $targets ) ) );
	},
	10,
	2
);

// Cron heartbeat: a real scheduled event the system cron executes via
// `wp cron event run --due-now`. Its option timestamp is the observable proof
// that scheduled work fires on staging.
add_action(
	'init',
	static function (): void {
		if ( ! wp_next_scheduled( 'lps_ops_heartbeat' ) ) {
			wp_schedule_event( time(), 'lps_ops_minutely', 'lps_ops_heartbeat' );
		}
	}
);
add_filter(
	'cron_schedules',
	function ( $schedules ) {
		// A one-minute cadence keeps the scheduled-work proof observable in
		// near-real-time on staging; production would use a slower interval.
		$schedules['lps_ops_minutely'] = array(
			'interval' => 60,
			'display'  => 'Every minute (LPS staging heartbeat)',
		);
		return $schedules;
	}
);

add_action(
	'lps_ops_heartbeat',
	static function (): void {
		update_option( 'lps_ops_heartbeat', time(), false );
		lps_ops_log( 'cron_heartbeat', array( 'hook' => 'lps_ops_heartbeat' ) );
	}
);

// Anonymous health endpoint for uptime checks. Deliberately minimal: no
// version strings, no paths, no account data.
add_action(
	'rest_api_init',
	static function (): void {
		register_rest_route(
			'lps-ops/v1',
			'/health',
			array(
				'methods'             => 'GET',
				'permission_callback' => '__return_true',
				'callback'            => static function (): WP_REST_Response {
					$maintenance = file_exists( ABSPATH . '.maintenance' );
					$db_file     = WP_CONTENT_DIR . '/database/.ht.sqlite';
					return new WP_REST_Response(
						array(
							'status'        => $maintenance ? 'maintenance' : 'ok',
							'maintenance'   => $maintenance,
							'environment'   => defined( 'WP_ENVIRONMENT_TYPE' ) ? WP_ENVIRONMENT_TYPE : '',
							'db'            => is_file( $db_file ) ? 'sqlite' : 'unavailable',
							'object_cache'  => function_exists( 'wp_using_ext_object_cache' ) ? wp_using_ext_object_cache() : false,
							// Lets the deploy prove the answering origin mounted
							// the release that was just flipped to.
							'release'       => defined( 'LPS_RELEASE_ID' ) ? LPS_RELEASE_ID : '',
							'time'          => gmdate( 'c' ),
						),
						200
					);
				},
			)
		);
	}
);
