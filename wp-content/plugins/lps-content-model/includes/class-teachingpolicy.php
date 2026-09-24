<?php
/**
 * Offering-scoped editorial authorization policy.
 *
 * @package LPS\ContentModel
 */

declare(strict_types=1);

namespace LPS\ContentModel;

require_once __DIR__ . '/class-policy.php';
require_once __DIR__ . '/class-securitypolicy.php';
require_once __DIR__ . '/class-teachingcontracts.php';

/**
 * Pure offering-scoped authorization shared by WordPress adapters and tests.
 *
 * Scoped roles (`professor`, `delegate`) hold no collection or global editing
 * rights: every scoped action requires a persisted, unexpired, unrevoked grant
 * record on the account. Scope is resolved only from server-side state — the
 * grant list and canonical relationships — never from user-controlled owner,
 * person, offering, or relation IDs. Denial is the default for every unknown
 * role, action, post type, scope, or grant shape.
 */
final class TeachingPolicy {
	/** Roles whose teaching access is granted per offering or per scope. */
	public const SCOPED_ROLES = array( 'professor', 'delegate' );

	/** Grant scopes: one offering record, or the scoped news-publishing lane. */
	public const SCOPES = array( 'offering', 'news' );

	/** Record types a scoped role may ever touch. */
	public const SCOPED_POST_TYPES = array( 'lps_offering', 'lps_unit', 'lps_resource', 'lps_news' );

	/** Record types a scoped role may publish: their own offering plus materials and scoped news. */
	public const PUBLISHABLE_POST_TYPES = array( 'lps_offering', 'lps_unit', 'lps_resource', 'lps_news' );

	/** Canonical relationship that supplies the offering scope per record type. */
	public const RELATIONSHIP_SCOPES = array(
		'lps_unit'     => 'unit_offering',
		'lps_resource' => 'resource_offering',
	);

	/** Teaching record types that never carry an offering scope; editors only. */
	public const EDITOR_ONLY_POST_TYPES = array( 'lps_course', 'lps_term' );

	/** Grant actions reserved to institutional editors and administrators. */
	public const GRANT_ACTIONS = array( 'grant-scope', 'revoke-scope' );

	/** User-controlled fields that must never be treated as a scope source. */
	public const UNTRUSTED_SCOPE_INPUTS = array(
		'_lps_owner_user_id',
		'post_author',
		'author',
		'lps_parent_offering',
		'offering_id',
		'person_id',
		'target_post_id',
		'source_post_id',
		'teaching_team',
	);

	/** Scope sources the server actually trusts. */
	public const TRUSTED_SCOPE_SOURCES = array( 'persisted_grant', 'persisted_relationship' );

	/** Account-reference fields that exist for accountability, never identity. */
	public const ACCOUNT_LINKAGE_FIELDS = array( '_lps_owner_user_id', '_lps_translation_reviewer_id', '_lps_uploader_user_id' );

	/**
	 * Fields a scoped role may write, per record type.
	 *
	 * The allowlist is the boundary: every unlisted field — identity, owner,
	 * review, scan, storage, import, translation, and system fields — is denied.
	 *
	 * @var array<string, array<int, string>>
	 */
	private const FIELD_ALLOWLIST = array(
		'lps_offering' => array( 'post_title', 'post_excerpt', 'post_content', '_lps_schedule', '_lps_venue', '_lps_syllabus_snapshot' ),
		'lps_unit'     => array( 'post_title', 'post_excerpt', 'post_content', '_lps_anchor', '_lps_position', '_lps_topic_date' ),
		'lps_resource' => array( 'post_title', 'post_excerpt', 'post_content', '_lps_resource_type', '_lps_resource_language', '_lps_external_url', '_lps_release_state', '_lps_release_at', '_lps_withdrawn_at' ),
		'lps_news'     => array( 'post_title', 'post_excerpt', 'post_content', '_lps_canonical_date', '_lps_news_status' ),
	);

	/**
	 * Allowlisted fields reserved to the publishing scoped role.
	 *
	 * Release and withdrawal fields change public file delivery, so a delegate
	 * may never write them even though they are not the `publish` action.
	 *
	 * @var array<int, string>
	 */
	private const PROFESSOR_ONLY_FIELDS = array( '_lps_release_state', '_lps_release_at', '_lps_withdrawn_at', '_lps_news_status' );

