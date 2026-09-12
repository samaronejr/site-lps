<?php
/**
 * Theme integration-test bootstrap seam.
 *
 * @package LPS\Theme\Tests
 */

declare(strict_types=1);

$wordpress_tests_directory = getenv( 'WP_TESTS_DIR' );
if ( ! is_string( $wordpress_tests_directory ) || '' === $wordpress_tests_directory ) {
	throw new RuntimeException( 'WP_TESTS_DIR must point to the WordPress PHPUnit test library.' );
}

require_once rtrim( $wordpress_tests_directory, '/' ) . '/includes/functions.php';
