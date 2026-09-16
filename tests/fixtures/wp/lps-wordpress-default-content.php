<?php
/**
 * Identity and removal of WordPress install-default placeholder content.
 *
 * `wp core install` seeds "Hello world!", "Sample Page" and a "Privacy Policy"
 * suggested-text draft. None of that copy is LPS content: it is untranslated
 * English WordPress boilerplate that must never be reachable on a public route,
 * in any locale. The local development bootstrap fixture and the disposable QA
 * provisioners share this single definition, so a fresh provision cannot
 * reintroduce the placeholder records silently and no consumer has to guess at
 * a slug list of its own.
 *
 * Nothing here hides a route: removal is a typed deletion of records whose copy
 * is still WordPress's own, and every refusal is recorded for review.
 *
 * @package LPS\Development
 */

declare(strict_types=1);

if ( ! function_exists( 'lps_wordpress_default_content_table' ) ) {
	/**
	 * Known WordPress install defaults.
	 *
	 * `policy` is `remove` for the records WordPress publishes during install
	 * and `never-publish` for the suggested-text draft, which stays an
	 * unpublished draft but must never appear on a public route.
	 *
	 * @return array<int, array{id: string, policy: string, postType: string, slug: string, title: string, markers: array<int, string>, probePaths: array<int, string>}>
	 */
	function lps_wordpress_default_content_table(): array {
		return array(
			array(
				'id'         => 'wordpress-default-sample-page',
				'policy'     => 'remove',
				'postType'   => 'page',
				'slug'       => 'sample-page',
				'title'      => 'Sample Page',
				'markers'    => array(
					'This is an example page',
					'XYZ Doohickey Company',
					'As a new WordPress user, you should go to',
				),
				'probePaths' => array( '/sample-page/', '/pt-br/sample-page/', '/en/sample-page/' ),
			),
			array(
				'id'         => 'wordpress-default-hello-world',
				'policy'     => 'remove',
				'postType'   => 'post',
				'slug'       => 'hello-world',
				'title'      => 'Hello world!',
				'markers'    => array( 'Welcome to WordPress. This is your first post' ),
				'probePaths' => array( '/hello-world/', '/pt-br/hello-world/', '/en/hello-world/' ),
			),
			array(
				'id'         => 'wordpress-default-privacy-policy',
				'policy'     => 'never-publish',
				'postType'   => 'page',
				'slug'       => 'privacy-policy',
				'title'      => 'Privacy Policy',
				'markers'    => array( 'privacy-policy-tutorial', 'Suggested text:' ),
				'probePaths' => array( '/privacy-policy/', '/pt-br/privacy-policy/', '/en/privacy-policy/' ),
			),
		);
	}
}

if ( ! function_exists( 'lps_wordpress_default_content_match' ) ) {
	/**
	 * Recognises WordPress install-default placeholder content.
	 *
	 * A record is a candidate when it occupies a default slug for the default
	 * record type, or when it still carries every marker of the default copy.
	 * `unmodified` additionally requires the untouched default title, which is
	 * what makes a record safe to delete without reviewing authored content.
	 *
	 * @return array{id: string, policy: string, slugMatch: bool, titleMatch: bool, markerMatch: bool, unmodified: bool, contentSha256: string}|null
	 */
	function lps_wordpress_default_content_match( string $post_type, string $slug, string $title, string $content ): ?array {
		foreach ( lps_wordpress_default_content_table() as $entry ) {
			$slug_match   = $entry['postType'] === $post_type && $entry['slug'] === $slug;
			$marker_match = array() !== $entry['markers'];
			foreach ( $entry['markers'] as $marker ) {
				if ( ! str_contains( $content, $marker ) ) {
					$marker_match = false;
					break;
				}
			}
			if ( ! $slug_match && ! $marker_match ) {
				continue;
			}
			$title_match = $entry['title'] === $title;
			return array(
				'id'            => $entry['id'],
				'policy'        => $entry['policy'],
				'slugMatch'     => $slug_match,
				'titleMatch'    => $title_match,
				'markerMatch'   => $marker_match,
				'unmodified'    => $marker_match && $title_match,
				'contentSha256' => hash( 'sha256', $content ),
			);
		}
		return null;
	}
}

