<?php
/**
 * PHPUnit bootstrap for foundation unit tests and future WordPress integration tests.
 *
 * @package LPS\Tests
 */

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

if (! function_exists('wp_parse_url')) {
	/**
	 * Mirrors the WordPress signature, including component extraction.
	 *
	 * @return array<string, int|string>|string|int|null|false
	 */
	function wp_parse_url(string $url, int $component = -1): array|string|int|null|false {
		return parse_url($url, $component);
	}
}

if (! function_exists('esc_attr')) {
	/**
	 * Mirrors the WordPress attribute escaper used by rendering seams.
	 */
	function esc_attr(string $text): string {
		return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
	}
}

if (! function_exists('wp_strip_all_tags')) {
	/**
	 * Mirrors the WordPress tag stripper used by metadata seams.
	 */
	function wp_strip_all_tags(string $text, bool $remove_breaks = false): string {
		$stripped = strip_tags(preg_replace('@<(script|style)[^>]*?>.*?</\\1>@si', '', $text) ?? $text);
		return $remove_breaks ? trim((string) preg_replace('/[\r\n\t ]+/', ' ', $stripped)) : $stripped;
	}
}

if (! function_exists('home_url')) {
	/**
	 * Mirrors the WordPress site-URL helper used by locale rendering seams.
	 */
	function home_url(string $path = '', ?string $scheme = null): string {
		$base = getenv('LPS_TEST_HOME_URL');
		$base = is_string($base) && '' !== $base ? $base : 'https://lps.example';
		return rtrim($base, '/') . '/' . ltrim($path, '/');
	}
}

if (! function_exists('wp_json_encode')) {
	/**
	 * Mirrors the WordPress JSON encoder used by structured-data seams.
	 */
	function wp_json_encode(mixed $data, int $options = 0, int $depth = 512): string|false {
		return json_encode($data, $options, $depth);
	}
}

$wordpress_tests_directory = getenv('WP_TESTS_DIR');
if (is_string($wordpress_tests_directory) && '' !== $wordpress_tests_directory) {
	require_once rtrim($wordpress_tests_directory, '/') . '/includes/functions.php';
}