	/**
	 * Returns whether a role is offering/scope-governed rather than collection-governed.
	 *
	 * @param string $role Policy role.
	 */
	public static function is_scoped_role( string $role ): bool {
		return in_array( $role, self::SCOPED_ROLES, true );
	}

	/**
	 * Normalizes one persisted grant record; unknown keys are dropped.
	 *
	 * @param mixed $grant Stored grant row.
	 * @return array{scope: string, offering_id: int, role: string, granted_at: string, expires_at: string, revoked_at: string, granted_by: int}
	 */
	public static function normalize_grant( mixed $grant ): array {
		$row = is_array( $grant ) ? $grant : array();
		return array(
			'scope'       => self::text( $row['scope'] ?? '' ),
			'offering_id' => Policy::sanitize_integer( $row['offering_id'] ?? 0 ),
			'role'        => self::text( $row['role'] ?? '' ),
			'granted_at'  => self::text( $row['granted_at'] ?? '' ),
			'expires_at'  => self::text( $row['expires_at'] ?? '' ),
			'revoked_at'  => self::text( $row['revoked_at'] ?? '' ),
			'granted_by'  => Policy::sanitize_integer( $row['granted_by'] ?? 0 ),
		);
	}

	/**
	 * Normalizes a stored grant list, dropping malformed rows.
	 *
	 * @param mixed $grants Stored grant list.
	 * @return array<int, array{scope: string, offering_id: int, role: string, granted_at: string, expires_at: string, revoked_at: string, granted_by: int}>
	 */
	public static function normalize_grants( mixed $grants ): array {
		if ( ! is_array( $grants ) ) {
			return array();
		}
		$normalized = array();
		foreach ( $grants as $grant ) {
			$row = self::normalize_grant( $grant );
			if ( '' === $row['scope'] || '' === $row['role'] || '' === $row['granted_at'] ) {
				continue;
			}
			$normalized[] = $row;
		}
		return $normalized;
	}

	/**
	 * Returns whether one grant record is well-formed.
	 *
	 * @param array{scope: string, offering_id: int, role: string, granted_at: string, expires_at: string, revoked_at: string, granted_by: int} $grant Normalized grant.
	 */
	public static function grant_shape_valid( array $grant ): bool {
		if ( ! in_array( $grant['scope'], self::SCOPES, true ) || ! in_array( $grant['role'], self::SCOPED_ROLES, true ) ) {
			return false;
		}
		if ( 'offering' === $grant['scope'] && 0 >= $grant['offering_id'] ) {
			return false;
		}
		if ( 'news' === $grant['scope'] && 0 !== $grant['offering_id'] ) {
			return false;
		}
		$granted_at = self::normalize_datetime( $grant['granted_at'] );
		if ( '' === $granted_at || 0 >= $grant['granted_by'] ) {
			return false;
		}
		if ( '' !== $grant['expires_at'] ) {
			$expires_at = self::normalize_datetime( $grant['expires_at'] );
			if ( '' === $expires_at || $expires_at <= $granted_at ) {
				return false;
			}
		}
		if ( '' !== $grant['revoked_at'] && '' === self::normalize_datetime( $grant['revoked_at'] ) ) {
			return false;
		}
		return true;
	}

	/**
	 * Returns whether a grant is usable right now.
	 *
	 * Revocation and expiry are evaluated on every call, so a revoked or expired
	 * grant stops working immediately — no cache or session may extend it.
	 *
	 * @param array{scope: string, offering_id: int, role: string, granted_at: string, expires_at: string, revoked_at: string, granted_by: int} $grant Normalized grant.
	 * @param string                                                                                                                            $now   Reference time.
	 */
	public static function grant_is_active( array $grant, string $now ): bool {
		if ( ! self::grant_shape_valid( $grant ) ) {
			return false;
		}
		if ( '' !== $grant['revoked_at'] ) {
			return false;
		}
		$now_normalized = self::normalize_datetime( $now );
		if ( '' === $now_normalized ) {
			return false;
		}
		if ( '' === $grant['expires_at'] ) {
			return true;
		}
		$expires_at = self::normalize_datetime( $grant['expires_at'] );
		return '' !== $expires_at && $expires_at > $now_normalized;
	}

