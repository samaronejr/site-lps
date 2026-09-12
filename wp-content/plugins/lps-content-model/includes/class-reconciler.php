<?php
/**
 * Inventory and target reconciliation reports.
 *
 * @package LPS\ContentModel
 */

declare(strict_types=1);

namespace LPS\ContentModel;

/**
 * Produces read-only source and target count receipts.
 *
 * @phpstan-import-type ImportPackageShape from ImportPackage
 * @phpstan-type ReconciliationIssue array{code: string, type: string, source: int|string, target: int|string}
 * @phpstan-type ReconciliationResult array{status: string, expected: array<string, int>, actual: array{counts: array<string, int>, hash: string}, errors: list<ReconciliationIssue>}
 */
final class Reconciler {
	/**
	 * Summarizes inventory coverage.
	 *
	 * @param string $directory Inventory directory.
	 * @param array  $package Parsed package.
	 * @phpstan-param ImportPackageShape $package Package.
	 * @return array{files: array<string, int>, dispositions: array<string, int>, package_source_matches: int, inventory_hash: string}
	 */
	public static function inventory( string $directory, array $package ): array {
		$files        = array( 'records.csv', 'urls.csv', 'assets.csv' );
		$counts       = array();
		$dispositions = array();
		$source_ids   = array();
		foreach ( $files as $file ) {
			$path            = rtrim( $directory, '/' ) . '/' . $file;
			$rows            = self::csv( $path );
			$counts[ $file ] = count( $rows );
			foreach ( $rows as $row ) {
				$disposition                  = self::text( $row['disposition'] ?? '' );
				$dispositions[ $disposition ] = ( $dispositions[ $disposition ] ?? 0 ) + 1;
				$id                           = self::text( $row['id'] ?? '' );
				if ( '' !== $id ) {
					$source_ids[ $id ] = true;
				}
			}
		}
		ksort( $dispositions );
		$matched = 0;
		foreach ( $package['records'] as $record ) {
			if ( isset( $source_ids[ self::text( $record['source_id'] ?? '' ) ] ) ) {
				++$matched;
			}
		}
		foreach ( $package['media'] as $asset ) {
			if ( isset( $source_ids[ self::text( $asset['source_id'] ?? '' ) ] ) ) {
				++$matched;
			}
		}
		return array(
			'files'                  => $counts,
			'dispositions'           => $dispositions,
			'package_source_matches' => $matched,
			'inventory_hash'         => self::directory_hash( $directory, $files ),
		);
	}

	/**
	 * Reconciles package and target.
	 *
	 * @param array  $package Parsed package.
	 * @phpstan-param ImportPackageShape $package Package.
	 * @param string $assets_dir Asset directory.
	 * @return array{status: string, expected: array<string, int>, actual: array{counts: array<string, int>, hash: string}, errors: list<array<string, int|string>>}
	 * @phpstan-return ReconciliationResult
	 */
	public static function target( array $package, string $assets_dir ): array {
		$plan             = ImportPlanner::plan( $package, $assets_dir );
		$actual           = Exporter::summary();
		$expected_authors = 0;
		foreach ( $package['authorships'] as $group ) {
			$expected_authors += is_array( $group['authors'] ?? null ) ? count( $group['authors'] ) : 0;
		}
		$expected = array(
			'records'       => (int) $plan['counts']['records'],
			'relationships' => (int) $plan['counts']['relationships'],
			'authorships'   => $expected_authors,
			'media'         => (int) $plan['counts']['media'],
			'redirects'     => (int) $plan['counts']['redirects'],
		);
		/**
		 * Reconciliation mismatches.
		 *
		 * @var list<ReconciliationIssue> $errors
		 */
		$errors = array();
		foreach ( $expected as $type => $count ) {
			if ( $actual['counts'][ $type ] !== $count ) {
				$errors[] = array(
					'code'   => 'lps_reconciliation_count_mismatch',
					'type'   => $type,
					'source' => $count,
					'target' => $actual['counts'][ $type ],
				);
			}
		}
		foreach ( $plan['records'] as $record ) {
			$post_id     = ImportRepository::post_id( self::text( $record['record_id'] ?? '' ) );
			$fingerprint = 0 < $post_id ? self::text( get_post_meta( $post_id, '_lps_import_fingerprint', true ) ) : '';
			if ( '' === $fingerprint || ! hash_equals( MigrationPolicy::fingerprint( $record ), $fingerprint ) ) {
				$errors[] = array(
					'code'   => 'lps_reconciliation_record_mismatch',
					'type'   => self::text( $record['record_id'] ?? '' ),
					'source' => MigrationPolicy::fingerprint( $record ),
					'target' => $fingerprint,
				);
			}
		}
		$redirect_errors = RedirectPolicy::verify( Exporter::data()['redirects'], home_url( '/' ) );
		foreach ( $redirect_errors as $error ) {
			$errors[] = array(
				'code'   => $error['code'],
				'type'   => $error['source'],
				'source' => 1,
				'target' => 0,
			);
		}
		return array(
			'status'   => array() === $errors ? 'verified' : 'failed',
			'expected' => $expected,
			'actual'   => $actual,
			'errors'   => $errors,
		);
	}

	/**
	 * Reads an inventory CSV.
	 *
	 * @param string $path CSV path.
	 * @return array<int, array<string, string>> Rows.
	 * @phpstan-return list<array<string, string>>
	 */
	private static function csv( string $path ): array {
		if ( ! is_file( $path ) || ! is_readable( $path ) ) {
			return array();
		}
		$file    = new \SplFileObject( $path, 'r' );
		$headers = $file->fgetcsv( ',', '"', '\\' );
		if ( ! is_array( $headers ) ) {
			return array();
		}
		$keys = self::csv_cells( $headers );
		$rows = array();
		while ( ! $file->eof() ) {
			$values = $file->fgetcsv( ',', '"', '\\' );
			if ( ! is_array( $values ) || array( null ) === $values ) {
				continue;
			}
			$cells = self::csv_cells( $values );
			if ( count( $keys ) !== count( $cells ) ) {
				continue;
			}
			$rows[] = array_combine( $keys, $cells );
		}
		return $rows;
	}

	/**
	 * Hashes inventory inputs.
	 *
	 * @param string             $directory Inventory directory.
	 * @param array<int, string> $files Files.
	 * @phpstan-param list<string> $files Files.
	 */
	private static function directory_hash( string $directory, array $files ): string {
		$hashes = array();
		foreach ( $files as $file ) {
			$path            = rtrim( $directory, '/' ) . '/' . $file;
			$hashes[ $file ] = is_file( $path ) ? hash_file( 'sha256', $path ) : '';
		}
		return MigrationPolicy::fingerprint( $hashes );
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
	 * Converts a boundary scalar to text.
	 *
	 * @param mixed $value Value.
	 */
	private static function text( mixed $value ): string {
		return is_scalar( $value ) ? trim( (string) $value ) : '';
	}
}
