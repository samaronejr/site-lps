<?php
/**
 * Live launch-corpus import rehearsal (task 17).
 *
 * Runs inside the dedicated Playground environment:
 *
 *   node node_modules/.bin/wp-playground-cli php --php=8.3 \
 *     --blueprint <blueprint.json> \
 *     --mount-before-install <env>/wordpress:/wordpress \
 *     --wordpress-install-mode install-from-existing-files-if-needed \
 *     --mount <worktree>:/workspace \
 *     --mount <worktree>/wp-content/plugins/lps-content-model:/wordpress/wp-content/plugins/lps-content-model \
 *     -- /workspace/scripts/migration/launch-corpus-rehearsal.php
 *
 * The script requires wp-load.php itself, so a single invocation performs the
 * full QA sequence against the real WordPress database: dry-run mutation
 * guard, apply, idempotent re-apply, verify, export, reviewed-field conflict
 * protection, media staging with alt/credit, and the failure package
 * (synthetic record, legacy-scrape source, missing catalog source, unsourced
 * claim, rights-unknown image, missing alt text). It prints one JSON
 * transcript and exits non-zero when any assertion fails.
 *
 * @package LPS\ContentModel
 */

declare(strict_types=1);

$wp_load = getenv( 'LPS_REHEARSAL_WP_LOAD' );
require is_string( $wp_load ) && '' !== $wp_load ? $wp_load : '/wordpress/wp-load.php';

use LPS\ContentModel\CliContext;
use LPS\ContentModel\Exporter;
use LPS\ContentModel\Importer;
use LPS\ContentModel\ImportPackage;
use LPS\ContentModel\ImportPlanner;
use LPS\ContentModel\ImportRepository;
use LPS\ContentModel\MediaStager;
use LPS\ContentModel\MigrationPolicy;
use LPS\ContentModel\Reconciler;

$root       = dirname( __DIR__, 2 );
$package    = ImportPackage::from_file( $root . '/content/import/launch-corpus.json' );
$assets_dir = $root . '/wp-content/plugins/lps-content-model/tests/fixtures';
$report     = array( 'steps' => array(), 'assertions' => 0, 'failures' => array() );

/**
 * Records one assertion.
 *
 * @param string $step      Step identifier.
 * @param bool   $condition Assertion outcome.
 * @param string $detail    Failure detail.
 */
function check( string $step, bool $condition, string $detail = '' ): void {
	global $report;
	++$report['assertions'];
	if ( ! $condition ) {
		$report['failures'][] = array( 'step' => $step, 'detail' => $detail );
	}
}

/**
 * Removes every imported record, attachment and relationship row so the
 * rehearsal is re-runnable on the same environment database.
 */
function reset_state(): void {
	global $wpdb;
	$posts = get_posts(
		array(
			// 'any' silently drops exclude_from_search types such as lps_redirect;
			// enumerate the contract types plus attachments explicitly.
			'post_type'      => array_merge( array_keys( \LPS\ContentModel\Contracts::post_types() ), array( 'attachment' ) ),
			'post_status'    => array( 'publish', 'draft', 'pending', 'private', 'future', 'inherit', 'trash' ),
			'posts_per_page' => -1,
			'fields'         => 'ids',
		)
	);
	foreach ( $posts as $post_id ) {
		if ( metadata_exists( 'post', (int) $post_id, '_lps_import_source_id' ) || metadata_exists( 'post', (int) $post_id, '_lps_media_checksum' ) ) {
			wp_delete_post( (int) $post_id, true );
		}
	}
	foreach ( array( 'lps_relationships', 'lps_authorships' ) as $table ) {
		$name = $wpdb->prefix . $table;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is fixed.
		$wpdb->query( "DELETE FROM {$name}" );
	}
}

reset_state();

// Step 1 — dry run: plan is ready and the mutation guard holds.
$before    = CliContext::state_hash();
$plan      = ImportPlanner::plan( $package, $assets_dir );
$after     = CliContext::state_hash();
$unchanged = hash_equals( $before, $after );
check( 'dry-run', 'ready' === $plan['status'], wp_json_encode( array( 'errors' => $plan['errors'], 'quarantine' => $plan['quarantine'], 'conflicts' => $plan['conflicts'] ) ) ?: '' );
check( 'dry-run', $unchanged, 'dry run mutated database or upload state' );
$report['steps']['dry-run'] = array(
	'status'           => $plan['status'],
	'counts'           => $plan['counts'],
	'mutation_unchanged' => $unchanged,
);

// Step 2 — apply the reviewed launch package: 24 locale variants plus 2
// redirect records, and 6 relationship groups (16 rows grouped by
// source|type).
$applied = Importer::apply( $package, $assets_dir );
check( 'apply', ! ( $applied instanceof WP_Error ), $applied instanceof WP_Error ? $applied->get_error_message() : '' );
check( 'apply', is_array( $applied ) && 26 === $applied['record_writes'], wp_json_encode( $applied ) ?: '' );
check( 'apply', is_array( $applied ) && 6 === $applied['relationship_writes'], wp_json_encode( $applied ) ?: '' );
$report['steps']['apply'] = $applied instanceof WP_Error ? array( 'error' => $applied->get_error_message() ) : $applied;

