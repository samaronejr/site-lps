<?php
/**
 * Teaching persistence service: canonical writes, uniqueness claims, history and reconciliation.
 *
 * @package LPS\ContentModel
 */

declare(strict_types=1);

namespace LPS\ContentModel;

use WP_Error;
use WP_Post;
use wpdb;

require_once __DIR__ . '/class-policy.php';
require_once __DIR__ . '/class-contracts.php';
require_once __DIR__ . '/class-relationshippolicy.php';
require_once __DIR__ . '/class-relationships.php';
require_once __DIR__ . '/class-teachingcontracts.php';
require_once __DIR__ . '/class-teachingmigrations.php';
require_once __DIR__ . '/class-translationpolicy.php';
require_once __DIR__ . '/class-translations.php';
require_once __DIR__ . '/class-publicationpolicy.php';
require_once __DIR__ . '/class-publicationrecords.php';

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Atomic uniqueness claims and fresh reconciliation reads must bypass object caches.
/**
 * Owns the writable boundary for teaching records and their uniqueness claims.
 *
 * Every teaching create flows through this service: the record is inserted as
 * a draft, canonical relationships are written, and the identity claim is
 * inserted into the indexed registry inside the same boundary. A duplicate
 * claim is a meaningful conflict — the loser leaves no post, no relationship
 * rows, and no registry row behind. Translated record IDs always resolve to
 * the Portuguese authority before hashing, so an English variant can never
 * mint a second uniqueness subject.
 */
final class TeachingRecords {
	/** Metadata keys a create request may write on the Portuguese authority. */
	private const AUTHORITY_META = array(
		'lps_course'   => array( '_lps_course_code', '_lps_course_level', '_lps_calendar_key', '_lps_program', '_lps_prerequisites', '_lps_syllabus' ),
		'lps_term'     => array( '_lps_calendar_key', '_lps_term_code', '_lps_period_label', '_lps_period_type', '_lps_starts_on', '_lps_ends_on', '_lps_term_source' ),
		'lps_offering' => array( '_lps_section_key', '_lps_schedule', '_lps_venue', '_lps_syllabus_snapshot', '_lps_lms_url', '_lps_lms_url_approved', '_lps_cancelled' ),
		'lps_unit'     => array( '_lps_anchor', '_lps_position', '_lps_topic_date' ),
	);

	/** Localized metadata keys an English variant may own at creation. */
	private const VARIANT_META = array(
		'lps_course'   => array( '_lps_prerequisites', '_lps_syllabus' ),
		'lps_offering' => array( '_lps_syllabus_snapshot' ),
	);

	/** Registers the dated-status refresh hooks. */
	public static function boot(): void {
		add_action( 'save_post', array( self::class, 'refresh_temporal_status' ), 20 );
	}

