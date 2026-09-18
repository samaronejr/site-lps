<?php
/**
 * Portable bilingual publishing and freshness policy.
 *
 * @package LPS\ContentModel
 */

declare(strict_types=1);

namespace LPS\ContentModel;

require_once __DIR__ . '/class-teachingcontracts.php';

/** Defines locale, authority, freshness, shared-data, and reporting rules without WordPress state. */
final class TranslationPolicy {
	public const SOURCE_LOCALE = 'pt-br';
	public const TARGET_LOCALE = 'en';

	/**
	 * Returns the exact supported locale contract.
	 *
	 * @return array<string, array{locale: string, w3c: string, name: string}>
	 */
	public static function locales(): array {
		return array(
			'pt-br' => array(
				'locale' => 'pt_BR',
				'w3c'    => 'pt-BR',
				'name'   => 'Portugues do Brasil',
			),
			'en'    => array(
				'locale' => 'en_US',
				'w3c'    => 'en',
				'name'   => 'English',
			),
		);
	}

	/**
	 * Returns mandatory Polylang route options.
	 *
	 * @return array{force_lang: int, hide_default: int, rewrite: int, default_lang: string, browser: int}
	 */
	public static function route_options(): array {
		return array(
			'force_lang'   => 1,
			'hide_default' => 0,
			'rewrite'      => 1,
			'default_lang' => self::SOURCE_LOCALE,
			'browser'      => 0,
		);
	}

	/**
	 * Checks whether a public path starts with a supported locale.
	 *
	 * @param string $path Public request path.
	 */
	public static function route_is_locale_prefixed( string $path ): bool {
		return 1 === preg_match( '~^/(?:pt-br|en)(?:/|$)~', $path );
	}

	/**
	 * Checks the governed required-English matrix.
	 *
	 * @param string $post_type Governed record type.
	 * @param string $page_key  Stable page key when applicable.
	 */
	public static function requires_english( string $post_type, string $page_key = '' ): bool {
		if ( 'page' === $post_type ) {
			return in_array( $page_key, array( 'home', 'about', 'research', 'projects', 'people', 'publications', 'infrastructure', 'opportunities', 'collaboration', 'contact', 'privacy', 'accessibility' ), true );
		}
		return in_array( $post_type, array( 'lps_person', 'lps_research_area', 'lps_project', 'lps_publication', 'lps_opportunity', 'lps_course', 'lps_offering' ), true );
	}

	/**
	 * Returns Portuguese-authoritative metadata keys.
	 *
	 * @param string $post_type Governed record type.
	 * @return array<int, string>
	 */
	public static function shared_meta_keys( string $post_type ): array {
		$common  = array( '_lps_owner_user_id', '_lps_review_date' );
		$by_type = array(
			'lps_person'        => array( '_lps_canonical_name', '_lps_sort_name', '_lps_person_status', '_lps_roles', '_lps_affiliations', '_lps_start_date', '_lps_end_date', '_lps_public_email', '_lps_orcid', '_lps_lattes_url', '_lps_scholar_url', '_lps_website_url', '_lps_credentials', '_lps_photo_rights', '_lps_privacy_reviewed' ),
			'lps_organization'  => array( '_lps_organization_name', '_lps_acronym', '_lps_organization_kind', '_lps_country_code', '_lps_canonical_url', '_lps_ror_id', '_lps_logo_asset_id', '_lps_public_profile' ),
			'lps_research_area' => array( '_lps_stable_key', '_lps_sort_order' ),
			'lps_project'       => array( '_lps_record_id', '_lps_project_status', '_lps_start_date', '_lps_end_date', '_lps_grant_ids', '_lps_asset_ids', '_lps_links' ),
			'lps_publication'   => array( '_lps_authoritative_title', '_lps_publication_type', '_lps_publication_status', '_lps_language', '_lps_publication_date', '_lps_date_precision', '_lps_doi', '_lps_isbn', '_lps_issn', '_lps_arxiv_id', '_lps_venue', '_lps_citation', '_lps_license', '_lps_canonical_url', '_lps_open_access_url', '_lps_pdf_url', '_lps_code_url', '_lps_data_url' ),
			'lps_news'          => array( '_lps_canonical_date', '_lps_news_status', '_lps_featured_until' ),
			'lps_opportunity'   => array( '_lps_opportunity_type', '_lps_audiences', '_lps_opens_at', '_lps_closes_at', '_lps_positions', '_lps_project_ids', '_lps_supervisor_ids', '_lps_funder_ids', '_lps_mode' ),
			'lps_event'         => array( '_lps_starts_at', '_lps_ends_at', '_lps_event_status', '_lps_online_url', '_lps_registration_url', '_lps_recording_url' ),
		);
		return array_values( array_unique( array_merge( $common, $by_type[ $post_type ] ?? array(), TeachingContracts::shared_specific_keys( $post_type ) ) ) );
	}

