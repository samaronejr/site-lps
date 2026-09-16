<?php
/**
 * Todo 29 acceptance provisioning: clean accounts and clean fixtures.
 *
 * Runs as the CLI session (administrator-equivalent). Every account is
 * deleted and recreated so each run starts from a clean account; every
 * fixture post is marked `_lps_t29` and replaced when it already exists.
 *
 * @package LPS\Acceptance
 */

require_once '/lps-probes/lib.php';
require_once ABSPATH . 'wp-admin/includes/user.php';
require_once ABSPATH . 'wp-admin/includes/image.php';

use LPS\ContentModel\Roles;
use LPS\ContentModel\Translations;

t29_guard_staging();

// Clean slate first: a previous run may have been interrupted before its
// cleanup, and leftover records hold their slugs in any status.
$sweep = t29_sweep();
t29_check( 'sweep:clean-slate', array() === $sweep['remaining'], $sweep );

$accounts = array(
	'contributor'     => array( 'login' => 't29.ana.contributor', 'collections' => array( 'news' ), 'mfa' => false ),
	'translator'      => array( 'login' => 't29.bruno.translator', 'collections' => array( 'research-area' ), 'mfa' => false ),
	'section-editor'  => array( 'login' => 't29.carla.editor', 'collections' => array( 'news', 'research-area', 'page' ), 'mfa' => false ),
	'publisher'       => array( 'login' => 't29.diego.publisher', 'collections' => array(), 'mfa' => true ),
	'publisher-nomfa' => array( 'login' => 't29.elias.publisher', 'collections' => array(), 'mfa' => false, 'role' => 'publisher' ),
	'administrator'   => array( 'login' => 't29.fatima.admin', 'collections' => array(), 'mfa' => true ),
	'privacy-auditor' => array( 'login' => 't29.fabia.auditor', 'collections' => array(), 'mfa' => false ),
	'deployer'        => array( 'login' => 't29.gabriel.deployer', 'collections' => array(), 'mfa' => false ),
);

$ids = array();
foreach ( $accounts as $key => $account ) {
	$role = $account['role'] ?? $key;
	$uid  = t29_recreate_user( $account['login'], $role );
	if ( array() !== $account['collections'] ) {
		update_user_meta( $uid, Roles::COLLECTIONS_META, $account['collections'] );
	} else {
		delete_user_meta( $uid, Roles::COLLECTIONS_META );
	}
	if ( $account['mfa'] ) {
		t29_enroll_totp( $uid );
	}
	$ids[ $key ] = $uid;
	t29_check( 'account:' . $key, Roles::policy_role( get_user_by( 'id', $uid ) ) === $role, Roles::policy_role( get_user_by( 'id', $uid ) ) );
}
t29_check(
	'account:publisher-nomfa-unenrolled',
	! \LPS\ContentModel\MFA::is_enrolled( $ids['publisher-nomfa'] ),
	\LPS\ContentModel\MFA::is_enrolled( $ids['publisher-nomfa'] )
);

// --- fixtures ---------------------------------------------------------------

// One existing person record the contributor can relate to.
$person = get_posts(
	array(
		'post_type'   => 'lps_person',
		'post_status' => 'any',
		'numberposts' => 1,
		'fields'      => 'ids',
	)
);
t29_check( 'fixture:person-target', count( $person ) > 0, count( $person ) );

// A rights-cleared attachment the contributor may reference (uploads are not a
// contributor capability; the asset is staged by the fixture).
$uploads = wp_upload_dir();
$png     = base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==' );
$rel     = 'lps-t29/cleared.png';
$abs     = $uploads['basedir'] . '/' . $rel;
wp_mkdir_p( dirname( $abs ) );
file_put_contents( $abs, $png ); // phpcs:ignore WordPress.WP.AlternativeFunctions
$cleared = t29_insert(
	'attachment',
	array(
		'post_title'     => 'T29 cleared fixture image',
		'post_mime_type' => 'image/png',
		'post_status'    => 'inherit',
		'guid'           => $uploads['baseurl'] . '/' . $rel,
	),
	array()
);
update_post_meta( $cleared, '_wp_attached_file', $rel );
update_post_meta( $cleared, '_wp_attachment_metadata', array( 'width' => 1, 'height' => 1, 'file' => $rel ) );
foreach (
	array(
		'_lps_media_credit'           => 'LPS fixture',
		'_lps_media_rights_holder'    => 'LPS',
		'_lps_media_license'          => 'institutional',
		'_lps_media_source_url'       => 'https://lps.ufrj.br/fixture',
		'_lps_media_checksum'         => hash_file( 'sha256', $abs ),
		'_lps_media_width'            => 1,
		'_lps_media_height'           => 1,
		'_lps_media_rights_status'    => 'cleared',
		'_lps_media_privacy_status'   => 'reviewed',
		'_lps_media_document_status'  => 'not-required',
	) as $key => $value
) {
	update_post_meta( $cleared, $key, $value );
}
t29_check( 'fixture:cleared-attachment', $cleared > 0, $cleared );

