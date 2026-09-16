<?php
/**
 * Deterministic migration boundary contract tests.
 *
 * @package LPS\ContentModel\Tests
 */

declare(strict_types=1);

namespace LPS\ContentModel\Tests;

use LPS\ContentModel\ImportPackage;
use LPS\ContentModel\MigrationPolicy;
use LPS\ContentModel\RedirectPolicy;

require_once dirname( __DIR__ ) . '/includes/class-policy.php';
require_once dirname( __DIR__ ) . '/includes/class-contracts.php';
require_once dirname( __DIR__ ) . '/includes/class-relationshippolicy.php';
require_once dirname( __DIR__ ) . '/includes/class-migrationpolicy.php';
require_once dirname( __DIR__ ) . '/includes/class-redirectpolicy.php';
require_once dirname( __DIR__ ) . '/includes/class-importpackage.php';

/** Proves input normalization and rejection before WordPress can mutate. */
final class MigrationContractsTest extends \PHPUnit\Framework\TestCase {
	private const FIXTURE = __DIR__ . '/fixtures/todo12-migration.json';

	/**
	 * Verifies that fixture covers every record relationship author and redirect type.
	 */
	public function test_fixture_covers_every_record_relationship_author_and_redirect_type(): void {
		$package = ImportPackage::from_file( self::FIXTURE );
		/**
		 * Fixture records.
		 *
		 * @var list<array{type: string}> $records
		 */
		$records = $package['records'];
		/**
		 * Fixture relationships.
		 *
		 * @var list<array{type: string}> $relationships
		 */
		$relationships = $package['relationships'];
		/**
		 * Fixture authorships.
		 *
		 * @var list<array{authors: list<array{kind: string}>}> $authorships
		 */
		$authorships = $package['authorships'];

		self::assertSame( array_keys( \LPS\ContentModel\Contracts::post_types() ), array_values( array_unique( array_column( $records, 'type' ) ) ) );
		self::assertSame( array_keys( \LPS\ContentModel\RelationshipPolicy::relation_specs() ), array_values( array_unique( array_column( $relationships, 'type' ) ) ) );
		self::assertSame( array( 'internal', 'external', 'collective' ), array_column( $authorships[0]['authors'], 'kind' ) );
		self::assertSame( array( 301, 410 ), array_column( $package['redirects'], 'status' ) );
	}

	/**
	 * Verifies that normalizes doi identifier url and partial date without inventing fields.
	 */
	public function test_normalizes_doi_identifier_url_and_partial_date_without_inventing_fields(): void {
		/**
		 * Fixture record.
		 *
		 * @var array{record_id: string, source_url: string, meta: array<string, mixed>} $record
		 */
		$record = MigrationPolicy::normalize_record(
			array(
				'record_id'  => ' LPS:Publication:EXAMPLE ',
				'type'       => 'lps_publication',
				'source_url' => 'HTTPS://Example.COM:443/a/../paper?b=2&a=1#fragment',
				'meta'       => array(
					'_lps_doi'              => 'doi:10.5555/LPS.Example',
					'_lps_publication_date' => '2024-5',
					'_lps_date_precision'   => 'month',
				),
			)
		);

		self::assertSame( 'lps:publication:example', $record['record_id'] );
		self::assertSame( 'https://example.com/paper?a=1&b=2', $record['source_url'] );
		self::assertSame( '10.5555/lps.example', $record['meta']['_lps_doi'] );
		self::assertSame( '2024-05', $record['meta']['_lps_publication_date'] );
		self::assertArrayNotHasKey( '_lps_venue', $record['meta'] );
	}

	/**
	 * Verifies that invalid date precision is quarantined.
	 */
	public function test_invalid_date_precision_is_quarantined(): void {
		self::assertSame( 'lps_invalid_date_precision', MigrationPolicy::date_error( '2024-05', 'day' ) );
		self::assertSame( 'lps_invalid_date_precision', MigrationPolicy::date_error( '2024-02-30', 'day' ) );
	}