	/**
	 * Creates one course through the canonical boundary.
	 *
	 * @param array<string, mixed> $input Boundary input.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function create_course( array $input ): array|WP_Error {
		$locale         = self::locale_input( $input );
		$translation_of = Policy::sanitize_integer( $input['translation_of'] ?? 0 );
		if ( TranslationPolicy::TARGET_LOCALE === $locale || 0 < $translation_of ) {
			return self::create_variant( 'lps_course', $input, $translation_of );
		}
		if ( TranslationPolicy::SOURCE_LOCALE !== $locale ) {
			return self::error( 'lps_teaching_locale_forbidden', 'Teaching records are authored on the Portuguese authority.', 'locale' );
		}
		$post_id = self::insert_record( 'lps_course', $input, TranslationPolicy::SOURCE_LOCALE, self::AUTHORITY_META['lps_course'] );
		if ( $post_id instanceof WP_Error ) {
			return $post_id;
		}
		return self::finish_create( $post_id, $input );
	}

	/**
	 * Creates one academic term and claims its calendar identity atomically.
	 *
	 * Terms are authority-only records: they are never translated, so an
	 * English or translated create is rejected before any identity is minted.
	 *
	 * @param array<string, mixed> $input Boundary input.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function create_term( array $input ): array|WP_Error {
		$locale = self::locale_input( $input );
		if ( TranslationPolicy::SOURCE_LOCALE !== $locale || 0 < Policy::sanitize_integer( $input['translation_of'] ?? 0 ) ) {
			return self::error( 'lps_teaching_locale_forbidden', 'Terms exist only on the Portuguese authority.', 'locale' );
		}
		$identity = TeachingContracts::term_identity( $input['calendar_key'] ?? '', $input['term_code'] ?? '' );
		if ( '' === $identity['token'] ) {
			return self::error( 'lps_teaching_term_identity_required', 'A calendar key and term code are required.', 'calendar_key' );
		}
		$meta_input = is_array( $input['meta'] ?? null ) ? $input['meta'] : array();
		$date_error = TeachingContracts::date_order_error(
			$input['starts_on'] ?? ( $meta_input['_lps_starts_on'] ?? '' ),
			$input['ends_on'] ?? ( $meta_input['_lps_ends_on'] ?? '' )
		);
		if ( null !== $date_error ) {
			return self::error( $date_error, 'The term boundaries are invalid.', 'starts_on' );
		}
		$existing = self::term_claim( TeachingContracts::term_identity_hash( $identity ) );
		if ( null !== $existing ) {
			return self::conflict_error( 'term', $existing );
		}
		$meta                      = self::sanitize_meta( 'lps_term', self::meta_input( $input, self::AUTHORITY_META['lps_term'] ), self::AUTHORITY_META['lps_term'] );
		$meta['_lps_starts_on']    = TeachingContracts::normalize_iso_date( $input['starts_on'] ?? ( $meta_input['_lps_starts_on'] ?? '' ) );
		$meta['_lps_ends_on']      = TeachingContracts::normalize_iso_date( $input['ends_on'] ?? ( $meta_input['_lps_ends_on'] ?? '' ) );
		$meta['_lps_calendar_key'] = $identity['calendar_key'];
		$meta['_lps_term_code']    = $identity['term_code'];
		$meta['_lps_term_token']   = $identity['token'];
		$post_id                   = self::insert_record( 'lps_term', $input, TranslationPolicy::SOURCE_LOCALE, array_keys( $meta ), $meta );
		if ( $post_id instanceof WP_Error ) {
			return $post_id;
		}
		$claim = self::claim_term( $post_id, $identity );
		if ( null !== $claim ) {
			self::cleanup_record( $post_id, true );
			return $claim;
		}
		return self::finish_create( $post_id, $input );
	}

	/**
	 * Creates one offering and claims its course/term/section identity atomically.
	 *
	 * The claim is the last write of the boundary: when two concurrent creates
	 * race, exactly one registry insert succeeds and the loser receives a 409
	 * conflict carrying the winning record ID while its draft, relationship
	 * rows, and claim are removed.
	 *
	 * @param array<string, mixed> $input Boundary input.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function create_offering( array $input ): array|WP_Error {
		$locale         = self::locale_input( $input );
		$translation_of = Policy::sanitize_integer( $input['translation_of'] ?? 0 );
		if ( TranslationPolicy::TARGET_LOCALE === $locale || 0 < $translation_of ) {
			return self::create_variant( 'lps_offering', $input, $translation_of );
		}
		if ( TranslationPolicy::SOURCE_LOCALE !== $locale ) {
			return self::error( 'lps_teaching_locale_forbidden', 'Offering identities are authored on the Portuguese authority.', 'locale' );
		}
		$course_id = self::authority_id( Policy::sanitize_integer( $input['course_id'] ?? 0 ) );
		$term_id   = self::authority_id( Policy::sanitize_integer( $input['term_id'] ?? 0 ) );
		$course    = 0 < $course_id ? get_post( $course_id ) : null;
		if ( ! $course instanceof WP_Post || 'lps_course' !== $course->post_type || 'trash' === $course->post_status ) {
			return self::error( 'lps_teaching_course_invalid', 'The offering requires an existing course record.', 'course_id' );
		}
		$term = 0 < $term_id ? get_post( $term_id ) : null;
		if ( ! $term instanceof WP_Post || 'lps_term' !== $term->post_type || 'trash' === $term->post_status ) {
			return self::error( 'lps_teaching_term_invalid', 'The offering requires an existing term record.', 'term_id' );
		}
		$section = TeachingContracts::normalize_section_key( $input['section'] ?? ( $input['section_key'] ?? '' ) );
		if ( '' === $section ) {
			return self::error( 'lps_required_section_key', 'A section key is required.', 'section' );
		}
		$identity = TeachingContracts::offering_identity( $course_id, $term_id, $section );
		$existing = self::offering_claim( TeachingContracts::offering_identity_hash( $identity ) );
		if ( null !== $existing ) {
			return self::conflict_error( 'offering', $existing );
		}
		$team = self::normalize_team( $input['team'] ?? array() );
		if ( $team instanceof WP_Error ) {
			return $team;
		}
		$input['section_key'] = $section;
		$post_id              = self::insert_record( 'lps_offering', $input, TranslationPolicy::SOURCE_LOCALE, self::AUTHORITY_META['lps_offering'] );
		if ( $post_id instanceof WP_Error ) {
			return $post_id;
		}
		foreach ( array(
			'offering_course' => array( self::relationship_row( $course_id, 'instance-of', 1 ) ),
			'offering_term'   => array( self::relationship_row( $term_id, 'scheduled-in', 1 ) ),
			'teaching_team'   => $team,
		) as $relationship_type => $rows ) {
			$written = Relationships::replace( $post_id, $relationship_type, $rows );
			if ( $written instanceof WP_Error ) {
				self::cleanup_record( $post_id, true );
				return $written;
			}
		}
		$claim = self::claim_offering( $post_id, $identity );
		if ( null !== $claim ) {
			self::cleanup_record( $post_id, true );
			return $claim;
		}
		self::refresh_temporal_status( $post_id );
		return self::finish_create( $post_id, $input );
	}

	/**
	 * Creates one teaching unit attached to an existing offering.
	 *
	 * @param array<string, mixed> $input Boundary input.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function create_unit( array $input ): array|WP_Error {
		$locale = self::locale_input( $input );
		if ( TranslationPolicy::SOURCE_LOCALE !== $locale || 0 < Policy::sanitize_integer( $input['translation_of'] ?? 0 ) ) {
			return self::error( 'lps_teaching_locale_forbidden', 'Units exist only on the Portuguese authority.', 'locale' );
		}
		$offering_id = self::authority_id( Policy::sanitize_integer( $input['offering_id'] ?? 0 ) );
		$offering    = 0 < $offering_id ? get_post( $offering_id ) : null;
		if ( ! $offering instanceof WP_Post || 'lps_offering' !== $offering->post_type || 'trash' === $offering->post_status ) {
			return self::error( 'lps_teaching_offering_invalid', 'The unit requires an existing offering record.', 'offering_id' );
		}
		$post_id = self::insert_record( 'lps_unit', $input, TranslationPolicy::SOURCE_LOCALE, self::AUTHORITY_META['lps_unit'] );
		if ( $post_id instanceof WP_Error ) {
			return $post_id;
		}
		$written = Relationships::replace( $post_id, 'unit_offering', array( self::relationship_row( $offering_id, 'part-of', 1 ) ) );
		if ( $written instanceof WP_Error ) {
			self::cleanup_record( $post_id, true );
			return $written;
		}
		return self::finish_create( $post_id, $input );
	}

	/**
	 * Publishes one stored teaching record through the full server-side gate.
	 *
	 * The same checks the insert/update filters enforce run here first so the
	 * boundary returns the complete typed error set instead of a bare status.
	 *
	 * @param int $post_id Record database ID.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function publish_record( int $post_id ): array|WP_Error {
		$post = get_post( $post_id );
		if ( ! $post instanceof WP_Post || ! isset( Contracts::post_types()[ $post->post_type ] ) ) {
			return self::error( 'lps_teaching_record_invalid', 'The record does not exist or is not governed.', 'id', 404 );
		}
		$stored_state = Policy::scalar_string( get_post_meta( $post_id, '_lps_state', true ) );
		$stored_state = '' === $stored_state ? 'draft' : $stored_state;
		if ( ! Policy::valid_transition( $stored_state, 'published' ) ) {
			return self::error( 'lps_invalid_state_transition', 'The stored editorial state cannot transition to published.', '_lps_state' );
		}
		$meta              = self::stored_meta( $post->post_type, $post_id );
		$meta              = Translations::merge_shared_meta( $post->post_type, $post_id, $meta );
		$errors            = array_merge(
			Policy::publish_errors(
				$post->post_type,
				array_merge(
					$meta,
					array(
						'post_title'   => $post->post_title,
						'post_excerpt' => $post->post_excerpt,
						'post_content' => $post->post_content,
					)
				)
			),
			Relationships::publish_errors( $post->post_type, Translations::source_id( $post_id ) ?? $post_id ),
			TeachingContracts::publish_errors( $post->post_type, $meta )
		);
		$translation_error = Translations::validate_request( $post->post_type, $post_id, 'publish', array( '_lps_locale' => Translations::locale( $post_id ) ) );
		if ( $translation_error instanceof WP_Error ) {
			$errors['translation'] = (string) $translation_error->get_error_code();
		}
		$origin_error = PublicationPolicy::origin_consistency_error( $meta );
		if ( null !== $origin_error ) {
			$errors['_lps_origin'] = $origin_error;
		}
		if ( array() !== $errors ) {
			return self::error(
				'lps_teaching_publish_denied',
				'The record does not satisfy the server-side publish contract.',
				'record',
				400,
				array(
					'errors'    => $errors,
					'record_id' => $post_id,
				)
			);
		}
		$updated = wp_update_post(
			array(
				'ID'          => $post_id,
				'post_status' => 'publish',
			),
			true
		);
		if ( $updated instanceof WP_Error ) {
			return $updated;
		}
		if ( 'publish' !== get_post_status( $post_id ) ) {
			return self::error( 'lps_teaching_publish_denied', 'The publish gate kept the record unpublished.', 'record', 400, array( 'record_id' => $post_id ) );
		}
		return self::record_response( $post_id );
	}

	/**
	 * Records a deliberate English-variant review against the current source.
	 *
	 * @param int $post_id     English variant record ID.
	 * @param int $reviewer_id Accountable reviewer ID.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function review_translation( int $post_id, int $reviewer_id ): array|WP_Error {
		return Translations::review( $post_id, $reviewer_id );
	}

	/**
	 * Returns the derived teaching history of one person in one locale.
	 *
	 * Reverse lookups are always derived from canonical forward rows: the
	 * `teaching_team` rows stored on each offering are read backwards to the
	 * person, so both co-teachers appear without any duplicated reverse data.
	 *
	 * @param int    $person_id Any associated person variant ID.
	 * @param string $locale    Supported locale slug.
	 * @param string $context   `view` (public rows only) or `edit` (full rows).
	 * @return array<string, mixed>|WP_Error
	 */
	public static function person_history( int $person_id, string $locale, string $context = 'view' ): array|WP_Error {
		$locale  = isset( TranslationPolicy::locales()[ $locale ] ) ? $locale : TranslationPolicy::SOURCE_LOCALE;
		$context = 'edit' === $context ? 'edit' : 'view';
		$person  = 0 < $person_id ? get_post( $person_id ) : null;
		if ( ! $person instanceof WP_Post || 'lps_person' !== $person->post_type ) {
			return self::error( 'lps_teaching_person_required', 'The teaching history requires an existing person record.', 'id', 404 );
		}
		$authority = self::authority_id( $person->ID );
		$entries   = array();
		foreach ( Relationships::reverse_for( $authority, 'teaching_team' ) as $row ) {
			if ( 'edit' !== $context && ! $row['public_visibility'] ) {
				continue;
			}
			$offering_authority = $row['source_post_id'];
			$variant_id         = TranslationPolicy::TARGET_LOCALE === $locale
				? Policy::sanitize_integer( Translations::variants( $offering_authority )[ TranslationPolicy::TARGET_LOCALE ] ?? 0 )
				: $offering_authority;
			$offering           = 0 < $variant_id ? get_post( $variant_id ) : null;
			if ( ! $offering instanceof WP_Post ) {
				continue;
			}
			if ( 'edit' !== $context && 'publish' !== $offering->post_status ) {
				continue;
			}
			$entries[] = self::history_entry( $row, $offering, $offering_authority, $locale );
		}
		$entries = self::sort_history( $entries );
		return array(
			'person_id'    => $person->ID,
			'authority_id' => $authority,
			'locale'       => $locale,
			'context'      => $context,
			'entries'      => $entries,
		);
	}