	/**
	 * Returns grants matching scope, offering, and role, split by usability.
	 *
	 * @param array<int, array<string, mixed>> $grants      Normalized grant list.
	 * @param string                           $scope       Grant scope.
	 * @param int                              $offering_id Offering record ID (0 for the news scope).
	 * @param string                           $role        Scoped role.
	 * @param string                           $now         Reference time.
	 * @return array{active: array<int, array<string, mixed>>, revoked: array<int, array<string, mixed>>, expired: array<int, array<string, mixed>>}
	 */
	public static function matching_grants( array $grants, string $scope, int $offering_id, string $role, string $now ): array {
		$result = array(
			'active'  => array(),
			'revoked' => array(),
			'expired' => array(),
		);
		foreach ( $grants as $grant ) {
			$row = self::normalize_grant( $grant );
			if ( $scope !== $row['scope'] || $offering_id !== $row['offering_id'] || $role !== $row['role'] ) {
				continue;
			}
			if ( '' !== $row['revoked_at'] ) {
				$result['revoked'][] = $row;
			} elseif ( ! self::grant_is_active( $row, $now ) ) {
				$result['expired'][] = $row;
			} else {
				$result['active'][] = $row;
			}
		}
		return $result;
	}

	/**
	 * Returns whether the account holds an active grant for the exact scope.
	 *
	 * @param string                           $role        Scoped role.
	 * @param string                           $scope       Grant scope.
	 * @param int                              $offering_id Offering record ID.
	 * @param array<int, array<string, mixed>> $grants      Normalized grant list.
	 * @param string                           $now         Reference time.
	 */
	public static function has_scope( string $role, string $scope, int $offering_id, array $grants, string $now ): bool {
		return array() !== self::matching_grants( $grants, $scope, $offering_id, $role, $now )['active'];
	}

	/**
	 * Resolves the grant scope for a record type, or empty when unscoped.
	 *
	 * @param string $post_type Record type.
	 */
	public static function scope_for_post_type( string $post_type ): string {
		return match ( $post_type ) {
			'lps_offering', 'lps_unit', 'lps_resource' => 'offering',
			'lps_news' => 'news',
			default => '',
		};
	}

	/**
	 * Returns the first scoped-authorization denial, or null when allowed.
	 *
	 * Deny-by-default order: unknown role, unknown action for the role, record
	 * type outside the scoped set, publish on a non-publishable type, then the
	 * persisted grant check (missing, revoked, expired).
	 *
	 * @param string                           $role        Policy role.
	 * @param string                           $action      Requested action.
	 * @param string                           $post_type   Governed record type.
	 * @param int                              $offering_id Resolved offering ID (0 for the news scope).
	 * @param array<int, array<string, mixed>> $grants      Normalized grant list.
	 * @param string                           $now         Reference time.
	 */
	public static function scope_error( string $role, string $action, string $post_type, int $offering_id, array $grants, string $now ): ?string {
		if ( ! self::is_scoped_role( $role ) ) {
			return 'lps_teaching_role_not_scoped';
		}
		if ( ! self::role_allows_action( $role, $action ) ) {
			return 'lps_teaching_action_forbidden';
		}
		if ( ! in_array( $post_type, self::SCOPED_POST_TYPES, true ) ) {
			return 'lps_teaching_scope_post_type';
		}
		if ( 'copy-forward' === $action && 'lps_offering' !== $post_type ) {
			return 'lps_teaching_action_forbidden';
		}
		$scope = self::scope_for_post_type( $post_type );
		if ( '' === $scope ) {
			return 'lps_teaching_scope_post_type';
		}
		if ( 'offering' === $scope && 0 >= $offering_id ) {
			return 'lps_teaching_scope_required';
		}
		$matching = self::matching_grants( $grants, $scope, 'offering' === $scope ? $offering_id : 0, $role, $now );
		if ( array() !== $matching['active'] ) {
			return null;
		}
		if ( array() !== $matching['revoked'] ) {
			return 'lps_teaching_grant_revoked';
		}
		if ( array() !== $matching['expired'] ) {
			return 'lps_teaching_grant_expired';
		}
		return 'lps_teaching_scope_required';
	}

