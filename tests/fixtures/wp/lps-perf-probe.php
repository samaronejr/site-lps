<?php
/**
 * Plugin Name: LPS Performance Probe Test Fixture
 * Description: Appends a machine-readable database query count to rendered HTML pages so the task-22 e2e suite can measure query growth on large resource lists. Test-only; never mounted outside the dedicated Playground environments.
 *
 * @package LPS
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action(
	'wp_footer',
	static function (): void {
		global $wpdb;
		if ( ! $wpdb instanceof wpdb ) {
			return;
		}
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Fixed-format numeric probe marker.
		echo '<!-- lps-queries:' . (int) $wpdb->num_queries . ' -->' . "\n";
	},
	PHP_INT_MAX
);
