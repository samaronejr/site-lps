<?php
/**
 * Append-only attributable editorial audit history.
 *
 * @package LPS\ContentModel
 */

declare(strict_types=1);

namespace LPS\ContentModel;

use WP_Post;
use wpdb;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The append-only audit ledger is intentionally not represented as public WordPress content.
/** Owns the private append-only hash-chained audit ledger. */
final class Audit {
	public const VERSION         = '1.0.0';
	private const VERSION_OPTION = 'lps_audit_schema_version';

	/** Registers ledger capture hooks. */
	public static function boot(): void {
		add_action( 'wp_after_insert_post', array( self::class, 'capture_post_write' ), 200, 4 );
		add_action( 'transition_post_status', array( self::class, 'capture_transition' ), 200, 3 );
		add_action( 'updated_option', array( self::class, 'capture_setting' ), 10, 3 );
		add_action( 'added_option', array( self::class, 'capture_added_setting' ), 10, 2 );
		add_action( 'updated_post_meta', array( self::class, 'capture_review' ), 10, 4 );
	}

	/** Returns the private ledger table name.
	 *
	 * @param wpdb|null $database Optional database adapter.
	 */
	public static function table_name( ?wpdb $database = null ): string {
		$database = $database ?? self::database();
		return $database->prefix . 'lps_audit_log';
	}

