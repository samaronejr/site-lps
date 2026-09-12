<?php
/**
 * Pure normalization and reconciliation policy for migration inputs.
 *
 * @package LPS\ContentModel
 */

declare(strict_types=1);

namespace LPS\ContentModel;

/** Rejects uncertain facts before any storage or file boundary is crossed. */
final class MigrationPolicy {
	/**
	 * Normalizes one record without adding absent facts.
	 *
	 * @param array<string, mixed> $record Parsed boundary record.
	 * @return array<string, mixed>
	 */
	public static function normalize_record( array $record ): array {
		$normalized              = $record;
		$normalized['record_id'] = strtolower( trim( self::text( $record['record_id'] ?? '' ) ) );
		if ( array_key_exists( 'source_url', $record ) ) {
			$normalized['source_url'] = self::normalize_url( self::text( $record['source_url'] ) );
		}
		$meta = is_array( $record['meta'] ?? null ) ? $record['meta'] : array();
		if ( array_key_exists( '_lps_doi', $meta ) ) {
			$meta['_lps_doi'] = RelationshipPolicy::normalize_doi( $meta['_lps_doi'] );
		}
		if ( array_key_exists( '_lps_publication_date', $meta ) ) {
			$meta['_lps_publication_date'] = self::normalize_date( self::text( $meta['_lps_publication_date'] ) );
		}
		$normalized['meta'] = $meta;
		return $normalized;
	}

	/**
	 * Returns a precise date/precision mismatch code.
	 *
	 * @param string $date      Date.
	 * @param string $precision Precision.
	 */
	public static function date_error( string $date, string $precision ): ?string {
		$patterns = array(
			'year'  => '/^\d{4}$/',
			'month' => '/^\d{4}-(0[1-9]|1[0-2])$/',
			'day'   => '/^\d{4}-(0[1-9]|1[0-2])-([0-2]\d|3[01])$/',
		);
		if ( ! isset( $patterns[ $precision ] ) || 1 !== preg_match( $patterns[ $precision ], $date ) ) {
			return 'lps_invalid_date_precision';
		}
		if ( 'day' === $precision ) {
			$parts = array_map( 'intval', explode( '-', $date ) );
			if ( ! checkdate( $parts[1], $parts[2], $parts[0] ) ) {
				return 'lps_invalid_date_precision';
			}
		}
		return null;
	}

	/**
	 * Requires an explicit human decision for every internal authorship match.
	 *
	 * @param array<array-key, mixed> $author Authorship candidate.
	 */
	public static function person_match_error( array $author ): ?string {
		return 'internal' === ( $author['kind'] ?? '' ) && true !== ( $author['manual_confirmation'] ?? false ) ? 'lps_person_match_confirmation_required' : null;
	}

	/**
	 * Returns the first media staging denial.
	 *
	 * @param array<string, mixed> $media Media candidate.
	 */
	public static function media_error( array $media ): ?string {
		if ( '' === trim( self::text( $media['path'] ?? '' ) ) ) {
			return 'lps_media_local_file_required';
		}
		if ( 'cleared' !== ( $media['rights_status'] ?? '' ) ) {
			return 'lps_media_rights_unknown';
		}
		foreach ( array( 'rights_holder', 'credit', 'license', 'source_url', 'checksum' ) as $field ) {
			if ( '' === trim( self::text( $media[ $field ] ?? '' ) ) ) {
				return 'lps_media_provenance_required';
			}
		}
		return null;
	}

	/**
	 * Interprets only bounded, previously cached Crossref outcomes.
	 *
	 * @param array<array-key, mixed> $cache Cache entry.
	 * @return array{code: string, fields: array<string, mixed>, labels: array<string, string>}
	 */
	public static function crossref_fields( array $cache ): array {
		$raw_fields = is_array( $cache['fields'] ?? null ) ? $cache['fields'] : array();
		$fields     = array();
		foreach ( $raw_fields as $field => $value ) {
			if ( is_string( $field ) ) {
				$fields[ $field ] = $value;
			}
		}
		if ( 'hit' !== ( $cache['status'] ?? '' ) ) {
			return array(
				'code'   => 'lps_crossref_unavailable',
				'fields' => array(),
				'labels' => array(),
			);
		}
		$labels = array();
		foreach ( array_keys( $fields ) as $field ) {
			$labels[ (string) $field ] = 'crossref-cache';
		}
		return array(
			'code'   => '',
			'fields' => $fields,
			'labels' => $labels,
		);
	}

