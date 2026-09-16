<?php
/**
 * Research-first homepage contract tests.
 *
 * @package LPS\Theme\Tests
 */

declare(strict_types=1);

namespace LPS\Theme\Tests;

use LPS\Theme\Homepage;
use PHPUnit\Framework\Attributes\DataProvider;

require_once dirname( __DIR__ ) . '/includes/class-homepage.php';

/** Research-first homepage contract tests. */
final class HomepageTest extends \PHPUnit\Framework\TestCase {
	/**
	 * Verifies that three journeys are locale specific and unique.
	 */
	public function test_three_journeys_are_locale_specific_and_unique(): void {
		$portuguese = Homepage::journeys( 'pt-br' );
		$english    = Homepage::journeys( 'en' );

		self::assertCount( 3, $portuguese );
		self::assertCount( 3, $english );
		self::assertSame( array( 'opportunities', 'collaborate', 'infrastructure' ), array_column( $english, 'page_key' ) );
		self::assertSame( array( 'Junte-se ao LPS', 'Colabore', 'Seja parceiro' ), array_column( $portuguese, 'label' ) );
		self::assertSame( array( 'Join LPS', 'Collaborate', 'Partner' ), array_column( $english, 'label' ) );
	}

	/**
	 * Provides feature states.
	 *
	 * @return array<string, array{array<string, mixed>, string, bool}>
	 */
	public static function feature_states(): array {
		return array(
			'published reviewed current feature' => array(
				array(
					'status'         => 'publish',
					'state'          => 'published',
					'locale'         => 'en',
					'source_id'      => 'lps:project:1',
					'feature_order'  => 1,
					'featured_until' => '2026-12-31',
					'stale'          => false,
				),
				'2026-09-03',
				true,
			),
			'unpublished'                        => array(
				array(
					'status'         => 'draft',
					'state'          => 'draft',
					'locale'         => 'en',
					'source_id'      => 'lps:project:1',
					'feature_order'  => 1,
					'featured_until' => '2026-12-31',
					'stale'          => false,
				),
				'2026-09-03',
				false,
			),
			'stale English'                      => array(
				array(
					'status'         => 'publish',
					'state'          => 'published',
					'locale'         => 'en',
					'source_id'      => 'lps:project:1',
					'feature_order'  => 1,
					'featured_until' => '2026-12-31',
					'stale'          => true,
				),
				'2026-09-03',
				false,
			),
			'expired'                            => array(
				array(
					'status'         => 'publish',
					'state'          => 'published',
					'locale'         => 'pt-br',
					'source_id'      => 'lps:project:1',
					'feature_order'  => 1,
					'featured_until' => '2026-09-02',
					'stale'          => false,
				),
				'2026-09-03',
				false,
			),
			'missing source'                     => array(
				array(
					'status'         => 'publish',
					'state'          => 'published',
					'locale'         => 'pt-br',
					'source_id'      => '',
					'feature_order'  => 1,
					'featured_until' => '2026-12-31',
					'stale'          => false,
				),
				'2026-09-03',
				false,
			),
		);
	}

	/**
	 * Verifies that feature eligibility rejects unpublishable or stale records.
	 *
	 * @param array<string, mixed> $record Candidate record.
	 * @param string               $today Evaluation date.
	 * @param bool                 $expected Expected result.
	 */
	#[DataProvider( 'feature_states' )]
	public function test_feature_eligibility_rejects_unpublishable_or_stale_records( array $record, string $today, bool $expected ): void {
		$raw_locale = $record['locale'] ?? 'en';
		$locale     = is_string( $raw_locale ) ? $raw_locale : 'en';
		self::assertSame( $expected, Homepage::eligible_feature( $record, $locale, $today ) );
	}

	/**
	 * Verifies that feature selection is explicit bounded and ordered.
	 */
	public function test_feature_selection_is_explicit_bounded_and_ordered(): void {
		$records = array(
			array(
				'status'         => 'publish',
				'state'          => 'published',
				'locale'         => 'en',
				'source_id'      => 'lps:project:3',
				'feature_order'  => 3,
				'featured_until' => '',
				'stale'          => false,
			),
			array(
				'status'         => 'publish',
				'state'          => 'published',
				'locale'         => 'en',
				'source_id'      => 'lps:project:1',
				'feature_order'  => 1,
				'featured_until' => '',
				'stale'          => false,
			),
			array(
				'status'         => 'publish',
				'state'          => 'published',
				'locale'         => 'en',
				'source_id'      => 'lps:project:2',
				'feature_order'  => 2,
				'featured_until' => '',
				'stale'          => false,
			),
			array(
				'status'         => 'publish',
				'state'          => 'published',
				'locale'         => 'en',
				'source_id'      => 'lps:project:4',
				'feature_order'  => 4,
				'featured_until' => '',
				'stale'          => false,
			),
		);

		self::assertSame(
			array( 'lps:project:1', 'lps:project:2', 'lps:project:3' ),
			array_column( Homepage::select_features( $records, 'en', '2026-09-03' ), 'source_id' )
		);
	}

