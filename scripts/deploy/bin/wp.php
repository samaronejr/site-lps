<?php
/**
 * WP-CLI entry shim for the PHP.wasm CLI SAPI.
 *
 * Mirrors the Playground blueprint run-cli.php: argv is rebuilt from the
 * tokens passed after `--` on the host command line, then the phar boots
 * WordPress at /wordpress and dispatches the command.
 */
putenv('SHELL_PIPE=0');

$args = array_slice($argv, 1);
$GLOBALS['argv'] = array_merge(
	array('/lps-bin/wp-cli.phar', '--path=/wordpress'),
	$args
);

require '/lps-bin/wp-cli.phar';
