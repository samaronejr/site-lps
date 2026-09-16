<?php
/** Disposable fixture introspection: only public machine-consumed identities. */
declare(strict_types=1);
/** Flattens stored metadata into single string values. */
function lps_t24k_flat_meta( int $post_id ): array {
 $flat = array();
 foreach ( get_post_meta( $post_id ) as $key => $values ) {
  $flat[ (string) $key ] = is_array( $values ) ? (string) reset( $values ) : (string) $values;
 }
 return $flat;
}
/**
 * Exports the machine-consumed facts that decide a record's distinct public state.
 *
 * Language-neutral values are merged from the Portuguese authority exactly as the
 * public surfaces do (Translations::merge_shared_meta plus PublicRoutes::with_authority_meta),
 * so an English variant reports the same lifecycle, consent and identifier state as
 * its authority. Only public state values leave the site: identifiers are reported
 * by field name, and no address, contact or other private metadata is exported.
 */
function lps_t24k_state_facts( WP_Post $post ): array {
 $meta   = lps_t24k_flat_meta( $post->ID );
 $source = $post->ID;
 if ( class_exists( '\\LPS\\ContentModel\\Translations' ) ) {
  $meta     = \LPS\ContentModel\Translations::merge_shared_meta( $post->post_type, $post->ID, $meta );
  $resolved = \LPS\ContentModel\Translations::source_id( $post->ID );
  $source   = null === $resolved ? $post->ID : $resolved;
 }
 if ( $source !== $post->ID && class_exists( '\\LPS\\Theme\\PublicRoutes' ) ) {
  $meta = \LPS\Theme\PublicRoutes::with_authority_meta( $post->post_type, $meta, lps_t24k_flat_meta( $source ) );
 }
 $value       = static fn( string $key ): string => trim( (string) ( $meta[ $key ] ?? '' ) );
 $identifiers = array();
 foreach ( array( '_lps_doi', '_lps_isbn', '_lps_issn', '_lps_arxiv_id', '_lps_canonical_url', '_lps_open_access_url', '_lps_pdf_url' ) as $key ) {
  if ( '' !== $value( $key ) ) { $identifiers[] = $key; }
 }
 return array( 'authorityId' => $source, 'state' => $value( '_lps_state' ), 'personStatus' => $value( '_lps_person_status' ), 'inMemoriamApproved' => $value( '_lps_in_memoriam_approved' ), 'privacyReviewed' => $value( '_lps_privacy_reviewed' ), 'publicProfile' => $value( '_lps_public_profile' ), 'eventStatus' => $value( '_lps_event_status' ), 'startsAt' => $value( '_lps_starts_at' ), 'endsAt' => $value( '_lps_ends_at' ), 'opensAt' => $value( '_lps_opens_at' ), 'closesAt' => $value( '_lps_closes_at' ), 'identifiers' => $identifiers );
}
add_action( 'wp_loaded', static function (): void {
 if ( ( $_GET['task24k'] ?? '' ) !== 'inventory' ) { return; }
 $rows = array();
 // A WordPress install default is never an ordinary record: the signature is
 // exported so consumers fail on it instead of cataloguing placeholder copy.
 $identified = function_exists( 'lps_wordpress_default_content_match' );
 foreach ( get_posts( array( 'post_type' => array( 'page', 'post', 'lps_person', 'lps_project', 'lps_publication', 'lps_research_area', 'lps_news', 'lps_event', 'lps_opportunity', 'lps_organization' ), 'post_status' => 'publish', 'numberposts' => -1, 'lang' => '', 'suppress_filters' => true ) ) as $post ) {
  $rows[] = array( 'id' => $post->ID, 'slug' => $post->post_name, 'parent' => $post->post_parent, 'type' => $post->post_type, 'title' => $post->post_title, 'url' => get_permalink( $post ), 'locale' => pll_get_post_language( $post->ID ), 'declaredLocale' => get_post_meta( $post->ID, '_lps_locale', true ), 'translations' => pll_get_post_translations( $post->ID ), 'pageKey' => get_post_meta( $post->ID, '_lps_page_key', true ), 'eventStatus' => get_post_meta( $post->ID, '_lps_event_status', true ), 'state' => get_post_meta( $post->ID, '_lps_state', true ), 'facts' => lps_t24k_state_facts( $post ), 'placeholder' => $identified ? lps_wordpress_default_content_match( $post->post_type, $post->post_name, $post->post_title, $post->post_content ) : null );
 }
 usort( $rows, static fn( array $a, array $b ): int => $a['id'] <=> $b['id'] );
 $defaults = null;
 if ( function_exists( 'lps_wordpress_default_content_table' ) ) {
  $defaults = array_map(
   static fn( array $entry ): array => array( 'id' => $entry['id'], 'policy' => $entry['policy'], 'postType' => $entry['postType'], 'slug' => $entry['slug'], 'markers' => $entry['markers'], 'probePaths' => $entry['probePaths'] ),
   lps_wordpress_default_content_table()
  );
 }
 header( 'Content-Type: application/json' );
 $ledger = get_option( 'lps_wordpress_default_content_ledger', array() );
 echo wp_json_encode( array( 'schemaVersion' => 1, 'languages' => pll_languages_list(), 'mode' => get_option( 'lps_t24_mode' ), 'wordpressDefaults' => $defaults, 'defaultContentLedger' => is_array( $ledger ) ? array_values( $ledger ) : array(), 'posts' => $rows ), JSON_UNESCAPED_UNICODE );
 exit;
}, 97 );
// Header reports the template actually selected, not a URL-derived guess.
add_filter( 'template_include', static function ( string $file ): string {
 global $_wp_current_template_id;
 header( 'X-Task24k-Template: ' . (string) $_wp_current_template_id );
 header( 'X-Task24k-Post: ' . get_queried_object_id() );
 return $file;
}, PHP_INT_MAX );
