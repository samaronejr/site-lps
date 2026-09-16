<?php
/**
 * WP-CLI input and output context.
 *
 * @package LPS\ContentModel
 */

declare(strict_types=1);

namespace LPS\ContentModel;

use WP_CLI;
use WP_Error;

/**
 * Resolves command inputs and deterministic output behavior.
 *
 * @phpstan-import-type ImportPackageShape from ImportPackage
 * @phpstan-type CliContextShape array{package: ImportPackageShape, assets_dir: string, inventory_dir: string}
 */
final class CliContext {
	/**
	 * Loads command context.
	 *
	 * @param array<string, string|bool> $assoc_args Named arguments.
	 * @param array<int, string>         $args       Positional arguments.
	 * @return CliContextShape
	 */
	public static function load( array $assoc_args, array $args = array() ): array {
		$input     = self::input_path( $assoc_args, $args );
		$assets    = self::argument( $assoc_args, 'assets-dir', 'LPS_IMPORT_ASSETS_DIR', dirname( __DIR__ ) . '/tests/fixtures' );
		$inventory = self::argument( $assoc_args, 'inventory', 'LPS_INVENTORY_DIR', dirname( __DIR__, 4 ) . '/content/inventory' );
		try {
			$package = ImportPackage::from_file( $input );
		} catch ( \InvalidArgumentException $error ) {
			WP_CLI::error( '[' . $error->getMessage() . '] Migration input could not be parsed.' );
		}
		return array(
			'package'       => $package,
			'assets_dir'    => $assets,
			'inventory_dir' => $inventory,
		);
	}

	/**
	 * Emits JSON.
	 *
	 * @param array<array-key, bool|float|int|string|array<array-key, mixed>|null> $value JSON value.
	 */
	public static function emit( array $value ): void {
		$encoded = wp_json_encode( $value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		WP_CLI::line( is_string( $encoded ) ? $encoded : '{}' );
	}

	/**
	 * Terminates with a typed error.
	 *
	 * @param WP_Error $error Error.
	 */
	public static function fail( WP_Error $error ): never {
		$data = $error->get_error_data();
		self::emit(
			array(
				'status'  => 'failed',
				'code'    => $error->get_error_code(),
				'details' => is_array( $data ) ? $data : array(),
			)
		);
		WP_CLI::halt( 1 );
	}

	/** Hashes canonical database and upload state. */
	public static function state_hash(): string {
		$upload = wp_upload_dir();
		$files  = array();
		$base   = $upload['basedir'];
		if ( is_dir( $base ) ) {
			$iterator = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $base, \FilesystemIterator::SKIP_DOTS ) );
			foreach ( $iterator as $file ) {
				if ( $file instanceof \SplFileInfo && $file->isFile() ) {
					$checksum = hash_file( 'sha256', $file->getPathname() );
					$files[ substr( $file->getPathname(), strlen( $base ) ) ] = is_string( $checksum ) ? $checksum : '';
				}
			}
		}
		ksort( $files );
		return MigrationPolicy::fingerprint(
			array(
				'database' => Exporter::data(),
				'files'    => $files,
			)
		);
	}

	/**
	 * Resolves the package path from --input, a positional argument, the
	 * environment constant, or the bundled fixture fallback.
	 *
	 * @param array<string, string|bool> $assoc_args Named arguments.
	 * @param array<int, string>         $args       Positional arguments.
	 */
	public static function input_path( array $assoc_args, array $args = array() ): string {
		$named = self::argument( $assoc_args, 'input', 'LPS_IMPORT_INPUT', '' );
		if ( '' !== $named ) {
			return $named;
		}
		$positional = self::text( $args[0] ?? '' );
		if ( '' !== $positional ) {
			return $positional;
		}
		return dirname( __DIR__ ) . '/tests/fixtures/todo12-migration.json';
	}

	/**
	 * Resolves one argument.
	 *
	 * @param array<string, string|bool> $args     Arguments.
	 * @param string                     $key      Argument key.
	 * @param string                     $constant Fallback constant.
	 * @param string                     $fallback Default value.
	 */
	private static function argument( array $args, string $key, string $constant, string $fallback ): string {
		$value = $args[ $key ] ?? false;
		if ( is_string( $value ) ) {
			return $value;
		}
		$constant_value = defined( $constant ) ? constant( $constant ) : false;
		return is_string( $constant_value ) ? $constant_value : $fallback;
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
