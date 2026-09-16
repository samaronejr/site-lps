<?php
/**
 * S4 — Publisher: publish, correct, unpublish, restore.
 *
 * Signs in as the clean publisher account, proves the MFA boundary, publishes
 * the reviewed records in both locales, releases a correction with the prior
 * revision retained, unpublishes and restores, changes a published slug with a
 * one-hop redirect, and attempts the forbidden gated publish.
 *
 * @package LPS\Acceptance
 */

require_once '/lps-probes/lib.php';

use LPS\ContentModel\MFA;
use LPS\ContentModel\Roles;

t29_guard_staging();
$fixture = get_option( '_t29_fixture' );
$en_id   = (int) $fixture['en_area'];
$pt_id   = (int) $fixture['pt_area'];

// Step 1: the MFA boundary. The unenrolled publisher session keeps no publish
// capability; the enrolled publisher session does.
$nomfa = (int) $fixture['accounts']['publisher-nomfa'];
wp_set_current_user( $nomfa );
t29_check( 'mfa:unenrolled-locked', ! Roles::current_user_can_action( 'publish' ), Roles::current_user_can_action( 'publish' ) );
$news = get_posts(
	array(
		'post_type'   => 'lps_news',
		'post_status' => 'pending',
		'fields'      => 'ids',
	)
);
$submitted = 0;
foreach ( $news as $candidate ) {
	if ( 'T29 contributor submission' === get_post( $candidate )->post_title ) {
		$submitted = (int) $candidate;
	}
}
t29_check( 'mfa:reviewed-record-found', $submitted > 0, $news );
$locked = t29_rest( 'POST', '/wp/v2/news/' . $submitted, array( 'status' => 'publish' ) );
t29_check( 'mfa:publish-denied', 403 === $locked['status'] && 'publish' !== get_post_status( $submitted ), array( $locked['status'], $locked['code'] ) );

$uid = t29_become( 't29.diego.publisher' );
t29_check( 'mfa:enrolled', MFA::is_enrolled( $uid ), MFA::is_enrolled( $uid ) );
t29_check( 'mfa:publish-capable', Roles::current_user_can_action( 'publish' ), true );

// Step 2: publish the reviewed revision. The English variant publishes first
// (it is the reviewed variant), then the Portuguese authority; both public
// URLs are asserted by the runner over HTTP.
$publish_en = t29_rest( 'POST', '/wp/v2/research-areas/' . $en_id, array( 'status' => 'publish' ) );
t29_check( 'publish:en-200', 200 === $publish_en['status'] && 'publish' === get_post_status( $en_id ), array( $publish_en['status'], $publish_en['code'] ) );
$publish_pt = t29_rest( 'POST', '/wp/v2/research-areas/' . $pt_id, array( 'status' => 'publish' ) );
t29_check( 'publish:pt-200', 200 === $publish_pt['status'] && 'publish' === get_post_status( $pt_id ), array( $publish_pt['status'], $publish_pt['code'] ) );
$publish_news = t29_rest( 'POST', '/wp/v2/news/' . $submitted, array( 'status' => 'publish' ) );
t29_check( 'publish:news-200', 200 === $publish_news['status'] && 'publish' === get_post_status( $submitted ), array( $publish_news['status'], $publish_news['code'] ) );
t29_audit_check( 'audit:publish', $submitted, 'publish', $uid );
$permalink = get_permalink( $submitted );
$pt_link   = get_permalink( $pt_id );
$en_link   = get_permalink( $en_id );

// Step 3: release a correction; the prior revision remains available.
$before_revision = wp_get_post_revisions( $submitted, array( 'posts_per_page' => 1, 'order' => 'DESC' ) );
$before_revision = reset( $before_revision );
$correction      = t29_rest(
	'POST',
	'/wp/v2/news/' . $submitted,
	array( 'content' => '<!-- wp:paragraph --><p>Contributor draft body, corrected.</p><!-- /wp:paragraph -->' )
);
t29_check( 'correct:status-200', 200 === $correction['status'], array( $correction['status'], $correction['code'] ) );
$revisions = wp_get_post_revisions( $submitted );
t29_check( 'correct:prior-revision-retained', $before_revision instanceof WP_Post && isset( $revisions[ $before_revision->ID ] ), count( $revisions ) );

