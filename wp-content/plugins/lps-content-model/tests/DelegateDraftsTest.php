<?php
/**
 * Delegate-prepared draft provenance contracts.
 *
 * A delegate scoped to an offering or the news lane may draft records that a
 * professor then adopts under her own authority. The `_lps_prepared_by`
 * marker keeps provenance of who drafted the record: it is a system-owned
 * accountability field stamped by the insert boundary, never writable by a
 * scoped role, never exposed through REST, never indexed, and never a scope
 * source. These tests pin that contract and the unchanged delegate boundary
 * (drafting allowed, publishing denied) the ergonomic layer must not weaken.
 *
 * @package LPS\ContentModel\Tests
 */

declare(strict_types=1);

namespace LPS\ContentModel\Tests;

require_once dirname( __DIR__ ) . '/includes/class-policy.php';
require_once dirname( __DIR__ ) . '/includes/class-contracts.php';
require_once dirname( __DIR__ ) . '/includes/class-securitypolicy.php';
require_once dirname( __DIR__ ) . '/includes/class-teachingcontracts.php';
require_once dirname( __DIR__ ) . '/includes/class-teachingpolicy.php';
require_once dirname( __DIR__ ) . '/includes/class-searchindex.php';
require_once dirname( __DIR__ ) . '/includes/class-taskdashboard.php';

use LPS\ContentModel\Contracts;
use LPS\ContentModel\SearchIndex;
use LPS\ContentModel\SecurityPolicy;
use LPS\ContentModel\TaskDashboard;
use LPS\ContentModel\TeachingContracts;
use LPS\ContentModel\TeachingPolicy;
use PHPUnit\Framework\TestCase;

/**
 * Proves the delegate-prepared marker contracts without WordPress.
 */
final class DelegateDraftsTest extends TestCase {
	/**
	 * One well-formed active grant row for scope tests.
	 *
	 * @param string               $scope       Grant scope.
	 * @param int                  $offering_id Offering identifier the grant covers.
	 * @param string               $role        Granted policy role.
	 * @param array<string, mixed> $overrides   Field overrides.
	 * @return array<string, mixed>
	 */
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

	/** The marker is declared on every governed type, private from REST. */
	public function test_prepared_by_contract_is_declared_typed_and_private(): void {
		$fields = Contracts::meta_fields();
		foreach ( $fields as $post_type => $definitions ) {
			self::assertArrayHasKey( '_lps_prepared_by', $definitions, $post_type . ' must declare the marker' );
			self::assertSame( 'integer', $definitions['_lps_prepared_by']['type'], $post_type );
			self::assertFalse( $definitions['_lps_prepared_by']['show_in_rest'], $post_type . ' marker must stay out of REST' );
		}
		foreach ( TeachingContracts::POST_TYPES as $post_type ) {
			$ownership = TeachingContracts::field_ownership( $post_type );
			self::assertSame( 'system', $ownership['_lps_prepared_by'] ?? '', $post_type . ' marker ownership must be system' );
		}
		self::assertContains( '_lps_prepared_by', TeachingContracts::private_meta_keys() );
	}

	/** The marker is accountability linkage, never identity or scope input. */
	public function test_prepared_by_is_linkage_not_identity_or_scope(): void {
		self::assertContains( '_lps_prepared_by', TeachingPolicy::ACCOUNT_LINKAGE_FIELDS );
		self::assertContains( '_lps_prepared_by', TeachingPolicy::UNTRUSTED_SCOPE_INPUTS );
		self::assertFalse( TeachingPolicy::scope_source_is_server_side( '_lps_prepared_by' ) );
		self::assertContains( '_lps_prepared_by', SecurityPolicy::private_fields() );
		self::assertNotContains( '_lps_prepared_by', SecurityPolicy::public_fields() );
		self::assertContains( '_lps_prepared_by', SearchIndex::private_fields() );
	}

	/** Scoped roles may never write the marker through the field boundary. */
	public function test_prepared_by_stays_outside_the_scoped_allowlist(): void {
		foreach ( TeachingPolicy::SCOPED_POST_TYPES as $post_type ) {
			self::assertFalse( TeachingPolicy::field_write_allowed( 'professor', $post_type, '_lps_prepared_by' ), $post_type );
			self::assertFalse( TeachingPolicy::field_write_allowed( 'delegate', $post_type, '_lps_prepared_by' ), $post_type );
		}
		// Institutional roles keep their own boundary.
		self::assertTrue( TeachingPolicy::field_write_allowed( 'contributor', 'lps_news', '_lps_prepared_by' ) );
		self::assertTrue( TeachingPolicy::field_write_allowed( 'publisher', 'lps_unit', '_lps_prepared_by' ) );
	}

	/** Drafting stays open to the delegate; publishing stays denied. */
	public function test_delegate_may_draft_but_never_publish(): void {
		$now    = '2026-03-01T12:00:00+00:00';
		$grants = array( self::grant( 'offering', 42, 'delegate' ) );
		foreach ( array( 'lps_unit', 'lps_resource' ) as $post_type ) {
			self::assertNull( TeachingPolicy::scope_error( 'delegate', 'create', $post_type, 42, $grants, $now ), $post_type );
			self::assertNull( TeachingPolicy::scope_error( 'delegate', 'edit', $post_type, 42, $grants, $now ), $post_type );
			self::assertSame( 'lps_teaching_action_forbidden', TeachingPolicy::scope_error( 'delegate', 'publish', $post_type, 42, $grants, $now ), $post_type );
		}
		// The news lane follows the same boundary.
		$news_grants = array( self::grant( 'news', 0, 'delegate' ) );
		self::assertNull( TeachingPolicy::scope_error( 'delegate', 'create', 'lps_news', 0, $news_grants, $now ) );
		self::assertSame( 'lps_teaching_action_forbidden', TeachingPolicy::scope_error( 'delegate', 'publish', 'lps_news', 0, $news_grants, $now ) );
		// The adopting professor keeps full publish authority in the shared scope.
		$prof_grants = array( self::grant( 'offering', 42, 'professor' ), self::grant( 'news', 0, 'professor' ) );
		self::assertNull( TeachingPolicy::scope_error( 'professor', 'publish', 'lps_unit', 42, $prof_grants, $now ) );
		self::assertNull( TeachingPolicy::scope_error( 'professor', 'publish', 'lps_news', 0, $prof_grants, $now ) );
	}

	/** The prepared-by badge label resolves in both dashboard locales. */
	public function test_prepared_by_label_resolves_in_both_locales(): void {
		self::assertSame( 'Preparado por', TaskDashboard::field_label( 'prepared_by', 'pt-br' ) );
		self::assertSame( 'Prepared by', TaskDashboard::field_label( 'prepared_by', 'en' ) );
	}
}
