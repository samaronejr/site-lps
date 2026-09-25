<?php
/**
 * WordPress role and collection-scope adapter.
 *
 * @package LPS\ContentModel
 */

declare(strict_types=1);

namespace LPS\ContentModel;

use WP_Error;
use WP_Post;
use WP_User;

/** Installs named roles and enforces per-account collection and offering-scope assignment. */
final class Roles {
	public const COLLECTIONS_META = '_lps_assigned_collections';
	public const GRANTS_META      = '_lps_teaching_grants';
	public const PERSON_META      = '_lps_person_id';

	/**
	 * Re-entrancy guard so only the grant helpers may persist grant metadata.
	 *
	 * @var bool
	 */
	private static bool $grant_syncing = false;

	/**
	 * Whether the trusted course-create boundary is mid-flight.
	 *
	 * Set only inside `TaskDashboard::handle_course` after `may_course` has
	 * authorized the account: the canonical `create_course`/`create_offering`
	 * boundaries write editor-owned relationship rows (`offering_course`,
	 * `offering_term`, `teaching_team`) that direct scoped writes must keep
	 * denying. `scoped_relationship_error` consults this flag exactly like
	 * `TeachingCopy::in_operation` — both flags mark an authorized boundary,
	 * never a caller privilege.
	 *
	 * @var bool
	 */
	private static bool $course_create_syncing = false;

	/** Registers runtime authorization hooks. */
	public static function boot(): void {
		add_filter( 'map_meta_cap', array( self::class, 'map_record_capability' ), 20, 4 );
		add_filter( 'user_has_cap', array( self::class, 'strip_unassigned_collections' ), 15, 4 );
		add_filter( 'user_has_cap', array( MFA::class, 'strip_unenrolled_privileges' ), 20, 4 );
		add_filter( 'add_user_metadata', array( self::class, 'protect_grant_meta' ), 10, 5 );
		add_filter( 'update_user_metadata', array( self::class, 'protect_grant_meta' ), 10, 5 );
		add_filter( 'delete_user_metadata', array( self::class, 'protect_grant_meta' ), 10, 5 );
		add_action( 'user_profile_update_errors', array( self::class, 'reject_shared_account' ), 10, 3 );
		add_action( 'wp_login', array( self::class, 'record_login' ), 10, 2 );
		add_action( 'show_user_profile', array( self::class, 'render_collection_assignments' ) );
		add_action( 'edit_user_profile', array( self::class, 'render_collection_assignments' ) );
		add_action( 'personal_options_update', array( self::class, 'save_collection_assignments' ) );
		add_action( 'edit_user_profile_update', array( self::class, 'save_collection_assignments' ) );
		add_action( 'show_user_profile', array( self::class, 'render_teaching_grants' ) );
		add_action( 'edit_user_profile', array( self::class, 'render_teaching_grants' ) );
		add_action( 'personal_options_update', array( self::class, 'save_teaching_grants' ) );
		add_action( 'edit_user_profile_update', array( self::class, 'save_teaching_grants' ) );
	}

	/** Creates or repairs the seven least-privilege roles. */
	public static function install(): void {
		foreach ( SecurityPolicy::roles() as $role ) {
			$slug = self::slug( $role );
			$caps = array( 'read' => true );
			foreach ( SecurityPolicy::capabilities_for_role( $role ) as $capability ) {
				$caps[ $capability ] = true;
			}
			foreach ( self::native_capabilities( $role ) as $capability ) {
				$caps[ $capability ] = true;
			}
			$existing = get_role( $slug );
			if ( null === $existing ) {
				add_role( $slug, self::label( $role ), $caps );
				continue;
			}
			foreach ( $caps as $capability => $grant ) {
				$existing->add_cap( $capability, $grant );
			}
		}
	}

	/** Returns the WordPress role slug.
	 *
	 * @param string $role Policy role.
	 */
	public static function slug( string $role ): string {
		return 'lps_' . str_replace( '-', '_', $role );
	}

	/** Returns the policy role for a user, or an empty string.
	 *
	 * @param WP_User|null $user Optional account.
	 */
	public static function policy_role( ?WP_User $user = null ): string {
		$user = $user ?? wp_get_current_user();
		if ( in_array( 'administrator', (array) $user->roles, true ) || in_array( self::slug( 'administrator' ), (array) $user->roles, true ) ) {
			return 'administrator';
		}
		if ( in_array( self::slug( 'publisher' ), (array) $user->roles, true ) ) {
			return 'publisher';
		}
		foreach ( SecurityPolicy::roles() as $role ) {
			if ( in_array( self::slug( $role ), (array) $user->roles, true ) ) {
				return $role;
			}
		}
		return '';
	}

	/** Returns assigned collection keys.
	 *
	 * @param int $user_id Account ID.
	 * @return array<int, string>
	 */
	public static function assigned_collections( int $user_id ): array {
		$value = get_user_meta( $user_id, self::COLLECTIONS_META, true );
		if ( ! is_array( $value ) ) {
			return array();
		}
		return array_values(
			array_filter(
				array_map( static fn( mixed $item ): string => sanitize_key( Policy::scalar_string( $item ) ), $value )
			)
		);
	}

