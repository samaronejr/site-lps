<?php
/**
 * WordPress adapter for the publication-visibility decision records.
 *
 * @package LPS\ContentModel
 */

declare(strict_types=1);

namespace LPS\ContentModel;

use WP_Post;

require_once __DIR__ . '/class-publicationpolicy.php';
require_once __DIR__ . '/class-contracts.php';

/**
 * Assembles the portable decision records every public surface evaluates.
 *
 * Provenance is always read from the authoritative Portuguese record: an
 * English variant never carries its own import identity, so a variant cannot
 * mint provenance or hide behind a forged origin claim. The adapter performs
 * no eligibility logic of its own — it only gathers the inputs
 * `PublicationPolicy::visibility_decision()` consumes.
 */
final class PublicationRecords {
	/**
	 * Builds the portable decision record for one stored post.
	 *
	 * @param WP_Post $post Stored record.
	 * @return array<string, mixed>
	 */
	public static function publication_record( WP_Post $post ): array {
		// The translation boundary is loaded lazily so this adapter stays safe
		// to require during early bring-up, before the plugin's own bootstrap
		// has wired every include.
		if ( ! class_exists( Translations::class, false ) && is_readable( __DIR__ . '/class-translations.php' ) ) {
			require_once __DIR__ . '/class-translations.php';
		}
		$source_id = class_exists( Translations::class ) ? ( Translations::source_id( $post->ID ) ?? $post->ID ) : $post->ID;
		$source    = $source_id === $post->ID ? $post : get_post( $source_id );
		$source    = $source instanceof WP_Post ? $source : $post;
		$meta      = array();
		foreach ( PublicationPolicy::decision_meta_keys() as $key ) {
			$meta[ $key ] = get_post_meta( $source->ID, $key, true );
		}
		$locale = class_exists( Translations::class ) ? Translations::locale( $post->ID ) : Policy::scalar_string( get_post_meta( $post->ID, '_lps_locale', true ) );
		$stale  = 'en' === $locale && class_exists( Translations::class ) && Translations::is_stale( $post->ID );
		return array_merge(
			$meta,
			array(
				'post_id'       => $post->ID,
				'post_type'     => $post->post_type,
				'status'        => $post->post_status,
				'locale'        => $locale,
				'stale'         => $stale,
				'_lps_state'    => get_post_meta( $post->ID, '_lps_state', true ),
				'source_status' => $source->post_status,
				'source_state'  => Policy::scalar_string( get_post_meta( $source->ID, '_lps_state', true ) ),
			)
		);
	}

	/**
	 * Builds the deterministic ambiguous-origin reconciliation report.
	 *
	 * The report is a review queue, never an automatic trust decision: rows
	 * are classified by `PublicationPolicy::reconciliation_class()` and only
	 * records needing editorial attention are returned.
	 *
	 * @return array<int, array{post_id: int, post_type: string, title: string, origin: string, action: string}>
	 */
	public static function origin_reconciliation(): array {
		$ids  = get_posts(
			array(
				'post_type'      => array_keys( Contracts::post_types() ),
				'post_status'    => array( 'publish', 'draft', 'pending', 'future', 'private' ),
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'orderby'        => 'ID',
				'order'          => 'ASC',
			)
		);
		$rows = array();
		foreach ( $ids as $raw_id ) {
			$post_id = Policy::sanitize_integer( $raw_id );
			$post    = get_post( $post_id );
			if ( ! $post instanceof WP_Post ) {
				continue;
			}
			$record = array( '_lps_origin' => get_post_meta( $post_id, '_lps_origin', true ) );
			foreach ( PublicationPolicy::decision_meta_keys() as $key ) {
				if ( '_lps_origin' !== $key ) {
					$record[ $key ] = get_post_meta( $post_id, $key, true );
				}
			}
			$class = PublicationPolicy::reconciliation_class( $record );
			if ( 'none' === $class['action'] ) {
				continue;
			}
			$rows[] = array(
				'post_id'   => $post_id,
				'post_type' => $post->post_type,
				'title'     => $post->post_title,
				'origin'    => $class['origin'],
				'action'    => $class['action'],
			);
		}
		return PublicationPolicy::sort_reconciliation( $rows );
	}
}
