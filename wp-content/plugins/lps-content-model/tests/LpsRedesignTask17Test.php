<?php
/**
 * Reviewed launch corpus and media-selection boundary contracts (task 17).
 *
 * @package LPS\ContentModel\Tests
 */

declare(strict_types=1);

namespace LPS\ContentModel\Tests;

require_once dirname( __DIR__ ) . '/includes/class-policy.php';
require_once dirname( __DIR__ ) . '/includes/class-contracts.php';
require_once dirname( __DIR__ ) . '/includes/class-relationshippolicy.php';
require_once dirname( __DIR__ ) . '/includes/class-teachingcontracts.php';
require_once dirname( __DIR__ ) . '/includes/class-migrationpolicy.php';
require_once dirname( __DIR__ ) . '/includes/class-redirectpolicy.php';
require_once dirname( __DIR__ ) . '/includes/class-importpackage.php';

use LPS\ContentModel\Contracts;
use LPS\ContentModel\ImportPackage;
use LPS\ContentModel\MigrationPolicy;
use LPS\ContentModel\Policy;
use LPS\ContentModel\TeachingContracts;
use PHPUnit\Framework\TestCase;

/**
 * Proves the task-17 launch-corpus admission rules without WordPress.
 *
 * Synthetic/fixture records, legacy-scrape sources, missing catalog sources,
 * unsourced claims and undocumented image alt/credit are all pure policy:
 * they run here directly. The live dry-run/apply/re-apply/export rehearsal
 * against a real WordPress database is exercised by
 * `scripts/migration/launch-corpus-rehearsal.php` inside the dedicated
 * Playground environment.
 */
final class LpsRedesignTask17Test extends TestCase {
	private const LAUNCH_PACKAGE = __DIR__ . '/../../../../content/import/launch-corpus.json';
	private const FIXTURE        = __DIR__ . '/fixtures/todo12-migration.json';

	/**
	 * Returns a minimal admissible record; tests mutate one field at a time.
	 *
	 * @return array<string, mixed>
	 */
	private static function record(): array {
		return array(
			'source_id'    => 'record-001',
			'type'         => 'page',
			'record_id'    => 'lps:page:00000000-0000-4000-8000-0000000000aa',
			'title'        => 'Laboratório de Processamento de Sinais',
			'slug'         => 'inicio',
			'locale'       => 'pt-br',
			'state'        => 'draft',
			'source_url'   => 'https://sites.google.com/lps.ufrj.br/lps/in%C3%ADcio',
			'captured_at'  => '2026-08-30T23:03:43Z',
			'checksum'     => 'sha256:' . str_repeat( 'a', 64 ),
			'rights'       => 'public-record',
			'review_state' => 'candidate',
			'meta'         => array(),
		);
	}

	/** An admissible record passes both boundary checks. */
	public function test_clean_record_has_no_source_or_claim_error(): void {
		self::assertNull( MigrationPolicy::source_error( self::record() ) );
		self::assertNull( MigrationPolicy::claim_error( self::record() ) );
	}

	/** Fixture flags, fixture source ids and fixture provenance paths are rejected outright. */
	public function test_synthetic_and_fixture_identities_are_hard_errors(): void {
		$record              = self::record();
		$record['synthetic'] = true;
		self::assertSame( 'lps_import_synthetic_record', MigrationPolicy::source_error( $record ) );

		$record              = self::record();
		$record['source_id'] = 'fixture:person-1';
		self::assertSame( 'lps_import_synthetic_record', MigrationPolicy::source_error( $record ) );

		$record              = self::record();
		$record['source_id'] = 'lps-redesign-attempt-1-person';
		self::assertSame( 'lps_import_synthetic_record', MigrationPolicy::source_error( $record ) );

		$record               = self::record();
		$record['source_url'] = 'https://staging.example/tests/fixtures/lps-redesign/people.json';
		self::assertSame( 'lps_import_synthetic_record', MigrationPolicy::source_error( $record ) );
	}

