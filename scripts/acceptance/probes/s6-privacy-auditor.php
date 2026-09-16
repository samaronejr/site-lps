<?php
/**
 * S6 — Privacy auditor: review and block.
 *
 * Signs in as the clean privacy-auditor account, inspects a record carrying a
 * proposed public photograph and a public contact address, records a block on
 * the missing rights evidence, proves the item stays non-public, escalates a
 * takedown with the publisher, and attempts the forbidden publish/edit.
 *
 * @package LPS\Acceptance
 */

require_once '/lps-probes/lib.php';

use LPS\ContentModel\Audit;
use LPS\ContentModel\Roles;

t29_guard_staging();
$fixture = get_option( '_t29_fixture' );
$page_id = (int) $fixture['page_privacy'];
$news_id = (int) $fixture['news_takedown'];
$uid     = t29_become( 't29.fabia.auditor' );

// Step 1: the auditor session holds review and audit capability but no
// editorial write path.
t29_check( 'session:review-capable', Roles::current_user_can_action( 'review' ), true );
t29_check( 'session:audit-capable', Roles::current_user_can_action( 'audit' ), true );
t29_check( 'session:no-edit', ! Roles::current_user_can_action( 'edit' ), Roles::current_user_can_action( 'edit' ) );

// The record under review: a page proposing a public contact address and a
// photograph whose attachment has no recorded rights or privacy review.
$contact = get_post_meta( $page_id, '_lps_report_contact', true );
$rights  = get_post_meta( (int) $fixture['unreviewed'], '_lps_media_rights_status', true );
$privacy = get_post_meta( (int) $fixture['unreviewed'], '_lps_media_privacy_status', true );
t29_check( 'inspect:contact-present', '' !== $contact, $contact );
t29_check( 'inspect:rights-unresolved', 'cleared' !== $rights && 'reviewed' !== $privacy, array( $rights, $privacy ) );

// Step 2: record the block on the missing rights evidence; the item stays
// non-public.
Audit::record(
	'review',
	$page_id,
	0,
	array(
		'verdict' => 'blocked',
		'finding' => 'photo-rights-evidence-missing',
		'field'   => '_lps_media_rights_status',
	)
);
t29_audit_check( 'audit:block-recorded', $page_id, 'review', $uid );
t29_check( 'block:still-draft', 'draft' === get_post_status( $page_id ), get_post_status( $page_id ) );

// The publish gate enforces the block: a publish-capable session that can
// edit the page (its administrator author) is denied by the media-rights
// contract, not by the auditor's note.
wp_set_current_user( (int) $fixture['accounts']['administrator'] );
$gated = t29_rest( 'POST', '/wp/v2/pages/' . $page_id, array( 'status' => 'publish' ) );
t29_check(
	'block:publish-gate',
	400 === $gated['status'] && 'publish' !== get_post_status( $page_id ),
	array( $gated['status'], $gated['code'], get_post_status( $page_id ) )
);
wp_set_current_user( $uid );

// Step 3: escalate a takedown request together with the publisher. The request
// and the action are recorded with their actors; provenance is intact.
Audit::record(
	'review',
	$news_id,
	0,
	array(
		'verdict' => 'takedown-request',
		'finding' => 'rights-evidence-under-review',
	)
);
t29_audit_check( 'audit:takedown-request', $news_id, 'review', $uid );
$provenance_before = get_post_meta( $news_id, '_lps_claim_source_url', true );
wp_set_current_user( (int) $fixture['accounts']['publisher'] );
$unpublish = t29_rest( 'POST', '/wp/v2/news/' . $news_id, array( 'status' => 'draft' ) );
t29_check( 'takedown:unpublished', 200 === $unpublish['status'] && 'draft' === get_post_status( $news_id ), array( $unpublish['status'], get_post_status( $news_id ) ) );
t29_audit_check( 'audit:takedown-unpublish', $news_id, 'unpublish', (int) $fixture['accounts']['publisher'] );
t29_check( 'takedown:provenance-intact', $provenance_before === get_post_meta( $news_id, '_lps_claim_source_url', true ) && '' !== $provenance_before, get_post_meta( $news_id, '_lps_claim_source_url', true ) );
wp_set_current_user( $uid );

// Step 4 (forbidden): publish or edit editorial content.
$publish = t29_rest( 'POST', '/wp/v2/news/' . $news_id, array( 'status' => 'publish' ) );
t29_check( 'forbidden:publish-denied', 403 === $publish['status'], array( $publish['status'], $publish['code'] ) );
$edit = t29_rest( 'POST', '/wp/v2/pages/' . $page_id, array( 'title' => 'Auditor edit attempt' ) );
t29_check( 'forbidden:edit-denied', 403 === $edit['status'] && 'Auditor edit attempt' !== get_post( $page_id )->post_title, array( $edit['status'], $edit['code'] ) );

t29_audit_chain_check();
t29_emit( 's6-privacy-auditor', array( 'page_id' => $page_id, 'news_id' => $news_id ) );
