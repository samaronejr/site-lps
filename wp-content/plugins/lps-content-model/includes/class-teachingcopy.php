<?php
/**
 * Safe next-term copy-forward and history-preserving correction propagation.
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
require_once __DIR__ . '/class-relationships.php';
require_once __DIR__ . '/class-teachingcontracts.php';
require_once __DIR__ . '/class-teachingmigrations.php';
require_once __DIR__ . '/class-teachingrecords.php';
require_once __DIR__ . '/class-teachingresources.php';
require_once __DIR__ . '/class-translationpolicy.php';
require_once __DIR__ . '/class-translations.php';
require_once __DIR__ . '/class-audit.php';

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Rollback and replay reads must bypass object caches.
/**
 * Owns the server-side copy-forward operation and correction propagation.
 *
 * Copy-forward is one auditable server operation: it reads a source offering,
 * creates a new draft offering on an explicitly declared term/section with an
 * explicitly reviewed teaching team, clones the unit structure, and clones
 * every resource as a draft. Sensitive and date-bound fields — LMS links,
 * cancellation flags, release states, release times, withdrawal timestamps,
 * topic dates and every unreleased or withdrawn payload — are reset rather
 * than carried. An already-public immutable version is reused only when the
 * caller explicitly selects it and it is still effectively released, cleared
 * and clean; every other resource arrives as an empty draft stub.
 *
 * The operation is idempotent on its `operation_id`: a completed operation
 * replays its stored manifest, and a retry that loses the identity race to
 * its own earlier attempt replays the same draft instead of minting a second
 * one. Any failure inside the boundary rolls the partial graph back — cloned
 * resources, units, relationship rows, the registry claim and the draft — so
 * a retry never meets a half-published offering.
 *
 * Corrections never propagate silently: `propagate_correction` rewrites the
 * declared fields only on the explicitly listed affected offerings of the
 * same course, saving a WordPress revision per target so history is
 * preserved, and records the operation in the audit ledger. Retrying a
 * correction operation replays its stored manifest without new revisions.
 */
final class TeachingCopy {
	/** Option prefix storing completed copy-forward manifests. */
	private const COPY_OPTION_PREFIX = 'lps_copy_op_';

	/** Option prefix storing completed correction manifests. */
	private const CORRECTION_OPTION_PREFIX = 'lps_correction_op_';

	/** Offering metadata copied forward into the new draft. */
	private const OFFERING_COPY_META = array( '_lps_schedule', '_lps_venue', '_lps_syllabus_snapshot' );

	/** Offering metadata reset on the new draft (sensitive or term-bound). */
	private const OFFERING_RESET_META = array( '_lps_lms_url', '_lps_lms_url_approved', '_lps_cancelled' );

	/** Unit metadata copied forward; `_lps_topic_date` is always reset. */
	private const UNIT_COPY_META = array( '_lps_anchor', '_lps_position' );

	/** Resource metadata copied forward into the draft stub. */
	private const RESOURCE_COPY_META = array( '_lps_resource_type', '_lps_resource_language' );

	/** Reset list reported in the manifest and required by the contract. */
	private const RESETS = array( 'announcements', 'deadlines', 'release_times', 'active_notices', 'unreleased_resources' );

	/**
	 * Whether the boundary is inside a copy-forward operation.
	 *
	 * The scoped-role relationship guard correctly denies teaching-team,
	 * course and term rows to direct scoped writes; the copy operation is the
	 * authorized boundary for those rows, so `Roles::scoped_relationship_error`
	 * consults this flag exactly like `Roles::$grant_syncing`.
	 *
	 * @var bool
	 */
	private static bool $in_operation = false;

	/** Returns whether the boundary is inside a copy-forward operation. */
	public static function in_operation(): bool {
		return self::$in_operation;
	}