	/** Builds the deterministic ledger schema.
	 *
	 * @param string $prefix          Database prefix.
	 * @param string $charset_collate Charset and collation clause.
	 */
	public static function schema_sql( string $prefix, string $charset_collate ): string {
		$table = $prefix . 'lps_audit_log';
		return "CREATE TABLE {$table} (
 audit_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
 actor_user_id bigint(20) unsigned NOT NULL,
 occurred_at varchar(32) NOT NULL,
 action varchar(24) NOT NULL,
 object_id bigint(20) unsigned NOT NULL DEFAULT 0,
 revision_id bigint(20) unsigned NOT NULL DEFAULT 0,
 context_json longtext NOT NULL,
 previous_hash char(64) NOT NULL DEFAULT '',
 entry_hash char(64) NOT NULL,
 PRIMARY KEY  (audit_id),
 UNIQUE KEY entry_hash (entry_hash),
 KEY object_history (object_id,audit_id),
 KEY actor_history (actor_user_id,audit_id),
 KEY action_history (action,audit_id)
) {$charset_collate};";
	}

	/** Installs or repairs append-only ledger storage. */
	public static function install(): void {
		$wpdb = self::database();
		require_once dirname( __DIR__, 4 ) . '/wp-admin/includes/upgrade.php';
		dbDelta( self::schema_sql( $wpdb->prefix, $wpdb->get_charset_collate() ) );
		update_option( self::VERSION_OPTION, self::VERSION, false );
	}

	/**
	 * Appends one immutable actor/time/action/revision entry.
	 *
	 * @param string                     $action        Audited action key.
	 * @param int                        $object_id     Record ID.
	 * @param int                        $revision_id   Revision ID.
	 * @param array<string, scalar|null> $context       Private machine context.
	 * @param int|null                   $actor_user_id Optional explicit actor.
	 * @throws \InvalidArgumentException Unsupported actions.
	 * @throws \RuntimeException Database append failures.
	 */
	public static function record( string $action, int $object_id = 0, int $revision_id = 0, array $context = array(), ?int $actor_user_id = null ): int {
		if ( ! in_array( $action, SecurityPolicy::audited_actions(), true ) ) {
			throw new \InvalidArgumentException( 'Unsupported audit action.' );
		}
		$wpdb     = self::database();
		$table    = self::table_name( $wpdb );
		$previous = $wpdb->get_var( "SELECT entry_hash FROM {$table} ORDER BY audit_id DESC LIMIT 1" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Trusted migration-owned table name.
		$previous = is_string( $previous ) ? $previous : '';
		$actor    = null === $actor_user_id ? get_current_user_id() : $actor_user_id;
		$payload  = SecurityPolicy::audit_payload( $actor, $action, $object_id, $revision_id, gmdate( 'c' ), $previous );
		$encoded  = wp_json_encode( $context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		$inserted = $wpdb->insert(
			$table,
			array_merge( $payload, array( 'context_json' => false === $encoded ? '{}' : $encoded ) ),
			array( '%d', '%s', '%s', '%d', '%d', '%s', '%s', '%s' )
		);
		if ( false === $inserted ) {
			throw new \RuntimeException( 'Unable to append the immutable audit entry.' );
		}
		return (int) $wpdb->insert_id;
	}

	/**
	 * Returns private ledger entries in descending order.
	 *
	 * @param int $limit Maximum rows.
	 * @return array<int, array<string, int|string>>
	 */
	public static function entries( int $limit = 200 ): array {
		$wpdb   = self::database();
		$table  = self::table_name( $wpdb );
		$rows   = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i ORDER BY audit_id DESC LIMIT %d', $table, max( 1, min( 1000, $limit ) ) ), 'ARRAY_A' );
		$result = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$result[] = array(
				'audit_id'      => Policy::sanitize_integer( $row['audit_id'] ?? 0 ),
				'actor_user_id' => Policy::sanitize_integer( $row['actor_user_id'] ?? 0 ),
				'occurred_at'   => Policy::scalar_string( $row['occurred_at'] ?? '' ),
				'action'        => Policy::scalar_string( $row['action'] ?? '' ),
				'object_id'     => Policy::sanitize_integer( $row['object_id'] ?? 0 ),
				'revision_id'   => Policy::sanitize_integer( $row['revision_id'] ?? 0 ),
				'context_json'  => Policy::scalar_string( $row['context_json'] ?? '{}' ),
				'previous_hash' => Policy::scalar_string( $row['previous_hash'] ?? '' ),
				'entry_hash'    => Policy::scalar_string( $row['entry_hash'] ?? '' ),
			);
		}
		return $result;
	}

	/** Verifies the complete immutable hash chain from oldest to newest. */
	public static function verify_chain(): bool {
		$previous = '';
		foreach ( array_reverse( self::entries( 1000 ) ) as $row ) {
			$payload = SecurityPolicy::audit_payload( (int) $row['actor_user_id'], (string) $row['action'], (int) $row['object_id'], (int) $row['revision_id'], (string) $row['occurred_at'], $previous );
			if ( ! hash_equals( (string) $row['previous_hash'], $previous ) || ! hash_equals( (string) $row['entry_hash'], $payload['entry_hash'] ) ) {
				return false;
			}
			$previous = (string) $row['entry_hash'];
		}
		return true;
	}

	/** Captures record create/edit and redirect actions.
	 *
	 * @param int          $post_id     Record ID.
	 * @param WP_Post      $post        Stored record.
	 * @param bool         $update      Whether updated.
	 * @param WP_Post|null $post_before Previous record.
	 */
	public static function capture_post_write( int $post_id, WP_Post $post, bool $update, ?WP_Post $post_before ): void {
		unset( $post_before );
		if ( ! isset( Contracts::post_types()[ $post->post_type ] ) || wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}
		$revision_id = self::latest_revision_id( $post_id );
		self::record(
			$update ? 'edit' : 'create',
			$post_id,
			$revision_id,
			array(
				'post_type' => $post->post_type,
				'status'    => $post->post_status,
			)
		);
		if ( 'lps_redirect' === $post->post_type ) {
			self::record( 'redirect', $post_id, $revision_id, array( 'status' => $post->post_status ) );
		}
	}

	/** Captures submit, publish, unpublish, and archive transitions.
	 *
	 * @param string  $new_status New status.
	 * @param string  $old_status Previous status.
	 * @param WP_Post $post       Record.
	 */
	public static function capture_transition( string $new_status, string $old_status, WP_Post $post ): void {
		if ( $new_status === $old_status || ! isset( Contracts::post_types()[ $post->post_type ] ) ) {
			return;
		}
		$action = match ( true ) {
			'pending' === $new_status => 'submit',
			'publish' === $new_status => 'publish',
			'publish' === $old_status && 'publish' !== $new_status => 'unpublish',
			'lps_archived' === $new_status => 'archive',
			default => '',
		};
		if ( '' !== $action ) {
			self::record(
				$action,
				$post->ID,
				self::latest_revision_id( $post->ID ),
				array(
					'from' => $old_status,
					'to'   => $new_status,
				)
			);
		}
	}

	/** Captures an attributable section-editor review transition.
	 *
	 * @param int    $meta_id    Metadata row ID.
	 * @param int    $object_id  Record ID.
	 * @param string $meta_key   Metadata key.
	 * @param mixed  $meta_value New metadata value.
	 */
	public static function capture_review( int $meta_id, int $object_id, string $meta_key, mixed $meta_value ): void {
		unset( $meta_id );
		$post = get_post( $object_id );
		if ( '_lps_state' !== $meta_key || 'in_review' !== Policy::scalar_string( $meta_value ) || ! $post instanceof WP_Post ) {
			return;
		}
		$collection = Roles::collection_for_post_type( $post->post_type );
		if ( Roles::current_user_can_action( 'review', $collection ) ) {
			self::record( 'review', $object_id, self::latest_revision_id( $object_id ), array( 'post_type' => $post->post_type ) );
		}
	}

	/** Captures changed governed settings.
	 *
	 * @param string $option    Option key.
	 * @param mixed  $old_value Previous value.
	 * @param mixed  $value     New value.
	 */
	public static function capture_setting( string $option, mixed $old_value, mixed $value ): void {
		if ( Contracts::OPTION_NAME !== $option || $old_value === $value ) {
			return;
		}
		self::record( 'settings', 0, 0, array( 'option' => $option ) );
	}

	/** Captures first creation of governed settings.
	 *
	 * @param string $option Option key.
	 * @param mixed  $value  New value.
	 */
	public static function capture_added_setting( string $option, mixed $value ): void {
		unset( $value );
		if ( Contracts::OPTION_NAME === $option ) {
			self::record( 'settings', 0, 0, array( 'option' => $option ) );
		}
	}

	/** Returns the latest attributable revision ID.
	 *
	 * @param int $post_id Record ID.
	 */
	private static function latest_revision_id( int $post_id ): int {
		$revisions = wp_get_post_revisions(
			$post_id,
			array(
				'posts_per_page' => 1,
				'order'          => 'DESC',
			)
		);
		$revision  = reset( $revisions );
		return $revision instanceof WP_Post ? $revision->ID : 0;
	}

	/** Returns the initialized database adapter.
	 *
	 * @throws \RuntimeException Missing adapter.
	 */
	private static function database(): wpdb {
		global $wpdb;
		if ( ! $wpdb instanceof wpdb ) {
			throw new \RuntimeException( 'WordPress database adapter is unavailable.' );
		}
		return $wpdb;
	}
}
// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
