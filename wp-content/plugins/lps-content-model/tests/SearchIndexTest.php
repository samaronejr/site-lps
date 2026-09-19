<?php
/**
 * Search index storage, transaction, and isolation contracts.
 *
 * @package LPS\ContentModel\Tests
 */

declare(strict_types=1);

namespace LPS\ContentModel\Tests;

use LPS\ContentModel\SearchIndex;
use LPS\ContentModel\SearchStorage;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/includes/class-searchindex.php';
require_once dirname( __DIR__ ) . '/includes/class-searchstorage.php';

/** In-memory index storage that records every transactional call. */
final class RecordingSearchStorage implements SearchStorage {
	/**
	 * Stored index rows keyed by record database ID.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	public array $rows = array();

	/**
	 * Ordered transaction and write calls.
	 *
	 * @var array<int, string>
	 */
	public array $calls = array();

	/**
	 * Whether the next insert must fail.
	 *
	 * @var bool
	 */
	public bool $fail_insert = false;

	/**
	 * Whether the next delete must fail.
	 *
	 * @var bool
	 */
	public bool $fail_delete = false;

	/** Opens a transaction and reports whether it started. */
	public function begin(): bool {
		$this->calls[] = 'begin';
		return true;
	}

	/** Commits the open transaction. */
	public function commit(): void {
		$this->calls[] = 'commit';
	}

	/** Reverts the open transaction. */
	public function rollback(): void {
		$this->calls[] = 'rollback';
	}

	/**
	 * Removes every indexed row of one record.
	 *
	 * @param int $post_id Record database ID.
	 */
	public function delete_record( int $post_id ): bool {
		$this->calls[] = 'delete:' . $post_id;
		if ( $this->fail_delete ) {
			return false;
		}
		unset( $this->rows[ $post_id ] );
		return true;
	}