	/**
	 * Copies one offering forward into a new term/section as a draft.
	 *
	 * @param array<string, mixed> $input Boundary input.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function copy_forward( array $input ): array|WP_Error {
		$operation_id = TeachingContracts::normalize_operation_id( $input['operation_id'] ?? '' );
		if ( '' === $operation_id ) {
			return self::error( 'lps_copy_forward_operation_id_required', 'A copy-forward operation identifier is required.', 'operation_id' );
		}
		$replayed = self::stored_replay( self::COPY_OPTION_PREFIX . $operation_id );
		if ( null !== $replayed ) {
			return $replayed;
		}

		$source_id = TeachingContracts::authoritative_id( Policy::sanitize_integer( $input['source_offering_id'] ?? 0 ) );
		$source    = 0 < $source_id ? get_post( $source_id ) : null;
		if ( ! $source instanceof WP_Post || 'lps_offering' !== $source->post_type || 'trash' === $source->post_status ) {
			return self::error( 'lps_teaching_offering_invalid', 'The copy requires an existing source offering record.', 'source_offering_id', 404 );
		}
		$new_term_id = TeachingContracts::authoritative_id( Policy::sanitize_integer( $input['new_term_id'] ?? ( $input['term_id'] ?? 0 ) ) );
		$new_term    = 0 < $new_term_id ? get_post( $new_term_id ) : null;
		if ( ! $new_term instanceof WP_Post || 'lps_term' !== $new_term->post_type || 'trash' === $new_term->post_status ) {
			return self::error( 'lps_teaching_term_invalid', 'The copy requires an existing target term record.', 'new_term_id' );
		}
		$new_section = TeachingContracts::normalize_section_key( $input['new_section'] ?? ( $input['section'] ?? '' ) );
		if ( '' === $new_section ) {
			return self::error( 'lps_required_section_key', 'A target section key is required.', 'new_section' );
		}
		if ( true !== ( $input['team_reviewed'] ?? false ) ) {
			return self::error( 'lps_copy_forward_team_review_required', 'The new teaching team must be explicitly reviewed.', 'team_reviewed' );
		}
		if ( ! is_array( $input['team'] ?? null ) || array() === $input['team'] ) {
			return self::error( 'lps_teaching_team_required', 'A reviewed teaching team with a lead is required.', 'team' );
		}
		$source_identity = TeachingRecords::offering_identity_for( $source_id );
		if ( null === $source_identity ) {
			return self::error( 'lps_teaching_offering_invalid', 'The source offering has no canonical identity.', 'source_offering_id' );
		}
		$course_calendar = Policy::scalar_string( get_post_meta( $source_identity['course_id'], '_lps_calendar_key', true ) );
		$term_calendar   = Policy::scalar_string( get_post_meta( $new_term_id, '_lps_calendar_key', true ) );
		if ( '' !== $course_calendar && '' !== $term_calendar && $course_calendar !== $term_calendar ) {
			return self::error( 'lps_copy_forward_calendar_mismatch', 'The target term belongs to a different calendar than the course.', 'new_term_id' );
		}
		$selected = self::selected_version_ids( $input['selected_version_ids'] ?? array() );
		if ( $selected instanceof WP_Error ) {
			return $selected;
		}
		$now        = gmdate( 'c' );
		$eligible   = self::reusable_version_ids( $source_id, $now );
		$plan_error = TeachingContracts::copy_forward_plan_error(
			array(
				'operation_id'         => $operation_id,
				'source_offering_id'   => $source_id,
				'source_term_id'       => $source_identity['term_id'],
				'source_section'       => $source_identity['section_key'],
				'new_term_id'          => $new_term_id,
				'new_section'          => $new_section,
				'creates_draft'        => true,
				'publishes'            => false,
				'team_reviewed'        => true,
				'resets'               => self::RESETS,
				'selected_version_ids' => $selected,
				'cleared_version_ids'  => $eligible,
			)
		);
		if ( null !== $plan_error ) {
			return self::error( $plan_error, 'The copy-forward plan violates the operation contract.', 'plan' );
		}

		$created            = array(
			'offering_id'  => 0,
			'unit_ids'     => array(),
			'resource_ids' => array(),
		);
		$committed          = false;
		self::$in_operation = true;
		try {
			$offering = self::create_copied_offering( $source, $source_identity, $new_term_id, $new_section, $input, $operation_id );
			if ( $offering instanceof WP_Error ) {
				$replayed_conflict = self::replay_conflict( $offering, $operation_id );
				return null !== $replayed_conflict ? $replayed_conflict : $offering;
			}
			$created['offering_id'] = Policy::sanitize_integer( $offering['id'] ?? 0 );
			$step                   = self::step_error( 'offering', $operation_id );
			if ( null !== $step ) {
				return $step;
			}
			$unit_map = self::copy_units( $source_id, $created['offering_id'], $created['unit_ids'], $operation_id );
			if ( $unit_map instanceof WP_Error ) {
				return $unit_map;
			}
			$resources = self::copy_resources( $source_id, $created['offering_id'], $unit_map, $selected, $now, $created['resource_ids'], $operation_id );
			if ( $resources instanceof WP_Error ) {
				return $resources;
			}
			$step = self::step_error( 'finalize', $operation_id );
			if ( null !== $step ) {
				return $step;
			}
			$manifest = array(
				'operation_id'         => $operation_id,
				'source_offering_id'   => $source_id,
				'new_offering_id'      => $created['offering_id'],
				'new_term_id'          => $new_term_id,
				'new_section_key'      => $new_section,
				'unit_ids'             => $created['unit_ids'],
				'resource_ids'         => $created['resource_ids'],
				'resets'               => self::RESETS,
				'selected_version_ids' => $selected,
				'creates_draft'        => true,
				'publishes'            => false,
				'atomic'               => true,
				'idempotent_retry'     => true,
				'created_at'           => $now,
				'replayed'             => false,
			);
			Audit::record(
				'create',
				$created['offering_id'],
				0,
				array(
					'operation_id'       => $operation_id,
					'source_offering_id' => $source_id,
					'new_term_id'        => $new_term_id,
					'new_section_key'    => $new_section,
					'copied_units'       => count( $created['unit_ids'] ),
					'copied_resources'   => count( $created['resource_ids'] ),
					'reused_versions'    => count( $selected ),
				)
			);
			self::store_manifest( self::COPY_OPTION_PREFIX . $operation_id, $manifest );
			$committed = true;
			return $manifest;
		} finally {
			self::$in_operation = false;
			if ( ! $committed && 0 < $created['offering_id'] ) {
				self::rollback( $created );
			}
		}
	}

	/**
	 * Propagates declared field corrections to explicitly selected offerings.
	 *
	 * History is preserved: every affected offering keeps its record and gains
	 * a WordPress revision capturing the corrected fields, and the operation is
	 * audited on the source offering. Offerings not listed are never touched.
	 *
	 * @param array<string, mixed> $input Boundary input.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function propagate_correction( array $input ): array|WP_Error {
		$operation_id = TeachingContracts::normalize_operation_id( $input['operation_id'] ?? '' );
		if ( '' === $operation_id ) {
			return self::error( 'lps_correction_operation_id_required', 'A correction operation identifier is required.', 'operation_id' );
		}
		$replayed = self::stored_replay( self::CORRECTION_OPTION_PREFIX . $operation_id );
		if ( null !== $replayed ) {
			return $replayed;
		}
		$source_id = TeachingContracts::authoritative_id( Policy::sanitize_integer( $input['source_offering_id'] ?? 0 ) );
		$source    = 0 < $source_id ? get_post( $source_id ) : null;
		if ( ! $source instanceof WP_Post || 'lps_offering' !== $source->post_type || 'trash' === $source->post_status ) {
			return self::error( 'lps_teaching_offering_invalid', 'The correction requires an existing source offering record.', 'source_offering_id', 404 );
		}
		$fields      = self::string_keyed( $input['fields'] ?? null );
		$field_error = self::correction_fields_error( $fields );
		if ( null !== $field_error ) {
			return self::error( $field_error, 'The correction fields violate the propagation contract.', 'fields' );
		}
		$affected = array();
		foreach ( is_array( $input['affected_offering_ids'] ?? null ) ? $input['affected_offering_ids'] : array() as $candidate ) {
			$target_id = TeachingContracts::authoritative_id( Policy::sanitize_integer( $candidate ) );
			if ( 0 < $target_id ) {
				$affected[ $target_id ] = true;
			}
		}
		$affected = array_keys( $affected );
		if ( array() === $affected ) {
			return self::error( 'lps_correction_affected_required', 'The affected offerings must be explicitly selected.', 'affected_offering_ids' );
		}
		$source_identity = TeachingRecords::offering_identity_for( $source_id );
		if ( null === $source_identity ) {
			return self::error( 'lps_teaching_offering_invalid', 'The source offering has no canonical identity.', 'source_offering_id' );
		}
		$sanitized = self::sanitize_correction_fields( $fields );
		$applied   = array();
		foreach ( $affected as $target_id ) {
			$target = get_post( $target_id );
			if ( ! $target instanceof WP_Post || 'lps_offering' !== $target->post_type || 'trash' === $target->post_status ) {
				return self::error( 'lps_teaching_offering_invalid', 'An affected offering record does not exist.', 'affected_offering_ids', 400, array( 'offering_id' => $target_id ) );
			}
			$target_identity = TeachingRecords::offering_identity_for( $target_id );
			if ( null === $target_identity || $target_identity['course_id'] !== $source_identity['course_id'] ) {
				return self::error( 'lps_correction_course_mismatch', 'Corrections propagate only within the same course.', 'affected_offering_ids', 400, array( 'offering_id' => $target_id ) );
			}
			foreach ( $sanitized as $key => $value ) {
				update_post_meta( $target_id, $key, $value );
				$stored = get_post_meta( $target_id, $key, true );
				if ( $stored !== $value && ! ( is_bool( $value ) && (bool) $stored === $value ) ) {
					return self::error(
						'lps_correction_field_denied',
						'A field write was denied by the scoped-role boundary.',
						'fields',
						400,
						array(
							'offering_id' => $target_id,
							'field'       => $key,
						)
					);
				}
			}
			$revision_id = self::save_revision( $target_id );
			TeachingRecords::refresh_temporal_status( $target_id );
			self::resync_search_index( $target_id );
			$applied[] = array(
				'offering_id' => $target_id,
				'revision_id' => $revision_id,
			);
		}
		$manifest = array(
			'operation_id'          => $operation_id,
			'source_offering_id'    => $source_id,
			'fields'                => array_keys( $sanitized ),
			'affected_offering_ids' => $affected,
			'applied'               => $applied,
			'created_at'            => gmdate( 'c' ),
			'replayed'              => false,
		);
		self::store_manifest( self::CORRECTION_OPTION_PREFIX . $operation_id, $manifest );
		Audit::record(
			'edit',
			$source_id,
			0,
			array(
				'operation_id'          => $operation_id,
				'decision'              => 'propagate-correction',
				'fields'                => implode( ',', array_keys( $sanitized ) ),
				'affected_offering_ids' => implode( ',', array_map( 'strval', $affected ) ),
			)
		);
		return $manifest;
	}

	/**
	 * Returns the offering metadata carried into the new draft.
	 *
	 * Descriptive structure is copied; sensitive and term-bound fields are
	 * reset. When the source has no syllabus snapshot, the course's current
	 * syllabus is snapshotted so the new draft always carries explicit text.
	 *
	 * @param array<string, mixed> $source_meta    Source offering metadata.
	 * @param string               $course_syllabus Course default syllabus.
	 * @return array<string, mixed>
	 */
	public static function offering_copy_fields( array $source_meta, string $course_syllabus ): array {
		$fields = array();
		foreach ( self::OFFERING_COPY_META as $key ) {
			$fields[ $key ] = Policy::scalar_string( $source_meta[ $key ] ?? '' );
		}
		if ( '' === trim( $fields['_lps_syllabus_snapshot'] ) ) {
			$fields['_lps_syllabus_snapshot'] = $course_syllabus;
		}
		foreach ( self::OFFERING_RESET_META as $key ) {
			$fields[ $key ] = '_lps_lms_url_approved' === $key || '_lps_cancelled' === $key ? false : '';
		}
		return $fields;
	}