if ( ! function_exists( 'lps_wordpress_default_content_blockers' ) ) {
	/**
	 * Site-structure references that must stop an automatic deletion.
	 *
	 * @param WP_Post $post Candidate record.
	 * @return array<int, string> Blocking reasons, empty when deletion is safe.
	 */
	function lps_wordpress_default_content_blockers( WP_Post $post ): array {
		$blockers = array();
		foreach ( array( 'page_on_front', 'page_for_posts', 'wp_page_for_privacy_policy' ) as $option ) {
			if ( (int) get_option( $option, 0 ) === $post->ID ) {
				$blockers[] = $option;
			}
		}
		$children = get_children(
			array(
				'post_parent' => $post->ID,
				'post_type'   => 'any',
				'post_status' => 'any',
				'numberposts' => 1,
			)
		);
		if ( array() !== $children ) {
			$blockers[] = 'child-records';
		}
		return $blockers;
	}
}

if ( ! function_exists( 'lps_wordpress_default_content_remove' ) ) {
	/**
	 * Deletes every removable WordPress install default whose copy is untouched.
	 *
	 * Absent records produce no ledger entry, so the routine is idempotent and
	 * safe to run on every request. Modified copy and records wired into site
	 * structure are refused and reported rather than deleted.
	 *
	 * @return array<int, array<string, mixed>> Ledger entries for this pass.
	 */
	function lps_wordpress_default_content_remove(): array {
		$ledger = array();
		foreach ( lps_wordpress_default_content_table() as $entry ) {
			if ( 'remove' !== $entry['policy'] ) {
				continue;
			}
			$post = get_page_by_path( $entry['slug'], OBJECT, $entry['postType'] );
			if ( ! $post instanceof WP_Post ) {
				continue;
			}
			$signature = lps_wordpress_default_content_match(
				$post->post_type,
				$post->post_name,
				$post->post_title,
				$post->post_content
			);
			$permalink = get_permalink( $post );
			$record    = array(
				'id'            => $entry['id'],
				'postId'        => $post->ID,
				'postType'      => $post->post_type,
				'slug'          => $post->post_name,
				'title'         => $post->post_title,
				'status'        => $post->post_status,
				'permalink'     => is_string( $permalink ) ? $permalink : '',
				'contentSha256' => null === $signature ? hash( 'sha256', $post->post_content ) : $signature['contentSha256'],
			);
			if ( null === $signature || true !== $signature['unmodified'] ) {
				$record['action'] = 'refused-modified-copy';
				$ledger[]         = $record;
				continue;
			}
			$blockers = lps_wordpress_default_content_blockers( $post );
			if ( array() !== $blockers ) {
				$record['action']   = 'refused-referenced';
				$record['blockers'] = $blockers;
				$ledger[]           = $record;
				continue;
			}
			$deleted          = wp_delete_post( $post->ID, true );
			$record['action'] = ( false === $deleted || null === $deleted ) ? 'delete-failed' : 'deleted';
			$ledger[]         = $record;
		}
		return $ledger;
	}
}

if ( ! function_exists( 'lps_wordpress_default_content_ledger_key' ) ) {
	/**
	 * Identity of one ledger entry, used to append each outcome exactly once.
	 *
	 * @param array<string, mixed> $entry Ledger entry.
	 */
	function lps_wordpress_default_content_ledger_key( array $entry ): string {
		return sprintf(
			'%s|%s|%s',
			is_string( $entry['id'] ?? null ) ? $entry['id'] : '',
			is_string( $entry['action'] ?? null ) ? $entry['action'] : '',
			(string) ( is_int( $entry['postId'] ?? null ) ? $entry['postId'] : 0 )
		);
	}
}

if ( ! function_exists( 'lps_wordpress_default_content_record' ) ) {
	/**
	 * Appends new ledger entries to the stored receipt, writing at most once per
	 * distinct outcome so repeated requests perform no further writes.
	 *
	 * @param array<int, array<string, mixed>> $ledger Ledger entries for this pass.
	 * @return bool Whether the receipt changed.
	 */
	function lps_wordpress_default_content_record( array $ledger, string $option = 'lps_wordpress_default_content_ledger' ): bool {
		$stored = get_option( $option, array() );
		$stored = is_array( $stored ) ? $stored : array();
		$keys   = array();
		foreach ( $stored as $entry ) {
			if ( is_array( $entry ) ) {
				$keys[ lps_wordpress_default_content_ledger_key( $entry ) ] = true;
			}
		}
		$appended = $stored;
		foreach ( $ledger as $entry ) {
			$key = lps_wordpress_default_content_ledger_key( $entry );
			if ( isset( $keys[ $key ] ) ) {
				continue;
			}
			$keys[ $key ] = true;
			$appended[]   = $entry;
		}
		if ( count( $appended ) === count( $stored ) ) {
			return false;
		}
		update_option( $option, $appended );
		return true;
	}
}
