<?php
/**
 * Teaching domain contracts: entities, identities, uniqueness, and history.
 *
 * @package LPS\ContentModel
 */

declare(strict_types=1);

namespace LPS\ContentModel;

require_once __DIR__ . '/class-policy.php';
require_once __DIR__ . '/class-translationpolicy.php';

/**
 * Canonical teaching entity, normalization, uniqueness, and temporal contracts.
 *
 * The five teaching record types extend the existing Contracts registry. Field
 * ownership is explicit: `shared` values are owned by the authoritative
 * Portuguese record, `localized` values are per-variant editorial fields, and
 * `system` values are boundary-written or derived and never editor-authored.
 * Temporal status is derived from term boundaries and is deliberately separate
 * from the editorial state machine in Policy.
 */
final class TeachingContracts {
	public const VERSION           = '1.0.0';
	public const DEFAULT_TIMEZONE  = 'America/Sao_Paulo';
	public const POST_TYPES        = array( 'lps_course', 'lps_term', 'lps_offering', 'lps_unit', 'lps_resource' );
	public const TEMPORAL_STATUSES = array( 'upcoming', 'current', 'completed', 'cancelled' );
	public const PERIOD_TYPES      = array( 'semester', 'trimester', 'quarter', 'annual', 'intensive' );
	public const COURSE_LEVELS     = array( 'undergraduate', 'graduate', 'extension' );
	public const TEAM_ROLES        = array( 'lead', 'co-teacher', 'assistant' );
	public const RELEASE_STATES    = array( 'draft', 'scheduled', 'released', 'withdrawn' );
	public const REVIEW_STATES     = array( 'pending', 'approved', 'rejected' );
	public const SCAN_STATES       = array( 'pending', 'clean', 'suspect', 'error' );
	public const RESOURCE_TYPES    = array( 'document', 'slides', 'notebook', 'dataset', 'link', 'other' );
	public const OWNERSHIP_KINDS   = array( 'shared', 'localized', 'system' );

	/**
	 * Offering fields a correction may propagate to explicitly selected
	 * offerings of the same course. Identity, provenance, temporal and
	 * system fields are never correctable through propagation.
	 *
	 * @var array<int, string>
	 */
	public const CORRECTABLE_OFFERING_FIELDS = array( '_lps_schedule', '_lps_venue', '_lps_syllabus_snapshot', '_lps_lms_url', '_lps_lms_url_approved', '_lps_cancelled' );

	/**
	 * Ownership of the common record fields declared by Contracts.
	 *
	 * @var array<string, string>
	 */
	private const COMMON_OWNERSHIP = array(
		'_lps_record_id'               => 'system',
		'_lps_origin'                  => 'system',
		'_lps_claim_verified'          => 'shared',
		'_lps_claim_source_url'        => 'shared',
		'_lps_claim_reviewed_at'       => 'shared',
		'_lps_locale'                  => 'system',
		'_lps_state'                   => 'system',
		'_lps_owner_user_id'           => 'shared',
		'_lps_review_date'             => 'shared',
		'_lps_created_at'              => 'system',
		'_lps_updated_at'              => 'system',
		'_lps_archived_at'             => 'system',
		'_lps_published_slug'          => 'system',
		'_lps_source_revision'         => 'system',
		'_lps_source_hash'             => 'system',
		'_lps_reviewed_source_hash'    => 'system',
		'_lps_translation_reviewed_at' => 'system',
		'_lps_translation_reviewer_id' => 'shared',
		'_lps_prepared_by'             => 'system',
		'_lps_import_source_id'        => 'system',
		'_lps_import_source_url'       => 'system',
		'_lps_import_captured_at'      => 'system',
		'_lps_import_checksum'         => 'system',
		'_lps_import_rights'           => 'system',
		'_lps_import_review_state'     => 'system',
		'_lps_import_fingerprint'      => 'system',
		'_lps_import_reviewed_fields'  => 'system',
		'_lps_crossref_fields'         => 'system',
		'_lps_crossref_cache_key'      => 'system',
		'_lps_crossref_cached_at'      => 'system',
	);

	/**
	 * Returns the five teaching record type definitions.
	 *
	 * @return array<string, array{labels: array{name: string, singular_name: string}, public: bool, builtin: bool, show_in_rest: bool, rest_base: string, supports: list<string>}>
	 */
	public static function post_types(): array {
		return array(
			'lps_course'   => self::type( 'Courses', 'Course', 'courses' ),
			'lps_term'     => self::type( 'Academic Terms', 'Academic Term', 'terms', false ),
			'lps_offering' => self::type( 'Course Offerings', 'Course Offering', 'offerings' ),
			'lps_unit'     => self::type( 'Teaching Units', 'Teaching Unit', 'units', false ),
			'lps_resource' => self::type( 'Teaching Resources', 'Teaching Resource', 'resources', false ),
		);
	}

