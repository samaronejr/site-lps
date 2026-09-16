<?php
/**
 * Regressions for the removal of WordPress install-default placeholder content.
 *
 * `wp core install` publishes "Hello world!" and an English "Sample Page". The
 * development bootstrap adopts every language-less record into pt-br, so those
 * untouched English placeholders used to become public Portuguese routes such
 * as /pt-br/sample-page/. These scenarios run against the in-memory request
 * runtime: no web server, browser or live import is involved.
 *
 * @package LPS\Tests
 */

declare(strict_types=1);

namespace LPS\Tests;

use PHPUnit\Framework\TestCase;

final class WordPressDefaultContentTest extends TestCase {
	/** Untouched WordPress install copy of the Sample Page. */
	private const SAMPLE_PAGE_COPY = "<!-- wp:paragraph -->\n<p>This is an example page. It's different from a blog post because it will stay in one place and will show up in your site navigation (in most themes). Most people start with an About page that introduces them to potential site visitors. It might say something like this:</p>\n<!-- /wp:paragraph -->\n\n<!-- wp:quote -->\n<blockquote class=\"wp-block-quote\"><p>...or something like this:</p></blockquote>\n<!-- /wp:quote -->\n\n<!-- wp:paragraph -->\n<p>The XYZ Doohickey Company was founded in 1971, and has been providing quality doohickeys to the public ever since.</p>\n<!-- /wp:paragraph -->\n\n<!-- wp:paragraph -->\n<p>As a new WordPress user, you should go to your dashboard to delete this page and create new pages for your content. Have fun!</p>\n<!-- /wp:paragraph -->";

	/** Untouched WordPress install copy of the first post. */
	private const HELLO_WORLD_COPY = "<!-- wp:paragraph -->\n<p>Welcome to WordPress. This is your first post. Edit or delete it, then start writing!</p>\n<!-- /wp:paragraph -->";

	/** Untouched WordPress install copy of the privacy-policy suggested text. */
	private const PRIVACY_POLICY_COPY = "<!-- wp:heading -->\n<h2>Who we are</h2>\n<!-- /wp:heading -->\n\n<!-- wp:paragraph -->\n<p><strong class=\"privacy-policy-tutorial\">Suggested text: </strong>Our website address is: https://example.com.</p>\n<!-- /wp:paragraph -->";

	private const LEDGER_OPTION = 'lps_wordpress_default_content_ledger';

	public static function setUpBeforeClass(): void {
		require_once __DIR__ . '/WpSeedRuntime.php';
		require_once dirname( __DIR__ ) . '/fixtures/wp/lps-wordpress-default-content.php';
	}

	protected function setUp(): void {
		WpSeedRuntime::reset();
	}

	/**
	 * Seeds one record exactly as WordPress leaves it after install.
	 *
	 * @param string $status Publication status.
	 */
	private function seed( string $post_type, string $slug, string $title, string $content, string $status = 'publish' ): int {
		return WpSeedRuntime::insert_post(
			array(
				'post_type'    => $post_type,
				'post_name'    => $slug,
				'post_title'   => $title,
				'post_content' => $content,
				'post_status'  => $status,
			)
		);
	}

	/** Seeds the published install defaults and clears the write counters. */
	private function seed_install_defaults(): void {
		$this->seed( 'page', 'sample-page', 'Sample Page', self::SAMPLE_PAGE_COPY );
		$this->seed( 'post', 'hello-world', 'Hello world!', self::HELLO_WORLD_COPY );
		$this->seed( 'page', 'privacy-policy', 'Privacy Policy', self::PRIVACY_POLICY_COPY, 'draft' );
		WpSeedRuntime::reset_counters();
	}

	/**
	 * Slugs of every record still stored, in identifier order.
	 *
	 * @return array<int, string>
	 */
	private function stored_slugs(): array {
		return array_values( array_map( static fn( array $row ): string => $row['post_name'], WpSeedRuntime::posts() ) );
	}