	/**
	 * Returns the unit metadata carried into the cloned unit.
	 *
	 * Anchor and position are structural and copy forward; the topic date is
	 * bound to the source term's calendar and is always reset.
	 *
	 * @param array<string, mixed> $source_meta Source unit metadata.
	 * @return array<string, mixed>
	 */
	public static function unit_copy_fields( array $source_meta ): array {
		$fields = array();
		foreach ( self::UNIT_COPY_META as $key ) {
			$fields[ $key ] = '_lps_position' === $key
				? Policy::sanitize_integer( $source_meta[ $key ] ?? 0 )
				: Policy::scalar_string( $source_meta[ $key ] ?? '' );
		}
		$fields['_lps_topic_date'] = '';
		return $fields;
	}

	/**
	 * Decides how one source resource is carried into the new draft.
	 *
	 * `reuse` means the clone selects the same immutable version — allowed
	 * only when the source resource is effectively released and the version
	 * was explicitly selected. `carry_public` means the clone keeps the
	 * public payload (external URL or selected version) and its review
	 * states; it is only ever true for effectively released resources.
	 * Selecting a version that is not currently public is a typed error.
	 *
	 * @param array<string, mixed> $resource_view Source resource view.
	 * @param array<int, string>   $selected      Explicitly selected version IDs.
	 * @param string               $now           Reference time (ISO-8601).
	 * @return array{reuse: bool, carry_public: bool, error: string|null}
	 */
	public static function resource_copy_decision( array $resource_view, array $selected, string $now ): array {
		$version_id   = Policy::scalar_string( $resource_view['_lps_version_id'] ?? '' );
		$external_url = Policy::scalar_string( $resource_view['_lps_external_url'] ?? '' );
		$released     = 'released' === TeachingContracts::effective_release_state(
			Policy::scalar_string( $resource_view['_lps_release_state'] ?? '' ),
			Policy::scalar_string( $resource_view['_lps_release_at'] ?? '' ),
			$now
		);
		$is_selected  = '' !== $version_id && in_array( $version_id, $selected, true );
		// Selection validity is gated once at plan level; an unreleased or
		// withdrawn resource that happens to reference a selected version is
		// reset like any other non-public resource, never a copy blocker.
		$reuse        = $released && '' !== $version_id && $is_selected;
		$carry_public = $released && ( $reuse || '' !== $external_url );
		return array(
			'reuse'        => $reuse,
			'carry_public' => $carry_public,
			'error'        => null,
		);
	}

