<?php
/**
 * Foundation fixture contract tests.
 *
 * @package LPS\Tests
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class FoundationFixtureTest extends TestCase {
	public function test_declared_bootstraps_exist_when_fixture_is_valid(): void {
		// Given: the deterministic workspace fixture.
		$contents = file_get_contents(dirname(__DIR__) . '/fixtures/foundation.json');
		self::assertIsString($contents);

		/** @var array{schemaVersion: int, plugin: array{entry: string}, theme: array{entry: string}} $fixture */
		$fixture = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);

		// When: its schema version and declared entry points are inspected.
		$entries = array($fixture['plugin']['entry'], $fixture['theme']['entry']);

		// Then: the schema is recognized and every entry point exists.
		self::assertSame(1, $fixture['schemaVersion'], 'foundation fixture schemaVersion must equal 1');
		foreach ($entries as $entry) {
			self::assertFileExists(dirname(__DIR__, 2) . '/' . $entry);
		}
	}
}
