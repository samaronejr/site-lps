<?php
/**
 * Baseline fixture contracts for the lps-redesign plan (task 1).
 *
 * @package LPS\ContentModel\Tests
 */

declare(strict_types=1);

namespace LPS\ContentModel\Tests;

use PHPUnit\Framework\TestCase;

final class LpsRedesignTask01Test extends TestCase {
	private const FIXTURE_DIR = __DIR__ . '/../../../../tests/fixtures/lps-redesign';

	/**
	 * Reads one lps-redesign JSON fixture and enforces the shared schema.
	 *
	 * @return array<string, mixed>
	 */
	private static function load_fixture( string $name ): array {
		$file = self::FIXTURE_DIR . '/' . $name . '.json';
		self::assertFileExists( $file );
		$fixture = json_decode( (string) file_get_contents( $file ), true, 512, JSON_THROW_ON_ERROR );
		self::assertSame( 1, $fixture['schemaVersion'], $name . ' schemaVersion must equal 1' );
		self::assertTrue( $fixture['synthetic'], $name . ' must declare synthetic data' );
		return $fixture;
	}

	public function test_faculty_fixture_is_synthetic_and_accounts_reference_fixture_people(): void {
		$faculty = self::load_fixture( 'faculty' );

		self::assertCount( 3, $faculty['people'] );
		self::assertCount( 3, $faculty['accounts'] );

		$people_ids = array_column( $faculty['people'], 'id' );
		foreach ( $faculty['people'] as $person ) {
			self::assertTrue( $person['synthetic'] );
			self::assertStringContainsString( '-synthetic-', $person['id'] );
		}
		foreach ( $faculty['accounts'] as $account ) {
			self::assertTrue( $account['synthetic'] );
			self::assertContains( $account['person'], $people_ids, 'accounts must reference fixture people only' );
		}
	}

	public function test_teaching_calendar_is_data_only_until_task_8_persistence(): void {
		$calendar = self::load_fixture( 'teaching-calendar' );

		self::assertSame( 'task-08', $calendar['persistence']['implementedBy'] );
		self::assertFalse( $calendar['persistence']['assumedInBaseline'] );

		$statuses = array_column( $calendar['offerings'], 'temporalStatus' );
		self::assertSame( array( 'current', 'completed', 'upcoming' ), $statuses );

		$tokens = array_column( $calendar['terms'], 'token' );
		self::assertSame( $tokens, array_unique( $tokens ), 'term tokens must be unique across calendars' );
	}

	public function test_attempt_namespace_pattern_is_deterministic(): void {
		$faculty  = self::load_fixture( 'faculty' );
		$pattern  = '/' . $faculty['attemptNamespace']['pattern'] . '/';
		$attempts = array( 'attempt-1', 'attempt-12', 'wave0-a' );
		foreach ( $attempts as $attempt ) {
			self::assertMatchesRegularExpression( $pattern, $attempt );
		}
		self::assertDoesNotMatchRegularExpression( $pattern, 'Attempt_1' );
		self::assertDoesNotMatchRegularExpression( $pattern, '' );
	}
}
