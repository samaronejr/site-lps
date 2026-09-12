<?php
/**
 * Polylang and WordPress adapter for controlled bilingual publishing.
 *
 * @package LPS\ContentModel
 */

declare(strict_types=1);

namespace LPS\ContentModel;

use WP_Error;
use WP_Post;
use WP_REST_Request;

/** Integrates the portable translation policy with Polylang and WordPress storage. */
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Atomic cross-locale material synchronization must use one database transaction.
/** Integrates Polylang without transferring editorial ownership between variants. */
final class Translations {
	private const REVIEW_NONCE_ACTION = 'lps_translation_review';
	private const REVIEW_NONCE_NAME   = '_lps_translation_review_nonce';
	private const PINNED_POLYLANG     = '3.8.7';

	/**
	 * Whether an authoritative system synchronization is in progress.
	 *
	 * @var bool
	 */
	private static bool $synchronizing = false;

	/** Registers translation hooks. */
	public static function boot(): void {
		add_filter( 'pll_get_post_types', array( self::class, 'translated_post_types' ), 10, 2 );
		add_action( 'init', array( self::class, 'configure_polylang_options' ), 1 );
		add_action( 'wp_after_insert_post', array( self::class, 'post_saved' ), 20, 4 );
		add_action( 'updated_post_meta', array( self::class, 'source_meta_updated' ), 20, 4 );
		add_filter( 'update_post_metadata', array( self::class, 'protect_shared_meta' ), 8, 5 );
		add_filter( 'language_attributes', array( self::class, 'language_attributes' ), 99 );
		add_filter( 'pll_rel_hreflang_attributes', array( self::class, 'published_hreflangs' ), 99 );
		add_action( 'template_redirect', array( self::class, 'enforce_requested_singular_locale' ), 2 );
		add_filter( 'redirect_canonical', array( self::class, 'prevent_locale_fallback_redirect' ), 99, 2 );
		add_filter( 'pll_check_canonical_url', array( self::class, 'prevent_polylang_locale_fallback' ), 99, 2 );
		add_filter( 'pll_redirect_home', array( self::class, 'prevent_absent_variant_home_redirect' ), 99 );
		add_action( 'admin_menu', array( self::class, 'register_dashboard' ) );
		add_action( 'admin_post_lps_review_translation', array( self::class, 'handle_review' ) );
	}

	/**
	 * Adds governed public records to Polylang's translated types.
	 *
	 * @param array<string, string> $post_types Existing translated types.
	 * @param bool                  $is_settings Whether this is the settings screen.
	 * @return array<string, string>
	 */
	public static function translated_post_types( array $post_types, bool $is_settings ): array {
		unset( $is_settings );
		foreach ( Contracts::post_types() as $post_type => $definition ) {
			if ( (bool) $definition['public'] ) {
				$post_types[ $post_type ] = $post_type;
			}
		}
		return $post_types;
	}

	/** Enforces directory routes for both locales and disables browser-language redirects. */
	public static function configure_polylang_options(): void {
		if ( ! defined( 'POLYLANG_VERSION' ) ) {
			return;
		}
		$current = get_option( 'polylang', array() );
		$current = is_array( $current ) ? $current : array();
		$desired = array_merge( $current, TranslationPolicy::route_options() );
		if ( $desired !== $current ) {
			update_option( 'polylang', $desired, false );
		}
	}

	/** Returns a typed runtime-contract error for missing or unpinned Polylang. */
	public static function polylang_version_error(): ?string {
		if ( ! defined( 'POLYLANG_VERSION' ) ) {
			return 'lps_polylang_required';
		}
		return self::PINNED_POLYLANG === POLYLANG_VERSION ? null : 'lps_polylang_version_mismatch';
	}

