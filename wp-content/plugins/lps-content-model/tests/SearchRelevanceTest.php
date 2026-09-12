<?php
/**
 * Representative-query placement and search latency budgets.
 *
 * @package LPS\ContentModel\Tests
 */

declare(strict_types=1);

namespace LPS\ContentModel\Tests;

use LPS\ContentModel\SearchIndex;
use LPS\ContentModel\SearchPolicy;
use PHPUnit\Framework\TestCase;
use RuntimeException;

require_once dirname( __DIR__ ) . '/includes/class-searchindex.php';

/** Placement and latency contracts for the locale search index. */
final class SearchRelevanceTest extends TestCase {
	/**
	 * Representative queries place the expected record in the top three.
	 */
	public function test_representative_queries_place_the_expected_record_in_the_top_three(): void {
		$fixture = self::fixture();
		$records = self::indexed( $fixture['records'] );
		$corpus  = array_merge( $records, self::distractors( $fixture['queries'] ) );

		$hits   = 0;
		$missed = array();
		foreach ( $fixture['queries'] as $case ) {
			$query    = self::text( $case['query'] ?? '' );
			$result   = SearchPolicy::search_records( $corpus, $query, self::text( $case['locale'] ?? '' ), array(), 1, 20 );
			$top      = array_map( static fn( mixed $value ): string => is_scalar( $value ) ? (string) $value : '', array_slice( array_column( $result['items'], 'post_id' ), 0, 3 ) );
			$expected = self::text( $case['expected_post_id'] ?? '' );
			if ( in_array( $expected, $top, true ) ) {
				++$hits;
				continue;
			}
			$missed[] = $query . ' => ' . implode( ',', $top );
		}

		$placement = $hits / count( $fixture['queries'] );
		self::assertGreaterThanOrEqual( 0.95, $placement, 'Top-three placement fell below 95%: ' . implode( ' | ', $missed ) );
	}

	/**
	 * Representative queries never return records of another locale.
	 */
	public function test_representative_queries_never_return_records_of_another_locale(): void {
		$fixture = self::fixture();
		$records = self::indexed( $fixture['records'] );

		foreach ( $fixture['queries'] as $case ) {
			$result = SearchPolicy::search_records( $records, self::text( $case['query'] ?? '' ), 'pt-br', array(), 1, 100 );
			foreach ( $result['items'] as $item ) {
				self::assertSame( 'pt-br', $item['locale'] );
				self::assertLessThan( 9000, $item['post_id'], 'An English row leaked into a Portuguese result set.' );
			}
		}
	}

	/**
	 * The p95 search latency stays under 500 ms on a ten-thousand record index.
	 */
	public function test_p95_search_latency_stays_under_500_ms_on_a_ten_thousand_record_index(): void {
		$records = self::generated_corpus( 10000 );
		$queries = array(
			'instrumentacao',
			'processamento de sinais',
			'10.1000/lps.7777',
			'registro 4242',
			'confiabilidade',
			'monitoramento industrial',
			'sinais nucleares',
			'deteccao',
			'0000-0002-1825-0097',
			'analise espectral',
		);

		$samples = array();
		foreach ( $queries as $query ) {
			foreach ( array( 1, 2 ) as $page ) {
				$started   = hrtime( true );
				$result    = SearchPolicy::search_records( $records, $query, 'pt-br', array( 'year' => array( '2024' ) ), $page, 20 );
				$samples[] = ( hrtime( true ) - $started ) / 1000000;
				self::assertGreaterThanOrEqual( 0, $result['total'] );
			}
		}

		sort( $samples );
		$index = (int) ceil( 0.95 * count( $samples ) ) - 1;
		$p95   = $samples[ $index ];
		self::assertLessThan( 500.0, $p95, sprintf( 'p95 search latency was %.1f ms over %d samples.', $p95, count( $samples ) ) );
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
	 * Loads the representative-query fixture.
	 *
	 * @return array{records: array<int, array<string, mixed>>, queries: array<int, array<string, mixed>>}
	 */
	private static function fixture(): array {
		$path = __DIR__ . '/fixtures/todo18-representative-queries.json';
		$raw  = file_get_contents( $path );
		if ( false === $raw ) {
			throw new RuntimeException( 'Representative-query fixture is unreadable.' );
		}
		$decoded = json_decode( $raw, true, 512, JSON_THROW_ON_ERROR );
		if ( ! is_array( $decoded ) || ! is_array( $decoded['records'] ?? null ) || ! is_array( $decoded['queries'] ?? null ) ) {
			throw new RuntimeException( 'Representative-query fixture is malformed.' );
		}
		/**
		 * Decoded fixture.
		 *
		 * @var array{records: array<int, array<string, mixed>>, queries: array<int, array<string, mixed>>} $decoded
		 */
		return $decoded;
	}

	/**
	 * Converts portable fixture records into scored index rows.
	 *
	 * @param array<int, array<string, mixed>> $records Portable records.
	 * @return array<int, array<string, mixed>>
	 */
	private static function indexed( array $records ): array {
		$rows = array();
		foreach ( $records as $record ) {
			$rows[] = SearchIndex::hydrate( SearchIndex::build_row( $record ) );
		}
		return $rows;
	}

	/**
	 * Builds body-only distractors that repeat every expected query term.
	 *
	 * A distractor mentions the searched term only in its low-weight body, so
	 * the expected record can reach the top three only through real weighting.
	 *
	 * @param array<int, array<string, mixed>> $queries Representative queries.
	 * @return array<int, array<string, mixed>>
	 */
	private static function distractors( array $queries ): array {
		$rows = array();
		$id   = 0;
		foreach ( $queries as $case ) {
			$query = is_string( $case['query'] ?? null ) ? $case['query'] : '';
			for ( $copy = 0; $copy < 40; ++$copy ) {
				++$id;
				$rows[] = SearchIndex::hydrate(
					SearchIndex::build_row(
						array(
							'post_id'      => $id,
							'locale'       => 'pt-br',
							'post_type'    => 'lps_news',
							'title'        => 'Boletim ' . $id,
							'summary'      => 'Resumo do boletim ' . $id,
							'body'         => 'Menção incidental a ' . $query . ' no corpo do boletim ' . $id . '.',
							'taxonomy'     => array(),
							'facets'       => array(),
							'url'          => '/pt-br/boletim/' . $id . '/',
							'published_at' => '2025-01-01 00:00:00',
						)
					)
				);
			}
		}
		return $rows;
	}

	/**
	 * Builds a deterministic ten-thousand row index.
	 *
	 * @param int $size Row count.
	 * @return array<int, array<string, mixed>>
	 */
	private static function generated_corpus( int $size ): array {
		$records = array();
		for ( $id = 1; $id <= $size; ++$id ) {
			$records[] = array(
				'post_id'   => $id,
				'locale'    => 0 === $id % 2 ? 'pt-br' : 'en',
				'post_type' => 'lps_publication',
				'exact'     => array( '10.1000/lps.' . $id, '0000-0002-1825-0097' ),
				'high'      => 'registro ' . $id . ' instrumentacao nuclear',
				'medium'    => 'processamento de sinais monitoramento industrial confiabilidade analise espectral',
				'low'       => 'corpo do registro ' . $id . ' com deteccao de falhas e sinais nucleares',
				'facets'    => array( 'year' => array( (string) ( 2020 + ( $id % 6 ) ) ) ),
			);
		}
		return $records;
	}
}