	/**
	 * Verifies that internal person match requires explicit manual confirmation.
	 */
	public function test_internal_person_match_requires_explicit_manual_confirmation(): void {
		self::assertSame(
			'lps_person_match_confirmation_required',
			MigrationPolicy::person_match_error(
				array(
					'kind'   => 'internal',
					'person' => 'lps:person:ada',
				)
			)
		);
		self::assertNull(
			MigrationPolicy::person_match_error(
				array(
					'kind'                => 'internal',
					'person'              => 'lps:person:ada',
					'manual_confirmation' => true,
				)
			)
		);
	}

	/**
	 * Verifies that missing media rights is quarantined before file staging.
	 */
	public function test_missing_media_rights_is_quarantined_before_file_staging(): void {
		self::assertSame(
			'lps_media_rights_unknown',
			MigrationPolicy::media_error(
				array(
					'path'          => 'photo.png',
					'rights_status' => 'unknown',
				)
			)
		);
	}

	/**
	 * Verifies that unavailable crossref is bounded and does not invent metadata.
	 */
	public function test_unavailable_crossref_is_bounded_and_does_not_invent_metadata(): void {
		$result = MigrationPolicy::crossref_fields(
			array(
				'status'     => 'unavailable',
				'cache_key'  => '10.5555/example',
				'fetched_at' => '2026-08-30T00:00:00Z',
				'fields'     => array(),
			)
		);

		self::assertSame( 'lps_crossref_unavailable', $result['code'] );
		self::assertSame( array(), $result['fields'] );
	}

	/**
	 * Verifies that redirect graph rejects duplicate chain loop and unsafe external target.
	 */
	public function test_redirect_graph_rejects_duplicate_chain_loop_and_unsafe_external_target(): void {
		$redirects = array(
			array(
				'source' => '/a/',
				'target' => '/b/',
				'status' => 301,
			),
			array(
				'source' => '/a',
				'target' => '/c/',
				'status' => 301,
			),
			array(
				'source' => '/b/',
				'target' => '/a/',
				'status' => 301,
			),
			array(
				'source' => '/external/',
				'target' => 'https://attacker.example/path',
				'status' => 301,
			),
		);

		$codes = array_column( RedirectPolicy::verify( $redirects, 'https://lps.ufrj.br' ), 'code' );

		self::assertContains( 'lps_redirect_duplicate_source', $codes );
		self::assertContains( 'lps_redirect_chain', $codes );
		self::assertContains( 'lps_redirect_loop', $codes );
		self::assertContains( 'lps_redirect_unsafe_target', $codes );
	}

	/**
	 * Verifies that deliberate gone redirect is valid and targetless.
	 */
	public function test_deliberate_gone_redirect_is_valid_and_targetless(): void {
		self::assertSame(
			array(),
			RedirectPolicy::verify(
				array(
					array(
						'source' => '/gone/',
						'target' => '',
						'gone'   => true,
						'status' => 410,
					),
				),
				'https://lps.ufrj.br'
			)
		);
	}

	/**
	 * Verifies that reviewed field difference is a conflict not an overwrite.
	 */
	public function test_reviewed_field_difference_is_a_conflict_not_an_overwrite(): void {
		$conflicts = MigrationPolicy::reviewed_conflicts(
			array(
				'post_title' => 'Reviewed title',
				'_lps_venue' => 'Reviewed venue',
			),
			array(
				'post_title' => 'Incoming title',
				'_lps_venue' => 'Incoming venue',
			),
			array( 'post_title', '_lps_venue' )
		);

		self::assertSame( array( 'post_title', '_lps_venue' ), array_column( $conflicts, 'field' ) );
		self::assertSame( array( 'lps_reviewed_field_conflict', 'lps_reviewed_field_conflict' ), array_column( $conflicts, 'code' ) );
	}

	/**
	 * Verifies that provenance survives normalization and reconciliation detects mismatch.
	 */
	public function test_provenance_survives_normalization_and_reconciliation_detects_mismatch(): void {
		$package = ImportPackage::from_file( self::FIXTURE );
		$record  = $package['records'][0];

		self::assertSame( 'page-1', $record['source_id'] );
		self::assertSame( '2026-08-30T23:03:43Z', $record['captured_at'] );
		self::assertSame( 'sha256:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', $record['checksum'] );
		self::assertSame( 'lps_reconciliation_count_mismatch', MigrationPolicy::reconciliation_error( 10, 9 ) );
	}
}
