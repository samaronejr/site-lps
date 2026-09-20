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
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/includes/class-homepage.php';
require_once dirname( __DIR__, 3 ) . '/plugins/lps-content-model/includes/class-policy.php';
require_once dirname( __DIR__, 3 ) . '/plugins/lps-content-model/includes/class-mediarenderer.php';
require_once dirname( __DIR__, 3 ) . '/plugins/lps-content-model/includes/class-mediausagepolicy.php';
require_once dirname( __DIR__, 3 ) . '/plugins/lps-content-model/includes/class-mediapolicy.php';

final class HomepageTest extends TestCase {
	public function test_three_journeys_are_locale_specific_and_unique(): void {
		$portuguese = Homepage::journeys( 'pt-br' );
		$english    = Homepage::journeys( 'en' );

		self::assertCount( 3, $portuguese );
		self::assertCount( 3, $english );
		self::assertSame( array( 'opportunities', 'collaborate', 'infrastructure' ), array_column( $english, 'page_key' ) );
		self::assertSame( array( 'Junte-se ao LPS', 'Colabore', 'Seja parceiro' ), array_column( $portuguese, 'label' ) );
		self::assertSame( array( 'Join LPS', 'Collaborate', 'Partner' ), array_column( $english, 'label' ) );
	}

	/** The two pinned first-viewport actions are locale-specific and stable. */
	public function test_primary_links_are_locale_specific(): void {
		$portuguese = Homepage::primary_links( 'pt-br' );
		$english    = Homepage::primary_links( 'en' );

		self::assertSame( array( 'research', 'teaching' ), array_column( $portuguese, 'key' ) );
		self::assertSame( array( 'Conheça a pesquisa', 'Disciplinas e materiais' ), array_column( $portuguese, 'label' ) );
		self::assertSame( array( '/pt-br/pesquisa/', '/pt-br/ensino/' ), array_column( $portuguese, 'url' ) );
		self::assertSame( array( '/en/research/', '/en/teaching/' ), array_column( $english, 'url' ) );
	}

	/**
	 * Returns a fully eligible native feature record for the data provider.
	 *
	 * @param array<string, mixed> $overrides Field overrides.
	 * @return array<string, mixed>
	 */
	private static function eligible_native( array $overrides = array() ): array {
		return array_replace(
			array(
				'status'             => 'publish',
				'state'              => 'published',
				'_lps_state'         => 'published',
				'locale'             => 'en',
				'source_id'          => 'lps:project:1',
				'post_type'          => 'lps_project',
				'_lps_origin'        => 'native',
				'_lps_record_id'     => 'lps:project:1',
				'_lps_owner_user_id' => 7,
				'_lps_review_date'   => '2027-01-01',
				'source_status'      => 'publish',
				'source_state'       => 'published',
				'feature_order'      => 1,
				'featured_until'     => '2026-12-31',
				'stale'              => false,
			),
			$overrides
		);
	}

	/** @return array<string, array{array<string, mixed>, string, bool}> */
	public static function feature_states(): array {
		return array(
			'published reviewed current feature' => array(
				self::eligible_native(),
				'2026-09-03',
				true,
			),
			'unpublished' => array(
				self::eligible_native( array( 'status' => 'draft', '_lps_state' => 'draft' ) ),
				'2026-09-03',
				false,
			),
			'stale English' => array(
				self::eligible_native( array( 'stale' => true ) ),
				'2026-09-03',
				false,
			),
			'expired' => array(
				self::eligible_native( array( 'locale' => 'pt-br', 'featured_until' => '2026-09-02' ) ),
				'2026-09-03',
				false,
			),
			'missing provenance' => array(
				self::eligible_native( array( 'locale' => 'pt-br', '_lps_record_id' => '' ) ),
				'2026-09-03',
				false,
			),
			'ambiguous origin' => array(
				self::eligible_native( array( '_lps_origin' => '', '_lps_record_id' => '' ) ),
				'2026-09-03',
				false,
			),
			'unreviewed import' => array(
				self::eligible_native(
					array(
						'_lps_origin'              => 'native',
						'_lps_import_source_id'    => 'record-001',
						'_lps_import_review_state' => 'candidate',
					)
				),
				'2026-09-03',
				false,
			),
			'reviewed import' => array(
				self::eligible_native(
					array(
						'_lps_origin'              => 'imported',
						'_lps_import_source_id'    => 'record-001',
						'_lps_import_review_state' => 'reviewed',
					)
				),
				'2026-09-03',
				true,
			),
		);
	}

