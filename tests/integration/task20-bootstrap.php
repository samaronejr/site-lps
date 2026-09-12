<?php
/**
 * Real WordPress integration bootstrap for an isolated local security fixture.
 *
 * @package LPS\Tests
 */

declare(strict_types=1);

$root = getenv( 'LPS_SECURITY_WP_ROOT' );
if ( ! is_string( $root ) || ! is_file( $root . '/wp-load.php' ) ) {
	throw new RuntimeException( 'LPS_SECURITY_WP_ROOT must identify the isolated WordPress fixture.' );
}
$_SERVER['HTTP_HOST']   = '127.0.0.1:8894';
$_SERVER['REQUEST_URI'] = '/';
require $root . '/wp-load.php';
if ( 'local' !== wp_get_environment_type() ) {
	throw new RuntimeException( 'Security integration tests may only mutate a local fixture.' );
}
require_once dirname( __DIR__, 2 ) . '/vendor/autoload.php';
