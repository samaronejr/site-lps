<?php
/**
 * Adversarial security and privacy contracts (task 20).
 *
 * These tests attack the policy seams the e2e spec exercises over HTTP:
 * forged scope sources, tampered grants, hostile upload names and bytes,
 * release/scan fail-closed states, forged download tokens and the plugin
 * allowlist. Every assertion is a denial or a fail-closed outcome, never a
 * happy-path restatement.
 *
 * @package LPS\ContentModel\Tests
 */

declare(strict_types=1);

namespace LPS\ContentModel\Tests;

require_once dirname( __DIR__ ) . '/includes/class-policy.php';
require_once dirname( __DIR__ ) . '/includes/class-contracts.php';
require_once dirname( __DIR__ ) . '/includes/class-securitypolicy.php';
require_once dirname( __DIR__ ) . '/includes/class-hardening.php';
require_once dirname( __DIR__ ) . '/includes/class-teachingcontracts.php';
require_once dirname( __DIR__ ) . '/includes/class-teachingpolicy.php';
require_once dirname( __DIR__ ) . '/includes/class-teachingstorage.php';
require_once dirname( __DIR__ ) . '/includes/class-teachingresources.php';

use LPS\ContentModel\Hardening;
use LPS\ContentModel\SecurityPolicy;
use LPS\ContentModel\TeachingContracts;
use LPS\ContentModel\TeachingPolicy;
use LPS\ContentModel\TeachingResources;
use LPS\ContentModel\TeachingStorage;
use PHPUnit\Framework\TestCase;

/**
 * Proves the adversarial boundary contracts without WordPress.
 */
final class LpsRedesignTask20Test extends TestCase {
	/** One well-formed active grant row for scope tests. */
	private static function grant( string $scope = 'offering', int $offering_id = 42, string $role = 'professor', array $overrides = array() ): array {
		return array_merge(
			array(
				'scope'       => $scope,
				'offering_id' => $offering_id,
				'role'        => $role,
				'granted_at'  => '2026-01-01T00:00:00+00:00',
				'expires_at'  => '',
				'revoked_at'  => '',
				'granted_by'  => 7,
			),
			$overrides
		);
	}

	/** The plugin allowlist survives traversal, duplicates and foreign entries. */
	public function test_plugin_allowlist_rejects_traversal_and_foreign_entries(): void {
		$allowed = Hardening::allowed_plugins(
			array(
				'../../etc/passwd',
				'lps-content-model/lps-content-model.php',
				'akismet/akismet.php',
				'polylang/polylang.php',
				'two-factor/two-factor.php',
				'lps-content-model/lps-content-model.php',
				'hello.php',
			)
		);
		self::assertSame( Hardening::PLUGINS, $allowed );
		self::assertSame( array(), Hardening::allowed_plugins( array( 'tracker/tracker.php' ) ) );
		// Network activation keeps the allowlist keys mapped to their timestamps.
		self::assertSame(
			array( 'lps-content-model/lps-content-model.php' => 1 ),
			Hardening::network_plugins( array( 'lps-content-model/lps-content-model.php' => 1, 'evil/evil.php' => 1 ) )
		);
	}

	/** Runtime code installation and unfiltered writes are denied for every cap. */
	public function test_runtime_install_and_unfiltered_caps_are_denied(): void {
		foreach ( array( 'install_plugins', 'update_plugins', 'delete_plugins', 'activate_plugins', 'install_themes', 'update_themes', 'delete_themes', 'edit_plugins', 'edit_themes', 'update_core', 'unfiltered_html', 'unfiltered_upload' ) as $cap ) {
			self::assertSame( array( 'do_not_allow' ), Hardening::deny_runtime_install( array( 'exist' ), $cap ), $cap );
		}
		self::assertSame( array( 'exist' ), Hardening::deny_runtime_install( array( 'exist' ), 'edit_posts' ) );
	}

	/** The emitted header set contains no external or unsafe script source. */
	public function test_headers_never_admit_external_or_unsafe_script_sources(): void {
		foreach ( array( false, true ) as $admin ) {
			$headers = Hardening::headers( $admin, true, 'deadbeef' );
			$csp     = $headers['Content-Security-Policy'];
			self::assertStringNotContainsString( 'unsafe-eval', $csp );
			self::assertStringNotContainsString( 'unsafe-inline\'; script-src', $csp );
			self::assertStringNotContainsString( 'http://', $csp );
			self::assertStringNotContainsString( 'https://', $csp );
			self::assertStringContainsString( "object-src 'none'", $csp );
			self::assertStringContainsString( "base-uri 'none'", $csp );
			self::assertStringContainsString( "frame-ancestors 'none'", $csp );
			self::assertSame( 'DENY', $headers['X-Frame-Options'] );
		}
	}