// Step 4: unpublish, then restore the previous approved revision; the trail
// stays attributable and reversible.
$unpublish = t29_rest( 'POST', '/wp/v2/news/' . $submitted, array( 'status' => 'draft' ) );
t29_check( 'unpublish:draft', 200 === $unpublish['status'] && 'draft' === get_post_status( $submitted ), array( $unpublish['status'], get_post_status( $submitted ) ) );
t29_audit_check( 'audit:unpublish', $submitted, 'unpublish', $uid );
$restored = $before_revision instanceof WP_Post ? wp_restore_post_revision( $before_revision->ID ) : false;
t29_check( 'restore:revision', false !== $restored && (int) $restored === $submitted, $restored );
$republish = t29_rest( 'POST', '/wp/v2/news/' . $submitted, array( 'status' => 'publish' ) );
t29_check( 'restore:republished', 200 === $republish['status'] && 'publish' === get_post_status( $submitted ), array( $republish['status'], $republish['code'] ) );

// Step 5: change a published slug. The first published slug is immutable while
// the record is live, so the documented path is unpublish, rename, republish,
// then register the one-hop redirect.
$rename_live = t29_rest( 'POST', '/wp/v2/news/' . $submitted, array( 'slug' => 't29-contributor-submission-renamed' ) );
$live_slug   = get_post( $submitted )->post_name;
t29_check( 'slug:immutable-while-published', 't29-contributor-submission' === $live_slug, array( $rename_live['status'], $rename_live['code'], $live_slug ) );
t29_rest( 'POST', '/wp/v2/news/' . $submitted, array( 'status' => 'draft' ) );
$rename = t29_rest( 'POST', '/wp/v2/news/' . $submitted, array( 'slug' => 't29-contributor-submission-renamed' ) );
t29_check( 'slug:renamed', 200 === $rename['status'] && 't29-contributor-submission-renamed' === get_post( $submitted )->post_name, array( $rename['status'], get_post( $submitted )->post_name ) );
t29_rest( 'POST', '/wp/v2/news/' . $submitted, array( 'status' => 'publish' ) );
$old_path = wp_parse_url( (string) $permalink, PHP_URL_PATH );
$new_path = wp_parse_url( (string) get_permalink( $submitted ), PHP_URL_PATH );
t29_check( 'slug:paths-differ', $old_path !== $new_path, array( $old_path, $new_path ) );
t29_check( 'redirect:authorized', Roles::current_user_can_action( 'redirect' ), true );
$redirect = t29_insert(
	'lps_redirect',
	array(
		'post_title'   => 'T29 slug redirect',
		'post_excerpt' => 'One-hop redirect for the renamed record.',
		'post_content' => '<!-- wp:paragraph --><p>Redirect record.</p><!-- /wp:paragraph -->',
		'post_status'  => 'publish',
	),
	array(
		'_lps_locale'              => 'pt-br',
		'_lps_redirect_source'     => $old_path,
		'_lps_redirect_target'     => $new_path,
		'_lps_redirect_status'     => 301,
		'_lps_redirect_reason'     => 'published slug renamed',
		'_lps_redirect_provenance' => 'https://lps.ufrj.br/redirect-provenance',
	)
);
t29_check( 'redirect:published', 'publish' === get_post_status( $redirect ), get_post_status( $redirect ) );
t29_audit_check( 'audit:redirect', $redirect, 'redirect', $uid );

// Step 6 (forbidden): publish a record with an unresolved gate. A news draft
// without the required canonical date is denied by name and stays draft.
$gated = t29_insert(
	'lps_news',
	array(
		'post_title'   => 'T29 gated record',
		'post_excerpt' => 'Gate fixture.',
		'post_content' => '<!-- wp:paragraph --><p>Gate body.</p><!-- /wp:paragraph -->',
	),
	array( '_lps_locale' => 'pt-br' )
);
$gated_publish = t29_rest( 'POST', '/wp/v2/news/' . $gated, array( 'status' => 'publish' ) );
t29_check(
	'forbidden:gate-blocks',
	400 === $gated_publish['status'] && 'lps_required_canonical_date' === $gated_publish['code'] && 'draft' === get_post_status( $gated ),
	array( $gated_publish['status'], $gated_publish['code'], get_post_status( $gated ) )
);

t29_audit_chain_check();
t29_emit(
	's4-publisher',
	array(
		'news_id'    => $submitted,
		'pt_id'      => $pt_id,
		'en_id'      => $en_id,
		'redirect'   => $redirect,
		'gated'      => $gated,
		'urls'       => array(
			'news'      => $permalink,
			'news_new'  => get_permalink( $submitted ),
			'pt_area'   => $pt_link,
			'en_area'   => $en_link,
			'old_path'  => $old_path,
			'new_path'  => $new_path,
		),
	)
);
