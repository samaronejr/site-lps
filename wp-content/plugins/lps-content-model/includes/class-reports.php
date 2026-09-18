<?php
/**
 * Private security and dormant-account reports.
 *
 * @package LPS\ContentModel
 */

declare(strict_types=1);

namespace LPS\ContentModel;

use WP_User;

/** Registers capability-protected private reports; nothing is exposed publicly or via REST. */
final class Reports {
	/** Registers private report hooks. */
	public static function boot(): void {
		add_action( 'admin_menu', array( self::class, 'register_pages' ) );
	}

	/** Registers capability-protected report pages. */
	public static function register_pages(): void {
		add_users_page(
			__( 'Dormant LPS accounts', 'lps-content-model' ),
			__( 'Dormant LPS accounts', 'lps-content-model' ),
			SecurityPolicy::capability( 'dormant-report' ),
			'lps-dormant-accounts',
			array( self::class, 'render_dormant' )
		);
		add_management_page(
			__( 'LPS audit history', 'lps-content-model' ),
			__( 'LPS audit history', 'lps-content-model' ),
			SecurityPolicy::capability( 'audit' ),
			'lps-audit-history',
			array( self::class, 'render_audit' )
		);
		add_management_page(
			__( 'Origin reconciliation', 'lps-content-model' ),
			__( 'Origin reconciliation', 'lps-content-model' ),
			SecurityPolicy::capability( 'review' ),
			'lps-origin-reconciliation',
			array( self::class, 'render_origin_reconciliation' )
		);
	}

	/**
	 * Returns dormant individual CMS accounts independently of Person records.
	 *
	 * @param int         $days Dormancy interval.
	 * @param string|null $now  Optional reference time.
	 * @return array<int, array{id: int, login: string, role: string, last_activity_at: string, days: int}>
	 */
	public static function dormant_accounts( int $days = 180, ?string $now = null ): array {
		$now   = $now ?? gmdate( 'c' );
		$rows  = array();
		$users = get_users( array( 'role__in' => array_map( array( Roles::class, 'slug' ), SecurityPolicy::roles() ) ) );
		foreach ( $users as $user ) {
			if ( ! $user instanceof WP_User ) {
				continue;
			}
			$activity = get_user_meta( $user->ID, '_lps_last_activity_at', true );
			$activity = is_string( $activity ) && '' !== $activity ? $activity : $user->user_registered . '+00:00';
			if ( ! SecurityPolicy::is_dormant( $activity, $now, $days ) ) {
				continue;
			}
			$rows[] = array(
				'id'               => $user->ID,
				'login'            => $user->user_login,
				'role'             => Roles::policy_role( $user ),
				'last_activity_at' => $activity,
				'days'             => $days,
			);
		}
		usort( $rows, static fn( array $left, array $right ): int => array( $left['last_activity_at'], $left['id'] ) <=> array( $right['last_activity_at'], $right['id'] ) );
		return $rows;
	}

	/** Renders the private dormant-account report. */
	public static function render_dormant(): void {
		if ( ! current_user_can( SecurityPolicy::capability( 'dormant-report' ) ) ) {
			wp_die( esc_html__( 'You cannot view dormant-account data.', 'lps-content-model' ), '', array( 'response' => 403 ) );
		}
		$rows = self::dormant_accounts();
		echo '<div class="wrap"><h1>' . esc_html__( 'Dormant LPS accounts', 'lps-content-model' ) . '</h1>';
		echo '<p>' . esc_html__( 'Accounts with no recorded activity for 180 days. Person records are not login accounts and are never included.', 'lps-content-model' ) . '</p>';
		echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Account', 'lps-content-model' ) . '</th><th>' . esc_html__( 'Role', 'lps-content-model' ) . '</th><th>' . esc_html__( 'Last activity', 'lps-content-model' ) . '</th></tr></thead><tbody>';
		foreach ( $rows as $row ) {
			echo '<tr><td>' . esc_html( $row['login'] ) . '</td><td>' . esc_html( $row['role'] ) . '</td><td><time datetime="' . esc_attr( $row['last_activity_at'] ) . '">' . esc_html( $row['last_activity_at'] ) . '</time></td></tr>';
		}
		echo '</tbody></table></div>';
	}