	/**
	 * Returns material fields requiring atomic synchronization.
	 *
	 * @param string $post_type Governed record type.
	 * @return array<int, string>
	 */
	public static function material_meta_keys( string $post_type ): array {
		return match ( $post_type ) {
			'lps_opportunity' => array( '_lps_opens_at', '_lps_closes_at', '_lps_opportunity_type' ),
			'lps_event' => array( '_lps_starts_at', '_lps_ends_at', '_lps_event_status' ),
			'lps_term' => array( '_lps_starts_on', '_lps_ends_on' ),
			'lps_offering' => array( '_lps_cancelled', '_lps_schedule' ),
			'lps_resource' => array( '_lps_release_state', '_lps_release_at', '_lps_withdrawn_at', '_lps_version_id', '_lps_external_url' ),
			default => array(),
		};
	}

	/**
	 * Returns a typed error for an English authority-field write.
	 *
	 * @param string $locale    Variant locale.
	 * @param string $meta_key  Candidate metadata key.
	 * @param string $post_type Governed record type.
	 */
	public static function shared_write_error( string $locale, string $meta_key, string $post_type ): ?string {
		return self::TARGET_LOCALE === $locale && in_array( $meta_key, self::shared_meta_keys( $post_type ), true ) ? 'lps_shared_field_portuguese_authority' : null;
	}