// An attachment with unresolved rights/privacy evidence for the auditor gate.
$rel2 = 'lps-t29/unreviewed.png';
$abs2 = $uploads['basedir'] . '/' . $rel2;
file_put_contents( $abs2, $png ); // phpcs:ignore WordPress.WP.AlternativeFunctions
$unreviewed = t29_insert(
	'attachment',
	array(
		'post_title'     => 'T29 unreviewed fixture image',
		'post_mime_type' => 'image/png',
		'post_status'    => 'inherit',
		'guid'           => $uploads['baseurl'] . '/' . $rel2,
	),
	array()
);
update_post_meta( $unreviewed, '_wp_attached_file', $rel2 );
update_post_meta( $unreviewed, '_wp_attachment_metadata', array( 'width' => 1, 'height' => 1, 'file' => $rel2 ) );
foreach (
	array(
		'_lps_media_credit'          => 'LPS fixture',
		'_lps_media_rights_holder'   => 'LPS',
		'_lps_media_license'         => 'institutional',
		'_lps_media_source_url'      => 'https://lps.ufrj.br/fixture',
		'_lps_media_checksum'        => hash_file( 'sha256', $abs2 ),
		'_lps_media_width'           => 1,
		'_lps_media_height'          => 1,
		'_lps_media_rights_status'   => 'unknown',
		'_lps_media_privacy_status'  => 'pending',
		'_lps_media_document_status' => 'not-required',
	) as $key => $value
) {
	update_post_meta( $unreviewed, $key, $value );
}
t29_check( 'fixture:unreviewed-attachment', $unreviewed > 0, $unreviewed );

// The bilingual research-area pair the translator works on. The English
// variant is authored by the translator account so the translator's own
// edit_post capability applies; the section editor created the record.
$pt_area = t29_insert(
	'lps_research_area',
	array(
		'post_title'   => 'T29 Area de Instrumentacao',
		'post_excerpt' => 'Area fixture pt-br.',
		'post_content' => '<!-- wp:paragraph --><p>Conteudo fixture da area.</p><!-- /wp:paragraph -->',
		'post_name'    => 't29-area-instrumentacao',
	),
	array(
		'_lps_locale'      => 'pt-br',
		'_lps_stable_key'  => 'instrumentation',
		'_lps_label'       => 'Instrumentacao',
		'_lps_review_date' => '2027-01-01',
	)
);
$en_area = t29_insert(
	'lps_research_area',
	array(
		'post_title'   => 'T29 Instrumentation area',
		'post_excerpt' => 'English fixture summary.',
		'post_content' => '<!-- wp:paragraph --><p>English fixture body.</p><!-- /wp:paragraph -->',
		'post_name'    => 't29-area-instrumentation',
		'post_author'  => $ids['translator'],
	),
	array(
		'_lps_locale'      => 'en',
		'_lps_label'       => 'Instrumentation',
		'_lps_review_date' => '2027-01-01',
	)
);
$associated = Translations::associate( $pt_area, $en_area );
t29_check( 'fixture:locale-pair', ! is_wp_error( $associated ), is_wp_error( $associated ) ? $associated->get_error_message() : $associated );

// A published news record the takedown flow can act on.
$news_takedown = t29_insert(
	'lps_news',
	array(
		'post_title'   => 'T29 takedown target',
		'post_excerpt' => 'Published fixture for the takedown flow.',
		'post_content' => '<!-- wp:paragraph --><p>Published fixture body.</p><!-- /wp:paragraph -->',
		'post_status'  => 'publish',
		'post_name'    => 't29-takedown-target',
	),
	array(
		'_lps_locale'          => 'pt-br',
		'_lps_canonical_date'  => '2026-09-15T00:00:00Z',
		'_lps_claim_source_url' => 'https://lps.ufrj.br/fixture-source',
	)
);
t29_check( 'fixture:takedown-published', 'publish' === get_post_status( $news_takedown ), get_post_status( $news_takedown ) );

// A page carrying a proposed public contact and the unreviewed photograph.
// The administrator account authors it so a publish-capable session can
// reach the publish gate (no role holds edit_others_pages).
$page_privacy = t29_insert(
	'page',
	array(
		'post_title'   => 'T29 privacy review page',
		'post_excerpt' => 'Page under privacy review.',
		'post_content' => '<!-- wp:paragraph --><p>Page body.</p><!-- /wp:paragraph -->' .
			'<!-- wp:lps/figure {"mediaId":' . $unreviewed . ',"locale":"pt-br","alt":"Proposed laboratory photograph","context":"privacy-review","caption":"Proposed photograph"} /-->',
		'post_name'    => 't29-privacy-review-page',
		'post_author'  => $ids['administrator'],
	),
	array(
		'_lps_locale'         => 'pt-br',
		'_lps_report_contact' => 'contato@lps.ufrj.br',
	)
);
t29_check( 'fixture:privacy-page', $page_privacy > 0, $page_privacy );

update_option(
	'_t29_fixture',
	array(
		'accounts'    => $ids,
		'person'      => $person[0] ?? 0,
		'cleared'     => $cleared,
		'unreviewed'  => $unreviewed,
		'pt_area'     => $pt_area,
		'en_area'     => $en_area,
		'news_takedown' => $news_takedown,
		'page_privacy'  => $page_privacy,
	),
	false
);

t29_emit( 'provision', array( 'accounts' => $ids, 'fixtures' => get_option( '_t29_fixture' ) ) );
