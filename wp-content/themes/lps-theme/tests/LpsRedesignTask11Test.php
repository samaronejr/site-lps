<?php
/**
 * Task 11 tests: the dynamic homepage composition and the governed image pipeline.
 *
 * Pure contracts run here without WordPress: the locked module order, the
 * record-to-module mapping, strata promotion, differentiated latest rows,
 * persistent teaching/journey entrances, text-only media degradation, and the
 * governed renderer's focal point and responsive sources. The wired CMS
 * boundary — publish a news item, link it to the homepage, clear or revoke
 * image rights — is exercised by tests/e2e/lps-redesign/task-11.spec.mjs.
 *
 * @package LPS\Theme\Tests
 */

declare(strict_types=1);

namespace LPS\Theme\Tests;

use LPS\ContentModel\Contracts;
use LPS\ContentModel\MediaPolicy;
use LPS\Theme\Homepage;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/includes/class-homepage.php';
require_once dirname( __DIR__, 3 ) . '/plugins/lps-content-model/includes/class-policy.php';
require_once dirname( __DIR__, 3 ) . '/plugins/lps-content-model/includes/class-mediarenderer.php';
require_once dirname( __DIR__, 3 ) . '/plugins/lps-content-model/includes/class-mediausagepolicy.php';
require_once dirname( __DIR__, 3 ) . '/plugins/lps-content-model/includes/class-mediapolicy.php';
require_once dirname( __DIR__, 3 ) . '/plugins/lps-content-model/includes/class-contracts.php';

final class LpsRedesignTask11Test extends TestCase {
	private const TODAY = '2026-09-19';

	/**
	 * The locked template renders the specified composition in order.
	 *
	 * Mission, research, latest, teaching, people, journeys, partners — the
	 * fixed ten-section presentation is gone; projects, evidence,
	 * infrastructure, and contact survive only as strata inside their modules.
	 */
	public function test_front_page_template_locks_the_specified_composition(): void {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local theme template, not a remote URL.
		$template = file_get_contents( dirname( __DIR__ ) . '/templates/front-page.html' );
		self::assertIsString( $template );
		preg_match_all( '/wp:lps-theme\/homepage \{"section":"([a-z]+)"/', $template, $blocks );
		self::assertSame(
			array( 'mission', 'research', 'latest', 'teaching', 'people', 'journeys', 'partners' ),
			$blocks[1],
			'The front page must render the specified module order.'
		);
		foreach ( array( 'projects', 'evidence', 'infrastructure', 'contact' ) as $stratum ) {
			self::assertStringNotContainsString( '"section":"' . $stratum . '"', $template );
		}
	}

	/** Record types and page keys map onto the composition's modules. */
	public function test_record_section_maps_types_to_modules(): void {
		$map = array(
			'lps_research_area' => 'research',
			'lps_project'       => 'projects',
			'lps_person'        => 'people',
			'lps_publication'   => 'latest',
			'lps_news'          => 'latest',
			'lps_event'         => 'latest',
			'lps_organization'  => 'partners',
		);
		foreach ( $map as $type => $section ) {
			self::assertSame( $section, Homepage::record_section( array( 'type' => $type ) ), $type );
		}
		$pages = array(
			'opportunities'  => 'journeys',
			'collaborate'    => 'journeys',
			'collaboration'  => 'journeys',
			'infrastructure' => 'infrastructure',
			'contact'        => 'contact',
			'home'           => '',
			'about'          => '',
		);
		foreach ( $pages as $key => $section ) {
			self::assertSame( $section, Homepage::record_section( array( 'type' => 'page', 'page_key' => $key ) ), $key );
		}
	}

	/**
	 * The research module nests projects, evidence, and infrastructure as
	 * strata under linked areas; a lone stratum is promoted to h2 and names
	 * the section.
	 */
	public function test_research_module_strata_and_promotion(): void {
		$records = array(
			$this->record( 'research', 'pt-br', array( 'source_id' => 'src:area' ) ),
			$this->record( 'projects', 'pt-br', array( 'source_id' => 'src:project' ) ),
			$this->record( 'evidence', 'pt-br', array( 'source_id' => 'src:evidence' ) ),
			$this->record( 'infrastructure', 'pt-br', array( 'source_id' => 'src:infra' ) ),
		);
		$html = Homepage::section_markup( 'research', 'pt-br', $records, self::TODAY );
		self::assertStringContainsString( 'aria-labelledby="lps-home-research"', $html );
		self::assertMatchesRegularExpression( '/<h2 id="lps-home-research">Pesquisa<\/h2>/', $html );
		foreach ( array( 'projects', 'evidence', 'infrastructure' ) as $stratum ) {
			self::assertStringContainsString( 'data-home-section="' . $stratum . '"', $html );
			self::assertMatchesRegularExpression( '/<h3 class="lps-kicker" id="lps-home-' . $stratum . '">[^<]+<\/h3>/', $html );
		}
		// Strata records nest one heading level below their stratum label.
		self::assertSame( 3, substr_count( $html, '<h4>' ) );
		self::assertSame( 1, substr_count( $html, '<h3><a ' ) );

		// Without areas, the first present stratum is promoted and names the module.
		$lone = Homepage::section_markup( 'research', 'pt-br', array( $records[2] ), self::TODAY );
		self::assertStringContainsString( 'aria-labelledby="lps-home-evidence"', $lone );
		self::assertMatchesRegularExpression( '/<h2 class="lps-kicker" id="lps-home-evidence">[^<]+<\/h2>/', $lone );
		self::assertStringNotContainsString( 'id="lps-home-research"', $lone );
		self::assertStringContainsString( '<h3><a ', $lone );

		// Two strata without areas: only the first is promoted.
		$pair = Homepage::section_markup( 'research', 'pt-br', array( $records[2], $records[3] ), self::TODAY );
		self::assertMatchesRegularExpression( '/<h2 class="lps-kicker" id="lps-home-evidence">/', $pair );
		self::assertMatchesRegularExpression( '/<h3 class="lps-kicker" id="lps-home-infrastructure">/', $pair );
	}