	/**
	 * Returns whether the canonical action matrix grants the action at all.
	 *
	 * The matrix lives in `SecurityPolicy::ACTIONS`; scoped checks add the
	 * offering/grant boundary on top of it, never beside it.
	 *
	 * @param string $role   Policy role.
	 * @param string $action Requested action.
	 */
	public static function role_allows_action( string $role, string $action ): bool {
		return SecurityPolicy::allows( $role, $action );
	}

	/**
	 * Returns whether a scoped role may write one field on one record type.
	 *
	 * Non-scoped roles are unaffected; scoped roles get the strict allowlist.
	 *
	 * @param string $role      Policy role.
	 * @param string $post_type Governed record type.
	 * @param string $field     Field or metadata key.
	 */
	public static function field_write_allowed( string $role, string $post_type, string $field ): bool {
		if ( ! self::is_scoped_role( $role ) ) {
			return true;
		}
		if ( ! in_array( $field, self::FIELD_ALLOWLIST[ $post_type ] ?? array(), true ) ) {
			return false;
		}
		if ( 'delegate' === $role && in_array( $field, self::PROFESSOR_ONLY_FIELDS, true ) ) {
			return false;
		}
		return true;
	}

	/**
	 * Returns whether a role may grant or revoke teaching scopes.
	 *
	 * Administrators manage every grant; section editors manage grants only
	 * while assigned the `teaching` collection. No other role may manage
	 * grants, and scoped roles can never grant themselves access.
	 *
	 * @param string             $role                 Granter policy role.
	 * @param array<int, string> $assigned_collections Granter collection keys.
	 */
	public static function may_manage_grants( string $role, array $assigned_collections ): bool {
		if ( 'administrator' === $role ) {
			return true;
		}
		return 'section-editor' === $role && in_array( 'teaching', $assigned_collections, true );
	}

	/**
	 * Returns the first grant-creation denial, or null when the grant may persist.
	 *
	 * @param string                           $granter_role      Granter policy role.
	 * @param array<int, string>               $assigned_collections Granter collection keys.
	 * @param int                              $granter_user_id   Granter account ID.
	 * @param int                              $target_user_id    Target account ID.
	 * @param string                           $target_role       Target account policy role.
	 * @param array<string, mixed>             $candidate         Proposed grant fields.
	 * @param array<int, array<string, mixed>> $existing_grants   Target account grant list.
	 */
	public static function grant_error( string $granter_role, array $assigned_collections, int $granter_user_id, int $target_user_id, string $target_role, array $candidate, array $existing_grants ): ?string {
		if ( ! self::may_manage_grants( $granter_role, $assigned_collections ) ) {
			return 'lps_teaching_grant_forbidden';
		}
		if ( 0 >= $target_user_id || $granter_user_id === $target_user_id ) {
			return 'lps_teaching_self_grant_forbidden';
		}
		$grant = self::normalize_grant( $candidate );
		if ( ! in_array( $grant['scope'], self::SCOPES, true ) ) {
			return 'lps_teaching_grant_invalid';
		}
		if ( 'offering' === $grant['scope'] && 0 >= $grant['offering_id'] ) {
			return 'lps_teaching_grant_invalid';
		}
		if ( 'news' === $grant['scope'] && 0 !== $grant['offering_id'] ) {
			return 'lps_teaching_grant_invalid';
		}
		$granted_at = self::normalize_datetime( $grant['granted_at'] );
		if ( '' === $granted_at ) {
			return 'lps_teaching_grant_invalid';
		}
		if ( '' !== $grant['expires_at'] ) {
			$expires_at = self::normalize_datetime( $grant['expires_at'] );
			if ( '' === $expires_at || $expires_at <= $granted_at ) {
				return 'lps_teaching_grant_invalid';
			}
		}
		if ( ! self::is_scoped_role( $target_role ) || $grant['role'] !== $target_role ) {
			return 'lps_teaching_grant_role_mismatch';
		}
		foreach ( $existing_grants as $existing ) {
			$row = self::normalize_grant( $existing );
			if ( $row['scope'] === $grant['scope'] && $row['offering_id'] === $grant['offering_id'] && $row['role'] === $grant['role'] && '' === $row['revoked_at'] ) {
				return 'lps_teaching_grant_duplicate';
			}
		}
		return null;
	}

