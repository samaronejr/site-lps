<?php
/**
 * Boundary parser for JSON, CSV, BibTeX, and DOI migration inputs.
 *
 * @package LPS\ContentModel
 */

declare(strict_types=1);

namespace LPS\ContentModel;

/**
 * Parses supported migration files into one package shape.
 *
 * @phpstan-type ImportRecord array<string, mixed>
 * @phpstan-type ImportPackageShape array{schema_version: string, records: list<ImportRecord>, relationships: list<array<string, mixed>>, authorships: list<array<string, mixed>>, media: list<array<string, mixed>>, redirects: list<array<string, mixed>>}
 */
final class ImportPackage {
	/**
	 * Parses a migration source file.
	 *
	 * @param string $path Input path.
	 * @return array Parsed package.
	 * @phpstan-return ImportPackageShape
	 * @throws \InvalidArgumentException When the input cannot be parsed.
	 */
	public static function from_file( string $path ): array {
		if ( ! is_file( $path ) || ! is_readable( $path ) ) {
			throw new \InvalidArgumentException( 'lps_import_input_unreadable' );
		}
		$extension = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
		$content   = '';
		$file      = new \SplFileObject( $path, 'r' );
		while ( ! $file->eof() ) {
			$content .= $file->fgets();
		}
		$package = match ( $extension ) {
			'json' => self::json( $content ),
			'csv' => self::csv( $content ),
			'bib', 'bibtex' => self::bibtex( $content ),
			'doi', 'txt' => self::dois( $content ),
			default => throw new \InvalidArgumentException( 'lps_import_format_unsupported' ),
		};
		foreach ( $package['records'] as $index => $record ) {
			$package['records'][ $index ] = MigrationPolicy::normalize_record( $record );
		}
		return $package;
	}

	/**
	 * Returns all pre-mutation package errors and quarantines.
	 *
	 * @param array $package Parsed package.
	 * @phpstan-param ImportPackageShape $package Parsed package.
	 * @return array<int, array{code: string, path: string}>
	 */
	public static function errors( array $package ): array {
		$errors     = array();
		$record_ids = array();
		$dois       = array();
		$records    = $package['records'];
		foreach ( $records as $index => $record ) {
			$id   = self::text( $record['record_id'] ?? '' );
			$type = self::text( $record['type'] ?? '' );
			if ( '' === Policy::sanitize_record_id( $id ) || ! isset( Contracts::post_types()[ $type ] ) || isset( $record_ids[ $id ] ) ) {
				$errors[] = self::error( isset( $record_ids[ $id ] ) ? 'lps_duplicate_record_id' : 'lps_import_record_invalid', "records.$index" );
			}
			$record_ids[ $id ] = true;
			$meta              = is_array( $record['meta'] ?? null ) ? $record['meta'] : array();
			if ( 'lps_publication' === $type ) {
				$doi = self::text( $meta['_lps_doi'] ?? '' );
				if ( '' !== $doi && isset( $dois[ $doi ] ) ) {
					$errors[] = self::error( 'lps_duplicate_doi', "records.$index.meta._lps_doi" );
				}
				$dois[ $doi ] = true;
				$date         = self::text( $meta['_lps_publication_date'] ?? '' );
				$precision    = self::text( $meta['_lps_date_precision'] ?? '' );
				if ( '' !== $date && null !== MigrationPolicy::date_error( $date, $precision ) ) {
					$errors[] = self::error( 'lps_invalid_date_precision', "records.$index.meta._lps_publication_date" );
				}
			}
		}
		$authorships = $package['authorships'];
		foreach ( $authorships as $group_index => $group ) {
			$authors = self::rows( $group['authors'] ?? null );
			foreach ( $authors as $author_index => $author ) {
				$code = MigrationPolicy::person_match_error( $author );
				if ( null !== $code ) {
					$errors[] = self::error( $code, "authorships.$group_index.authors.$author_index" );
				}
			}
		}
		$media = $package['media'];
		foreach ( $media as $index => $asset ) {
			$code = MigrationPolicy::media_error( $asset );
			if ( null !== $code ) {
				$errors[] = self::error( $code, "media.$index" );
			}
		}
		$redirects = $package['redirects'];
		foreach ( RedirectPolicy::verify( $redirects, home_url( '/' ) ) as $error ) {
			$errors[] = self::error( $error['code'], 'redirects.' . $error['source'] );
		}
		return $errors;
	}

