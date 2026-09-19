<?php
/**
 * WordPress-backed locale search index storage.
 *
 * @package LPS\ContentModel
 */

declare(strict_types=1);

namespace LPS\ContentModel;

use wpdb;

require_once __DIR__ . '/class-searchstorage.php';
require_once __DIR__ . '/class-searchindex.php';

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The search index is a custom table written transactionally and read fresh.
/** WordPress-backed search index storage. */
final class WpdbSearchStorage implements SearchStorage {
	/**
	 * WordPress database adapter.
	 *
	 * @var wpdb
	 */
	private wpdb $database;

	/**
	 * Fully qualified index table name.
	 *
	 * @var string
	 */
	private string $table;

	/**
	 * Whether this adapter opened the current transaction.
	 *
	 * @var bool
	 */
	private bool $started = false;

	/**
	 * Binds the adapter to one database connection.
	 *
	 * @param wpdb $database WordPress database adapter.
	 */
	public function __construct( wpdb $database ) {
		$this->database = $database;
		$this->table    = $database->prefix . SearchIndex::TABLE_SUFFIX;
	}

	/** Opens a transaction and reports whether it started. */
	public function begin(): bool {
		$this->started = false !== $this->database->query( 'START TRANSACTION' );
		return $this->started;
	}

	/** Commits the open transaction. */
	public function commit(): void {
		if ( $this->started ) {
			$this->database->query( 'COMMIT' );
			$this->started = false;
		}
	}

	/** Reverts the open transaction. */
	public function rollback(): void {
		if ( $this->started ) {
			$this->database->query( 'ROLLBACK' );
			$this->started = false;
		}
	}

	/**
	 * Removes every indexed row of one record.
	 *
	 * @param int $post_id Record database ID.
	 */
	public function delete_record( int $post_id ): bool {
		return false !== $this->database->delete( $this->table, array( 'post_id' => $post_id ), array( '%d' ) );
	}

	/**
	 * Writes one indexed row.
	 *
	 * @param array<string, mixed> $row Indexed row.
	 */
	public function insert_record( array $row ): bool {
		return false !== $this->database->insert(
			$this->table,
			array(
				'post_id'      => $row['post_id'],
				'locale'       => $row['locale'],
				'post_type'    => $row['post_type'],
				'exact_terms'  => $row['exact_terms'],
				'high_terms'   => $row['high_terms'],
				'medium_terms' => $row['medium_terms'],
				'low_terms'    => $row['low_terms'],
				'facets'       => $row['facets'],
				'lifecycle'    => $row['lifecycle'] ?? '',
				'title'        => $row['title'],
				'summary'      => $row['summary'],
				'url'          => $row['url'],
				'published_at' => $row['published_at'],
			),
			array( '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
		);
	}

	/**
	 * Reads the indexed rows of one locale and record type.
	 *
	 * @param string $locale    Supported locale slug.
	 * @param string $post_type Record type, empty for every indexed type.
	 * @return array<int, array<string, mixed>>
	 */
	public function rows_for( string $locale, string $post_type ): array {
		if ( '' === $post_type ) {
			$results = $this->database->get_results(
				$this->database->prepare( 'SELECT * FROM %i WHERE locale = %s ORDER BY post_id ASC', $this->table, $locale ),
				'ARRAY_A'
			);
		} else {
			$results = $this->database->get_results(
				$this->database->prepare( 'SELECT * FROM %i WHERE locale = %s AND post_type = %s ORDER BY post_id ASC', $this->table, $locale, $post_type ),
				'ARRAY_A'
			);
		}
		$rows = array();
		if ( ! is_array( $results ) ) {
			return $rows;
		}
		foreach ( $results as $result ) {
			$row = array();
			foreach ( $result as $column => $value ) {
				$row[ (string) $column ] = $value;
			}
			$rows[] = SearchIndex::hydrate( $row );
		}
		return $rows;
	}
}
// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