	/** Checks the complete role/action/collection/MFA boundary.
	 *
	 * Scoped roles hold no collection rights at all: they must go through
	 * `current_user_can_scoped_action`, which evaluates persisted grants.
	 *
	 * @param string $action     Action key.
	 * @param string $collection Collection key.
	 */
	public static function current_user_can_action( string $action, string $collection = '' ): bool {
		$user = wp_get_current_user();
		$role = self::policy_role( $user );
		if ( '' === $role || TeachingPolicy::is_scoped_role( $role ) ) {
			return false;
		}
		if ( ! SecurityPolicy::allows( $role, $action, $collection, self::assigned_collections( $user->ID ) ) ) {
			return false;
		}
		return SecurityPolicy::privileged_session_allowed( $role, MFA::is_enrolled( $user->ID ) );
	}

	/**
	 * Returns the persisted teaching-scope grants for an account.
	 *
	 * @param int $user_id Account ID.
	 * @return array<int, array{scope: string, offering_id: int, role: string, granted_at: string, expires_at: string, revoked_at: string, granted_by: int}>
	 */
	public static function teaching_grants( int $user_id ): array {
		return TeachingPolicy::normalize_grants( get_user_meta( $user_id, self::GRANTS_META, true ) );
	}

	/**
	 * Grants one scoped role on one offering or the news lane to an account.
	 *
	 * The grant is validated against the pure policy, persisted as account
	 * metadata, and recorded in the audit ledger. User-controlled input can
	 * propose a grant but only an authorized granter may persist it.
	 *
	 * @param int    $target_user_id Account receiving the grant.
	 * @param string $scope          Grant scope (`offering` or `news`).
	 * @param int    $offering_id    Offering record ID (0 for the news scope).
	 * @param string $role           Scoped role the grant enables.
	 * @param string $expires_at     Optional ISO-8601 expiry; empty means none.
	 * @return array{granted: true, index: int}|WP_Error
	 */
	public static function grant_scope( int $target_user_id, string $scope, int $offering_id, string $role, string $expires_at = '' ): array|WP_Error {
		$actor       = wp_get_current_user();
		$target      = get_user_by( 'id', $target_user_id );
		$target_role = $target instanceof WP_User ? self::policy_role( $target ) : '';
		$candidate   = array(
			'scope'       => $scope,
			'offering_id' => $offering_id,
			'role'        => $role,
			'granted_at'  => gmdate( 'c' ),
			'expires_at'  => $expires_at,
			'revoked_at'  => '',
			'granted_by'  => $actor->ID,
		);
		$grants      = self::teaching_grants( $target_user_id );
		$error       = TeachingPolicy::grant_error(
			self::policy_role( $actor ),
			self::assigned_collections( $actor->ID ),
			$actor->ID,
			$target_user_id,
			$target_role,
			$candidate,
			$grants
		);
		if ( null !== $error ) {
			return self::scope_wp_error( $error );
		}
		$grants[]            = TeachingPolicy::normalize_grant( $candidate );
		self::$grant_syncing = true;
		update_user_meta( $target_user_id, self::GRANTS_META, $grants );
		self::$grant_syncing = false;
		Audit::record(
			'grant-scope',
			$target_user_id,
			0,
			array(
				'scope'       => $scope,
				'offering_id' => $offering_id,
				'role'        => $role,
				'expires_at'  => TeachingPolicy::normalize_datetime( $expires_at ),
			)
		);
		Notifications::scope_granted(
			$target_user_id,
			array(
				'scope'       => $scope,
				'offering_id' => $offering_id,
				'role'        => $role,
				'expires_at'  => TeachingPolicy::normalize_datetime( $expires_at ),
			)
		);
		return array(
			'granted' => true,
			'index'   => count( $grants ) - 1,
		);
	}