	/** @param array<string, mixed> $record */
	#[DataProvider( 'feature_states' )]
	public function test_feature_eligibility_rejects_unpublishable_or_stale_records( array $record, string $today, bool $expected ): void {
		$raw_locale = $record['locale'] ?? 'en';
		$locale = is_string( $raw_locale ) ? $raw_locale : 'en';
		self::assertSame( $expected, Homepage::eligible_feature( $record, $locale, $today ) );
	}

	public function test_feature_selection_is_explicit_bounded_and_ordered(): void {
		$records = array(
			self::eligible_native( array( 'source_id' => 'lps:project:3', '_lps_record_id' => 'lps:project:3', 'feature_order' => 3, 'featured_until' => '' ) ),
			self::eligible_native( array( 'source_id' => 'lps:project:1', '_lps_record_id' => 'lps:project:1', 'feature_order' => 1, 'featured_until' => '' ) ),
			self::eligible_native( array( 'source_id' => 'lps:project:2', '_lps_record_id' => 'lps:project:2', 'feature_order' => 2, 'featured_until' => '' ) ),
			self::eligible_native( array( 'source_id' => 'lps:project:4', '_lps_record_id' => 'lps:project:4', 'feature_order' => 4, 'featured_until' => '' ) ),
		);

		self::assertSame(
			array( 'lps:project:1', 'lps:project:2', 'lps:project:3' ),
			array_column( Homepage::select_features( $records, 'en', '2026-09-03' ), 'source_id' )
		);
	}

	/** A record with no governed media degrades to text, never a broken box. */
	public function test_missing_media_degrades_to_text_without_placeholder(): void {
		$markup = Homepage::feature_media_markup( array( 'title' => 'Projeto validado', 'image' => null ), 'pt-br' );

		self::assertSame( '', $markup );
	}

	/**
	 * Returns one governed media pair that passes every rights and usage check.
	 *
	 * @return array{usage: array<string, mixed>, asset: array<string, mixed>}
	 */
	private function cleared_media(): array {
		return array(
			'usage' => array(
				'block'   => 'image',
				'alt'     => 'Conjunto de detectores na bancada do laboratório',
				'caption' => 'Bancada de instrumentação do LPS.',
				'context' => 'home-mission',
			),
			'asset' => array(
				'id'             => 41,
				'mime'           => 'image/jpeg',
				'url'            => '/uploads/detectors.jpg',
				'filename'       => 'detectors.jpg',
				'width'          => 2400,
				'height'         => 1600,
				'credit'         => 'LPS archive',
				'rights_holder'  => 'LPS',
				'license'        => 'authorized-use',
				'source_url'     => 'https://records.example/asset/41',
				'checksum'       => 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
				'rights_status'  => 'cleared',
				'privacy_status' => 'reviewed',
				'srcset'         => '/uploads/detectors-640.jpg 640w, /uploads/detectors.jpg 2400w',
			),
		);
	}

	/** A rights-cleared attachment renders as a responsive governed figure. */
	public function test_cleared_media_renders_responsive_figure_with_credit(): void {
		$record = array_replace( $this->record( 'mission', 'pt-br' ), array( 'media' => $this->cleared_media() ) );
		$markup = Homepage::feature_media_markup( $record, 'pt-br', 'hero' );

		self::assertStringContainsString( '<figure class="lps-media lps-media--image">', $markup );
		self::assertStringContainsString( 'src="/uploads/detectors.jpg"', $markup );
		self::assertStringContainsString( 'srcset="/uploads/detectors-640.jpg 640w, /uploads/detectors.jpg 2400w"', $markup );
		self::assertStringContainsString( 'width="2400" height="1600"', $markup );
		self::assertStringContainsString( 'alt="Conjunto de detectores na bancada do laboratório"', $markup );
		self::assertStringContainsString( 'loading="eager" fetchpriority="high"', $markup );
		self::assertStringContainsString( '<figcaption>Bancada de instrumentação do LPS.', $markup );
		self::assertStringContainsString( 'LPS archive - authorized-use', $markup );

		$content = Homepage::feature_media_markup( $record, 'pt-br' );
		self::assertStringContainsString( 'loading="lazy" fetchpriority="auto"', $content );
		self::assertStringNotContainsString( 'fetchpriority="high"', $content );
	}

