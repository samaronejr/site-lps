<?php
/**
 * Transaction-recording search storage for index contracts.
 *
 * @package LPS\ContentModel\Tests
 */

declare(strict_types=1);

namespace LPS\ContentModel\Tests;

use LPS\ContentModel\SearchIndex;
use LPS\ContentModel\SearchStorage;

require_once dirname( __DIR__ ) . '/includes/class-searchstorage.php';

/** In-memory index storage that records every transactional call. */
final class RecordingSearchStorage implements SearchStorage {
	/**
	 * Stored index rows keyed by record database ID.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	public array $rows = array();

	/**
	 * Ordered transaction and write calls.
	 *
	 * @var array<int, string>
	 */
	public array $calls = array();

	/**
	 * Whether the next insert must fail.
	 *
	 * @var bool
	 */
	public bool $fail_insert = false;

	/**
	 * Whether the next delete must fail.
	 *
	 * @var bool
	 */
	public bool $fail_delete = false;

	/** Opens a transaction and reports whether it started. */
	public function begin(): bool {
		$this->calls[] = 'begin';
		return true;
	}

	/** Commits the open transaction. */
	public function commit(): void {
		$this->calls[] = 'commit';
	}

	/** Reverts the open transaction. */
	public function rollback(): void {
		$this->calls[] = 'rollback';
	}

	/**
	 * Removes every indexed row of one record.
	 *
	 * @param int $post_id Record database ID.
	 */
	public function delete_record( int $post_id ): bool {
		$this->calls[] = 'delete:' . $post_id;
		if ( $this->fail_delete ) {
			return false;
		}
		unset( $this->rows[ $post_id ] );
		return true;
	}

	/**
	 * Writes one indexed row.
	 *
	 * @param array<string, mixed> $row Indexed row.
	 */
	public function insert_record( array $row ): bool {
		$candidate     = $row['post_id'] ?? 0;
		$post_id       = is_numeric( $candidate ) ? (int) $candidate : 0;
		$this->calls[] = 'insert:' . $post_id;
		if ( $this->fail_insert ) {
			return false;
		}
		$this->rows[ $post_id ] = $row;
		return true;
	}

	/**
	 * Reads the indexed rows of one locale and record type.
	 *
	 * @param string $locale    Supported locale slug.
	 * @param string $post_type Record type, empty for every indexed type.
	 * @return array<int, array<string, mixed>>
	 */
	public function rows_for( string $locale, string $post_type ): array {
		$rows = array();
		foreach ( $this->rows as $row ) {
			if ( ( $row['locale'] ?? '' ) !== $locale ) {
				continue;
			}
			if ( '' !== $post_type && ( $row['post_type'] ?? '' ) !== $post_type ) {
				continue;
			}
			$rows[] = SearchIndex::hydrate( $row );
		}
		return $rows;
	}
}