	/** Legacy and archive hosts are rejected on active records; redirects keep them as provenance. */
	public function test_legacy_scrape_hosts_are_hard_errors_except_redirects(): void {
		foreach ( array( 'https://web.archive.org/web/2002/http://www.lps.ufrj.br/', 'https://lps.ufrj.br/projetos/', 'http://www.lps.ufrj.br/publications/publications.html' ) as $url ) {
			$record               = self::record();
			$record['source_url'] = $url;
			self::assertSame( 'lps_import_legacy_scrape_source', MigrationPolicy::source_error( $record ), $url );
		}

		// Redirect records legitimately carry the legacy URL they replace.
		$record               = self::record();
		$record['type']       = 'lps_redirect';
		$record['source_url'] = 'http://www.lps.ufrj.br/publications/publications.html';
		self::assertNull( MigrationPolicy::source_error( $record ) );
	}

	/** Course and term imports quarantine until an authoritative catalog source is supplied. */
	public function test_course_code_and_calendar_records_require_catalog_sources(): void {
		$course         = self::record();
		$course['type'] = 'lps_course';
		$course['meta'] = array( '_lps_course_code' => 'EEL315' );
		self::assertSame( 'lps_import_course_source_required', MigrationPolicy::source_error( $course ) );

		$course['meta']['_lps_catalog_source_url'] = 'https://catalog.example/eel315';
		self::assertNull( MigrationPolicy::source_error( $course ) );

		$term         = self::record();
		$term['type'] = 'lps_term';
		$term['meta'] = array( '_lps_term_code' => '2026.1' );
		self::assertSame( 'lps_import_course_source_required', MigrationPolicy::source_error( $term ) );

		$term['meta']['_lps_term_source'] = 'UFRJ academic calendar 2026.1';
		self::assertNull( MigrationPolicy::source_error( $term ) );
	}

	/** A verified claim needs its source URL and review date; claim rows need source and evidence. */
	public function test_verified_claims_require_source_and_review_date(): void {
		$record         = self::record();
		$record['meta'] = array( '_lps_claim_verified' => true );
		self::assertSame( 'lps_import_claim_unsourced', MigrationPolicy::claim_error( $record ) );

		$record['meta']['_lps_claim_source_url'] = 'https://sites.google.com/lps.ufrj.br/lps/in%C3%ADcio';
		self::assertSame( 'lps_import_claim_unsourced', MigrationPolicy::claim_error( $record ), 'review date still missing' );

		$record['meta']['_lps_claim_reviewed_at'] = '2026-09-06';
		self::assertNull( MigrationPolicy::claim_error( $record ) );

		$record           = self::record();
		$record['claims'] = array(
			array(
				'statement'  => 'Founded in 1996',
				'source_url' => '',
			),
		);
		self::assertSame( 'lps_import_claim_unsourced', MigrationPolicy::claim_error( $record ) );
	}

	/** Staged images need reviewed alt text or an explicit decorative decision, and credit stays required. */
	public function test_image_media_requires_alt_or_decorative_and_credit(): void {
		$media = array(
			'path'          => 'photo.png',
			'media_type'    => 'image/png',
			'rights_status' => 'cleared',
			'rights_holder' => 'LPS',
			'credit'        => 'LPS',
			'license'       => 'CC BY 4.0',
			'source_url'    => 'https://legacy.example/photo.png',
			'checksum'      => 'sha256:' . str_repeat( 'b', 64 ),
		);
		self::assertSame( 'lps_media_alt_required', MigrationPolicy::media_error( $media ) );

		$media['alt_text'] = 'Bancada de instrumentação do laboratório';
		self::assertNull( MigrationPolicy::media_error( $media ) );

		unset( $media['alt_text'] );
		$media['decorative'] = true;
		self::assertNull( MigrationPolicy::media_error( $media ) );

		unset( $media['decorative'], $media['credit'] );
		$media['alt_text'] = 'Bancada';
		self::assertSame( 'lps_media_provenance_required', MigrationPolicy::media_error( $media ), 'credit is still required' );
	}

