<?php
/**
 * REST boundary for the teaching persistence service.
 *
 * @package LPS\ContentModel
 */

declare(strict_types=1);

namespace LPS\ContentModel;

use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

require_once __DIR__ . '/class-teachingrecords.php';
require_once __DIR__ . '/class-teachingpolicy.php';
require_once __DIR__ . '/class-roles.php';
require_once __DIR__ . '/class-securitypolicy.php';

/**
 * Exposes the canonical teaching write/read boundary over REST.
 *
 * Creates, publishes, translation reviews, history reads, and the
 * reconciliation report all delegate to `TeachingRecords`; this adapter only
 * maps HTTP parameters and enforces the role/scope/MFA boundary. Permission
 * checks never trust request-supplied scope: unit creates resolve their
 * offering from the declared parent and evaluate it against persisted grants.
 */
final class TeachingRest {
	public const NAMESPACE = 'lps/v1';

	/** Registers the teaching routes. */
	public static function boot(): void {
		add_action( 'rest_api_init', array( self::class, 'register_routes' ) );
	}

	/** Declares the teaching REST routes. */
	public static function register_routes(): void {
		foreach ( array( 'courses', 'terms', 'offerings', 'units' ) as $collection ) {
			register_rest_route(
				self::NAMESPACE,
				'/teaching/' . $collection,
				array(
					'methods'             => 'POST',
					'callback'            => array( self::class, 'create_record' ),
					'permission_callback' => array( self::class, 'may_create' ),
					'args'                => array(
						'title'          => array(
							'required'          => true,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						),
						'slug'           => array( 'type' => 'string' ),
						'locale'         => array( 'type' => 'string' ),
						'status'         => array( 'type' => 'string' ),
						'translation_of' => array( 'type' => 'integer' ),
						'meta'           => array( 'type' => 'object' ),
					),
				)
			);
		}
		register_rest_route(
			self::NAMESPACE,
			'/teaching/records/(?P<id>\d+)/publish',
			array(
				'methods'             => 'POST',
				'callback'            => array( self::class, 'publish_record' ),
				'permission_callback' => array( self::class, 'may_publish' ),
				'args'                => array(
					'id' => array(
						'required'          => true,
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					),
				),
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/teaching/records/(?P<id>\d+)/review-translation',
			array(
				'methods'             => 'POST',
				'callback'            => array( self::class, 'review_translation' ),
				'permission_callback' => array( self::class, 'may_review' ),
				'args'                => array(
					'id' => array(
						'required'          => true,
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					),
				),
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/teaching/people/(?P<id>\d+)/history',
			array(
				'methods'             => 'GET',
				'callback'            => array( self::class, 'person_history' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'id'      => array(
						'required'          => true,
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					),
					'locale'  => array( 'type' => 'string' ),
					'context' => array( 'type' => 'string' ),
				),
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/teaching/reconciliation',
			array(
				'methods'             => 'GET',
				'callback'            => array( self::class, 'reconciliation' ),
				'permission_callback' => array( self::class, 'may_review_reconciliation' ),
			)
		);
	}

	/**
	 * Creates one teaching record through the domain service.
	 *
	 * @param WP_REST_Request $request Current request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function create_record( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$collection = trim( (string) $request->get_route(), '/' );
		$collection = (string) substr( $collection, strrpos( $collection, '/' ) + 1 );
		$method     = match ( $collection ) {
			'courses' => 'create_course',
			'terms' => 'create_term',
			'offerings' => 'create_offering',
			'units' => 'create_unit',
			default => '',
		};
		if ( '' === $method ) {
			return self::error( 'lps_teaching_route_unknown', 'The teaching collection is unknown.', 'route', 404 );
		}
		$input = $request->get_params();
		unset( $input['rest_route'] );
		$result = TeachingRecords::{ $method }( $input );
		if ( $result instanceof WP_Error ) {
			return $result;
		}
		$response = rest_ensure_response( $result );
		$response->set_status( 201 );
		return $response;
	}

	/**
	 * Publishes one stored record through the full server-side gate.
	 *
	 * @param WP_REST_Request $request Current request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function publish_record( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$result = TeachingRecords::publish_record( Policy::sanitize_integer( $request->get_param( 'id' ) ) );
		if ( $result instanceof WP_Error ) {
			return $result;
		}
		return rest_ensure_response( $result );
	}

	/**
	 * Records a deliberate English-variant review.
	 *
	 * @param WP_REST_Request $request Current request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function review_translation( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$result = TeachingRecords::review_translation(
			Policy::sanitize_integer( $request->get_param( 'id' ) ),
			get_current_user_id()
		);
		if ( $result instanceof WP_Error ) {
			return $result;
		}
		return rest_ensure_response( $result );
	}

	/**
	 * Returns the derived teaching history of one person.
	 *
	 * The `edit` context is the second supported metadata context: it exposes
	 * non-public rows and unpublished offerings, so it requires an account that
	 * can edit the person record. The public `view` context only ever lists
	 * published, publicly visible rows.
	 *
	 * @param WP_REST_Request $request Current request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function person_history( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$context   = 'edit' === Policy::scalar_string( $request->get_param( 'context' ) ) ? 'edit' : 'view';
		$person_id = Policy::sanitize_integer( $request->get_param( 'id' ) );
		if ( 'edit' === $context && ! current_user_can( 'edit_post', $person_id ) ) {
			return self::error( 'lps_teaching_history_forbidden', 'The editorial history context requires edit access to the person record.', 'context', 403 );
		}
		$locale = Policy::scalar_string( $request->get_param( 'locale' ) );
		$locale = isset( TranslationPolicy::locales()[ $locale ] ) ? $locale : TranslationPolicy::SOURCE_LOCALE;
		$result = TeachingRecords::person_history( $person_id, $locale, $context );
		if ( $result instanceof WP_Error ) {
			return $result;
		}
		return rest_ensure_response( $result );
	}

	/**
	 * Returns the report-driven reconciliation receipt.
	 *
	 * @return WP_REST_Response
	 */
	public static function reconciliation(): WP_REST_Response {
		return rest_ensure_response( TeachingRecords::reconciliation_report() );
	}

	/**
	 * Returns whether the account may create on the requested collection.
	 *
	 * Editors create through their collection assignment; scoped roles may only
	 * create units, and only when the declared parent offering is covered by an
	 * active persisted grant — the offering ID is validated server-side, never
	 * trusted as a scope source on its own.
	 *
	 * @param WP_REST_Request $request Current request.
	 */
	public static function may_create( WP_REST_Request $request ): bool {
		$route      = trim( (string) $request->get_route(), '/' );
		$collection = (string) substr( $route, strrpos( $route, '/' ) + 1 );
		$post_type  = 'lps_' . rtrim( $collection, 's' );
		if ( Roles::current_user_can_action( 'create', 'teaching' ) ) {
			return true;
		}
		if ( 'units' !== $collection ) {
			return false;
		}
		$offering_id = Policy::sanitize_integer( $request->get_param( 'offering_id' ) );
		if ( 0 >= $offering_id ) {
			return false;
		}
		$parent = get_post( $offering_id );
		if ( ! $parent instanceof \WP_Post || 'lps_offering' !== $parent->post_type ) {
			return false;
		}
		return Roles::current_user_can_scoped_action( 'create', $post_type, $offering_id );
	}

	/**
	 * Returns whether the account may publish the addressed record.
	 *
	 * @param WP_REST_Request $request Current request.
	 */
	public static function may_publish( WP_REST_Request $request ): bool {
		$post_id = Policy::sanitize_integer( $request->get_param( 'id' ) );
		$post    = 0 < $post_id ? get_post( $post_id ) : null;
		if ( ! $post instanceof \WP_Post ) {
			return false;
		}
		if ( Roles::current_user_can_action( 'publish', 'teaching' ) ) {
			return true;
		}
		$offering_id = Roles::persisted_offering_id( $post );
		return 0 < $offering_id && Roles::current_user_can_scoped_action( 'publish', $post->post_type, $offering_id );
	}

	/**
	 * Returns whether the account may record a translation review.
	 *
	 * @param WP_REST_Request $request Current request.
	 */
	public static function may_review( WP_REST_Request $request ): bool {
		$post_id = Policy::sanitize_integer( $request->get_param( 'id' ) );
		return 0 < $post_id && is_user_logged_in() && current_user_can( 'edit_post', $post_id );
	}

	/**
	 * Returns whether the account may read the reconciliation report.
	 */
	public static function may_review_reconciliation(): bool {
		return Roles::current_user_can_action( 'review', 'teaching' ) || Roles::current_user_can_action( 'audit' );
	}

	/**
	 * Builds one typed REST error.
	 *
	 * @param string $code    Stable machine error code.
	 * @param string $message Human-readable message.
	 * @param string $field   Machine field name.
	 * @param int    $status  HTTP status.
	 */
	private static function error( string $code, string $message, string $field, int $status ): WP_Error {
		return new WP_Error(
			$code,
			$message,
			array(
				'status' => $status,
				'field'  => $field,
			)
		);
	}
}