	public function test_untouched_install_copy_is_recognised_as_an_unmodified_default(): void {
		$signature = lps_wordpress_default_content_match( 'page', 'sample-page', 'Sample Page', self::SAMPLE_PAGE_COPY );

		self::assertNotNull( $signature );
		self::assertSame( 'wordpress-default-sample-page', $signature['id'] );
		self::assertSame( 'remove', $signature['policy'] );
		self::assertTrue( $signature['slugMatch'] );
		self::assertTrue( $signature['titleMatch'] );
		self::assertTrue( $signature['markerMatch'] );
		self::assertTrue( $signature['unmodified'] );
		self::assertSame( hash( 'sha256', self::SAMPLE_PAGE_COPY ), $signature['contentSha256'] );
	}

	public function test_authored_records_are_never_reported_as_install_defaults(): void {
		self::assertNull(
			lps_wordpress_default_content_match( 'page', 'privacidade', 'Privacidade', '<p>Política de privacidade do LPS.</p>' )
		);
		self::assertNull(
			lps_wordpress_default_content_match( 'lps_news', 'nota-de-abertura', 'Nota de abertura', '<p>Notícia do laboratório.</p>' )
		);
	}

	public function test_authored_copy_at_a_default_slug_is_reported_as_modified(): void {
		$signature = lps_wordpress_default_content_match( 'page', 'sample-page', 'Sobre o laboratório', '<p>Texto autoral.</p>' );

		self::assertNotNull( $signature );
		self::assertTrue( $signature['slugMatch'] );
		self::assertFalse( $signature['titleMatch'] );
		self::assertFalse( $signature['markerMatch'] );
		self::assertFalse( $signature['unmodified'], 'Authored copy must never be reported as safe to delete.' );
	}

	public function test_removal_deletes_published_install_defaults_and_records_the_route_it_removed(): void {
		$this->seed_install_defaults();

		$ledger = lps_wordpress_default_content_remove();

		self::assertSame( array( 'privacy-policy' ), $this->stored_slugs(), 'Only the suggested-text draft may survive.' );
		self::assertSame( 2, WpSeedRuntime::deletes() );
		self::assertSame(
			array( 'wordpress-default-sample-page', 'wordpress-default-hello-world' ),
			array_column( $ledger, 'id' )
		);
		self::assertSame( array( 'deleted', 'deleted' ), array_column( $ledger, 'action' ) );
		self::assertSame(
			'https://lps.test/sample-page/',
			$ledger[0]['permalink'],
			'The receipt must record the route that existed before the deletion.'
		);
		self::assertSame( hash( 'sha256', self::SAMPLE_PAGE_COPY ), $ledger[0]['contentSha256'] );
		self::assertSame( 0, WpSeedRuntime::writes(), 'Removal edits no other record.' );
	}

	public function test_the_suggested_text_draft_is_left_untouched(): void {
		$this->seed_install_defaults();

		lps_wordpress_default_content_remove();

		$draft = WpSeedRuntime::find_post( 'privacy-policy', 'page' );
		self::assertNotNull( $draft );
		self::assertSame( 'draft', $draft['post_status'], 'The never-publish draft stays an unpublished draft.' );
	}

	public function test_removal_is_idempotent_across_repeated_requests(): void {
		$this->seed_install_defaults();
		lps_wordpress_default_content_record( lps_wordpress_default_content_remove() );
		$receipt = WpSeedRuntime::get_option( self::LEDGER_OPTION, array() );

		$second = lps_wordpress_default_content_remove();
		$changed = lps_wordpress_default_content_record( $second );

		self::assertSame( array(), $second, 'Absent records produce no further ledger entries.' );
		self::assertFalse( $changed, 'A settled receipt is not rewritten.' );
		self::assertSame( 2, WpSeedRuntime::deletes(), 'No second deletion pass runs.' );
		self::assertSame( $receipt, WpSeedRuntime::get_option( self::LEDGER_OPTION, array() ) );
	}