	/**
	 * Returns the resource metadata carried into the cloned draft stub.
	 *
	 * Type and authored language copy forward; every release field, release
	 * time, withdrawal timestamp and version/storage identity is reset. The
	 * external URL and review states survive only for effectively released
	 * resources (`carry_public`); everything else arrives pending review.
	 *
	 * @param array<string, mixed>                   $source_meta Source resource metadata.
	 * @param array{reuse: bool, carry_public: bool} $decision Copy decision.
	 * @return array<string, mixed>
	 */
	public static function resource_copy_fields( array $source_meta, array $decision ): array {
		$carry_public = true === $decision['carry_public'];
		$fields       = array();
		foreach ( self::RESOURCE_COPY_META as $key ) {
			$fields[ $key ] = Policy::scalar_string( $source_meta[ $key ] ?? '' );
		}
		$fields['_lps_external_url']         = $carry_public ? Policy::scalar_string( $source_meta['_lps_external_url'] ?? '' ) : '';
		$fields['_lps_rights_review']        = $carry_public ? Policy::scalar_string( $source_meta['_lps_rights_review'] ?? '' ) : 'pending';
		$fields['_lps_accessibility_review'] = $carry_public ? Policy::scalar_string( $source_meta['_lps_accessibility_review'] ?? '' ) : 'pending';
		$fields['_lps_release_state']        = 'draft';
		$fields['_lps_release_at']           = '';
		$fields['_lps_withdrawn_at']         = '';
		return $fields;
	}

	/**
	 * Returns the first correction-field contract violation, or null.
	 *
	 * @param array<string, mixed> $fields Declared field corrections.
	 */
	public static function correction_fields_error( array $fields ): ?string {
		if ( array() === $fields ) {
			return 'lps_correction_fields_required';
		}
		foreach ( $fields as $key => $value ) {
			unset( $value );
			if ( ! in_array( $key, TeachingContracts::CORRECTABLE_OFFERING_FIELDS, true ) ) {
				return 'lps_correction_field_forbidden';
			}
		}
		return null;
	}