	/**
	 * Returns teaching-specific metadata definitions keyed by record type.
	 *
	 * @param callable $field The Contracts::field factory.
	 * @phpstan-param callable(string, string, string): array{type: string, single: bool, description: string, show_in_rest: bool, sanitize_callback: callable(mixed): mixed, auth_callback: callable(): bool} $field
	 * @return array<string, array<string, array<string, mixed>>>
	 */
	public static function specific_meta_fields( callable $field ): array {
		$fields = array(
			'lps_course'   => array(
				'_lps_course_code'        => $field( 'string', 'Official course code', 'course_code' ),
				'_lps_course_level'       => $field( 'string', 'Course level', 'key' ),
				'_lps_calendar_key'       => $field( 'string', 'Responsible calendar key', 'key' ),
				'_lps_program'            => $field( 'string', 'Responsible program', 'text' ),
				'_lps_catalog_source_url' => $field( 'string', 'Authoritative catalog source URL', 'url' ),
				'_lps_prerequisites'      => $field( 'string', 'Prerequisites statement', 'textarea' ),
				'_lps_syllabus'           => $field( 'string', 'Default syllabus', 'textarea' ),
			),
			'lps_term'     => array(
				'_lps_calendar_key' => $field( 'string', 'Calendar key', 'key' ),
				'_lps_term_code'    => $field( 'string', 'Calendar term code', 'term_code' ),
				'_lps_period_label' => $field( 'string', 'Period label', 'text' ),
				'_lps_period_type'  => $field( 'string', 'Period type', 'key' ),
				'_lps_starts_on'    => $field( 'string', 'Term start date', 'iso_date' ),
				'_lps_ends_on'      => $field( 'string', 'Term end date', 'iso_date' ),
				'_lps_term_token'   => $field( 'string', 'Immutable calendar-qualified route token', 'key' ),
				'_lps_term_source'  => $field( 'string', 'Term source provenance', 'text' ),
			),
			'lps_offering' => array(
				'_lps_section_key'             => $field( 'string', 'Normalized section key', 'section_key' ),
				'_lps_schedule'                => $field( 'string', 'Meeting schedule', 'textarea' ),
				'_lps_venue'                   => $field( 'string', 'Venue', 'text' ),
				'_lps_syllabus_snapshot'       => $field( 'string', 'Published syllabus snapshot', 'textarea' ),
				'_lps_lms_url'                 => $field( 'string', 'Approved LMS link', 'url' ),
				'_lps_lms_url_approved'        => $field( 'boolean', 'LMS link approved', 'boolean' ),
				'_lps_cancelled'               => $field( 'boolean', 'Offering cancelled', 'boolean' ),
				'_lps_temporal_status'         => $field( 'string', 'Derived temporal status', 'key' ),
				'_lps_copy_operation_id'       => $field( 'string', 'Copy-forward operation identifier', 'key' ),
				'_lps_copy_source_offering_id' => $field( 'integer', 'Copy-forward source offering', 'integer' ),
			),
			'lps_unit'     => array(
				'_lps_anchor'     => $field( 'string', 'Stable unit anchor', 'key' ),
				'_lps_position'   => $field( 'integer', 'Ordered position', 'integer' ),
				'_lps_topic_date' => $field( 'string', 'Optional topic date', 'iso_date' ),
			),
			'lps_resource' => array(
				'_lps_resource_type'        => $field( 'string', 'Resource type', 'key' ),
				'_lps_resource_language'    => $field( 'string', 'Authored resource language', 'resource_language' ),
				'_lps_version_id'           => $field( 'string', 'Immutable local version identifier', 'version_id' ),
				'_lps_external_url'         => $field( 'string', 'External resource URL', 'url' ),
				'_lps_storage_key'          => $field( 'string', 'Opaque storage key', 'key' ),
				'_lps_sha256'               => $field( 'string', 'Version SHA-256 checksum', 'text' ),
				'_lps_byte_size'            => $field( 'integer', 'Version byte size', 'integer' ),
				'_lps_mime_type'            => $field( 'string', 'Detected MIME type', 'key' ),
				'_lps_scan_state'           => $field( 'string', 'Scan result state', 'key' ),
				'_lps_scan_version'         => $field( 'string', 'Scanner version', 'text' ),
				'_lps_uploader_user_id'     => $field( 'integer', 'Uploader account ID', 'integer' ),
				'_lps_release_state'        => $field( 'string', 'Release state', 'key' ),
				'_lps_release_at'           => $field( 'string', 'Scheduled release timestamp', 'datetime' ),
				'_lps_withdrawn_at'         => $field( 'string', 'Withdrawal timestamp', 'datetime' ),
				'_lps_rights_review'        => $field( 'string', 'Rights review state', 'key' ),
				'_lps_accessibility_review' => $field( 'string', 'Accessibility review state', 'key' ),
			),
		);
		foreach ( self::private_meta_keys() as $private_key ) {
			if ( isset( $fields['lps_resource'][ $private_key ] ) ) {
				$fields['lps_resource'][ $private_key ]['show_in_rest'] = false;
			}
		}
		// Correctable offering fields ride WordPress revisions so a propagated
		// correction preserves the prior value in the record's own history.
		foreach ( self::CORRECTABLE_OFFERING_FIELDS as $correctable ) {
			if ( isset( $fields['lps_offering'][ $correctable ] ) ) {
				$fields['lps_offering'][ $correctable ]['revisions_enabled'] = true;
			}
		}
		return $fields;
	}

	/**
	 * Registers the five teaching record types with explicit calls.
	 *
	 * The registration calls for the new post types are named explicitly here:
	 * `lps_course`, `lps_term`, `lps_offering`, `lps_unit`, and `lps_resource`.
	 */
	public static function register(): void {
		register_post_type( 'lps_course', self::registration_args( 'lps_course' ) );
		register_post_type( 'lps_term', self::registration_args( 'lps_term' ) );
		register_post_type( 'lps_offering', self::registration_args( 'lps_offering' ) );
		register_post_type( 'lps_unit', self::registration_args( 'lps_unit' ) );
		register_post_type( 'lps_resource', self::registration_args( 'lps_resource' ) );
	}