// Step 3 — re-apply: identical package plans zero writes.
$reapplied = Importer::apply( $package, $assets_dir );
check( 'reapply', is_array( $reapplied ) && 0 === $reapplied['writes'], wp_json_encode( $reapplied ) ?: '' );
$report['steps']['reapply'] = $reapplied instanceof WP_Error ? array( 'error' => $reapplied->get_error_message() ) : $reapplied;

// Step 4 — verify reconciles target against package and export preserves it.
$verify = Reconciler::target( $package, $assets_dir );
check( 'verify', 'verified' === $verify['status'], wp_json_encode( $verify['errors'] ) ?: '' );
$export = Exporter::data();
check( 'export', 26 === count( $export['records'] ), (string) count( $export['records'] ) );
check( 'export', 16 === count( $export['relationships'] ), (string) count( $export['relationships'] ) );
check( 'export', 2 === count( $export['redirects'] ), (string) count( $export['redirects'] ) );
$report['steps']['verify'] = array( 'status' => $verify['status'], 'expected' => $verify['expected'], 'actual_counts' => $verify['actual']['counts'] );
$report['steps']['export'] = array(
	'records'       => count( $export['records'] ),
	'relationships' => count( $export['relationships'] ),
	'redirects'     => count( $export['redirects'] ),
);

// Step 5 — reviewed fields are never silently overwritten.
$first        = $package['records'][0];
$first_post   = ImportRepository::post_id( (string) $first['record_id'] );
$reviewed_set = array( 'post_title' );
update_post_meta( $first_post, '_lps_import_reviewed_fields', $reviewed_set );
wp_update_post(
	array(
		'ID'         => $first_post,
		'post_title' => 'Revisão editorial do título',
	)
);
$conflict_package               = $package;
$conflict_package['records'][0] = array_merge( $first, array( 'reviewed_fields' => $reviewed_set ) );
$conflict_plan                  = ImportPlanner::plan( $conflict_package, $assets_dir );
check( 'reviewed-conflict', 'blocked' === $conflict_plan['status'], $conflict_plan['status'] );
check( 'reviewed-conflict', array() !== $conflict_plan['conflicts'] && 'lps_reviewed_field_conflict' === $conflict_plan['conflicts'][0]['code'], wp_json_encode( $conflict_plan['conflicts'] ) ?: '' );
check( 'reviewed-conflict', 'post_title' === ( $conflict_plan['conflicts'][0]['field'] ?? '' ), wp_json_encode( $conflict_plan['conflicts'] ) ?: '' );
$conflict_apply = Importer::apply( $conflict_package, $assets_dir );
check( 'reviewed-conflict', $conflict_apply instanceof WP_Error && 'lps_import_preflight_blocked' === $conflict_apply->get_error_code(), wp_json_encode( $conflict_apply ) ?: '' );
$stored_title = get_post( $first_post ) instanceof WP_Post ? (string) get_post( $first_post )->post_title : '';
check( 'reviewed-conflict', 'Revisão editorial do título' === $stored_title, $stored_title );
$report['steps']['reviewed-conflict'] = array(
	'status'    => $conflict_plan['status'],
	'conflicts' => $conflict_plan['conflicts'],
	'stored'    => $stored_title,
);

// Restore the reviewed title so the export checkpoint stays canonical.
wp_update_post(
	array(
		'ID'         => $first_post,
		'post_title' => (string) $first['title'],
	)
);

// Step 6 — media staging: rights-cleared image with reviewed alt and credit.
$media_asset = array(
	'source_id'      => 'media-image',
	'path'           => 'rights-cleared-photo.png',
	'media_type'     => 'image/png',
	'title'          => 'Rights-cleared fixture',
	'source_url'     => 'https://legacy.example/photo.png',
	'captured_at'    => '2026-08-30T23:03:43Z',
	'checksum'       => 'sha256:e1d87b10f4c6fd9f0f8543f52d74e2cc910cc07d743de1b5c24870ce2f94c241',
	'rights_status'  => 'cleared',
	'rights_holder'  => 'LPS',
	'credit'         => 'LPS',
	'license'        => 'CC BY 4.0',
	'privacy_status' => 'not-required',
	'locale'         => 'zxx',
	'review_state'   => 'reviewed',
	'alt_text'       => 'Rights-cleared fixture photograph of the LPS bench',
);
$staged      = MediaStager::stage( $media_asset, $assets_dir );
check( 'media-stage', ! ( $staged instanceof WP_Error ) && true === $staged['changed'], wp_json_encode( $staged ) ?: '' );
$attachment_id = is_array( $staged ) ? (int) $staged['attachment_id'] : 0;
check( 'media-stage', 'Rights-cleared fixture photograph of the LPS bench' === get_post_meta( $attachment_id, '_wp_attachment_image_alt', true ), (string) get_post_meta( $attachment_id, '_wp_attachment_image_alt', true ) );
check( 'media-stage', 'LPS' === get_post_meta( $attachment_id, '_lps_media_credit', true ), (string) get_post_meta( $attachment_id, '_lps_media_credit', true ) );
$restaged = MediaStager::stage( $media_asset, $assets_dir );
check( 'media-stage', is_array( $restaged ) && false === $restaged['changed'] && $attachment_id === (int) $restaged['attachment_id'], wp_json_encode( $restaged ) ?: '' );
$report['steps']['media-stage'] = array(
	'attachment_id' => $attachment_id,
	'changed'       => is_array( $staged ) ? $staged['changed'] : null,
	'restaged_changed' => is_array( $restaged ) ? $restaged['changed'] : null,
);

