<?php
/**
 * WP-CLI export command.
 *
 * @package LPS\ContentModel
 */

declare(strict_types=1);

namespace LPS\ContentModel;

use WP_CLI;

/** Implements `wp lps export`. */
final class ExportCommand {
	/**
	 * Exports canonical data.
	 *
	 * @param array<int, string>         $args Positional arguments.
	 * @phpstan-param list<string> $args
	 * @param array<string, string|bool> $assoc_args Named arguments.
	 */
	public function __invoke( array $args, array $assoc_args ): void {
		unset( $args );
		$data    = Exporter::data();
		$encoded = wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		if ( ! is_string( $encoded ) ) {
			WP_CLI::error( '[lps_export_encoding_failed] Canonical export encoding failed.' );
		}
		$output = $assoc_args['output'] ?? false;
		if ( is_string( $output ) ) {
			$content = $encoded . "\n";
			$file    = new \SplFileObject( $output, 'c+' );
			if ( ! $file->flock( LOCK_EX ) ) {
				WP_CLI::error( '[lps_export_write_failed] Canonical export could not be locked.' );
			}
			try {
				if ( ! $file->ftruncate( 0 ) ) {
					WP_CLI::error( '[lps_export_write_failed] Canonical export could not be written.' );
				}
				$file->rewind();
				if ( strlen( $content ) !== $file->fwrite( $content ) ) {
					WP_CLI::error( '[lps_export_write_failed] Canonical export could not be written.' );
				}
			} finally {
				$file->flock( LOCK_UN );
			}
			CliContext::emit(
				array(
					'status'  => 'exported',
					'output'  => $output,
					'summary' => Exporter::summary(),
				)
			);
			return;
		}
		WP_CLI::line( $encoded );
	}
}