	/**
	 * Grants one scoped role without evaluating the actor's grant rights.
	 *
	 * System boundaries use this when a persisted workflow already decided the
	 * grant belongs to the actor — e.g. the professor who just created an
	 * offering through the trusted create lane receives scope on it back.
	 * The candidate and target role are still validated the same way
	 * `grant_scope` validates them — malformed shapes are rejected — and a
	 * grant targeting a different account is only honored when the actor
	 * already holds granter rights under the ordinary policy. What this
	 * method skips is the self-grant case: a trusted boundary may hand the
	 * acting account scope on the record it just minted. Callers must have
	 * already enforced their own authorization for anything broader.
	 *
	 * @param int    $target_user_id Account receiving the grant.
	 * @param string $scope          Grant scope (`offering` or `news`).
	 * @param int    $offering_id    Offering record ID (0 for the news scope).
	 * @param string $role           Scoped role the grant enables.
	 * @param string $expires_at     Optional ISO-8601 expiry; empty means none.
	 * @return array{granted: true, index: int}|WP_Error
	 */
	public static function grant_scope_trusted( int $target_user_id, string $scope, int $offering_id, string $role, string $expires_at = '' ): array|WP_Error {
		if ( ! self::in_course_create() ) {
			return new WP_Error( 'lps_teaching_grant_forbidden', 'Trusted grants run only inside the course-create boundary.' );
		}
		$actor       = wp_get_current_user();
		$target      = get_user_by( 'id', $target_user_id );
		$target_role = $target instanceof WP_User ? self::policy_role( $target ) : '';
		if ( ! TeachingPolicy::is_scoped_role( $target_role ) ) {
			return new WP_Error( 'lps_teaching_target_role_invalid', 'The grant target does not carry a scoped role.' );
		}
		$candidate = TeachingPolicy::normalize_grant(
			array(
				'scope'       => $scope,
				'offering_id' => $offering_id,
				'role'        => $role,
				'granted_at'  => gmdate( 'c' ),
				'expires_at'  => $expires_at,
				'revoked_at'  => '',
				'granted_by'  => $actor->ID,
			)
		);
		if ( ! TeachingPolicy::grant_shape_valid( $candidate ) ) {
			return new WP_Error( 'lps_teaching_grant_invalid', 'The grant shape is incomplete.' );
		}
		$grants = self::teaching_grants( $target_user_id );
		if ( $target_user_id !== $actor->ID ) {
			// Crossing to another account is not part of the trusted lane:
			// that grant must survive the ordinary actor-authorization check.
			$actor_error = TeachingPolicy::grant_error(
				self::policy_role( $actor ),
				self::assigned_collections( $actor->ID ),
				$actor->ID,
				$target_user_id,
				$target_role,
				$candidate,
				$grants
			);
			if ( null !== $actor_error ) {
				return self::scope_wp_error( $actor_error );
			}
		}
		$grants[]            = $candidate;
		self::$grant_syncing = true;
		update_user_meta( $target_user_id, self::GRANTS_META, $grants );
		self::$grant_syncing = false;
		Audit::record(
			'grant-scope',
			$target_user_id,
			0,
			array(
				'scope'       => $scope,
				'offering_id' => $offering_id,
				'role'        => $role,
				'expires_at'  => TeachingPolicy::normalize_datetime( $expires_at ),
				'via'         => 'trusted-boundary',
			)
		);
		Notifications::scope_granted(
			$target_user_id,
			array(
				'scope'       => $scope,
				'offering_id' => $offering_id,
				'role'        => $role,
				'expires_at'  => TeachingPolicy::normalize_datetime( $expires_at ),
			)
		);
		return array(
			'granted' => true,
			'index'   => count( $grants ) - 1,
		);
	}

	/** Marks the trusted course-create boundary as mid-flight. */
	public static function begin_course_create(): void {
		self::$course_create_syncing = true;
	}

	/** Clears the trusted course-create flag; always pair with the begin. */
	public static function end_course_create(): void {
		self::$course_create_syncing = false;
	}

	/** Returns whether the trusted course-create boundary is mid-flight. */
	public static function in_course_create(): bool {
		return self::$course_create_syncing;
	}

	/**
	 * Revokes one persisted grant; the denial takes effect on the next check.
	 *
	 * @param int $target_user_id Account losing the grant.
	 * @param int $grant_index    Index into the normalized grant list.
	 * @return array{revoked: true}|WP_Error
	 */
	public static function revoke_scope( int $target_user_id, int $grant_index ): array|WP_Error {
		$actor  = wp_get_current_user();
		$grants = self::teaching_grants( $target_user_id );
		$error  = TeachingPolicy::revoke_error(
			self::policy_role( $actor ),
			self::assigned_collections( $actor->ID ),
			$actor->ID,
			$target_user_id,
			$grant_index,
			$grants
		);
		if ( null !== $error ) {
			return self::scope_wp_error( $error );
		}
		$grant                  = $grants[ $grant_index ];
		$grant['revoked_at']    = gmdate( 'c' );
		$grants[ $grant_index ] = $grant;
		self::$grant_syncing    = true;
		update_user_meta( $target_user_id, self::GRANTS_META, $grants );
		self::$grant_syncing = false;
		Audit::record(
			'revoke-scope',
			$target_user_id,
			0,
			array(
				'scope'       => $grant['scope'],
				'offering_id' => $grant['offering_id'],
				'role'        => $grant['role'],
			)
		);
		return array( 'revoked' => true );
	}

	/**
	 * Checks the complete scoped role/action/post-type/grant/MFA boundary.
	 *
	 * @param string $action      Action key.
	 * @param string $post_type   Governed record type.
	 * @param int    $offering_id Resolved offering ID (0 for the news scope).
	 */
	public static function current_user_can_scoped_action( string $action, string $post_type, int $offering_id = 0 ): bool {
		$user = wp_get_current_user();
		$role = self::policy_role( $user );
		if ( ! SecurityPolicy::privileged_session_allowed( $role, MFA::is_enrolled( $user->ID ) ) ) {
			return false;
		}
		return null === TeachingPolicy::scope_error( $role, $action, $post_type, $offering_id, self::teaching_grants( $user->ID ), gmdate( 'c' ) );
	}

