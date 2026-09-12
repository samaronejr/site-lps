<?php
/**
 * Controlled-taxonomy and relationship-integrity contract tests.
 *
 * @package LPS\ContentModel\Tests
 */

declare(strict_types=1);

namespace LPS\ContentModel\Tests;

use LPS\ContentModel\Migrations;
use LPS\ContentModel\Policy;
use LPS\ContentModel\RelationshipPolicy;
use LPS\ContentModel\Taxonomies;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/includes/class-policy.php';

require_once dirname( __DIR__ ) . '/includes/class-taxonomies.php';
require_once dirname( __DIR__ ) . '/includes/class-relationshippolicy.php';
require_once dirname( __DIR__ ) . '/includes/class-migrations.php';

/** Proves the portable Todo 8 integrity policy before WordPress storage is involved. */
final class RelationshipContractsTest extends TestCase {
	public function test_registers_only_approved_language_neutral_taxonomies_and_terms(): void {
		$definitions = Taxonomies::definitions();

		self::assertSame( array( 'lps_research_area_key', 'lps_application_domain' ), array_keys( $definitions ) );
		self::assertSame(
			array( 'instrumentation', 'signal-processing', 'computational-intelligence', 'software-engineering' ),
			array_keys( $definitions['lps_research_area_key']['terms'] )
		);
		self::assertSame(
			array( 'electrical-nuclear-energy', 'oil-and-gas', 'high-energy-physics', 'defense', 'medicine', 'veterinary-science', 'data-quality' ),
			array_keys( $definitions['lps_application_domain']['terms'] )
		);
	}

	public function test_migration_contract_has_three_idempotent_reversible_tables(): void {
		$suffixes = Migrations::table_suffixes();
		$schema   = Migrations::schema_sql( 'wp_', 'DEFAULT CHARACTER SET utf8mb4' );
		$rollback = Migrations::rollback_sql( 'wp_' );

		self::assertSame( array( 'lps_relationships', 'lps_authorships', 'lps_publication_dois' ), $suffixes );
		self::assertCount( 3, $schema );
		self::assertCount( 3, $rollback );
		self::assertStringContainsString( 'UNIQUE KEY publication_order', $schema['wp_lps_authorships'] );
		self::assertStringContainsString( 'UNIQUE KEY doi_hash', $schema['wp_lps_publication_dois'] );
		self::assertSame( $schema, Migrations::schema_sql( 'wp_', 'DEFAULT CHARACTER SET utf8mb4' ) );
	}

	public function test_relationship_order_is_stable_and_retains_typed_attributes(): void {
		$rows = array(
			array( 'target_post_id' => 31, 'relationship_role' => 'member', 'sort_order' => 3, 'start_date' => '2024-01-01', 'end_date' => '', 'public_visibility' => true ),
			array( 'target_post_id' => 11, 'relationship_role' => 'lead', 'sort_order' => 1, 'start_date' => '2020-01-01', 'end_date' => '', 'public_visibility' => true ),
			array( 'target_post_id' => 21, 'relationship_role' => 'member', 'sort_order' => 2, 'start_date' => '2022-03-01', 'end_date' => '2023-12-31', 'public_visibility' => false ),
		);

		$ordered = RelationshipPolicy::sort_rows( $rows );

		self::assertSame( array( 1, 2, 3 ), array_column( $ordered, 'sort_order' ) );
		self::assertSame( '2023-12-31', $ordered[1]['end_date'] );
		self::assertFalse( $ordered[1]['public_visibility'] );
	}

	public function test_authorship_supports_internal_external_and_collective_parties(): void {
		$authors = array(
			array( 'author_kind' => 'collective', 'author_post_id' => 0, 'display_name' => 'LPS Collaboration', 'orcid' => '', 'affiliation' => 'LPS', 'author_role' => 'group-author', 'sort_order' => 3, 'start_date' => '', 'end_date' => '', 'public_visibility' => true ),
			array( 'author_kind' => 'internal', 'author_post_id' => 101, 'display_name' => '', 'orcid' => '', 'affiliation' => '', 'author_role' => 'author', 'sort_order' => 1, 'start_date' => '', 'end_date' => '', 'public_visibility' => true ),
			array( 'author_kind' => 'external', 'author_post_id' => 0, 'display_name' => 'Ada External', 'orcid' => 'https://orcid.org/0000-0002-1825-0097', 'affiliation' => 'External University', 'author_role' => 'author', 'sort_order' => 2, 'start_date' => '', 'end_date' => '', 'public_visibility' => true ),
		);

		$normalized = RelationshipPolicy::normalize_authors( $authors );
		$errors     = RelationshipPolicy::author_errors( $authors );

		self::assertSame( array(), $errors );
		self::assertSame( array( 'internal', 'external', 'collective' ), array_column( $normalized, 'author_kind' ) );
		self::assertSame( '0000-0002-1825-0097', $normalized[1]['orcid'] );
		self::assertSame( 'External University', $normalized[1]['affiliation'] );
	}