	/**
	 * Creates the new draft offering through the canonical boundary.
	 *
	 * @param WP_Post                                                  $source          Source offering record.
	 * @param array{course_id: int, term_id: int, section_key: string} $source_identity Canonical source identity.
	 * @param int                                                      $new_term_id     Target term record ID.
	 * @param string                                                   $new_section     Normalized target section key.
	 * @param array<string, mixed>                                     $input           Boundary input.
	 * @param string                                                   $operation_id    Operation identifier.
	 * @return array<string, mixed>|WP_Error
	 */
	private static function create_copied_offering( WP_Post $source, array $source_identity, int $new_term_id, string $new_section, array $input, string $operation_id ): array|WP_Error {
		$source_meta = self::stored_meta( 'lps_offering', $source->ID );
		$fields      = self::offering_copy_fields(
			$source_meta,
			Policy::scalar_string( get_post_meta( $source_identity['course_id'], '_lps_syllabus', true ) )
		);
		$title       = Policy::scalar_string( $input['title'] ?? '' );
		$created     = self::call_guarded(
			static fn(): array|WP_Error => TeachingRecords::create_offering(
				array(
					'title'     => '' !== $title ? $title : $source->post_title,
					'excerpt'   => Policy::scalar_string( $input['excerpt'] ?? $source->post_excerpt ),
					'content'   => Policy::scalar_string( $input['content'] ?? $source->post_content ),
					'course_id' => $source_identity['course_id'],
					'term_id'   => $new_term_id,
					'section'   => $new_section,
					'team'      => $input['team'],
					'status'    => 'draft',
					'meta'      => $fields,
				)
			)
		);
		if ( $created instanceof WP_Error ) {
			return $created;
		}
		$new_id = Policy::sanitize_integer( $created['id'] ?? 0 );
		self::system_meta( $new_id, '_lps_copy_operation_id', $operation_id );
		self::system_meta( $new_id, '_lps_copy_source_offering_id', $source->ID );
		return $created;
	}

	/**
	 * Clones every unit of the source offering, preserving order.
	 *
	 * @param int             $source_id   Source offering record ID.
	 * @param int             $new_id      New offering record ID.
	 * @param array<int, int> $unit_ids    Created unit IDs (by reference).
	 * @param string          $operation_id Operation identifier.
	 * @return array<int, int>|WP_Error Old unit ID to new unit ID map.
	 */
	private static function copy_units( int $source_id, int $new_id, array &$unit_ids, string $operation_id ): array|WP_Error {
		$map  = array();
		$rows = Relationships::reverse_for( $source_id, 'unit_offering' );
		usort(
			$rows,
			static function ( array $left, array $right ): int {
				$left_unit  = get_post( Policy::sanitize_integer( $left['source_post_id'] ) );
				$right_unit = get_post( Policy::sanitize_integer( $right['source_post_id'] ) );
				$left_pos   = $left_unit instanceof WP_Post ? Policy::sanitize_integer( get_post_meta( $left_unit->ID, '_lps_position', true ) ) : 0;
				$right_pos  = $right_unit instanceof WP_Post ? Policy::sanitize_integer( get_post_meta( $right_unit->ID, '_lps_position', true ) ) : 0;
				return $left_pos <=> $right_pos;
			}
		);
		foreach ( $rows as $row ) {
			$unit_id = Policy::sanitize_integer( $row['source_post_id'] );
			$unit    = 0 < $unit_id ? get_post( $unit_id ) : null;
			if ( ! $unit instanceof WP_Post || 'lps_unit' !== $unit->post_type || 'trash' === $unit->post_status ) {
				continue;
			}
			$created = self::call_guarded(
				static fn(): array|WP_Error => TeachingRecords::create_unit(
					array(
						'title'       => $unit->post_title,
						'excerpt'     => $unit->post_excerpt,
						'content'     => $unit->post_content,
						'offering_id' => $new_id,
						'status'      => 'draft',
						'meta'        => self::unit_copy_fields( self::stored_meta( 'lps_unit', $unit_id ) ),
					)
				)
			);
			if ( $created instanceof WP_Error ) {
				return $created;
			}
			$new_unit_id     = Policy::sanitize_integer( $created['id'] ?? 0 );
			$map[ $unit_id ] = $new_unit_id;
			$unit_ids[]      = $new_unit_id;
			$step            = self::step_error( 'unit', $operation_id );
			if ( null !== $step ) {
				return $step;
			}
		}
		return $map;
	}