	/**
	 * Returns the first scoped denial for one persisted record, or null.
	 *
	 * Scope resolves only from server-side state: the record type, its persisted
	 * canonical relationships, and the account's persisted grants.
	 *
	 * @param int     $user_id Account ID.
	 * @param string  $action  Action key.
	 * @param WP_Post $post    Persisted record.
	 */
	public static function scoped_post_error( int $user_id, string $action, WP_Post $post ): ?string {
		$user = get_user_by( 'id', $user_id );
		$role = $user instanceof WP_User ? self::policy_role( $user ) : '';
		if ( ! TeachingPolicy::is_scoped_role( $role ) ) {
			return 'lps_teaching_role_not_scoped';
		}
		$offering_id = self::persisted_offering_id( $post );
		return TeachingPolicy::scope_error( $role, $action, $post->post_type, $offering_id, self::teaching_grants( $user_id ), gmdate( 'c' ) );
	}

	/**
	 * Resolves the offering scope for a persisted record from relationships.
	 *
	 * @param WP_Post $post Persisted record.
	 */
	public static function persisted_offering_id( WP_Post $post ): int {
		if ( 'lps_offering' === $post->post_type ) {
			return $post->ID;
		}
		$relationship = TeachingPolicy::RELATIONSHIP_SCOPES[ $post->post_type ] ?? '';
		if ( '' === $relationship ) {
			return 0;
		}
		$rows = Relationships::for_source( $post->ID, $relationship );
		return Policy::sanitize_integer( $rows[0]['target_post_id'] ?? 0 );
	}

	/**
	 * Returns the first scoped denial for a relationship write, or null.
	 *
	 * Scoped roles may only attach units and resources to offerings covered by
	 * an active grant; every other relationship write is denied, including
	 * teaching-team, course, and term changes, which are editor-owned.
	 *
	 * @param string                           $relationship_type Canonical relationship type.
	 * @param int                              $source_post_id    Source record ID.
	 * @param array<int, array<string, mixed>> $rows              Candidate rows.
	 */
	public static function scoped_relationship_error( string $relationship_type, int $source_post_id, array $rows ): ?string {
		$user = wp_get_current_user();
		$role = self::policy_role( $user );
		if ( ! TeachingPolicy::is_scoped_role( $role ) ) {
			return null;
		}
		if ( class_exists( TeachingCopy::class ) && TeachingCopy::in_operation() ) {
			// The copy-forward boundary is the authorized writer of the
			// offering's canonical rows inside its own operation; the REST
			// permission check already proved the account's copy-forward scope
			// on the source offering.
			return null;
		}
		if ( self::$course_create_syncing ) {
			// The trusted course-create boundary writes the new offering's
			// canonical rows mid-flight; `may_course` already authorized the
			// account for this lane before the flag was set.
			return null;
		}
		if ( ! SecurityPolicy::privileged_session_allowed( $role, MFA::is_enrolled( $user->ID ) ) ) {
			return 'lps_mfa_required';
		}
		if ( ! in_array( $relationship_type, array( 'unit_offering', 'resource_offering', 'resource_unit' ), true ) ) {
			return 'lps_teaching_relationship_forbidden';
		}
		$source = get_post( $source_post_id );
		if ( ! $source instanceof WP_Post || ! in_array( $source->post_type, TeachingPolicy::SCOPED_POST_TYPES, true ) ) {
			return 'lps_teaching_relationship_forbidden';
		}
		$grants          = self::teaching_grants( $user->ID );
		$now             = gmdate( 'c' );
		$source_offering = self::persisted_offering_id( $source );
		if ( 0 < $source_offering ) {
			$source_error = TeachingPolicy::scope_error( $role, 'edit', $source->post_type, $source_offering, $grants, $now );
			if ( null !== $source_error ) {
				return $source_error;
			}
		}
		foreach ( $rows as $row ) {
			$target_id = Policy::sanitize_integer( $row['target_post_id'] ?? 0 );
			$target    = get_post( $target_id );
			if ( ! $target instanceof WP_Post ) {
				continue;
			}
			$target_offering = 'lps_offering' === $target->post_type ? $target->ID : self::persisted_offering_id( $target );
			if ( 0 >= $target_offering ) {
				return 'lps_teaching_relationship_forbidden';
			}
			$error = TeachingPolicy::scope_error( $role, 'edit', $source->post_type, $target_offering, $grants, $now );
			if ( null !== $error ) {
				return $error;
			}
		}
		return null;
	}

	/**
	 * Blocks direct writes to grant metadata outside the grant helpers.
	 *
	 * @param mixed  $check      Existing short-circuit value.
	 * @param int    $object_id  Account ID.
	 * @param string $meta_key   Metadata key.
	 * @param mixed  $meta_value Candidate value.
	 * @param mixed  $prev_value Previous-value selector.
	 * @return mixed
	 */
	public static function protect_grant_meta( mixed $check, int $object_id, string $meta_key, mixed $meta_value, mixed $prev_value ): mixed {
		unset( $object_id, $meta_value, $prev_value );
		if ( self::GRANTS_META === $meta_key && ! self::$grant_syncing ) {
			return false;
		}
		return $check;
	}

