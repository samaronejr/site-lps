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

/** Installs named roles and enforces per-account collection assignment. */
final class Roles {
	public const COLLECTIONS_META = '_lps_assigned_collections';

	/** Registers runtime authorization hooks. */
	public static function boot(): void {
		add_filter( 'map_meta_cap', array( self::class, 'map_record_capability' ), 20, 4 );
		add_filter( 'user_has_cap', array( self::class, 'strip_unassigned_collections' ), 15, 4 );
		add_filter( 'user_has_cap', array( MFA::class, 'strip_unenrolled_privileges' ), 20, 4 );
		add_action( 'user_profile_update_errors', array( self::class, 'reject_shared_account' ), 10, 3 );
		add_action( 'wp_login', array( self::class, 'record_login' ), 10, 2 );
		add_action( 'show_user_profile', array( self::class, 'render_collection_assignments' ) );
		add_action( 'edit_user_profile', array( self::class, 'render_collection_assignments' ) );
		add_action( 'personal_options_update', array( self::class, 'save_collection_assignments' ) );
		add_action( 'edit_user_profile_update', array( self::class, 'save_collection_assignments' ) );
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
	 * @param string $action     Action key.
	 * @param string $collection Collection key.
	 */
	public static function current_user_can_action( string $action, string $collection = '' ): bool {
		$user = wp_get_current_user();
		$role = self::policy_role( $user );
		if ( '' === $role || ! SecurityPolicy::allows( $role, $action, $collection, self::assigned_collections( $user->ID ) ) ) {
			return false;
		}
		return SecurityPolicy::privileged_session_allowed( $role, MFA::is_enrolled( $user->ID ) );
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
		foreach ( array_keys( Contracts::post_types() ) as $post_type ) {
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
			if ( in_array( $role, array( 'section-editor', 'publisher', 'administrator' ), true ) ) {
				$capabilities[] = 'edit_others_' . $plural;
			}
			if ( SecurityPolicy::allows( $role, 'publish' ) ) {
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