	/**
	 * Clones every resource of the source offering as a draft.
	 *
	 * @param int                $source_id   Source offering record ID.
	 * @param int                $new_id      New offering record ID.
	 * @param array<int, int>    $unit_map    Old unit ID to new unit ID map.
	 * @param array<int, string> $selected    Explicitly selected version IDs.
	 * @param string             $now         Reference time (ISO-8601).
	 * @param array<int, int>    $resource_ids Created resource IDs (by reference).
	 * @param string             $operation_id Operation identifier.
	 * @return array<int, int>|WP_Error Created resource IDs.
	 */
	private static function copy_resources( int $source_id, int $new_id, array $unit_map, array $selected, string $now, array &$resource_ids, string $operation_id ): array|WP_Error {
		foreach ( Relationships::reverse_for( $source_id, 'resource_offering' ) as $row ) {
			$resource_id = Policy::sanitize_integer( $row['source_post_id'] );
			$resource    = 0 < $resource_id ? get_post( $resource_id ) : null;
			if ( ! $resource instanceof WP_Post || 'lps_resource' !== $resource->post_type || 'trash' === $resource->post_status ) {
				continue;
			}
			$meta      = self::stored_meta( 'lps_resource', $resource_id );
			$decision  = self::resource_copy_decision( $meta, $selected, $now );
			$fields    = self::resource_copy_fields( $meta, $decision );
			$input     = array(
				'title'       => $resource->post_title,
				'excerpt'     => $resource->post_excerpt,
				'content'     => $resource->post_content,
				'offering_id' => $new_id,
				'status'      => 'draft',
				'meta'        => $fields,
			);
			$unit_rows = Relationships::for_source( $resource_id, 'resource_unit' );
			$old_unit  = Policy::sanitize_integer( $unit_rows[0]['target_post_id'] ?? 0 );
			if ( 0 < $old_unit && isset( $unit_map[ $old_unit ] ) ) {
				$input['unit_id'] = $unit_map[ $old_unit ];
			}
			if ( true === $decision['reuse'] ) {
				$input['version_id'] = Policy::scalar_string( $meta['_lps_version_id'] ?? '' );
			}
			if ( '' !== $fields['_lps_external_url'] ) {
				$input['external_url'] = $fields['_lps_external_url'];
			}
			$created = self::call_guarded( static fn(): array|WP_Error => TeachingResources::create_resource( $input ) );
			if ( $created instanceof WP_Error ) {
				return $created;
			}
			$new_resource_id = Policy::sanitize_integer( $created['id'] ?? 0 );
			// Review states are outside the scoped-role field allowlist, so the
			// boundary stamps them itself — identical for editor- and
			// professor-initiated copies.
			self::system_meta( $new_resource_id, '_lps_rights_review', $fields['_lps_rights_review'] );
			self::system_meta( $new_resource_id, '_lps_accessibility_review', $fields['_lps_accessibility_review'] );
			$resource_ids[] = $new_resource_id;
			$step           = self::step_error( 'resource', $operation_id );
			if ( null !== $step ) {
				return $step;
			}
		}
		return $resource_ids;
	}

	/**
	 * Returns the version IDs reusable from the source offering right now.
	 *
	 * A version is reusable only while it is effectively released on a source
	 * resource and its registry row is cleared with a clean verdict — the same
	 * conditions the download resolver enforces on every request.
	 *
	 * @param int    $source_id Source offering record ID.
	 * @param string $now       Reference time (ISO-8601).
	 * @return array<int, string>
	 */
	private static function reusable_version_ids( int $source_id, string $now ): array {
		$eligible = array();
		foreach ( Relationships::reverse_for( $source_id, 'resource_offering' ) as $row ) {
			$resource_id = Policy::sanitize_integer( $row['source_post_id'] );
			$resource    = 0 < $resource_id ? get_post( $resource_id ) : null;
			if ( ! $resource instanceof WP_Post || 'lps_resource' !== $resource->post_type || 'trash' === $resource->post_status ) {
				continue;
			}
			$effective = TeachingContracts::effective_release_state(
				Policy::scalar_string( get_post_meta( $resource_id, '_lps_release_state', true ) ),
				Policy::scalar_string( get_post_meta( $resource_id, '_lps_release_at', true ) ),
				$now
			);
			if ( 'released' !== $effective ) {
				continue;
			}
			$version_id = TeachingContracts::normalize_version_id( get_post_meta( $resource_id, '_lps_version_id', true ) );
			if ( '' === $version_id ) {
				continue;
			}
			$version = TeachingResources::version_for_id( $version_id );
			if ( null === $version ) {
				continue;
			}
			if ( 'cleared' === Policy::scalar_string( $version['state'] ?? '' ) && 'clean' === Policy::scalar_string( $version['scan_verdict'] ?? '' ) ) {
				$eligible[] = $version_id;
			}
		}
		return array_values( array_unique( $eligible ) );
	}

	/**
	 * Normalizes the explicit version-reuse selection.
	 *
	 * @param mixed $input Boundary input.
	 * @return array<int, string>|WP_Error
	 */
	private static function selected_version_ids( mixed $input ): array|WP_Error {
		$selected = array();
		foreach ( is_array( $input ) ? $input : array() as $candidate ) {
			$version_id = TeachingContracts::normalize_version_id( $candidate );
			if ( '' === $version_id ) {
				return self::error( 'lps_invalid_version_id', 'A selected version identifier is malformed.', 'selected_version_ids' );
			}
			$selected[ $version_id ] = true;
		}
		return array_keys( $selected );
	}