	/**
	 * Builds a typed denial for scoped authorization failures.
	 *
	 * @param string $code Stable error code.
	 */
	private static function scope_wp_error( string $code ): WP_Error {
		return new WP_Error(
			$code,
			__( 'The requested teaching-scope operation is not allowed for this account.', 'lps-content-model' ),
			array( 'status' => 403 )
		);
	}

	/**
	 * Enforces collection assignment on record meta capabilities.
	 *
	 * @param array<int, string> $caps    Primitive capabilities.
	 * @param string             $cap     Requested meta capability.
	 * @param int                $user_id User ID.
	 * @param array<int, mixed>  $args    Capability arguments.
	 * @return array<int, string>
	 */
	public static function map_record_capability( array $caps, string $cap, int $user_id, array $args ): array {
		if ( ! in_array( $cap, array( 'edit_post', 'delete_post', 'publish_post' ), true ) || empty( $args[0] ) ) {
			return $caps;
		}
		$post_id = is_numeric( $args[0] ) ? (int) $args[0] : 0;
		$post    = get_post( $post_id );
		if ( ! $post instanceof WP_Post || ! isset( Contracts::post_types()[ $post->post_type ] ) ) {
			return $caps;
		}
		$user = get_user_by( 'id', $user_id );
		if ( ! $user instanceof WP_User ) {
			return array( 'do_not_allow' );
		}
		$role   = self::policy_role( $user );
		$action = 'delete_post' === $cap ? 'delete' : ( 'publish_post' === $cap ? 'publish' : 'edit' );
		if ( TeachingPolicy::is_scoped_role( $role ) ) {
			return null === self::scoped_post_error( $user_id, $action, $post ) ? $caps : array( 'do_not_allow' );
		}
		if ( ! SecurityPolicy::allows( $role, $action, self::collection_for_post_type( $post->post_type ), self::assigned_collections( $user_id ) ) ) {
			return array( 'do_not_allow' );
		}
		return $caps;
	}

	/**
	 * Removes collection primitives for scoped roles before list/create/admin-menu checks.
	 *
	 * @param array<string, bool> $allcaps Resolved capabilities.
	 * @param array<int, string>  $caps    Required primitive capabilities.
	 * @param array<int, mixed>   $args    Original capability arguments.
	 * @param WP_User             $user    Account.
	 * @return array<string, bool>
	 */
	public static function strip_unassigned_collections( array $allcaps, array $caps, array $args, WP_User $user ): array {
		unset( $caps, $args );
		$role = self::policy_role( $user );
		if ( TeachingPolicy::is_scoped_role( $role ) ) {
			foreach ( array_keys( Contracts::post_types() ) as $post_type ) {
				if ( in_array( $post_type, TeachingPolicy::SCOPED_POST_TYPES, true ) ) {
					continue;
				}
				$plural = 'page' === $post_type ? 'pages' : $post_type . 's';
				foreach ( array_keys( $allcaps ) as $capability ) {
					if ( 'edit_posts' === $capability ) {
						// Kept for the meta auth gate; `edit_post` on non-scoped
						// records is still denied by the scoped branch of
						// `map_record_capability`.
						continue;
					}
					if ( str_contains( $capability, $plural ) ) {
						$allcaps[ $capability ] = false;
					}
				}
			}
			return $allcaps;
		}
		if ( ! in_array( $role, array( 'contributor', 'translator', 'section-editor' ), true ) ) {
			return $allcaps;
		}
		$assigned = self::assigned_collections( $user->ID );
		foreach ( array_keys( Contracts::post_types() ) as $post_type ) {
			$collection = self::collection_for_post_type( $post_type );
			if ( in_array( $collection, $assigned, true ) ) {
				continue;
			}
			$plural = 'page' === $post_type ? 'pages' : $post_type . 's';
			foreach ( array_keys( $allcaps ) as $capability ) {
				if ( 'edit_posts' === $capability ) {
					// Kept for the meta auth gate; `edit_post` on unassigned
					// records is still denied by `map_record_capability`.
					continue;
				}
				if ( str_contains( $capability, $plural ) ) {
					$allcaps[ $capability ] = false;
				}
			}
		}
		return $allcaps;
	}

	/** Rejects generic role accounts at the account boundary.
	 *
	 * @param WP_Error $errors Validation errors.
	 * @param bool     $update Whether this is an update.
	 * @param object   $user   Candidate account data from WordPress.
	 */
	public static function reject_shared_account( WP_Error $errors, bool $update, object $user ): void {
		unset( $update );
		$login = isset( $user->user_login ) && is_string( $user->user_login ) ? $user->user_login : '';
		if ( SecurityPolicy::shared_account_name_forbidden( $login ) ) {
			$errors->add( 'lps_shared_account_forbidden', __( 'Shared or role-named accounts are forbidden. Create an individual attributable account.', 'lps-content-model' ) );
		}
	}

	/** Records deterministic last activity for dormant-account reporting.
	 *
	 * @param string  $user_login Account login.
	 * @param WP_User $user       Account.
	 */
	public static function record_login( string $user_login, WP_User $user ): void {
		unset( $user_login );
		update_user_meta( $user->ID, '_lps_last_activity_at', gmdate( 'c' ) );
	}