// Step 7 — failure package: every inadmissible row is rejected or quarantined.
$bad_record = array_merge(
	$first,
	array(
		'record_id' => 'lps:page:00000000-0000-4000-8000-00000000bad1',
		'source_id' => 'fixture:bad',
		'synthetic' => true,
	)
);
$scrape     = array_merge(
	$first,
	array(
		'record_id'  => 'lps:page:00000000-0000-4000-8000-00000000bad2',
		'source_id'  => 'scrape-1',
		'source_url' => 'https://web.archive.org/web/20020302000000/http://www.lps.ufrj.br/',
	)
);
$course     = array_merge(
	$first,
	array(
		'record_id' => 'lps:course:00000000-0000-4000-8000-00000000bad3',
		'source_id' => 'course-unsourced',
		'type'      => 'lps_course',
		'meta'      => array( '_lps_course_code' => 'EEL999' ),
	)
);
$claim      = array_merge(
	$first,
	array(
		'record_id' => 'lps:page:00000000-0000-4000-8000-00000000bad4',
		'source_id' => 'claim-unsourced',
		'meta'      => array( '_lps_claim_verified' => true ),
	)
);
$conflict   = array_merge(
	$first,
	array( 'reviewed_fields' => $reviewed_set )
);
$conflict['title'] = 'Conflicting incoming title';
$failure_package  = array(
	'schema_version' => '1.0',
	'records'        => array( $bad_record, $scrape, $course, $claim, $conflict ),
	'relationships'  => array(),
	'authorships'    => array(),
	'media'          => array(
		array_merge( $media_asset, array( 'rights_status' => 'unknown' ) ),
		array_merge( $media_asset, array( 'alt_text' => '', 'decorative' => false ) ),
	),
	'redirects'      => array(),
);
$state_before     = CliContext::state_hash();
$failure_plan     = ImportPlanner::plan( $failure_package, $assets_dir );
$failure_codes    = array_column( $failure_plan['errors'], 'code' );
$quarantine_codes = array_column( $failure_plan['quarantine'], 'code' );
check( 'failure', 'failed' === $failure_plan['status'], $failure_plan['status'] );
check( 'failure', in_array( 'lps_import_synthetic_record', $failure_codes, true ), wp_json_encode( $failure_codes ) ?: '' );
check( 'failure', in_array( 'lps_import_legacy_scrape_source', $failure_codes, true ), wp_json_encode( $failure_codes ) ?: '' );
check( 'failure', in_array( 'lps_import_course_source_required', $quarantine_codes, true ), wp_json_encode( $quarantine_codes ) ?: '' );
check( 'failure', in_array( 'lps_import_claim_unsourced', $quarantine_codes, true ), wp_json_encode( $quarantine_codes ) ?: '' );
check( 'failure', in_array( 'lps_media_rights_unknown', $quarantine_codes, true ), wp_json_encode( $quarantine_codes ) ?: '' );
check( 'failure', in_array( 'lps_media_alt_required', $quarantine_codes, true ), wp_json_encode( $quarantine_codes ) ?: '' );
check( 'failure', array() !== $failure_plan['conflicts'], 'conflicting reviewed record produced no conflict' );
$failure_apply = Importer::apply( $failure_package, $assets_dir );
check( 'failure', $failure_apply instanceof WP_Error && 'lps_import_preflight_blocked' === $failure_apply->get_error_code(), wp_json_encode( $failure_apply ) ?: '' );
check( 'failure', hash_equals( $state_before, CliContext::state_hash() ), 'failure package mutated state' );
$report['steps']['failure'] = array(
	'status'     => $failure_plan['status'],
	'errors'     => $failure_codes,
	'quarantine' => $quarantine_codes,
	'conflicts'  => count( $failure_plan['conflicts'] ),
);

$report['status'] = array() === $report['failures'] ? 'passed' : 'failed';
echo wp_json_encode( $report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "\n";
exit( 'passed' === $report['status'] ? 0 : 1 );