	/**
	 * Creates a reciprocal association without copying either locale's editorial fields.
	 *
	 * @param int $portuguese_id Portuguese authority record ID.
	 * @param int $english_id    Independent English variant ID.
	 * @return array{pt-br: int, en: int}|WP_Error
	 */
	public static function associate( int $portuguese_id, int $english_id ): array|WP_Error {
		if ( ! function_exists( 'pll_set_post_language' ) || ! function_exists( 'pll_save_post_translations' ) ) {
			return self::error( 'lps_polylang_required', 'The pinned Polylang plugin must be active.', 'plugin' );
		}
		$portuguese = get_post( $portuguese_id );
		$english    = get_post( $english_id );
		if ( ! $portuguese instanceof WP_Post || ! $english instanceof WP_Post ) {
			return self::error( 'lps_translation_variant_missing', 'Both translation records must exist.', 'translation' );
		}
		if ( $portuguese->post_type !== $english->post_type || ! isset( Contracts::post_types()[ $portuguese->post_type ] ) ) {
			return self::error( 'lps_translation_type_mismatch', 'Translation variants must have the same governed record type.', 'post_type' );
		}
		self::invoke( 'pll_set_post_language', array( $portuguese_id, TranslationPolicy::SOURCE_LOCALE ) );
		self::invoke( 'pll_set_post_language', array( $english_id, TranslationPolicy::TARGET_LOCALE ) );
		$translations      = self::invoke(
			'pll_save_post_translations',
			array(
				array(
					TranslationPolicy::SOURCE_LOCALE => $portuguese_id,
					TranslationPolicy::TARGET_LOCALE => $english_id,
				),
			)
		);
		$stored_portuguese = is_array( $translations ) && is_numeric( $translations[ TranslationPolicy::SOURCE_LOCALE ] ?? null ) ? (int) $translations[ TranslationPolicy::SOURCE_LOCALE ] : 0;
		$stored_english    = is_array( $translations ) && is_numeric( $translations[ TranslationPolicy::TARGET_LOCALE ] ?? null ) ? (int) $translations[ TranslationPolicy::TARGET_LOCALE ] : 0;
		if ( $portuguese_id !== $stored_portuguese || $english_id !== $stored_english ) {
			return self::error( 'lps_translation_association_failed', 'Polylang did not persist a reciprocal association.', 'translation' );
		}
		update_post_meta( $portuguese_id, '_lps_locale', TranslationPolicy::SOURCE_LOCALE );
		update_post_meta( $english_id, '_lps_locale', TranslationPolicy::TARGET_LOCALE );
		self::mark_target_against_source( $portuguese_id, $english_id );
		return array(
			'pt-br' => $portuguese_id,
			'en'    => $english_id,
		);
	}

	/**
	 * Returns reciprocal supported-locale associations.
	 *
	 * @param int $post_id Any associated variant ID.
	 * @return array<string, int>
	 */
	public static function variants( int $post_id ): array {
		if ( ! function_exists( 'pll_get_post_translations' ) ) {
			return array();
		}
		$value = self::invoke( 'pll_get_post_translations', array( $post_id ) );
		if ( ! is_array( $value ) ) {
			return array();
		}
		$result = array();
		foreach ( $value as $locale => $variant_id ) {
			if ( is_string( $locale ) && is_numeric( $variant_id ) && isset( TranslationPolicy::locales()[ $locale ] ) ) {
				$result[ $locale ] = (int) $variant_id;
			}
		}
		return $result;
	}

	/**
	 * Returns the supported locale assigned to a record.
	 *
	 * @param int $post_id Record ID.
	 */
	public static function locale( int $post_id ): string {
		if ( function_exists( 'pll_get_post_language' ) ) {
			$value = self::invoke( 'pll_get_post_language', array( $post_id, 'slug' ) );
			if ( is_string( $value ) && isset( TranslationPolicy::locales()[ $value ] ) ) {
				return $value;
			}
		}
		$value = Policy::scalar_string( get_post_meta( $post_id, '_lps_locale', true ) );
		return isset( TranslationPolicy::locales()[ $value ] ) ? $value : '';
	}

	/**
	 * Returns the Portuguese authority for shared values and relationships.
	 *
	 * @param int $post_id Any associated variant ID.
	 */
	public static function source_id( int $post_id ): ?int {
		$locale = self::locale( $post_id );
		if ( TranslationPolicy::SOURCE_LOCALE === $locale ) {
			return $post_id;
		}
		return TranslationPolicy::fallback_post_id( self::variants( $post_id ), TranslationPolicy::SOURCE_LOCALE );
	}