	/** A reviewed focal point renders as the governed object-position. */
	public function test_focal_point_renders_object_position(): void {
		$media          = $this->cleared_media();
		$media['asset'] = array_merge( $media['asset'], array( 'focal_x' => 0.25, 'focal_y' => 0.8 ) );
		$record         = array_replace( $this->record( 'mission', 'pt-br' ), array( 'media' => $media ) );
		$markup         = Homepage::feature_media_markup( $record, 'pt-br', 'hero' );

		self::assertStringContainsString( 'object-position: 25% 80%', $markup );
	}

	/** An attachment without rights fields can never render, referenced or not. */
	public function test_media_without_rights_fields_degrades_to_text(): void {
		$media          = $this->cleared_media();
		$media['asset'] = array(
			'id'     => 42,
			'mime'   => 'image/jpeg',
			'url'    => '/uploads/unreviewed.jpg',
			'width'  => 800,
			'height' => 600,
		);
		$record         = array_replace( $this->record( 'projects', 'en' ), array( 'media' => $media ) );
		$markup         = Homepage::feature_media_markup( $record, 'en' );
		self::assertSame( '', $markup );

		$html = Homepage::section_markup( 'research', 'en', array( $record ), '2026-09-06' );
		self::assertStringNotContainsString( '<img', $html );
		self::assertStringContainsString( 'CMS &lt;record&gt;', $html );
	}

	/** The removed raw-URL path cannot smuggle an ungoverned image through. */
	public function test_raw_image_url_is_never_rendered(): void {
		$record = array_replace( $this->record( 'mission', 'en' ), array( 'image' => 'https://cdn.example/photo.jpg' ) );
		$markup = Homepage::feature_media_markup( $record, 'en' );
		self::assertSame( '', $markup );

		$html = Homepage::section_markup( 'mission', 'en', array( $record ), '2026-09-06' );
		self::assertStringNotContainsString( 'cdn.example', $html );
		self::assertStringNotContainsString( '<img', $html );
	}

	/** Media slots exist on the feature row and media strata, not on dated rows. */
	public function test_media_slots_render_only_in_media_positions(): void {
		$media   = $this->cleared_media();
		$slotted = array_replace( $this->record( 'projects', 'en' ), array( 'media' => $media ) );
		$html    = Homepage::section_markup( 'research', 'en', array( $slotted ), '2026-09-06' );
		self::assertStringContainsString( '<figure class="lps-media lps-media--image">', $html );

		$plain = Homepage::section_markup( 'research', 'en', array( $this->record( 'projects', 'en' ) ), '2026-09-06' );
		self::assertStringNotContainsString( '<img', $plain );

		$dated = array_replace(
			$this->record( 'latest', 'en' ),
			array(
				'media' => $media,
				'date'  => '2026-09-01',
			)
		);
		$older = array_replace(
			$this->record( 'latest', 'en' ),
			array(
				'media'     => $media,
				'date'      => '2026-08-01',
				'source_id' => 'source:older',
			)
		);
		$html  = Homepage::section_markup( 'latest', 'en', array( $dated, $older ), '2026-09-06' );
		// Only the newest record carries the media slot.
		self::assertSame( 1, substr_count( $html, '<figure class="lps-media' ) );
		self::assertStringContainsString( 'lps-record--featured', $html );
	}

	/** Dated rows print the ISO day even when the canonical date carries a time. */
	public function test_latest_rows_normalize_datetime_to_day(): void {
		$news = array_replace(
			$this->record( 'latest', 'en' ),
			array(
				'type' => 'lps_news',
				'date' => '2026-09-01 09:00:00',
			)
		);
		$html = Homepage::section_markup( 'latest', 'en', array( $news ), '2026-09-06' );
		self::assertStringContainsString( '<time datetime="2026-09-01">2026-09-01</time>', $html );
		self::assertStringNotContainsString( '09:00:00', $html );
	}

