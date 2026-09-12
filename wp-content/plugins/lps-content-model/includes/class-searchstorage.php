<?php
/**
 * Transactional storage boundary of the locale search index.
 *
 * @package LPS\ContentModel
 */

declare(strict_types=1);

namespace LPS\ContentModel;

/**
 * Transactional storage boundary of the locale search index.
 *
 * The interface exists so the index contract is provable without a database:
 * the WordPress adapter and the test double implement exactly the same calls.
 */
interface SearchStorage {
	/** Opens a transaction and reports whether it started. */
	public function begin(): bool;

	/** Commits the open transaction. */
	public function commit(): void;

	/** Reverts the open transaction. */
	public function rollback(): void;

	/**
	 * Removes every indexed row of one record.
	 *
	 * @param int $post_id Record database ID.
	 */
	public function delete_record( int $post_id ): bool;

	/**
	 * Writes one indexed row.
	 *
	 * @param array<string, mixed> $row Indexed row.
	 */
	public function insert_record( array $row ): bool;

	/**
	 * Reads the indexed rows of one locale and record type.
	 *
	 * @param string $locale    Supported locale slug.
	 * @param string $post_type Record type, empty for every indexed type.
	 * @return array<int, array<string, mixed>>
	 */
	public function rows_for( string $locale, string $post_type ): array;
}