	/**
	 * Reads canonical relationships from the Portuguese authority.
	 *
	 * @param int    $post_id          Any associated variant ID.
	 * @param string $relationship_type Canonical relationship type.
	 * @return array<int, array<string, mixed>>
	 */
	public static function relationships_for( int $post_id, string $relationship_type ): array {
		$source_id = self::source_id( $post_id );
		return null === $source_id ? array() : Relationships::for_source( $source_id, $relationship_type );
	}

	/**
	 * Merges authority-owned values into validation without duplicate storage.
	 *
	 * @param string               $post_type Governed record type.
	 * @param int                  $post_id   Any associated variant ID.
	 * @param array<string, mixed> $meta      Variant metadata.
	 * @return array<string, mixed>
	 */
	public static function merge_shared_meta( string $post_type, int $post_id, array $meta ): array {
		$source_id = self::source_id( $post_id );
		if ( null === $source_id || $source_id === $post_id ) {
			return $meta;
		}
		foreach ( TranslationPolicy::shared_meta_keys( $post_type ) as $key ) {
			$meta[ $key ] = get_post_meta( $source_id, $key, true );
		}
		return $meta;
	}

	/**
	 * Returns a semantic hash of authority-owned canonical relationships.
	 *
	 * @param int $post_id Any associated variant ID.
	 */
	public static function shared_relationship_hash( int $post_id ): string {
		$source_id = self::source_id( $post_id );
		if ( null === $source_id ) {
			return hash( 'sha256', '[]' );
		}
		$sets = array();
		foreach ( array_keys( RelationshipPolicy::relation_specs() ) as $type ) {
			$rows = Relationships::for_source( $source_id, $type );
			if ( array() !== $rows ) {
				$sets[ $type ] = $rows;
			}
		}
		ksort( $sets, SORT_STRING );
		$encoded = wp_json_encode( $sets, JSON_UNESCAPED_SLASHES );
		return hash( 'sha256', false === $encoded ? '' : $encoded );
	}

	/**
	 * Validates one locale-aware mutation before storage.
	 *
	 * @param string               $post_type       Governed record type.
	 * @param int                  $post_id         Record ID for updates.
	 * @param string               $requested_status Requested WordPress status.
	 * @param array<string, mixed> $incoming        Incoming metadata and locale.
	 */
	public static function validate_request( string $post_type, int $post_id, string $requested_status, array $incoming ): ?WP_Error {
		$locale = Policy::scalar_string( $incoming['_lps_locale'] ?? self::locale( $post_id ) );
		if ( '' === $locale && isset( TranslationPolicy::locales()[ Policy::scalar_string( $incoming['lang'] ?? '' ) ] ) ) {
			$locale = Policy::scalar_string( $incoming['lang'] );
		}
		foreach ( array_keys( $incoming ) as $meta_key ) {
			$write_error = TranslationPolicy::shared_write_error( $locale, (string) $meta_key, $post_type );
			if ( null !== $write_error ) {
				return self::error( $write_error, 'Shared identifiers, dates, and status are owned by the Portuguese authority.', (string) $meta_key );
			}
		}
		if ( 'publish' !== $requested_status ) {
			return null;
		}
		$variants          = self::variants( $post_id );
		$counterpart_id    = TranslationPolicy::SOURCE_LOCALE === $locale ? ( $variants[ TranslationPolicy::TARGET_LOCALE ] ?? 0 ) : ( $variants[ TranslationPolicy::SOURCE_LOCALE ] ?? 0 );
		$counterpart_state = 0 < $counterpart_id ? Policy::scalar_string( get_post_status( $counterpart_id ) ) : null;
		$page_key          = 'page' === $post_type ? Policy::scalar_string( $incoming['_lps_page_key'] ?? get_post_meta( $post_id, '_lps_page_key', true ) ) : '';
		$stale             = TranslationPolicy::TARGET_LOCALE === $locale && 0 < $counterpart_id ? self::is_stale( $post_id ) : null;
		$error             = TranslationPolicy::publish_error( $post_type, $page_key, $locale, $requested_status, $counterpart_state, $stale );
		return null === $error ? null : self::error( $error, 'The bilingual publish contract denied this transition.', 'translation' );
	}

