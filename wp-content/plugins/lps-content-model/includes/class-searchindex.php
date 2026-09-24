<?php
/**
 * Plugin-owned locale search index synchronization.
 *
 * @package LPS\ContentModel
 */

declare(strict_types=1);

namespace LPS\ContentModel;

use WP_Post;
use WP_Query;
use WP_Term;
use wpdb;

require_once __DIR__ . '/class-searchpolicy.php';
require_once __DIR__ . '/class-searchstorage.php';
require_once __DIR__ . '/class-publicationpolicy.php';
require_once __DIR__ . '/class-publicationrecords.php';
require_once __DIR__ . '/class-teachingcontracts.php';

/** Owns the locale search index table and keeps it truthful on every transition. */
final class SearchIndex {
	public const TABLE_SUFFIX = 'lps_search_index';
	public const VERSION      = '1.3.0';

	private const VERSION_OPTION = 'lps_search_index_version';

	/**
	 * Record types carried by the search index.
	 *
	 * @var array<int, string>
	 */
	private const INDEXED_TYPES = array( 'lps_publication', 'lps_project', 'lps_person', 'lps_news', 'lps_opportunity', 'lps_event', 'lps_research_area', 'lps_course', 'lps_offering', 'lps_resource' );

	/**
	 * Locales the index accepts. Any other locale is absent and stays unindexed.
	 *
	 * @var array<int, string>
	 */
	private const LOCALES = array( 'pt-br', 'en' );

	/**
	 * Metadata that must never reach a public index row.
	 *
	 * @var array<int, string>
	 */
	private const PRIVATE_FIELDS = array(
		'_lps_private_notes',
		'_lps_internal_contact',
		'_lps_personal_email',
		'_lps_home_address',
		'_lps_phone',
		'_lps_review_comments',
		'_lps_cpf',
		'_lps_salary_band',
		'_lps_version_id',
		'_lps_storage_key',
		'_lps_uploader_user_id',
		'_lps_prepared_by',
	);

	/**
	 * Returns the record types carried by the index.
	 *
	 * @return array<int, string>
	 */
	public static function indexed_post_types(): array {
		return self::INDEXED_TYPES;
	}

	/**
	 * Returns the metadata keys that are never indexed.
	 *
	 * @return array<int, string>
	 */
	public static function private_fields(): array {
		return self::PRIVATE_FIELDS;
	}

	/**
	 * Returns the fully qualified index table name.
	 *
	 * @param string $prefix WordPress table prefix.
	 */
	public static function table_name( string $prefix ): string {
		return $prefix . self::TABLE_SUFFIX;
	}