	/** The latest module differentiates the featured row from dated rows. */
	public function test_latest_module_feature_and_dated_rows(): void {
		$records = array(
			$this->record( 'latest', 'en', array( 'source_id' => 'src:newest', 'date' => '2026-09-18', 'type' => 'lps_news' ) ),
			$this->record( 'latest', 'en', array( 'source_id' => 'src:older', 'date' => '2026-09-01', 'type' => 'lps_event', 'event_status' => 'scheduled', 'venue' => 'LPS auditorium' ) ),
			$this->record( 'latest', 'en', array( 'source_id' => 'src:oldest', 'date' => '2026-08-20', 'type' => 'lps_publication' ) ),
		);
		$html = Homepage::section_markup( 'latest', 'en', $records, self::TODAY );
		self::assertSame( 1, substr_count( $html, 'lps-record--featured' ) );
		// The featured row is the newest record, not the first input row.
		self::assertLessThan( strpos( $html, 'src:older' ), strpos( $html, 'src:newest' ) );
		self::assertStringContainsString( 'Scheduled', $html );
		self::assertStringContainsString( 'LPS auditorium', $html );
		self::assertStringContainsString( 'href="/en/news/"', $html );
		self::assertSame( 3, substr_count( $html, '<time datetime=' ) );
	}

	/** The teaching entrance persists in both locales with its canonical route. */
	public function test_teaching_entrance_is_persistent_and_localized(): void {
		foreach ( array( 'pt-br' => array( 'Ensino', 'Disciplinas e materiais', '/pt-br/ensino/' ), 'en' => array( 'Teaching', 'Courses and materials', '/en/teaching/' ) ) as $locale => $expected ) {
			$html = Homepage::section_markup( 'teaching', $locale, array(), self::TODAY );
			self::assertStringContainsString( 'data-home-section="teaching"', $html );
			self::assertStringContainsString( '<h2 id="lps-home-teaching">' . $expected[0] . '</h2>', $html );
			self::assertStringContainsString( 'data-home-action="teaching" href="' . $expected[2] . '">' . $expected[1] . '</a>', $html );
			self::assertStringNotContainsString( 'data-home-empty', $html );
		}
	}

	/** The people module keeps its collaboration entrances; empty stays omitted. */
	public function test_people_module_links_and_omission(): void {
		$html = Homepage::section_markup( 'people', 'pt-br', array( $this->record( 'people', 'pt-br' ) ), self::TODAY );
		self::assertStringContainsString( 'data-home-section="people"', $html );
		self::assertStringContainsString( 'href="/pt-br/pessoas/"', $html );
		self::assertStringContainsString( 'href="/pt-br/colabore/"', $html );
		self::assertSame( '', Homepage::section_markup( 'people', 'pt-br', array(), self::TODAY ) );
	}

	/** The mission module pins the two first-viewport actions in both states. */
	public function test_mission_primary_links_render_with_and_without_a_record(): void {
		foreach ( array( 'pt-br' => array( 'Conheça a pesquisa', 'Disciplinas e materiais' ), 'en' => array( 'Explore the research', 'Courses and materials' ) ) as $locale => $labels ) {
			foreach ( array( array(), array( $this->record( 'mission', $locale ) ) ) as $records ) {
				$html = Homepage::section_markup( 'mission', $locale, $records, self::TODAY );
				self::assertStringContainsString( 'data-home-action="research"', $html );
				self::assertStringContainsString( 'data-home-action="teaching"', $html );
				self::assertStringContainsString( $labels[0], $html );
				self::assertStringContainsString( $labels[1], $html );
			}
		}
	}