	/** Hostile upload names are refused before bytes are read. */
	public function test_upload_name_boundary_refuses_hostile_names(): void {
		foreach (
			array(
				'../escape.pdf',
				'..\\escape.pdf',
				"null\x00byte.pdf",
				'colon:name.pdf',
				"control\x1Fname.pdf",
				'.hidden.pdf',
				'trailingdot.pdf.',
				'trailing space.pdf ',
				'shell.php',
				'shell.php.pdf',
				'shell.pHp5.png',
				'page.html',
				'vector.svg',
				'app.js',
				'noextension',
			) as $name
		) {
			self::assertNotNull( TeachingStorage::name_error( $name ), $name );
		}
		self::assertNotNull( TeachingStorage::name_error( str_repeat( 'a', 256 ) . '.pdf' ) );
		self::assertNull( TeachingStorage::name_error( 'apostila-2026.pdf' ) );
		self::assertNull( TeachingStorage::name_error( 'dados.csv' ) );
	}

	/** Detected content type comes from magic bytes, never the client claim. */
	public function test_mime_detection_ignores_the_client_claim(): void {
		self::assertSame( 'application/pdf', TeachingStorage::detect_mime( "%PDF-1.4\nbody" ) );
		self::assertSame( 'application/zip', TeachingStorage::detect_mime( "PK\x03\x04zip" ) );
		self::assertSame( 'image/png', TeachingStorage::detect_mime( "\x89PNG\r\n\x1a\nrest" ) );
		self::assertSame( 'application/x-ole-storage', TeachingStorage::detect_mime( "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1ole" ) );
		self::assertSame( 'application/octet-stream', TeachingStorage::detect_mime( "\x00\x01\x02binary" ) );
		// A PHP payload renamed to .pdf is not a PDF and fails the type check.
		self::assertSame(
			'lps_teaching_type_mismatch',
			TeachingStorage::content_error( 'pdf', TeachingStorage::detect_mime( '<?php echo 1;' ), '<?php echo 1;', '/tmp/x' )
		);
		// A real PDF renamed to .txt is not plain text.
		self::assertSame(
			'lps_teaching_type_mismatch',
			TeachingStorage::content_error( 'txt', TeachingStorage::detect_mime( '%PDF-1.4 x' ), '%PDF-1.4 x', '/tmp/x' )
		);
	}

	/** Active payloads inside documents are refused. */
	public function test_active_document_payloads_are_refused(): void {
		self::assertFalse( Hardening::safe_document( 'application/pdf', "%PDF-1.7\n/OpenAction << /S /JavaScript /JS (app.alert(1)) >>" ) );
		self::assertFalse( Hardening::safe_document( 'application/pdf', "%PDF-1.7\n/Launch << /F (cmd.exe) >>" ) );
		self::assertFalse( Hardening::safe_document( 'application/pdf', "%PDF-1.7\n/EmbeddedFile << /F (x) >>" ) );
		// Hex-encoded /JavaScript (#4A#61#76#61#53#63#72#69#70#74) must still trip the check.
		self::assertFalse( Hardening::safe_document( 'application/pdf', '%PDF-1.7 /OpenAction << /S /#4A#61#76#61#53#63#72#69#70#74 >>' ) );
		self::assertFalse( Hardening::safe_document( 'text/vtt', "WEBVTT\n\n00:00.000 --> 00:01.000\n<img onerror=alert(1)>" ) );
		self::assertFalse( Hardening::safe_document( 'text/vtt', "WEBVTT\n\njavascript:alert(1)" ) );
		self::assertTrue( Hardening::safe_document( 'application/pdf', "%PDF-1.7\n1 0 obj << /Type /Catalog >> endobj\n%%EOF" ) );
	}