	/**
	 * Sorts history entries deterministically: newest term first, then title.
	 *
	 * @param array<int, array<string, mixed>> $entries History entries.
	 * @return array<int, array<string, mixed>>
	 */
	public static function sort_history( array $entries ): array {
		usort(
			$entries,
			static function ( array $left, array $right ): int {
				$left_term  = is_array( $left['term'] ?? null ) ? $left['term'] : array();
				$right_term = is_array( $right['term'] ?? null ) ? $right['term'] : array();
				$by_term    = Policy::scalar_string( $right_term['starts_on'] ?? '' ) <=> Policy::scalar_string( $left_term['starts_on'] ?? '' );
				if ( 0 !== $by_term ) {
					return $by_term;
				}
				return strcasecmp( Policy::scalar_string( $left['title'] ?? '' ), Policy::scalar_string( $right['title'] ?? '' ) );
			}
		);
		return $entries;
	}

	/**
	 * Classifies registry claims against the posts they point at.
	 *
	 * Pure reconciliation: a claim whose post is gone or trashed is an
	 * `orphaned_claim`; a claim whose stored identity no longer matches the
	 * post's canonical identity is an `identity_mismatch`.
	 *
	 * @param array<int, array<mixed>>                             $claims              Registry rows.
	 * @param array<int, array{exists: bool, post_status: string}> $posts    Post states keyed by ID.
	 * @param array<int, string>                                   $expected_hashes     Expected identity hashes keyed by post ID.
	 * @param string                                               $kind                `term` or `offering`.
	 * @return array<int, array{code: string, claim_post_id: int, detail: string}>
	 */
	public static function classify_registry_claims( array $claims, array $posts, array $expected_hashes, string $kind ): array {
		$issues = array();
		$id_key = 'term' === $kind ? 'term_post_id' : 'offering_post_id';
		$prefix = 'term' === $kind ? 'lps_reconcile_term' : 'lps_reconcile_offering';
		foreach ( $claims as $claim ) {
			$post_id = Policy::sanitize_integer( $claim[ $id_key ] ?? 0 );
			$post    = $posts[ $post_id ] ?? null;
			if ( ! is_array( $post ) || ! $post['exists'] || 'trash' === $post['post_status'] ) {
				$issues[] = array(
					'code'          => $prefix . '_orphaned_claim',
					'claim_post_id' => $post_id,
					'detail'        => 'The registry claim points at a missing or trashed record.',
				);
				continue;
			}
			$expected = Policy::scalar_string( $expected_hashes[ $post_id ] ?? '' );
			$stored   = Policy::scalar_string( $claim['identity_hash'] ?? '' );
			if ( '' !== $expected && '' !== $stored && ! hash_equals( $expected, $stored ) ) {
				$issues[] = array(
					'code'          => $prefix . '_identity_mismatch',
					'claim_post_id' => $post_id,
					'detail'        => 'The stored identity no longer matches the canonical record identity.',
				);
			}
		}
		return $issues;
	}

	/**
	 * Returns the governed record IDs that hold no registry claim.
	 *
	 * @param array<int, int> $post_ids    Candidate record IDs.
	 * @param array<int, int> $claimed_ids Claimed record IDs.
	 * @return array<int, int>
	 */
	public static function unclaimed_ids( array $post_ids, array $claimed_ids ): array {
		$claimed = array_fill_keys( array_map( array( Policy::class, 'sanitize_integer' ), $claimed_ids ), true );
		$missing = array();
		foreach ( $post_ids as $post_id ) {
			$post_id = Policy::sanitize_integer( $post_id );
			if ( 0 < $post_id && ! isset( $claimed[ $post_id ] ) ) {
				$missing[] = $post_id;
			}
		}
		sort( $missing );
		return $missing;
	}

