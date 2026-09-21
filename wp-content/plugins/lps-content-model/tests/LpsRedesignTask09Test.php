<?php
/**
 * Immutable resource versions and guarded-download contracts (task 9).
 *
 * @package LPS\ContentModel\Tests
 */

declare(strict_types=1);

namespace LPS\ContentModel\Tests;

require_once dirname( __DIR__ ) . '/includes/class-teachingresources.php';

use LPS\ContentModel\TeachingMigrations;
use LPS\ContentModel\TeachingResources;
use LPS\ContentModel\TeachingStorage;
use PHPUnit\Framework\TestCase;

/**
 * Proves the task-9 version, release, range and denial contracts without WordPress.
 *
 * Version-ID derivation, effective release state, the ordered denial chain,
 * single-range parsing, opaque download tokens and the registry schema are all
 * pure policy: they run here directly. Database-backed behavior — the guarded
 * route, streaming, scoped release/withdrawal and audit — is exercised by the
 * e2e spec against the real WordPress boundary.
 */
final class LpsRedesignTask09Test extends TestCase {
	/**
	 * Returns a fully releasable resource view; tests mutate one field at a time.
	 *
	 * @return array<string, mixed>
	 */
	private static function resource(): array {
		return array(
			'post_status'          => 'publish',
			'state'                => 'published',
			'version_id'           => 'lpsver:' . str_repeat( 'a', 64 ),
			'external_url'         => '',
			'storage_key'          => 'lps-file-' . str_repeat( '1', 32 ),
			'release_state'        => 'released',
			'release_at'           => '',
			'rights_review'        => 'approved',
			'accessibility_review' => 'approved',
		);
	}

	/**
	 * Returns a publicly visible parent-offering view.
	 *
	 * @return array<string, mixed>
	 */
	private static function offering(): array {
		return array(
			'post_status'       => 'publish',
			'state'             => 'published',
			'public_visibility' => true,
		);
	}

	/**
	 * Returns a cleared version row matching `resource()`.
	 *
	 * @return array<string, mixed>
	 */
	private static function version(): array {
		return array(
			'version_id'    => 'lpsver:' . str_repeat( 'a', 64 ),
			'key'           => 'lps-file-' . str_repeat( '1', 32 ),
			'state'         => 'cleared',
			'scan_verdict'  => 'clean',
			'checksum'      => str_repeat( 'b', 64 ),
			'bytes'         => 100,
			'mime'          => 'application/pdf',
			'detected_mime' => 'application/pdf',
			'extension'     => 'pdf',
			'original_name' => 'Apostila.pdf',
			'download_name' => 'apostila.pdf',
			'created_at'    => '2026-09-19T12:00:00+00:00',
		);
	}

	public function test_version_ids_are_deterministic_opaque_and_unique(): void {
		$key      = 'lps-file-' . str_repeat( '1', 32 );
		$checksum = str_repeat( 'b', 64 );
		$first    = TeachingResources::version_id_for( $key, $checksum );
		$second   = TeachingResources::version_id_for( $key, $checksum );
		self::assertSame( $first, $second, 'minting the same version twice is idempotent' );
		self::assertMatchesRegularExpression( '/^lpsver:[0-9a-f]{64}$/', $first );
		self::assertStringNotContainsString( $key, $first, 'the version ID never carries the storage key' );
		self::assertNotSame(
			$first,
			TeachingResources::version_id_for( 'lps-file-' . str_repeat( '2', 32 ), $checksum ),
			'a different object is a different version'
		);
		self::assertNotSame(
			$first,
			TeachingResources::version_id_for( $key, str_repeat( 'c', 64 ) ),
			'changed bytes are a new version'
		);
	}

