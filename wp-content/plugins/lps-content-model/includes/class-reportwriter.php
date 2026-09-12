<?php
/**
 * Deterministic migration report artifacts.
 *
 * @package LPS\ContentModel
 */

declare(strict_types=1);

namespace LPS\ContentModel;

/**
 * Writes machine-readable reports only when their bytes differ.
 *
 * @phpstan-import-type ImportPlan from ImportPlanner
 * @phpstan-import-type ReconciliationResult from Reconciler
 */
final class ReportWriter {
	/**
	 * Writes report artifacts.
	 *
	 * @param string $directory      Report directory.
	 * @param array  $plan           Import plan.
	 * @param array  $reconciliation Reconciliation result.
	 * @phpstan-param ImportPlan $plan
	 * @phpstan-param ReconciliationResult $reconciliation
	 * @return array<string, string>
	 * @throws \RuntimeException When a report artifact cannot be written.
	 */
	public static function write( string $directory, array $plan, array $reconciliation ): array {
		if ( ! wp_mkdir_p( $directory ) ) {
			throw new \RuntimeException( 'lps_report_directory_unavailable' );
		}
		$artifacts = array(
			'reconciliation.json' => self::json( $reconciliation ),
			'quarantine.json'     => self::json( $plan['quarantine'] ),
			'conflicts.json'      => self::json( $plan['conflicts'] ),
			'failures.json'       => self::json( $plan['errors'] ),
			'reconciliation.csv'  => self::csv( $reconciliation ),
		);
		$checksums = array();
		foreach ( $artifacts as $name => $content ) {
			$path    = rtrim( $directory, '/' ) . '/' . $name;
			$current = self::contents( $path );
			if ( null === $current || ! hash_equals( hash( 'sha256', $current ), hash( 'sha256', $content ) ) ) {
				self::write_contents( $path, $content );
			}
			$checksums[ $name ] = hash( 'sha256', $content );
		}
		ksort( $checksums );
		$checksum_path    = rtrim( $directory, '/' ) . '/checksums.json';
		$checksum_content = self::json( $checksums );
		$current          = self::contents( $checksum_path );
		if ( null === $current || ! hash_equals( hash( 'sha256', $current ), hash( 'sha256', $checksum_content ) ) ) {
			self::write_contents( $checksum_path, $checksum_content );
		}
		return $checksums;
	}

	/**
	 * Reads a report artifact.
	 *
	 * @param string $path Artifact path.
	 */
	private static function contents( string $path ): ?string {
		if ( ! is_file( $path ) || ! is_readable( $path ) ) {
			return null;
		}
		$file    = new \SplFileObject( $path, 'r' );
		$content = '';
		while ( ! $file->eof() ) {
			$content .= $file->fgets();
		}
		return $content;
	}

	/**
	 * Writes a locked report artifact.
	 *
	 * @param string $path    Artifact path.
	 * @param string $content Artifact bytes.
	 * @throws \RuntimeException When the artifact cannot be written completely.
	 */
	private static function write_contents( string $path, string $content ): void {
		$file = new \SplFileObject( $path, 'c+' );
		if ( ! $file->flock( LOCK_EX ) ) {
			throw new \RuntimeException( 'lps_report_file_lock_failed' );
		}
		try {
			if ( ! $file->ftruncate( 0 ) ) {
				throw new \RuntimeException( 'lps_report_write_failed' );
			}
			$file->rewind();
			if ( strlen( $content ) !== $file->fwrite( $content ) ) {
				throw new \RuntimeException( 'lps_report_write_failed' );
			}
		} finally {
			$file->flock( LOCK_UN );
		}
	}

	/**
	 * Encodes deterministic JSON.
	 *
	 * @param mixed $value Value.
	 */
	private static function json( mixed $value ): string {
		$encoded = wp_json_encode( $value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		return ( is_string( $encoded ) ? $encoded : 'null' ) . "\n";
	}

	/**
	 * Encodes reconciliation CSV.
	 *
	 * @param array $reconciliation Reconciliation result.
	 * @phpstan-param ReconciliationResult $reconciliation
	 */
	private static function csv( array $reconciliation ): string {
		$lines    = array( 'type,source_count,target_count,status' );
		$expected = $reconciliation['expected'];
		$actual   = $reconciliation['actual']['counts'];
		foreach ( $expected as $type => $source ) {
			$target  = $actual[ $type ] ?? 0;
			$lines[] = implode( ',', array( $type, (int) $source, $target, $source === $target ? 'matched' : 'mismatch' ) );
		}
		return implode( "\n", $lines ) . "\n";
	}
}
