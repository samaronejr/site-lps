<?php
/**
 * Approved language-neutral controlled taxonomies.
 *
 * @package LPS\ContentModel
 */

declare(strict_types=1);

namespace LPS\ContentModel;

use WP_Error;
use WP_Term;

/** Registers and protects the two approved controlled vocabularies. */
final class Taxonomies {
	private const SEED_OPTION = 'lps_controlled_taxonomy_seed_terms';

	/**
	 * Whether term-boundary hooks have already been registered.
	 *
	 * @var bool
	 */
	private static bool $booted = false;

	/**
	 * Returns the complete approved taxonomy contract.
	 *
	 * Term names and slugs deliberately remain language-neutral keys. Localized labels live in
	 * Research Area records and the presentation layer, never in the canonical term identity.
	 *
	 * @return array<string, array{object_types: array<int, string>, rest_base: string, terms: array<string, array{sort_order: int}>}>
	 */
	public static function definitions(): array {
		return array(
			'lps_research_area_key'  => array(
				'object_types' => array( 'lps_person', 'lps_project', 'lps_publication' ),
				'rest_base'    => 'research-area-keys',
				'terms'        => array(
					'instrumentation'            => array( 'sort_order' => 10 ),
					'signal-processing'          => array( 'sort_order' => 20 ),
					'computational-intelligence' => array( 'sort_order' => 30 ),
					'software-engineering'       => array( 'sort_order' => 40 ),
				),
			),
			'lps_application_domain' => array(
				'object_types' => array( 'lps_project' ),
				'rest_base'    => 'application-domains',
				'terms'        => array(
					'electrical-nuclear-energy' => array( 'sort_order' => 10 ),
					'oil-and-gas'               => array( 'sort_order' => 20 ),
					'high-energy-physics'       => array( 'sort_order' => 30 ),
					'defense'                   => array( 'sort_order' => 40 ),
					'medicine'                  => array( 'sort_order' => 50 ),
					'veterinary-science'        => array( 'sort_order' => 60 ),
					'data-quality'              => array( 'sort_order' => 70 ),
				),
			),
		);
	}

	/** Registers term-boundary filters once. */
	public static function boot(): void {
		if ( self::$booted ) {
			return;
		}
		self::$booted = true;
		add_filter( 'pre_insert_term', array( self::class, 'validate_inserted_term' ), 10, 3 );
		add_filter( 'wp_update_term_data', array( self::class, 'preserve_term_identity' ), 10, 4 );
	}

	/** Registers only the approved taxonomies. */
	public static function register(): void {
		foreach ( self::definitions() as $taxonomy => $definition ) {
			register_taxonomy(
				$taxonomy,
				$definition['object_types'],
				array(
					'labels'             => array(
						'name'          => 'lps_research_area_key' === $taxonomy ? __( 'Research area keys', 'lps-content-model' ) : __( 'Application domains', 'lps-content-model' ),
						'singular_name' => 'lps_research_area_key' === $taxonomy ? __( 'Research area key', 'lps-content-model' ) : __( 'Application domain', 'lps-content-model' ),
					),
					'public'             => true,
					'publicly_queryable' => false,
					'hierarchical'       => false,
					'show_ui'            => true,
					'show_admin_column'  => true,
					'show_in_rest'       => true,
					'rest_base'          => $definition['rest_base'],
					'rewrite'            => false,
					'meta_box_cb'        => false,
					'capabilities'       => array(
						'manage_terms' => 'do_not_allow',
						'edit_terms'   => 'do_not_allow',
						'delete_terms' => 'do_not_allow',
						'assign_terms' => 'do_not_allow',
					),
				)
			);
		}
	}