	/**
	 * Builds the report-driven reconciliation receipt for the teaching store.
	 *
	 * The report is a review queue, never an automatic repair: orphaned claims,
	 * identity mismatches, and unclaimed records are listed for editorial
	 * reconciliation alongside the ambiguous-origin queue.
	 *
	 * @return array<string, mixed>
	 */
	public static function reconciliation_report(): array {
		$wpdb            = self::database();
		$tables          = TeachingMigrations::table_names( $wpdb );
		$term_claims     = self::table_exists( $tables['term_registry'], $wpdb )
			? $wpdb->get_results( $wpdb->prepare( 'SELECT term_post_id, calendar_key, term_code, term_token, identity_hash FROM %i ORDER BY term_post_id ASC', $tables['term_registry'] ), 'ARRAY_A' )
			: array();
		$offering_claims = self::table_exists( $tables['offering_registry'], $wpdb )
			? $wpdb->get_results( $wpdb->prepare( 'SELECT offering_post_id, course_post_id, term_post_id, section_key, identity_hash FROM %i ORDER BY offering_post_id ASC', $tables['offering_registry'] ), 'ARRAY_A' )
			: array();
		$term_claims     = is_array( $term_claims ) ? $term_claims : array();
		$offering_claims = is_array( $offering_claims ) ? $offering_claims : array();

		$post_ids = array();
		foreach ( array_merge( $term_claims, $offering_claims ) as $claim ) {
			foreach ( $claim as $key => $value ) {
				if ( str_ends_with( (string) $key, '_post_id' ) ) {
					$post_ids[] = Policy::sanitize_integer( $value );
				}
			}
		}
		$posts = array();
		foreach ( array_unique( $post_ids ) as $post_id ) {
			$post              = get_post( $post_id );
			$posts[ $post_id ] = array(
				'exists'      => $post instanceof WP_Post,
				'post_status' => $post instanceof WP_Post ? $post->post_status : '',
			);
		}

		$term_hashes = array();
		foreach ( $term_claims as $claim ) {
			$term_id = Policy::sanitize_integer( $claim['term_post_id'] ?? 0 );
			if ( 0 < $term_id && ( $posts[ $term_id ]['exists'] ?? false ) ) {
				$term_hashes[ $term_id ] = TeachingContracts::term_identity_hash(
					array(
						'calendar_key' => Policy::scalar_string( get_post_meta( $term_id, '_lps_calendar_key', true ) ),
						'term_code'    => Policy::scalar_string( get_post_meta( $term_id, '_lps_term_code', true ) ),
					)
				);
			}
		}
		$offering_hashes = array();
		foreach ( $offering_claims as $claim ) {
			$offering_id = Policy::sanitize_integer( $claim['offering_post_id'] ?? 0 );
			if ( 0 < $offering_id && ( $posts[ $offering_id ]['exists'] ?? false ) ) {
				$identity = self::offering_identity_for( $offering_id );
				if ( null !== $identity ) {
					$offering_hashes[ $offering_id ] = TeachingContracts::offering_identity_hash( $identity );
				}
			}
		}

		$term_post_ids       = self::ids_of_type( 'lps_term' );
		$offering_post_ids   = self::ids_of_type( 'lps_offering' );
		$unclaimed_terms     = self::unclaimed_ids(
			$term_post_ids,
			array_map( static fn( array $claim ): int => Policy::sanitize_integer( $claim['term_post_id'] ?? 0 ), $term_claims )
		);
		$unclaimed_offerings = self::unclaimed_ids(
			$offering_post_ids,
			array_map( static fn( array $claim ): int => Policy::sanitize_integer( $claim['offering_post_id'] ?? 0 ), $offering_claims )
		);
		$unclaimed_issues    = array();
		foreach ( $unclaimed_terms as $post_id ) {
			$unclaimed_issues[] = array(
				'code'          => 'lps_reconcile_term_unclaimed',
				'claim_post_id' => $post_id,
				'detail'        => 'The term record holds no registry claim.',
			);
		}
		foreach ( $unclaimed_offerings as $post_id ) {
			$unclaimed_issues[] = array(
				'code'          => 'lps_reconcile_offering_unclaimed',
				'claim_post_id' => $post_id,
				'detail'        => 'The offering record holds no registry claim.',
			);
		}
		$issues = array_merge(
			self::classify_registry_claims( $term_claims, $posts, $term_hashes, 'term' ),
			self::classify_registry_claims( $offering_claims, $posts, $offering_hashes, 'offering' ),
			$unclaimed_issues
		);
		return array(
			'schema'                => TeachingMigrations::report(),
			'issues'                => $issues,
			'counts'                => array(
				'term_claims'         => count( $term_claims ),
				'offering_claims'     => count( $offering_claims ),
				'terms'               => count( $term_post_ids ),
				'offerings'           => count( $offering_post_ids ),
				'unclaimed_terms'     => count( $unclaimed_terms ),
				'unclaimed_offerings' => count( $unclaimed_offerings ),
			),
			'origin_reconciliation' => PublicationRecords::origin_reconciliation(),
		);
	}

	/**
	 * Resolves the canonical offering route to a published record.
	 *
	 * @param string $course_slug Course slug in the requested locale.
	 * @param string $term_token  Immutable calendar-qualified term token.
	 * @param string $section_key Section key.
	 * @param string $locale      Supported locale slug.
	 * @return array{post: WP_Post, offering_id: int, offering_authority_id: int, course_id: int, term_id: int}|null
	 */
	public static function resolve_offering_route( string $course_slug, string $term_token, string $section_key, string $locale ): ?array {
		$course = self::course_for_slug( $course_slug, $locale );
		if ( ! $course instanceof WP_Post ) {
			return null;
		}
		$course_authority = self::authority_id( $course->ID );
		$term_id          = self::term_for_token( $term_token );
		$section          = TeachingContracts::normalize_section_key( $section_key );
		if ( 0 === $term_id || '' === $section ) {
			return null;
		}
		$offering_authority = self::offering_for_identity( $course_authority, $term_id, $section );
		if ( 0 === $offering_authority ) {
			return null;
		}
		$variant_id = TranslationPolicy::TARGET_LOCALE === $locale
			? Policy::sanitize_integer( Translations::variants( $offering_authority )[ TranslationPolicy::TARGET_LOCALE ] ?? 0 )
			: $offering_authority;
		$post       = 0 < $variant_id ? get_post( $variant_id ) : null;
		if ( ! $post instanceof WP_Post || 'publish' !== $post->post_status ) {
			return null;
		}
		return array(
			'post'                  => $post,
			'offering_id'           => $post->ID,
			'offering_authority_id' => $offering_authority,
			'course_id'             => $course->ID,
			'term_id'               => $term_id,
		);
	}

	/**
	 * Returns the canonical landing path for one locale.
	 *
	 * @param string $locale Supported locale slug.
	 */
	public static function landing_path( string $locale ): string {
		return TranslationPolicy::TARGET_LOCALE === $locale ? '/en/teaching/' : '/pt-br/ensino/';
	}

	/**
	 * Returns the canonical course path for one locale.
	 *
	 * @param string $locale Supported locale slug.
	 * @param string $slug   Course slug in that locale.
	 */
	public static function course_path( string $locale, string $slug ): string {
		$base = TranslationPolicy::TARGET_LOCALE === $locale ? '/en/teaching/courses/' : '/pt-br/ensino/disciplinas/';
		return '' === $slug ? $base : $base . $slug . '/';
	}

	/**
	 * Returns the canonical offering path for one locale.
	 *
	 * @param string $locale      Supported locale slug.
	 * @param string $course_slug Course slug in that locale.
	 * @param string $term_token  Immutable calendar-qualified term token.
	 * @param string $section_key Normalized section key.
	 */
	public static function offering_path( string $locale, string $course_slug, string $term_token, string $section_key ): string {
		$base = self::course_path( $locale, $course_slug );
		return '' === $term_token || '' === $section_key ? $base : $base . $term_token . '/' . $section_key . '/';
	}

	/**
	 * Returns the canonical offering URL from stored relationships, or empty.
	 *
	 * @param int    $offering_id Any associated offering variant ID.
	 * @param string $locale      Supported locale slug.
	 */
	public static function offering_url( int $offering_id, string $locale ): string {
		$authority = self::authority_id( $offering_id );
		$identity  = self::offering_identity_for( $authority );
		if ( null === $identity ) {
			return '';
		}
		$course = self::localized_post( $identity['course_id'], $locale );
		$token  = Policy::scalar_string( get_post_meta( $identity['term_id'], '_lps_term_token', true ) );
		if ( ! $course instanceof WP_Post || '' === $token ) {
			return '';
		}
		return self::offering_path( $locale, $course->post_name, $token, $identity['section_key'] );
	}