	/**
	 * Returns whether English review trails the current Portuguese source.
	 *
	 * @param int $english_id English variant ID.
	 */
	public static function is_stale( int $english_id ): bool {
		$source_id = self::source_id( $english_id );
		if ( null === $source_id || $source_id === $english_id ) {
			return true;
		}
		return TranslationPolicy::is_stale( Policy::scalar_string( get_post_meta( $english_id, '_lps_reviewed_source_hash', true ) ), self::source_hash( $source_id ) );
	}

	/**
	 * Records a deliberate review against the current Portuguese source.
	 *
	 * @param int $english_id English variant ID.
	 * @param int $reviewer_id Accountable reviewer ID.
	 * @return array{source_id: int, english_id: int, source_revision: int, source_hash: string, relationship_hash: string}|WP_Error
	 */
	public static function review( int $english_id, int $reviewer_id ): array|WP_Error {
		$english = get_post( $english_id );
		if ( ! $english instanceof WP_Post || TranslationPolicy::TARGET_LOCALE !== self::locale( $english_id ) ) {
			return self::error( 'lps_english_variant_required', 'Only an associated English variant can be reviewed.', 'english_id' );
		}
		if ( 0 >= $reviewer_id || ! user_can( $reviewer_id, 'edit_post', $english_id ) ) {
			return self::error( 'lps_translation_review_forbidden', 'The reviewer cannot edit this English variant.', 'reviewer_id', 403 );
		}
		$source_id = self::source_id( $english_id );
		if ( null === $source_id ) {
			return self::error( 'lps_portuguese_source_missing', 'The authoritative Portuguese source is absent.', 'source_id' );
		}
		$hash     = self::source_hash( $source_id );
		$revision = self::source_revision( $source_id );
		update_post_meta( $english_id, '_lps_source_revision', $revision );
		update_post_meta( $english_id, '_lps_source_hash', $hash );
		update_post_meta( $english_id, '_lps_reviewed_source_hash', $hash );
		update_post_meta( $english_id, '_lps_translation_reviewed_at', gmdate( 'c' ) );
		update_post_meta( $english_id, '_lps_translation_reviewer_id', $reviewer_id );
		return array(
			'source_id'         => $source_id,
			'english_id'        => $english_id,
			'source_revision'   => $revision,
			'source_hash'       => $hash,
			'relationship_hash' => self::shared_relationship_hash( $english_id ),
		);
	}

	/**
	 * Atomically synchronizes material values to all already-published associated locales.
	 *
	 * @param int                  $portuguese_id Portuguese authority record ID.
	 * @param array<string, mixed> $values       Complete material value set.
	 * @return array{changed: bool, locales: int}|WP_Error
	 */
	public static function synchronize_material( int $portuguese_id, array $values ): array|WP_Error {
		$post = get_post( $portuguese_id );
		if ( ! $post instanceof WP_Post || TranslationPolicy::SOURCE_LOCALE !== self::locale( $portuguese_id ) ) {
			return self::error( 'lps_portuguese_source_required', 'Material synchronization must start from Portuguese.', 'source_id' );
		}
		$required = TranslationPolicy::material_meta_keys( $post->post_type );
		if ( array() === $required || array_keys( $values ) !== $required ) {
			return self::error( 'lps_material_field_set_incomplete', 'The complete ordered material field set is required.', 'material' );
		}
		$targets = array( $portuguese_id );
		foreach ( self::variants( $portuguese_id ) as $variant_id ) {
			if ( $variant_id !== $portuguese_id && 'publish' === get_post_status( $variant_id ) ) {
				$targets[] = $variant_id;
			}
		}
		global $wpdb;
		/**
		 * WordPress database connection used for the synchronization transaction.
		 *
		 * @var \wpdb $wpdb
		 */
		$started             = false !== $wpdb->query( 'START TRANSACTION' );
		self::$synchronizing = true;
		$changed             = false;
		foreach ( $targets as $target_id ) {
			foreach ( $required as $key ) {
				$changed = (bool) update_post_meta( $target_id, $key, $values[ $key ] ) || $changed;
				if ( '' !== $wpdb->last_error ) {
					if ( $started ) {
						$wpdb->query( 'ROLLBACK' );
					}
					self::$synchronizing = false;
					return self::error( 'lps_material_sync_storage_error', 'The atomic material synchronization failed.', 'material' );
				}
			}
		}
		if ( $started ) {
			$wpdb->query( 'COMMIT' );
		}
		self::$synchronizing = false;
		return array(
			'changed' => $changed,
			'locales' => count( $targets ),
		);
	}