	public function test_the_receipt_appends_each_distinct_outcome_exactly_once(): void {
		$entry = array(
			'id'     => 'wordpress-default-sample-page',
			'postId' => 2,
			'action' => 'deleted',
		);

		self::assertTrue( lps_wordpress_default_content_record( array( $entry ) ) );
		self::assertFalse( lps_wordpress_default_content_record( array( $entry ) ) );
		self::assertTrue(
			lps_wordpress_default_content_record( array( array( 'id' => 'wordpress-default-hello-world', 'postId' => 1, 'action' => 'deleted' ) ) )
		);

		$stored = WpSeedRuntime::get_option( self::LEDGER_OPTION, array() );
		self::assertIsArray( $stored );
		self::assertCount( 2, $stored );
	}

	public function test_modified_copy_at_a_default_slug_is_refused_instead_of_deleted(): void {
		$this->seed( 'page', 'sample-page', 'Sobre o laboratório', '<p>Página autoral do LPS.</p>' );
		WpSeedRuntime::reset_counters();

		$ledger = lps_wordpress_default_content_remove();

		self::assertSame( 0, WpSeedRuntime::deletes(), 'Authored content is never deleted automatically.' );
		self::assertSame( array( 'sample-page' ), $this->stored_slugs() );
		self::assertSame( 'refused-modified-copy', $ledger[0]['action'] );
		self::assertSame( 'Sobre o laboratório', $ledger[0]['title'] );
	}

	public function test_a_default_wired_into_site_structure_is_refused_and_reported(): void {
		$id = $this->seed( 'page', 'sample-page', 'Sample Page', self::SAMPLE_PAGE_COPY );
		WpSeedRuntime::update_option( 'page_on_front', $id );
		WpSeedRuntime::reset_counters();

		$ledger = lps_wordpress_default_content_remove();

		self::assertSame( 0, WpSeedRuntime::deletes() );
		self::assertSame( 'refused-referenced', $ledger[0]['action'] );
		self::assertSame( array( 'page_on_front' ), $ledger[0]['blockers'] );
	}

	public function test_a_default_with_child_records_is_refused_and_reported(): void {
		$id = $this->seed( 'page', 'sample-page', 'Sample Page', self::SAMPLE_PAGE_COPY );
		WpSeedRuntime::insert_post(
			array(
				'post_type'    => 'page',
				'post_name'    => 'sub-pagina',
				'post_title'   => 'Sub página',
				'post_content' => '<p>Conteúdo autoral.</p>',
				'post_status'  => 'publish',
				'post_parent'  => $id,
			)
		);
		WpSeedRuntime::reset_counters();

		$ledger = lps_wordpress_default_content_remove();

		self::assertSame( 0, WpSeedRuntime::deletes(), 'Deleting a parent would orphan authored children.' );
		self::assertSame( 'refused-referenced', $ledger[0]['action'] );
		self::assertSame( array( 'child-records' ), $ledger[0]['blockers'] );
	}

	public function test_the_bootstrap_removes_defaults_before_the_locale_assignment_adopts_them(): void {
		require_once dirname( __DIR__ ) . '/fixtures/wp/lps-development-bootstrap.php';
		$this->seed_install_defaults();
		$observed = null;
		// Runs at the priority of the pt-br adoption callback, so whatever it sees is
		// what that callback could have adopted into a public Portuguese route.
		WpSeedRuntime::add_hook(
			'init',
			function () use ( &$observed ): void {
				$observed = $this->stored_slugs();
			},
			1
		);

		WpSeedRuntime::fire( 'init' );

		self::assertSame(
			array( 'privacy-policy' ),
			$observed,
			'The install defaults must already be gone when language assignment runs.'
		);
		$receipt = WpSeedRuntime::get_option( self::LEDGER_OPTION, array() );
		self::assertIsArray( $receipt );
		self::assertSame(
			array( 'https://lps.test/sample-page/', 'https://lps.test/hello-world/' ),
			array_column( $receipt, 'permalink' )
		);
	}
}
