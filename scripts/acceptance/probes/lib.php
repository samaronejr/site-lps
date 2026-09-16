<?php
/**
 * Shared helpers for the Todo 29 role acceptance probes.
 *
 * Every probe runs inside `wp eval-file` on the local staging runtime and
 * prints exactly one `T29JSON:` line carrying the step transcript. The Node
 * runner (scripts/acceptance/simulate.mjs) parses that line and asserts the
 * recorded checks; a probe that dies before printing is reported as failed.
 *
 * No `declare(strict_types=1)` here or in probes: `wp eval-file` strips the
 * opening tag and eval()s the body, so a strict_types declaration would fatal.
 *
 * @package LPS\Acceptance
 */

use LPS\ContentModel\Audit;
use LPS\ContentModel\Roles;
use LPS\ContentModel\SecurityPolicy;

// `wp eval-file` + `rest_do_request` never define REST_REQUEST, so the
// direct-write gates (wp_insert_post_data) would double-fire on slashed
// block JSON that only exists inside the REST pipeline. Defining the
// constant makes the probes exercise the real REST boundary: the
// rest_pre_insert_* gates still run and return typed WP_Error denials.
if ( ! defined( 'REST_REQUEST' ) ) {
	define( 'REST_REQUEST', true );
}

/** Accumulated check transcript for the current probe. */
$T29_CHECKS = array();

/**
 * Records one named check with its observed value.
 *
 * @param string $name     Stable check identifier.
 * @param bool   $pass     Whether the observed value satisfies the contract.
 * @param mixed  $observed What was actually observed (for the transcript).
 */
function t29_check( string $name, bool $pass, $observed = null ): void {
	global $T29_CHECKS;
	$T29_CHECKS[] = array(
		'name'     => $name,
		'pass'     => $pass,
		'observed' => $observed,
	);
}

/**
 * Prints the transcript line and exits with the aggregate status.
 *
 * @param string $simulation Simulation identifier (s1-contributor, ...).
 * @param array  $extra      Extra transcript fields (ids, urls, notes).
 */
function t29_emit( string $simulation, array $extra = array() ): void {
	global $T29_CHECKS;
	$failed = array();
	foreach ( $T29_CHECKS as $check ) {
		if ( ! $check['pass'] ) {
			$failed[] = $check['name'];
		}
	}
	$payload = array_merge(
		array(
			'simulation' => $simulation,
			'checks'     => $T29_CHECKS,
			'failed'     => $failed,
			'status'     => array() === $failed ? 'passed' : 'failed',
		),
		$extra
	);
	echo "\nT29JSON:" . wp_json_encode( $payload ) . "\n";
	exit( array() === $failed ? 0 : 1 );
}

/** Refuses to run anywhere but the loopback staging runtime. */
function t29_guard_staging(): void {
	$host = wp_parse_url( home_url( '/' ), PHP_URL_HOST );
	if ( '127.0.0.1' !== $host && 'localhost' !== $host ) {
		echo "\nT29JSON:" . wp_json_encode(
			array(
				'simulation' => 'guard',
				'checks'     => array(),
				'failed'     => array( 'staging-host' ),
				'status'     => 'failed',
				'error'      => 'refusing to run acceptance probes on ' . (string) $host,
			)
		) . "\n";
		exit( 1 );
	}
}

/**
 * Reads one key from the per-run secrets file staged at /lps-secrets/t29.env.
 * The file is written mode 0600 by the runner and deleted during cleanup;
 * values never appear in transcripts or evidence.
 *
 * @param string $key Environment-style key.
 */
