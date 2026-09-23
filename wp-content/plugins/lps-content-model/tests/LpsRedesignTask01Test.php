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
		if ( ! is_array( $fixture ) ) {
			self::fail( $name . ' must decode to an object' );
		}
		self::assertSame( 1, $fixture['schemaVersion'] ?? null, $name . ' schemaVersion must equal 1' );
		self::assertTrue( $fixture['synthetic'] ?? null, $name . ' must declare synthetic data' );
		/** @var array<string, mixed> $fixture */
		return $fixture;
	}

	/**
	 * Reads one list-of-records field of a decoded fixture.
	 *
	 * @param array<string, mixed> $fixture Decoded fixture.
	 * @param string               $key     Field name.
	 * @return array<int, array<string, mixed>>
	 */
	private static function rows( array $fixture, string $key ): array {
		$value = $fixture[ $key ] ?? null;
		if ( ! is_array( $value ) ) {
			self::fail( $key . ' must be an array' );
		}
		/** @var array<int, array<string, mixed>> $value */
		return $value;
	}

	/**
	 * Reads one object field of a decoded fixture.
	 *
	 * @param array<string, mixed> $fixture Decoded fixture.
	 * @param string               $key     Field name.
	 * @return array<string, mixed>
	 */
	private static function field( array $fixture, string $key ): array {
		$value = $fixture[ $key ] ?? null;
		if ( ! is_array( $value ) ) {
			self::fail( $key . ' must be an object' );
		}
		/** @var array<string, mixed> $value */
		return $value;
	}

	/**
	 * Converts boundary input to string.
	 *
	 * @param mixed $value Boundary input.
	 */
	private static function text( mixed $value ): string {
		return is_scalar( $value ) ? (string) $value : '';
	}

	public function test_faculty_fixture_is_synthetic_and_accounts_reference_fixture_people(): void {
		$faculty = self::load_fixture( 'faculty' );

		$people   = self::rows( $faculty, 'people' );
		$accounts = self::rows( $faculty, 'accounts' );

		self::assertCount( 3, $people );
		self::assertCount( 3, $accounts );

		$people_ids = array_column( $people, 'id' );
		foreach ( $people as $person ) {
			self::assertTrue( $person['synthetic'] ?? null );
			self::assertStringContainsString( '-synthetic-', self::text( $person['id'] ?? '' ) );
		}
		foreach ( $accounts as $account ) {
			self::assertTrue( $account['synthetic'] ?? null );
			self::assertContains( $account['person'] ?? null, $people_ids, 'accounts must reference fixture people only' );
		}
	}

	public function test_teaching_calendar_is_data_only_until_task_8_persistence(): void {
		$calendar    = self::load_fixture( 'teaching-calendar' );
		$persistence = self::field( $calendar, 'persistence' );

		self::assertSame( 'task-08', $persistence['implementedBy'] ?? null );
		self::assertFalse( $persistence['assumedInBaseline'] ?? null );

		$statuses = array_column( self::rows( $calendar, 'offerings' ), 'temporalStatus' );
		self::assertSame( array( 'current', 'completed', 'upcoming' ), $statuses );

		$tokens = array_map( static fn( mixed $token ): string => self::text( $token ), array_column( self::rows( $calendar, 'terms' ), 'token' ) );
		self::assertSame( $tokens, array_unique( $tokens ), 'term tokens must be unique across calendars' );
	}

	public function test_attempt_namespace_pattern_is_deterministic(): void {
		$faculty   = self::load_fixture( 'faculty' );
		$namespace = self::field( $faculty, 'attemptNamespace' );
		$pattern   = '/' . self::text( $namespace['pattern'] ?? '' ) . '/';
		$attempts  = array( 'attempt-1', 'attempt-12', 'wave0-a' );
		foreach ( $attempts as $attempt ) {
			self::assertMatchesRegularExpression( $pattern, $attempt );
		}
		self::assertDoesNotMatchRegularExpression( $pattern, 'Attempt_1' );
		self::assertDoesNotMatchRegularExpression( $pattern, '' );
	}
}