	/**
	 * Produces explicit reviewed-field conflicts.
	 *
	 * @param array<string, mixed> $stored Stored values.
	 * @param array<string, mixed> $incoming Incoming values.
	 * @param array<int, string>   $reviewed_fields Protected fields.
	 * @return array<int, array{code: string, field: string, stored: mixed, incoming: mixed}>
	 */
	public static function reviewed_conflicts( array $stored, array $incoming, array $reviewed_fields ): array {
		$conflicts = array();
		foreach ( $reviewed_fields as $field ) {
			if ( array_key_exists( $field, $stored ) && array_key_exists( $field, $incoming ) && $stored[ $field ] !== $incoming[ $field ] ) {
				$conflicts[] = array(
					'code'     => 'lps_reviewed_field_conflict',
					'field'    => $field,
					'stored'   => $stored[ $field ],
					'incoming' => $incoming[ $field ],
				);
			}
		}
		return $conflicts;
	}

	/**
	 * Returns a reconciliation mismatch code.
	 *
	 * @param int $source_count Source count.
	 * @param int $target_count Target count.
	 */
	public static function reconciliation_error( int $source_count, int $target_count ): ?string {
		return $source_count === $target_count ? null : 'lps_reconciliation_count_mismatch';
	}

	/**
	 * Produces a stable JSON-compatible fingerprint.
	 *
	 * @param mixed $value Canonical value.
	 */
	public static function fingerprint( mixed $value ): string {
		$canonical = self::canonicalize( $value );
		$encoded   = wp_json_encode( $canonical, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		return hash( 'sha256', is_string( $encoded ) ? $encoded : '' );
	}

	/**
	 * Normalizes HTTP(S) URLs with sorted query keys and no fragments.
	 *
	 * @param string $url URL.
	 */
	public static function normalize_url( string $url ): string {
		$parts = wp_parse_url( trim( $url ) );
		if ( ! is_array( $parts ) ) {
			return '';
		}
		$scheme = strtolower( self::text( $parts['scheme'] ?? '' ) );
		$host   = strtolower( self::text( $parts['host'] ?? '' ) );
		if ( '' === $host || ! in_array( $scheme, array( 'http', 'https' ), true ) ) {
			return '';
		}
		$port_number = Policy::sanitize_integer( $parts['port'] ?? 0 );
		$port        = 0 !== $port_number && ! ( 443 === $port_number && 'https' === $scheme ) && ! ( 80 === $port_number && 'http' === $scheme ) ? ':' . $port_number : '';
		$path        = self::normalize_path( self::text( $parts['path'] ?? '/' ) );
		$query       = '';
		if ( isset( $parts['query'] ) ) {
			parse_str( self::text( $parts['query'] ), $values );
			ksort( $values );
			$query = array() === $values ? '' : '?' . http_build_query( $values, '', '&', PHP_QUERY_RFC3986 );
		}
		return $scheme . '://' . $host . $port . $path . $query;
	}

	/**
	 * Normalizes a local route path.
	 *
	 * @param string $path Path.
	 */
	public static function normalize_path( string $path ): string {
		$segments = array();
		foreach ( explode( '/', '/' . ltrim( $path, '/' ) ) as $segment ) {
			if ( '' === $segment || '.' === $segment ) {
				continue;
			}
			if ( '..' === $segment ) {
				array_pop( $segments );
				continue;
			}
			$segments[] = rawurlencode( rawurldecode( $segment ) );
		}
		return '/' . implode( '/', $segments ) . ( str_ends_with( $path, '/' ) && array() !== $segments ? '/' : '' );
	}

	/**
	 * Normalizes a partial date.
	 *
	 * @param string $date Date.
	 */
	private static function normalize_date( string $date ): string {
		return 1 === preg_match( '/^(\d{4})-(\d{1,2})$/', $date, $parts ) ? sprintf( '%s-%02d', $parts[1], (int) $parts[2] ) : $date;
	}

	/**
	 * Canonicalizes nested values.
	 *
	 * @param mixed $value Value.
	 * @return mixed
	 */
	private static function canonicalize( mixed $value ): mixed {
		if ( ! is_array( $value ) ) {
			return $value;
		}
		if ( ! array_is_list( $value ) ) {
			ksort( $value );
		}
		foreach ( $value as $key => $item ) {
			$value[ $key ] = self::canonicalize( $item );
		}
		return $value;
	}

	/**
	 * Converts a boundary scalar.
	 *
	 * @param mixed $value Value.
	 */
	private static function text( mixed $value ): string {
		return is_scalar( $value ) ? (string) $value : '';
	}
}
