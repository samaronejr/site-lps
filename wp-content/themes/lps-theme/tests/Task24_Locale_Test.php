<?php
/**
 * Locale metadata and escaping regression cover for Task 24i.
 *
 * @package LPS\Theme\Tests
 */

declare(strict_types=1);

namespace LPS\Theme\Tests;

use DOMDocument;
use DOMElement;
use DOMXPath;
use LPS\Theme\DiscoverySurfaces;
use LPS\Theme\Shell;

require_once dirname( __DIR__ ) . '/includes/class-shell.php';
require_once dirname( __DIR__ ) . '/includes/class-discoverysurfaces.php';

/** Preserves the formatter and output boundaries reused by core metadata. */
final class Task24_Locale_Test extends \PHPUnit\Framework\TestCase {
	/** Full dates resolve through the same public locale path as the shell. */
	public function test_dates_follow_public_locale_paths(): void {
		self::assertSame( '2 de março de 2026', DiscoverySurfaces::format_date( '2026-03-02', 'day', Shell::locale_from_path( '/pt-br/nota/' ) ) );
		self::assertSame( '2 March 2026', DiscoverySurfaces::format_date( '2026-03-02', 'day', Shell::locale_from_path( '/en/note/' ) ) );
		self::assertSame( 'março de 2026', DiscoverySurfaces::format_date( '2026-03', 'month', 'pt-br' ) );
		self::assertSame( 'March 2026', DiscoverySurfaces::format_date( '2026-03', 'month', 'en' ) );
		// The formatter only serves the reader if it reaches the rendered metadata block.
		$pt_markup = Shell::post_metadata_markup( '2026-03-02', '2026-03-02T10:00:00+00:00', array(), 'pt-br' );
		$en_markup = Shell::post_metadata_markup( '2026-03-02', '2026-03-02T10:00:00+00:00', array(), 'en' );
		self::assertStringContainsString( '>2 de março de 2026</time>', $pt_markup );
		self::assertStringContainsString( '>2 March 2026</time>', $en_markup );
		// Machines keep one identical timestamp while the human text differs by locale.
		self::assertStringContainsString( 'datetime="2026-03-02T10:00:00+00:00"', $pt_markup );
		self::assertStringContainsString( 'datetime="2026-03-02T10:00:00+00:00"', $en_markup );
		// The core block rendered `March 2, 2026` on Portuguese routes before this path existed.
		self::assertStringNotContainsString( 'March 2, 2026', $pt_markup );
	}

	/** Unknown and invalid dates never become a fabricated full date. */
	public function test_invalid_dates_keep_the_localized_unknown_state(): void {
		foreach ( array( 'pt-br', 'en' ) as $locale ) {
			$unknown = DiscoverySurfaces::format_date( '', 'unknown', $locale );
			foreach ( array( '2026-02-30', '2026-13-02', '<script>2026</script>', 'not-a-date' ) as $date ) {
				self::assertSame( $unknown, DiscoverySurfaces::format_date( $date, 'day', $locale ) );
			}
			self::assertSame( '2026', DiscoverySurfaces::format_date( '2026', 'year', $locale ) );
		}
		// The unknown state must survive into the rendered block, in the reader's language.
		foreach ( array(
			'pt-br' => 'Data não informada',
			'en'    => 'Date not provided',
		) as $unknown_locale => $unknown_text ) {
			$markup = Shell::post_metadata_markup( 'not-a-date', '', array(), $unknown_locale );
			self::assertStringContainsString( '>' . $unknown_text . '</time>', $markup );
			self::assertStringNotContainsString( '2026', $markup );
		}
	}