	/**
	 * Recomputes and stores the derived temporal status for one record.
	 *
	 * Offerings derive from their term boundaries and cancellation flag; terms
	 * fan the refresh out to every offering scheduled in them. Temporal status
	 * never mutates editorial state.
	 *
	 * @param int $post_id Record database ID.
	 */
	public static function refresh_temporal_status( int $post_id ): void {
		$post = get_post( $post_id );
		if ( ! $post instanceof WP_Post ) {
			return;
		}
		if ( 'lps_term' === $post->post_type ) {
			foreach ( Relationships::reverse_for( $post_id, 'offering_term' ) as $row ) {
				self::refresh_temporal_status( Policy::sanitize_integer( $row['source_post_id'] ) );
			}
			return;
		}
		if ( 'lps_offering' !== $post->post_type ) {
			return;
		}
		$term_rows = Relationships::for_source( $post_id, 'offering_term' );
		$term_id   = Policy::sanitize_integer( $term_rows[0]['target_post_id'] ?? 0 );
		if ( 0 >= $term_id ) {
			return;
		}
		$status = TeachingContracts::temporal_status(
			get_post_meta( $term_id, '_lps_starts_on', true ),
			get_post_meta( $term_id, '_lps_ends_on', true ),
			get_post_meta( $post_id, '_lps_cancelled', true ),
			TeachingContracts::today()
		);
		if ( '' !== $status && get_post_meta( $post_id, '_lps_temporal_status', true ) !== $status ) {
			update_post_meta( $post_id, '_lps_temporal_status', $status );
			// A temporal transition is a metadata write the post hooks never
			// see, so the index row — and its English sibling — is refreshed
			// explicitly rather than left quoting the stale status.
			if ( ! class_exists( SearchIndex::class ) ) {
				require_once __DIR__ . '/class-searchindex.php';
			}
			SearchIndex::synchronize_post( $post_id );
		}
	}

	/**
	 * Returns the stored canonical identity of one offering, or null.
	 *
	 * @param int $offering_id Authoritative offering record ID.
	 * @return array{course_id: int, term_id: int, section_key: string}|null
	 */
	public static function offering_identity_for( int $offering_id ): ?array {
		$course_rows = Relationships::for_source( $offering_id, 'offering_course' );
		$term_rows   = Relationships::for_source( $offering_id, 'offering_term' );
		$course_id   = Policy::sanitize_integer( $course_rows[0]['target_post_id'] ?? 0 );
		$term_id     = Policy::sanitize_integer( $term_rows[0]['target_post_id'] ?? 0 );
		$section     = TeachingContracts::normalize_section_key( get_post_meta( $offering_id, '_lps_section_key', true ) );
		if ( 0 >= $course_id || 0 >= $term_id || '' === $section ) {
			return null;
		}
		return TeachingContracts::offering_identity( $course_id, $term_id, $section );
	}

	/**
	 * Resolves the authoritative record ID for any associated variant.
	 *
	 * @param int $post_id Any associated variant ID.
	 */
	public static function authority_id( int $post_id ): int {
		return TeachingContracts::authoritative_id( $post_id );
	}

	/**
	 * Builds the portable record response for one stored record.
	 *
	 * @param int $post_id Record database ID.
	 * @return array<string, mixed>
	 */
	public static function record_response( int $post_id ): array {
		$post     = get_post( $post_id );
		$locale   = $post instanceof WP_Post ? Translations::locale( $post_id ) : '';
		$locale   = '' === $locale && $post instanceof WP_Post ? Policy::scalar_string( get_post_meta( $post_id, '_lps_locale', true ) ) : $locale;
		$variants = class_exists( Translations::class ) ? Translations::variants( $post_id ) : array();
		$response = array(
			'id'        => $post_id,
			'post_type' => $post instanceof WP_Post ? $post->post_type : '',
			'status'    => $post instanceof WP_Post ? $post->post_status : '',
			'slug'      => $post instanceof WP_Post ? $post->post_name : '',
			'title'     => $post instanceof WP_Post ? $post->post_title : '',
			'locale'    => $locale,
			'state'     => Policy::scalar_string( get_post_meta( $post_id, '_lps_state', true ) ),
			'record_id' => Policy::scalar_string( get_post_meta( $post_id, '_lps_record_id', true ) ),
			'variants'  => $variants,
		);
		if ( 'lps_term' === $response['post_type'] ) {
			$response['term_token'] = Policy::scalar_string( get_post_meta( $post_id, '_lps_term_token', true ) );
		}
		if ( 'lps_offering' === $response['post_type'] ) {
			$response['temporal_status'] = Policy::scalar_string( get_post_meta( $post_id, '_lps_temporal_status', true ) );
			$response['canonical_path']  = self::offering_url( $post_id, $locale );
		}
		if ( 'lps_course' === $response['post_type'] ) {
			$response['canonical_path'] = self::course_path( $locale, $response['slug'] );
		}
		return $response;
	}

	/**
	 * Builds one typed conflict error carrying the winning record ID.
	 *
	 * @param string $kind            `term` or `offering`.
	 * @param int    $existing_post_id Record already owning the identity.
	 */
	public static function conflict_error( string $kind, int $existing_post_id ): WP_Error {
		$code = 'term' === $kind ? 'lps_term_identity_conflict' : 'lps_offering_identity_conflict';
		return new WP_Error(
			$code,
			'A record with this canonical identity already exists.',
			array(
				'status'          => 409,
				'field'           => 'term' === $kind ? 'term_code' : 'section',
				'existing_record' => $existing_post_id,
			)
		);
	}

	/**
	 * Inserts one draft record with sanitized authority metadata.
	 *
	 * @param string               $post_type Teaching record type.
	 * @param array<string, mixed> $input     Boundary input.
	 * @param string               $locale    Record locale.
	 * @param array<int, string>   $allowed   Allowed metadata keys.
	 * @param array<string, mixed> $meta      Pre-sanitized metadata override.
	 * @return int|WP_Error
	 */
	private static function insert_record( string $post_type, array $input, string $locale, array $allowed, ?array $meta = null ): int|WP_Error {
		$meta                = null === $meta ? self::sanitize_meta( $post_type, self::meta_input( $input, $allowed ), $allowed ) : $meta;
		$meta['_lps_locale'] = $locale;
		$postarr             = array(
			'post_type'    => $post_type,
			'post_status'  => 'draft',
			'post_title'   => Policy::sanitize_text( $input['title'] ?? '' ),
			'post_excerpt' => Policy::sanitize_text( $input['excerpt'] ?? '' ),
			'post_content' => Policy::scalar_string( $input['content'] ?? '' ),
			'meta_input'   => $meta,
		);
		$slug                = sanitize_title( Policy::scalar_string( $input['slug'] ?? '' ) );
		if ( '' !== $slug ) {
			$postarr['post_name'] = $slug;
		}
		$post_id = wp_insert_post( $postarr, true );
		return $post_id instanceof WP_Error ? $post_id : (int) $post_id;
	}