	public function test_effective_release_state_evaluates_time_on_every_call(): void {
		$now = '2026-09-19T12:00:00+00:00';
		self::assertSame( 'released', TeachingResources::effective_release_state( 'released', '', $now ) );
		self::assertSame( 'withdrawn', TeachingResources::effective_release_state( 'withdrawn', '', $now ) );
		self::assertSame( 'draft', TeachingResources::effective_release_state( 'draft', '', $now ) );
		self::assertSame( 'draft', TeachingResources::effective_release_state( 'bogus', '', $now ), 'unknown states fail closed' );

		// A scheduled release is effective only once its time has passed.
		self::assertSame( 'scheduled', TeachingResources::effective_release_state( 'scheduled', '2026-09-19T13:00:00+00:00', $now ) );
		self::assertSame( 'released', TeachingResources::effective_release_state( 'scheduled', '2026-09-19T12:00:00+00:00', $now ) );
		self::assertSame( 'released', TeachingResources::effective_release_state( 'scheduled', '2026-09-19T11:00:00+00:00', $now ) );
		// Offsets compare as instants, not strings: 14:00+02:00 is 12:00Z.
		self::assertSame( 'released', TeachingResources::effective_release_state( 'scheduled', '2026-09-19T14:00:00+02:00', $now ) );
		// A malformed schedule can never release early.
		self::assertSame( 'scheduled', TeachingResources::effective_release_state( 'scheduled', 'not-a-date', $now ) );
	}

	public function test_release_denial_walks_the_ordered_chain(): void {
		$now      = '2026-09-19T12:00:00+00:00';
		$resource = self::resource();
		$offering = self::offering();
		$version  = self::version();

		// The happy path denies nothing.
		self::assertNull( TeachingResources::release_denial( $resource, $offering, $version, null, $now ) );

		// Missing or non-public resources are anonymous not-founds.
		self::assertSame( 'lps_resource_not_found', TeachingResources::release_denial( null, $offering, $version, null, $now ) );
		$draft = array_merge( $resource, array( 'state' => 'draft' ) );
		self::assertSame( 'lps_resource_not_found', TeachingResources::release_denial( $draft, $offering, $version, null, $now ) );
		$trashed = array_merge( $resource, array( 'post_status' => 'trash' ) );
		self::assertSame( 'lps_resource_not_found', TeachingResources::release_denial( $trashed, $offering, $version, null, $now ) );

		// A revoked or non-public parent denies delivery.
		self::assertSame( 'lps_resource_offering_not_public', TeachingResources::release_denial( $resource, null, $version, null, $now ) );
		$hidden = array_merge( $offering, array( 'public_visibility' => false ) );
		self::assertSame( 'lps_resource_offering_not_public', TeachingResources::release_denial( $resource, $hidden, $version, null, $now ) );
		$in_review = array_merge( $offering, array( 'state' => 'in_review' ) );
		self::assertSame( 'lps_resource_offering_not_public', TeachingResources::release_denial( $resource, $in_review, $version, null, $now ) );

		// External resources never serve local bytes.
		$external = array_merge( $resource, array( 'external_url' => 'https://example.org/file.pdf' ) );
		self::assertSame( 'lps_resource_external', TeachingResources::release_denial( $external, $offering, $version, null, $now ) );

		// Missing, unknown or mismatched versions deny delivery.
		$no_version = array_merge( $resource, array( 'version_id' => '' ) );
		self::assertSame( 'lps_resource_version_missing', TeachingResources::release_denial( $no_version, $offering, $version, null, $now ) );
		self::assertSame( 'lps_resource_version_missing', TeachingResources::release_denial( $resource, $offering, null, null, $now ) );
		$mismatch = array_merge( $resource, array( 'storage_key' => 'lps-file-' . str_repeat( '9', 32 ) ) );
		self::assertSame( 'lps_resource_version_mismatch', TeachingResources::release_denial( $mismatch, $offering, $version, null, $now ) );

		// Withdrawal and unreleased states deny regardless of version health.
		$withdrawn = array_merge( $resource, array( 'release_state' => 'withdrawn' ) );
		self::assertSame( 'lps_resource_withdrawn', TeachingResources::release_denial( $withdrawn, $offering, $version, null, $now ) );
		$drafted = array_merge( $resource, array( 'release_state' => 'draft' ) );
		self::assertSame( 'lps_resource_not_released', TeachingResources::release_denial( $drafted, $offering, $version, null, $now ) );
		$future = array_merge(
			$resource,
			array(
				'release_state' => 'scheduled',
				'release_at'    => '2026-09-19T13:00:00+00:00',
			)
		);
		self::assertSame( 'lps_resource_not_released', TeachingResources::release_denial( $future, $offering, $version, null, $now ) );
		$due = array_merge(
			$resource,
			array(
				'release_state' => 'scheduled',
				'release_at'    => '2026-09-19T11:00:00+00:00',
			)
		);
		self::assertNull( TeachingResources::release_denial( $due, $offering, $version, null, $now ), 'a due schedule releases without cron' );

		// Storage/scan denials pass through, then the review gates.
		self::assertSame( 'lps_teaching_not_cleared', TeachingResources::release_denial( $resource, $offering, $version, 'lps_teaching_not_cleared', $now ) );
		$no_rights = array_merge( $resource, array( 'rights_review' => 'pending' ) );
		self::assertSame( 'lps_resource_rights_not_approved', TeachingResources::release_denial( $no_rights, $offering, $version, null, $now ) );
		$no_a11y = array_merge( $resource, array( 'accessibility_review' => 'pending' ) );
		self::assertSame( 'lps_resource_accessibility_not_approved', TeachingResources::release_denial( $no_a11y, $offering, $version, null, $now ) );
	}

