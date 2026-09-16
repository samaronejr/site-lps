<?php
/**
 * Plugin Name: LPS Content Model preload (task 24 visual-QA harness only)
 * Description: Loads the content-model plugin before the seed fixtures run.
 *
 * On a fresh database the seed fixtures in `tests/fixtures/wp/` reference
 * `LPS\ContentModel\*` classes from their `init` callbacks while the plugin is not
 * active yet, which fatals during the first Playground boot. This harness-only
 * loader includes the plugin entry once; WordPress later includes the same
 * realpath for the activated plugin, so nothing is declared twice.
 *
 * The shared fixture defect itself is out of scope here and stays with Todo 26; this run
 * reproduced it on a fresh database (fatal in class-audit.php from lps-trust-seed.php)
 * and reuses the Todo 23 workaround verbatim.
 *
 * @package LPS\Evidence
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( '\LPS\ContentModel\Translations' ) ) {
	$lps_plugin_entry = WP_CONTENT_DIR . '/plugins/lps-content-model/lps-content-model.php';
	if ( is_readable( $lps_plugin_entry ) ) {
		require_once $lps_plugin_entry;
	}
}

/*
 * The shared seed fixtures write records from `init` callbacks that run before the
 * plugin's own `init:10` migration, so on an empty database the append-only audit
 * table does not exist yet and the first seeded write fatals. This calls the
 * plugin's own idempotent migration earlier; it adds no behaviour of its own and
 * is mounted only by this evidence run.
 */
add_action(
	'init',
	static function (): void {
		if ( class_exists( '\\LPS\\ContentModel\\Plugin' ) ) {
			\LPS\ContentModel\Plugin::migrate();
		}
	},
	0
);