	/** Event rows carry an explicit status and venue, differentiated from news. */
	public function test_event_rows_carry_status_and_venue(): void {
		$event = array_replace(
			$this->record( 'latest', 'pt-br' ),
			array(
				'type'         => 'lps_event',
				'date'         => '2026-10-01T14:00:00-03:00',
				'event_status' => 'cancelled',
				'venue'        => 'Auditório do LPS',
			)
		);
		$html = Homepage::section_markup( 'latest', 'pt-br', array( $event ), '2026-09-06' );
		self::assertStringContainsString( '<time datetime="2026-10-01">2026-10-01</time>', $html );
		self::assertStringContainsString( 'Eventos', $html );
		self::assertStringContainsString( 'Cancelado', $html );
		self::assertStringContainsString( 'Auditório do LPS', $html );
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
			'section'            => $section,
			'title'              => 'CMS <record>',
			'summary'            => 'CMS & summary',
			'url'                => '/' . $locale . '/projects/record/',
			'source_id'          => 'source:record',
			'record_id'          => 'lps:project:record',
			'status'             => 'publish',
			'state'              => 'published',
			'locale'             => $locale,
			'stale'              => false,
			'review_date'        => '2027-01-01',
			'feature_order'      => 1,

			// Unified visibility-decision inputs: a reviewed native record.
			'post_type'          => 'lps_project',
			'_lps_state'         => 'published',
			'_lps_origin'        => 'native',
			'_lps_record_id'     => 'lps:project:record',
			'_lps_owner_user_id' => 7,
			'_lps_review_date'   => '2027-01-01',
			'source_status'      => 'publish',
			'source_state'       => 'published',
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
		$html = Homepage::section_markup( 'research', 'en', array( $this->record() ), '2026-09-06' );
		self::assertStringContainsString( 'data-home-section="projects"', $html );
		self::assertStringContainsString( 'data-source-id="source:record"', $html );
		self::assertStringContainsString( 'CMS &lt;record&gt;', $html );
		self::assertStringContainsString( 'CMS &amp; summary', $html );
		self::assertStringNotContainsString( '<img', $html );
	}

	/** Every publication boundary fails closed without hiding a valid neighbor. */
	public function test_unsafe_or_ineligible_content_is_not_rendered(): void {
		foreach ( array( 'pt-br', 'en' ) as $locale ) {
			foreach ( array(
				array( 'status' => 'draft' ),
				array( '_lps_state' => 'archived' ),
				array( 'stale' => true ),
				array( 'locale' => 'en' === $locale ? 'pt-br' : 'en' ),
				array( '_lps_origin' => 'imported', '_lps_import_source_id' => 'record-001', '_lps_import_review_state' => 'candidate' ),
				array( '_lps_origin' => '', '_lps_record_id' => '' ),
				array( '_lps_owner_user_id' => 0 ),
				array( '_lps_review_date' => '2026-09-05' ),
				array( 'featured_until' => '2026-09-05' ),
				array( 'featured_from' => '2026-09-07' ),
				array( 'url' => 'javascript:alert(1)' ),
			) as $change ) {
				$rejected = array_replace( $this->record( 'latest', $locale ), array( 'title' => 'REJECTED_RECORD', 'summary' => 'REJECTED_SUMMARY', 'date' => '2026-09-01' ), $change );
				$valid    = array_replace( $this->record( 'latest', $locale ), array( 'source_id' => 'source:valid', 'date' => '2026-09-02' ) );
				$html     = Homepage::section_markup( 'latest', $locale, array( $rejected, $valid ), '2026-09-06' );
				self::assertStringNotContainsString( 'REJECTED_RECORD', $html );
				self::assertStringNotContainsString( 'REJECTED_SUMMARY', $html );
				self::assertStringNotContainsString( 'source:record', $html );
				self::assertStringContainsString( 'data-source-id="source:valid"', $html );
				self::assertSame( 1, substr_count( $html, '<article' ) );
				self::assertSame(
					Homepage::section_markup( 'latest', $locale, array(), '2026-09-06' ),
					Homepage::section_markup( 'latest', $locale, array( $rejected ), '2026-09-06' )
				);
			}
		}
	}

