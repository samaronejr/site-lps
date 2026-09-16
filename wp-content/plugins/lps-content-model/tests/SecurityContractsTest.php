<?php
/**
 * Least-privilege, MFA, audit, and account security contracts.
 *
 * @package LPS\ContentModel\Tests
 */

declare(strict_types=1);

namespace LPS\ContentModel\Tests;

require_once dirname( __DIR__ ) . '/includes/class-securitypolicy.php';

use LPS\ContentModel\SecurityPolicy;
use PHPUnit\Framework\Attributes\DataProvider;

/** Least-privilege, MFA, audit, and account security contracts. */
final class SecurityContractsTest extends \PHPUnit\Framework\TestCase {
	/**
	 * Provides role action matrix.
	 *
	 * @return iterable<string, array{string, string, bool}>
	 */
	public static function role_action_matrix(): iterable {
		$fixture = require __DIR__ . '/fixtures/todo10-role-matrix.php';
		/**
		 * Fixture matrix.
		 *
		 * @var array<string, array{allow: array<int, string>, deny: array<int, string>}> $matrix
		 */
		$matrix = is_array( $fixture ) ? $fixture : array();
		foreach ( $matrix as $role => $expectations ) {
			foreach ( $expectations['allow'] as $action ) {
				yield "$role allows $action" => array( $role, $action, true );
			}
			foreach ( $expectations['deny'] as $action ) {
				yield "$role denies $action" => array( $role, $action, false );
			}
		}
	}

	/**
	 * Verifies that role action matrix.
	 *
	 * @param string $role Editorial role.
	 * @param string $action Requested action.
	 * @param bool   $expected Expected result.
	 */
	#[DataProvider( 'role_action_matrix' )]
	public function test_role_action_matrix( string $role, string $action, bool $expected ): void {
		self::assertSame( $expected, SecurityPolicy::allows( $role, $action ) );
	}

	/**
	 * Verifies that assigned collection is a second mandatory boundary.
	 */
	public function test_assigned_collection_is_a_second_mandatory_boundary(): void {
		self::assertTrue( SecurityPolicy::allows( 'section-editor', 'edit', 'project', array( 'project', 'publication' ) ) );
		self::assertFalse( SecurityPolicy::allows( 'section-editor', 'edit', 'person', array( 'project', 'publication' ) ) );
		self::assertFalse( SecurityPolicy::allows( 'contributor', 'submit', 'news', array( 'project' ) ) );
		self::assertTrue( SecurityPolicy::allows( 'administrator', 'settings', 'site-settings', array() ) );
	}

	/**
	 * Verifies that translator may only write localized fields.
	 */
	public function test_translator_may_only_write_localized_fields(): void {
		self::assertTrue( SecurityPolicy::can_write_field( 'translator', 'post_title', 'en' ) );
		self::assertTrue( SecurityPolicy::can_write_field( 'translator', '_lps_eligibility', 'en' ) );
		self::assertFalse( SecurityPolicy::can_write_field( 'translator', '_lps_record_id', 'en' ) );
		self::assertFalse( SecurityPolicy::can_write_field( 'translator', '_lps_doi', 'en' ) );
		self::assertFalse( SecurityPolicy::can_write_field( 'translator', '_lps_start_date', 'en' ) );
		self::assertFalse( SecurityPolicy::can_write_field( 'translator', 'post_title', 'pt-br' ) );
	}

	/**
	 * Verifies that mfa is required and bypass never grants privileged action.
	 */
	public function test_mfa_is_required_and_bypass_never_grants_privileged_action(): void {
		self::assertTrue( SecurityPolicy::requires_mfa( 'publisher' ) );
		self::assertTrue( SecurityPolicy::requires_mfa( 'administrator' ) );
		self::assertFalse( SecurityPolicy::requires_mfa( 'section-editor' ) );
		self::assertFalse( SecurityPolicy::privileged_session_allowed( 'publisher', false ) );
		self::assertFalse( SecurityPolicy::privileged_session_allowed( 'administrator', false ) );
		self::assertTrue( SecurityPolicy::privileged_session_allowed( 'publisher', true ) );
	}

	/**
	 * Verifies that audit actions and hash chain are complete and immutable by contract.
	 */
	public function test_audit_actions_and_hash_chain_are_complete_and_immutable_by_contract(): void {
		self::assertSame(
			array( 'create', 'edit', 'submit', 'review', 'publish', 'unpublish', 'archive', 'import', 'redirect', 'settings' ),
			SecurityPolicy::audited_actions()
		);
		$entry = SecurityPolicy::audit_payload( 7, 'publish', 42, 99, '2026-08-31T12:00:00+00:00', 'abc' );
		self::assertSame( 7, $entry['actor_user_id'] );
		self::assertSame( 99, $entry['revision_id'] );
		self::assertSame( 'abc', $entry['previous_hash'] );
		self::assertSame( 64, strlen( $entry['entry_hash'] ) );
		self::assertSame( $entry, SecurityPolicy::audit_payload( 7, 'publish', 42, 99, '2026-08-31T12:00:00+00:00', 'abc' ) );
		self::assertSame( array(), array_intersect( SecurityPolicy::public_fields(), SecurityPolicy::private_fields() ) );
	}

	/**
	 * Verifies that dormant cutoff is deterministic and never uses person records.
	 */
	public function test_dormant_cutoff_is_deterministic_and_never_uses_person_records(): void {
		self::assertSame( '2026-03-04T00:00:00+00:00', SecurityPolicy::dormant_cutoff( '2026-08-31T00:00:00+00:00', 180 ) );
		self::assertFalse( SecurityPolicy::is_dormant( '2026-03-05T00:00:00+00:00', '2026-08-31T00:00:00+00:00', 180 ) );
		self::assertTrue( SecurityPolicy::is_dormant( '2026-03-03T23:59:59+00:00', '2026-08-31T00:00:00+00:00', 180 ) );
		self::assertNotContains( '_lps_user_id', SecurityPolicy::person_meta_fields() );
		self::assertTrue( SecurityPolicy::shared_account_name_forbidden( 'publisher' ) );
		self::assertTrue( SecurityPolicy::shared_account_name_forbidden( 'lps-admin' ) );
		self::assertFalse( SecurityPolicy::shared_account_name_forbidden( 'maria.silva' ) );
	}
}
