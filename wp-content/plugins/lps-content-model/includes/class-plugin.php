<?php
/**
 * WordPress integration for the portable content model.
 *
 * @package LPS\ContentModel
 */

declare(strict_types=1);

namespace LPS\ContentModel;

use WP_Error;
use WP_Post;
use WP_REST_Request;

require_once __DIR__ . '/class-publicationpolicy.php';

/** WordPress adapter for the portable content contracts. */
final class Plugin {
	private const SCHEMA_OPTION = 'lps_content_model_schema_version';
	private const NONCE_ACTION  = 'lps_content_model_save';
	private const NONCE_NAME    = '_lps_content_model_nonce';

	/**
	 * Pending admin validation errors keyed by record and field.
	 *
	 * @var array<int, array<string, string>>
	 */
	private static array $pending_errors = array();

	/** Registers WordPress hooks. */
	public static function boot(): void {
		Taxonomies::boot();
		Translations::boot();
		Roles::boot();
		MFA::boot();
		Audit::boot();
		Reports::boot();
		Media::boot();
		SearchIndex::boot();
		TeachingRecords::boot();
		TeachingRest::boot();
		TeachingResources::boot();
		add_action( 'init', array( self::class, 'register' ), 5 );
		add_action( 'init', array( self::class, 'migrate' ), 10 );
		add_action( 'add_meta_boxes', array( self::class, 'add_meta_boxes' ) );
		add_action( 'save_post', array( self::class, 'persist_pending_scoped_parent' ), 9, 2 );
		add_action( 'save_post', array( self::class, 'save_admin_fields' ), 10, 2 );
		add_action( 'wp_after_insert_post', array( self::class, 'complete_record' ), 10, 4 );
		add_action( 'deleted_post', array( Relationships::class, 'cleanup_deleted_post' ) );
		add_filter( 'wp_insert_post_data', array( self::class, 'validate_direct_insert' ), 10, 4 );
		add_filter( 'update_post_metadata', array( Relationships::class, 'intercept_metadata_update' ), 9, 5 );
		add_filter( 'update_post_metadata', array( self::class, 'protect_identity_meta' ), 10, 5 );
		add_filter( 'update_post_metadata', array( self::class, 'protect_role_meta' ), 11, 5 );
		add_filter( 'pre_delete_post', array( self::class, 'archive_instead_of_delete' ), 10, 3 );
		add_filter( 'rest_pre_dispatch', array( self::class, 'validate_rest_delete' ), 10, 3 );
		add_action( 'admin_menu', array( self::class, 'settings_page' ) );
		add_action( 'admin_notices', array( self::class, 'admin_notices' ) );
	}

	/** Runs activation-safe migrations and registration. */
	public static function activate(): void {
		self::register();
		self::migrate();
		Roles::install();
		Audit::install();
		flush_rewrite_rules( false );
	}

	/** Registers types, statuses, metadata, REST hooks, and settings. */
	public static function register(): void {
		foreach ( Contracts::post_types() as $post_type => $definition ) {
			if ( 'page' === $post_type ) {
				add_post_type_support( 'page', 'custom-fields' );
				add_post_type_support( 'page', 'thumbnail' );
				continue;
			}
			if ( in_array( $post_type, TeachingContracts::POST_TYPES, true ) ) {
				continue;
			}
			$args = $definition;
			unset( $args['builtin'] );
			$args['menu_icon']       = 'dashicons-media-document';
			$args['has_archive']     = (bool) $args['public'];
			$args['rewrite']         = (bool) $args['public'];
			$args['capability_type'] = array( $post_type, $post_type . 's' );
			$args['map_meta_cap']    = true;
			/**
			 * Validated WordPress post type key.
			 *
			 * @var non-empty-lowercase-string $post_type
			 */
			register_post_type( $post_type, $args );
		}

		TeachingContracts::register();
		Taxonomies::register();
		Media::register();

		register_post_status(
			'lps_archived',
			array(
				'label'                  => __( 'Archived', 'lps-content-model' ),
				'public'                 => false,
				'internal'               => true,
				'show_in_admin_all_list' => true,
				// translators: %s is the number of archived records.
				'label_count'            => _n_noop( 'Archived <span class="count">(%s)</span>', 'Archived <span class="count">(%s)</span>', 'lps-content-model' ),
			)
		);

		foreach ( Contracts::meta_fields() as $post_type => $fields ) {
			foreach ( $fields as $key => $definition ) {
				$registration = $definition;
				if ( 'array' === $registration['type'] ) {
					$registration['show_in_rest'] = array(
						'schema' => array(
							'type'  => 'array',
							'items' => array( 'type' => 'string' ),
						),
					);
				}
				register_post_meta( $post_type, $key, $registration );
			}
			add_filter( 'rest_pre_insert_' . $post_type, array( self::class, 'validate_rest_insert' ), 10, 2 );
			add_filter( 'rest_prepare_' . $post_type, array( self::class, 'remove_private_rest_fields' ), 20, 3 );
		}

		register_setting(
			'lps_content_model',
			Contracts::OPTION_NAME,
			array(
				'type'              => 'object',
				'description'       => 'Typed institutional site settings.',
				'sanitize_callback' => array( self::class, 'sanitize_site_settings' ),
				'default'           => array(),
				'show_in_rest'      => array( 'schema' => Contracts::site_settings_schema() ),
			)
		);
	}

	/** Applies idempotent versioned schema migrations. */
	public static function migrate(): void {
		$current = get_option( self::SCHEMA_OPTION, '0.0.0' );
		if ( is_string( $current ) && version_compare( $current, Contracts::VERSION, '<' ) ) {
			Roles::install();
			Audit::install();
			if ( false === get_option( Contracts::OPTION_NAME, false ) ) {
				add_option( Contracts::OPTION_NAME, array(), '', false );
			}
			update_option( self::SCHEMA_OPTION, Contracts::VERSION, false );
		}
		Migrations::apply();
		TeachingMigrations::apply();
		SearchIndex::install();
	}