	/** Three actual anchors use CMS task labels, source IDs and the intended routes. */
	public function test_journeys_use_only_reviewed_destination_ctas_in_both_locales(): void {
		$routes = array(
			'pt-br' => array( 'opportunities' => '/pt-br/oportunidades/', 'collaborate' => '/pt-br/colabore/', 'infrastructure' => '/pt-br/infraestrutura/' ),
			'en'    => array( 'opportunities' => '/en/opportunities/', 'collaborate' => '/en/collaborate/', 'infrastructure' => '/en/infrastructure/' ),
		);
		foreach ( $routes as $locale => $destinations ) {
			$records = array();
			foreach ( $destinations as $key => $url ) {
				$records[] = array_replace( $this->record( 'journeys', $locale ), array( 'page_key' => $key, 'url' => $url, 'cta' => 'CMS_TASK_' . $key, 'source_id' => 'source:' . $key ) );
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
			$html    = Homepage::section_markup( 'journeys', $locale, array(), '2026-09-06' );
			$message = 'en' === $locale ? 'Information not published' : 'Informações não publicadas';
			self::assertStringNotContainsString( '<a ', $html );
			self::assertSame( 3, substr_count( $html, 'aria-disabled="true"' ) );
			self::assertSame( 3, substr_count( $html, '<span aria-disabled="true">' . $message . '</span>' ) );
			self::assertSame( 3, substr_count( $html, 'lps-journey-label' ) );
			self::assertStringContainsString( 'aria-labelledby="lps-home-journeys"', $html );
			self::assertMatchesRegularExpression( '/<h2 id="lps-home-journeys">[^<]+<\/h2>/', $html );
		}
	}

	/** Rendered features, not just the selector, are ordered and bounded to three. */
	public function test_project_stratum_is_bounded_and_mission_has_one_h1(): void {
		foreach ( array( 'pt-br', 'en' ) as $locale ) {
			$records = array();
			foreach ( array( 4, 2, 1, 5, 3 ) as $order ) {
				$records[] = array_replace( $this->record( 'projects', $locale ), array( 'feature_order' => $order, 'source_id' => 'source:' . $order ) );
			}
			$html = Homepage::section_markup( 'research', $locale, $records, '2026-09-06' );
			self::assertSame( 3, substr_count( $html, '<article' ) );
			preg_match_all( '/data-source-id="([^"]+)"/', $html, $sources );
			self::assertSame( array( 'source:1', 'source:2', 'source:3' ), $sources[1] );
			$html = Homepage::section_markup( 'mission', $locale, array( $this->record( 'mission', $locale ) ), '2026-09-06' );
			self::assertSame( 1, substr_count( $html, '<h1' ) );
			self::assertStringNotContainsString( '<h2', $html );
			self::assertStringContainsString( '<section data-home-section="mission" data-source-id="source:record"', $html );
		}
	}

	/**
	 * Sparse policy: optional modules are omitted, required ones keep a named
	 * compact notice, and no state may fall back to foreign CMS copy.
	 */
	public function test_empty_section_is_accessible_without_mixed_language(): void {
		// 'partners' is not optional: it always renders to carry the required contact handoff.
		$optional = array( 'research', 'latest', 'people' );
		foreach ( array( 'pt-br', 'en' ) as $locale ) {
			$foreign_locale = 'en' === $locale ? 'pt-br' : 'en';
			foreach ( $optional as $section ) {
				$foreign = array_replace(
					$this->record( $section, $foreign_locale ),
					array(
						'title'   => 'FOREIGN_TITLE',
						'summary' => 'FOREIGN_SUMMARY',
					)
				);
				self::assertSame( '', Homepage::section_markup( $section, $locale, array(), '2026-09-06' ), $section );
				self::assertSame( '', Homepage::section_markup( $section, $locale, array( $foreign ), '2026-09-06' ), $section );
			}
			foreach ( array( 'mission', 'contact' ) as $section ) {
				$foreign = array_replace(
					$this->record( $section, $foreign_locale ),
					array(
						'title'   => 'FOREIGN_TITLE',
						'summary' => 'FOREIGN_SUMMARY',
					)
				);
				$html    = 'contact' === $section
					? Homepage::section_markup( 'partners', $locale, array( $foreign ), '2026-09-06' )
					: Homepage::section_markup( $section, $locale, array( $foreign ), '2026-09-06' );
				$empty   = 'contact' === $section
					? Homepage::section_markup( 'partners', $locale, array(), '2026-09-06' )
					: Homepage::section_markup( $section, $locale, array(), '2026-09-06' );
				self::assertSame( $empty, $html );
				self::assertStringContainsString( 'aria-labelledby="lps-home-' . $section . '"', $html );
				self::assertMatchesRegularExpression( '/<h[12][^>]*id="lps-home-' . $section . '">[^<]+<\/h[12]>/', $html );
				self::assertMatchesRegularExpression( '/<p data-home-empty="' . $section . '">[^<]+<\/p>/', $html );
				self::assertStringNotContainsString( 'FOREIGN_', $html );
				self::assertStringNotContainsString( '<article', $html );
				// The mission notice keeps only the two pinned first-viewport
				// actions; the contact notice keeps no link at all.
				self::assertSame( 'mission' === $section ? 2 : 0, substr_count( $html, '<a ' ) );
				self::assertStringNotContainsString( '<img', $html );
			}
		}
	}

	/**
	 * A zero-record home renders only the required modules: the mission notice,
	 * the three truthful disabled journeys, the teaching entrance, and the
	 * contact handoff notice.
	 */
	public function test_zero_record_home_renders_only_required_modules(): void {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reads a local theme template fixture, not a remote URL.
		$template = file_get_contents( dirname( __DIR__ ) . '/templates/front-page.html' );
		self::assertIsString( $template );
		preg_match_all( '/wp:lps-theme\/homepage \{"section":"([a-z]+)"/', $template, $blocks );
		self::assertSame( array( 'mission', 'research', 'latest', 'teaching', 'people', 'journeys', 'partners' ), $blocks[1] );
		foreach ( array( 'pt-br', 'en' ) as $locale ) {
			$page = '';
			foreach ( $blocks[1] as $section ) {
				$page .= Homepage::section_markup( $section, $locale, array(), '2026-09-06' );
			}
			self::assertSame( 5, substr_count( $page, 'data-home-section=' ), $page );
			self::assertStringContainsString( 'data-home-section="mission"', $page );
			self::assertStringContainsString( 'data-home-section="journeys"', $page );
			self::assertStringContainsString( 'data-home-section="teaching"', $page );
			self::assertStringContainsString( 'data-home-section="partners"', $page );
			self::assertStringContainsString( 'data-home-section="contact"', $page );
			foreach ( array( 'research', 'latest', 'people', 'projects', 'evidence', 'infrastructure' ) as $omitted ) {
				self::assertStringNotContainsString( 'data-home-section="' . $omitted . '"', $page );
			}
			self::assertSame( 2, substr_count( $page, 'data-home-empty=' ), $page );
			self::assertStringContainsString( 'data-home-empty="mission"', $page );
			self::assertStringContainsString( 'data-home-empty="contact"', $page );
			self::assertSame( 3, substr_count( $page, 'aria-disabled="true"' ) );
			self::assertSame( 1, substr_count( $page, '<h1' ) );
			// The pinned first-viewport actions and the teaching entrance persist.
			self::assertStringContainsString( 'data-home-action="research"', $page );
			self::assertStringContainsString( 'data-home-action="teaching"', $page );
			self::assertStringContainsString( 'en' === $locale ? '/en/teaching/' : '/pt-br/ensino/', $page );
		}
	}

	/**
	 * A nested stratum that becomes the module's only content is promoted to
	 * h2 so the section keeps a valid accessible name and sequential headings.
	 */
	public function test_lone_stratum_is_promoted_to_keep_the_module_named(): void {
		foreach ( array( 'pt-br', 'en' ) as $locale ) {
			$evidence = $this->record( 'evidence', $locale );
			$html     = Homepage::section_markup( 'research', $locale, array( $evidence ), '2026-09-06' );
			self::assertStringContainsString( 'aria-labelledby="lps-home-evidence"', $html );
			self::assertMatchesRegularExpression( '/<h2 class="lps-kicker" id="lps-home-evidence">[^<]+<\/h2>/', $html );
			self::assertStringNotContainsString( 'lps-home-research">', $html );
			self::assertStringNotContainsString( 'data-home-empty', $html );

			$contact = array_replace( $this->record( 'contact', $locale ), array( 'cta' => 'CONTACT_CTA' ) );
			$html    = Homepage::section_markup( 'partners', $locale, array( $contact ), '2026-09-06' );
			// The nested contact stratum keeps its own landmark name; the outer
			// partners section drops its label so the two landmarks never share
			// one accessible name (axe landmark-unique).
			self::assertStringContainsString( 'aria-labelledby="lps-home-contact"', $html );
			self::assertStringContainsString( '<section data-home-section="partners" class="lps-home-section">', $html );
			self::assertMatchesRegularExpression( '/<h2 class="lps-kicker" id="lps-home-contact">[^<]+<\/h2>/', $html );
			self::assertStringNotContainsString( 'lps-home-partners">', $html );
			self::assertStringNotContainsString( 'data-home-empty', $html );
			self::assertStringContainsString( 'CONTACT_CTA', $html );
		}
	}
}