	/** Renders administrator-managed collection assignment checkboxes.
	 *
	 * @param WP_User $user Account being edited.
	 */
	public static function render_collection_assignments( WP_User $user ): void {
		if ( ! current_user_can( 'edit_user', $user->ID ) ) {
			return;
		}
		$assigned = self::assigned_collections( $user->ID );
		wp_nonce_field( 'lps_collection_assignments_' . $user->ID, '_lps_collections_nonce' );
		echo '<h2>' . esc_html__( 'LPS collection assignments', 'lps-content-model' ) . '</h2><fieldset><legend class="screen-reader-text">' . esc_html__( 'Assigned collections', 'lps-content-model' ) . '</legend>';
		foreach ( self::collections() as $collection ) {
			echo '<label style="display:block"><input type="checkbox" name="lps_assigned_collections[]" value="' . esc_attr( $collection ) . '" ' . checked( in_array( $collection, $assigned, true ), true, false ) . '> ' . esc_html( $collection ) . '</label>';
		}
		echo '</fieldset>';
	}

	/** Saves nonce-protected collection assignments.
	 *
	 * @param int $user_id Account ID.
	 */
	public static function save_collection_assignments( int $user_id ): void {
		if ( ! current_user_can( 'edit_user', $user_id ) || ! isset( $_POST['_lps_collections_nonce'] ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized as a scalar on the next line before verification.
		$raw_nonce = wp_unslash( $_POST['_lps_collections_nonce'] );
		$nonce     = is_string( $raw_nonce ) ? sanitize_text_field( $raw_nonce ) : '';
		if ( ! wp_verify_nonce( $nonce, 'lps_collection_assignments_' . $user_id ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Every collection is sanitized and allowlisted below.
		$submitted = isset( $_POST['lps_assigned_collections'] ) && is_array( $_POST['lps_assigned_collections'] ) ? wp_unslash( $_POST['lps_assigned_collections'] ) : array();
		$assigned  = array_values( array_intersect( self::collections(), array_map( static fn( mixed $item ): string => sanitize_key( Policy::scalar_string( $item ) ), $submitted ) ) );
		update_user_meta( $user_id, self::COLLECTIONS_META, $assigned );
	}

	/** Renders the grant-management panel for teaching scopes.
	 *
	 * Only accounts that may manage grants see the controls; the persisted
	 * grant list is shown to anyone who can edit the account.
	 *
	 * @param WP_User $user Account being edited.
	 */
	public static function render_teaching_grants( WP_User $user ): void {
		if ( ! current_user_can( 'edit_user', $user->ID ) ) {
			return;
		}
		$actor  = wp_get_current_user();
		$may    = TeachingPolicy::may_manage_grants( self::policy_role( $actor ), self::assigned_collections( $actor->ID ) );
		$grants = self::teaching_grants( $user->ID );
		$now    = gmdate( 'c' );
		wp_nonce_field( 'lps_teaching_grants_' . $user->ID, '_lps_grants_nonce' );
		echo '<h2>' . esc_html__( 'LPS teaching scope grants', 'lps-content-model' ) . '</h2>';
		echo '<p>' . esc_html__( 'Offering and news scopes are granted per account and take effect immediately; revocation and expiry are evaluated on every request.', 'lps-content-model' ) . '</p>';
		echo '<fieldset><legend class="screen-reader-text">' . esc_html__( 'Persisted teaching grants', 'lps-content-model' ) . '</legend>';
		if ( array() === $grants ) {
			echo '<p>' . esc_html__( 'No teaching scope grants are recorded for this account.', 'lps-content-model' ) . '</p>';
		}
		foreach ( $grants as $index => $grant ) {
			$state = '' !== $grant['revoked_at'] ? __( 'revoked', 'lps-content-model' ) : ( TeachingPolicy::grant_is_active( $grant, $now ) ? __( 'active', 'lps-content-model' ) : __( 'expired', 'lps-content-model' ) );
			$label = sprintf(
				/* translators: 1: grant scope, 2: offering record ID, 3: scoped role, 4: grant state, 5: expiry timestamp. */
				__( '%1$s scope on offering %2$d as %3$s — %4$s (expires %5$s)', 'lps-content-model' ),
				$grant['scope'],
				$grant['offering_id'],
				$grant['role'],
				$state,
				'' === $grant['expires_at'] ? __( 'never', 'lps-content-model' ) : $grant['expires_at']
			);
			$disabled = '' !== $grant['revoked_at'] || ! $may;
			echo '<label style="display:block"><input type="checkbox" name="lps_revoke_grants[]" value="' . esc_attr( (string) $index ) . '" ' . disabled( $disabled, true, false ) . '> ' . esc_html__( 'Revoke:', 'lps-content-model' ) . ' ' . esc_html( $label ) . '</label>';
		}
		echo '</fieldset>';
		if ( ! $may ) {
			return;
		}
		echo '<fieldset><legend>' . esc_html__( 'Add a teaching scope grant', 'lps-content-model' ) . '</legend>';
		echo '<p><label for="lps_grant_scope">' . esc_html__( 'Scope', 'lps-content-model' ) . '</label> <select id="lps_grant_scope" name="lps_grant_scope">';
		foreach ( TeachingPolicy::SCOPES as $scope ) {
			echo '<option value="' . esc_attr( $scope ) . '">' . esc_html( $scope ) . '</option>';
		}
		echo '</select> <label for="lps_grant_offering">' . esc_html__( 'Offering record ID', 'lps-content-model' ) . '</label> <input type="number" min="0" id="lps_grant_offering" name="lps_grant_offering" value="0">';
		echo ' <label for="lps_grant_role">' . esc_html__( 'Scoped role', 'lps-content-model' ) . '</label> <select id="lps_grant_role" name="lps_grant_role">';
		foreach ( TeachingPolicy::SCOPED_ROLES as $scoped_role ) {
			echo '<option value="' . esc_attr( $scoped_role ) . '">' . esc_html( $scoped_role ) . '</option>';
		}
		echo '</select> <label for="lps_grant_expires">' . esc_html__( 'Expires at (optional, ISO-8601)', 'lps-content-model' ) . '</label> <input type="text" id="lps_grant_expires" name="lps_grant_expires" value="" placeholder="2027-01-01T00:00:00+00:00"></p>';
		echo '</fieldset>';
		self::render_person_link( $user, $may );
	}

	/** Renders the direct account-to-person-record link.
	 *
	 * The link is the authoritative resolution of `person_for_user`: it names
	 * the one `lps_person` record the account owns so a professor's dashboard
	 * profile, proposals and public page belong to them without depending on
	 * authored-post or grant inference. Grant managers edit it; everyone else
	 * who can edit the account reads it.
	 *
	 * @param WP_User $user Account being edited.
	 * @param bool    $may  Whether the acting account may manage teaching grants.
	 */
	private static function render_person_link( WP_User $user, bool $may ): void {
		$current = Policy::sanitize_integer( get_user_meta( $user->ID, self::PERSON_META, true ) );
		$people  = function_exists( 'get_posts' ) ? get_posts(
			array(
				'post_type'      => 'lps_person',
				'post_status'    => array( 'publish', 'draft', 'private' ),
				// phpcs:ignore WordPress.WP.PostsPerPage.posts_per_page_posts_per_page -- A lab directory can exceed the sniff's 100 cap; the selector needs every record.
				'posts_per_page' => 500,
				'orderby'        => 'title',
				'order'          => 'ASC',
			)
		) : array();
		echo '<h2>' . esc_html__( 'Linked person record', 'lps-content-model' ) . '</h2>';
		echo '<p>' . esc_html__( 'The record the account owns — its public page at /pessoas/ and the dashboard profile it proposes changes against.', 'lps-content-model' ) . '</p>';
		if ( ! $may ) {
			$title = 0 < $current && function_exists( 'get_post' ) && get_post( $current ) instanceof \WP_Post ? get_post( $current )->post_title : '';
			echo '<p>' . ( '' !== $title ? esc_html( $title ) : esc_html__( 'No person record is linked to this account.', 'lps-content-model' ) ) . '</p>';
			return;
		}
		echo '<p><label for="lps_person_id">' . esc_html__( 'Person record', 'lps-content-model' ) . '</label> <select id="lps_person_id" name="lps_person_id">';
		echo '<option value="0">' . esc_html__( '— none —', 'lps-content-model' ) . '</option>';
		foreach ( $people as $person ) {
			echo '<option value="' . esc_attr( (string) $person->ID ) . '" ' . selected( $current, $person->ID, false ) . '>' . esc_html( $person->post_title . ' (#' . $person->ID . ')' ) . '</option>';
		}
		echo '</select></p>';
	}

	/** Saves nonce-protected teaching scope grants.
	 *
	 * @param int $user_id Account ID.
	 */
	public static function save_teaching_grants( int $user_id ): void {
		if ( ! current_user_can( 'edit_user', $user_id ) || ! isset( $_POST['_lps_grants_nonce'] ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized as a scalar on the next line before verification.
		$raw_nonce = wp_unslash( $_POST['_lps_grants_nonce'] );
		$nonce     = is_string( $raw_nonce ) ? sanitize_text_field( $raw_nonce ) : '';
		if ( ! wp_verify_nonce( $nonce, 'lps_teaching_grants_' . $user_id ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Every index is sanitized to an integer below.
		$revocations = isset( $_POST['lps_revoke_grants'] ) && is_array( $_POST['lps_revoke_grants'] ) ? wp_unslash( $_POST['lps_revoke_grants'] ) : array();
		$indexes     = array_map( array( Policy::class, 'sanitize_integer' ), $revocations );
		rsort( $indexes );
		foreach ( $indexes as $index ) {
			self::revoke_scope( $user_id, $index );
		}
		$raw_scope   = isset( $_POST['lps_grant_scope'] ) ? sanitize_text_field( Policy::scalar_string( wp_unslash( $_POST['lps_grant_scope'] ) ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Read as a scalar then sanitized.
		$scope       = sanitize_key( $raw_scope );
		$raw_offer   = isset( $_POST['lps_grant_offering'] ) ? sanitize_text_field( Policy::scalar_string( wp_unslash( $_POST['lps_grant_offering'] ) ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Read as a scalar then sanitized.
		$offering_id = Policy::sanitize_integer( $raw_offer );
		$raw_role    = isset( $_POST['lps_grant_role'] ) ? sanitize_text_field( Policy::scalar_string( wp_unslash( $_POST['lps_grant_role'] ) ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Read as a scalar then sanitized.
		$grant_role  = sanitize_key( $raw_role );
		$expires     = isset( $_POST['lps_grant_expires'] ) ? sanitize_text_field( Policy::scalar_string( wp_unslash( $_POST['lps_grant_expires'] ) ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Read as a scalar then sanitized.
		if ( '' !== $scope || 0 < $offering_id || '' !== $grant_role || '' !== $expires ) {
			self::grant_scope( $user_id, $scope, $offering_id, $grant_role, $expires );
		}
		$actor = wp_get_current_user();
		if ( TeachingPolicy::may_manage_grants( self::policy_role( $actor ), self::assigned_collections( $actor->ID ) ) && isset( $_POST['lps_person_id'] ) ) {
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized as a scalar on the next line.
			$raw_person = wp_unslash( $_POST['lps_person_id'] );
			$person_id  = Policy::sanitize_integer( is_string( $raw_person ) ? sanitize_text_field( $raw_person ) : '' );
			if ( 0 < $person_id && function_exists( 'get_post' ) && get_post( $person_id ) instanceof \WP_Post && 'lps_person' === get_post_type( $person_id ) ) {
				update_user_meta( $user_id, self::PERSON_META, $person_id );
			} elseif ( 0 >= $person_id ) {
				delete_user_meta( $user_id, self::PERSON_META );
			}
		}
	}

	/** Returns governed collection keys.
	 *
	 * @return array<int, string>
	 */
	public static function collections(): array {
		$collections = array_map( array( self::class, 'collection_for_post_type' ), array_keys( Contracts::post_types() ) );
		return array_values( array_unique( array_merge( $collections, array( 'site-settings', 'media-asset' ) ) ) );
	}

	/** Maps a post type to its governance collection.
	 *
	 * @param string $post_type Post type key.
	 */
	public static function collection_for_post_type( string $post_type ): string {
		return match ( $post_type ) {
			'lps_person' => 'person',
			'lps_organization' => 'organization',
			'lps_research_area' => 'research-area',
			'lps_project' => 'project',
			'lps_publication' => 'publication',
			'lps_opportunity' => 'opportunity',
			'lps_event' => 'event',
			'lps_news' => 'news',
			'lps_redirect' => 'redirect',
			'lps_course', 'lps_term', 'lps_offering', 'lps_unit', 'lps_resource' => 'teaching',
			default => $post_type,
		};
	}

	/** Returns the role label.
	 *
	 * @param string $role Policy role.
	 */
	private static function label( string $role ): string {
		return ucwords( str_replace( '-', ' ', $role ) );
	}

	/** Returns required WordPress primitive capabilities.
	 *
	 * @param string $role Policy role.
	 * @return array<int, string>
	 */
	private static function native_capabilities( string $role ): array {
		$capabilities = array();
		$may_edit     = SecurityPolicy::allows( $role, 'edit' );
		$scoped       = TeachingPolicy::is_scoped_role( $role );
		if ( $may_edit ) {
			// The registered meta auth callback (`Policy::can_edit_meta`) gates on
			// `edit_posts`; without it every REST meta write is denied. Per-post
			// and per-type checks still scope what this primitive can touch.
			$capabilities[] = 'edit_posts';
		}
		foreach ( array_keys( Contracts::post_types() ) as $post_type ) {
			if ( $scoped && ! in_array( $post_type, TeachingPolicy::SCOPED_POST_TYPES, true ) ) {
				continue;
			}
			if ( 'page' === $post_type ) {
				if ( $may_edit ) {
					$capabilities[] = 'edit_pages';
				}
				if ( SecurityPolicy::allows( $role, 'publish' ) ) {
					$capabilities[] = 'publish_pages';
					$capabilities[] = 'edit_published_pages';
				}
				continue;
			}
			$plural = $post_type . 's';
			if ( $may_edit ) {
				$capabilities[] = 'edit_' . $plural;
				$capabilities[] = 'edit_' . $post_type;
			}
			if ( in_array( $role, array( 'section-editor', 'publisher', 'administrator' ), true ) || $scoped ) {
				$capabilities[] = 'edit_others_' . $plural;
			}
			if ( SecurityPolicy::allows( $role, 'publish' ) ) {
				// Every record type a scoped role reaches here is publishable, so
				// the grant decision alone gates these primitives.
				$capabilities[] = 'publish_' . $plural;
				$capabilities[] = 'edit_published_' . $plural;
			}
		}
		if ( 'administrator' === $role ) {
			$capabilities = array_merge( $capabilities, array( 'manage_options', 'list_users', 'create_users', 'edit_users', 'promote_users' ) );
		}
		if ( 'privacy-auditor' === $role || 'administrator' === $role ) {
			$capabilities[] = 'list_users';
		}
		return array_values( array_unique( $capabilities ) );
	}
}