	/** Every non-public combination resolves to a denial, never bytes. */
	public function test_release_denial_covers_every_non_public_combination(): void {
		$resource = array(
			'post_status'          => 'publish',
			'state'                => 'published',
			'version_id'           => 'lpsver:' . str_repeat( 'a', 64 ),
			'external_url'         => '',
			'storage_key'          => 'lps-file-' . str_repeat( 'b', 32 ),
			'release_state'        => 'released',
			'release_at'           => '',
			'rights_review'        => 'approved',
			'accessibility_review' => 'approved',
		);
		$offering = array(
			'post_status'       => 'publish',
			'state'             => 'published',
			'public_visibility' => true,
		);
		$version = array( 'key' => 'lps-file-' . str_repeat( 'b', 32 ) );
		$now     = '2026-09-19T00:00:00+00:00';

		self::assertNull( TeachingResources::release_denial( $resource, $offering, $version, null, $now ) );
		self::assertSame( 'lps_resource_not_found', TeachingResources::release_denial( null, $offering, $version, null, $now ) );
		self::assertSame( 'lps_resource_not_found', TeachingResources::release_denial( array_merge( $resource, array( 'post_status' => 'draft' ) ), $offering, $version, null, $now ) );
		self::assertSame( 'lps_resource_not_found', TeachingResources::release_denial( array_merge( $resource, array( 'state' => 'in_review' ) ), $offering, $version, null, $now ) );
		self::assertSame( 'lps_resource_offering_not_public', TeachingResources::release_denial( $resource, null, $version, null, $now ) );
		self::assertSame( 'lps_resource_offering_not_public', TeachingResources::release_denial( $resource, array_merge( $offering, array( 'public_visibility' => false ) ), $version, null, $now ) );
		self::assertSame( 'lps_resource_offering_not_public', TeachingResources::release_denial( $resource, array_merge( $offering, array( 'post_status' => 'draft' ) ), $version, null, $now ) );
		self::assertSame( 'lps_resource_external', TeachingResources::release_denial( array_merge( $resource, array( 'external_url' => 'https://files.example/x.pdf' ) ), $offering, $version, null, $now ) );
		self::assertSame( 'lps_resource_version_missing', TeachingResources::release_denial( array_merge( $resource, array( 'version_id' => '' ) ), $offering, $version, null, $now ) );
		self::assertSame( 'lps_resource_version_missing', TeachingResources::release_denial( $resource, $offering, null, null, $now ) );
		self::assertSame( 'lps_resource_version_mismatch', TeachingResources::release_denial( $resource, $offering, array( 'key' => 'lps-file-' . str_repeat( 'c', 32 ) ), null, $now ) );
		self::assertSame( 'lps_resource_withdrawn', TeachingResources::release_denial( array_merge( $resource, array( 'release_state' => 'withdrawn' ) ), $offering, $version, null, $now ) );
		self::assertSame( 'lps_resource_not_released', TeachingResources::release_denial( array_merge( $resource, array( 'release_state' => 'draft' ) ), $offering, $version, null, $now ) );
		self::assertSame( 'lps_resource_not_released', TeachingResources::release_denial( array_merge( $resource, array( 'release_state' => 'scheduled', 'release_at' => '2027-01-01T00:00:00+00:00' ) ), $offering, $version, null, $now ) );
		self::assertSame( 'lps_teaching_not_cleared', TeachingResources::release_denial( $resource, $offering, $version, 'lps_teaching_not_cleared', $now ) );
		self::assertSame( 'lps_resource_rights_not_approved', TeachingResources::release_denial( array_merge( $resource, array( 'rights_review' => 'pending' ) ), $offering, $version, null, $now ) );
		self::assertSame( 'lps_resource_accessibility_not_approved', TeachingResources::release_denial( array_merge( $resource, array( 'accessibility_review' => 'pending' ) ), $offering, $version, null, $now ) );
	}

	/** The release clock fails closed: unknown states and unparseable times stay drafts. */
	public function test_release_clock_fails_closed(): void {
		$now = '2026-09-19T12:00:00+00:00';
		self::assertSame( 'released', TeachingContracts::effective_release_state( 'released', '', $now ) );
		self::assertSame( 'withdrawn', TeachingContracts::effective_release_state( 'withdrawn', '', $now ) );
		self::assertSame( 'released', TeachingContracts::effective_release_state( 'scheduled', '2026-09-19T11:00:00+00:00', $now ) );
		self::assertSame( 'scheduled', TeachingContracts::effective_release_state( 'scheduled', '2026-09-19T13:00:00+00:00', $now ) );
		self::assertSame( 'scheduled', TeachingContracts::effective_release_state( 'scheduled', 'not-a-date', $now ) );
		// An unparseable reference clock cannot prove a scheduled release is due,
		// so the stored state holds — `scheduled` is the fail-closed answer here,
		// never `released`.
		self::assertSame( 'scheduled', TeachingContracts::effective_release_state( 'scheduled', '2026-09-19T11:00:00+00:00', 'not-a-date' ) );
		self::assertSame( 'draft', TeachingContracts::effective_release_state( 'forged-state', '', $now ) );
		self::assertSame( 'draft', TeachingContracts::effective_release_state( '', '', $now ) );
		self::assertSame( 'draft', TeachingContracts::effective_release_state( array( 'released' ), '', $now ) );
	}

