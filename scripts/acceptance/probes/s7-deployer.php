<?php
/**
 * S7 — Deployer: deploy and roll back (credential boundary).
 *
 * The deploy and rollback steps run as real `staging.mjs` commands in the
 * runner. This probe proves the deployer account's editorial boundary: the
 * credential carries no content or review capability.
 *
 * @package LPS\Acceptance
 */

require_once '/lps-probes/lib.php';

use LPS\ContentModel\Roles;

t29_guard_staging();
$fixture = get_option( '_t29_fixture' );
$uid     = t29_become( 't29.gabriel.deployer' );

t29_check( 'session:role', 'deployer' === Roles::policy_role( get_user_by( 'id', $uid ) ), Roles::policy_role( get_user_by( 'id', $uid ) ) );

// Forbidden: edit public content or approve an editorial revision with the
// deploy credential.
$create = t29_rest(
	'POST',
	'/wp/v2/news',
	array(
		'title'   => 'T29 deployer write attempt',
		'excerpt' => 'x',
		'content' => 'x',
		'status'  => 'draft',
		'meta'    => array( '_lps_locale' => 'pt-br', '_lps_canonical_date' => '2026-09-15T00:00:00Z' ),
	)
);
t29_check( 'forbidden:create-denied', 403 === $create['status'], array( $create['status'], $create['code'] ) );

$edit = t29_rest( 'POST', '/wp/v2/news/' . (int) $fixture['news_takedown'], array( 'title' => 'Deployer edit attempt' ) );
t29_check( 'forbidden:edit-denied', 403 === $edit['status'], array( $edit['status'], $edit['code'] ) );

$review = t29_rest(
	'POST',
	'/wp/v2/news/' . (int) $fixture['news_takedown'],
	array( 'meta' => array( '_lps_state' => 'in_review' ) )
);
t29_check( 'forbidden:review-denied', 403 === $review['status'], array( $review['status'], $review['code'] ) );

$publish = t29_rest( 'POST', '/wp/v2/news/' . (int) $fixture['news_takedown'], array( 'status' => 'publish' ) );
t29_check( 'forbidden:publish-denied', 403 === $publish['status'], array( $publish['status'], $publish['code'] ) );

$deployer_publish_rows = array();
foreach ( t29_audit_for( (int) $fixture['news_takedown'], 'publish' ) as $row ) {
	if ( (int) $row['actor_user_id'] === $uid ) {
		$deployer_publish_rows[] = $row;
	}
}
t29_check( 'forbidden:no-audit-writes', 0 === count( $deployer_publish_rows ), count( $deployer_publish_rows ) );

t29_audit_chain_check();
t29_emit( 's7-deployer', array( 'deployer_id' => $uid ) );