	/**
	 * Rejects direct English writes to shared authority fields.
	 *
	 * @param mixed  $check      Existing metadata short circuit.
	 * @param int    $object_id  Record ID.
	 * @param string $meta_key   Metadata key.
	 * @param mixed  $meta_value Candidate value.
	 * @param mixed  $previous   Previous-value selector.
	 */
	public static function protect_shared_meta( mixed $check, int $object_id, string $meta_key, mixed $meta_value, mixed $previous ): mixed {
		unset( $meta_value, $previous );
		if ( self::$synchronizing ) {
			return $check;
		}
		$post = get_post( $object_id );
		if ( $post instanceof WP_Post && null !== TranslationPolicy::shared_write_error( self::locale( $object_id ), $meta_key, $post->post_type ) ) {
			return false;
		}
		return $check;
	}

	/**
	 * Tracks source changes after WordPress stores post fields.
	 *
	 * @param int          $post_id     Record ID.
	 * @param WP_Post      $post        Stored record.
	 * @param bool         $update      Whether this was an update.
	 * @param WP_Post|null $post_before Previous record.
	 */
	public static function post_saved( int $post_id, WP_Post $post, bool $update, ?WP_Post $post_before ): void {
		unset( $update, $post_before );
		if ( ! isset( Contracts::post_types()[ $post->post_type ] ) || wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}
		$locale = self::locale( $post_id );
		if ( '' !== $locale && get_post_meta( $post_id, '_lps_locale', true ) !== $locale ) {
			update_post_meta( $post_id, '_lps_locale', $locale );
		}
		if ( TranslationPolicy::SOURCE_LOCALE === $locale ) {
			self::refresh_target( $post_id );
		}
	}

	/**
	 * Tracks relevant Portuguese metadata changes after storage.
	 *
	 * @param int    $meta_id    Metadata row ID.
	 * @param int    $post_id    Source record ID.
	 * @param string $meta_key   Updated key.
	 * @param mixed  $meta_value Updated value.
	 */
	public static function source_meta_updated( int $meta_id, int $post_id, string $meta_key, mixed $meta_value ): void {
		unset( $meta_id, $meta_value );
		if ( self::$synchronizing || ! str_starts_with( $meta_key, '_lps_' ) || in_array( $meta_key, array( '_lps_updated_at', '_lps_source_hash' ), true ) || TranslationPolicy::SOURCE_LOCALE !== self::locale( $post_id ) ) {
			return;
		}
		self::refresh_target( $post_id );
	}