	/**
	 * Writes one indexed row.
	 *
	 * @param array<string, mixed> $row Indexed row.
	 */
	public function insert_record( array $row ): bool {
		$candidate     = $row['post_id'] ?? 0;
		$post_id       = is_numeric( $candidate ) ? (int) $candidate : 0;
		$this->calls[] = 'insert:' . $post_id;
		if ( $this->fail_insert ) {
			return false;
		}
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

/** Contract tests for the plugin-owned locale search index. */
final class SearchIndexTest extends TestCase {
	/**
	 * The index schema declares a locale-scoped, single-row-per-record table.
	 */
	public function test_schema_declares_a_locale_scoped_single_row_per_record_table(): void {
		$sql = SearchIndex::schema_sql( 'wp_', 'DEFAULT CHARACTER SET utf8mb4' );

		self::assertSame( 'wp_lps_search_index', SearchIndex::table_name( 'wp_' ) );
		self::assertStringStartsWith( 'CREATE TABLE wp_lps_search_index (', $sql );
		self::assertStringContainsString( 'UNIQUE KEY post_id (post_id)', $sql );
		self::assertStringContainsString( 'KEY locale_type (locale,post_type)', $sql );
		self::assertStringEndsWith( 'DEFAULT CHARACTER SET utf8mb4;', $sql );
	}

	/**
	 * Provides indexability decisions for record states.
	 *
	 * @return array<string, array{string, string, string, bool}>
	 */
	public static function indexability_cases(): array {
		return array(
			'published pt-br publication' => array( 'publish', 'pt-br', 'lps_publication', true ),
			'published en project'        => array( 'publish', 'en', 'lps_project', true ),
			'draft'                       => array( 'draft', 'pt-br', 'lps_publication', false ),
			'pending'                     => array( 'pending', 'pt-br', 'lps_publication', false ),
			'private'                     => array( 'private', 'pt-br', 'lps_publication', false ),
			'archived'                    => array( 'lps_archived', 'pt-br', 'lps_publication', false ),
			'absent locale'               => array( 'publish', 'fr', 'lps_publication', false ),
			'missing locale'              => array( 'publish', '', 'lps_publication', false ),
			'unindexed type'              => array( 'publish', 'pt-br', 'lps_organization', false ),
		);
	}

	/**
	 * Only published, supported-locale, indexed-type records enter the index.
	 *
	 * @param string $status    Data-provider value.
	 * @param string $locale    Data-provider value.
	 * @param string $post_type Data-provider value.
	 * @param bool   $expected  Data-provider value.
	 */
	#[DataProvider( 'indexability_cases' )]
	public function test_only_published_supported_locale_records_are_indexable( string $status, string $locale, string $post_type, bool $expected ): void {
		self::assertSame( $expected, SearchIndex::is_indexable( $status, $locale, $post_type ) );
	}

	/**
	 * Private metadata never reaches an index row.
	 */
	public function test_private_metadata_never_reaches_an_index_row(): void {
		$record = self::record( 41, 'pt-br', 'lps_person', 'Ana Cláudia Sá' );
		foreach ( SearchIndex::private_fields() as $field ) {
			$record[ $field ] = 'segredo-' . $field;
		}
		$record['_lps_private_notes'] = 'telefone 21 99999-0000 e endereço residencial';

		$row     = SearchIndex::build_row( $record );
		$encoded = (string) wp_json_encode_compat( $row );

		self::assertStringNotContainsString( 'segredo-', $encoded );
		self::assertStringNotContainsString( '99999-0000', $encoded );
		foreach ( SearchIndex::private_fields() as $field ) {
			self::assertArrayNotHasKey( $field, $row );
		}
		self::assertSame( array( 'post_id', 'locale', 'post_type', 'exact_terms', 'high_terms', 'medium_terms', 'low_terms', 'facets', 'lifecycle', 'title', 'summary', 'url', 'published_at' ), array_keys( $row ) );
	}

	/**
	 * Index rows carry normalized weighted terms.
	 */
	public function test_index_rows_carry_normalized_weighted_terms(): void {
		$record            = self::record( 42, 'pt-br', 'lps_publication', 'Detecção de Falhas' );
		$record['doi']     = '10.1000/LPS.Falhas';
		$record['summary'] = 'Processamento de SINAIS';
		$record['body']    = '<p>Corpo Técnico</p>';

		$row = SearchIndex::build_row( $record );

		self::assertStringContainsString( '10.1000/lps.falhas', self::text( $row['exact_terms'] ) );
		self::assertStringContainsString( 'deteccao de falhas', self::text( $row['high_terms'] ) );
		self::assertSame( 'processamento de sinais', $row['medium_terms'] );
		self::assertSame( 'corpo tecnico', $row['low_terms'] );
	}

	/**
	 * Publishing replaces the indexed row inside one transaction.
	 */
	public function test_publishing_replaces_the_indexed_row_inside_one_transaction(): void {
		$storage = new RecordingSearchStorage();
		$record  = self::record( 51, 'pt-br', 'lps_project', 'Monitoramento de Dutos' );

		self::assertTrue( SearchIndex::synchronize( $storage, $record ) );

		self::assertSame( array( 'begin', 'delete:51', 'insert:51', 'commit' ), $storage->calls );
		self::assertArrayHasKey( 51, $storage->rows );
		self::assertSame( 'pt-br', $storage->rows[51]['locale'] );
	}

	/**
	 * Archiving removes the indexed row inside one transaction.
	 */
	public function test_archiving_removes_the_indexed_row_inside_one_transaction(): void {
		$storage = new RecordingSearchStorage();
		$record  = self::record( 52, 'pt-br', 'lps_project', 'Monitoramento de Dutos' );
		SearchIndex::synchronize( $storage, $record );
		$storage->calls = array();

		$record['status'] = 'lps_archived';
		self::assertTrue( SearchIndex::synchronize( $storage, $record ) );

		self::assertSame( array( 'begin', 'delete:52', 'commit' ), $storage->calls );
		self::assertSame( array(), $storage->rows );
		self::assertSame( array(), $storage->rows_for( 'pt-br', '' ) );
	}

	/**
	 * A failed write rolls back and never commits a partial row.
	 */
	public function test_a_failed_write_rolls_back_and_never_commits_a_partial_row(): void {
		$storage              = new RecordingSearchStorage();
		$storage->fail_insert = true;

		self::assertFalse( SearchIndex::synchronize( $storage, self::record( 53, 'pt-br', 'lps_news', 'Nota' ) ) );

		self::assertSame( array( 'begin', 'delete:53', 'insert:53', 'rollback' ), $storage->calls );
		self::assertNotContains( 'commit', $storage->calls );
		self::assertSame( array(), $storage->rows );
	}

	/**
	 * A failed delete rolls back before any insert runs.
	 */
	public function test_a_failed_delete_rolls_back_before_any_insert_runs(): void {
		$storage              = new RecordingSearchStorage();
		$storage->fail_delete = true;

		self::assertFalse( SearchIndex::synchronize( $storage, self::record( 54, 'pt-br', 'lps_news', 'Nota' ) ) );

		self::assertSame( array( 'begin', 'delete:54', 'rollback' ), $storage->calls );
	}

	/**
	 * Provides records the public surfaces refuse to publish.
	 *
	 * @return array<string, array{array<string, string>, bool}>
	 */
	public static function visibility_cases(): array {
		return array(
			'ordinary published person'   => array( array(), true ),
			'archived state'              => array( array( '_lps_state' => 'archived' ), false ),
			'unapproved in memoriam'      => array( array( '_lps_person_status' => 'in-memoriam' ), false ),
			'approved in memoriam'        => array(
				array(
					'_lps_person_status'        => 'in-memoriam',
					'_lps_in_memoriam_approved' => '1',
				),
				true,
			),
			'privacy not reviewed listed' => array( array( '_lps_privacy_reviewed' => '' ), true ),
		);
	}

	/**
	 * A record the public surfaces withhold never reaches the index.
	 *
	 * @param array<string, string> $flags    Data-provider value.
	 * @param bool                  $expected Data-provider value.
	 */
	#[DataProvider( 'visibility_cases' )]
	public function test_a_record_the_public_surfaces_withhold_never_reaches_the_index( array $flags, bool $expected ): void {
		$record = array_merge( self::record( 91, 'pt-br', 'lps_person', 'Pessoa de fixture' ), $flags );

		self::assertSame( $expected, SearchIndex::is_publicly_visible( $record ) );

		$storage = new RecordingSearchStorage();
		SearchIndex::synchronize( $storage, $record );
		self::assertSame( $expected, isset( $storage->rows[91] ) );
	}

	/**
	 * Searching one locale never returns rows of another locale.
	 */
	public function test_searching_one_locale_never_returns_rows_of_another_locale(): void {
		$storage = new RecordingSearchStorage();
		SearchIndex::synchronize( $storage, self::record( 61, 'pt-br', 'lps_publication', 'Instrumentação Nuclear' ) );
		SearchIndex::synchronize( $storage, self::record( 62, 'en', 'lps_publication', 'Instrumentação Nuclear' ) );

		$portuguese = SearchIndex::search( $storage, '', 'instrumentacao', 'pt-br', array() );
		$english    = SearchIndex::search( $storage, '', 'instrumentacao', 'en', array() );

		self::assertSame( array( 61 ), array_column( $portuguese['items'], 'post_id' ) );
		self::assertSame( array( 62 ), array_column( $english['items'], 'post_id' ) );
	}

	/**
	 * A record removed from the index disappears from results immediately.
	 */
	public function test_a_removed_record_disappears_from_results_immediately(): void {
		$storage = new RecordingSearchStorage();
		SearchIndex::synchronize( $storage, self::record( 71, 'pt-br', 'lps_news', 'Bancada de ensaios' ) );
		self::assertSame( 1, SearchIndex::search( $storage, '', 'bancada', 'pt-br', array() )['total'] );

		SearchIndex::remove( $storage, 71 );

		self::assertSame( 0, SearchIndex::search( $storage, '', 'bancada', 'pt-br', array() )['total'] );
	}

	/**
	 * Stored rows round-trip back into the scored search shape.
	 */
	public function test_stored_rows_round_trip_back_into_the_scored_search_shape(): void {
		$record            = self::record( 81, 'pt-br', 'lps_project', 'Plataforma Aberta' );
		$record['facets']  = array(
			'status' => array( 'active' ),
			'area'   => array( 'signal-processing' ),
		);
		$hydrated          = SearchIndex::hydrate( SearchIndex::build_row( $record ) );

		self::assertSame( 81, $hydrated['post_id'] );
		self::assertSame( 'pt-br', $hydrated['locale'] );
		self::assertSame(
			array(
				'status' => array( 'active' ),
				'area'   => array( 'signal-processing' ),
			),
			$hydrated['facets']
		);
		self::assertContains( 'plataforma aberta', (array) $hydrated['exact'] );
	}

	/**
	 * Converts boundary input to string.
	 *
	 * @param mixed $value Boundary input.
	 */
	private static function text( mixed $value ): string {
		return is_scalar( $value ) ? (string) $value : '';
	}

	/**
	 * Builds one portable record.
	 *
	 * @param int    $post_id   Record database ID.
	 * @param string $locale    Supported locale slug.
	 * @param string $post_type Record type.
	 * @param string $title     Record title.
	 * @return array<string, mixed>
	 */
	private static function record( int $post_id, string $locale, string $post_type, string $title ): array {
		return array(
			'post_id'       => $post_id,
			'status'        => 'publish',
			'locale'        => $locale,
			'post_type'     => $post_type,
			'title'         => $title,
			'acronym'       => '',
			'doi'           => '',
			'orcid'         => '',
			'summary'       => 'resumo público',
			'taxonomy'      => array(),
			'body'          => 'corpo público',
			'facets'        => array(),
			'url'           => '/pt-br/registro/',
			'published_at'  => '2025-03-01 00:00:00',

			// Unified visibility-decision inputs: a reviewed native record.
			'_lps_state'    => 'published',
			'_lps_origin'   => 'native',
			'_lps_record_id' => 'lps:' . str_replace( '_', '-', str_replace( 'lps_', '', $post_type ) ) . ':018f21ce-7d7a-7abc-8a2f-2d6937f89a11',
			'stale'         => false,
			'source_status' => 'publish',
			'source_state'  => 'published',
		);
	}
}

/**
 * Encodes one value as JSON without WordPress loaded.
 *
 * @param mixed $value Encodable value.
 */
function wp_json_encode_compat( mixed $value ): string {
	return (string) json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- assertion helper running without WordPress.
}