	/** Range parsing refuses multi-range, malformed and unsatisfiable requests. */
	public function test_range_parser_refuses_abuse(): void {
		self::assertSame( array( 'status' => 'ok', 'start' => 0, 'end' => 9 ), TeachingResources::parse_range( 'bytes=0-9', 100 ) );
		self::assertSame( array( 'status' => 'ok', 'start' => 90, 'end' => 99 ), TeachingResources::parse_range( 'bytes=90-', 100 ) );
		self::assertSame( array( 'status' => 'ok', 'start' => 90, 'end' => 99 ), TeachingResources::parse_range( 'bytes=-10', 100 ) );
		self::assertSame( 'unsatisfiable', TeachingResources::parse_range( 'bytes=0-1,5-9', 100 )['status'] );
		self::assertSame( 'unsatisfiable', TeachingResources::parse_range( 'bytes=99-0', 100 )['status'] );
		self::assertSame( 'unsatisfiable', TeachingResources::parse_range( 'bytes=100-200', 100 )['status'] );
		self::assertSame( 'unsatisfiable', TeachingResources::parse_range( 'bytes=-0', 100 )['status'] );
		self::assertSame( 'unsatisfiable', TeachingResources::parse_range( 'bytes=abc-def', 100 )['status'] );
		self::assertSame( 'none', TeachingResources::parse_range( 'items=0-9', 100 )['status'] );
		self::assertSame( 'none', TeachingResources::parse_range( '', 100 )['status'] );
	}

	/** Download tokens only ever come from the opaque record-ID shape. */
	public function test_download_token_rejects_forged_shapes(): void {
		$uuid = '01234567-89ab-cdef-0123-456789abcdef';
		self::assertSame( $uuid, TeachingResources::download_token( 'lps:resource:' . $uuid ) );
		self::assertSame( '', TeachingResources::download_token( 'lps:resource:../../etc/passwd' ) );
		self::assertSame( '', TeachingResources::download_token( 'lps:resource:' . strtoupper( $uuid ) ) );
		self::assertSame( '', TeachingResources::download_token( 'lps:resource:' . $uuid . '-ff' ) );
		self::assertSame( '', TeachingResources::download_token( 'lps:file:lps-file-' . str_repeat( 'a', 32 ) ) );
		self::assertSame( '', TeachingResources::download_token( '12345' ) );
		self::assertSame( '', TeachingResources::download_token( '' ) );
	}