	/**
	 * Builds the dbDelta-compatible index schema.
	 *
	 * @param string $prefix          WordPress table prefix.
	 * @param string $charset_collate Trusted wpdb charset/collation clause.
	 */
	public static function schema_sql( string $prefix, string $charset_collate ): string {
		$table = self::table_name( $prefix );
		return "CREATE TABLE {$table} (
 search_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
 post_id bigint(20) unsigned NOT NULL,
 locale varchar(8) NOT NULL,
 post_type varchar(32) NOT NULL,
 exact_terms text NOT NULL,
 high_terms text NOT NULL,
 medium_terms longtext NOT NULL,
 low_terms longtext NOT NULL,
 facets longtext NOT NULL,
 lifecycle text NOT NULL,
 title text NOT NULL,
 summary text NOT NULL,
 url varchar(255) NOT NULL DEFAULT '',
 published_at datetime NOT NULL DEFAULT '1970-01-01 00:00:00',
 PRIMARY KEY  (search_id),
 UNIQUE KEY post_id (post_id),
 KEY locale_type (locale,post_type),
 KEY locale_published (locale,published_at)
) {$charset_collate};";
	}

	/**
	 * Reports whether one record belongs in the public index.
	 *
	 * Drafts, archived records, private types, and absent locales never enter.
	 *
	 * @param string $status    Record post status.
	 * @param string $locale    Recorded locale slug.
	 * @param string $post_type Record type.
	 */
	public static function is_indexable( string $status, string $locale, string $post_type ): bool {
		return 'publish' === $status
			&& in_array( $locale, self::LOCALES, true )
			&& in_array( $post_type, self::INDEXED_TYPES, true );
	}

	/**
	 * Reports whether the public surfaces publish this record at all.
	 *
	 * The index evaluates the same plugin-owned decision every other public
	 * surface uses — `PublicationPolicy::visibility_decision()` on the
	 * `public` surface — so search can never leak a record the renderers,
	 * feeds, or previews would withhold: archived or in-review records,
	 * unapproved in-memoriam profiles, stale English variants, unreviewed
	 * imports, and ambiguous-origin records are all absent from the index.
	 *
	 * @param array<string, mixed> $record Portable record.
	 */
	public static function is_publicly_visible( array $record ): bool {
		$locale   = self::text( $record['locale'] ?? '' );
		$decision = PublicationPolicy::visibility_decision( $record, PublicationPolicy::SURFACE_PUBLIC, $locale, gmdate( 'Y-m-d' ) );
		$visible  = $decision['visible'];
		if ( ! $visible && 'lps_resource' === self::text( $record['post_type'] ?? '' ) ) {
			// A scheduled resource is indexed so its release needs no scheduler:
			// the query-time lifecycle gate withholds it until its release time
			// passes, exactly like the download resolver. Every other denial —
			// draft, withdrawn, unapproved review, uncleared scan — still keeps
			// the row out of the index entirely.
			$visible = 'scheduled' === self::text( $record['_lps_release_state'] ?? '' )
				&& array( '_lps_release_state' => 'lps_resource_not_released' ) === $decision['errors'];
		}
		if ( $visible && 'lps_resource' === self::text( $record['post_type'] ?? '' ) ) {
			// A resource is public only while its parent offering is public —
			// the same structural check the download resolver applies.
			$visible = true === ( $record['offering_visible'] ?? false );
		}
		return $visible;
	}

	/**
	 * Builds one index row from a portable record.
	 *
	 * Only the allow-listed public fields are read, so private metadata cannot
	 * reach the index even when the caller passes a complete record.
	 *
	 * @param array<string, mixed> $record Portable record.
	 * @return array<string, mixed>
	 */
	public static function build_row( array $record ): array {
		$exact = array();
		foreach ( array( 'doi', 'orcid', 'acronym', 'title', 'code', 'term_token' ) as $key ) {
			$value = SearchPolicy::normalize( self::text( $record[ $key ] ?? '' ) );
			if ( '' !== $value && ! in_array( $value, $exact, true ) ) {
				$exact[] = $value;
			}
		}
		$high     = SearchPolicy::normalize( implode( ' ', array( self::text( $record['title'] ?? '' ), self::text( $record['acronym'] ?? '' ), self::text( $record['doi'] ?? '' ), self::text( $record['orcid'] ?? '' ), self::text( $record['name'] ?? '' ), self::text( $record['course_title'] ?? '' ), implode( ' ', self::strings( $record['instructors'] ?? array() ) ) ) ) );
		$taxonomy = implode( ' ', self::strings( $record['taxonomy'] ?? array() ) );
		$medium   = SearchPolicy::normalize( self::text( $record['summary'] ?? '' ) . ' ' . $taxonomy . ' ' . self::text( $record['term_label'] ?? '' ) . ' ' . self::text( $record['section'] ?? '' ) . ' ' . self::text( $record['program'] ?? '' ) . ' ' . self::text( $record['schedule'] ?? '' ) . ' ' . self::text( $record['venue'] ?? '' ) . ' ' . self::text( $record['resource_type'] ?? '' ) . ' ' . self::text( $record['language'] ?? '' ) );
		$low      = SearchPolicy::normalize( self::text( $record['body'] ?? '' ) . ' ' . self::text( $record['prerequisites'] ?? '' ) . ' ' . self::text( $record['syllabus'] ?? '' ) . ' ' . self::text( $record['syllabus_snapshot'] ?? '' ) );
		$facets   = SearchPolicy::sanitize_facets(
			is_array( $record['facets'] ?? null ) ? $record['facets'] : array(),
			SearchPolicy::facet_definitions( self::text( $record['post_type'] ?? '' ) )
		);

		return array(
			'post_id'      => self::number( $record['post_id'] ?? 0 ),
			'locale'       => self::text( $record['locale'] ?? '' ),
			'post_type'    => self::text( $record['post_type'] ?? '' ),
			'exact_terms'  => implode( "\n", $exact ),
			'high_terms'   => $high,
			'medium_terms' => $medium,
			'low_terms'    => $low,
			'facets'       => self::encode( $facets ),
			'lifecycle'    => self::encode( self::sanitize_lifecycle( $record['lifecycle'] ?? array() ) ),
			'title'        => self::text( $record['title'] ?? '' ),
			'summary'      => self::text( $record['summary'] ?? '' ),
			'url'          => self::text( $record['url'] ?? '' ),
			'published_at' => self::text( $record['published_at'] ?? '1970-01-01 00:00:00' ),
		);
	}

	/**
	 * Converts one stored row into the shape the search policy scores.
	 *
	 * @param array<string, mixed> $row Stored index row.
	 * @return array<string, mixed>
	 */
	public static function hydrate( array $row ): array {
		$facets = array();
		$stored = self::text( $row['facets'] ?? '' );
		if ( '' !== $stored ) {
			$decoded = json_decode( $stored, true );
			if ( is_array( $decoded ) ) {
				foreach ( $decoded as $facet => $values ) {
					if ( is_string( $facet ) && is_array( $values ) ) {
						$facets[ $facet ] = self::strings( $values );
					}
				}
			}
		}
		$lifecycle = array();
		$encoded   = self::text( $row['lifecycle'] ?? '' );
		if ( '' !== $encoded ) {
			$decoded = json_decode( $encoded, true );
			if ( is_array( $decoded ) ) {
				$lifecycle = self::sanitize_lifecycle( $decoded );
			}
		}
		return array(
			'post_id'      => self::number( $row['post_id'] ?? 0 ),
			'locale'       => self::text( $row['locale'] ?? '' ),
			'post_type'    => self::text( $row['post_type'] ?? '' ),
			'exact'        => array_values( array_filter( explode( "\n", self::text( $row['exact_terms'] ?? '' ) ), static fn( string $term ): bool => '' !== $term ) ),
			'high'         => self::text( $row['high_terms'] ?? '' ),
			'medium'       => self::text( $row['medium_terms'] ?? '' ),
			'low'          => self::text( $row['low_terms'] ?? '' ),
			'facets'       => $facets,
			'lifecycle'    => $lifecycle,
			'title'        => self::text( $row['title'] ?? '' ),
			'summary'      => self::text( $row['summary'] ?? '' ),
			'url'          => self::text( $row['url'] ?? '' ),
			'published_at' => self::text( $row['published_at'] ?? '' ),
		);
	}

	/**
	 * Replaces the indexed row of one record inside a single transaction.
	 *
	 * A failed delete or insert reverts the whole change, so a partially
	 * indexed record can never become visible to search.
	 *
	 * @param SearchStorage        $storage Index storage boundary.
	 * @param array<string, mixed> $record  Portable record.
	 */
	public static function replace( SearchStorage $storage, array $record ): bool {
		$row = self::build_row( $record );
		if ( 0 >= self::number( $row['post_id'] ) ) {
			return false;
		}
		$storage->begin();
		if ( ! $storage->delete_record( self::number( $row['post_id'] ) ) ) {
			$storage->rollback();
			return false;
		}
		if ( ! $storage->insert_record( $row ) ) {
			$storage->rollback();
			return false;
		}
		$storage->commit();
		return true;
	}

	/**
	 * Removes one record from the index inside a single transaction.
	 *
	 * @param SearchStorage $storage Index storage boundary.
	 * @param int           $post_id Record database ID.
	 */
	public static function remove( SearchStorage $storage, int $post_id ): bool {
		if ( 0 >= $post_id ) {
			return false;
		}
		$storage->begin();
		if ( ! $storage->delete_record( $post_id ) ) {
			$storage->rollback();
			return false;
		}
		$storage->commit();
		return true;
	}

	/**
	 * Applies one publish or archive transition to the index.
	 *
	 * @param SearchStorage        $storage Index storage boundary.
	 * @param array<string, mixed> $record  Portable record including its status.
	 */
	public static function synchronize( SearchStorage $storage, array $record ): bool {
		$status    = self::text( $record['status'] ?? '' );
		$locale    = self::text( $record['locale'] ?? '' );
		$post_type = self::text( $record['post_type'] ?? '' );
		if ( self::is_indexable( $status, $locale, $post_type ) && self::is_publicly_visible( $record ) ) {
			return self::replace( $storage, $record );
		}
		return self::remove( $storage, self::number( $record['post_id'] ?? 0 ) );
	}

	/**
	 * Runs one locale search over the stored index.
	 *
	 * @param SearchStorage                     $storage   Index storage boundary.
	 * @param string                            $post_type Record type, empty for every indexed type.
	 * @param string                            $query     Untrusted query string.
	 * @param string                            $locale    Supported locale slug.
	 * @param array<string, array<int, string>> $facets    Sanitized active facets.
	 * @param int                               $page      Requested page, one-based.
	 * @param int                               $per_page  Results per page.
	 * @param string|null                       $now       Evaluation instant (ISO-8601); null means now.
	 * @return array{items: array<int, array<string, mixed>>, total: int, page: int, per_page: int, pages: int, error: string|null}
	 */
	public static function search( SearchStorage $storage, string $post_type, string $query, string $locale, array $facets, int $page = 1, int $per_page = SearchPolicy::PER_PAGE, ?string $now = null ): array {
		return SearchPolicy::search_records( $storage->rows_for( $locale, $post_type ), $query, $locale, $facets, $page, $per_page, $now );
	}

	/**
	 * Creates or repairs the index table idempotently.
	 *
	 * The index is plugin-owned storage, so it is installed next to the
	 * relationship schema instead of inside it and stays independently
	 * reversible.
	 */
	public static function install(): void {
		global $wpdb;
		if ( ! $wpdb instanceof wpdb ) {
			return;
		}
		$installed = get_option( self::VERSION_OPTION, '' );
		if ( is_string( $installed ) && self::VERSION === $installed && self::has_lifecycle_column( $wpdb ) ) {
			return;
		}
		require_once dirname( __DIR__, 4 ) . '/wp-admin/includes/upgrade.php';
		dbDelta( self::schema_sql( $wpdb->prefix, $wpdb->get_charset_collate() ) );
		self::ensure_lifecycle_column( $wpdb );
		self::rebuild();
		update_option( self::VERSION_OPTION, self::VERSION, false );
	}

	/**
	 * Reports whether the index table already carries the lifecycle column.
	 *
	 * @param wpdb $database WordPress database adapter.
	 */
	private static function has_lifecycle_column( wpdb $database ): bool {
		$columns = $database->get_col( 'SHOW COLUMNS FROM ' . self::table_name( $database->prefix ), 0 );
		return in_array( 'lifecycle', $columns, true );
	}

	/**
	 * Adds the lifecycle column when dbDelta could not.
	 *
	 * The dbDelta routine emits `ADD COLUMN ... AFTER`, which the SQLite driver cannot
	 * place; a plain `ADD COLUMN` with a literal default is the portable
	 * repair. On MySQL dbDelta has already added the column, so this path is
	 * only reached where the fallback statement is valid.
	 *
	 * @param wpdb $database WordPress database adapter.
	 */
	private static function ensure_lifecycle_column( wpdb $database ): void {
		if ( self::has_lifecycle_column( $database ) ) {
			return;
		}
		$database->query( 'ALTER TABLE ' . self::table_name( $database->prefix ) . ' ADD COLUMN lifecycle text NOT NULL DEFAULT \'\'' );
	}

	/**
	 * Rebuilds the whole index from the published records.
	 *
	 * @return int Indexed record count.
	 */
	public static function rebuild(): int {
		$storage = self::storage();
		if ( null === $storage ) {
			return 0;
		}
		$query   = new WP_Query(
			array(
				'post_type'              => self::INDEXED_TYPES,
				'post_status'            => 'publish',
				'posts_per_page'         => 2000, // phpcs:ignore WordPress.WP.PostsPerPage.posts_per_page_posts_per_page -- one-shot index rebuild walks the full published set exactly once.
				'orderby'                => 'ID',
				'order'                  => 'ASC',
				'no_found_rows'          => true,
				'update_post_meta_cache' => true,
				'update_post_term_cache' => true,
			)
		);
		$indexed = 0;
		foreach ( $query->posts as $post ) {
			if ( $post instanceof WP_Post && self::synchronize( $storage, self::record_from_post( $post ) ) ) {
				++$indexed;
			}
		}
		return $indexed;
	}

	/**
	 * Returns the reverse statement of the index table.
	 *
	 * @param string $prefix WordPress table prefix.
	 */
	public static function rollback_sql( string $prefix ): string {
		return 'DROP TABLE IF EXISTS ' . self::table_name( $prefix );
	}

	/** Registers the index synchronization hooks. */
	public static function boot(): void {
		add_action( 'wp_after_insert_post', array( self::class, 'synchronize_post' ), 20, 2 );
		add_action( 'deleted_post', array( self::class, 'forget_post' ), 10, 2 );
	}

	/**
	 * Synchronizes one record after WordPress finished writing it.
	 *
	 * @param int          $post_id Record database ID.
	 * @param WP_Post|null $post    Written record.
	 */
	public static function synchronize_post( int $post_id, ?WP_Post $post = null ): void {
		if ( ! $post instanceof WP_Post ) {
			$found = get_post( $post_id );
			$post  = $found instanceof WP_Post ? $found : null;
		}
		if ( ! $post instanceof WP_Post ) {
			return;
		}
		$storage = self::storage();
		if ( null === $storage ) {
			return;
		}
		self::synchronize( $storage, self::record_from_post( $post ) );
		self::synchronize_variants( $storage, $post->ID );
		self::synchronize_dependents( $storage, $post );
	}

	/**
	 * Re-synchronizes the translation siblings of one written record.
	 *
	 * An authority edit can stale or refresh the English variant's visibility
	 * and shared terms, so every associated variant row is re-evaluated on the
	 * same write — the index never keeps a stale sibling row behind.
	 *
	 * @param SearchStorage $storage Index storage boundary.
	 * @param int           $post_id Written record ID.
	 */
	private static function synchronize_variants( SearchStorage $storage, int $post_id ): void {
		if ( ! class_exists( Translations::class ) ) {
			return;
		}
		foreach ( Translations::variants( $post_id ) as $variant_id ) {
			$variant_id = self::number( $variant_id );
			if ( $variant_id === $post_id || 0 >= $variant_id ) {
				continue;
			}
			$variant = get_post( $variant_id );
			if ( $variant instanceof WP_Post ) {
				self::synchronize( $storage, self::record_from_post( $variant ) );
			}
		}
	}

	/**
	 * Re-synchronizes the indexed rows that embed one record's data.
	 *
	 * Teaching rows carry related-record terms — instructor names, course
	 * titles and codes, term labels and boundaries — so a correction to a
	 * person, term, course or offering must reach every row that quotes it:
	 *
	 *  - `lps_person`    → offerings listing the person on the teaching team.
	 *  - `lps_term`      → offerings scheduled in the term.
	 *  - `lps_course`    → offerings of the course.
	 *  - `lps_offering`  → resources attached to the offering.
	 *
	 * Each dependent's own translation variants are re-evaluated too, so a
	 * shared-field change reaches both locale rows.
	 *
	 * @param SearchStorage $storage Index storage boundary.
	 * @param WP_Post       $post    Written record.
	 */
	private static function synchronize_dependents( SearchStorage $storage, WP_Post $post ): void {
		if ( ! class_exists( Relationships::class ) ) {
			return;
		}
		$authority = class_exists( TeachingContracts::class ) ? TeachingContracts::authoritative_id( $post->ID ) : $post->ID;
		$targets   = array();
		switch ( $post->post_type ) {
			case 'lps_person':
				foreach ( Relationships::reverse_for( $authority, 'teaching_team' ) as $row ) {
					$targets[] = self::number( $row['source_post_id'] );
				}
				break;
			case 'lps_term':
				foreach ( Relationships::reverse_for( $authority, 'offering_term' ) as $row ) {
					$targets[] = self::number( $row['source_post_id'] );
				}
				break;
			case 'lps_course':
				foreach ( Relationships::reverse_for( $authority, 'offering_course' ) as $row ) {
					$targets[] = self::number( $row['source_post_id'] );
				}
				break;
			case 'lps_offering':
				foreach ( Relationships::reverse_for( $authority, 'resource_offering' ) as $row ) {
					$targets[] = self::number( $row['source_post_id'] );
				}
				break;
			default:
				break;
		}
		foreach ( array_unique( $targets ) as $target_id ) {
			if ( 0 >= $target_id || $target_id === $post->ID ) {
				continue;
			}
			$target = get_post( $target_id );
			if ( ! $target instanceof WP_Post ) {
				continue;
			}
			self::synchronize( $storage, self::record_from_post( $target ) );
			self::synchronize_variants( $storage, $target->ID );
		}
	}

	/**
	 * Removes one deleted record from the index and re-syncs its dependents.
	 *
	 * Dependents are resolved before the relationship cleanup runs, so a
	 * deleted term or person still reaches the rows that quoted it; their
	 * re-sync then indexes the absence (empty label, missing instructor).
	 *
	 * @param int          $post_id Record database ID.
	 * @param WP_Post|null $post    Deleted record, when the hook supplies it.
	 */
	public static function forget_post( int $post_id, ?WP_Post $post = null ): void {
		$storage = self::storage();
		if ( null === $storage ) {
			return;
		}
		if ( $post instanceof WP_Post ) {
			self::synchronize_dependents( $storage, $post );
			// A deleted authority also changes every variant's source-state
			// decision, so sibling rows are re-evaluated before removal.
			self::synchronize_variants( $storage, $post_id );
		}
		self::remove( $storage, $post_id );
	}

	/**
	 * Builds the portable record of one WordPress post.
	 *
	 * @param WP_Post $post Record.
	 * @return array<string, mixed>
	 */
	public static function record_from_post( WP_Post $post ): array {
		$terms = array();
		foreach ( array( 'lps_research_area_key', 'lps_application_domain' ) as $taxonomy ) {
			$assigned = get_the_terms( $post, $taxonomy );
			if ( is_array( $assigned ) ) {
				foreach ( $assigned as $term ) {
					$terms[] = $term->slug;
				}
			}
		}
		$decision = class_exists( PublicationRecords::class ) ? PublicationRecords::publication_record( $post ) : array();
		return array_merge(
			$decision,
			array(
				'post_id'      => $post->ID,
				'status'       => $post->post_status,
				'locale'       => self::text( $decision['locale'] ?? self::meta( $post->ID, '_lps_locale' ) ),
				'post_type'    => $post->post_type,
				'title'        => $post->post_title,
				'acronym'      => self::meta( $post->ID, '_lps_acronym' ),
				'doi'          => self::canonical_doi( $post ),
				'orcid'        => self::meta( $post->ID, '_lps_orcid' ),
				'summary'      => $post->post_excerpt,
				'taxonomy'     => $terms,
				'body'         => $post->post_content,
				'facets'       => self::facets_for_post( $post, $terms ),
				'url'          => (string) get_permalink( $post ),
				'published_at' => $post->post_date_gmt,
			),
			self::teaching_fields( $post )
		);
	}

	/**
	 * Builds the teaching-specific index fields of one record.
	 *
	 * Shared values are always read from the Portuguese authority so an
	 * English variant row carries the same course code, team, term and
	 * release facts as its source. Only the allow-listed public fields are
	 * read: version identifiers, storage keys and uploader linkage never
	 * reach the record, let alone the index row.
	 *
	 * @param WP_Post $post Record.
	 * @return array<string, mixed>
	 */
	private static function teaching_fields( WP_Post $post ): array {
		if ( ! class_exists( TeachingContracts::class ) || ! in_array( $post->post_type, TeachingContracts::POST_TYPES, true ) ) {
			return array();
		}
		$authority = TeachingContracts::authoritative_id( $post->ID );
		$shared    = array();
		foreach ( TeachingContracts::shared_specific_keys( $post->post_type ) as $key ) {
			$shared[ $key ] = get_post_meta( $authority, $key, true );
		}
		$locale = class_exists( Translations::class ) ? Translations::locale( $post->ID ) : self::meta( $post->ID, '_lps_locale' );

		if ( 'lps_course' === $post->post_type ) {
			$code = self::text( $shared['_lps_course_code'] ?? '' );
			return array(
				'code'          => $code,
				'program'       => self::text( $shared['_lps_program'] ?? '' ),
				'prerequisites' => self::text( get_post_meta( $post->ID, '_lps_prerequisites', true ) ),
				'syllabus'      => self::text( get_post_meta( $post->ID, '_lps_syllabus', true ) ),
				'url'           => class_exists( TeachingRecords::class ) ? TeachingRecords::course_path( $locale, $post->post_name ) : (string) get_permalink( $post ),
				'facets'        => array(
					'level' => array( self::text( $shared['_lps_course_level'] ?? '' ) ),
				),
			);
		}

		if ( 'lps_offering' === $post->post_type ) {
			$identity  = class_exists( TeachingRecords::class ) ? TeachingRecords::offering_identity_for( $authority ) : null;
			$course_id = self::number( $identity['course_id'] ?? 0 );
			$term_id   = self::number( $identity['term_id'] ?? 0 );
			$course    = self::localized_post( $course_id, $locale );
			$term      = 0 < $term_id ? get_post( $term_id ) : null;
			$team      = class_exists( Relationships::class ) ? Relationships::for_source( $authority, 'teaching_team' ) : array();
			$temporal  = self::text( get_post_meta( $authority, '_lps_temporal_status', true ) );
			if ( '' === $temporal && $term instanceof WP_Post ) {
				$temporal = TeachingContracts::temporal_status(
					get_post_meta( $term->ID, '_lps_starts_on', true ),
					get_post_meta( $term->ID, '_lps_ends_on', true ),
					get_post_meta( $authority, '_lps_cancelled', true ),
					TeachingContracts::today()
				);
			}
			$course_code = 0 < $course_id ? self::text( get_post_meta( $course_id, '_lps_course_code', true ) ) : '';
			$term_label  = $term instanceof WP_Post ? self::text( get_post_meta( $term->ID, '_lps_period_label', true ) ) : '';
			$term_token  = $term instanceof WP_Post ? self::text( get_post_meta( $term->ID, '_lps_term_token', true ) ) : '';
			return array(
				'code'              => $course_code,
				'course_title'      => $course instanceof WP_Post ? $course->post_title : '',
				'term_label'        => $term_label,
				'term_token'        => $term_token,
				'section'           => self::text( $shared['_lps_section_key'] ?? '' ),
				'instructors'       => self::team_names( $team ),
				'schedule'          => self::text( $shared['_lps_schedule'] ?? '' ),
				'venue'             => self::text( $shared['_lps_venue'] ?? '' ),
				'syllabus_snapshot' => self::text( get_post_meta( $post->ID, '_lps_syllabus_snapshot', true ) ),
				'lifecycle'         => array(
					'starts_on' => $term instanceof WP_Post ? self::text( get_post_meta( $term->ID, '_lps_starts_on', true ) ) : '',
					'ends_on'   => $term instanceof WP_Post ? self::text( get_post_meta( $term->ID, '_lps_ends_on', true ) ) : '',
					'cancelled' => (bool) filter_var( $shared['_lps_cancelled'] ?? false, FILTER_VALIDATE_BOOLEAN ),
				),
				'url'               => class_exists( TeachingRecords::class ) ? TeachingRecords::offering_url( $post->ID, $locale ) : (string) get_permalink( $post ),
				'facets'            => array(
					'status'     => '' === $temporal ? array() : array( SearchPolicy::offering_status_bucket( $temporal ) ),
					'term'       => array( $term_token ),
					'level'      => array( 0 < $course_id ? self::text( get_post_meta( $course_id, '_lps_course_level', true ) ) : '' ),
					'instructor' => self::team_slugs( $team ),
				),
			);
		}

		if ( 'lps_resource' === $post->post_type ) {
			$offering_id = 0;
			$visible     = false;
			if ( class_exists( Relationships::class ) ) {
				$rows        = Relationships::for_source( $authority, 'resource_offering' );
				$offering_id = self::number( $rows[0]['target_post_id'] ?? 0 );
				$visible     = (bool) ( $rows[0]['public_visibility'] ?? false );
			}
			$offering       = 0 < $offering_id ? get_post( $offering_id ) : null;
			$course_id      = 0;
			$course_code    = '';
			$course_title   = '';
			$offering_title = '';
			if ( $offering instanceof WP_Post ) {
				$offering_title = $offering->post_title;
				$visible        = $visible && 'publish' === $offering->post_status && 'published' === self::text( get_post_meta( $offering->ID, '_lps_state', true ) );
				if ( class_exists( TeachingRecords::class ) ) {
					$identity  = TeachingRecords::offering_identity_for( $offering->ID );
					$course_id = self::number( $identity['course_id'] ?? 0 );
				}
				if ( 0 < $course_id ) {
					$course_code  = self::text( get_post_meta( $course_id, '_lps_course_code', true ) );
					$course       = self::localized_post( $course_id, $locale );
					$course_title = $course instanceof WP_Post ? $course->post_title : '';
				}
			} else {
				$visible = false;
			}
			$external_url = self::text( $shared['_lps_external_url'] ?? '' );
			$download     = class_exists( TeachingResources::class ) ? TeachingResources::download_url( $post->ID ) : '';
			return array(
				'code'             => $course_code,
				'course_title'     => trim( $course_title . ' ' . $offering_title ),
				'resource_type'    => self::text( $shared['_lps_resource_type'] ?? '' ),
				'language'         => self::text( $shared['_lps_resource_language'] ?? '' ),
				'offering_visible' => $visible,
				'lifecycle'        => array(
					'release_state' => self::text( $shared['_lps_release_state'] ?? '' ),
					'release_at'    => self::text( $shared['_lps_release_at'] ?? '' ),
				),
				'url'              => '' !== $external_url ? $external_url : $download,
				'facets'           => array(
					'type'     => array( self::text( $shared['_lps_resource_type'] ?? '' ) ),
					'language' => array( self::text( $shared['_lps_resource_language'] ?? '' ) ),
				),
			);
		}

		return array();
	}

	/**
	 * Returns the public canonical names of one teaching team.
	 *
	 * Only publicly visible team rows contribute: a hidden membership never
	 * reaches the index. The canonical name is authority-owned shared data,
	 * so both locale rows carry the same instructor terms. Co-teachers share
	 * the single offering row — the offering is one search entity, never one
	 * row per instructor.
	 *
	 * @param array<int, array<string, mixed>> $team Canonical team rows.
	 * @return array<int, string>
	 */
	private static function team_names( array $team ): array {
		$names = array();
		foreach ( $team as $member ) {
			if ( ! ( $member['public_visibility'] ?? false ) ) {
				continue;
			}
			$person_id = self::number( $member['target_post_id'] ?? 0 );
			$name      = 0 < $person_id ? self::text( get_post_meta( $person_id, '_lps_canonical_name', true ) ) : '';
			if ( '' === $name ) {
				$person = 0 < $person_id ? get_post( $person_id ) : null;
				$name   = $person instanceof WP_Post ? $person->post_title : '';
			}
			if ( '' !== $name && ! in_array( $name, $names, true ) ) {
				$names[] = $name;
			}
		}
		return $names;
	}

	/**
	 * Returns the person slugs of one teaching team.
	 *
	 * @param array<int, array<string, mixed>> $team Canonical team rows.
	 * @return array<int, string>
	 */
	private static function team_slugs( array $team ): array {
		$slugs = array();
		foreach ( $team as $member ) {
			if ( ! ( $member['public_visibility'] ?? false ) ) {
				continue;
			}
			$person = get_post( self::number( $member['target_post_id'] ?? 0 ) );
			if ( $person instanceof WP_Post && '' !== $person->post_name && ! in_array( $person->post_name, $slugs, true ) ) {
				$slugs[] = $person->post_name;
			}
		}
		return $slugs;
	}

	/**
	 * Returns the locale variant of a record, or null when absent.
	 *
	 * @param int    $post_id Any associated variant ID.
	 * @param string $locale  Supported locale slug.
	 */
	private static function localized_post( int $post_id, string $locale ): ?WP_Post {
		$post = 0 < $post_id ? get_post( $post_id ) : null;
		if ( ! $post instanceof WP_Post ) {
			return null;
		}
		$record_locale = class_exists( Translations::class ) ? Translations::locale( $post->ID ) : '';
		if ( $record_locale === $locale ) {
			return $post;
		}
		$variants = class_exists( Translations::class ) ? Translations::variants( $post->ID ) : array();
		$variant  = isset( $variants[ $locale ] ) ? get_post( $variants[ $locale ] ) : null;
		return $variant instanceof WP_Post ? $variant : null;
	}

	/**
	 * Keeps only the allow-listed lifecycle keys of one record.
	 *
	 * @param mixed $value Boundary input.
	 * @return array<string, mixed>
	 */
	private static function sanitize_lifecycle( mixed $value ): array {
		if ( ! is_array( $value ) ) {
			return array();
		}
		$lifecycle = array();
		foreach ( array( 'starts_on', 'ends_on', 'release_state', 'release_at' ) as $key ) {
			$text = self::text( $value[ $key ] ?? '' );
			if ( '' !== $text ) {
				$lifecycle[ $key ] = $text;
			}
		}
		if ( array_key_exists( 'cancelled', $value ) ) {
			$lifecycle['cancelled'] = (bool) $value['cancelled'];
		}
		return $lifecycle;
	}

	/**
	 * Returns the canonical DOI of one publication.
	 *
	 * DOIs are owned by the publication DOI table, not by post metadata, so the
	 * index reads the canonical value instead of the compatibility mirror.
	 *
	 * @param WP_Post $post Record.
	 */
	private static function canonical_doi( WP_Post $post ): string {
		$mirror = self::meta( $post->ID, '_lps_doi' );
		if ( '' !== $mirror || ! class_exists( Relationships::class ) ) {
			return $mirror;
		}
		return self::text( Relationships::doi_for_publication( self::authority_id( $post ) ) );
	}

	/**
	 * Returns the database ID of the Portuguese authority record.
	 *
	 * @param WP_Post $post Record.
	 */
	private static function authority_id( WP_Post $post ): int {
		if ( ! class_exists( Translations::class ) ) {
			return $post->ID;
		}
		return self::number( Translations::source_id( $post->ID ) ?? $post->ID );
	}

	/**
	 * Builds the facet values of one record.
	 *
	 * @param WP_Post            $post  Record.
	 * @param array<int, string> $terms Assigned controlled taxonomy keys.
	 * @return array<string, array<int, string>>
	 */
	private static function facets_for_post( WP_Post $post, array $terms ): array {
		$year   = substr( $post->post_date_gmt, 0, 4 );
		$facets = array(
			'area'   => $terms,
			'domain' => $terms,
			'year'   => array( $year ),
			'date'   => array( $year ),
		);
		foreach ( array( 'status', 'type', 'role', 'state', 'category', 'audience' ) as $facet ) {
			$value = self::meta( $post->ID, '_lps_' . $facet );
			if ( '' !== $value ) {
				$facets[ $facet ] = array( $value );
			}
		}
		$person_status = self::meta( $post->ID, '_lps_person_status' );
		if ( '' !== $person_status ) {
			$facets['status'] = array( $person_status );
		}
		$project_status = self::meta( $post->ID, '_lps_project_status' );
		if ( '' !== $project_status ) {
			$facets['status'] = array( $project_status );
		}
		return $facets;
	}

	/**
	 * Returns the WordPress-backed storage, or null before the database exists.
	 */
	private static function storage(): ?SearchStorage {
		global $wpdb;
		require_once __DIR__ . '/class-wpdbsearchstorage.php';
		return $wpdb instanceof wpdb ? new WpdbSearchStorage( $wpdb ) : null;
	}

	/**
	 * Reads one public metadata value as a string.
	 *
	 * @param int    $post_id Record database ID.
	 * @param string $key     Metadata key.
	 */
	private static function meta( int $post_id, string $key ): string {
		if ( in_array( $key, self::PRIVATE_FIELDS, true ) ) {
			return '';
		}
		$value = get_post_meta( $post_id, $key, true );
		return is_scalar( $value ) ? (string) $value : '';
	}

	/**
	 * Encodes a sanitized string-keyed payload for storage.
	 *
	 * @param array<string, mixed> $values Sanitized storage payload.
	 */
	private static function encode( array $values ): string {
		return (string) json_encode( $values, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- storage payload written without WordPress loaded in unit tests.
	}

	/**
	 * Converts boundary input to a string list.
	 *
	 * @param mixed $value Boundary input.
	 * @return array<int, string>
	 */
	private static function strings( mixed $value ): array {
		if ( ! is_array( $value ) ) {
			return array();
		}
		$list = array();
		foreach ( $value as $item ) {
			$text = self::text( $item );
			if ( '' !== $text ) {
				$list[] = $text;
			}
		}
		return $list;
	}

	/**
	 * Converts boundary input to string.
	 *
	 * @param mixed $value Boundary input.
	 */
	private static function text( mixed $value ): string {
		if ( is_string( $value ) ) {
			return $value;
		}
		if ( is_int( $value ) || is_float( $value ) ) {
			return (string) $value;
		}
		return '';
	}

	/**
	 * Converts boundary input to int.
	 *
	 * @param mixed $value Boundary input.
	 */
	private static function number( mixed $value ): int {
		return is_numeric( $value ) ? (int) $value : 0;
	}
}
