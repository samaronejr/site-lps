<?php
/**
 * Member-category registry for the dashboard user-management lane.
 *
 * A member category names a laboratory relationship — professor, doctoral
 * student, secretary — and carries the account privileges that relationship
 * implies: the policy role the account is minted with plus, for
 * collection-scoped roles, the collections it may touch. Built-in
 * categories cover the laboratory's standing relationships; authorized
 * dashboard users may mint additional categories with their own
 * role/collection profile.
 *
 * @package LPS\ContentModel
 */

declare(strict_types=1);

namespace LPS\ContentModel;

use WP_Error;
use WP_User;

/** Member categories and the accounts minted under them. */
final class MemberCategories {

	/** Option storing the custom category definitions. */
	public const OPTION = 'lps_member_categories';

	/** User meta recording the category an account was minted under. */
	public const CATEGORY_META = '_lps_member_category';

	/** User meta recording a suspended member account. */
	public const SUSPENDED_META = '_lps_member_suspended';

	/** User meta preserving the role a suspended account held. */
	public const SUSPENDED_ROLE_META = '_lps_member_suspended_role';

	/** User meta forcing an invite-password rotation on next request. */
	public const FORCE_RESET_META = '_lps_member_force_reset';

	/** Pseudo-role for member-only accounts (the stock `subscriber` role). */
	public const MEMBER_ROLE = 'member';

	/**
	 * Registers the invite-password rotation hooks.
	 *
	 * A lane-minted account carries an initial password the creator hands
	 * over; until the member replaces it through the core reset screen the
	 * flag keeps every signed-in request routed back to that screen.
	 */
	public static function boot(): void {
		add_action( 'init', array( self::class, 'enforce_password_rotation' ) );
		add_action( 'password_reset', array( self::class, 'clear_password_rotation' ) );
	}

