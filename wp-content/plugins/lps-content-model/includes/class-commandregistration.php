<?php
/**
 * WP-CLI command registration.
 *
 * @package LPS\ContentModel
 */

declare(strict_types=1);

namespace LPS\ContentModel;

/** Registers migration commands only inside WP-CLI. */
final class CommandRegistration {
	/** Registers WP-CLI commands. */
	public static function boot(): void {
		if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
			return;
		}
		\WP_CLI::add_command( 'lps import', ImportCommand::class );
		\WP_CLI::add_command( 'lps import dry-run', array( new ImportCommand(), 'dry_run' ) );
		\WP_CLI::add_command( 'lps export', ExportCommand::class );
		\WP_CLI::add_command( 'lps redirects', RedirectCommand::class );
	}
}