	/**
	 * Seeds missing approved terms and restores their immutable key identity.
	 *
	 * @return array{created: int, restored: int, errors: array<int, string>}
	 */
	public static function seed(): array {
		$created_ids = get_option( self::SEED_OPTION, array() );
		$created_ids = is_array( $created_ids ) ? $created_ids : array();
		/**
		 * Migration-created term IDs by taxonomy.
		 *
		 * @var array<string, array<int, int>> $created_ids
		 */
		$created  = 0;
		$restored = 0;
		$errors   = array();

		foreach ( self::definitions() as $taxonomy => $definition ) {
			foreach ( $definition['terms'] as $key => $term_definition ) {
				$existing = term_exists( $key, $taxonomy );
				if ( null === $existing ) {
					$result = wp_insert_term(
						$key,
						$taxonomy,
						array(
							'slug'        => $key,
							'description' => '',
						)
					);
					if ( $result instanceof WP_Error ) {
						$errors[] = (string) $result->get_error_code();
						continue;
					}
					$created_ids[ $taxonomy ][] = (int) $result['term_id'];
					++$created;
					$term_id = (int) $result['term_id'];
				} else {
					$term_id = is_array( $existing ) ? (int) $existing['term_id'] : (int) $existing;
					$term    = get_term( $term_id, $taxonomy );
					if ( $term instanceof WP_Term && ( $key !== $term->name || $key !== $term->slug || '' !== $term->description ) ) {
						wp_update_term(
							$term_id,
							$taxonomy,
							array(
								'name'        => $key,
								'slug'        => $key,
								'description' => '',
							)
						);
						++$restored;
					}
				}
				update_term_meta( $term_id, '_lps_sort_order', $term_definition['sort_order'] );
			}
		}

		update_option( self::SEED_OPTION, $created_ids, false );
		return array(
			'created'  => $created,
			'restored' => $restored,
			'errors'   => $errors,
		);
	}

	/**
	 * Removes only terms created by this migration.
	 *
	 * @return array{removed: int}
	 */
	public static function rollback_seed(): array {
		$created_ids = get_option( self::SEED_OPTION, array() );
		$created_ids = is_array( $created_ids ) ? $created_ids : array();
		/**
		 * Migration-created term IDs by taxonomy.
		 *
		 * @var array<string, array<int, int>> $created_ids
		 */
		$removed = 0;
		foreach ( $created_ids as $taxonomy => $term_ids ) {
			foreach ( $term_ids as $term_id ) {
				if ( true === wp_delete_term( (int) $term_id, $taxonomy ) ) {
					++$removed;
				}
			}
		}
		delete_option( self::SEED_OPTION );
		return array( 'removed' => $removed );
	}

	/**
	 * Rejects free tags and localized aliases at the write boundary.
	 *
	 * @param mixed                $term     Candidate term name.
	 * @param string               $taxonomy Taxonomy name.
	 * @param array<string, mixed> $args     Candidate term data.
	 * @return mixed
	 */
	public static function validate_inserted_term( mixed $term, string $taxonomy, array $args ): mixed {
		$definitions = self::definitions();
		if ( ! isset( $definitions[ $taxonomy ] ) ) {
			return $term;
		}
		$name = is_string( $term ) ? trim( $term ) : '';
		$slug = isset( $args['slug'] ) && is_string( $args['slug'] ) ? trim( $args['slug'] ) : $name;
		if ( $name !== $slug || ! isset( $definitions[ $taxonomy ]['terms'][ $slug ] ) ) {
			return new WP_Error(
				'lps_unapproved_taxonomy_term',
				'This controlled taxonomy accepts only approved language-neutral keys.',
				array(
					'status'   => 400,
					'taxonomy' => $taxonomy,
					'term'     => $name,
				)
			);
		}
		return $term;
	}

	/**
	 * Makes approved term identity immutable even for low-level updates.
	 *
	 * @param array<string, mixed> $data     Sanitized term data.
	 * @param int                  $term_id  Term ID.
	 * @param string               $taxonomy Taxonomy name.
	 * @param array<string, mixed> $args     Raw update arguments.
	 * @return array<string, mixed>
	 */
	public static function preserve_term_identity( array $data, int $term_id, string $taxonomy, array $args ): array {
		unset( $args );
		if ( ! isset( self::definitions()[ $taxonomy ] ) ) {
			return $data;
		}
		$term = get_term( $term_id, $taxonomy );
		if ( ! $term instanceof WP_Term || ! isset( self::definitions()[ $taxonomy ]['terms'][ $term->slug ] ) ) {
			return $data;
		}
		$data['name']        = $term->slug;
		$data['slug']        = $term->slug;
		$data['description'] = '';
		return $data;
	}

	/**
	 * Returns whether a key is approved for one controlled taxonomy.
	 *
	 * @param string $taxonomy Taxonomy name.
	 * @param string $key      Language-neutral key.
	 */
	public static function is_approved( string $taxonomy, string $key ): bool {
		return isset( self::definitions()[ $taxonomy ]['terms'][ $key ] );
	}
}