	/**
	 * Verifies that image fallback is accessible and contains no placeholder image.
	 */
	public function test_image_fallback_is_accessible_and_contains_no_placeholder_image(): void {
		$markup = Homepage::feature_media_markup(
			array(
				'title' => 'Projeto validado',
				'image' => null,
			),
			'pt-br'
		);

		self::assertStringContainsString( 'role="img"', $markup );
		self::assertStringContainsString( 'Imagem não publicada', $markup );
		self::assertStringNotContainsString( '<img', $markup );
		self::assertStringNotContainsString( 'placeholder', strtolower( $markup ) );
	}

	/**
	 * Creates an explicit, current, reviewed CMS selection.
	 *
	 * @param string $section Locked section key.
	 * @param string $locale Record locale.
	 * @return array<string, mixed>
	 */
	private function record( string $section = 'projects', string $locale = 'en' ): array {
		return array(
			'section'       => $section,
			'title'         => 'CMS <record>',
			'summary'       => 'CMS & summary',
			'url'           => '/' . $locale . '/projects/record/',
			'source_id'     => 'source:record',
			'record_id'     => 'lps:project:record',
			'status'        => 'publish',
			'state'         => 'published',
			'locale'        => $locale,
			'stale'         => false,
			'reviewed'      => true,
			'review_date'   => '2027-01-01',
			'feature_order' => 1,
		);
	}

	/** Unselected records cannot become implicit features. */
	public function test_unselected_record_is_not_implicitly_featured(): void {
		$record = $this->record();
		unset( $record['feature_order'] );
		self::assertSame( array(), Homepage::select_features( array( $record ), 'en', '2026-09-06' ) );
	}

	/** CMS values are escaped and carry provenance. */
	public function test_sections_escape_cms_copy_and_attach_provenance(): void {
		$html = Homepage::section_markup( 'projects', 'en', array( $this->record() ), '2026-09-06' );
		self::assertStringContainsString( 'data-home-section="projects"', $html );
		self::assertStringContainsString( 'data-source-id="source:record"', $html );
		self::assertStringContainsString( 'CMS &lt;record&gt;', $html );
		self::assertStringContainsString( 'CMS &amp; summary', $html );
		self::assertSame( 1, substr_count( $html, '<h2' ) );
		self::assertSame( 1, substr_count( $html, '<h3' ) );
		self::assertStringNotContainsString( '<img', $html );
	}

	/** Every publication boundary fails closed without hiding a valid neighbor. */
	public function test_unsafe_or_ineligible_content_is_not_rendered(): void {
		foreach ( array( 'pt-br', 'en' ) as $locale ) {
			foreach ( array(
				array( 'status' => 'draft' ),
				array( 'state' => 'archived' ),
				array( 'stale' => true ),
				array( 'locale' => 'en' === $locale ? 'pt-br' : 'en' ),
				array( 'reviewed' => false ),
				array( 'review_date' => '2026-09-05' ),
				array( 'source_id' => '' ),
				array( 'featured_until' => '2026-09-05' ),
				array( 'featured_from' => '2026-09-07' ),
				array( 'url' => 'javascript:alert(1)' ),
			) as $change ) {
				$rejected = array_replace(
					$this->record( 'projects', $locale ),
					array(
						'title'   => 'REJECTED_RECORD',
						'summary' => 'REJECTED_SUMMARY',
					),
					$change
				);
				$valid    = array_replace( $this->record( 'projects', $locale ), array( 'source_id' => 'source:valid' ) );
				$html     = Homepage::section_markup( 'projects', $locale, array( $rejected, $valid ), '2026-09-06' );
				self::assertStringNotContainsString( 'REJECTED_RECORD', $html );
				self::assertStringNotContainsString( 'REJECTED_SUMMARY', $html );
				self::assertStringNotContainsString( 'source:record', $html );
				self::assertStringContainsString( 'data-source-id="source:valid"', $html );
				self::assertSame( 1, substr_count( $html, '<article' ) );
				self::assertSame(
					Homepage::section_markup( 'projects', $locale, array(), '2026-09-06' ),
					Homepage::section_markup( 'projects', $locale, array( $rejected ), '2026-09-06' )
				);
			}
		}
	}

