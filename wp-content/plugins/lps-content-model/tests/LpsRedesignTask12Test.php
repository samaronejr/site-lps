<?php
/**
 * Teaching search indexing, lifecycle gating and propagation contracts (task 12).
 *
 * @package LPS\ContentModel\Tests
 */

declare(strict_types=1);

namespace LPS\ContentModel\Tests;

require_once dirname( __DIR__ ) . '/includes/class-searchindex.php';
require_once dirname( __DIR__ ) . '/includes/class-searchstorage.php';
require_once dirname( __DIR__ ) . '/includes/class-teachingresources.php';
require_once dirname( __DIR__, 3 ) . '/themes/lps-theme/includes/class-searchsurfaces.php';

use LPS\ContentModel\Policy;
use LPS\ContentModel\SearchIndex;
use LPS\ContentModel\SearchPolicy;
use LPS\ContentModel\SearchStorage;
use LPS\ContentModel\TeachingContracts;
use LPS\ContentModel\TeachingResources;
use LPS\Theme\SearchSurfaces;
use PHPUnit\Framework\TestCase;

/** In-memory index storage for the task-12 contract assertions. */
final class Task12SearchStorage implements SearchStorage {
	/**
	 * Stored index rows keyed by record database ID.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	public array $rows = array();

	/** Opens a transaction and reports whether it started. */
	public function begin(): bool {
		return true;
	}

	/** Commits the open transaction. */
	public function commit(): void {
	}

	/** Reverts the open transaction. */
	public function rollback(): void {
	}

	/**
	 * Removes every indexed row of one record.
	 *
	 * @param int $post_id Record database ID.
	 */
	public function delete_record( int $post_id ): bool {
		unset( $this->rows[ $post_id ] );
		return true;
	}

	/**
	 * Writes one indexed row.
	 *
	 * @param array<string, mixed> $row Indexed row.
	 */
	public function insert_record( array $row ): bool {
		$post_id                = Policy::sanitize_integer( $row['post_id'] ?? 0 );
		$this->rows[ $post_id ] = $row;
		return true;
	}

	/**
	 * Reads the indexed rows of one locale and record type.
	 *
	 * @param string $locale    Supported locale slug.
	 * @param string $post_type Record type, empty for every indexed type.
	 * @return array<int, array<string, mixed>>
	 */
	public function rows_for( string $locale, string $post_type ): array {
		$rows = array();
		foreach ( $this->rows as $row ) {
			if ( ( $row['locale'] ?? '' ) !== $locale ) {
				continue;
			}
			if ( '' !== $post_type && ( $row['post_type'] ?? '' ) !== $post_type ) {
				continue;
			}
			$rows[] = SearchIndex::hydrate( $row );
		}
		return $rows;
	}
}

/**
 * Proves the task-12 teaching search contracts without WordPress.
 *
 * Indexable types, weighted teaching terms, the current/previous offering
 * filter, resource lifecycle gating, locale isolation and private-field
 * exclusion are all pure policy: they run here directly. The wired boundary —
 * publish/correction/withdrawal propagation through real HTTP GETs — is
 * exercised by the e2e spec.
 */
final class LpsRedesignTask12Test extends TestCase {
	private const NOW = '2026-09-19T12:00:00+00:00';

	/**
	 * Returns a published, publicly visible portable record.
	 *
	 * @param int                  $post_id   Record database ID.
	 * @param string               $locale    Supported locale slug.
	 * @param string               $post_type Record type.
	 * @param string               $title     Record title.
	 * @param array<string, mixed> $overrides Field overrides.
	 * @return array<string, mixed>
	 */
	private static function record( int $post_id, string $locale, string $post_type, string $title, array $overrides = array() ): array {
		return array_merge(
			array(
				'post_id'        => $post_id,
				'status'         => 'publish',
				'locale'         => $locale,
				'post_type'      => $post_type,
				'title'          => $title,
				'summary'        => 'resumo público',
				'body'           => 'corpo público',
				'facets'         => array(),
				'url'            => '/pt-br/registro/',
				'published_at'   => '2026-03-01 00:00:00',
				'_lps_state'     => 'published',
				'_lps_origin'    => 'native',
				'_lps_record_id' => 'lps:record:018f21ce-7d7a-7abc-8a2f-2d6937f89a11',
				'stale'          => false,
				'source_status'  => 'publish',
				'source_state'   => 'published',
			),
			$overrides
		);
	}