	/**
	 * Parses JSON.
	 *
	 * @param string $content Input bytes.
	 * @return array Parsed package.
	 * @phpstan-return ImportPackageShape
	 * @throws \InvalidArgumentException When JSON is invalid.
	 */
	private static function json( string $content ): array {
		$decoded = json_decode( $content, true );
		if ( ! is_array( $decoded ) || '1.0' !== ( $decoded['schema_version'] ?? '' ) ) {
			throw new \InvalidArgumentException( 'lps_import_json_invalid' );
		}
		return self::shape( self::object( $decoded ) );
	}

	/**
	 * Parses CSV.
	 *
	 * @param string $content Input bytes.
	 * @return array Parsed package.
	 * @phpstan-return ImportPackageShape
	 * @throws \InvalidArgumentException When CSV is invalid.
	 */
	private static function csv( string $content ): array {
		$stream = new \SplFileObject( 'php://temp', 'w+' );
		$stream->fwrite( $content );
		$stream->rewind();
		$headers = $stream->fgetcsv( ',', '"', '\\' );
		if ( ! is_array( $headers ) ) {
			throw new \InvalidArgumentException( 'lps_import_csv_invalid' );
		}
		$keys    = self::csv_cells( $headers );
		$records = array();
		while ( ! $stream->eof() ) {
			$values = $stream->fgetcsv( ',', '"', '\\' );
			if ( ! is_array( $values ) || array( null ) === $values ) {
				continue;
			}
			$cells = self::csv_cells( $values );
			if ( count( $cells ) !== count( $keys ) ) {
				throw new \InvalidArgumentException( 'lps_import_csv_invalid' );
			}
			$row         = array_combine( $keys, $cells );
			$meta        = json_decode( self::text( $row['meta_json'] ?? '{}' ), true );
			$row['meta'] = is_array( $meta ) ? self::object( $meta ) : array();
			unset( $row['meta_json'] );
			$records[] = $row;
		}
		return self::shape(
			array(
				'schema_version' => '1.0',
				'records'        => $records,
			)
		);
	}

	/**
	 * Parses BibTeX.
	 *
	 * @param string $content Input bytes.
	 * @return array Parsed package.
	 * @phpstan-return ImportPackageShape
	 */
	private static function bibtex( string $content ): array {
		preg_match_all( '/@[a-zA-Z]+\s*\{\s*([^,]+),([\s\S]*?)\n\}/', $content, $entries, PREG_SET_ORDER );
		$records = array();
		foreach ( $entries as $entry ) {
			preg_match_all( '/(title|doi|year|month)\s*=\s*[\{\"]([^\}\"]+)[\}\"]/i', $entry[2], $fields, PREG_SET_ORDER );
			$values = array();
			foreach ( $fields as $field ) {
				$values[ strtolower( $field[1] ) ] = trim( $field[2] );
			}
			$key       = sanitize_key( trim( $entry[1] ) );
			$date      = self::text( $values['year'] ?? '' ) . ( isset( $values['month'] ) ? '-' . $values['month'] : '' );
			$records[] = array(
				'source_id' => 'bibtex:' . $key,
				'type'      => 'lps_publication',
				'record_id' => self::record_id( 'publication', 'bibtex:' . $key ),
				'title'     => self::text( $values['title'] ?? '' ),
				'locale'    => 'und',
				'state'     => 'draft',
				'meta'      => array(
					'_lps_doi'              => self::text( $values['doi'] ?? '' ),
					'_lps_publication_date' => $date,
					'_lps_date_precision'   => isset( $values['month'] ) ? 'month' : 'year',
				),
			);
		}
		return self::shape(
			array(
				'schema_version' => '1.0',
				'records'        => $records,
			)
		);
	}

