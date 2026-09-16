<?php
/**
 * Locale route tests for trust surfaces and institutional pages.
 *
 * @package LPS\Theme\Tests
 */

declare(strict_types=1);

namespace LPS\Theme\Tests;

require_once dirname( __DIR__ ) . '/includes/class-trustroutes.php';

use DateTimeImmutable;
use LPS\Theme\TrustRoutes;
use PHPUnit\Framework\Attributes\DataProvider;

/** Route contract tests for trust and institutional surfaces. */
final class TrustRoutesTest extends \PHPUnit\Framework\TestCase {
	/**
	 * Provides frozen archive routes per record type and locale.
	 *
	 * @return array<string, array{string, string, string}>
	 */
	public static function frozen_archives(): array {
		return array(
			'opportunities pt-br' => array( 'lps_opportunity', 'pt-br', '/pt-br/oportunidades/' ),
			'opportunities en'    => array( 'lps_opportunity', 'en', '/en/opportunities/' ),
			'events pt-br'        => array( 'lps_event', 'pt-br', '/pt-br/eventos/' ),
			'events en'           => array( 'lps_event', 'en', '/en/events/' ),
			'news pt-br'          => array( 'lps_news', 'pt-br', '/pt-br/noticias/' ),
			'news en'             => array( 'lps_news', 'en', '/en/news/' ),
		);
	}

	/**
	 * Archive paths match the frozen information architecture.
	 *
	 * @param string $post_type Record type.
	 * @param string $locale    Locale slug.
	 * @param string $expected  Frozen path.
	 */
	#[DataProvider( 'frozen_archives' )]
	public function test_archive_paths_follow_the_frozen_information_architecture( string $post_type, string $locale, string $expected ): void {
		self::assertSame( $expected, TrustRoutes::archive_path( $post_type, $locale ) );
	}

	/**
	 * Provides frozen institutional page routes per key and locale.
	 *
	 * @return array<string, array{string, string, string}>
	 */
	public static function frozen_pages(): array {
		return array(
			'about pt-br'         => array( 'about', 'pt-br', '/pt-br/sobre/' ),
			'about en'            => array( 'about', 'en', '/en/about/' ),
			'collaboration pt-br' => array( 'collaboration', 'pt-br', '/pt-br/colabore/' ),
			'collaboration en'    => array( 'collaboration', 'en', '/en/collaborate/' ),
			'contact pt-br'       => array( 'contact', 'pt-br', '/pt-br/contato/' ),
			'contact en'          => array( 'contact', 'en', '/en/contact/' ),
			'privacy pt-br'       => array( 'privacy', 'pt-br', '/pt-br/privacidade/' ),
			'privacy en'          => array( 'privacy', 'en', '/en/privacy/' ),
			'accessibility pt-br' => array( 'accessibility', 'pt-br', '/pt-br/acessibilidade/' ),
			'accessibility en'    => array( 'accessibility', 'en', '/en/accessibility/' ),
			'history pt-br'       => array( 'history', 'pt-br', '/pt-br/sobre/historia/' ),
			'history en'          => array( 'history', 'en', '/en/about/history/' ),
			'governance pt-br'    => array( 'governance', 'pt-br', '/pt-br/sobre/governanca/' ),
			'governance en'       => array( 'governance', 'en', '/en/about/governance/' ),
		);
	}

	/**
	 * Institutional page paths match the frozen information architecture.
	 *
	 * @param string $key      Institutional page key.
	 * @param string $locale   Locale slug.
	 * @param string $expected Frozen path.
	 */
	#[DataProvider( 'frozen_pages' )]
	public function test_institutional_page_paths_follow_the_frozen_information_architecture( string $key, string $locale, string $expected ): void {
		self::assertSame( $expected, TrustRoutes::page_path( $key, $locale ) );
	}

	/** Closed and cancelled records keep their original public path. */
	public function test_single_paths_are_stable_for_closed_and_cancelled_records(): void {
		self::assertSame( '/pt-br/oportunidades/bolsa-encerrada/', TrustRoutes::single_path( 'lps_opportunity', 'pt-br', 'bolsa-encerrada' ) );
		self::assertSame( '/en/events/cancelled-seminar/', TrustRoutes::single_path( 'lps_event', 'en', 'cancelled-seminar' ) );
	}