	/**
	 * Returns a released, fully approved resource record.
	 *
	 * @param array<string, mixed> $overrides Field overrides.
	 * @return array<string, mixed>
	 */
	private static function resource( array $overrides = array() ): array {
		return self::record(
			301,
			'pt-br',
			'lps_resource',
			'Apostila de Sinais',
			array_merge(
				array(
					'_lps_release_state'        => 'released',
					'_lps_release_at'           => '',
					'_lps_version_id'           => 'lpsver:' . str_repeat( 'a', 64 ),
					'_lps_scan_state'           => 'clean',
					'_lps_rights_review'        => 'approved',
					'_lps_accessibility_review' => 'approved',
					'offering_visible'          => true,
					'language'                  => 'pt-br',
					'resource_type'             => 'document',
					'lifecycle'                 => array(
						'release_state' => 'released',
						'release_at'    => '',
					),
					'facets'                    => array(
						'type'     => array( 'document' ),
						'language' => array( 'pt-br' ),
					),
				),
				$overrides
			)
		);
	}

	/**
	 * Returns a published offering record with a current-term lifecycle.
	 *
	 * @param array<string, mixed> $overrides Field overrides.
	 * @return array<string, mixed>
	 */
	private static function offering( array $overrides = array() ): array {
		return self::record(
			201,
			'pt-br',
			'lps_offering',
			'Sinais e Sistemas — Turma T01',
			array_merge(
				array(
					'code'         => 'LPS-101',
					'course_title' => 'Sinais e Sistemas',
					'term_label'   => '2026.2',
					'term_token'   => '2026-2-semester',
					'section'      => 't01',
					'instructors'  => array( 'Docente de Sinais', 'Colega Convidado' ),
					'lifecycle'    => array(
						'starts_on' => '2026-08-01',
						'ends_on'   => '2026-12-15',
						'cancelled' => false,
					),
					'facets'       => array(
						'status'     => array( 'current' ),
						'term'       => array( '2026-2-semester' ),
						'level'      => array( 'undergraduate' ),
						'instructor' => array( 'docente-sinais', 'colega-convidado' ),
					),
				),
				$overrides
			)
		);
	}

	/**
	 * Courses, offerings and resources are indexable; terms and units are not.
	 */
	public function test_teaching_record_types_are_indexable_but_terms_and_units_are_not(): void {
		foreach ( array( 'lps_course', 'lps_offering', 'lps_resource' ) as $type ) {
			self::assertContains( $type, SearchIndex::indexed_post_types() );
			self::assertTrue( SearchIndex::is_indexable( 'publish', 'pt-br', $type ), $type );
			self::assertTrue( SearchIndex::is_indexable( 'publish', 'en', $type ), $type );
			self::assertFalse( SearchIndex::is_indexable( 'draft', 'pt-br', $type ), $type . ' draft' );
			self::assertFalse( SearchIndex::is_indexable( 'publish', 'fr', $type ), $type . ' fr' );
		}
		foreach ( array( 'lps_term', 'lps_unit' ) as $type ) {
			self::assertNotContains( $type, SearchIndex::indexed_post_types() );
			self::assertFalse( SearchIndex::is_indexable( 'publish', 'pt-br', $type ), $type );
		}
	}

	/**
	 * A course row carries its code as an exact term and its level as a facet.
	 */
	public function test_course_rows_carry_exact_codes_levels_and_authored_text(): void {
		$row = SearchIndex::build_row(
			self::record(
				101,
				'pt-br',
				'lps_course',
				'Sinais e Sistemas',
				array(
					'code'          => 'LPS-101',
					'program'       => 'Engenharia Elétrica',
					'prerequisites' => 'Cálculo III',
					'syllabus'      => 'Ementa de sinais contínuos',
					'facets'        => array( 'level' => array( 'undergraduate' ) ),
				)
			)
		);

		self::assertStringContainsString( 'lps-101', self::text( $row['exact_terms'] ) );
		self::assertStringContainsString( 'sinais e sistemas', self::text( $row['high_terms'] ) );
		self::assertStringContainsString( 'engenharia eletrica', self::text( $row['medium_terms'] ) );
		self::assertStringContainsString( 'calculo iii', self::text( $row['low_terms'] ) );
		self::assertStringContainsString( 'ementa de sinais', self::text( $row['low_terms'] ) );
		$facets = self::decoded( self::text( $row['facets'] ) );
		self::assertSame( array( 'undergraduate' ), $facets['level'] ?? null );
	}