	/**
	 * Builds the deterministic missing and stale editor report.
	 *
	 * @return array<int, array{state: string, post_type: string, title: string, source_id: int, english_id: int, source_hash: string}>
	 */
	public static function report(): array {
		$ids  = get_posts(
			array(
				'post_type'      => array_keys( Contracts::post_types() ),
				'post_status'    => array( 'publish', 'draft', 'pending', 'future', 'private' ),
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'lang'           => TranslationPolicy::SOURCE_LOCALE,
			)
		);
		$rows = array();
		foreach ( $ids as $raw_id ) {
			$source_id = (int) $raw_id;
			$source    = get_post( $source_id );
			if ( ! $source instanceof WP_Post || TranslationPolicy::SOURCE_LOCALE !== self::locale( $source_id ) ) {
				continue;
			}
			$variants   = self::variants( $source_id );
			$english_id = $variants[ TranslationPolicy::TARGET_LOCALE ] ?? 0;
			$state      = '';
			if ( 0 === $english_id && TranslationPolicy::requires_english( $source->post_type, Policy::scalar_string( get_post_meta( $source_id, '_lps_page_key', true ) ) ) ) {
				$state = 'missing';
			} elseif ( 0 < $english_id && self::is_stale( $english_id ) ) {
				$state = 'stale';
			}
			if ( '' !== $state ) {
				$rows[] = array(
					'state'       => $state,
					'post_type'   => $source->post_type,
					'title'       => $source->post_title,
					'source_id'   => $source_id,
					'english_id'  => $english_id,
					'source_hash' => self::source_hash( $source_id ),
				);
			}
		}
		/**
		 * Deterministically sorted report rows.
		 *
		 * @var array<int, array{state: string, post_type: string, title: string, source_id: int, english_id: int, source_hash: string}> $sorted
		 */
		$sorted = TranslationPolicy::sort_report( $rows );
		return $sorted;
	}

	/** Registers the editor translation-freshness dashboard. */
	public static function register_dashboard(): void {
		add_management_page( __( 'Translation freshness', 'lps-content-model' ), __( 'Translation freshness', 'lps-content-model' ), 'edit_posts', 'lps-translation-freshness', array( self::class, 'render_dashboard' ) );
	}