	public function test_doi_case_prefix_and_url_variants_normalize_to_one_identity(): void {
		$variants = array(
			'10.5555/LPS.Example',
			'doi:10.5555/lps.example',
			'https://doi.org/10.5555/LPS.Example',
			'http://dx.doi.org/10.5555/lps.example',
		);
		$normalized = array_map(
			static fn ( string $doi ): string => RelationshipPolicy::normalize_doi( $doi ),
			$variants
		);

		self::assertSame( array( '10.5555/lps.example' ), array_values( array_unique( $normalized ) ) );
		self::assertSame( '', RelationshipPolicy::normalize_doi( 'https://example.org/not-a-doi' ) );
	}

	public function test_dangling_and_wrong_typed_relationship_targets_are_rejected(): void {
		self::assertSame(
			'lps_orphan_relationship_target',
			RelationshipPolicy::endpoint_error( 'project_member', 'lps_project', null, false )
		);
		self::assertSame(
			'lps_invalid_relationship_target_type',
			RelationshipPolicy::endpoint_error( 'project_member', 'lps_project', 'lps_organization', true )
		);
	}

	public function test_project_membership_requires_at_least_one_lead(): void {
		$errors = RelationshipPolicy::relationship_errors(
			'project_member',
			array(
				array( 'target_post_id' => 10, 'relationship_role' => 'member', 'sort_order' => 1 ),
				array( 'target_post_id' => 20, 'relationship_role' => 'member', 'sort_order' => 2 ),
			)
		);

		self::assertContains( 'lps_project_lead_required', $errors );
	}

	public function test_research_areas_require_one_primary_and_no_more_than_four_secondary(): void {
		$six_areas = array( array( 'target_post_id' => 1, 'relationship_role' => 'primary', 'sort_order' => 1 ) );
		for ( $position = 2; $position <= 6; ++$position ) {
			$six_areas[] = array( 'target_post_id' => $position, 'relationship_role' => 'secondary', 'sort_order' => $position );
		}

		$errors = RelationshipPolicy::relationship_errors( 'research_area', $six_areas );

		self::assertContains( 'lps_too_many_secondary_research_areas', $errors );
		self::assertNotContains( 'lps_primary_research_area_required', $errors );
	}

	public function test_reverse_lists_are_derived_from_canonical_rows(): void {
		$canonical = array(
			array( 'source_post_id' => 300, 'target_post_id' => 70, 'relationship_type' => 'project_member', 'sort_order' => 2 ),
			array( 'source_post_id' => 200, 'target_post_id' => 70, 'relationship_type' => 'project_member', 'sort_order' => 1 ),
			array( 'source_post_id' => 100, 'target_post_id' => 80, 'relationship_type' => 'project_member', 'sort_order' => 1 ),
		);

		$reverse = RelationshipPolicy::reverse_rows( $canonical, 70, 'project_member' );

		self::assertSame( array( 200, 300 ), array_column( $reverse, 'source_post_id' ) );
		foreach ( $reverse as $row ) {
			self::assertArrayNotHasKey( 'reverse_relationship', $row );
		}
	}

	public function test_alumni_and_deceased_people_remain_valid_historical_targets(): void {
		self::assertTrue( RelationshipPolicy::historical_target_allowed( 'alumni' ) );
		self::assertTrue( RelationshipPolicy::historical_target_allowed( 'deceased' ) );
		self::assertTrue( RelationshipPolicy::historical_target_allowed( 'in-memoriam' ) );
	}

	public function test_any_record_participating_in_a_relationship_is_referenced(): void {
		$rows = array( array( 'source_post_id' => 44, 'target_post_id' => 55 ) );

		self::assertTrue( RelationshipPolicy::record_participates( 44, $rows ) );
		self::assertTrue( RelationshipPolicy::record_participates( 55, $rows ) );
		self::assertSame( 'lps_record_referenced', Policy::deletion_error( false, true ) );
	}

	public function test_manual_reverse_relationship_payloads_are_rejected(): void {
		self::assertSame(
			'lps_manual_reverse_forbidden',
			RelationshipPolicy::manual_reverse_error( array( 'reverse_relationships' => array() ) )
		);
		self::assertSame(
			'lps_manual_reverse_forbidden',
			RelationshipPolicy::manual_reverse_error( array( 'relationship_type' => 'person_project' ) )
		);
	}

}
