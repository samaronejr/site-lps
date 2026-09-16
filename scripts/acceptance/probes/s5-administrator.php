<?php
/**
 * S5 — Administrator: provision, configure, import.
 *
 * Signs in as the clean administrator account (MFA enrolled), creates one
 * individual account through the documented account boundary, proves the
 * shared/role-named account rejection, updates one site setting with an audit
 * row, and attempts the forbidden publish-gate bypass. The import dry-run and
 * idempotent re-apply run as real `wp lps` commands in the runner.
 *
 * @package LPS\Acceptance
 */

require_once '/lps-probes/lib.php';
require_once ABSPATH . 'wp-admin/includes/user.php';

use LPS\ContentModel\Audit;
use LPS\ContentModel\Roles;

t29_guard_staging();
$fixture = get_option( '_t29_fixture' );
$uid     = t29_become( 't29.fatima.admin' );

// Step 1: create one individual account and assign one role plus collections.
// A leftover account from a prior run is removed first so the step is clean.
t29_check( 'account:create-capable', current_user_can( 'create_users' ), current_user_can( 'create_users' ) );
$existing = get_user_by( 'login', 't29.helena.contributor' );
if ( $existing instanceof WP_User ) {
	wp_delete_user( $existing->ID );
}
$_POST = array(
	'user_login' => 't29.helena.contributor',
	'email'      => 't29-helena@t29.invalid',
	'pass1'      => t29_secret( 'T29_PASSWORD' ),
	'pass2'      => t29_secret( 'T29_PASSWORD' ),
	'nickname'   => 't29.helena.contributor',
	'role'       => 'lps_contributor',
);
$created = edit_user( 0 );
t29_check( 'account:individual-created', ! is_wp_error( $created ) && 0 < $created, is_wp_error( $created ) ? $created->get_error_message() : $created );
if ( ! is_wp_error( $created ) ) {
	update_user_meta( (int) $created, Roles::COLLECTIONS_META, array( 'news' ) );
	$assigned = Roles::assigned_collections( (int) $created );
	t29_check( 'account:collections-assigned', array( 'news' ) === $assigned, $assigned );
	t29_check( 'account:role', 'contributor' === Roles::policy_role( get_user_by( 'id', (int) $created ) ), Roles::policy_role( get_user_by( 'id', (int) $created ) ) );
}

// Step 2: a role-named or shared account creation attempt is rejected at the
// account boundary.
$_POST = array(
	'user_login' => 'lps-publisher',
	'email'      => 'shared@t29.invalid',
	'pass1'      => t29_secret( 'T29_PASSWORD' ),
	'pass2'      => t29_secret( 'T29_PASSWORD' ),
	'nickname'   => 'lps-publisher',
	'role'       => 'lps_publisher',
);
$shared = edit_user( 0 );
t29_check(
	'account:shared-rejected',
	is_wp_error( $shared ) && 'lps_shared_account_forbidden' === $shared->get_error_code() && ! get_user_by( 'login', 'lps-publisher' ),
	is_wp_error( $shared ) ? $shared->get_error_code() : 'created'
);

// Step 3: update one site setting from an official record; the audit row
// records the settings action with this actor.
$settings = get_option( 'lps_site_settings', array() );
$settings = is_array( $settings ) ? $settings : array();
$before   = $settings['tagline_en'] ?? '';
$settings['tagline_en'] = 'Signal processing at UFRJ (t29)';
$write    = t29_rest( 'POST', '/wp/v2/settings', array( 'lps_site_settings' => $settings ) );
t29_check( 'settings:updated', 200 === $write['status'], array( $write['status'], $write['code'] ) );
$stored = get_option( 'lps_site_settings', array() );
t29_check( 'settings:stored', ( $stored['tagline_en'] ?? '' ) === 'Signal processing at UFRJ (t29)', $stored['tagline_en'] ?? '' );
$settings_rows = array();
foreach ( Audit::entries( 1000 ) as $entry ) {
	if ( 'settings' === $entry['action'] && (int) $entry['actor_user_id'] === $uid ) {
		$settings_rows[] = $entry;
	}
}
t29_check( 'audit:settings', count( $settings_rows ) > 0, count( $settings_rows ) );
// Restore the prior value so the simulation leaves no configuration drift.
$settings['tagline_en'] = $before;
t29_rest( 'POST', '/wp/v2/settings', array( 'lps_site_settings' => $settings ) );

// Step 4/5: `wp lps import dry-run` and the idempotent re-apply run as real
// CLI commands in the runner; this probe asserts the capability boundary the
// handbook documents for the role.
t29_check( 'import:capability', Roles::current_user_can_action( 'import' ), true );

// Step 6 (forbidden): bypass a publish gate through administrative access. The
// gate binds administrators too: a record missing a required field is denied.
$gated = t29_insert(
	'lps_news',
	array(
		'post_title'   => 'T29 admin gate bypass attempt',
		'post_excerpt' => 'Gate fixture.',
		'post_content' => '<!-- wp:paragraph --><p>Gate body.</p><!-- /wp:paragraph -->',
	),
	array( '_lps_locale' => 'pt-br' )
);
$bypass = t29_rest( 'POST', '/wp/v2/news/' . $gated, array( 'status' => 'publish' ) );
t29_check(
	'forbidden:gate-binds-admin',
	400 === $bypass['status'] && 'lps_required_canonical_date' === $bypass['code'] && 'draft' === get_post_status( $gated ),
	array( $bypass['status'], $bypass['code'], get_post_status( $gated ) )
);

t29_audit_chain_check();
t29_emit( 's5-administrator', array( 'created_user' => is_wp_error( $created ) ? 0 : (int) $created, 'gated' => $gated ) );