	/**
	 * Returns the first revocation denial, or null when the grant may be revoked.
	 *
	 * @param string                           $granter_role         Granter policy role.
	 * @param array<int, string>               $assigned_collections Granter collection keys.
	 * @param int                              $granter_user_id      Granter account ID.
	 * @param int                              $target_user_id       Target account ID.
	 * @param int                              $grant_index          Index into the normalized grant list.
	 * @param array<int, array<string, mixed>> $existing_grants      Target account grant list.
	 */
	public static function revoke_error( string $granter_role, array $assigned_collections, int $granter_user_id, int $target_user_id, int $grant_index, array $existing_grants ): ?string {
		if ( ! self::may_manage_grants( $granter_role, $assigned_collections ) ) {
			return 'lps_teaching_grant_forbidden';
		}
		if ( 0 >= $target_user_id || $granter_user_id === $target_user_id ) {
			return 'lps_teaching_self_grant_forbidden';
		}
		$grant = $existing_grants[ $grant_index ] ?? null;
		if ( null === $grant || '' !== self::normalize_grant( $grant )['revoked_at'] ) {
			return 'lps_teaching_grant_missing';
		}
		return null;
	}

	/**
	 * Returns whether a scope source is server-side persisted state.
	 *
	 * Only persisted grants and persisted canonical relationships may resolve
	 * scope; request input, metadata, and session hints are never sources.
	 *
	 * @param string $source Candidate scope source.
	 */
	public static function scope_source_is_server_side( string $source ): bool {
		return in_array( $source, self::TRUSTED_SCOPE_SOURCES, true );
	}

	/**
	 * Returns the public Person identity fields, which carry no account key.
	 *
	 * @return array<int, string>
	 */
	public static function person_identity_fields(): array {
		return array(
			'_lps_canonical_name',
			'_lps_sort_name',
			'_lps_person_status',
			'_lps_roles',
			'_lps_affiliations',
			'_lps_start_date',
			'_lps_end_date',
			'_lps_public_email',
			'_lps_orcid',
			'_lps_lattes_url',
			'_lps_scholar_url',
			'_lps_website_url',
			'_lps_credentials',
			'_lps_photo_rights',
			'_lps_privacy_reviewed',
		);
	}

	/**
	 * Returns whether public identity and teaching history survive deactivation.
	 *
	 * Person records are content, not accounts: no identity field references a
	 * user ID, and the teaching-team relationship targets Person records, so
	 * removing login access changes nothing about the public record or history.
	 *
	 * @param array<int, string> $person_meta_keys Registered Person metadata keys.
	 */
	public static function person_identity_survives_deactivation( array $person_meta_keys ): bool {
		foreach ( $person_meta_keys as $key ) {
			if ( str_contains( $key, 'user_id' ) && ! in_array( $key, self::ACCOUNT_LINKAGE_FIELDS, true ) ) {
				return false;
			}
		}
		foreach ( self::person_identity_fields() as $identity_field ) {
			if ( in_array( $identity_field, self::ACCOUNT_LINKAGE_FIELDS, true ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Normalizes an ISO-8601 datetime to canonical form, or empty.
	 *
	 * @param mixed $value Boundary input.
	 */
	public static function normalize_datetime( mixed $value ): string {
		$text = self::text( $value );
		if ( '' === $text ) {
			return '';
		}
		$parsed = \DateTimeImmutable::createFromFormat( \DateTimeInterface::ATOM, $text );
		if ( false === $parsed ) {
			$parsed = \DateTimeImmutable::createFromFormat( 'Y-m-d\TH:i:s', $text );
		}
		if ( false === $parsed ) {
			$timestamp = strtotime( $text );
			$parsed    = false === $timestamp ? false : ( new \DateTimeImmutable( '@' . $timestamp ) );
		}
		return false === $parsed ? '' : $parsed->format( 'c' );
	}

	/**
	 * Converts boundary input to single-line plain text.
	 *
	 * @param mixed $value Boundary input.
	 */
	private static function text( mixed $value ): string {
		if ( ! is_scalar( $value ) ) {
			return '';
		}
		$text = (string) preg_replace( '/<[^>]*>/', '', (string) $value );
		$text = (string) preg_replace( '/[\x00-\x1F\x7F]/u', '', $text );
		return trim( $text );
	}
}