	/** A governed image renders focal point, dimensions, credit, and sources. */
	public function test_governed_renderer_outputs_focal_dimensions_credit_and_sources(): void {
		$usage = array(
			'block'     => 'image',
			'media_id'  => 41,
			'locale'    => 'pt-br',
			'alt'       => 'Bancada de instrumentação do laboratório',
			'caption'   => 'Bancada do LPS.',
			'context'   => 'home-mission',
			'placement' => 'hero',
		);
		$asset = array(
			'id'             => 41,
			'mime'           => 'image/jpeg',
			'url'            => '/uploads/bench.jpg',
			'filename'       => 'bench.jpg',
			'width'          => 2400,
			'height'         => 1600,
			'focal_x'        => 0.3,
			'focal_y'        => 0.7,
			'credit'         => 'LPS archive',
			'rights_holder'  => 'LPS',
			'license'        => 'authorized-use',
			'source_url'     => 'https://records.example/asset/41',
			'checksum'       => 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
			'rights_status'  => 'cleared',
			'privacy_status' => 'reviewed',
			'srcset'         => '/uploads/bench-640.jpg 640w, /uploads/bench.jpg 2400w',
			'sources'        => array(
				'image/avif' => '/uploads/bench-640.avif 640w, /uploads/bench.avif 2400w',
				'image/webp' => '/uploads/bench-640.webp 640w, /uploads/bench.webp 2400w',
			),
		);
		self::assertSame( array(), MediaPolicy::usage_errors( $usage, $asset ) );
		$html = MediaPolicy::render_image( $usage, $asset );
		self::assertStringContainsString( 'object-position: 30% 70%', $html );
		self::assertStringContainsString( 'width="2400" height="1600"', $html );
		self::assertStringContainsString( 'type="image/avif"', $html );
		self::assertStringContainsString( 'type="image/webp"', $html );
		self::assertStringContainsString( 'LPS archive - authorized-use', $html );
		self::assertStringContainsString( 'loading="eager" fetchpriority="high"', $html );
	}

	/** The centered focal default emits no redundant object-position. */
	public function test_centered_focal_point_emits_no_style(): void {
		$usage = array(
			'block'     => 'image',
			'media_id'  => 41,
			'locale'    => 'en',
			'alt'       => 'Laboratory bench',
			'caption'   => 'LPS bench.',
			'context'   => 'home-mission',
			'placement' => 'content',
		);
		$asset = array(
			'id'             => 41,
			'mime'           => 'image/jpeg',
			'url'            => '/uploads/bench.jpg',
			'filename'       => 'bench.jpg',
			'width'          => 2400,
			'height'         => 1600,
			'focal_x'        => 0.5,
			'focal_y'        => 0.5,
			'credit'         => 'LPS archive',
			'rights_holder'  => 'LPS',
			'license'        => 'authorized-use',
			'source_url'     => 'https://records.example/asset/41',
			'checksum'       => 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
			'rights_status'  => 'cleared',
			'privacy_status' => 'reviewed',
		);
		$html = MediaPolicy::render_image( $usage, $asset );
		self::assertStringNotContainsString( 'object-position', $html );
		self::assertStringNotContainsString( 'style=', $html );
	}

	/** Every record type the homepage composes may carry a reviewed featured image. */
	public function test_record_types_support_reviewed_featured_media(): void {
		$types = array( 'page', 'lps_person', 'lps_organization', 'lps_research_area', 'lps_project', 'lps_publication', 'lps_news', 'lps_event' );
		$defs  = Contracts::post_types();
		foreach ( $types as $type ) {
			self::assertArrayHasKey( $type, $defs );
			self::assertContains( 'thumbnail', $defs[ $type ]['supports'], $type );
		}
	}

	/**
	 * Creates an explicit, current, reviewed CMS selection.
	 *
	 * @param string               $section   Locked section key.
	 * @param string               $locale    Record locale.
	 * @param array<string, mixed> $overrides Field overrides.
	 * @return array<string, mixed>
	 */
	private function record( string $section, string $locale, array $overrides = array() ): array {
		return array_replace(
			array(
				'section'            => $section,
				'title'              => 'CMS record',
				'summary'            => 'CMS summary',
				'url'                => '/' . $locale . '/projects/record/',
				'source_id'          => 'source:record',
				'record_id'          => 'lps:project:record',
				'status'             => 'publish',
				'state'              => 'published',
				'locale'             => $locale,
				'stale'              => false,
				'review_date'        => '2027-01-01',
				'feature_order'      => 1,
				'post_type'          => 'lps_project',
				'_lps_state'         => 'published',
				'_lps_origin'        => 'native',
				'_lps_record_id'     => 'lps:project:record',
				'_lps_owner_user_id' => 7,
				'_lps_review_date'   => '2027-01-01',
				'source_status'      => 'publish',
				'source_state'       => 'published',
			),
			$overrides
		);
	}
}