	/**
	 * Returns a stored operation manifest marked as a replay, or null.
	 *
	 * @param string $option_name Manifest option key.
	 * @return array<string, mixed>|null
	 */
	private static function stored_replay( string $option_name ): ?array {
		$stored = get_option( $option_name, null );
		if ( ! is_array( $stored ) || array() === $stored ) {
			return null;
		}
		$manifest             = self::string_keyed( $stored );
		$manifest['replayed'] = true;
		return $manifest;
	}

	/**
	 * Replays a completed operation when the identity race is lost to itself.
	 *
	 * A 409 from the canonical boundary is a real duplicate-section conflict
	 * unless the winning record carries this operation's identifier — in which
	 * case the earlier attempt already did the work and the retry replays it.
	 *
	 * @param WP_Error $error        Boundary error.
	 * @param string   $operation_id Operation identifier.
	 * @return array<string, mixed>|null
	 */
	private static function replay_conflict( WP_Error $error, string $operation_id ): ?array {
		if ( 'lps_offering_identity_conflict' !== $error->get_error_code() ) {
			return null;
		}
		// A concurrent retry may have completed while this request raced: the
		// stored manifest is the authoritative replay.
		$stored = self::stored_replay( self::COPY_OPTION_PREFIX . $operation_id );
		if ( null !== $stored ) {
			return $stored;
		}
		$data     = $error->get_error_data();
		$existing = is_array( $data ) ? Policy::sanitize_integer( $data['existing_record'] ?? 0 ) : 0;
		if ( 0 >= $existing || Policy::scalar_string( get_post_meta( $existing, '_lps_copy_operation_id', true ) ) !== $operation_id ) {
			return null;
		}
		return self::rebuild_manifest( $existing, $operation_id );
	}

	/**
	 * Rebuilds a manifest from stored state when the option is gone.
	 *
	 * @param int    $new_id       New offering record ID.
	 * @param string $operation_id Operation identifier.
	 * @return array<string, mixed>
	 */
	private static function rebuild_manifest( int $new_id, string $operation_id ): array {
		$identity     = TeachingRecords::offering_identity_for( $new_id );
		$unit_ids     = array();
		$resource_ids = array();
		$selected     = array();
		foreach ( Relationships::reverse_for( $new_id, 'unit_offering' ) as $row ) {
			$unit_id = Policy::sanitize_integer( $row['source_post_id'] );
			if ( 0 < $unit_id ) {
				$unit_ids[] = $unit_id;
			}
		}
		foreach ( Relationships::reverse_for( $new_id, 'resource_offering' ) as $row ) {
			$resource_id = Policy::sanitize_integer( $row['source_post_id'] );
			if ( 0 >= $resource_id ) {
				continue;
			}
			$resource_ids[] = $resource_id;
			$version_id     = TeachingContracts::normalize_version_id( get_post_meta( $resource_id, '_lps_version_id', true ) );
			if ( '' !== $version_id ) {
				$selected[] = $version_id;
			}
		}
		return array(
			'operation_id'         => $operation_id,
			'source_offering_id'   => Policy::sanitize_integer( get_post_meta( $new_id, '_lps_copy_source_offering_id', true ) ),
			'new_offering_id'      => $new_id,
			'new_term_id'          => is_array( $identity ) ? $identity['term_id'] : 0,
			'new_section_key'      => is_array( $identity ) ? $identity['section_key'] : '',
			'unit_ids'             => $unit_ids,
			'resource_ids'         => $resource_ids,
			'resets'               => self::RESETS,
			'selected_version_ids' => array_values( array_unique( $selected ) ),
			'creates_draft'        => true,
			'publishes'            => false,
			'atomic'               => true,
			'idempotent_retry'     => true,
			'created_at'           => '',
			'replayed'             => true,
		);
	}

	/**
	 * Removes every trace of a failed operation, children first.
	 *
	 * Relationship rows are removed before posts so each draft is unreferenced
	 * and unpublished when WordPress evaluates deletion — the archival guard
	 * then deletes rather than archives, and the registry claim is released so
	 * a retry can claim the same identity.
	 *
	 * @param array{offering_id: int, unit_ids: array<int, int>, resource_ids: array<int, int>} $created Created record IDs.
	 */
	private static function rollback( array $created ): void {
		$wpdb   = self::database();
		$tables = Migrations::table_names( $wpdb );
		foreach ( array_merge( $created['resource_ids'], $created['unit_ids'], array( $created['offering_id'] ) ) as $post_id ) {
			if ( 0 < $post_id ) {
				$wpdb->delete( $tables['relationships'], array( 'source_post_id' => $post_id ), array( '%d' ) );
			}
		}
		if ( 0 < $created['offering_id'] ) {
			TeachingMigrations::release_post_claims( $created['offering_id'] );
		}
		foreach ( array_merge( $created['resource_ids'], $created['unit_ids'], array( $created['offering_id'] ) ) as $post_id ) {
			if ( 0 < $post_id ) {
				wp_delete_post( $post_id, true );
			}
		}
	}