	/** Scoped authorization denies forged, expired, revoked and mismatched grants. */
	public function test_scope_boundary_denies_forged_and_stale_grants(): void {
		$now    = '2026-09-19T00:00:00+00:00';
		$grants = array( self::grant() );

		self::assertNull( TeachingPolicy::scope_error( 'professor', 'edit', 'lps_unit', 42, $grants, $now ) );
		// A grant on another offering never covers this one.
		self::assertSame( 'lps_teaching_scope_required', TeachingPolicy::scope_error( 'professor', 'edit', 'lps_unit', 43, $grants, $now ) );
		// A delegate grant never authorizes professor actions and vice versa.
		self::assertSame( 'lps_teaching_scope_required', TeachingPolicy::scope_error( 'delegate', 'edit', 'lps_unit', 42, $grants, $now ) );
		// Revoked and expired grants stop working immediately.
		self::assertSame( 'lps_teaching_grant_revoked', TeachingPolicy::scope_error( 'professor', 'edit', 'lps_unit', 42, array( self::grant( 'offering', 42, 'professor', array( 'revoked_at' => '2026-02-01T00:00:00+00:00' ) ) ), $now ) );
		self::assertSame( 'lps_teaching_grant_expired', TeachingPolicy::scope_error( 'professor', 'edit', 'lps_unit', 42, array( self::grant( 'offering', 42, 'professor', array( 'expires_at' => '2026-03-01T00:00:00+00:00' ) ) ), $now ) );
		// Malformed grants are never honored: a grant without a valid grantor is
		// unusable, which the matcher buckets with expired grants — still a denial.
		self::assertSame( 'lps_teaching_grant_expired', TeachingPolicy::scope_error( 'professor', 'edit', 'lps_unit', 42, array( self::grant( 'offering', 42, 'professor', array( 'granted_by' => 0 ) ) ), $now ) );
		self::assertSame( 'lps_teaching_scope_required', TeachingPolicy::scope_error( 'professor', 'edit', 'lps_unit', 42, array( array( 'scope' => 'offering', 'offering_id' => 42 ) ), $now ) );
		// Non-scoped roles never enter the scoped boundary.
		self::assertSame( 'lps_teaching_role_not_scoped', TeachingPolicy::scope_error( 'administrator', 'edit', 'lps_unit', 42, $grants, $now ) );
		// Actions outside the role matrix are denied before grant lookup.
		self::assertSame( 'lps_teaching_action_forbidden', TeachingPolicy::scope_error( 'delegate', 'publish', 'lps_unit', 42, $grants, $now ) );
		self::assertSame( 'lps_teaching_action_forbidden', TeachingPolicy::scope_error( 'professor', 'grant-scope', 'lps_unit', 42, $grants, $now ) );
		// Publish is refused on non-publishable types even with a grant.
		self::assertSame( 'lps_teaching_publish_type_forbidden', TeachingPolicy::scope_error( 'professor', 'publish', 'lps_offering', 42, $grants, $now ) );
		// Copy-forward is scoped to offerings only.
		self::assertSame( 'lps_teaching_action_forbidden', TeachingPolicy::scope_error( 'professor', 'copy-forward', 'lps_unit', 42, $grants, $now ) );
		// An offering scope never satisfies the news lane and vice versa.
		self::assertSame( 'lps_teaching_scope_required', TeachingPolicy::scope_error( 'professor', 'publish', 'lps_news', 0, $grants, $now ) );
		self::assertNull( TeachingPolicy::scope_error( 'professor', 'publish', 'lps_news', 0, array( self::grant( 'news', 0 ) ), $now ) );
		// Record types outside the scoped set are denied outright.
		self::assertSame( 'lps_teaching_scope_post_type', TeachingPolicy::scope_error( 'professor', 'edit', 'lps_person', 42, $grants, $now ) );
		self::assertSame( 'lps_teaching_scope_post_type', TeachingPolicy::scope_error( 'professor', 'edit', 'page', 42, $grants, $now ) );
	}

	/** The scoped field allowlist refuses system, scope and identity writes. */
	public function test_scoped_field_allowlist_refuses_system_writes(): void {
		foreach ( array( '_lps_owner_user_id', '_lps_state', '_lps_scan_verdict', '_lps_storage_key', '_lps_rights_review', '_lps_accessibility_review', '_lps_record_id', '_lps_locale', 'post_author', '_lps_privacy_reviewed' ) as $field ) {
			self::assertFalse( TeachingPolicy::field_write_allowed( 'professor', 'lps_resource', $field ), $field );
			self::assertFalse( TeachingPolicy::field_write_allowed( 'delegate', 'lps_unit', $field ), $field );
		}
		// Release fields are professor-only even inside the allowlist.
		self::assertTrue( TeachingPolicy::field_write_allowed( 'professor', 'lps_resource', '_lps_release_state' ) );
		self::assertFalse( TeachingPolicy::field_write_allowed( 'delegate', 'lps_resource', '_lps_release_state' ) );
		self::assertFalse( TeachingPolicy::field_write_allowed( 'delegate', 'lps_news', '_lps_news_status' ) );
		// Non-scoped roles are unaffected by the scoped allowlist.
		self::assertTrue( TeachingPolicy::field_write_allowed( 'publisher', 'lps_resource', '_lps_rights_review' ) );
	}

