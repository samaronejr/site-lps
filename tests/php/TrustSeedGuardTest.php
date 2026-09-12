<?php
/**
 * Unit coverage for the trust seed dependency and version guards.
 *
 * @package LPS\Tests
 */

declare(strict_types=1);

namespace LPS\Tests;

use PHPUnit\Framework\TestCase;

final class TrustSeedGuardTest extends TestCase {
	public static function setUpBeforeClass(): void {
		require_once __DIR__ . '/WpSeedRuntime.php';
		require_once dirname( __DIR__ ) . '/fixtures/wp/lps-trust-seed.php';
	}

	protected function setUp(): void {
		WpSeedRuntime::reset();
	}

	public function test_dependencies_are_not_ready_without_migration_receipts(): void {
		WpSeedRuntime::activate_content_model_plugin();

		self::assertFalse( lps_trust_seed_dependencies_ready() );
	}

	public function test_dependencies_are_not_ready_with_an_empty_receipt(): void {
		WpSeedRuntime::activate_content_model_plugin();
		WpSeedRuntime::update_option( 'lps_relationship_schema_version', '1.0.0' );
		WpSeedRuntime::update_option( 'lps_audit_schema_version', '' );

		self::assertFalse( lps_trust_seed_dependencies_ready() );
	}

	public function test_dependencies_are_ready_once_the_plugin_published_both_receipts(): void {
		WpSeedRuntime::activate_content_model_plugin();
		WpSeedRuntime::apply_plugin_migration_receipts();

		self::assertTrue( lps_trust_seed_dependencies_ready() );
	}

	public function test_seed_is_pending_on_a_fresh_database(): void {
		self::assertTrue( lps_trust_seed_is_pending() );
	}

	public function test_seed_is_not_pending_at_the_current_version(): void {
		WpSeedRuntime::update_option( LPS_TRUST_SEED_OPTION, LPS_TRUST_SEED_VERSION );

		self::assertFalse( lps_trust_seed_is_pending() );
	}

	public function test_seed_is_pending_again_after_a_version_bump(): void {
		WpSeedRuntime::update_option( LPS_TRUST_SEED_OPTION, '1' );

		self::assertTrue( lps_trust_seed_is_pending() );
	}
}
