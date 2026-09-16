<?php
/**
 * Minimal wp-config for the LPS staging release tree.
 *
 * Environment constants (WP_ENVIRONMENT_TYPE, WP_DEBUG, DISALLOW_FILE_*,
 * LPS_EDGE_SECURITY_VERIFIED, salts) are injected by the runtime launcher
 * (scripts/deploy/staging.mjs) as predefined constants, which is the
 * Playground equivalent of an institution-managed wp-config provisioned
 * outside the web root: the release tree itself carries no secrets and no
 * environment decisions.
 *
 * @package LPS
 */

define( 'DB_NAME', 'database_name_here' );
define( 'DB_USER', 'username_here' );
define( 'DB_PASSWORD', 'password_here' );
define( 'DB_HOST', 'localhost' );
define( 'DB_CHARSET', 'utf8mb4' );
define( 'DB_COLLATE', '' );

define( 'AUTH_KEY', 'put your unique phrase here' );
define( 'SECURE_AUTH_KEY', 'put your unique phrase here' );
define( 'LOGGED_IN_KEY', 'put your unique phrase here' );
define( 'NONCE_KEY', 'put your unique phrase here' );
define( 'AUTH_SALT', 'put your unique phrase here' );
define( 'SECURE_AUTH_SALT', 'put your unique phrase here' );
define( 'LOGGED_IN_SALT', 'put your unique phrase here' );
define( 'NONCE_SALT', 'put your unique phrase here' );

$table_prefix = 'wp_';

define( 'WP_DEBUG', false );

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

require_once ABSPATH . 'wp-settings.php';
