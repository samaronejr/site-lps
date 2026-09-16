<?php
/**
 * Todo 29 acceptance cleanup: removes every simulation account and record.
 *
 * Simulation posts are marked `_lps_t29`. The sweep also removes records in
 * statuses WP_Query 'any' cannot see (trash, lps_archived, inherit) and clears
 * relationship rows in both directions, so the staging corpus returns to its
 * pre-run state and no leftover holds a slug for the next run.
 *
 * @package LPS\Acceptance
 */

require_once '/lps-probes/lib.php';

t29_guard_staging();

$sweep = t29_sweep();
t29_check( 'posts:removed', array() === $sweep['remaining'], $sweep );

// Residue assertion: nothing the next run could collide with may survive.
global $wpdb;
$residue = (int) $wpdb->get_var(
	"SELECT COUNT(*) FROM {$wpdb->posts} p
	 LEFT JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_lps_t29'
	 WHERE m.meta_value = '1' OR p.post_name LIKE 't29-%'"
);
$residue_users = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->users} WHERE user_login LIKE 't29.%'" );
t29_check( 'residue:none', 0 === $residue && 0 === $residue_users, array( 'posts' => $residue, 'users' => $residue_users ) );

// The audit ledger is append-only; its rows are the evidence and stay.
t29_audit_chain_check();
t29_emit( 'cleanup', array( 'posts_removed' => $sweep['posts'], 'users_removed' => $sweep['users'] ) );