	/**
	 * Creates an English variant of an existing authority record.
	 *
	 * @param string               $post_type      Teaching record type.
	 * @param array<string, mixed> $input          Boundary input.
	 * @param int                  $translation_of Authority record ID.
	 * @return array<string, mixed>|WP_Error
	 */
	private static function create_variant( string $post_type, array $input, int $translation_of ): array|WP_Error {
		$locale = self::locale_input( $input );
		if ( TranslationPolicy::TARGET_LOCALE !== $locale ) {
			return self::error( 'lps_teaching_locale_forbidden', 'A variant create must declare the English locale.', 'locale' );
		}
		if ( ! isset( self::VARIANT_META[ $post_type ] ) ) {
			return self::error( 'lps_teaching_locale_forbidden', 'This record type has no translated variant.', 'locale' );
		}
		$source = 0 < $translation_of ? get_post( $translation_of ) : null;
		if ( ! $source instanceof WP_Post || $post_type !== $source->post_type || TranslationPolicy::SOURCE_LOCALE !== Translations::locale( $source->ID ) ) {
			return self::error( 'lps_teaching_translation_required', 'An English variant requires its Portuguese authority record.', 'translation_of' );
		}
		$variants = Translations::variants( $source->ID );
		if ( 0 < Policy::sanitize_integer( $variants[ TranslationPolicy::TARGET_LOCALE ] ?? 0 ) ) {
			return self::error( 'lps_teaching_variant_exists', 'The English variant already exists.', 'translation_of', 409, array( 'existing_record' => $variants[ TranslationPolicy::TARGET_LOCALE ] ) );
		}
		$post_id = self::insert_record( $post_type, $input, TranslationPolicy::TARGET_LOCALE, self::VARIANT_META[ $post_type ] );
		if ( $post_id instanceof WP_Error ) {
			return $post_id;
		}
		$associated = Translations::associate( $source->ID, $post_id );
		if ( $associated instanceof WP_Error ) {
			self::cleanup_record( $post_id, false );
			return $associated;
		}
		return self::finish_create( $post_id, $input );
	}

	/**
	 * Publishes a created record when requested and returns its response.
	 *
	 * @param int                  $post_id Record database ID.
	 * @param array<string, mixed> $input   Boundary input.
	 * @return array<string, mixed>|WP_Error
	 */
	private static function finish_create( int $post_id, array $input ): array|WP_Error {
		if ( 'publish' === Policy::scalar_string( $input['status'] ?? 'draft' ) ) {
			$published = self::publish_record( $post_id );
			if ( $published instanceof WP_Error ) {
				return $published;
			}
		}
		return self::record_response( $post_id );
	}

	/**
	 * Removes every trace of a failed create: relationships, claims, then post.
	 *
	 * Relationship rows are removed before the post so the draft is
	 * unreferenced and unpublished when WordPress evaluates deletion, which
	 * keeps the archival guard from converting the cleanup into an archive.
	 *
	 * @param int  $post_id    Record database ID.
	 * @param bool $with_claim Whether registry claims may exist.
	 */
	private static function cleanup_record( int $post_id, bool $with_claim ): void {
		$wpdb   = self::database();
		$tables = Migrations::table_names( $wpdb );
		$wpdb->delete( $tables['relationships'], array( 'source_post_id' => $post_id ), array( '%d' ) );
		if ( $with_claim ) {
			TeachingMigrations::release_post_claims( $post_id );
		}
		wp_delete_post( $post_id, true );
	}

	/**
	 * Normalizes and validates a teaching-team row set.
	 *
	 * @param mixed $team Boundary team input.
	 * @return array<int, array{target_post_id: int, relationship_role: string, sort_order: int, start_date: string, end_date: string, public_visibility: bool}>|WP_Error
	 */
	private static function normalize_team( mixed $team ): array|WP_Error {
		if ( ! is_array( $team ) || array() === $team ) {
			return self::error( 'lps_teaching_team_required', 'A teaching team with a lead is required.', 'team' );
		}
		$rows = array();
		$sort = 0;
		foreach ( $team as $member ) {
			if ( ! is_array( $member ) ) {
				return self::error( 'lps_teaching_team_invalid', 'A teaching-team row is malformed.', 'team' );
			}
			$person_id = self::authority_id( Policy::sanitize_integer( $member['person_id'] ?? ( $member['target_post_id'] ?? 0 ) ) );
			$person    = 0 < $person_id ? get_post( $person_id ) : null;
			if ( ! $person instanceof WP_Post || 'lps_person' !== $person->post_type || 'trash' === $person->post_status ) {
				return self::error( 'lps_orphan_relationship_target', 'A teaching-team member record does not exist.', 'team', 400, array( 'person_id' => $person_id ) );
			}
			++$sort;
			$rows[] = self::relationship_row(
				$person_id,
				Policy::scalar_string( $member['role'] ?? '' ),
				Policy::sanitize_integer( $member['sort_order'] ?? $sort ),
				Policy::scalar_string( $member['start_date'] ?? '' ),
				Policy::scalar_string( $member['end_date'] ?? '' ),
				(bool) ( $member['public_visibility'] ?? true )
			);
		}
		$errors = RelationshipPolicy::relationship_errors( 'teaching_team', $rows );
		if ( array() !== $errors ) {
			return self::error( $errors[0], 'The teaching team violates its integrity contract.', 'team', 400, array( 'errors' => $errors ) );
		}
		return $rows;
	}

	/**
	 * Builds one canonical relationship row.
	 *
	 * @param int    $target_post_id    Target record ID.
	 * @param string $role              Relationship role.
	 * @param int    $sort_order        Ordered position.
	 * @param string $start_date        Optional start date.
	 * @param string $end_date          Optional end date.
	 * @param bool   $public_visibility Whether the row is publicly visible.
	 * @return array{target_post_id: int, relationship_role: string, sort_order: int, start_date: string, end_date: string, public_visibility: bool}
	 */
	private static function relationship_row( int $target_post_id, string $role, int $sort_order, string $start_date = '', string $end_date = '', bool $public_visibility = true ): array {
		return array(
			'target_post_id'    => $target_post_id,
			'relationship_role' => $role,
			'sort_order'        => $sort_order,
			'start_date'        => $start_date,
			'end_date'          => $end_date,
			'public_visibility' => $public_visibility,
		);
	}

	/**
	 * Claims one term identity in the indexed registry.
	 *
	 * @param int                                                           $post_id  Term record ID.
	 * @param array{calendar_key: string, term_code: string, token: string} $identity Canonical term identity.
	 */
	private static function claim_term( int $post_id, array $identity ): ?WP_Error {
		$wpdb     = self::database();
		$tables   = TeachingMigrations::table_names( $wpdb );
		$inserted = $wpdb->insert(
			$tables['term_registry'],
			array(
				'term_post_id'  => $post_id,
				'calendar_key'  => $identity['calendar_key'],
				'term_code'     => $identity['term_code'],
				'term_token'    => $identity['token'],
				'identity_hash' => TeachingContracts::term_identity_hash( $identity ),
				'created_at'    => gmdate( 'c' ),
			),
			array( '%d', '%s', '%s', '%s', '%s', '%s' )
		);
		if ( false !== $inserted ) {
			return null;
		}
		$existing = self::term_claim( TeachingContracts::term_identity_hash( $identity ) );
		return null !== $existing
			? self::conflict_error( 'term', $existing )
			: self::error( 'lps_teaching_registry_error', 'The term registry rejected the identity claim.', 'term_code', 500, array( 'database_error' => $wpdb->last_error ) );
	}