	/** Three actual anchors use CMS task labels, source IDs and the intended routes. */
	public function test_journeys_use_only_reviewed_destination_ctas_in_both_locales(): void {
		$routes = array(
			'pt-br' => array(
				'opportunities'  => '/pt-br/oportunidades/',
				'collaborate'    => '/pt-br/colabore/',
				'infrastructure' => '/pt-br/infraestrutura/',
			),
			'en'    => array(
				'opportunities'  => '/en/opportunities/',
				'collaborate'    => '/en/collaborate/',
				'infrastructure' => '/en/infrastructure/',
			),
		);
		foreach ( $routes as $locale => $destinations ) {
			$records = array();
			foreach ( $destinations as $key => $url ) {
				$records[] = array_replace(
					$this->record( 'journeys', $locale ),
					array(
						'page_key'  => $key,
						'url'       => $url,
						'cta'       => 'CMS_TASK_' . $key,
						'source_id' => 'source:' . $key,
					)
				);
			}
			$html = Homepage::section_markup( 'journeys', $locale, $records, '2026-09-06' );
			self::assertSame( 3, substr_count( $html, '<a ' ) );
			self::assertSame( 3, substr_count( $html, 'data-home-journey=' ) );
			foreach ( $destinations as $key => $url ) {
				self::assertStringContainsString( 'data-home-journey="' . $key . '" data-source-id="source:' . $key . '" href="' . $url . '">CMS_TASK_' . $key . '</a>', $html );
			}
		}
	}

	/** Missing destinations have no invented link in either locale. */
	public function test_missing_journey_is_not_replaced_by_an_unreviewed_link(): void {
		foreach ( array( 'pt-br', 'en' ) as $locale ) {
			$html = Homepage::section_markup( 'journeys', $locale, array(), '2026-09-06' );
			self::assertStringNotContainsString( '<a ', $html );
			self::assertSame( 3, substr_count( $html, 'aria-disabled="true"' ) );
			self::assertStringContainsString( 'aria-labelledby="lps-home-journeys"', $html );
		}
	}

	/** Rendered features, not just the selector, are ordered and bounded to three. */
	public function test_project_limit_is_three_and_mission_has_one_h1(): void {
		foreach ( array( 'pt-br', 'en' ) as $locale ) {
			$records = array();
			foreach ( array( 4, 2, 1, 5, 3 ) as $order ) {
				$records[] = array_replace(
					$this->record( 'projects', $locale ),
					array(
						'feature_order' => $order,
						'source_id'     => 'source:' . $order,
					)
				);
			}
			$html = Homepage::section_markup( 'projects', $locale, $records, '2026-09-06' );
			self::assertSame( 3, substr_count( $html, '<article' ) );
			preg_match_all( '/data-source-id="([^"]+)"/', $html, $sources );
			self::assertSame( array( 'source:1', 'source:2', 'source:3' ), $sources[1] );
			$html = Homepage::section_markup( 'mission', $locale, array( $this->record( 'mission', $locale ) ), '2026-09-06' );
			self::assertSame( 1, substr_count( $html, '<h1' ) );
			self::assertStringNotContainsString( '<h2', $html );
			self::assertStringContainsString( '<section data-home-section="mission" data-source-id="source:record"', $html );
		}
	}

	/** Empty sections remain named and cannot fall back to foreign CMS copy. */
	public function test_empty_section_is_accessible_without_mixed_language(): void {
		foreach ( array( 'pt-br', 'en' ) as $locale ) {
			$foreign_locale = 'en' === $locale ? 'pt-br' : 'en';
			$foreign        = array_replace(
				$this->record( 'projects', $foreign_locale ),
				array(
					'title'   => 'FOREIGN_TITLE',
					'summary' => 'FOREIGN_SUMMARY',
				)
			);
			$html           = Homepage::section_markup( 'projects', $locale, array( $foreign ), '2026-09-06' );
			self::assertSame( Homepage::section_markup( 'projects', $locale, array(), '2026-09-06' ), $html );
			self::assertStringContainsString( 'aria-labelledby="lps-home-projects"', $html );
			self::assertMatchesRegularExpression( '/<h2 id="lps-home-projects">[^<]+<\/h2>/', $html );
			self::assertMatchesRegularExpression( '/<p data-home-empty="projects">[^<]+<\/p>/', $html );
			self::assertStringNotContainsString( 'FOREIGN_', $html );
			self::assertStringNotContainsString( '<article', $html );
			self::assertStringNotContainsString( '<a ', $html );
			self::assertStringNotContainsString( '<img', $html );
		}
	}
}