	/**
	 * Runs one boundary call with the scoped-role field guard lifted.
	 *
	 * The guard correctly denies system and non-allowlisted fields to direct
	 * scoped writes; the copy operation is the authorized boundary for its own
	 * writes, so it lifts that one guard for the duration of each canonical
	 * create call and restores it immediately — the same convention
	 * `TeachingResources::system_meta` uses per write.
	 *
	 * @param callable(): (array<string, mixed>|WP_Error) $call Boundary call.
	 * @return array<string, mixed>|WP_Error
	 */
	private static function call_guarded( callable $call ): array|WP_Error {
		remove_filter( 'update_post_metadata', array( Plugin::class, 'protect_role_meta' ), 11 );
		try {
			return $call();
		} finally {
			add_filter( 'update_post_metadata', array( Plugin::class, 'protect_role_meta' ), 11, 5 );
		}
	}

	/**
	 * Writes system-owned metadata past the scoped-role field guard.
	 *
	 * @param int    $post_id Record ID.
	 * @param string $key     Metadata key.
	 * @param mixed  $value   Sanitized value.
	 */
	private static function system_meta( int $post_id, string $key, mixed $value ): void {
		remove_filter( 'update_post_metadata', array( Plugin::class, 'protect_role_meta' ), 11 );
		update_post_meta( $post_id, $key, $value );
		add_filter( 'update_post_metadata', array( Plugin::class, 'protect_role_meta' ), 11, 5 );
	}

	/**
	 * Returns the injected step failure for this operation, or null.
	 *
	 * The `lps_teaching_copy_step_error` filter is the operation's failure
	 * seam: infrastructure adapters (and the test fixture) may return a
	 * WP_Error for a named step — `offering`, `unit`, `resource`, `finalize` —
	 * and the boundary rolls the partial graph back. No filter is registered
	 * in production, so the seam is inert unless an adapter uses it.
	 *
	 * @param string $step         Step identifier.
	 * @param string $operation_id Operation identifier.
	 */
	private static function step_error( string $step, string $operation_id ): ?WP_Error {
		if ( ! function_exists( 'apply_filters' ) ) {
			return null;
		}
		/**
		 * Injected step failure, when any adapter vetoed the step.
		 *
		 * @var mixed $failure
		 */
		$failure = apply_filters( 'lps_teaching_copy_step_error', null, $step, $operation_id );
		return $failure instanceof WP_Error ? $failure : null;
	}

	/**
	 * Sanitizes declared correction fields through the registered normalizers.
	 *
	 * @param array<string, mixed> $fields Declared field corrections.
	 * @return array<string, mixed>
	 */
	private static function sanitize_correction_fields( array $fields ): array {
		$definitions = Contracts::meta_fields()['lps_offering'] ?? array();
		$sanitized   = array();
		foreach ( $fields as $key => $value ) {
			if ( ! in_array( $key, TeachingContracts::CORRECTABLE_OFFERING_FIELDS, true ) ) {
				continue;
			}
			$sanitized[ $key ] = isset( $definitions[ $key ]['sanitize_callback'] )
				? call_user_func( $definitions[ $key ]['sanitize_callback'], $value )
				: Policy::scalar_string( $value );
		}
		return $sanitized;
	}

	/**
	 * Saves a WordPress revision for one corrected offering.
	 *
	 * Meta-only corrections never trip the post-field change detector, so the
	 * check is lifted for this one call — the revision is the correction's
	 * history record and must exist even when only metadata changed.
	 *
	 * @param int $post_id Offering record ID.
	 */
	private static function save_revision( int $post_id ): int {
		if ( ! function_exists( 'wp_save_post_revision' ) ) {
			return 0;
		}
		$force       = static fn(): bool => false;
		add_filter( 'wp_save_post_revision_check_for_changes', $force, 99 );
		$revision_id = wp_save_post_revision( $post_id );
		remove_filter( 'wp_save_post_revision_check_for_changes', $force, 99 );
		return is_numeric( $revision_id ) ? (int) $revision_id : 0;
	}

	/**
	 * Re-indexes one corrected offering after the metadata-only write.
	 *
	 * @param int $post_id Offering record ID.
	 */
	private static function resync_search_index( int $post_id ): void {
		if ( ! class_exists( SearchIndex::class ) ) {
			require_once __DIR__ . '/class-searchindex.php';
		}
		SearchIndex::synchronize_post( $post_id );
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
	 * Narrows a boundary payload to a string-keyed map.
	 *
	 * @param mixed $value Boundary input.
	 * @return array<string, mixed>
	 */
	private static function string_keyed( mixed $value ): array {
		if ( ! is_array( $value ) ) {
			return array();
		}
		$map = array();
		foreach ( $value as $key => $item ) {
			if ( is_string( $key ) ) {
				$map[ $key ] = $item;
			}
		}
		return $map;
	}

	/**
	 * Persists the completed operation manifest for idempotent replay.
	 *
	 * @param string               $option_name Manifest option key.
	 * @param array<string, mixed> $manifest    Completed manifest.
	 */
	private static function store_manifest( string $option_name, array $manifest ): void {
		if ( ! add_option( $option_name, $manifest, '', false ) ) {
			update_option( $option_name, $manifest, false );
		}
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
