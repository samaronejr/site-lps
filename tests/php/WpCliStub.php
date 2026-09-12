<?php
/**
 * PHPStan-only declaration of the WP-CLI static API used by the plugin.
 *
 * @package LPS\Tests
 */

declare(strict_types=1);

/** Runtime methods provided by wp-cli/wp-cli. */
final class WP_CLI {
	/** @param callable|class-string $callable */
	public static function add_command( string $name, callable|string $callable ): void {}

	public static function line( string $message ): void {}

	public static function error( string $message ): never {
		throw new RuntimeException( $message );
	}

	public static function halt( int $exit_code ): never {
		throw new RuntimeException( (string) $exit_code );
	}
}