	/**
	 * Claims one offering identity in the indexed registry.
	 *
	 * @param int                                                      $post_id  Offering record ID.
	 * @param array{course_id: int, term_id: int, section_key: string} $identity Canonical offering identity.
	 */
	private static function claim_offering( int $post_id, array $identity ): ?WP_Error {
		$wpdb     = self::database();
		$tables   = TeachingMigrations::table_names( $wpdb );
		$inserted = $wpdb->insert(
			$tables['offering_registry'],
			array(
				'offering_post_id' => $post_id,
				'course_post_id'   => $identity['course_id'],
				'term_post_id'     => $identity['term_id'],
				'section_key'      => $identity['section_key'],
				'identity_hash'    => TeachingContracts::offering_identity_hash( $identity ),
				'created_at'       => gmdate( 'c' ),
			),
			array( '%d', '%d', '%d', '%s', '%s', '%s' )
		);
		if ( false !== $inserted ) {
			return null;
		}
		$existing = self::offering_claim( TeachingContracts::offering_identity_hash( $identity ) );
		return null !== $existing
			? self::conflict_error( 'offering', $existing )
			: self::error( 'lps_teaching_registry_error', 'The offering registry rejected the identity claim.', 'section', 500, array( 'database_error' => $wpdb->last_error ) );
	}

	/**
	 * Returns the term record owning an identity hash, or null.
	 *
	 * @param string $identity_hash Canonical identity hash.
	 */
	private static function term_claim( string $identity_hash ): ?int {
		$wpdb   = self::database();
		$tables = TeachingMigrations::table_names( $wpdb );
		if ( ! self::table_exists( $tables['term_registry'], $wpdb ) ) {
			return null;
		}
		$found = $wpdb->get_var( $wpdb->prepare( 'SELECT term_post_id FROM %i WHERE identity_hash = %s LIMIT 1', $tables['term_registry'], $identity_hash ) );
		return null === $found ? null : Policy::sanitize_integer( $found );
	}

	/**
	 * Returns the offering record owning an identity hash, or null.
	 *
	 * @param string $identity_hash Canonical identity hash.
	 */
	private static function offering_claim( string $identity_hash ): ?int {
		$wpdb   = self::database();
		$tables = TeachingMigrations::table_names( $wpdb );
		if ( ! self::table_exists( $tables['offering_registry'], $wpdb ) ) {
			return null;
		}
		$found = $wpdb->get_var( $wpdb->prepare( 'SELECT offering_post_id FROM %i WHERE identity_hash = %s LIMIT 1', $tables['offering_registry'], $identity_hash ) );
		return null === $found ? null : Policy::sanitize_integer( $found );
	}