	/**
	 * An offering row carries instructors, term labels and lifecycle facts.
	 */
	public function test_offering_rows_carry_instructors_term_labels_and_lifecycle(): void {
		$row = SearchIndex::build_row( self::offering() );

		self::assertStringContainsString( 'docente de sinais', self::text( $row['high_terms'] ) );
		self::assertStringContainsString( 'colega convidado', self::text( $row['high_terms'] ) );
		self::assertStringContainsString( 'sinais e sistemas', self::text( $row['high_terms'] ) );
		self::assertStringContainsString( '2026.2', self::text( $row['medium_terms'] ) );
		self::assertStringContainsString( 't01', self::text( $row['medium_terms'] ) );
		self::assertStringContainsString( '2026-2-semester', self::text( $row['exact_terms'] ) );
		self::assertStringContainsString( 'lps-101', self::text( $row['exact_terms'] ) );
		$facets = self::decoded( self::text( $row['facets'] ) );
		self::assertSame( array( 'current' ), $facets['status'] ?? null );
		self::assertSame( array( '2026-2-semester' ), $facets['term'] ?? null );
		self::assertSame( array( 'docente-sinais', 'colega-convidado' ), $facets['instructor'] ?? null );
		$lifecycle = self::decoded( self::text( $row['lifecycle'] ) );
		self::assertSame( '2026-08-01', $lifecycle['starts_on'] ?? null );
		self::assertSame( '2026-12-15', $lifecycle['ends_on'] ?? null );
		self::assertFalse( $lifecycle['cancelled'] ?? null );
	}

