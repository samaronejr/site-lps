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
require_once __DIR__ . '/class-teachingresources.php';
require_once __DIR__ . '/class-teachingcopy.php';
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
			'/teaching/resources',
			array(
				'methods'             => 'POST',
				'callback'            => array( self::class, 'create_resource' ),
				'permission_callback' => array( self::class, 'may_create_resource' ),
				'args'                => array(
					'title'       => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'slug'        => array( 'type' => 'string' ),
					'offering_id' => array(
						'required'          => true,
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					),
					'unit_id'     => array( 'type' => 'integer' ),
					'version_id'  => array( 'type' => 'string' ),
					'meta'        => array( 'type' => 'object' ),
				),
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/teaching/resource-versions',
			array(
				'methods'             => 'POST',
				'callback'            => array( self::class, 'upload_version' ),
				'permission_callback' => array( self::class, 'may_upload_version' ),
				'args'                => array(
					'offering_id' => array(
						'required'          => true,
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					),
					'scan'        => array( 'type' => 'boolean' ),
				),
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/teaching/resource-versions/(?P<version_id>lpsver:[0-9a-f]{64})/scan',
			array(
				'methods'             => 'POST',
				'callback'            => array( self::class, 'scan_version' ),
				'permission_callback' => array( self::class, 'may_upload_version' ),
				'args'                => array(
					'offering_id' => array(
						'required'          => true,
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					),
				),
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/teaching/resources/(?P<id>\d+)/version',
			array(
				'methods'             => 'POST',
				'callback'            => array( self::class, 'select_version' ),
				'permission_callback' => array( self::class, 'may_edit_resource' ),
				'args'                => array(
					'id'         => array(
						'required'          => true,
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					),
					'version_id' => array(
						'required' => true,
						'type'     => 'string',
					),
				),
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/teaching/resources/(?P<id>\d+)/release',
			array(
				'methods'             => 'POST',
				'callback'            => array( self::class, 'release_resource' ),
				'permission_callback' => array( self::class, 'may_publish_resource' ),
				'args'                => array(
					'id'         => array(
						'required'          => true,
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					),
					'state'      => array( 'type' => 'string' ),
					'release_at' => array( 'type' => 'string' ),
				),
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/teaching/resources/(?P<id>\d+)/withdraw',
			array(
				'methods'             => 'POST',
				'callback'            => array( self::class, 'withdraw_resource' ),
				'permission_callback' => array( self::class, 'may_publish_resource' ),
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
			'/teaching/offerings/(?P<id>\d+)/copy-forward',
			array(
				'methods'             => 'POST',
				'callback'            => array( self::class, 'copy_forward' ),
				'permission_callback' => array( self::class, 'may_copy_forward' ),
				'args'                => array(
					'id'                   => array(
						'required'          => true,
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					),
					'operation_id'         => array(
						'required' => true,
						'type'     => 'string',
					),
					'new_term_id'          => array(
						'required'          => true,
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					),
					'new_section'          => array(
						'required' => true,
						'type'     => 'string',
					),
					'team'                 => array(
						'required' => true,
						'type'     => 'array',
					),
					'team_reviewed'        => array( 'type' => 'boolean' ),
					'selected_version_ids' => array( 'type' => 'array' ),
					'title'                => array( 'type' => 'string' ),
					'excerpt'              => array( 'type' => 'string' ),
					'content'              => array( 'type' => 'string' ),
				),
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/teaching/offerings/(?P<id>\d+)/corrections',
			array(
				'methods'             => 'POST',
				'callback'            => array( self::class, 'propagate_correction' ),
				'permission_callback' => array( self::class, 'may_propagate_correction' ),
				'args'                => array(
					'id'                    => array(
						'required'          => true,
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					),
					'operation_id'          => array(
						'required' => true,
						'type'     => 'string',
					),
					'fields'                => array(
						'required' => true,
						'type'     => 'object',
					),
					'affected_offering_ids' => array(
						'required' => true,
						'type'     => 'array',
					),
				),
			)
		);
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
	 * Creates one teaching resource through the domain service.
	 *
	 * @param WP_REST_Request $request Current request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function create_resource( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$input = $request->get_params();
		unset( $input['rest_route'] );
		$result = TeachingResources::create_resource( $input );
		if ( $result instanceof WP_Error ) {
			return $result;
		}
		$response = rest_ensure_response( $result );
		$response->set_status( 201 );
		return $response;
	}

	/**
	 * Stores an uploaded file in quarantine and mints its immutable version.
	 *
	 * The upload travels as multipart `file`; `scan=false` leaves the version
	 * quarantined so the scan boundary can be exercised separately.
	 *
	 * @param WP_REST_Request $request Current request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function upload_version( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$files = $request->get_file_params();
		$file  = is_array( $files['file'] ?? null ) ? $files['file'] : array();
		$name  = Policy::scalar_string( $file['name'] ?? '' );
		$tmp   = Policy::scalar_string( $file['tmp_name'] ?? '' );
		if ( '' === $name || '' === $tmp ) {
			return self::error( 'lps_teaching_upload_required', 'A multipart file upload is required.', 'file', 400 );
		}
		$scan_param = $request->get_param( 'scan' );
		$scan       = null === $scan_param ? true : wp_validate_boolean( $scan_param );
		$result     = TeachingResources::upload_version( $name, $tmp, TeachingResources::storage_config(), $scan );
		if ( null !== $result['error'] || null === $result['version'] ) {
			return self::error( (string) $result['error'], 'The upload was denied by the storage boundary.', 'file', 400 );
		}
		$response = rest_ensure_response( $result['version'] );
		$response->set_status( 201 );
		return $response;
	}

	/**
	 * Drives one minted version through the configured scanner.
	 *
	 * @param WP_REST_Request $request Current request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function scan_version( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$result = TeachingResources::scan_version(
			Policy::scalar_string( $request->get_param( 'version_id' ) ),
			TeachingResources::storage_config()
		);
		if ( null === $result['version'] ) {
			return self::error( (string) $result['error'], 'The version does not exist.', 'version_id', 404 );
		}
		return rest_ensure_response( $result['version'] );
	}

	/**
	 * Replaces the version a resource serves, explicitly and audibly.
	 *
	 * @param WP_REST_Request $request Current request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function select_version( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$result = TeachingResources::select_version(
			Policy::sanitize_integer( $request->get_param( 'id' ) ),
			Policy::scalar_string( $request->get_param( 'version_id' ) )
		);
		if ( $result instanceof WP_Error ) {
			return $result;
		}
		return rest_ensure_response( $result );
	}

	/**
	 * Applies a scoped release or scheduling decision.
	 *
	 * @param WP_REST_Request $request Current request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function release_resource( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$result = TeachingResources::release_resource(
			Policy::sanitize_integer( $request->get_param( 'id' ) ),
			Policy::scalar_string( $request->get_param( 'state' ) ),
			Policy::scalar_string( $request->get_param( 'release_at' ) ),
			TeachingResources::storage_config()
		);
		if ( $result instanceof WP_Error ) {
			return $result;
		}
		return rest_ensure_response( $result );
	}

	/**
	 * Applies a scoped withdrawal decision.
	 *
	 * @param WP_REST_Request $request Current request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function withdraw_resource( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$result = TeachingResources::withdraw_resource( Policy::sanitize_integer( $request->get_param( 'id' ) ) );
		if ( $result instanceof WP_Error ) {
			return $result;
		}
		return rest_ensure_response( $result );
	}

	/**
	 * Copies one offering forward into a new term/section as a draft.
	 *
	 * The route's `id` is the source offering; the operation identifier makes
	 * retries idempotent and the response carries the complete manifest.
	 *
	 * @param WP_REST_Request $request Current request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function copy_forward( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$input                       = $request->get_params();
		$input['source_offering_id'] = Policy::sanitize_integer( $request->get_param( 'id' ) );
		unset( $input['rest_route'] );
		$result = TeachingCopy::copy_forward( $input );
		if ( $result instanceof WP_Error ) {
			return $result;
		}
		$response = rest_ensure_response( $result );
		$response->set_status( 201 );
		return $response;
	}

	/**
	 * Propagates declared field corrections to explicitly selected offerings.
	 *
	 * @param WP_REST_Request $request Current request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function propagate_correction( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$input                       = $request->get_params();
		$input['source_offering_id'] = Policy::sanitize_integer( $request->get_param( 'id' ) );
		unset( $input['rest_route'] );
		$result = TeachingCopy::propagate_correction( $input );
		if ( $result instanceof WP_Error ) {
			return $result;
		}
		return rest_ensure_response( $result );
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
	 * Returns whether the account may create a resource on the declared offering.
	 *
	 * Editors create through their collection assignment; scoped roles create
	 * only when the declared parent offering is covered by an active persisted
	 * grant — the offering ID is validated server-side, never trusted alone.
	 *
	 * @param WP_REST_Request $request Current request.
	 */
	public static function may_create_resource( WP_REST_Request $request ): bool {
		if ( Roles::current_user_can_action( 'create', 'teaching' ) ) {
			return true;
		}
		$offering_id = Policy::sanitize_integer( $request->get_param( 'offering_id' ) );
		if ( 0 >= $offering_id ) {
			return false;
		}
		$parent = get_post( $offering_id );
		if ( ! $parent instanceof \WP_Post || 'lps_offering' !== $parent->post_type ) {
			return false;
		}
		return Roles::current_user_can_scoped_action( 'create', 'lps_resource', $offering_id );
	}

	/**
	 * Returns whether the account may mint or rescan versions for an offering.
	 *
	 * Versions are shared immutable assets: the scoped check anchors them to a
	 * declared offering covered by an active grant, exactly like a create.
	 *
	 * @param WP_REST_Request $request Current request.
	 */
	public static function may_upload_version( WP_REST_Request $request ): bool {
		return self::may_create_resource( $request );
	}

	/**
	 * Returns whether the account may replace the addressed resource's version.
	 *
	 * @param WP_REST_Request $request Current request.
	 */
	public static function may_edit_resource( WP_REST_Request $request ): bool {
		$post_id = Policy::sanitize_integer( $request->get_param( 'id' ) );
		$post    = 0 < $post_id ? get_post( $post_id ) : null;
		if ( ! $post instanceof \WP_Post || 'lps_resource' !== $post->post_type ) {
			return false;
		}
		if ( Roles::current_user_can_action( 'edit', 'teaching' ) ) {
			return true;
		}
		$offering_id = Roles::persisted_offering_id( $post );
		return 0 < $offering_id && Roles::current_user_can_scoped_action( 'edit', 'lps_resource', $offering_id );
	}

	/**
	 * Returns whether the account may release or withdraw the addressed resource.
	 *
	 * Release and withdrawal change public file delivery, so they follow the
	 * `publish` action: editors with publish rights on the teaching collection,
	 * or a scoped role whose persisted grant covers the resource's offering.
	 *
	 * @param WP_REST_Request $request Current request.
	 */
	public static function may_publish_resource( WP_REST_Request $request ): bool {
		$post_id = Policy::sanitize_integer( $request->get_param( 'id' ) );
		$post    = 0 < $post_id ? get_post( $post_id ) : null;
		if ( ! $post instanceof \WP_Post || 'lps_resource' !== $post->post_type ) {
			return false;
		}
		if ( Roles::current_user_can_action( 'publish', 'teaching' ) ) {
			return true;
		}
		$offering_id = Roles::persisted_offering_id( $post );
		return 0 < $offering_id && Roles::current_user_can_scoped_action( 'publish', 'lps_resource', $offering_id );
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
	 * Returns whether the account may copy the addressed offering forward.
	 *
	 * Editors act through their teaching collection assignment; a scoped
	 * professor needs an active grant covering the source offering — the
	 * `copy-forward` action is scoped to `lps_offering` only, so delegates
	 * and out-of-scope professors are denied before any mutation.
	 *
	 * @param WP_REST_Request $request Current request.
	 */
	public static function may_copy_forward( WP_REST_Request $request ): bool {
		$post_id = Policy::sanitize_integer( $request->get_param( 'id' ) );
		$post    = 0 < $post_id ? get_post( $post_id ) : null;
		if ( ! $post instanceof \WP_Post || 'lps_offering' !== $post->post_type ) {
			return false;
		}
		if ( Roles::current_user_can_action( 'create', 'teaching' ) ) {
			return true;
		}
		return Roles::current_user_can_scoped_action( 'copy-forward', 'lps_offering', $post_id );
	}

	/**
	 * Returns whether the account may propagate the declared correction.
	 *
	 * Editors act through their teaching collection assignment. A scoped role
	 * must hold an active grant on every explicitly affected offering and may
	 * only propagate fields inside its own field allowlist — the service
	 * re-verifies every write against the same boundary.
	 *
	 * @param WP_REST_Request $request Current request.
	 */
	public static function may_propagate_correction( WP_REST_Request $request ): bool {
		$post_id = Policy::sanitize_integer( $request->get_param( 'id' ) );
		$post    = 0 < $post_id ? get_post( $post_id ) : null;
		if ( ! $post instanceof \WP_Post || 'lps_offering' !== $post->post_type ) {
			return false;
		}
		if ( Roles::current_user_can_action( 'edit', 'teaching' ) ) {
			return true;
		}
		$role = Roles::policy_role();
		if ( ! TeachingPolicy::is_scoped_role( $role ) ) {
			return false;
		}
		$fields = $request->get_param( 'fields' );
		if ( ! is_array( $fields ) || array() === $fields ) {
			return false;
		}
		foreach ( array_keys( $fields ) as $field ) {
			if ( ! is_string( $field ) || ! TeachingPolicy::field_write_allowed( $role, 'lps_offering', $field ) ) {
				return false;
			}
		}
		$affected = $request->get_param( 'affected_offering_ids' );
		if ( ! is_array( $affected ) || array() === $affected ) {
			return false;
		}
		foreach ( $affected as $candidate ) {
			$target_id = Policy::sanitize_integer( $candidate );
			$target    = 0 < $target_id ? get_post( $target_id ) : null;
			if ( ! $target instanceof \WP_Post || 'lps_offering' !== $target->post_type ) {
				return false;
			}
			if ( ! Roles::current_user_can_scoped_action( 'edit', 'lps_offering', $target_id ) ) {
				return false;
			}
		}
		return true;
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