	/**
	 * Returns the field ownership map for one teaching record type.
	 *
	 * @param string $post_type Teaching record type.
	 * @return array<string, string>
	 */
	public static function field_ownership( string $post_type ): array {
		$specific = array(
			'lps_course'   => array(
				'_lps_course_code'        => 'shared',
				'_lps_course_level'       => 'shared',
				'_lps_calendar_key'       => 'shared',
				'_lps_program'            => 'shared',
				'_lps_catalog_source_url' => 'shared',
				'_lps_prerequisites'      => 'localized',
				'_lps_syllabus'           => 'localized',
			),
			'lps_term'     => array(
				'_lps_calendar_key' => 'shared',
				'_lps_term_code'    => 'shared',
				'_lps_period_label' => 'shared',
				'_lps_period_type'  => 'shared',
				'_lps_starts_on'    => 'shared',
				'_lps_ends_on'      => 'shared',
				'_lps_term_token'   => 'system',
				'_lps_term_source'  => 'shared',
			),
			'lps_offering' => array(
				'_lps_section_key'             => 'shared',
				'_lps_schedule'                => 'shared',
				'_lps_venue'                   => 'shared',
				'_lps_syllabus_snapshot'       => 'localized',
				'_lps_lms_url'                 => 'shared',
				'_lps_lms_url_approved'        => 'shared',
				'_lps_cancelled'               => 'shared',
				'_lps_temporal_status'         => 'system',
				'_lps_copy_operation_id'       => 'system',
				'_lps_copy_source_offering_id' => 'system',
			),
			'lps_unit'     => array(
				'_lps_anchor'     => 'shared',
				'_lps_position'   => 'shared',
				'_lps_topic_date' => 'shared',
			),
			'lps_resource' => array(
				'_lps_resource_type'        => 'shared',
				'_lps_resource_language'    => 'shared',
				'_lps_version_id'           => 'system',
				'_lps_external_url'         => 'shared',
				'_lps_storage_key'          => 'system',
				'_lps_sha256'               => 'shared',
				'_lps_byte_size'            => 'shared',
				'_lps_mime_type'            => 'shared',
				'_lps_scan_state'           => 'shared',
				'_lps_scan_version'         => 'shared',
				'_lps_uploader_user_id'     => 'system',
				'_lps_release_state'        => 'shared',
				'_lps_release_at'           => 'shared',
				'_lps_withdrawn_at'         => 'shared',
				'_lps_rights_review'        => 'shared',
				'_lps_accessibility_review' => 'shared',
			),
		);
		return array_merge( self::COMMON_OWNERSHIP, $specific[ $post_type ] ?? array() );
	}

	/**
	 * Returns the per-variant editorial teaching metadata keys.
	 *
	 * Localized fields are the only teaching values an English variant may own,
	 * so they are also the only teaching fields whose change can make a
	 * reviewed English variant stale. Shared and system fields — schedules,
	 * term boundaries, release states, version identifiers, storage keys, and
	 * provenance — never enter the translation-freshness hash.
	 *
	 * @param string $post_type Teaching record type.
	 * @return array<int, string>
	 */
	public static function localized_meta_keys( string $post_type ): array {
		$keys = array();
		foreach ( self::field_ownership( $post_type ) as $key => $ownership ) {
			if ( 'localized' === $ownership ) {
				$keys[] = $key;
			}
		}
		return $keys;
	}

	/**
	 * Returns the Portuguese-authority-owned teaching metadata keys.
	 *
	 * @param string $post_type Teaching record type.
	 * @return array<int, string>
	 */
	public static function shared_specific_keys( string $post_type ): array {
		$keys = array();
		foreach ( self::field_ownership( $post_type ) as $key => $ownership ) {
			if ( 'shared' === $ownership && ! array_key_exists( $key, self::COMMON_OWNERSHIP ) ) {
				$keys[] = $key;
			}
		}
		return $keys;
	}

	/**
	 * Returns teaching metadata keys never exposed through REST.
	 *
	 * @return array<int, string>
	 */
	public static function private_meta_keys(): array {
		return array( '_lps_version_id', '_lps_storage_key', '_lps_uploader_user_id', '_lps_prepared_by' );
	}

	/**
	 * Returns the temporal/editorial state separation contract.
	 *
	 * @return array{temporal: array{field: string, derived_from: array<int, string>, values: array<int, string>, publishable: array<int, string>}, editorial: array{field: string, owner: string, terminal: string}}
	 */
	public static function state_model(): array {
		return array(
			'temporal'  => array(
				'field'        => '_lps_temporal_status',
				'derived_from' => array( '_lps_starts_on', '_lps_ends_on', '_lps_cancelled' ),
				'values'       => self::TEMPORAL_STATUSES,
				'publishable'  => array( 'upcoming', 'current', 'completed', 'cancelled' ),
			),
			'editorial' => array(
				'field'    => '_lps_state',
				'owner'    => 'Policy::valid_transition',
				'terminal' => 'archived',
			),
		);
	}

	/**
	 * Normalizes a section key deterministically for uniqueness and routing.
	 *
	 * @param mixed $value Boundary input.
	 */
	public static function normalize_section_key( mixed $value ): string {
		return self::normalize_key( $value );
	}

	/**
	 * Normalizes one route lookup key with the section-key contract.
	 *
	 * Route tokens and section keys share the same folded, lowercased,
	 * hyphenated ASCII form, so a request segment always compares equal to the
	 * stored canonical value it addresses.
	 *
	 * @param mixed $value Boundary input.
	 */
	public static function normalize_lookup_key( mixed $value ): string {
		return self::normalize_key( $value );
	}

	/**
	 * Normalizes a calendar term code such as `2026.1` or `2026-T1`.
	 *
	 * @param mixed $value Boundary input.
	 */
	public static function normalize_term_code( mixed $value ): string {
		return self::normalize_key( $value );
	}

	/**
	 * Normalizes a calendar key.
	 *
	 * @param mixed $value Boundary input.
	 */
	public static function normalize_calendar_key( mixed $value ): string {
		return self::normalize_key( $value );
	}