	public function test_parse_range_honors_single_ranges_and_rejects_the_rest(): void {
		// No header or a non-bytes unit falls back to a full response.
		self::assertSame( 'none', TeachingResources::parse_range( '', 100 )['status'] );
		self::assertSame( 'none', TeachingResources::parse_range( 'items=0-9', 100 )['status'] );

		// Bounded, open-ended and suffix ranges resolve to byte offsets.
		$range = TeachingResources::parse_range( 'bytes=0-9', 100 );
		self::assertSame(
			array(
				'status' => 'ok',
				'start'  => 0,
				'end'    => 9,
			),
			$range
		);
		$open = TeachingResources::parse_range( 'bytes=90-', 100 );
		self::assertSame(
			array(
				'status' => 'ok',
				'start'  => 90,
				'end'    => 99,
			),
			$open
		);
		$suffix = TeachingResources::parse_range( 'bytes=-10', 100 );
		self::assertSame(
			array(
				'status' => 'ok',
				'start'  => 90,
				'end'    => 99,
			),
			$suffix
		);
		// A suffix longer than the object clamps to the whole object.
		$whole = TeachingResources::parse_range( 'bytes=-500', 100 );
		self::assertSame(
			array(
				'status' => 'ok',
				'start'  => 0,
				'end'    => 99,
			),
			$whole
		);
		// An end beyond the object clamps to the last byte.
		$clamped = TeachingResources::parse_range( 'bytes=90-999', 100 );
		self::assertSame(
			array(
				'status' => 'ok',
				'start'  => 90,
				'end'    => 99,
			),
			$clamped
		);

		// Multi-range, malformed and out-of-bounds requests are 416s.
		self::assertSame( 'unsatisfiable', TeachingResources::parse_range( 'bytes=0-9,20-29', 100 )['status'] );
		self::assertSame( 'unsatisfiable', TeachingResources::parse_range( 'bytes=100-109', 100 )['status'] );
		self::assertSame( 'unsatisfiable', TeachingResources::parse_range( 'bytes=50-40', 100 )['status'] );
		self::assertSame( 'unsatisfiable', TeachingResources::parse_range( 'bytes=-0', 100 )['status'] );
		self::assertSame( 'unsatisfiable', TeachingResources::parse_range( 'bytes=abc-def', 100 )['status'] );
		self::assertSame( 'unsatisfiable', TeachingResources::parse_range( 'bytes=-', 100 )['status'] );
		self::assertSame( 'unsatisfiable', TeachingResources::parse_range( 'bytes=0-9', 0 )['status'] );
	}

	public function test_download_tokens_are_opaque_and_never_expose_ids(): void {
		$uuid = '123e4567-e89b-42d3-a456-426614174000';
		self::assertSame( $uuid, TeachingResources::download_token( 'lps:resource:' . $uuid ) );
		// Raw post IDs, storage keys and version IDs never mint a token.
		self::assertSame( '', TeachingResources::download_token( 'lps:resource:12345' ) );
		self::assertSame( '', TeachingResources::download_token( 'lps-file-' . str_repeat( '1', 32 ) ) );
		self::assertSame( '', TeachingResources::download_token( 'lpsver:' . str_repeat( 'a', 64 ) ) );
		self::assertSame( '', TeachingResources::download_token( '' ) );
		self::assertSame( '', TeachingResources::download_token( 'lps:resource:' . $uuid . '-extra' ) );

		// The rewrite rule only matches the opaque token shape.
		$rules = TeachingResources::rewrite_rules();
		self::assertCount( 1, $rules );
		$pattern = array_key_first( $rules );
		self::assertIsString( $pattern );
		self::assertStringStartsWith( 'lps-resource/', $pattern );
		self::assertStringContainsString( TeachingResources::DOWNLOAD_QUERY_VAR, $rules[ $pattern ] );
		self::assertSame( 1, preg_match( '#^' . $pattern . '#', 'lps-resource/' . $uuid . '/' ) );
		self::assertSame( 0, preg_match( '#^' . $pattern . '#', 'lps-resource/12345/' ) );
		self::assertSame( 0, preg_match( '#^' . $pattern . '#', 'lps-resource/lps-file-' . str_repeat( '1', 32 ) . '/' ) );
	}

