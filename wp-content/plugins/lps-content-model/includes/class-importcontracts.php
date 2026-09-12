<?php
/**
 * Migration provenance metadata contracts.
 *
 * @package LPS\ContentModel
 */

declare(strict_types=1);

namespace LPS\ContentModel;

/** Owns fields preserved by deterministic imports. */
final class ImportContracts {
	/**
	 * Returns common migration metadata fields.
	 *
	 * @param callable $field Field factory.
	 * @phpstan-param callable(string, string, string): array{type: string, single: bool, description: string, show_in_rest: bool, sanitize_callback: callable(mixed): mixed, auth_callback: callable(): bool} $field
	 * @return array<string, array{type: string, single: bool, description: string, show_in_rest: bool, sanitize_callback: callable(mixed): mixed, auth_callback: callable(): bool}>
	 */
	public static function fields( callable $field ): array {
		return array(
			'_lps_import_source_id'       => $field( 'string', 'Migration source identifier', 'text' ),
			'_lps_import_source_url'      => $field( 'string', 'Migration source URL', 'url' ),
			'_lps_import_captured_at'     => $field( 'string', 'Migration capture timestamp', 'datetime' ),
			'_lps_import_checksum'        => $field( 'string', 'Migration source checksum', 'text' ),
			'_lps_import_rights'          => $field( 'string', 'Migration rights state', 'key' ),
			'_lps_import_review_state'    => $field( 'string', 'Migration review state', 'key' ),
			'_lps_import_fingerprint'     => $field( 'string', 'Normalized migration fingerprint', 'text' ),
			'_lps_import_reviewed_fields' => $field( 'array', 'Fields protected from silent import overwrite', 'string_array' ),
			'_lps_crossref_fields'        => $field( 'array', 'Fields sourced from deterministic Crossref cache', 'string_array' ),
			'_lps_crossref_cache_key'     => $field( 'string', 'Crossref cache identity', 'text' ),
			'_lps_crossref_cached_at'     => $field( 'string', 'Crossref cache capture timestamp', 'datetime' ),
		);
	}
}
