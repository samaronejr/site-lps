<?php
/**
 * Locale search, relevance, facet, and pagination contracts.
 *
 * @package LPS\ContentModel\Tests
 */

declare(strict_types=1);

namespace LPS\ContentModel\Tests;

use LPS\ContentModel\SearchPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/includes/class-searchpolicy.php';

final class SearchContractsTest extends TestCase {
	/** @return array<string, array{string, string}> */
	public static function normalization_cases(): array {
		return array(
			'accent and case' => array( '  José Álvarez  ', 'jose alvarez' ),
			'identifier'      => array( 'HTTPS://DOI.ORG/10.1000/LPS.Test', 'https://doi.org/10.1000/lps.test' ),
			'hostile markup'  => array( '<script>alert(1)</script> SÍNAL', 'alert 1 sinal' ),
		);
	}

	#[DataProvider( 'normalization_cases' )]
	public function test_normalizes_accents_case_spacing_and_markup( string $input, string $expected ): void {
		self::assertSame( $expected, SearchPolicy::normalize( $input ) );
	}

	public function test_query_boundary_reports_short_long_and_malformed_values(): void {
		self::assertSame( 'too_short', SearchPolicy::query_error( 'a' ) );
		self::assertSame( 'too_long', SearchPolicy::query_error( str_repeat( 'a', 101 ) ) );
		self::assertSame( 'malformed', SearchPolicy::query_error( "ok\0bad" ) );
		self::assertNull( SearchPolicy::query_error( 'Óleo e gás' ) );
	}

	public function test_weighting_locale_isolation_facets_and_stable_pagination(): void {
		$records = array(
			self::record( 7, 'pt-br', 'lps_project', 'doi elsewhere', 'conceito', 'body', array( 'status' => array( 'active' ) ), array( '10.1000/test' ) ),
			self::record( 2, 'pt-br', 'lps_publication', '10.1000/test', 'summary', 'body', array( 'year' => array( '2025' ), 'type' => array( 'article' ) ), array( '10.1000/test' ) ),
			self::record( 3, 'en', 'lps_publication', '10.1000/test', 'summary', 'body', array( 'year' => array( '2025' ), 'type' => array( 'article' ) ), array( '10.1000/test' ) ),
			self::record( 5, 'pt-br', 'lps_publication', 'another', '10.1000/test in summary', 'body', array( 'year' => array( '2024' ), 'type' => array( 'paper' ) ) ),
		);

		$result = SearchPolicy::search_records( $records, '10.1000/TEST', 'pt-br', array( 'year' => array( '2025' ) ), 1, 20 );
		self::assertSame( array( 2 ), array_column( $result['items'], 'post_id' ) );
		self::assertSame( 1, $result['total'] );

		$many = array();
		for ( $id = 1; $id <= 45; ++$id ) {
			$many[] = self::record( $id, 'pt-br', 'lps_news', 'Sinal ' . $id, 'conceito sinal', '', array( 'year' => array( '2025' ) ) );
		}
		$pages = array();
		for ( $page = 1; $page <= 3; ++$page ) {
			$pages = array_merge( $pages, array_column( SearchPolicy::search_records( $many, 'sinal', 'pt-br', array(), $page, 20 )['items'], 'post_id' ) );
		}
		self::assertCount( 45, $pages );
		self::assertCount( 45, array_unique( array_map( static fn( mixed $value ): string => is_scalar( $value ) ? (string) $value : '', $pages ) ) );
	}

	public function test_and_across_facets_or_within_one_facet(): void {
		$records = array(
			self::record( 1, 'pt-br', 'lps_project', 'A', 'sinal', '', array( 'status' => array( 'active' ), 'area' => array( 'signal-processing' ) ) ),
			self::record( 2, 'pt-br', 'lps_project', 'B', 'sinal', '', array( 'status' => array( 'completed' ), 'area' => array( 'signal-processing' ) ) ),
			self::record( 3, 'pt-br', 'lps_project', 'C', 'sinal', '', array( 'status' => array( 'active' ), 'area' => array( 'software-engineering' ) ) ),
		);
		$result = SearchPolicy::search_records( $records, 'sinal', 'pt-br', array( 'status' => array( 'active', 'completed' ), 'area' => array( 'signal-processing' ) ), 1, 20 );
		self::assertSame( array( 1, 2 ), array_column( $result['items'], 'post_id' ) );
	}

	public function test_defines_every_approved_listing_facet_and_rejects_unknown_values(): void {
		self::assertSame( array( 'year', 'type', 'person', 'project', 'area' ), array_keys( SearchPolicy::facet_definitions( 'lps_publication' ) ) );
		self::assertSame( array( 'status', 'area', 'domain' ), array_keys( SearchPolicy::facet_definitions( 'lps_project' ) ) );
		self::assertSame( array( 'role', 'status', 'area' ), array_keys( SearchPolicy::facet_definitions( 'lps_person' ) ) );
		self::assertSame( array( 'category', 'year' ), array_keys( SearchPolicy::facet_definitions( 'lps_news' ) ) );
		self::assertSame( array( 'state', 'type', 'audience' ), array_keys( SearchPolicy::facet_definitions( 'lps_opportunity' ) ) );
		self::assertSame( array( 'date', 'type' ), array_keys( SearchPolicy::facet_definitions( 'lps_event' ) ) );
		self::assertSame( array( 'area' => array( 'signal-processing' ) ), SearchPolicy::sanitize_facets( array( 'area' => array( 'signal-processing', '<script>' ), 'unknown' => array( 'x' ) ), array( 'area' => array() ) ) );
	}

	public function test_ten_thousand_record_reference_query_stays_under_budget(): void {
		$records = array();
		for ( $id = 1; $id <= 10000; ++$id ) {
			$records[] = self::record( $id, 0 === $id % 2 ? 'pt-br' : 'en', 'lps_publication', 'Publicação ' . $id, 'processamento de sinais e instrumentação', 'corpo', array( 'year' => array( (string) ( 2020 + $id % 6 ) ) ) );
		}
		$started = hrtime( true );
		$result  = SearchPolicy::search_records( $records, 'instrumentacao', 'pt-br', array( 'year' => array( '2024' ) ), 2, 20 );
		$elapsed = ( hrtime( true ) - $started ) / 1000000;
		self::assertCount( 20, $result['items'] );
		self::assertLessThan( 500.0, $elapsed, '10,000-record reference query exceeded 500 ms.' );
	}

	/**
	 * @param array<string, array<int, string>> $facets Facet values.
	 * @param array<int, string>                $exact  Exact identifiers.
	 * @return array<string, mixed>
	 */
	private static function record( int $id, string $locale, string $type, string $title, string $summary, string $body, array $facets, array $exact = array() ): array {
		return array( 'post_id' => $id, 'locale' => $locale, 'post_type' => $type, 'title' => $title, 'high' => $title, 'medium' => $summary, 'low' => $body, 'exact' => $exact, 'facets' => $facets, 'published_at' => sprintf( '2025-01-%02d 00:00:00', ( $id % 28 ) + 1 ) );
	}
}