	/** Authoritative publication titles stay text, with unchanged action URLs. */
	public function test_publication_output_escapes_titles_without_changing_link_targets(): void {
		$title  = 'QA & "quoted" <img src=x onerror="window.__lpsInjected=true">';
		$record = array(
			'title'    => $title,
			'code_url' => 'https://example.org/code?x=1&y=2',
			'data_url' => 'https://example.org/data?x=1&y=2',
		);
		foreach ( array( 'pt-br', 'en' ) as $locale ) {
			$document = new DOMDocument();
			self::assertTrue( $document->loadXML( '<html><body>' . DiscoverySurfaces::render_publication( $record, array(), array(), $locale ) . '</body></html>' ) );
			$heading = $document->getElementsByTagName( 'h1' )->item( 0 );
			self::assertInstanceOf( DOMElement::class, $heading );
			self::assertSame( $title, ( new DOMXPath( $document ) )->evaluate( 'string(//h1)' ) );
			self::assertSame( 0, $document->getElementsByTagName( 'img' )->length );
			self::assertSame( 0, $document->getElementsByTagName( 'script' )->length );
			$targets = array();
			foreach ( $document->getElementsByTagName( 'a' ) as $link ) {
				$targets[] = $link->getAttribute( 'href' );
			}
			self::assertContains( $record['code_url'], $targets );
			self::assertContains( $record['data_url'], $targets );
			// Action labels read in the reader's language while the URLs stay byte-identical.
			$paths = new DOMXPath( $document );
			self::assertSame( 'en' === $locale ? 'Code' : 'Código', $paths->evaluate( 'string(//a[@href="' . $record['code_url'] . '"])' ) );
			self::assertSame( 'en' === $locale ? 'Data' : 'Dados', $paths->evaluate( 'string(//a[@href="' . $record['data_url'] . '"])' ) );
		}
	}

	/** Stored category names reach the reader decoded, escaped exactly once. */
	public function test_category_labels_render_entities_exactly_once(): void {
		// WordPress stores term names entity-encoded, exactly like post titles.
		$stored = 'Ciencia &amp; &quot;Tecnologia&quot;';
		$reader = 'Ciencia & "Tecnologia"';
		foreach ( array( 'pt-br', 'en' ) as $locale ) {
			$markup   = Shell::post_metadata_markup( '2026-03-02', '2026-03-02T10:00:00+00:00', array( $stored ), $locale );
			$document = new DOMDocument();
			self::assertTrue( $document->loadXML( '<html><body>' . $markup . '</body></html>' ) );
			$terms = ( new DOMXPath( $document ) )->evaluate( 'string(//div[@class="wp-block-post-terms"])' );
			self::assertSame( $reader, $terms );
			self::assertStringNotContainsString( '&amp;amp;', $markup );
			self::assertSame( 1, substr_count( $markup, '&amp;' ) );
			self::assertSame( 1, substr_count( $markup, '&quot;Tecnologia&quot;' ) );
			self::assertSame( 0, $document->getElementsByTagName( 'img' )->length );
			self::assertSame( 0, $document->getElementsByTagName( 'script' )->length );
		}
	}

	/** Malformed, hostile, and missing category names stay inert reader text. */
	public function test_category_labels_survive_malformed_names(): void {
		// Stored exactly as WordPress encodes a hostile term name.
		$hostile = '&lt;img src=x onerror=&quot;window.__lpsInjected=true&quot;&gt;';
		foreach ( array( 'pt-br', 'en' ) as $locale ) {
			$markup   = Shell::post_metadata_markup( '2026-03-02', '2026-03-02T10:00:00+00:00', array( 'Pesquisa &amp; Desenvolvimento', $hostile, '' ), $locale );
			$document = new DOMDocument();
			self::assertTrue( $document->loadXML( '<html><body>' . $markup . '</body></html>' ) );
			$terms = ( new DOMXPath( $document ) )->evaluate( 'string(//div[@class="wp-block-post-terms"])' );
			self::assertSame( 'Pesquisa & Desenvolvimento, <img src=x onerror="window.__lpsInjected=true">, ', $terms );
			// Escaped once: no doubled ampersand and no doubled angle bracket.
			self::assertStringNotContainsString( '&amp;amp;', $markup );
			self::assertStringNotContainsString( '&amp;lt;', $markup );
			self::assertSame( 0, $document->getElementsByTagName( 'img' )->length );
			self::assertSame( 0, $document->getElementsByTagName( 'script' )->length );
		}
		// A record with no categories renders no terms block at all.
		$without_terms = Shell::post_metadata_markup( '2026-03-02', '2026-03-02T10:00:00+00:00', array(), 'pt-br' );
		self::assertStringNotContainsString( 'wp-block-post-terms', $without_terms );
		// The localized missing-category state is a label, never a taxonomy link.
		foreach ( array(
			'pt-br' => 'Sem categoria',
			'en'    => 'Uncategorized',
		) as $missing_locale => $missing_text ) {
			$missing = Shell::post_metadata_markup( '2026-03-02', '2026-03-02T10:00:00+00:00', array( $missing_text ), $missing_locale );
			self::assertStringContainsString( '<div class="wp-block-post-terms">' . $missing_text . '</div>', $missing );
			self::assertStringNotContainsString( '<a ', $missing );
		}
	}
}
