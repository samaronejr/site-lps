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

/** Owns the locale search index table and keeps it truthful on every transition. */
final class SearchIndex {
	public const TABLE_SUFFIX = 'lps_search_index';
	public const VERSION      = '1.2.0';

	private const VERSION_OPTION = 'lps_search_index_version';

	/**
	 * Record types carried by the search index.
	 *
	 * @var array<int, string>
	 */
	private const INDEXED_TYPES = array( 'lps_publication', 'lps_project', 'lps_person', 'lps_news', 'lps_opportunity', 'lps_event', 'lps_research_area' );

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
	 * A record can be `publish` in WordPress and still be withheld from every
	 * public surface: an archived record and an unapproved in-memoriam profile
	 * are both hidden by the public listing. Indexing either would make search
	 * the one place that leaks them, so the index applies the same gate.
	 *
	 * @param array<string, mixed> $record Portable record.
	 */
	public static function is_publicly_visible( array $record ): bool {
		if ( 'archived' === self::text( $record['_lps_state'] ?? '' ) ) {
			return false;
		}
		if ( 'in-memoriam' === self::text( $record['_lps_person_status'] ?? '' ) && ! self::flag( $record['_lps_in_memoriam_approved'] ?? '' ) ) {
			return false;
		}
		return true;
	}

	/**
	 * Reads one boundary value as a boolean flag.
	 *
	 * @param mixed $value Boundary input.
	 */
	private static function flag( mixed $value ): bool {
		if ( is_bool( $value ) ) {
			return $value;
		}
		if ( is_int( $value ) ) {
			return 0 !== $value;
		}
		return is_string( $value ) && in_array( strtolower( $value ), array( '1', 'true', 'yes' ), true );
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
		foreach ( array( 'doi', 'orcid', 'acronym', 'title' ) as $key ) {
			$value = SearchPolicy::normalize( self::text( $record[ $key ] ?? '' ) );
			if ( '' !== $value && ! in_array( $value, $exact, true ) ) {
				$exact[] = $value;
			}
		}
		$high     = SearchPolicy::normalize( implode( ' ', array( self::text( $record['title'] ?? '' ), self::text( $record['acronym'] ?? '' ), self::text( $record['doi'] ?? '' ), self::text( $record['orcid'] ?? '' ), self::text( $record['name'] ?? '' ) ) ) );
		$taxonomy = implode( ' ', self::strings( $record['taxonomy'] ?? array() ) );
		$medium   = SearchPolicy::normalize( self::text( $record['summary'] ?? '' ) . ' ' . $taxonomy );
		$low      = SearchPolicy::normalize( self::text( $record['body'] ?? '' ) );
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
		return array(
			'post_id'      => self::number( $row['post_id'] ?? 0 ),
			'locale'       => self::text( $row['locale'] ?? '' ),
			'post_type'    => self::text( $row['post_type'] ?? '' ),
			'exact'        => array_values( array_filter( explode( "\n", self::text( $row['exact_terms'] ?? '' ) ), static fn( string $term ): bool => '' !== $term ) ),
			'high'         => self::text( $row['high_terms'] ?? '' ),
			'medium'       => self::text( $row['medium_terms'] ?? '' ),
			'low'          => self::text( $row['low_terms'] ?? '' ),
			'facets'       => $facets,
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
	 * @return array{items: array<int, array<string, mixed>>, total: int, page: int, per_page: int, pages: int, error: string|null}
	 */
	public static function search( SearchStorage $storage, string $post_type, string $query, string $locale, array $facets, int $page = 1, int $per_page = SearchPolicy::PER_PAGE ): array {
		return SearchPolicy::search_records( $storage->rows_for( $locale, $post_type ), $query, $locale, $facets, $page, $per_page );
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
		if ( is_string( $installed ) && self::VERSION === $installed ) {
			return;
		}
		require_once dirname( __DIR__, 4 ) . '/wp-admin/includes/upgrade.php';
		dbDelta( self::schema_sql( $wpdb->prefix, $wpdb->get_charset_collate() ) );
		self::rebuild();
		update_option( self::VERSION_OPTION, self::VERSION, false );
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
		add_action( 'deleted_post', array( self::class, 'forget_post' ) );
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
	}

	/**
	 * Removes one deleted record from the index.
	 *
	 * @param int $post_id Record database ID.
	 */
	public static function forget_post( int $post_id ): void {
		$storage = self::storage();
		if ( null !== $storage ) {
			self::remove( $storage, $post_id );
		}
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
		return array(
			'post_id'                   => $post->ID,
			'status'                    => $post->post_status,
			'locale'                    => self::meta( $post->ID, '_lps_locale' ),
			'post_type'                 => $post->post_type,
			'title'                     => $post->post_title,
			'acronym'                   => self::meta( $post->ID, '_lps_acronym' ),
			'doi'                       => self::canonical_doi( $post ),
			'orcid'                     => self::meta( $post->ID, '_lps_orcid' ),
			'summary'                   => $post->post_excerpt,
			'taxonomy'                  => $terms,
			'body'                      => $post->post_content,
			'facets'                    => self::facets_for_post( $post, $terms ),
			'url'                       => (string) get_permalink( $post ),
			'published_at'              => $post->post_date_gmt,

			'_lps_state'                => self::meta( $post->ID, '_lps_state' ),
			'_lps_person_status'        => self::meta( $post->ID, '_lps_person_status' ),
			'_lps_in_memoriam_approved' => self::meta( $post->ID, '_lps_in_memoriam_approved' ),
		);
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
	 * Encodes facet values for storage.
	 *
	 * @param array<string, array<int, string>> $facets Sanitized facet values.
	 */
	private static function encode( array $facets ): string {
		return (string) json_encode( $facets, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- storage payload written without WordPress loaded in unit tests.
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
