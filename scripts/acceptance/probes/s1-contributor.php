<?php
/**
 * S1 — Contributor: create and submit.
 *
 * Signs in as the clean contributor account, creates a draft in the assigned
 * news collection with provenance, attaches one relationship and one
 * rights-cleared media asset, submits for review, then attempts the forbidden
 * publish action.
 *
 * @package LPS\Acceptance
 */

require_once '/lps-probes/lib.php';

use LPS\ContentModel\Relationships;
use LPS\ContentModel\Roles;

t29_guard_staging();
$fixture = get_option( '_t29_fixture' );
$uid     = t29_become( 't29.ana.contributor' );

// Step 1: the dashboard exposes only assigned collections.
$assigned = Roles::assigned_collections( $uid );
t29_check( 'collections:assigned', array( 'news' ) === $assigned, $assigned );
t29_check( 'collections:scope-enforced', ! Roles::current_user_can_action( 'create', 'person' ), Roles::current_user_can_action( 'create', 'person' ) );

// Step 2: create a draft in the assigned collection with provenance, owner and
// review date.
$create = t29_rest(
	'POST',
	'/wp/v2/news',
	array(
		'title'   => 'T29 contributor submission',
		'excerpt' => 'Contributor draft summary.',
		'content' => '<!-- wp:paragraph --><p>Contributor draft body.</p><!-- /wp:paragraph -->',
		'status'  => 'draft',
		'slug'    => 't29-contributor-submission',
		'meta'    => array(
			'_lps_locale'           => 'pt-br',
			'_lps_state'            => 'draft',
			'_lps_canonical_date'   => '2026-09-15T00:00:00Z',
			'_lps_claim_source_url' => 'https://lps.ufrj.br/contributor-source',
			'_lps_claim_reviewed_at' => '2026-09-15',
			'_lps_review_date'      => '2027-01-01',
		),
	)
);
t29_check( 'create:status-201', 201 === $create['status'], $create['status'] );
$post_id = (int) ( $create['data']['id'] ?? 0 );
update_post_meta( $post_id, '_lps_t29', '1' );
t29_check( 'create:draft', 'draft' === get_post_status( $post_id ), get_post_status( $post_id ) );
// The accountable owner is a private field (never REST-writable); the record
// owner is assigned through the meta boundary the editor panel uses.
update_post_meta( $post_id, '_lps_owner_user_id', $uid );
t29_check( 'create:owner', (int) get_post_meta( $post_id, '_lps_owner_user_id', true ) === $uid, get_post_meta( $post_id, '_lps_owner_user_id', true ) );

// Step 3: attach one existing relationship and one rights-cleared media asset
// with per-usage alternative text.
t29_check( 'relate:authorized', Roles::current_user_can_action( 'edit', 'news' ), true );
$rel = Relationships::replace(
	$post_id,
	'related_record',
	array(
		array(
			'target_post_id'    => (int) $fixture['person'],
			'relationship_role' => 'related',
			'sort_order'        => 1,
			'start_date'        => '',
			'end_date'          => '',
			'public_visibility' => true,
		),
	)
);
t29_check( 'relate:attached', ! is_wp_error( $rel ) && 1 === ( $rel['count'] ?? 0 ), is_wp_error( $rel ) ? $rel->get_error_message() : $rel );
$media = t29_rest(
	'POST',
	'/wp/v2/news/' . $post_id,
	array(
		'content' => '<!-- wp:paragraph --><p>Contributor draft body.</p><!-- /wp:paragraph -->' .
			'<!-- wp:lps/figure {"mediaId":' . (int) $fixture['cleared'] . ',"locale":"pt-br","alt":"Laboratory bench with signal equipment","context":"submission","caption":"Laboratory bench"} /-->',
	)
);
t29_check( 'media:attached', 200 === $media['status'], $media['status'] );

// Step 4: submit for review. The WordPress status moves to pending and the
// ledger records the submit transition; the editorial state is still draft
// until a section editor reviews it.
$submit = t29_rest( 'POST', '/wp/v2/news/' . $post_id, array( 'status' => 'pending' ) );
t29_check( 'submit:status-200', 200 === $submit['status'], $submit['status'] );
t29_check( 'submit:pending', 'pending' === get_post_status( $post_id ), get_post_status( $post_id ) );
t29_check( 'submit:state-draft', 'draft' === get_post_meta( $post_id, '_lps_state', true ), get_post_meta( $post_id, '_lps_state', true ) );
t29_audit_check( 'audit:submit', $post_id, 'submit', $uid );

// Step 5 (forbidden): publish the same record. The action is denied, the state
// is unchanged and no new revision is created.
$revisions_before = count( wp_get_post_revisions( $post_id ) );
$denied           = t29_rest( 'POST', '/wp/v2/news/' . $post_id, array( 'status' => 'publish' ) );
t29_check( 'forbidden:publish-denied', 403 === $denied['status'], array( $denied['status'], $denied['code'] ) );
t29_check( 'forbidden:state-unchanged', 'pending' === get_post_status( $post_id ), get_post_status( $post_id ) );
t29_check( 'forbidden:no-revision', count( wp_get_post_revisions( $post_id ) ) === $revisions_before, count( wp_get_post_revisions( $post_id ) ) );
t29_check( 'forbidden:no-publish-audit', 0 === count( t29_audit_for( $post_id, 'publish' ) ), count( t29_audit_for( $post_id, 'publish' ) ) );

t29_audit_chain_check();
t29_emit( 's1-contributor', array( 'post_id' => $post_id ) );