	/** Renders the private immutable audit report. */
	public static function render_audit(): void {
		if ( ! current_user_can( SecurityPolicy::capability( 'audit' ) ) ) {
			wp_die( esc_html__( 'You cannot view audit history.', 'lps-content-model' ), '', array( 'response' => 403 ) );
		}
		echo '<div class="wrap"><h1>' . esc_html__( 'Immutable LPS audit history', 'lps-content-model' ) . '</h1>';
		echo '<p><strong>' . esc_html( Audit::verify_chain() ? __( 'Hash chain verified.', 'lps-content-model' ) : __( 'Hash chain verification failed.', 'lps-content-model' ) ) . '</strong></p>';
		echo '<table class="widefat striped"><thead><tr><th>ID</th><th>' . esc_html__( 'Actor', 'lps-content-model' ) . '</th><th>' . esc_html__( 'Time', 'lps-content-model' ) . '</th><th>' . esc_html__( 'Action', 'lps-content-model' ) . '</th><th>' . esc_html__( 'Object / revision', 'lps-content-model' ) . '</th></tr></thead><tbody>';
		foreach ( Audit::entries() as $row ) {
			echo '<tr><td>' . esc_html( (string) $row['audit_id'] ) . '</td><td>' . esc_html( (string) $row['actor_user_id'] ) . '</td><td>' . esc_html( (string) $row['occurred_at'] ) . '</td><td>' . esc_html( (string) $row['action'] ) . '</td><td>' . esc_html( (string) $row['object_id'] . ' / ' . (string) $row['revision_id'] ) . '</td></tr>';
		}
		echo '</tbody></table></div>';
	}

	/**
	 * Renders the ambiguous-origin reconciliation queue.
	 *
	 * The report is a review queue, never an automatic trust decision: rows
	 * are classified by `PublicationPolicy::reconciliation_class()` and an
	 * editor must reconcile each record deliberately. Ambiguous records are
	 * already withheld from every public surface by the unified visibility
	 * decision, so listing them here changes nothing about their trust.
	 */
	public static function render_origin_reconciliation(): void {
		if ( ! current_user_can( SecurityPolicy::capability( 'review' ) ) ) {
			wp_die( esc_html__( 'You cannot review record origins.', 'lps-content-model' ), '', array( 'response' => 403 ) );
		}
		$rows   = class_exists( PublicationRecords::class ) ? PublicationRecords::origin_reconciliation() : array();
		$labels = array(
			'ambiguous'         => __( 'Ambiguous origin — review required', 'lps-content-model' ),
			'conflict'          => __( 'Native claim conflicts with import provenance', 'lps-content-model' ),
			'unreviewed-import' => __( 'Imported record without completed review', 'lps-content-model' ),
		);
		echo '<div class="wrap"><h1>' . esc_html__( 'Origin reconciliation', 'lps-content-model' ) . '</h1>';
		echo '<p>' . esc_html__( 'Records whose provenance needs an editorial decision. Reconciling a record here is a deliberate review action; nothing on this page grants trust automatically.', 'lps-content-model' ) . '</p>';
		echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Queue', 'lps-content-model' ) . '</th><th>' . esc_html__( 'Type', 'lps-content-model' ) . '</th><th>' . esc_html__( 'Record', 'lps-content-model' ) . '</th><th>' . esc_html__( 'Resolved origin', 'lps-content-model' ) . '</th></tr></thead><tbody>';
		foreach ( $rows as $row ) {
			$label = $labels[ $row['action'] ] ?? $row['action'];
			echo '<tr><td><code>' . esc_html( $row['action'] ) . '</code> ' . esc_html( $label ) . '</td><td>' . esc_html( $row['post_type'] ) . '</td><td>' . esc_html( $row['title'] ) . ' (#' . esc_html( (string) $row['post_id'] ) . ')</td><td><code>' . esc_html( $row['origin'] ) . '</code></td></tr>';
		}
		if ( array() === $rows ) {
			echo '<tr><td colspan="4">' . esc_html__( 'No records need origin reconciliation.', 'lps-content-model' ) . '</td></tr>';
		}
		echo '</tbody></table></div>';
	}
}
