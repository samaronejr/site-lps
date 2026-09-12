<?php
/**
 * WP-CLI redirect command.
 *
 * @package LPS\ContentModel
 */

declare(strict_types=1);

namespace LPS\ContentModel;

use WP_CLI;

/** Implements `wp lps redirects verify`. */
final class RedirectCommand {
	/**
	 * Verifies redirects.
	 *
	 * @param array<int, string>         $args Positional arguments.
	 * @phpstan-param list<string> $args
	 * @param array<string, string|bool> $assoc_args Named arguments.
	 */
	public function verify( array $args, array $assoc_args ): void {
		unset( $args, $assoc_args );
		$redirects = Exporter::data()['redirects'];
		$errors    = RedirectPolicy::verify( $redirects, home_url( '/' ) );
		$gone      = count( array_filter( $redirects, static fn ( array $row ): bool => 410 === $row['status'] && $row['gone'] ) );
		CliContext::emit(
			array(
				'status'    => array() === $errors ? 'verified' : 'failed',
				'redirects' => count( $redirects ),
				'gone'      => $gone,
				'errors'    => $errors,
			)
		);
		if ( array() !== $errors ) {
			WP_CLI::halt( 1 );
		}
	}
}