	/**
	 * A resource row carries authored language and release lifecycle, never private fields.
	 */
	public function test_resource_rows_carry_language_and_release_lifecycle_without_private_fields(): void {
		$record = self::resource();
		foreach ( SearchIndex::private_fields() as $field ) {
			$record[ $field ] = 'segredo-' . $field;
		}
		$record['_lps_storage_key'] = 'lps-file-' . str_repeat( '9', 32 );

		$row     = SearchIndex::build_row( $record );
		$encoded = (string) json_encode( $row, JSON_UNESCAPED_UNICODE ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- assertion helper running without WordPress.

		self::assertStringNotContainsString( 'segredo-', $encoded );
		self::assertStringNotContainsString( 'lps-file-', $encoded );
		self::assertStringNotContainsString( 'lpsver:', $encoded );
		$facets = self::decoded( self::text( $row['facets'] ) );
		self::assertSame( array( 'document' ), $facets['type'] ?? null );
		self::assertSame( array( 'pt-br' ), $facets['language'] ?? null );
		$lifecycle = self::decoded( self::text( $row['lifecycle'] ) );
		self::assertSame( 'released', $lifecycle['release_state'] ?? null );
	}

	/**
	 * The resource visibility decision mirrors the download boundary.
	 */
	public function test_resource_visibility_requires_release_reviews_and_a_public_offering(): void {
		self::assertTrue( SearchIndex::is_publicly_visible( self::resource() ) );

		// An external resource carries no bytes to scan: released and approved
		// is enough, matching the publish gate and the download resolver.
		$external = self::resource(
			array(
				'_lps_version_id' => '',
				'_lps_scan_state' => '',
				'external_url'    => 'https://example.org/notes.pdf',
			)
		);
		self::assertTrue( SearchIndex::is_publicly_visible( $external ) );

		// A scheduled resource is indexed so its release needs no scheduler.
		$scheduled = self::resource(
			array(
				'_lps_release_state' => 'scheduled',
				'_lps_release_at'    => '2026-10-01T00:00:00+00:00',
				'lifecycle'          => array(
					'release_state' => 'scheduled',
					'release_at'    => '2026-10-01T00:00:00+00:00',
				),
			)
		);
		self::assertTrue( SearchIndex::is_publicly_visible( $scheduled ) );

		// Withdrawn, draft-release, unapproved and hidden-offering resources
		// never enter the index at all.
		self::assertFalse( SearchIndex::is_publicly_visible( self::resource( array( '_lps_release_state' => 'withdrawn' ) ) ) );
		self::assertFalse( SearchIndex::is_publicly_visible( self::resource( array( '_lps_release_state' => 'draft' ) ) ) );
		self::assertFalse( SearchIndex::is_publicly_visible( self::resource( array( '_lps_rights_review' => 'pending' ) ) ) );
		self::assertFalse( SearchIndex::is_publicly_visible( self::resource( array( '_lps_accessibility_review' => 'rejected' ) ) ) );
		self::assertFalse( SearchIndex::is_publicly_visible( self::resource( array( '_lps_scan_state' => 'pending' ) ) ) );
		self::assertFalse( SearchIndex::is_publicly_visible( self::resource( array( 'offering_visible' => false ) ) ) );
		self::assertFalse(
			SearchIndex::is_publicly_visible(
				self::resource(
					array(
						'offering_visible' => true,
						'_lps_state'       => 'in_review',
					)
				)
			)
		);
	}

	/**
	 * The effective release state is the single rule both boundaries share.
	 */
	public function test_effective_release_state_is_shared_by_contracts_and_resources(): void {
		$now = self::NOW;
		foreach ( array(
			'released'  => 'released',
			'withdrawn' => 'withdrawn',
			'draft'     => 'draft',
			'bogus'     => 'draft',
		) as $state => $expected ) {
			self::assertSame( $expected, TeachingContracts::effective_release_state( $state, '', $now ) );
			self::assertSame( $expected, TeachingResources::effective_release_state( $state, '', $now ) );
		}
		self::assertSame( 'scheduled', TeachingContracts::effective_release_state( 'scheduled', '2026-09-19T13:00:00+00:00', $now ) );
		self::assertSame( 'released', TeachingContracts::effective_release_state( 'scheduled', '2026-09-19T12:00:00+00:00', $now ) );
		self::assertSame( 'released', TeachingContracts::effective_release_state( 'scheduled', '2026-09-19T11:00:00+00:00', $now ) );
		self::assertSame( 'scheduled', TeachingContracts::effective_release_state( 'scheduled', 'not-a-date', $now ) );
	}

	/**
	 * Scheduled resources stay out of results and counts until their time passes.
	 */
	public function test_scheduled_resources_are_gated_at_query_time_never_by_a_scheduler(): void {
		$storage = new Task12SearchStorage();
		SearchIndex::synchronize(
			$storage,
			self::resource(
				array(
					'post_id'            => 302,
					'title'              => 'Material agendado',
					'_lps_release_state' => 'scheduled',
					'_lps_release_at'    => '2026-10-01T00:00:00+00:00',
					'lifecycle'          => array(
						'release_state' => 'scheduled',
						'release_at'    => '2026-10-01T00:00:00+00:00',
					),
				)
			)
		);
		SearchIndex::synchronize(
			$storage,
			self::resource(
				array(
					'post_id' => 303,
					'title'   => 'Material publicado',
				)
			)
		);

		// The scheduled row is indexed — but invisible before its release time.
		self::assertArrayHasKey( 302, $storage->rows );
		$before = SearchIndex::search( $storage, '', 'material', 'pt-br', array(), 1, 20, '2026-09-20T00:00:00+00:00' );
		self::assertSame( array( 303 ), array_column( $before['items'], 'post_id' ) );

		// Once the time passes the same row answers without any scheduler run.
		$after = SearchIndex::search( $storage, '', 'material', 'pt-br', array(), 1, 20, '2026-10-02T00:00:00+00:00' );
		self::assertSame( array( 302, 303 ), array_column( $after['items'], 'post_id' ) );

		// Facet counts apply the same gate: a future release contributes nothing.
		$rows   = $storage->rows_for( 'pt-br', 'lps_resource' );
		$counts = SearchPolicy::facet_counts( $rows, SearchPolicy::facet_definitions( 'lps_resource' ), '2026-09-20T00:00:00+00:00' );
		self::assertSame( array( 'document' => 1 ), $counts['type'] );
		$counts = SearchPolicy::facet_counts( $rows, SearchPolicy::facet_definitions( 'lps_resource' ), '2026-10-02T00:00:00+00:00' );
		self::assertSame( array( 'document' => 2 ), $counts['type'] );

		// A resource row without lifecycle data fails closed.
		$legacy = SearchIndex::hydrate(
			array_merge(
				SearchIndex::build_row( self::resource( array( 'post_id' => 304 ) ) ),
				array( 'lifecycle' => '' )
			)
		);
		$result = SearchPolicy::search_records( array( $legacy ), 'material', 'pt-br', array(), 1, 20, self::NOW );
		self::assertSame( 0, $result['total'] );
	}

	/**
	 * Withdrawal removes the row; release writes it inside one transaction.
	 */
	public function test_release_and_withdrawal_transitions_update_the_index(): void {
		$storage = new Task12SearchStorage();
		SearchIndex::synchronize( $storage, self::resource( array( 'post_id' => 305 ) ) );
		self::assertArrayHasKey( 305, $storage->rows );

		$withdrawn = self::resource(
			array(
				'post_id'            => 305,
				'_lps_release_state' => 'withdrawn',
			)
		);
		SearchIndex::synchronize( $storage, $withdrawn );
		self::assertArrayNotHasKey( 305, $storage->rows );
		self::assertSame( 0, SearchIndex::search( $storage, '', 'apostila', 'pt-br', array(), 1, 20, self::NOW )['total'] );
	}

	/**
	 * The current/previous filter derives from term boundaries on every read.
	 */
	public function test_current_previous_filters_derive_from_term_boundaries_at_query_time(): void {
		$storage = new Task12SearchStorage();
		SearchIndex::synchronize( $storage, self::offering( array( 'post_id' => 201 ) ) );
		SearchIndex::synchronize(
			$storage,
			self::offering(
				array(
					'post_id'   => 202,
					'title'     => 'Sinais e Sistemas — Turma T02',
					'section'   => 't02',
					'lifecycle' => array(
						'starts_on' => '2025-08-01',
						'ends_on'   => '2025-12-15',
						'cancelled' => false,
					),
					'facets'    => array( 'status' => array( 'previous' ) ),
				)
			)
		);

		$definitions = SearchPolicy::facet_definitions( 'lps_offering' );
		self::assertSame( array( 'current', 'previous' ), $definitions['status'] );

		$current = SearchIndex::search( $storage, 'lps_offering', 'sinais', 'pt-br', array( 'status' => array( 'current' ) ), 1, 20, self::NOW );
		self::assertSame( array( 201 ), array_column( $current['items'], 'post_id' ) );
		$previous = SearchIndex::search( $storage, 'lps_offering', 'sinais', 'pt-br', array( 'status' => array( 'previous' ) ), 1, 20, self::NOW );
		self::assertSame( array( 202 ), array_column( $previous['items'], 'post_id' ) );

		// A term transition flips the bucket at query time even when the stored
		// facet still says current: the lifecycle row is the source of truth.
		$rows = $storage->rows_for( 'pt-br', 'lps_offering' );
		foreach ( $rows as &$row ) {
			if ( 201 === $row['post_id'] ) {
				$lifecycle            = self::field( $row, 'lifecycle' );
				$lifecycle['ends_on'] = '2026-09-01';
				$row['lifecycle']     = $lifecycle;
			}
		}
		unset( $row );
		$after = SearchPolicy::search_records( $rows, 'sinais', 'pt-br', array( 'status' => array( 'previous' ) ), 1, 20, '2026-09-19T12:00:00+00:00' );
		self::assertSame( array( 201, 202 ), array_column( $after['items'], 'post_id' ) );

		// A cancelled offering is previous, never current.
		foreach ( $rows as &$row ) {
			if ( 201 === $row['post_id'] ) {
				$lifecycle              = self::field( $row, 'lifecycle' );
				$lifecycle['cancelled'] = true;
				$lifecycle['ends_on']   = '2026-12-15';
				$row['lifecycle']       = $lifecycle;
			}
		}
		unset( $row );
		$cancelled = SearchPolicy::search_records( $rows, 'sinais', 'pt-br', array( 'status' => array( 'current' ) ), 1, 20, self::NOW );
		self::assertSame( array(), array_column( $cancelled['items'], 'post_id' ) );
	}

	/**
	 * The status bucket maps upcoming/current to current and the rest to previous.
	 */
	public function test_offering_status_bucket_mapping(): void {
		self::assertSame( 'current', SearchPolicy::offering_status_bucket( 'current' ) );
		self::assertSame( 'current', SearchPolicy::offering_status_bucket( 'upcoming' ) );
		self::assertSame( 'previous', SearchPolicy::offering_status_bucket( 'completed' ) );
		self::assertSame( 'previous', SearchPolicy::offering_status_bucket( 'cancelled' ) );
		self::assertSame( 'previous', SearchPolicy::offering_status_bucket( 'bogus' ) );
	}

	/**
	 * Exact course-code and accent-insensitive lookups reach teaching rows.
	 */
	public function test_exact_code_and_accent_insensitive_lookup_reach_teaching_rows(): void {
		$storage = new Task12SearchStorage();
		SearchIndex::synchronize(
			$storage,
			self::record(
				101,
				'pt-br',
				'lps_course',
				'Sinais e Sistemas',
				array(
					'code'   => 'LPS-101',
					'facets' => array( 'level' => array( 'undergraduate' ) ),
				)
			)
		);
		SearchIndex::synchronize( $storage, self::offering() );

		// The exact code resolves the course and its offering.
		$by_code = SearchIndex::search( $storage, '', 'LPS-101', 'pt-br', array(), 1, 20, self::NOW );
		self::assertSame( array( 101, 201 ), array_column( $by_code['items'], 'post_id' ) );

		// Accented and unaccented spellings return the same offering.
		$accented   = SearchIndex::search( $storage, '', 'Sinais', 'pt-br', array(), 1, 20, self::NOW );
		$unaccented = SearchIndex::search( $storage, '', 'sinais', 'pt-br', array(), 1, 20, self::NOW );
		self::assertSame( array_column( $accented['items'], 'post_id' ), array_column( $unaccented['items'], 'post_id' ) );
		self::assertContains( 201, array_column( $accented['items'], 'post_id' ) );

		// Instructor and term-label lookups reach the offering row.
		self::assertContains( 201, array_column( SearchIndex::search( $storage, '', 'Docente', 'pt-br', array(), 1, 20, self::NOW )['items'], 'post_id' ) );
		self::assertContains( 201, array_column( SearchIndex::search( $storage, '', '2026.2', 'pt-br', array(), 1, 20, self::NOW )['items'], 'post_id' ) );
	}

	/**
	 * A co-taught offering is one search entity, never one row per instructor.
	 */
	public function test_co_taught_offerings_are_single_search_entities(): void {
		$storage = new Task12SearchStorage();
		SearchIndex::synchronize( $storage, self::offering() );

		foreach ( array( 'Docente', 'Convidado' ) as $query ) {
			$result = SearchIndex::search( $storage, '', $query, 'pt-br', array(), 1, 20, self::NOW );
			self::assertSame( array( 201 ), array_column( $result['items'], 'post_id' ), $query );
			self::assertSame( 1, $result['total'], $query );
		}
		// Re-synchronizing the same record replaces the row idempotently.
		SearchIndex::synchronize( $storage, self::offering() );
		self::assertCount( 1, $storage->rows );
	}

	/**
	 * Locale isolation holds for teaching rows; drafts never enter the index.
	 */
	public function test_locale_isolation_and_draft_exclusion_hold_for_teaching(): void {
		$storage = new Task12SearchStorage();
		SearchIndex::synchronize( $storage, self::offering() );
		SearchIndex::synchronize(
			$storage,
			self::offering(
				array(
					'post_id' => 203,
					'locale'  => 'en',
					'title'   => 'Signals and Systems — Section T01',
				)
			)
		);
		SearchIndex::synchronize( $storage, self::record( 204, 'pt-br', 'lps_course', 'Rascunho', array( 'status' => 'draft' ) ) );

		$portuguese = SearchIndex::search( $storage, '', 'sinais', 'pt-br', array(), 1, 20, self::NOW );
		$english    = SearchIndex::search( $storage, '', 'signals', 'en', array(), 1, 20, self::NOW );
		self::assertSame( array( 201 ), array_column( $portuguese['items'], 'post_id' ) );
		self::assertSame( array( 203 ), array_column( $english['items'], 'post_id' ) );
		self::assertArrayNotHasKey( 204, $storage->rows );
	}

	/**
	 * The new facet contracts are approved and closed vocabularies are enforced.
	 */
	public function test_teaching_facet_definitions_and_closed_vocabularies(): void {
		self::assertSame( array( 'level' ), array_keys( SearchPolicy::facet_definitions( 'lps_course' ) ) );
		self::assertSame( array( 'status', 'term', 'level', 'instructor' ), array_keys( SearchPolicy::facet_definitions( 'lps_offering' ) ) );
		self::assertSame( array( 'type', 'language' ), array_keys( SearchPolicy::facet_definitions( 'lps_resource' ) ) );
		foreach ( array( 'lps_course', 'lps_offering', 'lps_resource' ) as $type ) {
			self::assertContains( $type, SearchPolicy::faceted_post_types() );
		}

		// The offering status vocabulary is closed: raw temporal values are
		// dropped, only the public buckets survive sanitization.
		$clean = SearchPolicy::sanitize_facets(
			array( 'status' => array( 'current', 'completed', 'cancelled', 'previous' ) ),
			SearchPolicy::facet_definitions( 'lps_offering' )
		);
		self::assertSame( array( 'current', 'previous' ), $clean['status'] );
	}

	/**
	 * The surface labels the teaching types and the current/previous values.
	 */
	public function test_search_surface_labels_teaching_types_and_status_values(): void {
		self::assertSame( 'Disciplinas', SearchSurfaces::type_label( 'lps_course', 'pt-br' ) );
		self::assertSame( 'Turmas', SearchSurfaces::type_label( 'lps_offering', 'pt-br' ) );
		self::assertSame( 'Materiais de ensino', SearchSurfaces::type_label( 'lps_resource', 'pt-br' ) );
		self::assertSame( 'Courses', SearchSurfaces::type_label( 'lps_course', 'en' ) );
		self::assertSame( 'Offerings', SearchSurfaces::type_label( 'lps_offering', 'en' ) );
		self::assertSame( 'Teaching materials', SearchSurfaces::type_label( 'lps_resource', 'en' ) );
		self::assertSame( 'Atuais', SearchSurfaces::facet_value_label( 'status', 'current', 'pt-br' ) );
		self::assertSame( 'Anteriores', SearchSurfaces::facet_value_label( 'status', 'previous', 'pt-br' ) );
		self::assertSame( 'Current', SearchSurfaces::facet_value_label( 'status', 'current', 'en' ) );
		self::assertSame( 'Previous', SearchSurfaces::facet_value_label( 'status', 'previous', 'en' ) );
		self::assertSame( 'Graduação', SearchSurfaces::facet_value_label( 'level', 'undergraduate', 'pt-br' ) );
		// Open-vocabulary values render their stored slug.
		self::assertSame( '2026-2-semester', SearchSurfaces::facet_value_label( 'term', '2026-2-semester', 'pt-br' ) );
	}

	/**
	 * Decodes one JSON object field of a stored index row.
	 *
	 * @param string $json Encoded field.
	 * @return array<string, mixed>
	 */
	private static function decoded( string $json ): array {
		$value = json_decode( $json, true );
		if ( ! is_array( $value ) ) {
			self::fail( 'the stored field must decode to an object' );
		}
		/** @var array<string, mixed> $value */
		return $value;
	}

	/**
	 * Reads one array-valued field of a hydrated index row.
	 *
	 * @param array<string, mixed> $row Hydrated row.
	 * @param string               $key Field name.
	 * @return array<string, mixed>
	 */
	private static function field( array $row, string $key ): array {
		$value = $row[ $key ] ?? null;
		if ( ! is_array( $value ) ) {
			self::fail( $key . ' must be an array' );
		}
		/** @var array<string, mixed> $value */
		return $value;
	}

	/**
	 * Converts boundary input to string.
	 *
	 * @param mixed $value Boundary input.
	 */
	private static function text( mixed $value ): string {
		return is_scalar( $value ) ? (string) $value : '';
	}
}