	/** Only trust routes resolve; foreign and unsupported locales do not. */
	public function test_match_path_resolves_trust_routes_and_rejects_foreign_paths(): void {
		self::assertSame(
			array(
				'post_type' => 'lps_opportunity',
				'locale'    => 'pt-br',
				'slug'      => 'bolsa-doutorado',
			),
			TrustRoutes::match_path( '/pt-br/oportunidades/bolsa-doutorado/' )
		);
		self::assertSame(
			array(
				'post_type' => 'lps_news',
				'locale'    => 'en',
				'slug'      => '',
			),
			TrustRoutes::match_path( '/en/news/' )
		);
		self::assertNull( TrustRoutes::match_path( '/en/publications/' ) );
		self::assertNull( TrustRoutes::match_path( '/fr/news/' ) );
	}

	/** Every archive, paged, and detail route is registered. */
	public function test_rewrite_rules_cover_every_trust_archive_and_detail_route(): void {
		$rules = TrustRoutes::rewrite_rules();
		self::assertArrayHasKey( 'pt-br/oportunidades/?$', $rules );
		self::assertArrayHasKey( 'pt-br/oportunidades/([^/]+)/?$', $rules );
		self::assertArrayHasKey( 'en/events/([^/]+)/?$', $rules );
		self::assertArrayHasKey( 'en/news/?$', $rules );
		foreach ( $rules as $query ) {
			self::assertStringStartsWith( 'index.php?post_type=lps_', $query );
		}
	}

	/**
	 * Provides locale slugs and their BCP47 language tags.
	 *
	 * @return array<string, array{string, string}>
	 */
	public static function language_tags(): array {
		return array(
			'portuguese' => array( 'pt-br', 'pt-BR' ),
			'english'    => array( 'en', 'en' ),
			'fallback'   => array( 'fr', 'pt-BR' ),
		);
	}

	/**
	 * Locale slugs map to their BCP47 language tags.
	 *
	 * @param string $locale   Locale slug.
	 * @param string $expected BCP47 tag.
	 */
	#[DataProvider( 'language_tags' )]
	public function test_locale_slugs_map_to_bcp47_language_tags( string $locale, string $expected ): void {
		self::assertSame( $expected, TrustRoutes::bcp47( $locale ) );
	}

	/** Only opportunities past the retention window become noindex. */
	public function test_robots_directive_is_emitted_only_for_aged_closed_opportunities(): void {
		$now = new DateTimeImmutable( '2026-09-03T12:00:00+00:00' );
		self::assertSame( '', TrustRoutes::robots_directive( 'lps_opportunity', '2026-09-02T12:00:00+00:00', $now ) );
		self::assertSame( 'noindex, follow', TrustRoutes::robots_directive( 'lps_opportunity', '2026-01-31T12:00:00+00:00', $now ) );
		self::assertSame( '', TrustRoutes::robots_directive( 'lps_event', '2020-01-01T12:00:00+00:00', $now ) );
	}

	/** Trust and institutional paths resolve to their owning locale. */
	public function test_locale_for_path_covers_trust_and_institutional_routes(): void {
		self::assertSame( 'pt-br', TrustRoutes::locale_for_path( '/pt-br/oportunidades/bolsa/' ) );
		self::assertSame( 'en', TrustRoutes::locale_for_path( '/en/news/' ) );
		self::assertSame( 'en', TrustRoutes::locale_for_path( '/en/privacy/' ) );
		self::assertSame( 'pt-br', TrustRoutes::locale_for_path( '/pt-br/acessibilidade/' ) );
		self::assertSame( '', TrustRoutes::locale_for_path( '/en/publications/' ) );
	}

	/** The document language tag follows the route locale in BCP47 form. */
	public function test_language_attributes_use_bcp47_route_locale(): void {
		self::assertSame(
			'lang="pt-BR"',
			TrustRoutes::language_attributes_for_path( 'lang="en-US"', '/pt-br/oportunidades/' )
		);
		self::assertSame(
			'lang="en"',
			TrustRoutes::language_attributes_for_path( 'lang="pt-BR"', '/en/privacy/' )
		);
		self::assertSame(
			'lang="en-US"',
			TrustRoutes::language_attributes_for_path( 'lang="en-US"', '/en/publications/' )
		);
	}
}