	/**
	 * Parses a DOI list.
	 *
	 * @param string $content Input bytes.
	 * @return array Parsed package.
	 * @phpstan-return ImportPackageShape
	 */
	private static function dois( string $content ): array {
		$records = array();
		$lines   = preg_split( '/\R/', $content );
		foreach ( false === $lines ? array() : $lines as $line ) {
			$doi = RelationshipPolicy::normalize_doi( $line );
			if ( '' !== $doi ) {
				$key       = sanitize_key( str_replace( '/', '-', $doi ) );
				$records[] = array(
					'source_id' => 'doi:' . $doi,
					'type'      => 'lps_publication',
					'record_id' => self::record_id( 'publication', $key ),
					'title'     => $doi,
					'locale'    => 'und',
					'state'     => 'draft',
					'meta'      => array( '_lps_doi' => $doi ),
					'crossref'  => array(
						'status'    => 'unavailable',
						'cache_key' => $doi,
						'fields'    => array(),
					),
				);
			}
		}
		return self::shape(
			array(
				'schema_version' => '1.0',
				'records'        => $records,
			)
		);
	}

	/**
	 * Shapes parsed values.
	 *
	 * @param array<string, mixed> $input Parsed values.
	 * @return array Parsed package.
	 * @phpstan-return ImportPackageShape
	 */
	private static function shape( array $input ): array {
		/**
		 * Package with every collection initialized.
		 *
		 * @var ImportPackageShape $result
		 */
		$result = array(
			'schema_version' => '1.0',
			'records'        => array(),
			'relationships'  => array(),
			'authorships'    => array(),
			'media'          => array(),
			'redirects'      => array(),
		);
		foreach ( array( 'records', 'relationships', 'authorships', 'media', 'redirects' ) as $key ) {
			$result[ $key ] = self::rows( $input[ $key ] ?? null );
		}
		return $result;
	}

	/**
	 * Builds an input error.
	 *
	 * @param string $code Error code.
	 * @param string $path Input path.
	 * @return array{code: string, path: string}
	 */
	private static function error( string $code, string $path ): array {
		return array(
			'code' => $code,
			'path' => $path,
		);
	}

	/**
	 * Converts CSV parser cells to strings.
	 *
	 * @param array<int, string|null> $values Parser cells.
	 * @phpstan-param list<string|null> $values
	 * @return list<string>
	 */
	private static function csv_cells( array $values ): array {
		return array_map( static fn ( ?string $value ): string => $value ?? '', $values );
	}

	/**
	 * Parses an object list.
	 *
	 * @param mixed $value Boundary value.
	 * @return array<int, array<string, mixed>> Object rows.
	 * @phpstan-return list<array<string, mixed>>
	 */
	private static function rows( mixed $value ): array {
		if ( ! is_array( $value ) ) {
			return array();
		}
		$rows = array();
		foreach ( $value as $row ) {
			if ( is_array( $row ) ) {
				$rows[] = self::object( $row );
			}
		}
		return $rows;
	}

	/**
	 * Parses one string-keyed object.
	 *
	 * @param array<array-key, mixed> $value Boundary object.
	 * @return array<string, mixed>
	 */
	private static function object( array $value ): array {
		$object = array();
		foreach ( $value as $key => $item ) {
			if ( is_string( $key ) ) {
				$object[ $key ] = $item;
			}
		}
		return $object;
	}

	/**
	 * Builds a deterministic record ID.
	 *
	 * @param string $type     Record type.
	 * @param string $identity Source identity.
	 */
	private static function record_id( string $type, string $identity ): string {
		$hash = hash( 'sha256', $identity );
		$uuid = substr( $hash, 0, 8 ) . '-' . substr( $hash, 8, 4 ) . '-4' . substr( $hash, 13, 3 ) . '-8' . substr( $hash, 17, 3 ) . '-' . substr( $hash, 20, 12 );
		return 'lps:' . $type . ':' . $uuid;
	}

	/**
	 * Converts a boundary scalar to text.
	 *
	 * @param mixed $value Boundary value.
	 */
	private static function text( mixed $value ): string {
		return is_scalar( $value ) ? trim( (string) $value ) : '';
	}
}