	/** Redirects a flagged member to the password-reset screen.
	 *
	 * Login pages, AJAX and CLI contexts are exempt — the reset form itself
	 * lives under `wp-login.php`, and machine contexts never browse.
	 */
	public static function enforce_password_rotation(): void {
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			return;
		}
		if ( ! function_exists( 'is_user_logged_in' ) || ! is_user_logged_in() ) {
			return;
		}
		$user = wp_get_current_user();
		if ( '1' !== get_user_meta( $user->ID, self::FORCE_RESET_META, true ) ) {
			return;
		}
		if ( ( defined( 'DOING_AJAX' ) && DOING_AJAX ) || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return;
		}
		$script = '';
		if ( isset( $_SERVER['SCRIPT_NAME'] ) && is_string( $_SERVER['SCRIPT_NAME'] ) ) {
			$script = sanitize_text_field( wp_unslash( $_SERVER['SCRIPT_NAME'] ) );
		}
		if ( false !== strpos( $script, 'wp-login.php' ) ) {
			return;
		}
		$key = get_password_reset_key( $user );
		if ( $key instanceof WP_Error ) {
			return;
		}
		// Core's reset screen lives on `wp-login.php`; the theme rewrites
		// `wp_login_url()` to the branded sign-in page, which would drop the
		// reset action and strand the member on a plain login form.
		wp_safe_redirect(
			add_query_arg(
				array(
					'action' => 'rp',
					'key'    => $key,
					'login'  => rawurlencode( $user->user_login ),
				),
				home_url( 'wp-login.php' )
			)
		);
		exit;
	}

	/** Clears the rotation flag once the member sets a new password.
	 *
	 * @param WP_User $user Account whose password was just reset.
	 */
	public static function clear_password_rotation( WP_User $user ): void {
		delete_user_meta( $user->ID, self::FORCE_RESET_META );
	}

	/** Policy roles a member category may carry, least to most privilege. */
	private const ASSIGNABLE_ROLES = array(
		'member',
		'contributor',
		'translator',
		'delegate',
		'professor',
		'section-editor',
		'publisher',
	);

	/** Returns the built-in category definitions.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function builtins(): array {
		return array(
			'professor'  => array(
				'label_pt'     => 'Professor(a)',
				'label_en'     => 'Professor',
				'role'         => 'professor',
				'collections'  => array(),
				'person_roles' => array( 'professor' ),
			),
			'doutorado'  => array(
				'label_pt'     => 'Doutorando(a)',
				'label_en'     => 'PhD student',
				'role'         => self::MEMBER_ROLE,
				'collections'  => array(),
				'person_roles' => array( 'doutorando' ),
			),
			'mestrado'   => array(
				'label_pt'     => 'Mestrando(a)',
				'label_en'     => "Master's student",
				'role'         => self::MEMBER_ROLE,
				'collections'  => array(),
				'person_roles' => array( 'mestrando' ),
			),
			'graduacao'  => array(
				'label_pt'     => 'Graduando(a)',
				'label_en'     => 'Undergraduate student',
				'role'         => self::MEMBER_ROLE,
				'collections'  => array(),
				'person_roles' => array( 'graduando' ),
			),
			'secretaria' => array(
				'label_pt'     => 'Secretaria do laboratório',
				'label_en'     => 'Lab secretary',
				'role'         => 'publisher',
				'collections'  => array(),
				'person_roles' => array( 'secretaria' ),
			),
		);
	}

	/** Returns every category, built-ins merged with the stored customs.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function categories(): array {
		$categories = array();
		foreach ( self::builtins() as $key => $definition ) {
			$definition['key']     = $key;
			$definition['builtin'] = true;
			$categories[ $key ]    = $definition;
		}
		$stored = get_option( self::OPTION, array() );
		if ( is_array( $stored ) ) {
			foreach ( $stored as $key => $definition ) {
				if ( ! is_string( $key ) || '' === $key || ! is_array( $definition ) || isset( $categories[ $key ] ) ) {
					continue;
				}
				$input = array();
				foreach ( $definition as $def_key => $def_value ) {
					if ( is_string( $def_key ) ) {
						$input[ $def_key ] = $def_value;
					}
				}
				$normalized = self::normalize_definition( $key, $input );
				if ( null === $normalized ) {
					continue;
				}
				$normalized['key']     = $key;
				$normalized['builtin'] = false;
				$categories[ $key ]    = $normalized;
			}
		}
		return $categories;
	}

	/** Returns one category definition or null.
	 *
	 * @param string $key Category key.
	 * @return array<string, mixed>|null
	 */
	public static function category( string $key ): ?array {
		$categories = self::categories();
		return $categories[ $key ] ?? null;
	}

	/** Returns the localized label of one category.
	 *
	 * @param array<string, mixed> $category Category definition.
	 * @param string               $locale   Supported locale slug.
	 */
	public static function label( array $category, string $locale ): string {
		$primary   = 'en' === $locale ? 'label_en' : 'label_pt';
		$secondary = 'en' === $locale ? 'label_pt' : 'label_en';
		$label     = $category[ $primary ] ?? '';
		if ( ! is_string( $label ) || '' === $label ) {
			$label = $category[ $secondary ] ?? '';
		}
		return is_string( $label ) ? $label : '';
	}

	/** Returns the policy-role options a creator may pick for a new category.
	 *
	 * Professors may delegate privileges up to the publishing lanes; the
	 * site-level `administrator` role never appears — minting a site admin
	 * stays an administrator-only action taken outside this lane.
	 *
	 * @param string $actor_role Acting account's policy role.
	 * @return array<int, string>
	 */
	public static function role_options( string $actor_role ): array {
		if ( 'administrator' === $actor_role ) {
			return array_merge( self::ASSIGNABLE_ROLES, array( 'administrator' ) );
		}
		return self::ASSIGNABLE_ROLES;
	}

	/** Returns the localized label of one assignable policy role.
	 *
	 * @param string $role   Policy role key.
	 * @param string $locale Supported locale slug.
	 */
	public static function role_label( string $role, string $locale ): string {
		$english = 'en' === $locale;
		return match ( $role ) {
			self::MEMBER_ROLE => $english ? 'Member — sign in and member areas only' : 'Membro — apenas entrar e áreas de membro',
			'contributor' => $english ? 'Contributor — draft content in assigned areas' : 'Contribuinte — rascunha conteúdo nas áreas atribuídas',
			'translator' => $english ? 'Translator — translate content in assigned areas' : 'Tradutor(a) — traduz conteúdo nas áreas atribuídas',
			'delegate' => $english ? 'Delegate — assists in granted offering workspaces' : 'Delegado(a) — auxilia nas ofertas concedidas',
			'professor' => $english ? 'Professor — teach, publish own offerings and submit news' : 'Docente — leciona, publica as próprias ofertas e envia notícias',
			'section-editor' => $english ? 'Section editor — manage and review content in assigned areas' : 'Editor(a) de seção — gerencia e revisa conteúdo nas áreas atribuídas',
			'publisher' => $english ? 'Publisher — full editorial pipeline on site content' : 'Editor(a) publicador(a) — fluxo editorial completo do conteúdo',
			'administrator' => $english ? 'Administrator — full site administration' : 'Administrador(a) — administração completa do site',
			default => $role,
		};
	}

	/** Returns whether a role carries collection assignments.
	 *
	 * @param string $role Policy role key.
	 */
	public static function is_collection_role( string $role ): bool {
		return in_array( $role, array( 'contributor', 'translator', 'section-editor' ), true );
	}

	/** Returns the WordPress role slug a category mints.
	 *
	 * @param array<string, mixed> $category Category definition.
	 */
	public static function wp_role( array $category ): string {
		$role = is_string( $category['role'] ?? null ) ? $category['role'] : '';
		if ( '' === $role || self::MEMBER_ROLE === $role ) {
			return 'subscriber';
		}
		return Roles::slug( $role );
	}

	/** Validates and persists a new custom category.
	 *
	 * @param array<string, mixed> $input      Raw definition input.
	 * @param string               $actor_role Acting account's policy role.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function create( array $input, string $actor_role ): array|WP_Error {
		$key = sanitize_key( Policy::scalar_string( $input['key'] ?? '' ) );
		if ( '' === $key ) {
			$key = sanitize_key( Policy::scalar_string( $input['label_pt'] ?? '' ) );
		}
		if ( '' === $key ) {
			return new WP_Error( 'lps_required_category_key' );
		}
		$definition = self::normalize_definition( $key, $input );
		if ( null === $definition ) {
			return new WP_Error( 'lps_invalid_category' );
		}
		if ( ! in_array( $definition['role'], self::role_options( $actor_role ), true ) ) {
			return new WP_Error( 'lps_category_role_forbidden' );
		}
		if ( isset( self::categories()[ $key ] ) ) {
			return new WP_Error( 'lps_category_exists' );
		}
		$stored         = get_option( self::OPTION, array() );
		$stored         = is_array( $stored ) ? $stored : array();
		$stored[ $key ] = array(
			'label_pt'     => $definition['label_pt'],
			'label_en'     => $definition['label_en'],
			'role'         => $definition['role'],
			'collections'  => $definition['collections'],
			'person_roles' => $definition['person_roles'],
		);
		update_option( self::OPTION, $stored, false );
		$definition['key']     = $key;
		$definition['builtin'] = false;
		return $definition;
	}

	/** Removes a custom category when no member account references it.
	 *
	 * @param string $key Category key.
	 * @return true|WP_Error
	 */
	public static function remove( string $key ): bool|WP_Error {
		$category = self::category( $key );
		if ( null === $category ) {
			return new WP_Error( 'lps_category_missing' );
		}
		if ( ! empty( $category['builtin'] ) ) {
			return new WP_Error( 'lps_category_builtin' );
		}
		$holders = get_users(
			array(
				'fields'     => 'ids',
				'number'     => 1,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Exact-key lookup on the usermeta index; the member list's own query is capped and cannot serve as the in-use guard.
				'meta_query' => array(
					array(
						'key'   => self::CATEGORY_META,
						'value' => $key,
					),
				),
			)
		);
		if ( array() !== $holders ) {
			return new WP_Error( 'lps_category_in_use' );
		}
		$stored = get_option( self::OPTION, array() );
		if ( is_array( $stored ) && isset( $stored[ $key ] ) ) {
			unset( $stored[ $key ] );
			update_option( self::OPTION, $stored, false );
		}
		return true;
	}

	/** Returns member accounts: every account with an lps role or a
	 * member-category stamp, so pre-lane accounts appear too.
	 *
	 * @return array<int, WP_User>
	 */
	public static function member_users(): array {
		if ( ! function_exists( 'get_users' ) ) {
			return array();
		}
		$users = get_users( array( 'number' => 500 ) );
		$out   = array();
		foreach ( $users as $user ) {
			if ( ! $user instanceof WP_User ) {
				continue;
			}
			$category_meta = get_user_meta( $user->ID, self::CATEGORY_META, true );
			if ( '' !== $category_meta || '' !== Roles::policy_role( $user ) ) {
				$out[] = $user;
			}
		}
		return $out;
	}

	/** Returns whether the account was minted through the member lane.
	 *
	 * @param WP_User $user Account.
	 */
	public static function is_member( WP_User $user ): bool {
		$category = get_user_meta( $user->ID, self::CATEGORY_META, true );
		return is_string( $category ) && '' !== $category;
	}

	/** Returns the member's category key, when minted through the lane.
	 *
	 * @param int $user_id Account ID.
	 */
	public static function category_for_user( int $user_id ): string {
		$key = get_user_meta( $user_id, self::CATEGORY_META, true );
		return is_string( $key ) ? $key : '';
	}

	/** Returns whether the member account is suspended.
	 *
	 * @param int $user_id Account ID.
	 */
	public static function is_suspended( int $user_id ): bool {
		$suspended = get_user_meta( $user_id, self::SUSPENDED_META, true );
		return '1' === $suspended;
	}

	/** Validates a raw definition into the stored shape, or null.
	 *
	 * @param string               $key   Category key.
	 * @param array<string, mixed> $input Raw definition.
	 * @return array<string, mixed>|null
	 */
	private static function normalize_definition( string $key, array $input ): ?array {
		$label_pt = sanitize_text_field( Policy::scalar_string( $input['label_pt'] ?? '' ) );
		$label_en = sanitize_text_field( Policy::scalar_string( $input['label_en'] ?? '' ) );
		$role     = sanitize_key( Policy::scalar_string( $input['role'] ?? '' ) );
		if ( '' === $key || '' === $label_pt || ! in_array( $role, array_merge( self::ASSIGNABLE_ROLES, array( 'administrator' ) ), true ) ) {
			return null;
		}
		$collections = array();
		foreach ( (array) ( $input['collections'] ?? array() ) as $collection ) {
			$collection = sanitize_key( Policy::scalar_string( $collection ) );
			if ( '' !== $collection && in_array( $collection, Roles::collections(), true ) ) {
				$collections[] = $collection;
			}
		}
		$person_roles = array();
		foreach ( (array) ( $input['person_roles'] ?? array() ) as $person_role ) {
			$person_role = sanitize_text_field( Policy::scalar_string( $person_role ) );
			if ( '' !== $person_role ) {
				$person_roles[] = $person_role;
			}
		}
		return array(
			'label_pt'     => $label_pt,
			'label_en'     => $label_en,
			'role'         => $role,
			'collections'  => array_values( array_unique( $collections ) ),
			'person_roles' => array_values( array_unique( $person_roles ) ),
		);
	}
}
