<?php
/**
 * Two-Factor integration for privileged editorial roles.
 *
 * @package LPS\ContentModel
 */

declare(strict_types=1);

namespace LPS\ContentModel;

use WP_User;

/** Requires an enabled provider from the pinned WordPress Two-Factor plugin. */
final class MFA {
	public const REQUIRED_PLUGIN_VERSION = '0.16.0';

	/** Registers enrollment notices. */
	public static function boot(): void {
		add_action( 'admin_notices', array( self::class, 'enrollment_notice' ) );
	}

	/** Checks the exact pinned plugin contract. */
	public static function plugin_available(): bool {
		return class_exists( 'Two_Factor_Core' ) && defined( 'TWO_FACTOR_VERSION' ) && self::REQUIRED_PLUGIN_VERSION === TWO_FACTOR_VERSION;
	}

	/** Checks for at least one enabled provider.
	 *
	 * @param int $user_id Account ID.
	 */
	public static function is_enrolled( int $user_id ): bool {
		if ( ! self::plugin_available() ) {
			return false;
		}
		$callback = array( 'Two_Factor_Core', 'get_available_providers_for_user' );
		if ( ! is_callable( $callback ) ) {
			return false;
		}
		$providers = call_user_func( $callback, $user_id );
		return is_array( $providers ) && ! empty( $providers );
	}

	/**
	 * Removes privileged capabilities until MFA enrollment exists.
	 *
	 * The user retains read/profile access so an individual can enroll; content and settings
	 * mutations remain impossible, including REST and direct admin requests.
	 *
	 * @param array<string, bool> $allcaps Resolved capabilities.
	 * @param array<int, string>  $caps    Required primitive capabilities.
	 * @param array<int, mixed>   $args    Original capability request.
	 * @param WP_User             $user    Current user.
	 * @return array<string, bool>
	 */
	public static function strip_unenrolled_privileges( array $allcaps, array $caps, array $args, WP_User $user ): array {
		unset( $caps, $args );
		$role = Roles::policy_role( $user );
		if ( ! SecurityPolicy::requires_mfa( $role ) || self::is_enrolled( $user->ID ) ) {
			return $allcaps;
		}
		foreach ( array_keys( $allcaps ) as $capability ) {
			if ( ! in_array( $capability, array( 'read', 'exist' ), true ) ) {
				$allcaps[ $capability ] = false;
			}
		}
		return $allcaps;
	}

	/** Renders an actionable enrollment lock notice. */
	public static function enrollment_notice(): void {
		$user = wp_get_current_user();
		$role = Roles::policy_role( $user );
		if ( ! SecurityPolicy::requires_mfa( $role ) || self::is_enrolled( $user->ID ) ) {
			return;
		}
		$url = get_edit_profile_url( $user->ID );
		echo '<div class="notice notice-error"><p><strong>' . esc_html__( 'MFA enrollment required.', 'lps-content-model' ) . '</strong> ';
		echo esc_html__( 'Publisher and Administrator privileges are locked until an enabled provider from Two-Factor 0.16.0 is configured.', 'lps-content-model' ) . ' ';
		echo '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Open your profile to enroll', 'lps-content-model' ) . '</a></p></div>';
	}
}
