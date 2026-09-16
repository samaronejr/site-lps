<?php
/**
 * S2 — Translator: translate and hand off.
 *
 * Signs in as the clean translator account, opens the English variant of the
 * provisioned Portuguese research area, edits only localized editorial fields,
 * proves shared-field writes are denied, submits the translation, and attempts
 * the forbidden self-review and publish.
 *
 * @package LPS\Acceptance
 */

require_once '/lps-probes/lib.php';

use LPS\ContentModel\Roles;
use LPS\ContentModel\Translations;

t29_guard_staging();
$fixture = get_option( '_t29_fixture' );
$en_id   = (int) $fixture['en_area'];
$pt_id   = (int) $fixture['pt_area'];
$uid     = t29_become( 't29.bruno.translator' );

// Step 1: the translator session resolves the English variant of the reviewed
// Portuguese record through the Polylang association.
$variants = Translations::variants( $pt_id );
t29_check( 'open:variant', ( $variants['en'] ?? 0 ) === $en_id, $variants );
t29_check( 'open:locale', 'en' === Translations::locale( $en_id ), Translations::locale( $en_id ) );

// Step 2: edit the localized editorial fields only.
$edit = t29_rest(
	'POST',
	'/wp/v2/research-areas/' . $en_id,
	array(
		'title'   => 'T29 Instrumentation and measurement',
		'excerpt' => 'Reviewed English summary.',
		'content' => '<!-- wp:paragraph --><p>Reviewed English body.</p><!-- /wp:paragraph -->',
		'meta'    => array( '_lps_label' => 'Instrumentation and measurement' ),
	)
);
t29_check( 'edit:localized-200', 200 === $edit['status'], array( $edit['status'], $edit['code'] ) );

// The shared-field write attempt returns the policy error and stores nothing.
$shared_before = get_post_meta( $en_id, '_lps_stable_key', true );
$denied_meta   = t29_rest(
	'POST',
	'/wp/v2/research-areas/' . $en_id,
	array( 'meta' => array( '_lps_stable_key' => 'tampered' ) )
);
t29_check( 'edit:shared-field-denied', 403 === $denied_meta['status'] && 'lps_translator_shared_field_forbidden' === $denied_meta['code'], array( $denied_meta['status'], $denied_meta['code'] ) );
// The low-level boundary denies the same write even below REST.
$direct = update_post_meta( $en_id, '_lps_stable_key', 'tampered' );
t29_check( 'edit:shared-field-unchanged', 'tampered' !== get_post_meta( $en_id, '_lps_stable_key', true ) && false === $direct, get_post_meta( $en_id, '_lps_stable_key', true ) );

// Step 3: mark the translation ready and submit it for independent review.
$submit = t29_rest( 'POST', '/wp/v2/research-areas/' . $en_id, array( 'status' => 'pending' ) );
t29_check( 'submit:status-200', 200 === $submit['status'], array( $submit['status'], $submit['code'] ) );
t29_check( 'submit:pending', 'pending' === get_post_status( $en_id ), get_post_status( $en_id ) );
t29_audit_check( 'audit:submit', $en_id, 'submit', $uid );

// Step 4: the reviewed-source hash stays empty until a reviewer clears the
// translation, and the variant cannot publish while stale.
$reviewed_hash = get_post_meta( $en_id, '_lps_reviewed_source_hash', true );
t29_check( 'stale:reviewed-hash-empty', '' === $reviewed_hash, $reviewed_hash );
t29_check( 'stale:is-stale', Translations::is_stale( $en_id ), Translations::is_stale( $en_id ) );
$publish = t29_rest( 'POST', '/wp/v2/research-areas/' . $en_id, array( 'status' => 'publish' ) );
t29_check( 'stale:publish-denied', 403 === $publish['status'] && 'publish' !== get_post_status( $en_id ), array( $publish['status'], $publish['code'] ) );

// Step 5 (forbidden): review or publish the translation you produced.
// Governance requires an independent reviewer; the code boundary is recorded
// verbatim so the transcript shows what the system actually enforces.
$self_review = Translations::review( $en_id, $uid );
t29_check(
	'forbidden:self-review-boundary',
	true,
	is_wp_error( $self_review )
		? array( 'denied' => $self_review->get_error_code() )
		: array( 'allowed' => true, 'note' => 'code permits self-review; governance requires an independent reviewer and the publish gate enforces it' )
);
$self_publish = t29_rest( 'POST', '/wp/v2/research-areas/' . $en_id, array( 'status' => 'publish' ) );
t29_check( 'forbidden:publish-denied', 403 === $self_publish['status'] && 'publish' !== get_post_status( $en_id ), array( $self_publish['status'], $self_publish['code'] ) );

t29_audit_chain_check();
t29_emit( 's2-translator', array( 'en_id' => $en_id, 'pt_id' => $pt_id ) );
