<?php
/**
 * Pure least-privilege security policy.
 *
 * @package LPS\ContentModel
 */

declare(strict_types=1);

namespace LPS\ContentModel;

use DateInterval;
use DateTimeImmutable;

/** Deterministic policy shared by WordPress adapters and tests. */
final class SecurityPolicy {
	/** Role-to-action permission map.
	 *
	 * @var array<string, array<int, string>>
	 */
	private const ACTIONS = array(
		'contributor'     => array( 'create', 'edit', 'submit' ),
		'translator'      => array( 'edit', 'submit' ),
		'section-editor'  => array( 'create', 'edit', 'submit', 'review', 'archive', 'grant-scope', 'revoke-scope' ),
		'publisher'       => array( 'create', 'edit', 'submit', 'review', 'publish', 'unpublish', 'archive', 'redirect' ),
		'administrator'   => array( 'create', 'edit', 'submit', 'review', 'publish', 'unpublish', 'archive', 'import', 'redirect', 'settings', 'audit', 'dormant-report', 'grant-scope', 'revoke-scope' ),
		'privacy-auditor' => array( 'review', 'audit', 'dormant-report' ),
		'deployer'        => array( 'deploy' ),
		'professor'       => array( 'create', 'edit', 'submit', 'publish', 'copy-forward' ),
		'delegate'        => array( 'create', 'edit', 'submit' ),
	);

	/** Returns all governed policy roles.
	 *
	 * @return array<int, string>
	 */
	public static function roles(): array {
		return array_keys( self::ACTIONS );
	}

	/** Returns all actions retained by the audit ledger.
	 *
	 * @return array<int, string>
	 */
	public static function audited_actions(): array {
		return array( 'create', 'edit', 'submit', 'review', 'publish', 'unpublish', 'archive', 'import', 'redirect', 'settings', 'grant-scope', 'revoke-scope' );
	}

	/**
	 * Returns whether a role may perform an action in its collection assignment.
	 *
	 * @param string             $role                 Policy role.
	 * @param string             $action               Requested action.
	 * @param string             $collection           Collection key.
	 * @param array<int, string> $assigned_collections Assigned collection keys.
	 */
	public static function allows( string $role, string $action, string $collection = '', array $assigned_collections = array() ): bool {
		if ( ! in_array( $action, self::ACTIONS[ $role ] ?? array(), true ) ) {
			return false;
		}
		if ( '' === $collection || ! in_array( $role, array( 'contributor', 'translator', 'section-editor' ), true ) ) {
			return true;
		}
		return in_array( $collection, $assigned_collections, true );
	}

	/** Returns the custom primitive capability for an action.
	 *
	 * @param string $action Action key.
	 */
	public static function capability( string $action ): string {
		return 'lps_' . str_replace( '-', '_', $action );
	}

	/** Returns custom primitive capabilities for a role.
	 *
	 * @param string $role Policy role.
	 * @return array<int, string>
	 */
	public static function capabilities_for_role( string $role ): array {
		return array_map( array( self::class, 'capability' ), self::ACTIONS[ $role ] ?? array() );
	}

	/** Accounts holding public publishing authority require an enrolled Two-Factor provider.
	 *
	 * Publisher and administrator keep their existing requirement; professor is
	 * the new public-publishing role and meets the same contract. Delegate never
	 * publishes, so it is not MFA-gated.
	 *
	 * @param string $role Policy role.
	 */
	public static function requires_mfa( string $role ): bool {
		return in_array( $role, array( 'publisher', 'administrator', 'professor' ), true );
	}

	/** Checks the privileged-session MFA boundary.
	 *
	 * @param string $role         Policy role.
	 * @param bool   $mfa_enrolled Whether a provider is enabled.
	 */
	public static function privileged_session_allowed( string $role, bool $mfa_enrolled ): bool {
		return ! self::requires_mfa( $role ) || $mfa_enrolled;
	}