	public function test_version_response_never_leaks_storage_internals(): void {
		$response = TeachingResources::version_response( self::version() );
		self::assertSame( 'lpsver:' . str_repeat( 'a', 64 ), $response['version_id'] );
		self::assertSame( 'cleared', $response['state'] );
		self::assertSame( 'clean', $response['scan_verdict'] );
		self::assertSame( str_repeat( 'b', 64 ), $response['sha256'] );
		self::assertSame( 100, $response['bytes'] );
		self::assertSame( 'application/pdf', $response['mime'] );
		self::assertSame( 'apostila.pdf', $response['download_name'] );
		// The storage key, server path and original client name never surface.
		self::assertArrayNotHasKey( 'key', $response );
		self::assertArrayNotHasKey( 'storage_key', $response );
		self::assertArrayNotHasKey( 'path', $response );
		self::assertArrayNotHasKey( 'original_name', $response );
		self::assertArrayNotHasKey( 'extension', $response );
	}

	public function test_download_headers_force_attachment_nosniff_and_no_store(): void {
		$headers = TeachingStorage::download_headers( self::version() );
		self::assertSame( 'application/pdf', $headers['Content-Type'] );
		self::assertStringStartsWith( 'attachment; filename="', $headers['Content-Disposition'] );
		self::assertStringContainsString( 'apostila.pdf', $headers['Content-Disposition'] );
		self::assertSame( 'nosniff', $headers['X-Content-Type-Options'] );
		self::assertSame( 'no-store', $headers['Cache-Control'] );
		// Filenames are sanitized to a safe ASCII basename.
		$unsafe = TeachingStorage::download_headers(
			array_merge( self::version(), array( 'original_name' => '../../etc/passwd%00.php' ) )
		);
		self::assertStringNotContainsString( '..', $unsafe['Content-Disposition'] );
		self::assertStringNotContainsString( '/', $unsafe['Content-Disposition'] );
		self::assertStringNotContainsString( '%', $unsafe['Content-Disposition'] );
	}

	public function test_version_registry_schema_is_additive_and_immutable(): void {
		self::assertSame( '1.1.0', TeachingMigrations::VERSION );

		// The 1.1.0 step is additive on top of 1.0.0.
		$plan = TeachingMigrations::plan( '0.0.0', '1.1.0' );
		self::assertTrue( $plan['ok'] );
		self::assertSame(
			array( 'create_term_registry', 'create_offering_registry', 'create_resource_version_registry' ),
			$plan['steps']
		);
		$incremental = TeachingMigrations::plan( '1.0.0', '1.1.0' );
		self::assertTrue( $incremental['ok'] );
		self::assertSame( array( 'create_resource_version_registry' ), $incremental['steps'] );

		// The registry pins immutable version identity: opaque ID primary key,
		// unique storage key, checksum and byte size.
		$schema = TeachingMigrations::schema_sql( 'wp_', 'DEFAULT CHARSET utf8mb4' );
		self::assertArrayHasKey( 'wp_lps_resource_version_registry', $schema );
		$ddl = $schema['wp_lps_resource_version_registry'];
		self::assertStringContainsString( 'version_id char(71) NOT NULL', $ddl );
		self::assertStringContainsString( 'PRIMARY KEY  (version_id)', $ddl );
		self::assertStringContainsString( 'UNIQUE KEY storage_key (storage_key)', $ddl );
		self::assertStringContainsString( 'sha256 char(64) NOT NULL', $ddl );
		self::assertStringContainsString( 'byte_size bigint(20) unsigned NOT NULL', $ddl );

		// The dry run mutates nothing and preserves existing records.
		$dry = TeachingMigrations::dry_run( '1.0.0', '1.1.0' );
		self::assertTrue( $dry['ok'] );
		self::assertSame( 'false', var_export( $dry['mutated'], true ) );
		self::assertSame( 'true', var_export( $dry['preserves_existing_records'], true ) );
	}
}