	/**
	 * Sanitizes the one site-settings object against its property schema.
	 *
	 * @param mixed $value Boundary input.
	 * @return array<string, int|string>
	 */
	public static function sanitize_site_settings( mixed $value ): array {
		if ( ! is_array( $value ) ) {
			return array();
		}
		$result     = array();
		$properties = Contracts::site_settings_schema()['properties'];
		foreach ( $properties as $key => $schema ) {
			if ( ! array_key_exists( $key, $value ) ) {
				continue;
			}
			if ( 'integer' === $schema['type'] ) {
				$result[ $key ] = Policy::sanitize_integer( $value[ $key ] );
			} elseif ( 'email' === ( $schema['format'] ?? '' ) ) {
				$result[ $key ] = Policy::sanitize_email( $value[ $key ] );
			} elseif ( 'uri' === ( $schema['format'] ?? '' ) ) {
				$result[ $key ] = Policy::sanitize_url( $value[ $key ] );
			} else {
				$result[ $key ] = Policy::sanitize_text( $value[ $key ] );
			}
		}
		return $result;
	}

	/**
	 * Denies invalid direct publish and immutable-field mutations.
	 *
	 * @param array<string, mixed> $data        Prepared database fields.
	 * @param array<string, mixed> $postarr     Parsed record input.
	 * @param array<string, mixed> $unsanitized Raw record input.
	 * @param bool                 $update      Whether this is an update.
	 * @return array<string, mixed>
	 */
	public static function validate_direct_insert( array $data, array $postarr, array $unsanitized, bool $update ): array {
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return $data;
		}
		$post_type = Policy::scalar_string( $data['post_type'] ?? 'post' );
		if ( ! isset( Contracts::post_types()[ $post_type ] ) ) {
			return $data;
		}
		$raw_post_id = $postarr['ID'] ?? 0;
		$post_id     = is_numeric( $raw_post_id ) ? (int) $raw_post_id : 0;
		$role        = Roles::policy_role();
		if ( TeachingPolicy::is_scoped_role( $role ) ) {
			$stored_post = 0 < $post_id ? get_post( $post_id ) : null;
			if ( $stored_post instanceof WP_Post ) {
				$scoped_error = Roles::scoped_post_error( get_current_user_id(), 'edit', $stored_post );
			} else {
				$grants       = Roles::teaching_grants( get_current_user_id() );
				$scoped_error = 'lps_offering' === $post_type ? 'lps_teaching_scope_required' : TeachingPolicy::scope_error( $role, 'create', $post_type, 0, $grants, gmdate( 'c' ) );
			}
			if ( null !== $scoped_error ) {
				self::$pending_errors[ $post_id ]['_lps_scope'] = $scoped_error;
				$data['post_status']                            = $update ? Policy::scalar_string( get_post_status( $post_id ) ) : 'draft';
				return $data;
			}
		}
		$incoming = $unsanitized['meta_input'] ?? array();
		$incoming = is_array( $incoming ) ? $incoming : array();
		/**
		 * Typed direct-insert metadata.
		 *
		 * @var array<string, mixed> $incoming
		 */
		$meta          = self::merged_meta( $post_type, $post_id, $incoming );
		$meta          = Translations::merge_shared_meta( $post_type, $post_id, $meta );
		$state         = Policy::scalar_string( $meta['_lps_state'] ?? ( 'publish' === ( $data['post_status'] ?? '' ) ? 'published' : 'draft' ) );
		$reverse_error = RelationshipPolicy::manual_reverse_error( array( 'meta' => $incoming ) );
		if ( null !== $reverse_error ) {
			self::$pending_errors[ $post_id ]['_lps_relationships'] = $reverse_error;
			$data['post_status']                                    = $update ? Policy::scalar_string( get_post_status( $post_id ) ) : 'draft';
			return $data;
		}
		if ( 'lps_publication' === $post_type && 0 < $post_id && array_key_exists( '_lps_doi', $incoming ) ) {
			$doi_error = Relationships::doi_error( $post_id, $incoming['_lps_doi'] );
			if ( $doi_error instanceof WP_Error ) {
				self::$pending_errors[ $post_id ]['_lps_doi'] = (string) $doi_error->get_error_code();
				$data['post_status']                          = $update ? Policy::scalar_string( get_post_status( $post_id ) ) : 'draft';
				return $data;
			}
		}
		if ( $update ) {
			$stored_state = Policy::scalar_string( get_post_meta( $post_id, '_lps_state', true ) );
			$stored_state = '' === $stored_state ? 'draft' : $stored_state;
			if ( ! Policy::valid_transition( $stored_state, $state ) ) {
				self::$pending_errors[ $post_id ]['_lps_state'] = 'lps_invalid_state_transition';
				$data['post_status']                            = Policy::scalar_string( get_post_status( $post_id ) );
				return $data;
			}
		}
		if ( true === $update && 'publish' === get_post_status( $post_id ) ) {
			$stored_slug = Policy::scalar_string( get_post_meta( $post_id, '_lps_published_slug', true ) );
			if ( '' !== $stored_slug && isset( $unsanitized['post_name'] ) && ! hash_equals( $stored_slug, sanitize_title( Policy::scalar_string( $unsanitized['post_name'] ) ) ) ) {
				self::$pending_errors[ $post_id ]['post_name'] = 'lps_immutable_published_slug';
				$data['post_name']                             = $stored_slug;
			}
		}
		if ( 'publish' === ( $data['post_status'] ?? '' ) ) {
			$translation_error = Translations::validate_request( $post_type, $post_id, 'publish', $incoming );
			if ( $translation_error instanceof WP_Error ) {
				self::$pending_errors[ $post_id ]['translation'] = (string) $translation_error->get_error_code();
				$data['post_status']                             = $update ? Policy::scalar_string( get_post_status( $post_id ) ) : 'draft';
				return $data;
			}
			$origin_error = PublicationPolicy::origin_consistency_error( $meta );
			if ( null !== $origin_error ) {
				self::$pending_errors[ $post_id ]['_lps_origin'] = $origin_error;
				$data['post_status']                             = $update ? Policy::scalar_string( get_post_status( $post_id ) ) : 'draft';
				return $data;
			}
			$record                 = array_merge( $meta, $data );
			$relationship_source_id = Translations::source_id( $post_id ) ?? $post_id;
			$errors                 = array_merge( Policy::publish_errors( $post_type, $record ), Relationships::publish_errors( $post_type, $relationship_source_id ), TeachingContracts::publish_errors( $post_type, $record ) );
			if ( ! empty( $errors ) ) {
				self::$pending_errors[ $post_id ] = $errors;
				$data['post_status']              = $update ? Policy::scalar_string( get_post_status( $post_id ) ) : 'draft';
			}
		}
		return $data;
	}

	/**
	 * Validates a REST create or update before database mutation.
	 *
	 * @param \stdClass       $prepared Prepared post database fields.
	 * @param WP_REST_Request $request  Current REST request.
	 * @return \stdClass|WP_Error
	 */
	public static function validate_rest_insert( \stdClass $prepared, WP_REST_Request $request ): \stdClass|WP_Error {
		$post_type = isset( $prepared->post_type ) && is_string( $prepared->post_type ) ? $prepared->post_type : Policy::scalar_string( $request->get_param( 'type' ) );
		$post_id   = isset( $prepared->ID ) && is_numeric( $prepared->ID ) ? (int) $prepared->ID : Policy::sanitize_integer( $request->get_param( 'id' ) );
		$role      = Roles::policy_role();
		$action    = 0 < $post_id ? 'edit' : 'create';
		if ( TeachingPolicy::is_scoped_role( $role ) ) {
			$scoped_error = self::scoped_rest_error( $role, $action, $post_type, $post_id, $request );
			if ( null !== $scoped_error ) {
				return self::error( $scoped_error, 'This account lacks the required teaching scope for this action.', 'type' );
			}
			if ( 'create' === $action && isset( TeachingPolicy::RELATIONSHIP_SCOPES[ $post_type ] ) ) {
				// The declared parent was already validated against the persisted
				// grants; stash it so `save_post` can write the canonical row before
				// the REST meta update checks `edit_post_meta` on the new record.
				self::$pending_scoped_offering = Policy::sanitize_integer( $request->get_param( 'lps_parent_offering' ) );
			}
		} elseif ( '' !== $role && ! Roles::current_user_can_action( $action, Roles::collection_for_post_type( $post_type ) ) ) {
			return self::error( 'lps_collection_scope_forbidden', 'This account is not assigned to this collection or action.', 'type' );
		}
		$incoming = $request->get_param( 'meta' );
		$incoming = is_array( $incoming ) ? $incoming : array();
		/**
		 * Typed REST metadata.
		 *
		 * @var array<string, mixed> $incoming
		 */
		$locale = Policy::scalar_string( $incoming['_lps_locale'] ?? get_post_meta( $post_id, '_lps_locale', true ) );
		foreach ( array_keys( $incoming ) as $field ) {
			if ( ! SecurityPolicy::can_write_field( $role, (string) $field, $locale ) ) {
				return self::error( 'lps_translator_shared_field_forbidden', 'Translators may change only localized English editorial fields.', (string) $field, array(), 403 );
			}
			if ( ! TeachingPolicy::field_write_allowed( $role, $post_type, (string) $field ) ) {
				return self::error( 'lps_teaching_field_forbidden', 'This scoped account may not write this field.', (string) $field );
			}
		}
		$meta          = self::merged_meta( $post_type, $post_id, $incoming );
		$meta          = Translations::merge_shared_meta( $post_type, $post_id, $meta );
		$request_data  = $request->get_params();
		$reverse_error = RelationshipPolicy::manual_reverse_error( $request_data );
		if ( null !== $reverse_error ) {
			return self::error( $reverse_error, 'Reverse relationships are derived and cannot be submitted manually.', '_lps_relationships' );
		}
		foreach ( array_keys( $incoming ) as $meta_key ) {
			if ( in_array( $meta_key, RelationshipPolicy::legacy_relationship_meta_keys(), true ) ) {
				return self::error( 'lps_relationship_table_required', 'Relationships must be written through the canonical relationship boundary.', $meta_key );
			}
		}
		if ( 'lps_publication' === $post_type && array_key_exists( '_lps_doi', $incoming ) ) {
			$doi_error = Relationships::doi_error( $post_id, $incoming['_lps_doi'] );
			if ( $doi_error instanceof WP_Error ) {
				return $doi_error;
			}
		}

		if ( isset( $incoming['_lps_record_id'] ) ) {
			$candidate = Policy::sanitize_record_id( $incoming['_lps_record_id'] );
			if ( '' === $candidate ) {
				return self::error( 'lps_malformed_record_id', 'The internal record ID is malformed.', '_lps_record_id' );
			}
			$stored = Policy::scalar_string( get_post_meta( $post_id, '_lps_record_id', true ) );
			if ( ! Policy::can_change_identity( $stored, $candidate ) ) {
				return self::error( 'lps_immutable_record_id', 'The internal record ID is immutable.', '_lps_record_id' );
			}
		}

		if ( 0 < $post_id && isset( $incoming['_lps_state'] ) ) {
			$stored_state = Policy::scalar_string( get_post_meta( $post_id, '_lps_state', true ) );
			$stored_state = '' === $stored_state ? 'draft' : $stored_state;
			if ( ! Policy::valid_transition( $stored_state, Policy::scalar_string( $incoming['_lps_state'] ) ) ) {
				return self::error( 'lps_invalid_state_transition', 'The requested editorial-state transition is not allowed.', '_lps_state' );
			}
		}

		$requested_slug = $request->get_param( 'slug' );
		if ( 0 < $post_id && 'publish' === get_post_status( $post_id ) && is_string( $requested_slug ) ) {
			$stored_slug = Policy::scalar_string( get_post_meta( $post_id, '_lps_published_slug', true ) );
			if ( '' !== $stored_slug && ! hash_equals( $stored_slug, sanitize_title( $requested_slug ) ) ) {
				return self::error( 'lps_immutable_published_slug', 'A published slug is immutable.', 'slug' );
			}
		}

		if ( isset( $incoming['_lps_origin'] ) ) {
			$origin_error = 'en' === $locale
				? 'lps_origin_variant_forbidden'
				: PublicationPolicy::origin_write_error( Policy::scalar_string( get_post_meta( $post_id, '_lps_origin', true ) ), Policy::scalar_string( $incoming['_lps_origin'] ), $meta );
			if ( null !== $origin_error ) {
				return self::error( $origin_error, 'The record origin cannot be written this way.', '_lps_origin' );
			}
		}

		$translation_input = array_merge( $incoming, array( 'lang' => $request->get_param( 'lang' ) ) );
		$requested_status  = isset( $prepared->post_status ) && is_string( $prepared->post_status ) ? $prepared->post_status : Policy::scalar_string( $request->get_param( 'status' ) );
		$requested_status  = '' === $requested_status && 0 < $post_id ? Policy::scalar_string( get_post_status( $post_id ) ) : $requested_status;
		$translation_error = Translations::validate_request( $post_type, $post_id, $requested_status, $translation_input );
		if ( $translation_error instanceof WP_Error ) {
			return $translation_error;
		}

		if ( 'publish' === $requested_status ) {
			$origin_error = PublicationPolicy::origin_consistency_error( $meta );
			if ( null !== $origin_error ) {
				return self::error( $origin_error, 'The record origin conflicts with its stored provenance.', '_lps_origin' );
			}
			$stored_post = 0 < $post_id ? get_post( $post_id ) : null;
			$record      = array_merge(
				$meta,
				array(
					'post_title'   => isset( $prepared->post_title ) && is_string( $prepared->post_title ) ? $prepared->post_title : ( $stored_post instanceof WP_Post ? $stored_post->post_title : '' ),
					'post_excerpt' => isset( $prepared->post_excerpt ) && is_string( $prepared->post_excerpt ) ? $prepared->post_excerpt : ( $stored_post instanceof WP_Post ? $stored_post->post_excerpt : '' ),
					'post_content' => isset( $prepared->post_content ) && is_string( $prepared->post_content ) ? $prepared->post_content : ( $stored_post instanceof WP_Post ? $stored_post->post_content : '' ),
				)
			);

			$relationship_source_id = Translations::source_id( $post_id ) ?? $post_id;
			$errors                 = array_merge( Policy::publish_errors( $post_type, $record ), Relationships::publish_errors( $post_type, $relationship_source_id ), TeachingContracts::publish_errors( $post_type, $record ) );
			if ( ! empty( $errors ) ) {
				$code  = reset( $errors );
				$field = (string) array_key_first( $errors );
				return self::error( (string) $code, 'The record does not satisfy the server-side publish contract.', $field, $errors );
			}
		}
		return $prepared;
	}

	/**
	 * Prevents low-level immutable identity metadata updates.
	 *
	 * @param mixed  $check      Existing short-circuit value.
	 * @param int    $object_id  Record database ID.
	 * @param string $meta_key   Metadata key.
	 * @param mixed  $meta_value Candidate value.
	 * @return mixed
	 */
	public static function protect_identity_meta( mixed $check, int $object_id, string $meta_key, mixed $meta_value ): mixed {
		if ( '_lps_origin' === $meta_key ) {
			// An English variant never carries its own origin: provenance lives
			// on the Portuguese authority and is read from there.
			if ( 'en' === Translations::locale( $object_id ) ) {
				return false;
			}
			$stored = Policy::scalar_string( get_post_meta( $object_id, '_lps_origin', true ) );
			$record = array();
			foreach ( PublicationPolicy::decision_meta_keys() as $key ) {
				$record[ $key ] = get_post_meta( $object_id, $key, true );
			}
			$candidate = Policy::scalar_string( $meta_value );
			return null === PublicationPolicy::origin_write_error( $stored, $candidate, $record ) ? $check : false;
		}
		if ( 'en' === Translations::locale( $object_id ) && in_array( $meta_key, PublicationPolicy::english_forbidden_provenance_keys(), true ) ) {
			// A variant cannot mint import provenance or a Crossref identity.
			return false;
		}
		if ( '_lps_term_token' === $meta_key ) {
			$stored    = Policy::scalar_string( get_post_meta( $object_id, $meta_key, true ) );
			$candidate = Policy::scalar_string( $meta_value );
			return '' === $stored || hash_equals( $stored, $candidate ) ? $check : false;
		}
		if ( '_lps_record_id' !== $meta_key ) {
			return $check;
		}
		$stored    = Policy::scalar_string( get_post_meta( $object_id, $meta_key, true ) );
		$candidate = Policy::sanitize_record_id( $meta_value );
		return Policy::can_change_identity( $stored, $candidate ) ? $check : false;
	}

	/** Blocks translator and scoped-role writes outside their field and grant boundaries.
	 *
	 * @param mixed  $check      Existing short-circuit value.
	 * @param int    $object_id  Record ID.
	 * @param string $meta_key   Metadata key.
	 * @param mixed  $meta_value Candidate value.
	 * @return mixed
	 */
	public static function protect_role_meta( mixed $check, int $object_id, string $meta_key, mixed $meta_value ): mixed {
		unset( $meta_value );
		$role   = Roles::policy_role();
		$locale = Policy::scalar_string( get_post_meta( $object_id, '_lps_locale', true ) );
		if ( ! SecurityPolicy::can_write_field( $role, $meta_key, $locale ) ) {
			return false;
		}
		if ( ! TeachingPolicy::is_scoped_role( $role ) ) {
			return $check;
		}
		$post = get_post( $object_id );
		if ( ! $post instanceof WP_Post || ! TeachingPolicy::field_write_allowed( $role, $post->post_type, $meta_key ) ) {
			return false;
		}
		return null === Roles::scoped_post_error( get_current_user_id(), 'edit', $post ) ? $check : false;
	}

	/** Removes private ownership and audit-shaped values from anonymous REST responses.
	 *
	 * @param mixed           $response REST response.
	 * @param mixed           $post     Prepared post.
	 * @param WP_REST_Request $request  Request.
	 * @return mixed
	 */
	public static function remove_private_rest_fields( mixed $response, mixed $post, WP_REST_Request $request ): mixed {
		unset( $post, $request );
		if ( is_user_logged_in() || ! is_object( $response ) || ! method_exists( $response, 'get_data' ) || ! method_exists( $response, 'set_data' ) ) {
			return $response;
		}
		$data = $response->get_data();
		if ( ! is_array( $data ) ) {
			return $response;
		}
		foreach ( SecurityPolicy::private_fields() as $field ) {
			unset( $data[ $field ] );
			if ( isset( $data['meta'] ) && is_array( $data['meta'] ) ) {
				unset( $data['meta'][ $field ] );
			}
		}
				$response->set_data( $data );
		return $response;
	}

	/**
	 * Completes generated identity and timestamp fields after storage.
	 *
	 * @param int          $post_id     Record database ID.
	 * @param WP_Post      $post        Stored record.
	 * @param bool         $update      Whether this is an update.
	 * @param WP_Post|null $post_before Previous record, when any.
	 */
	public static function complete_record( int $post_id, WP_Post $post, bool $update, ?WP_Post $post_before ): void {
		unset( $post_before );
		if ( ! isset( Contracts::post_types()[ $post->post_type ] ) || wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}
		// Identity, provenance, timestamp and editorial-state fields are
		// system-owned: the scoped-role field guard correctly denies them to
		// direct writes, so the completer lifts that one guard for its own
		// writes and restores it immediately.
		remove_filter( 'update_post_metadata', array( self::class, 'protect_role_meta' ), 11 );
		$now = gmdate( 'c' );
		if ( '' === Policy::scalar_string( get_post_meta( $post_id, '_lps_record_id', true ) ) ) {
			$prefix = str_replace( 'lps_', '', $post->post_type );
			$prefix = str_replace( '_', '-', $prefix );
			update_post_meta( $post_id, '_lps_record_id', 'lps:' . $prefix . ':' . wp_generate_uuid4() );
		}
		if ( '' === Policy::scalar_string( get_post_meta( $post_id, '_lps_origin', true ) ) ) {
			$provenance = array();
			foreach ( PublicationPolicy::decision_meta_keys() as $key ) {
				$provenance[ $key ] = get_post_meta( $post_id, $key, true );
			}
			if ( PublicationPolicy::has_import_provenance( $provenance ) ) {
				// Real provenance always resolves to imported, even on legacy saves.
				update_post_meta( $post_id, '_lps_origin', PublicationPolicy::ORIGIN_IMPORTED );
			} elseif ( ! $update ) {
				// Only genuinely new records are marked native; a legacy record
				// saved without provenance stays ambiguous and is reported, never
				// automatically trusted.
				update_post_meta( $post_id, '_lps_origin', PublicationPolicy::ORIGIN_NATIVE );
			}
		}
		if ( ! $update || '' === Policy::scalar_string( get_post_meta( $post_id, '_lps_created_at', true ) ) ) {
			update_post_meta( $post_id, '_lps_created_at', $now );
		}
		update_post_meta( $post_id, '_lps_updated_at', $now );
		$state = 'publish' === $post->post_status ? 'published' : Policy::scalar_string( get_post_meta( $post_id, '_lps_state', true ) );
		update_post_meta( $post_id, '_lps_state', '' === $state ? 'draft' : $state );
		if ( 'publish' === $post->post_status && '' === Policy::scalar_string( get_post_meta( $post_id, '_lps_published_slug', true ) ) ) {
			update_post_meta( $post_id, '_lps_published_slug', $post->post_name );
		}
		if ( 'lps_term' === $post->post_type && '' === Policy::scalar_string( get_post_meta( $post_id, '_lps_term_token', true ) ) ) {
			$token = TeachingContracts::term_token(
				get_post_meta( $post_id, '_lps_calendar_key', true ),
				get_post_meta( $post_id, '_lps_term_code', true )
			);
			if ( '' !== $token ) {
				update_post_meta( $post_id, '_lps_term_token', $token );
			}
		}
		if ( 'lps_offering' === $post->post_type ) {
			$term_rows = Relationships::for_source( $post_id, 'offering_term' );
			$term_id   = Policy::sanitize_integer( $term_rows[0]['target_post_id'] ?? 0 );
			$status    = 0 < $term_id ? TeachingContracts::temporal_status(
				get_post_meta( $term_id, '_lps_starts_on', true ),
				get_post_meta( $term_id, '_lps_ends_on', true ),
				get_post_meta( $post_id, '_lps_cancelled', true ),
				TeachingContracts::today()
			) : '';
			if ( '' !== $status ) {
				update_post_meta( $post_id, '_lps_temporal_status', $status );
			}
		}
		add_filter( 'update_post_metadata', array( self::class, 'protect_role_meta' ), 11, 5 );
	}

	/**
	 * Returns typed REST deletion denials.
	 *
	 * @param mixed           $result  Existing dispatch result.
	 * @param mixed           $server  REST server instance.
	 * @param WP_REST_Request $request Current request.
	 * @return mixed
	 */
	public static function validate_rest_delete( mixed $result, mixed $server, WP_REST_Request $request ): mixed {
		unset( $server );
		if ( 'DELETE' !== $request->get_method() ) {
			return $result;
		}
		$route = $request->get_route();
		foreach ( Contracts::post_types() as $post_type => $definition ) {
			$pattern = '~^/wp/v2/' . preg_quote( (string) $definition['rest_base'], '~' ) . '/(?P<id>\d+)$~';
			if ( 1 !== preg_match( $pattern, $route, $matches ) ) {
				continue;
			}
			$post = get_post( (int) $matches['id'] );
			if ( ! $post instanceof WP_Post || $post_type !== $post->post_type ) {
				return $result;
			}
			$published = 'publish' === $post->post_status || '' !== Policy::scalar_string( get_post_meta( $post->ID, '_lps_published_slug', true ) );
			$code      = Policy::deletion_error( $published, self::is_referenced( $post ) );
			if ( null !== $code ) {
				return self::error( $code, 'This record must be archived; hard deletion is denied.', 'id' );
			}
		}
		return $result;
	}

	/**
	 * Converts prohibited hard deletes into archival.
	 *
	 * @param mixed   $check        Existing short-circuit value.
	 * @param WP_Post $post         Candidate record.
	 * @param bool    $force_delete Whether trash is bypassed.
	 * @return mixed
	 */
	public static function archive_instead_of_delete( mixed $check, WP_Post $post, bool $force_delete ): mixed {
		unset( $force_delete );
		if ( ! isset( Contracts::post_types()[ $post->post_type ] ) ) {
			return $check;
		}
		$published  = 'publish' === $post->post_status || '' !== Policy::scalar_string( get_post_meta( $post->ID, '_lps_published_slug', true ) );
		$referenced = self::is_referenced( $post );
		if ( null === Policy::deletion_error( $published, $referenced ) ) {
			return $check;
		}
		update_post_meta( $post->ID, '_lps_state', 'archived' );
		update_post_meta( $post->ID, '_lps_archived_at', gmdate( 'c' ) );
		wp_update_post(
			array(
				'ID'          => $post->ID,
				'post_status' => 'lps_archived',
			),
			true
		);
		return false;
	}

	/** Registers accessible structured-record panels. */
	public static function add_meta_boxes(): void {
		foreach ( array_keys( Contracts::post_types() ) as $post_type ) {
			add_meta_box(
				'lps-content-record',
				__( 'LPS structured record', 'lps-content-model' ),
				array( self::class, 'render_meta_box' ),
				$post_type,
				'normal',
				'high'
			);
		}
	}

	/**
	 * Renders an accessible structured-record panel.
	 *
	 * @param WP_Post $post Current record.
	 */
	public static function render_meta_box( WP_Post $post ): void {
		wp_nonce_field( self::NONCE_ACTION, self::NONCE_NAME );
		echo '<p>' . esc_html__( 'Fields marked required are enforced again by the server when publishing.', 'lps-content-model' ) . '</p>';
		echo '<p>' . esc_html__( 'Relationships and controlled classifications are managed through their canonical integrity boundary and are never duplicated in this panel.', 'lps-content-model' ) . '</p>';
		foreach ( Contracts::meta_fields()[ $post->post_type ] as $key => $definition ) {
			if ( in_array( $key, RelationshipPolicy::legacy_relationship_meta_keys(), true ) || '_lps_application_domains' === $key ) {
				continue;
			}
			$value    = get_post_meta( $post->ID, $key, true );
			$readonly = '_lps_record_id' === $key || '_lps_published_slug' === $key || '_lps_origin' === $key || str_ends_with( $key, '_at' ) || str_starts_with( $key, '_lps_source_' ) || str_starts_with( $key, '_lps_reviewed_source_' ) || str_starts_with( $key, '_lps_import_' ) || str_starts_with( $key, '_lps_crossref_' ) || str_starts_with( $key, '_lps_copy_' ) || '_lps_translation_reviewer_id' === $key;
			$id       = 'lps-field-' . sanitize_html_class( $key );
			echo '<p><label for="' . esc_attr( $id ) . '"><strong>' . esc_html( (string) $definition['description'] ) . '</strong></label><br>';
			if ( 'boolean' === $definition['type'] ) {
				echo '<input type="checkbox" id="' . esc_attr( $id ) . '" name="lps_meta[' . esc_attr( $key ) . ']" value="1" ' . checked( (bool) $value, true, false ) . ( $readonly ? ' disabled' : '' ) . '>';
			} elseif ( 'array' === $definition['type'] ) {
				$display = is_array( $value ) ? implode( "\n", array_map( array( Policy::class, 'scalar_string' ), $value ) ) : '';
				echo '<textarea class="widefat" rows="3" id="' . esc_attr( $id ) . '" name="lps_meta[' . esc_attr( $key ) . ']"' . ( $readonly ? ' readonly' : '' ) . '>' . esc_textarea( $display ) . '</textarea>';
			} else {
				echo '<input class="widefat" type="text" id="' . esc_attr( $id ) . '" name="lps_meta[' . esc_attr( $key ) . ']" value="' . esc_attr( Policy::scalar_string( $value ) ) . '"' . ( $readonly ? ' readonly' : '' ) . '>';
			}
			// translators: %s is an immutable machine field key.
			echo '<br><span class="description">' . esc_html( sprintf( __( 'Machine key: %s', 'lps-content-model' ), $key ) ) . '</span></p>';
		}
	}

	/**
	 * Saves nonce-protected admin metadata.
	 *
	 * @param int     $post_id Record database ID.
	 * @param WP_Post $post    Current record.
	 */
	public static function save_admin_fields( int $post_id, WP_Post $post ): void {
		if ( ! isset( Contracts::meta_fields()[ $post->post_type ] ) || ! isset( $_POST[ self::NONCE_NAME ] ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized immediately by Policy and sanitize_text_field.
		$nonce = sanitize_text_field( Policy::scalar_string( wp_unslash( $_POST[ self::NONCE_NAME ] ) ) );
		if ( ! wp_verify_nonce( $nonce, self::NONCE_ACTION ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Each typed value uses its registered sanitizer below.
		$submitted = isset( $_POST['lps_meta'] ) && is_array( $_POST['lps_meta'] ) ? wp_unslash( $_POST['lps_meta'] ) : array();
		foreach ( Contracts::meta_fields()[ $post->post_type ] as $key => $definition ) {
			if ( '_lps_record_id' === $key || '_lps_published_slug' === $key || '_lps_origin' === $key || str_ends_with( $key, '_at' ) || str_starts_with( $key, '_lps_source_' ) || str_starts_with( $key, '_lps_reviewed_source_' ) || str_starts_with( $key, '_lps_import_' ) || str_starts_with( $key, '_lps_crossref_' ) || str_starts_with( $key, '_lps_copy_' ) || '_lps_translation_reviewer_id' === $key || in_array( $key, RelationshipPolicy::legacy_relationship_meta_keys(), true ) || '_lps_application_domains' === $key ) {
				continue;
			}
			$value = $submitted[ $key ] ?? ( 'boolean' === $definition['type'] ? false : null );
			if ( null === $value ) {
				continue;
			}
			if ( 'array' === $definition['type'] && is_string( $value ) ) {
				$parts = preg_split( '/\R/', $value );
				$value = false === $parts ? array() : $parts;
			}
			update_post_meta( $post_id, $key, call_user_func( $definition['sanitize_callback'], $value ) );
		}
	}

	/** Registers the single site-settings page. */
	public static function settings_page(): void {
		add_options_page(
			__( 'LPS Site Settings', 'lps-content-model' ),
			__( 'LPS Site Settings', 'lps-content-model' ),
			'manage_options',
			'lps-site-settings',
			array( self::class, 'render_settings_page' )
		);
	}

	/** Renders the typed site-settings form. */
	public static function render_settings_page(): void {
		$values = get_option( Contracts::OPTION_NAME, array() );
		$values = is_array( $values ) ? $values : array();
		echo '<div class="wrap"><h1>' . esc_html__( 'LPS Site Settings', 'lps-content-model' ) . '</h1><form method="post" action="options.php">';
		settings_fields( 'lps_content_model' );
		foreach ( Contracts::site_settings_schema()['properties'] as $key => $schema ) {
			$id = 'lps-setting-' . sanitize_html_class( $key );
			echo '<p><label for="' . esc_attr( $id ) . '"><strong>' . esc_html( ucwords( str_replace( '_', ' ', $key ) ) ) . '</strong></label><br>';
			echo '<input class="regular-text" id="' . esc_attr( $id ) . '" name="' . esc_attr( Contracts::OPTION_NAME ) . '[' . esc_attr( $key ) . ']" type="' . ( 'email' === ( $schema['format'] ?? '' ) ? 'email' : 'text' ) . '" value="' . esc_attr( Policy::scalar_string( $values[ $key ] ?? '' ) ) . '"></p>';
		}
		submit_button();
		echo '</form></div>';
	}

	/** Renders actionable server-side validation notices. */
	public static function admin_notices(): void {
		if ( empty( self::$pending_errors ) ) {
			return;
		}
		$errors = array_merge( ...array_values( self::$pending_errors ) );
		echo '<div class="notice notice-error" role="alert"><p><strong>' . esc_html__( 'Publishing was denied by the LPS content contract.', 'lps-content-model' ) . '</strong></p><ul>';
		foreach ( $errors as $field => $code ) {
			echo '<li><code>' . esc_html( $code ) . '</code>: ' . esc_html( $field ) . '</li>';
		}
		echo '</ul></div>';
	}

	/**
	 * Returns the first scoped REST denial, or null when the request may proceed.
	 *
	 * Creates of units and resources must declare their parent offering through
	 * the `lps_parent_offering` parameter; the declared ID is validated against
	 * the persisted grant list, so an input ID can never create its own access.
	 * Scoped roles never create offerings directly: offering creation is an
	 * institutional act and copy-forward is a dedicated server operation.
	 *
	 * @param string          $role      Scoped policy role.
	 * @param string          $action    `create` or `edit`.
	 * @param string          $post_type Governed record type.
	 * @param int             $post_id   Record ID for updates.
	 * @param WP_REST_Request $request   Current REST request.
	 */
	private static function scoped_rest_error( string $role, string $action, string $post_type, int $post_id, WP_REST_Request $request ): ?string {
		$user = wp_get_current_user();
		if ( ! SecurityPolicy::privileged_session_allowed( $role, MFA::is_enrolled( $user->ID ) ) ) {
			return 'lps_mfa_required';
		}
		if ( ! in_array( $post_type, TeachingPolicy::SCOPED_POST_TYPES, true ) ) {
			return 'lps_teaching_scope_post_type';
		}
		$offering_id = 0;
		if ( 'offering' === TeachingPolicy::scope_for_post_type( $post_type ) ) {
			if ( 0 < $post_id ) {
				$stored = get_post( $post_id );
				if ( ! $stored instanceof WP_Post ) {
					return 'lps_teaching_scope_required';
				}
				$offering_id = Roles::persisted_offering_id( $stored );
			} else {
				if ( 'lps_offering' === $post_type ) {
					return 'lps_teaching_scope_required';
				}
				$offering_id = Policy::sanitize_integer( $request->get_param( 'lps_parent_offering' ) );
				$parent      = 0 < $offering_id ? get_post( $offering_id ) : null;
				if ( ! $parent instanceof WP_Post || 'lps_offering' !== $parent->post_type ) {
					return 'lps_teaching_scope_required';
				}
			}
		}
		$grants = Roles::teaching_grants( $user->ID );
		$error  = TeachingPolicy::scope_error( $role, $action, $post_type, $offering_id, $grants, gmdate( 'c' ) );
		if ( null !== $error ) {
			return $error;
		}
		$author_param = $request->get_param( 'author' );
		if ( null !== $author_param ) {
			$author_id = Policy::sanitize_integer( $author_param );
			$expected  = 0 < $post_id ? Policy::sanitize_integer( get_post_field( 'post_author', $post_id ) ) : $user->ID;
			if ( $author_id !== $expected ) {
				return 'lps_teaching_owner_forbidden';
			}
		}
		return null;
	}

	/**
	 * Offering ID declared by an in-flight scoped REST create.
	 *
	 * @var int|null
	 */
	private static ?int $pending_scoped_offering = null;

	/**
	 * Persists the validated parent offering inside `wp_insert_post`.
	 *
	 * `validate_rest_insert` already proved the declared `lps_parent_offering`
	 * is a real offering inside the account's active grants. Writing the
	 * canonical `unit_offering` or `resource_offering` row on `save_post` —
	 * before the REST meta update checks `edit_post_meta` — keeps the new
	 * record scoped from its first persisted state.
	 *
	 * @param int     $post_id Inserted record ID.
	 * @param WP_Post $post    Inserted record.
	 */
	public static function persist_pending_scoped_parent( int $post_id, WP_Post $post ): void {
		$offering_id = self::$pending_scoped_offering;
		if ( null === $offering_id ) {
			return;
		}
		self::$pending_scoped_offering = null;
		$relationship                  = TeachingPolicy::RELATIONSHIP_SCOPES[ $post->post_type ] ?? '';
		if ( '' === $relationship || 0 >= $offering_id ) {
			return;
		}
		Relationships::replace(
			$post_id,
			$relationship,
			array(
				array(
					'target_post_id'    => $offering_id,
					'relationship_role' => 'unit_offering' === $relationship ? 'part-of' : 'attached-to',
					'sort_order'        => 1,
					'start_date'        => '',
					'end_date'          => '',
					'public_visibility' => true,
				),
			)
		);
	}

	/**
	 * Merges stored and incoming typed metadata.
	 *
	 * @param string               $post_type Record type.
	 * @param int                  $post_id   Record database ID.
	 * @param array<string, mixed> $incoming  Incoming metadata.
	 * @return array<string, mixed>
	 */
	private static function merged_meta( string $post_type, int $post_id, array $incoming ): array {
		$meta = array();
		foreach ( Contracts::meta_fields()[ $post_type ] ?? array() as $key => $definition ) {
			$meta[ $key ] = 0 < $post_id ? get_post_meta( $post_id, $key, true ) : '';
			if ( array_key_exists( $key, $incoming ) ) {
				$meta[ $key ] = call_user_func( $definition['sanitize_callback'], $incoming[ $key ] );
			}
		}
		return $meta;
	}

	/**
	 * Builds a typed REST boundary error.
	 *
	 * @param string                $code    Stable error code.
	 * @param string                $message Human-readable message.
	 * @param string                $field   Machine field key.
	 * @param array<string, string> $errors  All field violations.
	 * @param int                   $status  HTTP status; 403 for authorization denials.
	 */
	private static function error( string $code, string $message, string $field, array $errors = array(), int $status = 400 ): WP_Error {
		return new WP_Error(
			$code,
			$message,
			array(
				'status' => $status,
				'field'  => $field,
				'errors' => $errors,
			)
		);
	}

	/**
	 * Checks typed relationship metadata for references to a record.
	 *
	 * @param WP_Post $post Candidate record.
	 */
	private static function is_referenced( WP_Post $post ): bool {
		if ( Relationships::is_referenced( $post->ID ) ) {
			return true;
		}
		$needle = Policy::scalar_string( get_post_meta( $post->ID, '_lps_record_id', true ) );
		if ( '' === $needle ) {
			return false;
		}
		$records = get_posts(
			array(
				'post_type'      => array_keys( Contracts::post_types() ),
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'fields'         => 'ids',
			)
		);
		foreach ( $records as $record_id ) {
			if ( (int) $record_id === $post->ID ) {
				continue;
			}
			$metadata = get_post_meta( (int) $record_id );
			if ( ! is_array( $metadata ) ) {
				continue;
			}
			foreach ( $metadata as $key => $values ) {
				$encoded_values = wp_json_encode( $values );
				$encoded_values = false === $encoded_values ? '' : $encoded_values;
				if ( is_string( $key ) && str_ends_with( $key, '_ids' ) && str_contains( $encoded_values, $needle ) ) {
					return true;
				}
			}
		}
		return false;
	}
}