	/** Owner-review codes quarantine; synthetic and legacy-scrape codes fail hard. */
	public function test_quarantine_routing_separates_owner_review_from_hard_failure(): void {
		$codes = MigrationPolicy::quarantine_codes();
		foreach ( array( 'lps_media_alt_required', 'lps_import_course_source_required', 'lps_import_claim_unsourced', 'lps_media_rights_unknown', 'lps_media_provenance_required', 'lps_person_match_confirmation_required' ) as $code ) {
			self::assertContains( $code, $codes );
		}
		foreach ( array( 'lps_import_synthetic_record', 'lps_import_legacy_scrape_source' ) as $code ) {
			self::assertNotContains( $code, $codes, $code . ' is a hard rejection, not a review quarantine' );
		}
	}

	/** The package boundary reports each new admission code against its record. */
	public function test_package_errors_surface_new_codes_per_record(): void {
		$package = array(
			'schema_version' => '1.0',
			'records'        => array(
				array_merge( self::record(), array( 'synthetic' => true ) ),
				array_merge(
					self::record(),
					array(
						'record_id'  => 'lps:page:00000000-0000-4000-8000-0000000000ab',
						'source_url' => 'https://web.archive.org/web/2002/http://www.lps.ufrj.br/',
					)
				),
				array_merge(
					self::record(),
					array(
						'record_id' => 'lps:course:00000000-0000-4000-8000-0000000000ac',
						'type'      => 'lps_course',
						'meta'      => array( '_lps_course_code' => 'EEL315' ),
					)
				),
				array_merge(
					self::record(),
					array(
						'record_id' => 'lps:page:00000000-0000-4000-8000-0000000000ad',
						'meta'      => array( '_lps_claim_verified' => true ),
					)
				),
			),
			'relationships'  => array(),
			'authorships'    => array(),
			'media'          => array(),
			'redirects'      => array(),
		);
		$codes   = array_column( ImportPackage::errors( $package ), 'code' );
		self::assertContains( 'lps_import_synthetic_record', $codes );
		self::assertContains( 'lps_import_legacy_scrape_source', $codes );
		self::assertContains( 'lps_import_course_source_required', $codes );
		self::assertContains( 'lps_import_claim_unsourced', $codes );
	}

	/** The reviewed launch package carries no boundary errors and no staged media. */
	public function test_checked_in_launch_package_passes_the_hardened_boundary(): void {
		self::assertFileExists( self::LAUNCH_PACKAGE );
		$package = ImportPackage::from_file( self::LAUNCH_PACKAGE );
		self::assertSame( array(), ImportPackage::errors( $package ), 'the reviewed launch package must carry no boundary errors' );
		self::assertSame( 24, count( $package['records'] ), '12 migrate records x 2 locales' );
		self::assertSame( array(), $package['media'], 'no media is staged while rights are undocumented' );
		foreach ( $package['records'] as $record ) {
			self::assertNull( MigrationPolicy::source_error( $record ), Policy::scalar_string( $record['source_id'] ?? null ) );
		}
	}

	/** The migration fixture satisfies the catalog-source and alt-text contracts. */
	public function test_todo12_fixture_remains_admissible_under_new_contracts(): void {
		$package = ImportPackage::from_file( self::FIXTURE );
		foreach ( $package['records'] as $record ) {
			self::assertNull( MigrationPolicy::source_error( $record ), Policy::scalar_string( $record['source_id'] ?? null ) );
		}
		foreach ( $package['media'] as $asset ) {
			self::assertNull( MigrationPolicy::media_error( $asset ) );
		}
	}

	/** The catalog source field is registered shared provenance on lps_course. */
	public function test_course_contract_registers_the_catalog_source_field(): void {
		$fields = Contracts::meta_fields();
		self::assertArrayHasKey( '_lps_catalog_source_url', $fields['lps_course'] );
		$ownership = TeachingContracts::field_ownership( 'lps_course' );
		self::assertSame( 'shared', $ownership['_lps_catalog_source_url'], 'the catalog source is shared provenance, not localized text' );
	}
}