	/**
	 * Hashes only editor-owned, translatable Portuguese source fields.
	 *
	 * Staleness is field-specific. For teaching record types the hash covers
	 * exactly the localized (per-variant editorial) fields declared by
	 * `TeachingContracts::field_ownership()`: a shared schedule, term boundary,
	 * release state, or file/version identifier never forces a meaningless
	 * re-translation, and authored-language resources never require translated
	 * file bytes. For the pre-existing record types the hash keeps its
	 * established coverage — every non-shared, non-system `_lps_` field — so
	 * reviewed hashes already stored for those records stay valid.
	 *
	 * @param string               $post_type Governed record type.
	 * @param array<string, mixed> $record    Source record.
	 */
	public static function source_hash( string $post_type, array $record ): string {
		$relevant = array();
		foreach ( array( 'post_title', 'post_excerpt', 'post_content' ) as $field ) {
			$relevant[ $field ] = self::normalize_hash_value( $record[ $field ] ?? '' );
		}
		if ( in_array( $post_type, TeachingContracts::POST_TYPES, true ) ) {
			foreach ( TeachingContracts::localized_meta_keys( $post_type ) as $key ) {
				$relevant[ $key ] = self::normalize_hash_value( $record[ $key ] ?? '' );
			}
		} else {
			$shared = self::shared_meta_keys( $post_type );
			foreach ( $record as $key => $value ) {
				if ( ! str_starts_with( $key, '_lps_' ) || in_array( $key, $shared, true ) || in_array( $key, array( '_lps_origin', '_lps_locale', '_lps_state', '_lps_created_at', '_lps_updated_at', '_lps_archived_at', '_lps_published_slug', '_lps_source_revision', '_lps_source_hash', '_lps_reviewed_source_hash', '_lps_translation_reviewed_at' ), true ) ) {
					continue;
				}
				$relevant[ $key ] = self::normalize_hash_value( $value );
			}
		}
		ksort( $relevant, SORT_STRING );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Portable policy is loaded without WordPress by unit consumers.
		$encoded = json_encode( $relevant, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		return hash( 'sha256', false === $encoded ? '' : $encoded );
	}

	/**
	 * Compares reviewed and current authority hashes.
	 *
	 * @param string $reviewed_source_hash Last reviewed hash.
	 * @param string $current_source_hash  Current source hash.
	 */
	public static function is_stale( string $reviewed_source_hash, string $current_source_hash ): bool {
		return '' === $reviewed_source_hash || '' === $current_source_hash || ! hash_equals( $reviewed_source_hash, $current_source_hash );
	}

	/**
	 * Returns the first deterministic bilingual publish denial code.
	 *
	 * @param string      $post_type            Governed record type.
	 * @param string      $page_key             Stable page key.
	 * @param string      $locale               Candidate locale.
	 * @param string      $requested_status     Candidate status.
	 * @param string|null $counterpart_status Counterpart status when present.
	 * @param bool|null   $material_synchronized Synchronization or stale signal.
	 */
	public static function publish_error( string $post_type, string $page_key, string $locale, string $requested_status, ?string $counterpart_status, ?bool $material_synchronized ): ?string {
		if ( 'publish' !== $requested_status ) {
			return null;
		}
		if ( self::SOURCE_LOCALE === $locale && self::requires_english( $post_type, $page_key ) ) {
			if ( null === $counterpart_status ) {
				return 'lps_required_english_variant_missing';
			}
			if ( 'publish' !== $counterpart_status ) {
				return 'lps_required_english_variant_unpublished';
			}
		}
		if ( self::TARGET_LOCALE === $locale && true === $material_synchronized ) {
			return 'lps_english_source_review_required';
		}
		if ( self::SOURCE_LOCALE === $locale && 'publish' === $counterpart_status && false === $material_synchronized && array() !== self::material_meta_keys( $post_type ) ) {
			return 'lps_material_translation_sync_required';
		}
		return null;
	}

	/**
	 * Resolves only a real requested variant, never another-language fallback.
	 *
	 * @param array<string, int> $translations Reciprocal association.
	 * @param string             $locale       Requested locale.
	 */
	public static function fallback_post_id( array $translations, string $locale ): ?int {
		$value = $translations[ $locale ] ?? 0;
		return 0 < $value ? $value : null;
	}

	/**
	 * Sorts dashboard rows deterministically.
	 *
	 * @param array<int, array{state: string, post_type: string, title: string, source_id: int}> $rows Unsorted rows.
	 * @return array<int, array{state: string, post_type: string, title: string, source_id: int}>
	 */
	public static function sort_report( array $rows ): array {
		usort(
			$rows,
			static function ( array $left, array $right ): int {
				$rank = array(
					'missing' => 0,
					'stale'   => 1,
				);
				return array( $rank[ $left['state'] ] ?? 2, $left['post_type'], strtolower( $left['title'] ), $left['source_id'] ) <=> array( $rank[ $right['state'] ] ?? 2, $right['post_type'], strtolower( $right['title'] ), $right['source_id'] );
			}
		);
		return $rows;
	}

	/**
	 * Builds alternate links only for real published variants.
	 *
	 * @param array<string, array{status: string, url: string}> $variants Variant states.
	 * @return array<string, string>
	 */
	public static function hreflangs( array $variants ): array {
		$result = array();
		foreach ( self::locales() as $slug => $locale ) {
			if ( isset( $variants[ $slug ] ) && 'publish' === $variants[ $slug ]['status'] && '' !== $variants[ $slug ]['url'] ) {
				$result[ $locale['w3c'] ] = $variants[ $slug ]['url'];
			}
		}
		return $result;
	}

	/**
	 * Returns a valid W3C HTML language declaration.
	 *
	 * @param string $locale Supported locale slug.
	 */
	public static function html_lang( string $locale ): string {
		return self::locales()[ $locale ]['w3c'] ?? self::locales()[ self::SOURCE_LOCALE ]['w3c'];
	}

	/**
	 * Normalizes one source value for deterministic hashing.
	 *
	 * @param mixed $value Source value.
	 */
	private static function normalize_hash_value( mixed $value ): mixed {
		if ( is_array( $value ) ) {
			$result = array_map( array( self::class, 'normalize_hash_value' ), $value );
			if ( ! array_is_list( $result ) ) {
				ksort( $result, SORT_STRING );
			}
			return $result;
		}
		if ( is_bool( $value ) || is_int( $value ) || is_float( $value ) ) {
			return $value;
		}
		if ( is_string( $value ) || null === $value ) {
			return trim( preg_replace( '/\s+/u', ' ', $value ?? '' ) ?? '' );
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Portable policy is loaded without WordPress by unit consumers.
		$encoded = json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		return false === $encoded ? '' : $encoded;
	}
}
