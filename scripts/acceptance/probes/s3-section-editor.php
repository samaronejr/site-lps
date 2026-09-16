<?php
/**
 * S3 — Section editor: review, archive, hand off.
 *
 * Signs in as the clean section-editor account, rejects an incomplete
 * submission with a recorded finding, approves the contributor's resubmission,
 * reviews the translator's English variant, proves the stale-translation
 * publish gate, archives a superseded record, and attempts the forbidden
 * self-publish.
 *
 * @package LPS\Acceptance
 */

require_once '/lps-probes/lib.php';

use LPS\ContentModel\Audit;
use LPS\ContentModel\Roles;
use LPS\ContentModel\Translations;

t29_guard_staging();
$fixture = get_option( '_t29_fixture' );
$en_id   = (int) $fixture['en_area'];
$pt_id   = (int) $fixture['pt_area'];
$uid     = t29_become( 't29.carla.editor' );

// Step 1: the review queue for an assigned collection. The contributor's
// pending submission is visible to the editor's collection scope.
$queue = get_posts(
	array(
		'post_type'   => 'lps_news',
		'post_status' => 'pending',
		'fields'      => 'ids',
	)
);
$submitted = 0;
foreach ( $queue as $candidate ) {
	if ( 'T29 contributor submission' === get_post( $candidate )->post_title ) {
		$submitted = (int) $candidate;
	}
}
t29_check( 'queue:submission-visible', $submitted > 0, $queue );

// Step 2: reject a record with a missing source. A second contributor
// submission without provenance returns to draft with the finding recorded.
$incomplete = t29_insert(
	'lps_news',
	array(
		'post_title'   => 'T29 incomplete submission',
		'post_excerpt' => 'Missing source fixture.',
		'post_content' => '<!-- wp:paragraph --><p>No provenance.</p><!-- /wp:paragraph -->',
		'post_status'  => 'pending',
		'post_author'  => (int) $fixture['accounts']['contributor'],
	),
	array(
		'_lps_locale'         => 'pt-br',
		'_lps_canonical_date' => '2026-09-15T00:00:00Z',
	)
);
$reject = t29_rest( 'POST', '/wp/v2/news/' . $incomplete, array( 'status' => 'draft' ) );
t29_check( 'reject:returned-to-draft', 200 === $reject['status'] && 'draft' === get_post_status( $incomplete ), array( $reject['status'], get_post_status( $incomplete ) ) );
Audit::record( 'review', $incomplete, 0, array( 'verdict' => 'rejected', 'finding' => 'missing-source' ) );
t29_audit_check( 'audit:reject-finding', $incomplete, 'review', $uid );

// Step 3: approve the complete revision and send it to a publisher. The
// editorial state moves draft -> in_review and the ledger records the review.
$approve = t29_rest(
	'POST',
	'/wp/v2/news/' . $submitted,
	array( 'meta' => array( '_lps_state' => 'in_review' ) )
);
t29_check( 'approve:status-200', 200 === $approve['status'], array( $approve['status'], $approve['code'] ) );
t29_check( 'approve:in-review', 'in_review' === get_post_meta( $submitted, '_lps_state', true ), get_post_meta( $submitted, '_lps_state', true ) );
t29_audit_check( 'audit:review', $submitted, 'review', $uid );

// Step 4: review the translation produced by the translator account and clear
// it; the reviewed hash and reviewer id are stored.
$review = Translations::review( $en_id, $uid );
t29_check( 'translation:reviewed', ! is_wp_error( $review ), is_wp_error( $review ) ? $review->get_error_message() : $review );
t29_check(
	'translation:hash-stored',
	! is_wp_error( $review ) && get_post_meta( $en_id, '_lps_reviewed_source_hash', true ) === $review['source_hash'],
	get_post_meta( $en_id, '_lps_reviewed_source_hash', true )
);
t29_check(
	'translation:reviewer-stored',
	(int) get_post_meta( $en_id, '_lps_translation_reviewer_id', true ) === $uid,
	get_post_meta( $en_id, '_lps_translation_reviewer_id', true )
);
t29_check( 'translation:fresh', ! Translations::is_stale( $en_id ), Translations::is_stale( $en_id ) );

// A source edit after review makes the variant stale again; the publish gate
// then denies it by name until an independent reviewer re-clears it.
$source_edit = t29_rest(
	'POST',
	'/wp/v2/research-areas/' . $pt_id,
	array( 'excerpt' => 'Area fixture pt-br, revista.' )
);
t29_check( 'stale:source-edit', 200 === $source_edit['status'], array( $source_edit['status'], $source_edit['code'] ) );
t29_check( 'stale:variant-stale', Translations::is_stale( $en_id ), Translations::is_stale( $en_id ) );

// A publish-capable session (enrolled publisher) hits the named stale gate.
wp_set_current_user( (int) $fixture['accounts']['publisher'] );
$stale_publish = t29_rest( 'POST', '/wp/v2/research-areas/' . $en_id, array( 'status' => 'publish' ) );
t29_check(
	'stale:publish-gate',
	400 === $stale_publish['status'] && 'lps_english_source_review_required' === $stale_publish['code'],
	array( $stale_publish['status'], $stale_publish['code'] )
);
wp_set_current_user( $uid );

// The independent reviewer clears the refreshed source.
$review2 = Translations::review( $en_id, $uid );
t29_check( 'stale:re-reviewed', ! is_wp_error( $review2 ) && ! Translations::is_stale( $en_id ), is_wp_error( $review2 ) ? $review2->get_error_message() : Translations::is_stale( $en_id ) );

// Step 5: archive a superseded record; relationships and provenance survive
// and the state is archived.
$superseded = t29_insert(
	'lps_news',
	array(
		'post_title'   => 'T29 superseded record',
		'post_excerpt' => 'Superseded fixture.',
		'post_content' => '<!-- wp:paragraph --><p>Superseded body.</p><!-- /wp:paragraph -->',
	),
	array(
		'_lps_locale'           => 'pt-br',
		'_lps_canonical_date'   => '2026-09-15T00:00:00Z',
		'_lps_claim_source_url' => 'https://lps.ufrj.br/superseded-source',
	)
);
t29_check( 'archive:authorized', Roles::current_user_can_action( 'archive', 'news' ), true );
wp_update_post(
	array(
		'ID'          => $superseded,
		'post_status' => 'lps_archived',
		'meta_input'  => array(
			'_lps_state'       => 'archived',
			'_lps_archived_at' => gmdate( 'c' ),
		),
	)
);
t29_check( 'archive:status', 'lps_archived' === get_post_status( $superseded ), get_post_status( $superseded ) );
t29_check( 'archive:state', 'archived' === get_post_meta( $superseded, '_lps_state', true ), get_post_meta( $superseded, '_lps_state', true ) );
t29_check( 'archive:provenance', 'https://lps.ufrj.br/superseded-source' === get_post_meta( $superseded, '_lps_claim_source_url', true ), get_post_meta( $superseded, '_lps_claim_source_url', true ) );
t29_audit_check( 'audit:archive', $superseded, 'archive', $uid );

// Step 6 (forbidden): publish your own reviewed revision.
$self_publish = t29_rest( 'POST', '/wp/v2/news/' . $submitted, array( 'status' => 'publish' ) );
t29_check( 'forbidden:publish-denied', 403 === $self_publish['status'] && 'publish' !== get_post_status( $submitted ), array( $self_publish['status'], $self_publish['code'] ) );

t29_audit_chain_check();
t29_emit( 's3-section-editor', array( 'submitted' => $submitted, 'incomplete' => $incomplete, 'superseded' => $superseded ) );
