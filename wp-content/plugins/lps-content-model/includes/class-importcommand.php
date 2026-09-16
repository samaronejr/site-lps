<?php
/**
 * WP-CLI import command surface.
 *
 * @package LPS\ContentModel
 */

declare(strict_types=1);

namespace LPS\ContentModel;

use WP_CLI;
use WP_Error;

/** Implements `wp lps import ...`. */
final class ImportCommand {
	/**
	 * Plans an import.
	 *
	 * @param array<int, string>         $args Positional arguments.
	 * @phpstan-param list<string> $args
	 * @param array<string, string|bool> $assoc_args Named arguments.
	 */
	public function plan( array $args, array $assoc_args ): void {
		$context             = CliContext::load( $assoc_args, $args );
		$result              = ImportPlanner::plan( $context['package'], $context['assets_dir'] );
		$result['inventory'] = Reconciler::inventory( $context['inventory_dir'], $context['package'] );
		CliContext::emit( $result );
	}

	/**
	 * Performs a dry run.
	 *
	 * @param array<int, string>         $args Positional arguments.
	 * @phpstan-param list<string> $args
	 * @param array<string, string|bool> $assoc_args Named arguments.
	 */
	public function dry_run( array $args, array $assoc_args ): void {
		$context = CliContext::load( $assoc_args, $args );
		$before  = CliContext::state_hash();
		$plan    = ImportPlanner::plan( $context['package'], $context['assets_dir'] );
		$after   = CliContext::state_hash();
		CliContext::emit(
			array(
				'status'         => $plan['status'],
				'mutation_guard' => array(
					'before'    => $before,
					'after'     => $after,
					'unchanged' => hash_equals( $before, $after ),
				),
				'plan'           => $plan,
			)
		);
	}

	/**
	 * Applies an import.
	 *
	 * @param array<int, string>         $args Positional arguments.
	 * @phpstan-param list<string> $args
	 * @param array<string, string|bool> $assoc_args Named arguments.
	 */
	public function apply( array $args, array $assoc_args ): void {
		$context = CliContext::load( $assoc_args, $args );
		$plan    = ImportPlanner::plan( $context['package'], $context['assets_dir'] );
		$result  = Importer::apply( $context['package'], $context['assets_dir'] );
		if ( $result instanceof WP_Error ) {
			CliContext::fail( $result );
		}
		$reconciliation = Reconciler::target( $context['package'], $context['assets_dir'] );
		$report_dir     = $assoc_args['report-dir'] ?? false;
		if ( is_string( $report_dir ) ) {
			$result['artifacts'] = ReportWriter::write( $report_dir, $plan, $reconciliation );
		}
		$result['reconciliation'] = $reconciliation;
		CliContext::emit( $result );
	}

	/**
	 * Verifies an import.
	 *
	 * @param array<int, string>         $args Positional arguments.
	 * @phpstan-param list<string> $args
	 * @param array<string, string|bool> $assoc_args Named arguments.
	 */
	public function verify( array $args, array $assoc_args ): void {
		$context             = CliContext::load( $assoc_args, $args );
		$result              = Reconciler::target( $context['package'], $context['assets_dir'] );
		$result['inventory'] = Reconciler::inventory( $context['inventory_dir'], $context['package'] );
		CliContext::emit( $result );
		if ( 'verified' !== $result['status'] ) {
			WP_CLI::halt( 1 );
		}
	}
}
