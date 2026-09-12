<?php
/**
 * Security regression contracts.
 *
 * @package LPS\ContentModel\Tests
 */

declare(strict_types=1);

namespace LPS\ContentModel\Tests;

use LPS\ContentModel\Contracts;
use LPS\ContentModel\Hardening;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/includes/class-policy.php';
require_once dirname( __DIR__ ) . '/includes/class-contracts.php';
if ( is_file( dirname( __DIR__ ) . '/includes/class-hardening.php' ) ) {
	require_once dirname( __DIR__ ) . '/includes/class-hardening.php';
}

final class HardeningTest extends TestCase {
	public function test_private_metadata_never_enters_the_registered_rest_schema(): void {
		foreach ( Contracts::meta_fields() as $fields ) {
			self::assertFalse( $fields['_lps_owner_user_id']['show_in_rest'] );
			self::assertFalse( $fields['_lps_translation_reviewer_id']['show_in_rest'] );
			self::assertTrue( $fields['_lps_record_id']['show_in_rest'] );
		}
	}

	public function test_forbidden_plugins_are_removed_before_loading(): void {
		self::assertSame(
			array( 'lps-content-model/lps-content-model.php', 'two-factor/two-factor.php' ),
			Hardening::allowed_plugins( array( 'tracker/tracker.php', 'lps-content-model/lps-content-model.php', 'two-factor/two-factor.php', '../evil.php' ) )
		);
	}

	public function test_headers_have_no_external_or_unsafe_script_sources(): void {
		$headers = Hardening::headers( false, true, 'abc123' );
		self::assertSame( 'nosniff', $headers['X-Content-Type-Options'] );
		self::assertSame( 'DENY', $headers['X-Frame-Options'] );
		self::assertSame( 'no-referrer', $headers['Referrer-Policy'] );
		self::assertSame( 'max-age=31536000', $headers['Strict-Transport-Security'] );
		self::assertStringContainsString( "script-src 'self' 'nonce-abc123'", $headers['Content-Security-Policy'] );
		self::assertStringContainsString( "connect-src 'self'", $headers['Content-Security-Policy'] );
		self::assertStringNotContainsString( 'unsafe-eval', $headers['Content-Security-Policy'] );
		self::assertArrayNotHasKey( 'Strict-Transport-Security', Hardening::headers( false, false, 'abc123' ) );
	}

	public function test_upload_content_and_double_extensions_are_validated(): void {
		foreach ( array( 'shell.php.jpg', 'shell.phtml.pdf', 'shell.PHP8.png', 'shell.svg', 'shell.html', 'shell.phar' ) as $name ) {
			self::assertFalse( Hardening::safe_upload_name( $name ), $name );
		}
		self::assertTrue( Hardening::safe_upload_name( 'research.figure.png' ) );
		self::assertFalse( Hardening::safe_document( 'application/pdf', '<?php echo 1;' ) );
		self::assertFalse( Hardening::safe_document( 'application/pdf', '%PDF-1.7 /OpenAction << /JS (alert(1)) >>' ) );
		self::assertFalse( Hardening::safe_document( 'text/vtt', 'WEBVTT\n<script>alert(1)</script>' ) );
		self::assertTrue( Hardening::safe_document( 'application/pdf', "%PDF-1.7\n1 0 obj << /Type /Catalog >> endobj\n%%EOF" ) );
		self::assertTrue( Hardening::safe_document( 'text/vtt', "WEBVTT\n\n00:00.000 --> 00:01.000\nCaption" ) );
	}

	public function test_response_policy_omits_the_core_version_generator(): void {
		$source = (string) file_get_contents( dirname( __DIR__ ) . '/includes/class-hardening.php' );
		self::assertStringContainsString( "remove_action( 'wp_head', 'wp_generator' );", $source );
		self::assertStringContainsString( "add_filter( 'the_generator', '__return_empty_string' );", $source );
	}

	public function test_public_inventory_declares_no_runtime_vendors_or_storage(): void {
		$inventory = Hardening::inventory();
		self::assertSame( array(), $inventory['public_cookies'] );
		self::assertSame( array(), $inventory['public_storage'] );
		self::assertSame( array(), $inventory['third_party_runtime'] );
		self::assertSame( array( 'lps-content-model', 'polylang', 'two-factor' ), $inventory['plugins'] );
	}

	public function test_production_gate_enforces_only_a_positively_declared_production_environment(): void {
		$restore = getenv( 'WP_ENVIRONMENT_TYPE' );
		putenv( 'WP_ENVIRONMENT_TYPE' );
		self::assertSame( '', Hardening::declared_environment(), 'A fresh install declares no environment.' );
		self::assertFalse( Hardening::enforces_production( Hardening::declared_environment() ), 'A fresh install must never be treated as production.' );
		foreach ( array( '', 'local', 'development', 'staging', 'productionish', 'pre-production' ) as $declared ) {
			self::assertFalse( Hardening::enforces_production( $declared ), $declared );
		}
		foreach ( array( 'production', 'PRODUCTION', ' production ' ) as $declared ) {
			self::assertTrue( Hardening::enforces_production( $declared ), $declared );
		}
		putenv( 'WP_ENVIRONMENT_TYPE=production' );
		self::assertSame( 'production', Hardening::declared_environment() );
		self::assertTrue( Hardening::enforces_production( Hardening::declared_environment() ) );
		putenv( is_string( $restore ) ? 'WP_ENVIRONMENT_TYPE=' . $restore : 'WP_ENVIRONMENT_TYPE' );
	}
}