	/**
	 * Returns the term record ID owning a route token, or zero.
	 *
	 * The registry is the primary lookup; the metadata fallback keeps records
	 * created outside the service boundary resolvable for reconciliation.
	 *
	 * @param string $term_token Immutable calendar-qualified token.
	 */
	private static function term_for_token( string $term_token ): int {
		$token = TeachingContracts::normalize_lookup_key( $term_token );
		if ( '' === $token ) {
			return 0;
		}
		$wpdb   = self::database();
		$tables = TeachingMigrations::table_names( $wpdb );
		if ( self::table_exists( $tables['term_registry'], $wpdb ) ) {
			$found = $wpdb->get_var( $wpdb->prepare( 'SELECT term_post_id FROM %i WHERE term_token = %s LIMIT 1', $tables['term_registry'], $token ) );
			if ( null !== $found ) {
				return Policy::sanitize_integer( $found );
			}
		}
		$ids = get_posts(
			array(
				'post_type'      => 'lps_term',
				'post_status'    => array( 'publish', 'draft', 'pending', 'future', 'private' ),
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- token fallback for records created outside the service boundary.
					array(
						'key'   => '_lps_term_token',
						'value' => $token,
					),
				),
			)
		);
		return Policy::sanitize_integer( $ids[0] ?? 0 );
	}

	/**
	 * Returns the authoritative offering ID for a canonical identity, or zero.
	 *
	 * @param int    $course_id   Authoritative course ID.
	 * @param int    $term_id     Authoritative term ID.
	 * @param string $section_key Normalized section key.
	 */
	private static function offering_for_identity( int $course_id, int $term_id, string $section_key ): int {
		$wpdb   = self::database();
		$tables = TeachingMigrations::table_names( $wpdb );
		if ( ! self::table_exists( $tables['offering_registry'], $wpdb ) ) {
			return 0;
		}
		$found = $wpdb->get_var( $wpdb->prepare( 'SELECT offering_post_id FROM %i WHERE course_post_id = %d AND term_post_id = %d AND section_key = %s LIMIT 1', $tables['offering_registry'], $course_id, $term_id, $section_key ) );
		return null === $found ? 0 : Policy::sanitize_integer( $found );
	}

	/**
	 * Returns the published course record for a slug in one locale, or null.
	 *
	 * @param string $slug   Course slug.
	 * @param string $locale Supported locale slug.
	 */
	private static function course_for_slug( string $slug, string $locale ): ?WP_Post {
		$slug = sanitize_title( $slug );
		if ( '' === $slug ) {
			return null;
		}
		$post = get_page_by_path( $slug, OBJECT, 'lps_course' );
		if ( ! $post instanceof WP_Post || 'publish' !== $post->post_status ) {
			return null;
		}
		$record_locale = class_exists( Translations::class ) ? Translations::locale( $post->ID ) : Policy::scalar_string( get_post_meta( $post->ID, '_lps_locale', true ) );
		return $record_locale === $locale ? $post : null;
	}

	/**
	 * Returns the locale variant of a record, or null when absent.
	 *
	 * @param int    $post_id Any associated variant ID.
	 * @param string $locale  Supported locale slug.
	 */
	private static function localized_post( int $post_id, string $locale ): ?WP_Post {
		$post = 0 < $post_id ? get_post( $post_id ) : null;
		if ( ! $post instanceof WP_Post ) {
			return null;
		}
		$record_locale = class_exists( Translations::class ) ? Translations::locale( $post->ID ) : '';
		if ( $record_locale === $locale ) {
			return $post;
		}
		$variants = class_exists( Translations::class ) ? Translations::variants( $post->ID ) : array();
		$variant  = isset( $variants[ $locale ] ) ? get_post( $variants[ $locale ] ) : null;
		return $variant instanceof WP_Post ? $variant : null;
	}

	/**
	 * Builds one history entry from a canonical team row.
	 *
	 * @param array{source_post_id: int, relationship_role: string, start_date: string, end_date: string, public_visibility: bool} $row Canonical team row.
	 * @param WP_Post                                                                                                              $offering           Offering record in the requested locale.
	 * @param int                                                                                                                  $offering_authority Authoritative offering ID.
	 * @param string                                                                                                               $locale             Supported locale slug.
	 * @return array<string, mixed>
	 */
	private static function history_entry( array $row, WP_Post $offering, int $offering_authority, string $locale ): array {
		$course_rows = Relationships::for_source( $offering_authority, 'offering_course' );
		$term_rows   = Relationships::for_source( $offering_authority, 'offering_term' );
		$course_id   = Policy::sanitize_integer( $course_rows[0]['target_post_id'] ?? 0 );
		$term_id     = Policy::sanitize_integer( $term_rows[0]['target_post_id'] ?? 0 );
		$course      = self::localized_post( $course_id, $locale );
		$term        = 0 < $term_id ? get_post( $term_id ) : null;
		$token       = $term instanceof WP_Post ? Policy::scalar_string( get_post_meta( $term->ID, '_lps_term_token', true ) ) : '';
		$section     = TeachingContracts::normalize_section_key( get_post_meta( $offering_authority, '_lps_section_key', true ) );
		$starts_on   = $term instanceof WP_Post ? Policy::scalar_string( get_post_meta( $term->ID, '_lps_starts_on', true ) ) : '';
		$ends_on     = $term instanceof WP_Post ? Policy::scalar_string( get_post_meta( $term->ID, '_lps_ends_on', true ) ) : '';
		$url         = $course instanceof WP_Post && '' !== $token ? self::offering_path( $locale, $course->post_name, $token, $section ) : '';
		return array(
			'offering_id'           => $offering->ID,
			'offering_authority_id' => $offering_authority,
			'title'                 => $offering->post_title,
			'url'                   => $url,
			'status'                => $offering->post_status,
			'role'                  => $row['relationship_role'],
			'start_date'            => $row['start_date'],
			'end_date'              => $row['end_date'],
			'public_visibility'     => $row['public_visibility'],
			'section_key'           => $section,
			'term'                  => array(
				'token'        => $token,
				'period_label' => $term instanceof WP_Post ? Policy::scalar_string( get_post_meta( $term->ID, '_lps_period_label', true ) ) : '',
				'starts_on'    => $starts_on,
				'ends_on'      => $ends_on,
			),
			'course'                => array(
				'title' => $course instanceof WP_Post ? $course->post_title : '',
				'slug'  => $course instanceof WP_Post ? $course->post_name : '',
				'url'   => $course instanceof WP_Post ? self::course_path( $locale, $course->post_name ) : '',
			),
			'temporal_status'       => TeachingContracts::temporal_status(
				$starts_on,
				$ends_on,
				get_post_meta( $offering_authority, '_lps_cancelled', true ),
				TeachingContracts::today()
			),
		);
	}

	/**
	 * Returns governed record IDs of one type, authority records only.
	 *
	 * @param string $post_type Teaching record type.
	 * @return array<int, int>
	 */
	private static function ids_of_type( string $post_type ): array {
		$ids    = get_posts(
			array(
				'post_type'      => $post_type,
				'post_status'    => array( 'publish', 'draft', 'pending', 'future', 'private' ),
				'posts_per_page' => -1, // phpcs:ignore WordPress.WP.PostsPerPage.posts_per_page_posts_per_page -- reconciliation must see the full set.
				'fields'         => 'ids',
				'orderby'        => 'ID',
				'order'          => 'ASC',
			)
		);
		$result = array();
		foreach ( $ids as $id ) {
			$post_id = Policy::sanitize_integer( $id );
			if ( self::authority_id( $post_id ) === $post_id ) {
				$result[] = $post_id;
			}
		}
		return $result;
	}

	/**
	 * Returns the stored metadata of one record for its registered fields.
	 *
	 * @param string $post_type Record type.
	 * @param int    $post_id   Record database ID.
	 * @return array<string, mixed>
	 */
	private static function stored_meta( string $post_type, int $post_id ): array {
		$meta = array();
		foreach ( Contracts::meta_fields()[ $post_type ] ?? array() as $key => $definition ) {
			unset( $definition );
			$meta[ $key ] = get_post_meta( $post_id, $key, true );
		}
		return $meta;
	}

	/**
	 * Merges the `meta` object with top-level unprefixed field names.
	 *
	 * Callers may send either `meta: {_lps_period_label: ...}` or a top-level
	 * `period_label`; both normalize to the governed `_lps_` key when that key
	 * is allowed for the record type.
	 *
	 * @param array<string, mixed> $input   Boundary input.
	 * @param array<int, string>   $allowed Allowed metadata keys.
	 * @return array<string, mixed>
	 */
	private static function meta_input( array $input, array $allowed ): array {
		$meta = array();
		if ( is_array( $input['meta'] ?? null ) ) {
			foreach ( $input['meta'] as $key => $value ) {
				if ( is_string( $key ) ) {
					$meta[ $key ] = $value;
				}
			}
		}
		foreach ( $allowed as $key ) {
			$plain = str_starts_with( $key, '_lps_' ) ? substr( $key, 5 ) : $key;
			if ( ! array_key_exists( $key, $meta ) && array_key_exists( $plain, $input ) ) {
				$meta[ $key ] = $input[ $plain ];
			}
		}
		return $meta;
	}

	/**
	 * Sanitizes boundary metadata through the registered field normalizers.
	 *
	 * @param string             $post_type Record type.
	 * @param mixed              $input     Boundary meta input.
	 * @param array<int, string> $allowed   Allowed metadata keys.
	 * @return array<string, mixed>
	 */
	private static function sanitize_meta( string $post_type, mixed $input, array $allowed ): array {
		$input  = is_array( $input ) ? $input : array();
		$fields = Contracts::meta_fields()[ $post_type ] ?? array();
		$meta   = array();
		foreach ( $allowed as $key ) {
			if ( ! array_key_exists( $key, $input ) ) {
				continue;
			}
			$meta[ $key ] = isset( $fields[ $key ]['sanitize_callback'] )
				? call_user_func( $fields[ $key ]['sanitize_callback'], $input[ $key ] )
				: Policy::scalar_string( $input[ $key ] );
		}
		return $meta;
	}

	/**
	 * Returns the requested locale, defaulting to the source locale.
	 *
	 * @param array<string, mixed> $input Boundary input.
	 */
	private static function locale_input( array $input ): string {
		$locale = strtolower( trim( Policy::scalar_string( $input['locale'] ?? '' ) ) );
		return isset( TranslationPolicy::locales()[ $locale ] ) ? $locale : TranslationPolicy::SOURCE_LOCALE;
	}

	/**
	 * Checks one registry table without mutating it.
	 *
	 * @param string $table    Fully qualified table name.
	 * @param wpdb   $database WordPress database adapter.
	 */
	private static function table_exists( string $table, wpdb $database ): bool {
		$pattern = $database->esc_like( $table );
		$found   = $database->get_var( $database->prepare( 'SHOW TABLES LIKE %s', $pattern ) );
		return is_string( $found ) && $table === $found;
	}

	/**
	 * Returns the initialized WordPress database adapter.
	 *
	 * @throws \RuntimeException When WordPress has not initialized wpdb.
	 */
	private static function database(): wpdb {
		global $wpdb;
		if ( ! $wpdb instanceof wpdb ) {
			throw new \RuntimeException( 'WordPress database adapter is unavailable.' );
		}
		return $wpdb;
	}

	/**
	 * Builds one typed boundary error.
	 *
	 * @param string               $code    Stable machine error code.
	 * @param string               $message Human-readable message.
	 * @param string               $field   Machine field name.
	 * @param int                  $status  HTTP status.
	 * @param array<string, mixed> $context Additional machine-readable context.
	 */
	private static function error( string $code, string $message, string $field, int $status = 400, array $context = array() ): WP_Error {
		return new WP_Error(
			$code,
			$message,
			array_merge(
				array(
					'status' => $status,
					'field'  => $field,
				),
				$context
			)
		);
	}
}
// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