function t29_secret( string $key ): string {
	$raw = @file_get_contents( '/lps-secrets/t29.env' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	if ( false === $raw ) {
		return '';
	}
	if ( 1 === preg_match( '/^' . preg_quote( $key, '/' ) . '=(.+)$/m', $raw, $m ) ) {
		return trim( $m[1] );
	}
	return '';
}

/**
 * Deletes and recreates one disposable simulation account.
 *
 * @param string $login Individual login.
 * @param string $role  Policy role key (contributor, ...).
 * @return int Account ID.
 */
function t29_recreate_user( string $login, string $role ): int {
	$existing = get_user_by( 'login', $login );
	if ( $existing instanceof WP_User ) {
		wp_delete_user( $existing->ID );
	}
	$password = t29_secret( 'T29_PASSWORD' );
	if ( '' === $password ) {
		$password = wp_generate_password( 24, true, true );
	}
	$uid = wp_insert_user(
		array(
			'user_login'   => $login,
			'user_pass'    => $password,
			'user_email'   => str_replace( array( '.', '_' ), '-', $login ) . '@t29.invalid',
			'display_name' => $login,
			'role'         => Roles::slug( $role ),
		)
	);
	if ( is_wp_error( $uid ) ) {
		throw new RuntimeException( $login . ': ' . $uid->get_error_message() );
	}
	return (int) $uid;
}

/**
 * Enrolls an account in Two-Factor TOTP through the plugin's own meta contract,
 * proving enrollment via MFA::is_enrolled.
 *
 * @param int $user_id Account ID.
 */
function t29_enroll_totp( int $user_id ): void {
	$meta_key = static function ( string $class, array $constants, string $documented ): string {
		foreach ( $constants as $constant ) {
			if ( defined( $class . '::' . $constant ) ) {
				$value = constant( $class . '::' . $constant );
				if ( is_string( $value ) && '' !== $value ) {
					return $value;
				}
			}
		}
		return $documented;
	};
	$totp_meta      = $meta_key( 'Two_Factor_Totp', array( 'SECRET_META_KEY', 'USER_META_KEY' ), '_two_factor_totp_key' );
	$enabled_meta   = $meta_key( 'Two_Factor_Core', array( 'ENABLED_PROVIDERS_USER_META_KEY' ), '_two_factor_enabled_providers' );
	$provider_meta  = $meta_key( 'Two_Factor_Core', array( 'PROVIDER_USER_META_KEY' ), '_two_factor_provider' );
	$last_login_key = $meta_key( 'Two_Factor_Totp', array( 'LAST_SUCCESSFUL_LOGIN_META_KEY' ), '_two_factor_totp_last_successful_login' );
	$secret         = t29_secret( 'T29_TOTP_SECRET' );
	if ( '' === $secret ) {
		throw new RuntimeException( 'T29_TOTP_SECRET missing from /lps-secrets/t29.env' );
	}
	update_user_meta( $user_id, $totp_meta, $secret );
	update_user_meta( $user_id, $enabled_meta, array( 'Two_Factor_Totp' ) );
	update_user_meta( $user_id, $provider_meta, 'Two_Factor_Totp' );
	delete_user_meta( $user_id, $last_login_key );
	if ( ! \LPS\ContentModel\MFA::is_enrolled( $user_id ) ) {
		throw new RuntimeException( 'user ' . $user_id . ': TOTP enrollment did not take effect' );
	}
}

/**
 * Switches the current session to a simulation account and records the
 * resolved policy role so the transcript proves who acted.
 *
 * @param string $login Account login.
 * @return int Account ID.
 */
function t29_become( string $login ): int {
	$user = get_user_by( 'login', $login );
	if ( ! $user instanceof WP_User ) {
		throw new RuntimeException( 'account missing: ' . $login );
	}
	wp_set_current_user( $user->ID );
	t29_check( 'session:' . $login, Roles::policy_role( $user ) !== '' && get_current_user_id() === $user->ID, Roles::policy_role( $user ) );
	return $user->ID;
}

/**
 * Dispatches an internal REST request as the current user and records the
 * status, error code and resulting post state.
 *
 * @param string $method HTTP method.
 * @param string $route  REST route.
 * @param array  $params Request parameters.
 * @return array{status:int, code:string, data:array}
 */
function t29_rest( string $method, string $route, array $params = array() ): array {
	$request = new WP_REST_Request( $method, $route );
	foreach ( $params as $key => $value ) {
		$request->set_param( $key, $value );
	}
	$response = rest_do_request( $request );
	$data     = $response->get_data();
	return array(
		'status' => $response->get_status(),
		'code'   => is_array( $data ) && isset( $data['code'] ) ? (string) $data['code'] : '',
		'data'   => is_array( $data ) ? $data : array(),
	);
}

/**
 * Returns audit-ledger rows for one object, newest first.
 *
 * @param int         $object_id Record ID.
 * @param string|null $action    Optional action filter.
 * @return array<int, array<string, mixed>>
 */
function t29_audit_for( int $object_id, ?string $action = null ): array {
	$rows = array();
	foreach ( Audit::entries( 1000 ) as $entry ) {
		if ( (int) $entry['object_id'] !== $object_id ) {
			continue;
		}
		if ( null !== $action && $entry['action'] !== $action ) {
			continue;
		}
		$rows[] = $entry;
	}
	return $rows;
}

/**
 * Records whether the ledger holds a row for object+action(+actor).
 *
 * @param string $name      Check name.
 * @param int    $object_id Record ID.
 * @param string $action    Audited action.
 * @param int    $actor     Optional required actor.
 */
function t29_audit_check( string $name, int $object_id, string $action, int $actor = 0 ): void {
	$rows = t29_audit_for( $object_id, $action );
	$hit  = false;
	foreach ( $rows as $row ) {
		if ( 0 === $actor || (int) $row['actor_user_id'] === $actor ) {
			$hit = true;
			break;
		}
	}
	t29_check( $name, $hit, array( 'action' => $action, 'rows' => count( $rows ) ) );
}

/** Asserts the audit hash chain still verifies after the simulation's writes. */
function t29_audit_chain_check(): void {
	t29_check( 'audit-chain-verified', Audit::verify_chain(), Audit::verify_chain() );
}

/**
 * Creates one governed record directly (fixture path) with the run marker.
 *
 * @param string $post_type Governed type.
 * @param array  $fields    Post fields.
 * @param array  $meta      Metadata.
 * @return int Record ID.
 */
function t29_insert( string $post_type, array $fields, array $meta = array() ): int {
	$meta['_lps_t29'] = '1';
	$id               = wp_insert_post(
		array_merge(
			array(
				'post_type'   => $post_type,
				'post_status' => 'draft',
				'meta_input'  => $meta,
			),
			$fields
		),
		true
	);
	if ( is_wp_error( $id ) ) {
		throw new RuntimeException( 'insert ' . $post_type . ': ' . $id->get_error_message() );
	}
	return (int) $id;
}

/**
 * Removes every simulation account and record, in any post status.
 *
 * The sweep deliberately bypasses WP_Query: 'any' excludes internal statuses
 * (trash, lps_archived, inherit), and wp_unique_post_slug() has no status
 * filter, so a leftover record in any status silently occupies its slug and
 * forces -2 suffixes in the next run. The REST controller also uniquifies
 * draft slugs against the 'publish' status, so even a leftover draft breaks
 * the documented rename path. Sweeping here, before provisioning, is what
 * makes "clean account and fixture" true rather than assumed.
 *
 * @return array{users:int, posts:int, remaining:array<int, int>} Counts and leftovers.
 */
function t29_sweep(): array {
	global $wpdb;
	require_once ABSPATH . 'wp-admin/includes/user.php';

	// Users first: wp_delete_user() trashes their posts (never force-deletes),
	// and the post sweep below removes whatever that leaves behind.
	$removed_users = 0;
	$logins        = $wpdb->get_col( "SELECT user_login FROM {$wpdb->users} WHERE user_login LIKE 't29.%'" );
	foreach ( (array) $logins as $login ) {
		$user = get_user_by( 'login', $login );
		if ( $user instanceof WP_User && wp_delete_user( $user->ID ) ) {
			++$removed_users;
		}
	}

	$tables = \LPS\ContentModel\Migrations::table_names( $wpdb );
	$ids    = $wpdb->get_col(
		"SELECT DISTINCT p.ID FROM {$wpdb->posts} p
		 LEFT JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_lps_t29'
		 WHERE m.meta_value = '1' OR p.post_name LIKE 't29-%'"
	);
	$removed_posts = 0;
	$remaining     = array();
	foreach ( (array) $ids as $post_id ) {
		$post_id = (int) $post_id;
		if ( ! get_post( $post_id ) instanceof WP_Post ) {
			continue;
		}
		// Clear both relationship directions and the identity/published-slug
		// markers, or archive_instead_of_delete() converts the delete into an
		// lps_archived row that 'any'-status queries can never see again.
		$wpdb->delete( $tables['relationships'], array( 'source_post_id' => $post_id ), array( '%d' ) );
		$wpdb->delete( $tables['relationships'], array( 'target_post_id' => $post_id ), array( '%d' ) );
		$wpdb->delete( $tables['authorships'], array( 'publication_id' => $post_id ), array( '%d' ) );
		$wpdb->delete( $tables['authorships'], array( 'author_post_id' => $post_id ), array( '%d' ) );
		delete_post_meta( $post_id, '_lps_published_slug' );
		delete_post_meta( $post_id, '_lps_record_id' );
		if ( ! in_array( get_post_status( $post_id ), array( 'draft', 'auto-draft', 'inherit' ), true ) ) {
			wp_update_post( array( 'ID' => $post_id, 'post_status' => 'draft' ) );
		}
		if ( wp_delete_post( $post_id, true ) ) {
			++$removed_posts;
		} else {
			$remaining[] = $post_id;
		}
	}

	// Attachment files staged by the fixture.
	$uploads = wp_upload_dir();
	foreach ( array( 'lps-t29/cleared.png', 'lps-t29/unreviewed.png' ) as $rel ) {
		$abs = $uploads['basedir'] . '/' . $rel;
		if ( is_file( $abs ) ) {
			unlink( $abs );
		}
	}
	@rmdir( $uploads['basedir'] . '/lps-t29' ); // phpcs:ignore WordPress.WP.AlternativeFunctions

	delete_option( '_t29_fixture' );
	return array(
		'users'     => $removed_users,
		'posts'     => $removed_posts,
		'remaining' => $remaining,
	);
}