	/** Translators own prose in English variants, never shared identity/date/relation fields.
	 *
	 * @param string $role   Policy role.
	 * @param string $field  Field key.
	 * @param string $locale Locale key.
	 */
	public static function can_write_field( string $role, string $field, string $locale ): bool {
		if ( 'translator' !== $role ) {
			return true;
		}
		if ( 'en' !== $locale ) {
			return false;
		}
		if ( in_array( $field, array( 'post_title', 'post_excerpt', 'post_content' ), true ) ) {
			return true;
		}
		$localized = array( '_lps_label', '_lps_synonyms', '_lps_eligibility', '_lps_application_instructions' );
		return in_array( $field, $localized, true );
	}

	/** Returns fields forbidden on public surfaces.
	 *
	 * @return array<int, string>
	 */
	public static function private_fields(): array {
		return array( 'capabilities', 'allcaps', '_lps_owner_user_id', '_lps_translation_reviewer_id', 'audit', 'audit_id', 'actor_user_id', 'revision_id', 'previous_hash', 'entry_hash' );
	}

	/** Returns public REST response fields.
	 *
	 * @return array<int, string>
	 */
	public static function public_fields(): array {
		return array( 'id', 'date', 'slug', 'status', 'type', 'link', 'title', 'content', 'excerpt', 'meta' );
	}

	/**
	 * Returns Person metadata, which deliberately has no account foreign key.
	 *
	 * @return array<int, string>
	 */
	public static function person_meta_fields(): array {
		if ( ! class_exists( Contracts::class ) ) {
			return array( '_lps_canonical_name', '_lps_sort_name', '_lps_person_status' );
		}
		return array_keys( Contracts::meta_fields()['lps_person'] );
	}

	/** Checks generic role names forbidden for individual accounts.
	 *
	 * @param string $login Candidate login.
	 */
	public static function shared_account_name_forbidden( string $login ): bool {
		$normalized = strtolower( trim( $login ) );
		$normalized = str_replace( array( '_', '.', ' ' ), '-', $normalized );
		$aliases    = array_merge( self::roles(), array( 'admin', 'administrator', 'editor', 'publisher', 'translator', 'deployer' ) );
		foreach ( $aliases as $alias ) {
			if ( $alias === $normalized || 'lps-' . $alias === $normalized || $alias . '-lps' === $normalized ) {
				return true;
			}
		}
		return false;
	}

	/** Calculates an ISO dormant cutoff.
	 *
	 * @param string $now  Reference time.
	 * @param int    $days Dormancy interval.
	 */
	public static function dormant_cutoff( string $now, int $days ): string {
		$date = new DateTimeImmutable( $now );
		return $date->sub( new DateInterval( 'P' . max( 1, $days ) . 'D' ) )->format( 'c' );
	}

	/** Checks whether activity predates the cutoff.
	 *
	 * @param string $last_activity Last activity time.
	 * @param string $now           Reference time.
	 * @param int    $days          Dormancy interval.
	 */
	public static function is_dormant( string $last_activity, string $now, int $days ): bool {
		return new DateTimeImmutable( $last_activity ) < new DateTimeImmutable( self::dormant_cutoff( $now, $days ) );
	}

	/**
	 * Builds the canonical append-only hash-chain payload.
	 *
	 * @param int    $actor_user_id Actor account ID.
	 * @param string $action        Action key.
	 * @param int    $object_id     Record ID.
	 * @param int    $revision_id   Revision ID.
	 * @param string $occurred_at   Immutable event time.
	 * @param string $previous_hash Previous chain hash.
	 * @return array{actor_user_id: int, occurred_at: string, action: string, object_id: int, revision_id: int, previous_hash: string, entry_hash: string}
	 */
	public static function audit_payload( int $actor_user_id, string $action, int $object_id, int $revision_id, string $occurred_at, string $previous_hash ): array {
		$canonical = implode( '|', array( $actor_user_id, $occurred_at, $action, $object_id, $revision_id, $previous_hash ) );
		return array(
			'actor_user_id' => $actor_user_id,
			'occurred_at'   => $occurred_at,
			'action'        => $action,
			'object_id'     => $object_id,
			'revision_id'   => $revision_id,
			'previous_hash' => $previous_hash,
			'entry_hash'    => hash( 'sha256', $canonical ),
		);
	}
}