	/**
	 * Normalizes an official course code to uppercase ASCII form.
	 *
	 * @param mixed $value Boundary input.
	 */
	public static function normalize_course_code( mixed $value ): string {
		$code = strtoupper( self::fold( Policy::scalar_string( $value ) ) );
		$code = (string) preg_replace( '/[^A-Z0-9.\-_]+/', '', $code );
		return trim( $code, '.-_' );
	}

	/**
	 * Normalizes an immutable local version identifier.
	 *
	 * @param mixed $value Boundary input.
	 */
	public static function normalize_version_id( mixed $value ): string {
		$value = strtolower( trim( Policy::scalar_string( $value ) ) );
		return 1 === preg_match( '/^lpsver:[0-9a-f]{64}$/', $value ) ? $value : '';
	}

	/**
	 * Normalizes an authored resource language tag.
	 *
	 * @param mixed $value Boundary input.
	 */
	public static function normalize_language( mixed $value ): string {
		$value = strtolower( trim( Policy::scalar_string( $value ) ) );
		return 1 === preg_match( '/^[a-z]{2,3}(?:-[a-z0-9]{2,8})*$/', $value ) ? $value : '';
	}

	/**
	 * Normalizes an ISO calendar date without changing precision.
	 *
	 * @param mixed $value Boundary input.
	 */
	public static function normalize_iso_date( mixed $value ): string {
		$value = trim( Policy::scalar_string( $value ) );
		if ( 1 !== preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $value, $parts ) ) {
			return '';
		}
		return checkdate( (int) $parts[2], (int) $parts[3], (int) $parts[1] ) ? $value : '';
	}

	/**
	 * Normalizes an institutional timezone identifier.
	 *
	 * @param mixed $value Boundary input.
	 */
	public static function normalize_timezone( mixed $value ): string {
		$value = trim( Policy::scalar_string( $value ) );
		return '' !== $value && in_array( $value, \DateTimeZone::listIdentifiers(), true ) ? $value : '';
	}

	/**
	 * Normalizes a copy-forward operation identifier.
	 *
	 * @param mixed $value Boundary input.
	 */
	public static function normalize_operation_id( mixed $value ): string {
		$value = strtolower( trim( Policy::scalar_string( $value ) ) );
		return 1 === preg_match( '/^[a-z0-9][a-z0-9\-]{7,127}$/', $value ) ? $value : '';
	}

	/**
	 * Builds the immutable calendar-qualified term route token.
	 *
	 * @param mixed $calendar_key Calendar key.
	 * @param mixed $term_code    Calendar term code.
	 */
	public static function term_token( mixed $calendar_key, mixed $term_code ): string {
		$calendar = self::normalize_calendar_key( $calendar_key );
		$code     = self::normalize_term_code( $term_code );
		return '' === $calendar || '' === $code ? '' : $code . '-' . $calendar;
	}

	/**
	 * Returns the canonical registry identity for one term.
	 *
	 * @param mixed $calendar_key Calendar key.
	 * @param mixed $term_code    Calendar term code.
	 * @return array{calendar_key: string, term_code: string, token: string}
	 */
	public static function term_identity( mixed $calendar_key, mixed $term_code ): array {
		return array(
			'calendar_key' => self::normalize_calendar_key( $calendar_key ),
			'term_code'    => self::normalize_term_code( $term_code ),
			'token'        => self::term_token( $calendar_key, $term_code ),
		);
	}

	/**
	 * Returns the canonical registry identity for one offering.
	 *
	 * @param int   $course_id Authoritative course record ID.
	 * @param int   $term_id   Authoritative term record ID.
	 * @param mixed $section   Boundary section input.
	 * @return array{course_id: int, term_id: int, section_key: string}
	 */
	public static function offering_identity( int $course_id, int $term_id, mixed $section ): array {
		return array(
			'course_id'   => $course_id,
			'term_id'     => $term_id,
			'section_key' => self::normalize_section_key( $section ),
		);
	}

	/**
	 * Hashes a canonical term identity for the indexed registry.
	 *
	 * @param array{calendar_key: string, term_code: string} $identity Canonical term identity.
	 */
	public static function term_identity_hash( array $identity ): string {
		return hash( 'sha256', 'lps-term|' . Policy::scalar_string( $identity['calendar_key'] ) . '|' . Policy::scalar_string( $identity['term_code'] ) );
	}

	/**
	 * Hashes a canonical offering identity for the indexed registry.
	 *
	 * @param array{course_id: int, term_id: int, section_key: string} $identity Canonical offering identity.
	 */
	public static function offering_identity_hash( array $identity ): string {
		return hash( 'sha256', 'lps-offering|' . Policy::sanitize_integer( $identity['course_id'] ) . '|' . Policy::sanitize_integer( $identity['term_id'] ) . '|' . Policy::scalar_string( $identity['section_key'] ) );
	}

	/**
	 * Resolves the authoritative record that owns a uniqueness claim.
	 *
	 * Translated record IDs never create a second uniqueness subject: an English
	 * variant resolves to its Portuguese authority through the repository's own
	 * translation mechanism.
	 *
	 * @param int                $post_id      Any associated variant ID.
	 * @param array<string, int> $translations Reciprocal locale association.
	 */
	public static function uniqueness_subject_id( int $post_id, array $translations ): int {
		$authoritative = TranslationPolicy::fallback_post_id( $translations, TranslationPolicy::SOURCE_LOCALE );
		return null === $authoritative ? $post_id : $authoritative;
	}

	/**
	 * Resolves the authoritative record ID through the translation adapter.
	 *
	 * @param int $post_id Any associated variant ID.
	 */
	public static function authoritative_id( int $post_id ): int {
		if ( class_exists( Translations::class ) ) {
			$source_id = Translations::source_id( $post_id );
			if ( null !== $source_id ) {
				return $source_id;
			}
		}
		return $post_id;
	}

	/**
	 * Derives the temporal status from term boundaries and cancellation.
	 *
	 * Temporal status never mutates editorial state: a completed offering stays
	 * publishable and searchable, and end-of-term never archives a record.
	 *
	 * @param mixed $starts_on Term start date.
	 * @param mixed $ends_on   Term end date.
	 * @param mixed $cancelled Cancellation flag.
	 * @param mixed $today     Reference date in the institutional timezone.
	 */
	public static function temporal_status( mixed $starts_on, mixed $ends_on, mixed $cancelled, mixed $today ): string {
		if ( filter_var( $cancelled, FILTER_VALIDATE_BOOLEAN ) ) {
			return 'cancelled';
		}
		$starts = self::normalize_iso_date( $starts_on );
		$ends   = self::normalize_iso_date( $ends_on );
		$today  = self::normalize_iso_date( $today );
		if ( '' === $starts || '' === $ends || '' === $today ) {
			return '';
		}
		if ( $today < $starts ) {
			return 'upcoming';
		}
		return $today <= $ends ? 'current' : 'completed';
	}

	/**
	 * Returns the release state in effect at one instant.
	 *
	 * `released` and `withdrawn` are authoritative immediately; `scheduled`
	 * becomes effective only when its release time has passed — evaluated on
	 * every call, so a stopped scheduler can never release early and a due
	 * release never waits on cron. Unknown states fail closed to `draft`.
	 * The download resolver and the search index share this one evaluation.
	 *
	 * @param mixed $release_state Stored release state.
	 * @param mixed $release_at    Scheduled release timestamp.
	 * @param mixed $now           Reference time (ISO-8601).
	 */
	public static function effective_release_state( mixed $release_state, mixed $release_at, mixed $now ): string {
		$state = trim( Policy::scalar_string( $release_state ) );
		if ( 'released' === $state || 'withdrawn' === $state ) {
			return $state;
		}
		if ( 'scheduled' === $state ) {
			$release_time = self::instant( $release_at );
			$now_time     = self::instant( $now );
			if ( null !== $release_time && null !== $now_time && $release_time <= $now_time ) {
				return 'released';
			}
			return 'scheduled';
		}
		return 'draft';
	}

	/**
	 * Returns the invalid-date-order violation for term boundaries.
	 *
	 * @param mixed $starts_on Term start date.
	 * @param mixed $ends_on   Term end date.
	 */
	public static function date_order_error( mixed $starts_on, mixed $ends_on ): ?string {
		$raw_starts = trim( Policy::scalar_string( $starts_on ) );
		$raw_ends   = trim( Policy::scalar_string( $ends_on ) );
		if ( '' === $raw_starts || '' === $raw_ends ) {
			return 'lps_term_date_required';
		}
		$starts = self::normalize_iso_date( $starts_on );
		$ends   = self::normalize_iso_date( $ends_on );
		if ( '' === $starts || '' === $ends ) {
			return 'lps_invalid_term_date';
		}
		return $starts <= $ends ? null : 'lps_invalid_term_date_order';
	}

	/**
	 * Returns teaching field-level publish violations.
	 *
	 * @param string               $post_type Teaching record type.
	 * @param array<string, mixed> $record    Candidate record.
	 * @return array<string, string>
	 */
	public static function publish_errors( string $post_type, array $record ): array {
		$errors = array();
		switch ( $post_type ) {
			case 'lps_course':
				foreach ( array( '_lps_course_code', '_lps_course_level', '_lps_calendar_key' ) as $key ) {
					if ( '' === trim( Policy::scalar_string( $record[ $key ] ?? '' ) ) ) {
						$errors[ $key ] = 'lps_required_' . substr( $key, 5 );
					}
				}
				if ( ! in_array( $record['_lps_course_level'] ?? '', self::COURSE_LEVELS, true ) ) {
					$errors['_lps_course_level'] = 'lps_invalid_course_level';
				}
				break;
			case 'lps_term':
				foreach ( array( '_lps_calendar_key', '_lps_term_code', '_lps_period_label', '_lps_period_type' ) as $key ) {
					if ( '' === trim( Policy::scalar_string( $record[ $key ] ?? '' ) ) ) {
						$errors[ $key ] = 'lps_required_' . substr( $key, 5 );
					}
				}
				if ( ! in_array( $record['_lps_period_type'] ?? '', self::PERIOD_TYPES, true ) ) {
					$errors['_lps_period_type'] = 'lps_invalid_period_type';
				}
				$date_error = self::date_order_error( $record['_lps_starts_on'] ?? '', $record['_lps_ends_on'] ?? '' );
				if ( 'lps_term_date_required' === $date_error ) {
					$missing_field            = '' === trim( Policy::scalar_string( $record['_lps_starts_on'] ?? '' ) ) ? '_lps_starts_on' : '_lps_ends_on';
					$errors[ $missing_field ] = $date_error;
				} elseif ( 'lps_invalid_term_date' === $date_error ) {
					$date_field            = '' === self::normalize_iso_date( $record['_lps_starts_on'] ?? '' ) ? '_lps_starts_on' : '_lps_ends_on';
					$errors[ $date_field ] = $date_error;
				} elseif ( null !== $date_error ) {
					$errors['_lps_ends_on'] = $date_error;
				}
				$expected_token = self::term_token( $record['_lps_calendar_key'] ?? '', $record['_lps_term_code'] ?? '' );
				if ( '' !== $expected_token && ! hash_equals( $expected_token, Policy::scalar_string( $record['_lps_term_token'] ?? '' ) ) ) {
					$errors['_lps_term_token'] = 'lps_term_token_mismatch';
				}
				break;
			case 'lps_offering':
				if ( '' === self::normalize_section_key( $record['_lps_section_key'] ?? '' ) ) {
					$errors['_lps_section_key'] = 'lps_required_section_key';
				}
				$temporal_status = Policy::scalar_string( $record['_lps_temporal_status'] ?? '' );
				if ( '' !== $temporal_status && ! in_array( $temporal_status, self::TEMPORAL_STATUSES, true ) ) {
					$errors['_lps_temporal_status'] = 'lps_invalid_temporal_status';
				}
				$lms_url = trim( Policy::scalar_string( $record['_lps_lms_url'] ?? '' ) );
				if ( '' !== $lms_url && ! filter_var( $record['_lps_lms_url_approved'] ?? false, FILTER_VALIDATE_BOOLEAN ) ) {
					$errors['_lps_lms_url'] = 'lps_lms_url_not_approved';
				}
				break;
			case 'lps_unit':
				if ( '' === self::normalize_key( $record['_lps_anchor'] ?? '' ) ) {
					$errors['_lps_anchor'] = 'lps_required_anchor';
				}
				if ( 1 > Policy::sanitize_integer( $record['_lps_position'] ?? 0 ) ) {
					$errors['_lps_position'] = 'lps_invalid_unit_position';
				}
				$topic = trim( Policy::scalar_string( $record['_lps_topic_date'] ?? '' ) );
				if ( '' !== $topic && '' === self::normalize_iso_date( $topic ) ) {
					$errors['_lps_topic_date'] = 'lps_invalid_topic_date';
				}
				break;
			case 'lps_resource':
				if ( ! in_array( $record['_lps_resource_type'] ?? '', self::RESOURCE_TYPES, true ) ) {
					$errors['_lps_resource_type'] = 'lps_invalid_resource_type';
				}
				if ( '' === self::normalize_language( $record['_lps_resource_language'] ?? '' ) ) {
					$errors['_lps_resource_language'] = 'lps_invalid_resource_language';
				}
				if ( ! in_array( $record['_lps_release_state'] ?? '', self::RELEASE_STATES, true ) ) {
					$errors['_lps_release_state'] = 'lps_invalid_release_state';
				}
				if ( ! in_array( $record['_lps_rights_review'] ?? '', self::REVIEW_STATES, true ) ) {
					$errors['_lps_rights_review'] = 'lps_invalid_rights_review';
				}
				if ( ! in_array( $record['_lps_accessibility_review'] ?? '', self::REVIEW_STATES, true ) ) {
					$errors['_lps_accessibility_review'] = 'lps_invalid_accessibility_review';
				}
				$version_id   = trim( Policy::scalar_string( $record['_lps_version_id'] ?? '' ) );
				$external_url = trim( Policy::scalar_string( $record['_lps_external_url'] ?? '' ) );
				if ( '' === $version_id && '' === $external_url ) {
					$errors['_lps_version_id'] = 'lps_resource_version_or_url_required';
				} elseif ( '' !== $version_id && '' !== $external_url ) {
					$errors['_lps_external_url'] = 'lps_resource_version_and_url_conflict';
				}
				if ( '' !== $version_id && '' === self::normalize_version_id( $version_id ) ) {
					$errors['_lps_version_id'] = 'lps_invalid_version_id';
				}
				if ( '' !== $version_id ) {
					if ( ! in_array( $record['_lps_scan_state'] ?? '', self::SCAN_STATES, true ) ) {
						$errors['_lps_scan_state'] = 'lps_invalid_scan_state';
					}
					if ( '' === self::normalize_key( $record['_lps_storage_key'] ?? '' ) ) {
						$errors['_lps_storage_key'] = 'lps_required_storage_key';
					}
					if ( 1 !== preg_match( '/^[0-9a-f]{64}$/', Policy::scalar_string( $record['_lps_sha256'] ?? '' ) ) ) {
						$errors['_lps_sha256'] = 'lps_invalid_sha256';
					}
					if ( 1 > Policy::sanitize_integer( $record['_lps_byte_size'] ?? 0 ) ) {
						$errors['_lps_byte_size'] = 'lps_invalid_byte_size';
					}
					if ( '' === self::normalize_key( $record['_lps_mime_type'] ?? '' ) ) {
						$errors['_lps_mime_type'] = 'lps_required_mime_type';
					}
				}
				$release_state = Policy::scalar_string( $record['_lps_release_state'] ?? '' );
				if ( 'released' === $release_state ) {
					if ( '' !== $version_id && 'clean' !== ( $record['_lps_scan_state'] ?? '' ) ) {
						$errors['_lps_scan_state'] = 'lps_resource_scan_not_clean';
					}
					if ( 'approved' !== ( $record['_lps_rights_review'] ?? '' ) ) {
						$errors['_lps_rights_review'] = 'lps_resource_rights_not_approved';
					}
					if ( 'approved' !== ( $record['_lps_accessibility_review'] ?? '' ) ) {
						$errors['_lps_accessibility_review'] = 'lps_resource_accessibility_not_approved';
					}
				}
				if ( 'scheduled' === $release_state && '' === trim( Policy::scalar_string( $record['_lps_release_at'] ?? '' ) ) ) {
					$errors['_lps_release_at'] = 'lps_release_at_required';
				}
				if ( 'withdrawn' === $release_state && '' === trim( Policy::scalar_string( $record['_lps_withdrawn_at'] ?? '' ) ) ) {
					$errors['_lps_withdrawn_at'] = 'lps_withdrawn_at_required';
				}
				break;
			default:
				break;
		}
		return $errors;
	}

	/**
	 * Returns teaching relationship cardinality and role violations.
	 *
	 * @param string                           $relationship_type Canonical relationship type.
	 * @param array<int, array<string, mixed>> $rows              Normalized candidate rows.
	 * @return array<int, string>
	 */
	public static function relationship_errors( string $relationship_type, array $rows ): array {
		$errors = array();
		switch ( $relationship_type ) {
			case 'offering_course':
				if ( 0 === count( $rows ) ) {
					$errors[] = 'lps_offering_course_required';
				} elseif ( 1 < count( $rows ) ) {
					$errors[] = 'lps_offering_multiple_courses';
				}
				break;
			case 'offering_term':
				if ( 0 === count( $rows ) ) {
					$errors[] = 'lps_offering_term_required';
				} elseif ( 1 < count( $rows ) ) {
					$errors[] = 'lps_offering_multiple_terms';
				}
				break;
			case 'unit_offering':
				if ( 0 === count( $rows ) ) {
					$errors[] = 'lps_unit_offering_required';
				} elseif ( 1 < count( $rows ) ) {
					$errors[] = 'lps_unit_multiple_offerings';
				}
				break;
			case 'resource_offering':
				if ( 0 === count( $rows ) ) {
					$errors[] = 'lps_resource_offering_required';
				} elseif ( 1 < count( $rows ) ) {
					$errors[] = 'lps_resource_multiple_offerings';
				}
				break;
			case 'resource_unit':
				if ( 1 < count( $rows ) ) {
					$errors[] = 'lps_resource_multiple_units';
				}
				break;
			case 'teaching_team':
				if ( 0 === count( $rows ) ) {
					$errors[] = 'lps_teaching_team_required';
				} elseif ( ! in_array( 'lead', array_column( $rows, 'relationship_role' ), true ) ) {
					$errors[] = 'lps_teaching_lead_required';
				}
				break;
			default:
				break;
		}
		return array_values( array_unique( $errors ) );
	}

	/**
	 * Returns the cross-offering unit violation for a resource.
	 *
	 * A resource's unit must belong to the resource's own offering.
	 *
	 * @param int $resource_offering_id Offering owning the resource.
	 * @param int $unit_offering_id     Offering owning the referenced unit.
	 */
	public static function resource_unit_error( int $resource_offering_id, int $unit_offering_id ): ?string {
		if ( 0 >= $unit_offering_id ) {
			return 'lps_resource_unit_orphan';
		}
		return $resource_offering_id === $unit_offering_id ? null : 'lps_cross_offering_unit_reference';
	}

	/**
	 * Returns the first copy-forward plan violation.
	 *
	 * The operation contract: new records are created in draft only; descriptive
	 * structure, unit ordering, and syllabus text are copied; a new term/section
	 * and explicit teaching-team review are required; announcements, deadlines,
	 * release times, active notices, and unreleased or withdrawn resources are
	 * reset; already-public immutable versions are reused only after explicit
	 * selection; one server-side operation completes with a manifest or leaves
	 * no half-published offering; retrying the same operation identifier must
	 * not duplicate it.
	 *
	 * @param array<string, mixed> $plan Candidate copy-forward plan.
	 */
	public static function copy_forward_plan_error( array $plan ): ?string {
		if ( '' === self::normalize_operation_id( $plan['operation_id'] ?? '' ) ) {
			return 'lps_copy_forward_operation_id_required';
		}
		foreach ( array( 'source_offering_id', 'new_term_id' ) as $field ) {
			if ( 0 >= Policy::sanitize_integer( $plan[ $field ] ?? 0 ) ) {
				return 'lps_copy_forward_field_required';
			}
		}
		if ( '' === self::normalize_section_key( $plan['new_section'] ?? '' ) ) {
			return 'lps_copy_forward_field_required';
		}
		if ( true !== ( $plan['creates_draft'] ?? false ) ) {
			return 'lps_copy_forward_must_create_draft';
		}
		if ( true === ( $plan['publishes'] ?? false ) ) {
			return 'lps_copy_forward_publish_forbidden';
		}
		if ( true !== ( $plan['team_reviewed'] ?? false ) ) {
			return 'lps_copy_forward_team_review_required';
		}
		foreach ( array( 'announcements', 'deadlines', 'release_times', 'active_notices', 'unreleased_resources' ) as $reset ) {
			if ( ! in_array( $reset, is_array( $plan['resets'] ?? null ) ? $plan['resets'] : array(), true ) ) {
				return 'lps_copy_forward_reset_required';
			}
		}
		$selected = is_array( $plan['selected_version_ids'] ?? null ) ? $plan['selected_version_ids'] : array();
		$cleared  = is_array( $plan['cleared_version_ids'] ?? null ) ? $plan['cleared_version_ids'] : array();
		foreach ( $selected as $version_id ) {
			if ( ! in_array( $version_id, $cleared, true ) ) {
				return 'lps_copy_forward_unreleased_version';
			}
		}
		$source_offering = Policy::sanitize_integer( $plan['source_offering_id'] ?? 0 );
		$source_term     = Policy::sanitize_integer( $plan['source_term_id'] ?? 0 );
		$new_term        = Policy::sanitize_integer( $plan['new_term_id'] ?? 0 );
		$source_section  = self::normalize_section_key( $plan['source_section'] ?? '' );
		$new_section     = self::normalize_section_key( $plan['new_section'] ?? '' );
		if ( $source_term === $new_term && $source_section === $new_section ) {
			return 'lps_copy_forward_identity_collision';
		}
		return null;
	}

	/**
	 * Builds the deterministic copy-forward manifest for a valid plan.
	 *
	 * @param array<string, mixed> $plan Validated copy-forward plan.
	 * @return array{operation_id: string, source_offering_id: int, new_term_id: int, new_section_key: string, creates_draft: true, publishes: false, resets: array<int, string>, selected_version_ids: array<int, string>, atomic: true, idempotent_retry: true}
	 */
	public static function copy_forward_manifest( array $plan ): array {
		return array(
			'operation_id'         => self::normalize_operation_id( $plan['operation_id'] ?? '' ),
			'source_offering_id'   => Policy::sanitize_integer( $plan['source_offering_id'] ?? 0 ),
			'new_term_id'          => Policy::sanitize_integer( $plan['new_term_id'] ?? 0 ),
			'new_section_key'      => self::normalize_section_key( $plan['new_section'] ?? '' ),
			'creates_draft'        => true,
			'publishes'            => false,
			'resets'               => array_values( array_filter( is_array( $plan['resets'] ?? null ) ? $plan['resets'] : array(), 'is_string' ) ),
			'selected_version_ids' => array_values( array_filter( is_array( $plan['selected_version_ids'] ?? null ) ? $plan['selected_version_ids'] : array(), 'is_string' ) ),
			'atomic'               => true,
			'idempotent_retry'     => true,
		);
	}

	/**
	 * Returns today's date in the configured institutional timezone.
	 *
	 * @param string $timezone Institutional timezone identifier.
	 */
	public static function today( string $timezone = self::DEFAULT_TIMEZONE ): string {
		$timezone = self::normalize_timezone( $timezone );
		$timezone = '' === $timezone ? self::DEFAULT_TIMEZONE : $timezone;
		try {
			return ( new \DateTimeImmutable( 'now', new \DateTimeZone( $timezone ) ) )->format( 'Y-m-d' );
		} catch ( \Exception $exception ) {
			unset( $exception );
			return gmdate( 'Y-m-d' );
		}
	}

	/**
	 * Parses one boundary timestamp to a Unix instant, or null.
	 *
	 * @param mixed $value Boundary input.
	 */
	private static function instant( mixed $value ): ?int {
		$text = trim( Policy::scalar_string( $value ) );
		if ( '' === $text ) {
			return null;
		}
		$timestamp = strtotime( $text );
		return false === $timestamp ? null : $timestamp;
	}

	/**
	 * Normalizes one machine key: folded, lowercased, hyphenated ASCII.
	 *
	 * @param mixed $value Boundary input.
	 */
	private static function normalize_key( mixed $value ): string {
		$key = strtolower( self::fold( Policy::scalar_string( $value ) ) );
		$key = (string) preg_replace( '/[^a-z0-9]+/', '-', $key );
		$key = (string) preg_replace( '/-{2,}/', '-', $key );
		return trim( $key, '-' );
	}

	/**
	 * Folds common accented Latin characters to ASCII deterministically.
	 *
	 * @param string $value Boundary text.
	 */
	private static function fold( string $value ): string {
		return strtr(
			$value,
			array(
				'á' => 'a',
				'à' => 'a',
				'â' => 'a',
				'ã' => 'a',
				'ä' => 'a',
				'å' => 'a',
				'é' => 'e',
				'è' => 'e',
				'ê' => 'e',
				'ë' => 'e',
				'í' => 'i',
				'ì' => 'i',
				'î' => 'i',
				'ï' => 'i',
				'ó' => 'o',
				'ò' => 'o',
				'ô' => 'o',
				'õ' => 'o',
				'ö' => 'o',
				'ú' => 'u',
				'ù' => 'u',
				'û' => 'u',
				'ü' => 'u',
				'ç' => 'c',
				'ñ' => 'n',
				'ý' => 'y',
				'ÿ' => 'y',
				'ß' => 'ss',
				'Á' => 'A',
				'À' => 'A',
				'Â' => 'A',
				'Ã' => 'A',
				'Ä' => 'A',
				'Å' => 'A',
				'É' => 'E',
				'È' => 'E',
				'Ê' => 'E',
				'Ë' => 'E',
				'Í' => 'I',
				'Ì' => 'I',
				'Î' => 'I',
				'Ï' => 'I',
				'Ó' => 'O',
				'Ò' => 'O',
				'Ô' => 'O',
				'Õ' => 'O',
				'Ö' => 'O',
				'Ú' => 'U',
				'Ù' => 'U',
				'Û' => 'U',
				'Ü' => 'U',
				'Ç' => 'C',
				'Ñ' => 'N',
				'Ý' => 'Y',
			)
		);
	}

	/**
	 * Builds one record-type definition matching the Contracts shape.
	 *
	 * @param string $plural    Plural label.
	 * @param string $singular  Singular label.
	 * @param string $rest_base REST collection base.
	 * @param bool   $is_public Whether public queries are supported.
	 * @return array{labels: array{name: string, singular_name: string}, public: bool, builtin: bool, show_in_rest: bool, rest_base: string, supports: list<string>}
	 */
	private static function type( string $plural, string $singular, string $rest_base, bool $is_public = true ): array {
		return array(
			'labels'       => array(
				'name'          => $plural,
				'singular_name' => $singular,
			),
			'public'       => $is_public,
			'builtin'      => false,
			'show_in_rest' => true,
			'rest_base'    => $rest_base,
			'supports'     => array( 'title', 'editor', 'excerpt', 'author', 'revisions', 'custom-fields' ),
		);
	}

	/**
	 * Builds the WordPress registration arguments for one teaching type.
	 *
	 * @param string $post_type Teaching record type.
	 * @return array{labels: array{name: string, singular_name: string}, public: bool, show_in_rest: bool, rest_base: string, supports: list<string>, menu_icon: string, has_archive: bool, rewrite: bool, capability_type: array{string, string}, map_meta_cap: bool}
	 */
	private static function registration_args( string $post_type ): array {
		$args = self::post_types()[ $post_type ];
		unset( $args['builtin'] );
		$args['menu_icon']       = 'dashicons-welcome-learn-more';
		$args['has_archive']     = (bool) $args['public'];
		$args['rewrite']         = (bool) $args['public'];
		$args['capability_type'] = array( $post_type, $post_type . 's' );
		$args['map_meta_cap']    = true;
		return $args;
	}
}