	/** Grant management refuses self-grants, role mismatches and duplicates. */
	public function test_grant_management_refuses_escalation(): void {
		$candidate = self::grant();
		self::assertSame( 'lps_teaching_grant_forbidden', TeachingPolicy::grant_error( 'professor', array(), 1, 2, 'professor', $candidate, array() ) );
		self::assertSame( 'lps_teaching_grant_forbidden', TeachingPolicy::grant_error( 'section-editor', array( 'news' ), 1, 2, 'professor', $candidate, array() ) );
		self::assertSame( 'lps_teaching_self_grant_forbidden', TeachingPolicy::grant_error( 'administrator', array(), 2, 2, 'professor', $candidate, array() ) );
		self::assertSame( 'lps_teaching_grant_role_mismatch', TeachingPolicy::grant_error( 'administrator', array(), 1, 2, 'delegate', $candidate, array() ) );
		self::assertSame( 'lps_teaching_grant_role_mismatch', TeachingPolicy::grant_error( 'administrator', array(), 1, 2, 'publisher', $candidate, array() ) );
		self::assertSame( 'lps_teaching_grant_invalid', TeachingPolicy::grant_error( 'administrator', array(), 1, 2, 'professor', self::grant( 'offering', 0 ), array() ) );
		self::assertSame( 'lps_teaching_grant_invalid', TeachingPolicy::grant_error( 'administrator', array(), 1, 2, 'professor', self::grant( 'news', 9 ), array() ) );
		self::assertSame( 'lps_teaching_grant_invalid', TeachingPolicy::grant_error( 'administrator', array(), 1, 2, 'professor', self::grant( 'offering', 42, 'professor', array( 'expires_at' => '2025-01-01T00:00:00+00:00' ) ), array() ) );
		self::assertSame( 'lps_teaching_grant_duplicate', TeachingPolicy::grant_error( 'administrator', array(), 1, 2, 'professor', $candidate, array( $candidate ) ) );
		self::assertNull( TeachingPolicy::grant_error( 'administrator', array(), 1, 2, 'professor', $candidate, array() ) );
		self::assertNull( TeachingPolicy::grant_error( 'section-editor', array( 'teaching' ), 1, 2, 'professor', $candidate, array() ) );
		// Revocation mirrors the same boundary.
		self::assertSame( 'lps_teaching_grant_forbidden', TeachingPolicy::revoke_error( 'professor', array(), 1, 2, 0, array( $candidate ) ) );
		self::assertSame( 'lps_teaching_self_grant_forbidden', TeachingPolicy::revoke_error( 'administrator', array(), 2, 2, 0, array( $candidate ) ) );
		self::assertSame( 'lps_teaching_grant_missing', TeachingPolicy::revoke_error( 'administrator', array(), 1, 2, 5, array( $candidate ) ) );
		self::assertSame( 'lps_teaching_grant_missing', TeachingPolicy::revoke_error( 'administrator', array(), 1, 2, 0, array( self::grant( 'offering', 42, 'professor', array( 'revoked_at' => '2026-02-01T00:00:00+00:00' ) ) ) ) );
	}

	/** User-controlled fields are never trusted as scope sources. */
	public function test_scope_sources_are_server_side_only(): void {
		foreach ( TeachingPolicy::UNTRUSTED_SCOPE_INPUTS as $input ) {
			self::assertFalse( TeachingPolicy::scope_source_is_server_side( $input ), $input );
		}
		foreach ( TeachingPolicy::TRUSTED_SCOPE_SOURCES as $source ) {
			self::assertTrue( TeachingPolicy::scope_source_is_server_side( $source ), $source );
		}
	}

	/** Private fields stay out of the public REST shape. */
	public function test_private_fields_never_enter_the_public_shape(): void {
		foreach ( array( '_lps_owner_user_id', '_lps_translation_reviewer_id', 'actor_user_id', 'audit', 'entry_hash', 'previous_hash', 'capabilities', 'allcaps' ) as $field ) {
			self::assertContains( $field, SecurityPolicy::private_fields() );
			self::assertNotContains( $field, SecurityPolicy::public_fields() );
		}
	}