	/** Renders the deterministic editor report and review actions. */
	public static function render_dashboard(): void {
		$rows = self::report();
		echo '<div class="wrap"><h1>' . esc_html__( 'Translation freshness', 'lps-content-model' ) . '</h1>';
		echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'State', 'lps-content-model' ) . '</th><th>' . esc_html__( 'Type', 'lps-content-model' ) . '</th><th>' . esc_html__( 'Portuguese source', 'lps-content-model' ) . '</th><th>' . esc_html__( 'Action', 'lps-content-model' ) . '</th></tr></thead><tbody>';
		foreach ( $rows as $row ) {
			echo '<tr><td><code>' . esc_html( $row['state'] ) . '</code></td><td>' . esc_html( $row['post_type'] ) . '</td><td>' . esc_html( $row['title'] ) . ' (#' . esc_html( (string) $row['source_id'] ) . ')</td><td>';
			if ( 'stale' === $row['state'] && 0 < $row['english_id'] ) {
				$url = wp_nonce_url( admin_url( 'admin-post.php?action=lps_review_translation&english_id=' . $row['english_id'] ), self::REVIEW_NONCE_ACTION, self::REVIEW_NONCE_NAME );
				echo '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Confirm reviewed against current Portuguese source', 'lps-content-model' ) . '</a>';
			} else {
				echo esc_html__( 'Create and review an independent English variant.', 'lps-content-model' );
			}
			echo '</td></tr>';
		}
		if ( array() === $rows ) {
			echo '<tr><td colspan="4">' . esc_html__( 'No missing or stale translations.', 'lps-content-model' ) . '</td></tr>';
		}
		echo '</tbody></table></div>';
	}

	/** Handles a nonce-protected reviewed stale-clear action. */
	public static function handle_review(): void {
		if ( ! isset( $_GET[ self::REVIEW_NONCE_NAME ] ) ) {
			wp_die( esc_html__( 'Invalid translation review request.', 'lps-content-model' ), '', array( 'response' => 403 ) );
		}
		$nonce = is_string( $_GET[ self::REVIEW_NONCE_NAME ] ) ? sanitize_text_field( wp_unslash( $_GET[ self::REVIEW_NONCE_NAME ] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, self::REVIEW_NONCE_ACTION ) ) {
			wp_die( esc_html__( 'Invalid translation review request.', 'lps-content-model' ), '', array( 'response' => 403 ) );
		}
		$english_id = isset( $_GET['english_id'] ) && ( is_string( $_GET['english_id'] ) || is_int( $_GET['english_id'] ) ) ? absint( wp_unslash( $_GET['english_id'] ) ) : 0;
		$result     = self::review( $english_id, get_current_user_id() );
		if ( $result instanceof WP_Error ) {
			wp_die( esc_html( $result->get_error_message() ), '', array( 'response' => 400 ) );
		}
		wp_safe_redirect( admin_url( 'tools.php?page=lps-translation-freshness&reviewed=1' ) );
		exit;
	}

	/**
	 * Returns the correct W3C declaration for the current route.
	 *
	 * @param string $output Existing language attributes.
	 */
	public static function language_attributes( string $output ): string {
		$locale = self::current_locale();
		if ( '' === $locale ) {
			return $output;
		}
		$lang = TranslationPolicy::html_lang( $locale );
		return preg_match( '/\blang=(?:"[^"]*"|\'[^\']*\')/', $output ) ? (string) preg_replace( '/\blang=(?:"[^"]*"|\'[^\']*\')/', 'lang="' . $lang . '"', $output, 1 ) : trim( $output . ' lang="' . $lang . '"' );
	}

	/**
	 * Removes alternate links for absent or unpublished variants.
	 *
	 * @param array<string, string> $hreflangs Polylang alternate candidates.
	 * @return array<string, string>
	 */
	public static function published_hreflangs( array $hreflangs ): array {
		if ( ! is_singular() ) {
			return $hreflangs;
		}
		$post_id = get_queried_object_id();
		$real    = array();
		foreach ( self::variants( $post_id ) as $locale => $variant_id ) {
			if ( 'publish' === get_post_status( $variant_id ) ) {
				$url = get_permalink( $variant_id );
				if ( is_string( $url ) ) {
					$real[ TranslationPolicy::html_lang( $locale ) ] = $url;
				}
			}
		}
		return $real;
	}

	/** Converts a resolved wrong-locale singular request into a real localized 404. */
	public static function enforce_requested_singular_locale(): void {
		if ( ! is_singular() || ! isset( $_SERVER['REQUEST_URI'] ) ) {
			return;
		}
		$request_uri = is_string( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '/';
		if ( ! preg_match( '~^/(?P<locale>pt-br|en)(?:/|$)~', $request_uri, $matches ) ) {
			return;
		}
		$post_id = get_queried_object_id();
		if ( 0 >= $post_id || self::locale( $post_id ) === $matches['locale'] ) {
			return;
		}
		global $wp_query;
		if ( $wp_query instanceof \WP_Query ) {
			$wp_query->set_404();
			status_header( 404 );
			nocache_headers();
		}
	}

	/**
	 * Prevents canonical guessing from crossing locale boundaries or self-redirecting.
	 *
	 * @param string|false $redirect_url Proposed canonical destination.
	 * @param string       $requested_url Requested URL.
	 * @return string|false
	 */
	public static function prevent_locale_fallback_redirect( string|false $redirect_url, string $requested_url ): string|false {
		if ( false === $redirect_url ) {
			return false;
		}
		$requested_path = Policy::scalar_string( wp_parse_url( $requested_url, PHP_URL_PATH ) );
		$redirect_path  = Policy::scalar_string( wp_parse_url( $redirect_url, PHP_URL_PATH ) );
		if ( untrailingslashit( $requested_path ) === untrailingslashit( $redirect_path ) ) {
			return false;
		}
		if ( preg_match( '~^/(?P<locale>pt-br|en)(?:/|$)~', $requested_path, $requested ) && preg_match( '~^/(?P<locale>pt-br|en)(?:/|$)~', $redirect_path, $redirect ) && $redirect['locale'] !== $requested['locale'] ) {
			return false;
		}
		return $redirect_url;
	}

	/**
	 * Prevents Polylang canonical guessing from resolving an absent locale variant.
	 *
	 * @param string|false $redirect_url Proposed Polylang canonical URL.
	 * @param mixed        $language     Detected Polylang language object.
	 * @return string|false
	 */
	public static function prevent_polylang_locale_fallback( string|false $redirect_url, mixed $language ): string|false {
		unset( $language );
		$request_uri   = isset( $_SERVER['REQUEST_URI'] ) && is_string( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '/';
		$requested_url = home_url( $request_uri );
		return self::prevent_locale_fallback_redirect( $redirect_url, $requested_url );
	}

	/**
	 * Prevents a non-home absent variant from being redirected as a language home request.
	 *
	 * @param string|false $redirect Proposed localized home URL.
	 * @return string|false
	 */
	public static function prevent_absent_variant_home_redirect( string|false $redirect ): string|false {
		if ( ! isset( $_SERVER['REQUEST_URI'] ) || ! is_string( $_SERVER['REQUEST_URI'] ) ) {
			return $redirect;
		}
		$request_path = Policy::scalar_string( wp_parse_url( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ), PHP_URL_PATH ) );
		return preg_match( '~^/(?:pt-br|en)/.+~', $request_path ) ? false : $redirect;
	}

	/** Returns the current supported Polylang route locale. */
	private static function current_locale(): string {
		if ( function_exists( 'pll_current_language' ) ) {
			$value = self::invoke( 'pll_current_language', array( 'slug' ) );
			if ( is_string( $value ) && isset( TranslationPolicy::locales()[ $value ] ) ) {
				return $value;
			}
		}
		return '';
	}

	/**
	 * Builds the current relevant Portuguese source hash.
	 *
	 * @param int $source_id Portuguese source ID.
	 */
	private static function source_hash( int $source_id ): string {
		$post = get_post( $source_id );
		if ( ! $post instanceof WP_Post ) {
			return '';
		}
		$record = array(
			'post_title'   => $post->post_title,
			'post_excerpt' => $post->post_excerpt,
			'post_content' => $post->post_content,
		);
		foreach ( Contracts::meta_fields()[ $post->post_type ] ?? array() as $key => $definition ) {
			unset( $definition );
			$record[ $key ] = get_post_meta( $source_id, $key, true );
		}
		return TranslationPolicy::source_hash( $post->post_type, $record );
	}

	/**
	 * Returns the newest Portuguese revision or source ID.
	 *
	 * @param int $source_id Portuguese source ID.
	 */
	private static function source_revision( int $source_id ): int {
		$revisions = wp_get_post_revisions(
			$source_id,
			array(
				'posts_per_page' => 1,
				'order'          => 'DESC',
				'orderby'        => 'ID',
			)
		);
		$first     = reset( $revisions );
		return $first instanceof WP_Post ? $first->ID : $source_id;
	}

	/**
	 * Refreshes source observables on an associated English variant.
	 *
	 * @param int $source_id Portuguese source ID.
	 */
	private static function refresh_target( int $source_id ): void {
		$english_id = self::variants( $source_id )[ TranslationPolicy::TARGET_LOCALE ] ?? 0;
		if ( 0 < $english_id ) {
			self::mark_target_against_source( $source_id, $english_id );
		}
	}

	/**
	 * Stores source observables without clearing reviewed state.
	 *
	 * @param int $source_id  Portuguese source ID.
	 * @param int $english_id English variant ID.
	 */
	private static function mark_target_against_source( int $source_id, int $english_id ): void {
		update_post_meta( $english_id, '_lps_source_revision', self::source_revision( $source_id ) );
		update_post_meta( $english_id, '_lps_source_hash', self::source_hash( $source_id ) );
	}

	/**
	 * Calls an optional Polylang public API function.
	 *
	 * @param string            $callback  Public API function name.
	 * @param array<int, mixed> $arguments Ordered arguments.
	 */
	private static function invoke( string $callback, array $arguments ): mixed {
		if ( ! is_callable( $callback ) ) {
			return null;
		}
		return call_user_func_array( $callback, $arguments );
	}

	/**
	 * Builds a typed translation-boundary error.
	 *
	 * @param string $code    Stable error code.
	 * @param string $message Human-readable message.
	 * @param string $field   Machine field.
	 * @param int    $status  HTTP status.
	 */
	private static function error( string $code, string $message, string $field, int $status = 400 ): WP_Error {
		return new WP_Error(
			$code,
			$message,
			array(
				'status' => $status,
				'field'  => $field,
			)
		);
	}
}