	/**
	 * Documents the news self-publish divergence flagged at gate review.
	 *
	 * `lps_news` sits in PUBLISHABLE_POST_TYPES, so a news-scope grant satisfies
	 * `scope_error('publish')`; the REST `may_publish` gate resolves scope via
	 * `persisted_offering_id`, which returns 0 for news because RELATIONSHIP_SCOPES
	 * has no entry — the same 0 the news scope expects. The dashboard publish
	 * handler resolves the identical 0 explicitly. Both surfaces therefore let a
	 * news-granted professor self-publish, bypassing the review queue the
	 * `handle_news` docblock promises. This is pre-existing policy design at the
	 * baseline; the e2e spec captures the live request/response evidence and the
	 * finding is classified blocked (policy decision required), not silently
	 * pinned as correct here.
	 */
	public function test_news_publish_divergence_is_structural(): void {
		self::assertContains( 'lps_news', TeachingPolicy::PUBLISHABLE_POST_TYPES );
		self::assertArrayNotHasKey( 'lps_news', TeachingPolicy::RELATIONSHIP_SCOPES );
		self::assertSame( 'news', TeachingPolicy::scope_for_post_type( 'lps_news' ) );
		// The scoped boundary itself allows publish with a news grant — the
		// divergence lives in which surfaces reach this check.
		self::assertNull(
			TeachingPolicy::scope_error( 'professor', 'publish', 'lps_news', 0, array( self::grant( 'news', 0 ) ), '2026-09-19T00:00:00+00:00' )
		);
	}

	/** The storage gate fails closed on missing roots, scanners and caps. */
	public function test_storage_gate_fails_closed(): void {
		$root  = sys_get_temp_dir() . '/lps-t20-storage-' . getmypid();
		$pub   = sys_get_temp_dir() . '/lps-t20-public-' . getmypid();
		$valid = array(
			'storage_root'     => $root,
			'public_root'      => $pub,
			'scanner_adapter'  => static fn( array $request ): string => 'clean',
			'scanner_approved' => true,
		);
		self::assertContains( 'lps_storage_root_missing', TeachingStorage::config_errors( $valid ) );
		mkdir( $root, 0750, true );
		mkdir( $pub, 0750, true );
		try {
			self::assertSame( array(), TeachingStorage::config_errors( $valid ) );
			self::assertContains( 'lps_scanner_missing', TeachingStorage::config_errors( array_merge( $valid, array( 'scanner_adapter' => null ) ) ) );
			self::assertContains( 'lps_scanner_unapproved', TeachingStorage::config_errors( array_merge( $valid, array( 'scanner_approved' => false ) ), true ) );
			// The public-root check only runs once both roots exist on disk.
			mkdir( $pub . '/files', 0750, true );
			self::assertContains( 'lps_storage_root_public', TeachingStorage::config_errors( array_merge( $valid, array( 'storage_root' => $pub . '/files' ) ) ) );
			self::assertContains( 'lps_storage_cap_invalid', TeachingStorage::config_errors( array_merge( $valid, array( 'max_bytes' => TeachingStorage::ABSOLUTE_MAX_BYTES + 1 ) ) ) );
			self::assertContains( 'lps_storage_cap_invalid', TeachingStorage::config_errors( array_merge( $valid, array( 'max_bytes' => '50MB' ) ) ) );
			// A quarantined or uncleared record can never be released.
			self::assertSame(
				'lps_teaching_not_cleared',
				TeachingStorage::release_error( array( 'state' => 'quarantined', 'scan_verdict' => 'none' ), $valid )
			);
			self::assertSame(
				'lps_teaching_not_cleared',
				TeachingStorage::release_error( array( 'state' => 'cleared', 'scan_verdict' => 'pending' ), $valid )
			);
			self::assertNull(
				TeachingStorage::release_error( array( 'state' => 'cleared', 'scan_verdict' => 'clean' ), $valid )
			);
		} finally {
			rmdir( $pub . '/files' );
			rmdir( $root );
			rmdir( $pub );
		}
	}

	/** Download names are ASCII-safe basenames; traversal never survives. */
	public function test_download_name_strips_traversal_and_hostile_bytes(): void {
		// Traversal separators and hostile bytes are stripped to a flat safe
		// basename carrying the canonical stored extension, never the client's.
		self::assertSame( 'etc-passwd.pdf', TeachingStorage::download_name( '../../etc/passwd', 'pdf' ) );
		self::assertSame( 'evil-name.pdf', TeachingStorage::download_name( "evil\x00name.pdf", 'pdf' ) );
		self::assertSame( 'shell.pdf', TeachingStorage::download_name( 'shell.php', 'pdf' ) );
		$name = TeachingStorage::download_name( 'Apostila Módulo 1.pdf', 'pdf' );
		self::assertStringEndsWith( '.pdf', $name );
		self::assertMatchesRegularExpression( '/^[a-z0-9._-]+$/i', $name );
	}
}
