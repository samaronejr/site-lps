<?php
/**
 * Nonpublic teaching-upload quarantine contracts for the lps-redesign plan (task 6).
 *
 * @package LPS\ContentModel\Tests
 */

declare(strict_types=1);

namespace LPS\ContentModel\Tests;

require_once dirname( __DIR__ ) . '/includes/class-policy.php';
require_once dirname( __DIR__ ) . '/includes/class-hardening.php';
require_once dirname( __DIR__ ) . '/includes/class-securitypolicy.php';
require_once dirname( __DIR__ ) . '/includes/class-teachingstorage.php';
require_once dirname( __DIR__ ) . '/includes/class-mediarenderer.php';
require_once dirname( __DIR__ ) . '/includes/class-mediapolicy.php';
require_once dirname( __DIR__ ) . '/includes/class-mediauploads.php';

use LPS\ContentModel\MediaUploads;
use LPS\ContentModel\TeachingStorage;
use PHPUnit\Framework\TestCase;

final class LpsRedesignTask06Test extends TestCase {
	private const SCRATCH_ROOT = __DIR__ . '/../../../../test-results/lps-redesign-task06';
	private const NOW          = '2026-09-18T00:00:00+00:00';

	/** @var array<int, string> */
	private array $created_dirs = array();

	protected function tearDown(): void {
		foreach ( $this->created_dirs as $dir ) {
			self::remove_tree( $dir );
		}
		$this->created_dirs = array();
	}

	/**
	 * Removes a scratch tree created by this test run.
	 *
	 * @param string $dir Scratch directory.
	 */
	private static function remove_tree( string $dir ): void {
		if ( ! is_dir( $dir ) ) {
			return;
		}
		$items = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS ),
			\RecursiveIteratorIterator::CHILD_FIRST
		);
		foreach ( $items as $item ) {
			if ( ! $item instanceof \SplFileInfo ) {
				continue;
			}
			$item->isDir() ? rmdir( $item->getPathname() ) : unlink( $item->getPathname() );
		}
		rmdir( $dir );
	}

	/**
	 * Creates an isolated storage/public pair outside any web root.
	 *
	 * @param array<string, mixed> $overrides Configuration overrides.
	 * @return array<string, mixed>
	 */
	private function make_config( array $overrides = array() ): array {
		$base   = self::SCRATCH_ROOT . '/' . bin2hex( random_bytes( 6 ) );
		$public = $base . '/public';
		$root   = $base . '/storage';
		mkdir( $public, 0750, true );
		mkdir( $root, 0750, true );
		$this->created_dirs[] = $base;
		return array_merge(
			array(
				'storage_root'     => $root,
				'public_root'      => $public,
				'now'              => self::NOW,
				'scanner_adapter'  => TeachingStorage::test_scanner( array( 'clean' ) ),
				'scanner_approved' => false,
			),
			$overrides
		);
	}

	/**
	 * Writes fixture bytes to a scratch upload file.
	 *
	 * @param string $bytes File contents.
	 */
	private static function upload( string $bytes ): string {
		$tmp = tempnam( sys_get_temp_dir(), 'lps-up-' );
		self::assertIsString( $tmp );
		file_put_contents( $tmp, $bytes );
		return $tmp;
	}

	/**
	 * Stores an upload and returns its record, asserting success.
	 *
	 * @param string               $name   Client filename.
	 * @param string               $tmp    Temporary path.
	 * @param array<string, mixed> $config Storage configuration.
	 * @return array<string, mixed>
	 */
	private static function store_ok( string $name, string $tmp, array $config ): array {
		$result = TeachingStorage::store( $name, $tmp, $config );
		self::assertNull( $result['error'], $name );
		self::assertIsArray( $result['record'] );
		return $result['record'];
	}

	/**
	 * Returns a config value as a string.
	 *
	 * @param array<string, mixed> $config Storage configuration.
	 * @param string               $key    Config key.
	 */
	private static function config_string( array $config, string $key ): string {
		$value = $config[ $key ] ?? '';
		return is_string( $value ) ? $value : '';
	}

	private static function pdf_bytes(): string {
		return "%PDF-1.7\n1 0 obj << /Type /Catalog /Pages 2 0 R >> endobj\n2 0 obj << /Type /Pages /Kids [] /Count 0 >> endobj\n%%EOF\n";
	}

	private static function csv_bytes(): string {
		return "student,grade\nsynthetic-ada,9.5\nsynthetic-grace,8.0\n";
	}

	private static function notebook_bytes(): string {
		return (string) json_encode(
			array(
				'nbformat'       => 4,
				'nbformat_minor' => 5,
				'metadata'       => array( 'kernelspec' => array( 'name' => 'python3' ) ),
				'cells'          => array(
					array(
						'cell_type' => 'markdown',
						'metadata'  => array(),
						'source'    => array( '# Synthetic fixture' ),
					),
					array(
						'cell_type'       => 'code',
						'metadata'        => array(),
						'execution_count' => null,
						'source'          => array( 'x = 1' ),
						'outputs'         => array(
							array(
								'output_type' => 'execute_result',
								'data'        => array( 'text/plain' => '1' ),
								'metadata'    => array(),
							),
						),
					),
				),
			)
		);
	}

	private static function png_bytes(): string {
		return "\x89PNG\r\n\x1a\n"
			. "\x00\x00\x00\x0DIHDR"
			. pack( 'N', 2 ) . pack( 'N', 2 )
			. "\x08\x06\x00\x00\x00"
			. "\x00\x00\x00\x00";
	}

	/**
	 * Builds a minimal ZIP package in memory.
	 *
	 * @param array<int, array{name: string, data: string, method?: int, flags?: int}> $entries Members.
	 */
	private static function zip_bytes( array $entries ): string {
		$out     = '';
		$central = '';
		foreach ( $entries as $entry ) {
			$name     = $entry['name'];
			$data     = $entry['data'];
			$method   = $entry['method'] ?? 0;
			$flags    = $entry['flags'] ?? 0;
			$payload  = 8 === $method ? (string) gzdeflate( $data ) : $data;
			$offset   = strlen( $out );
			$crc      = crc32( $data );
			$out     .= "PK\x03\x04" . pack( 'v', 20 ) . pack( 'v', $flags ) . pack( 'v', $method )
				. pack( 'v', 0 ) . pack( 'v', 0 ) . pack( 'V', $crc )
				. pack( 'V', strlen( $payload ) ) . pack( 'V', strlen( $data ) )
				. pack( 'v', strlen( $name ) ) . pack( 'v', 0 ) . $name . $payload;
			$central .= "PK\x01\x02" . pack( 'v', 20 ) . pack( 'v', 20 ) . pack( 'v', $flags ) . pack( 'v', $method )
				. pack( 'v', 0 ) . pack( 'v', 0 ) . pack( 'V', $crc )
				. pack( 'V', strlen( $payload ) ) . pack( 'V', strlen( $data ) )
				. pack( 'v', strlen( $name ) ) . pack( 'v', 0 ) . pack( 'v', 0 )
				. pack( 'v', 0 ) . pack( 'v', 0 ) . pack( 'V', 0 ) . pack( 'V', $offset ) . $name;
		}
		$cd_offset = strlen( $out );
		$out      .= $central;
		$out      .= "PK\x05\x06" . pack( 'v', 0 ) . pack( 'v', 0 )
			. pack( 'v', count( $entries ) ) . pack( 'v', count( $entries ) )
			. pack( 'V', strlen( $central ) ) . pack( 'V', $cd_offset ) . pack( 'v', 0 );
		return $out;
	}

	/** Builds a valid minimal DOCX package. */
	private static function docx_bytes(): string {
		return self::zip_bytes(
			array(
				array(
					'name' => '[Content_Types].xml',
					'data' => '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
						. '<Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>'
						. '</Types>',
				),
				array(
					'name' => 'word/document.xml',
					'data' => '<?xml version="1.0"?><w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body/></w:document>',
				),
				array(
					'name' => '_rels/.rels',
					'data' => '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"/>',
				),
			)
		);
	}

	public function test_valid_pdf_csv_notebook_and_office_intake_quarantines_with_checksum(): void {
		$config = $this->make_config();
		foreach (
			array(
				'lecture-notes.pdf' => array( self::pdf_bytes(), 'application/pdf', 'application/pdf' ),
				'grades.csv'        => array( self::csv_bytes(), 'text/plain', 'text/csv; charset=utf-8' ),
				'lab.ipynb'         => array( self::notebook_bytes(), 'text/plain', 'application/x-ipynb+json' ),
				'slides.docx'       => array( self::docx_bytes(), 'application/zip', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document' ),
				'figure.png'        => array( self::png_bytes(), 'image/png', 'image/png' ),
			) as $name => $expect
		) {
			$record = self::store_ok( $name, self::upload( $expect[0] ), $config );
			self::assertSame( TeachingStorage::KIND_TEACHING, $record['kind'] );
			self::assertSame( 'quarantined', $record['state'] );
			self::assertSame( $expect[1], $record['detected_mime'], $name );
			self::assertSame( $expect[2], $record['mime'], $name );
			self::assertSame( hash( 'sha256', $expect[0] ), $record['checksum'] );
			self::assertIsString( $record['key'] );
			self::assertTrue( TeachingStorage::key_valid( $record['key'] ) );
			self::assertStringNotContainsString( $name, $record['key'], 'opaque keys never carry the client name' );
			$path = TeachingStorage::storage_path( self::config_string( $config, 'storage_root' ), $record );
			self::assertFileExists( $path );
			self::assertStringContainsString( '/quarantine/', $path );
			self::assertTrue( str_starts_with( $path, self::config_string( $config, 'storage_root' ) ) );
			self::assertStringNotContainsString( self::config_string( $config, 'public_root' ), $path );
			self::assertArrayNotHasKey( 'url', $record );
			self::assertSame( 'NULL', gettype( TeachingStorage::public_url( $record ) ) );
		}
	}

	public function test_scan_transitions_are_fail_closed(): void {
		$config = $this->make_config();

		// clean: quarantined -> scanning -> cleared, object moves with the state.
		$record                    = self::store_ok( 'a.pdf', self::upload( self::pdf_bytes() ), $config );
		$seen                      = array();
		$existed                   = false;
		$config['scanner_adapter'] = static function ( array $request ) use ( &$seen, &$existed ): string {
			$seen    = $request;
			$existed = is_string( $request['path'] ?? null ) && is_file( $request['path'] );
			return 'clean';
		};
		$scan                      = TeachingStorage::scan( $record, $config );
		self::assertNull( $scan['error'] );
		self::assertSame( 'cleared', $scan['record']['state'] );
		self::assertSame( 'clean', $scan['record']['scan_verdict'] );
		$cleared_path = TeachingStorage::storage_path( self::config_string( $config, 'storage_root' ), $scan['record'] );
		self::assertFileExists( $cleared_path );
		self::assertStringContainsString( '/cleared/', $cleared_path );
		self::assertSame( $record['checksum'], $seen['checksum'] ?? '' );
		self::assertTrue( $existed, 'the adapter receives the real local object' );
		$seen_path = is_string( $seen['path'] ?? null ) ? $seen['path'] : '';
		self::assertStringContainsString( '/scanning/', $seen_path );
		self::assertTrue( str_starts_with( $seen_path, self::config_string( $config, 'storage_root' ) ) );

		// pending: stays scanning, never cleared.
		$record                    = self::store_ok( 'b.pdf', self::upload( self::pdf_bytes() ), $config );
		$config['scanner_adapter'] = TeachingStorage::test_scanner( array( 'pending' ) );
		$scan                      = TeachingStorage::scan( $record, $config );
		self::assertNull( $scan['error'] );
		self::assertSame( 'scanning', $scan['record']['state'] );
		self::assertSame( 'pending', $scan['record']['scan_verdict'] );
		self::assertSame( 'lps_teaching_not_cleared', TeachingStorage::release_error( $scan['record'], $config ) );

		// error and invalid verdicts: back to quarantined, never cleared.
		foreach ( array( 'error', 'invalid-verdict' ) as $verdict ) {
			$record                    = self::store_ok( 'c-' . $verdict . '.pdf', self::upload( self::pdf_bytes() ), $config );
			$config['scanner_adapter'] = TeachingStorage::test_scanner( array( $verdict ) );
			$scan                      = TeachingStorage::scan( $record, $config );
			self::assertSame( 'quarantined', $scan['record']['state'], $verdict );
			self::assertSame( 'lps_teaching_not_cleared', TeachingStorage::release_error( $scan['record'], $config ) );
		}
		$record                    = self::store_ok( 'd.pdf', self::upload( self::pdf_bytes() ), $config );
		$config['scanner_adapter'] = static function (): string {
			throw new \RuntimeException( 'scanner daemon unreachable' );
		};
		$scan                      = TeachingStorage::scan( $record, $config );
		self::assertSame( 'quarantined', $scan['record']['state'] );

		// infected: failed, never cleared, still purgeable.
		$record                    = self::store_ok( 'e.pdf', self::upload( self::pdf_bytes() ), $config );
		$config['scanner_adapter'] = TeachingStorage::test_scanner( array( 'infected' ) );
		$scan                      = TeachingStorage::scan( $record, $config );
		self::assertSame( 'failed', $scan['record']['state'] );
		self::assertSame( 'lps_teaching_not_cleared', TeachingStorage::release_error( $scan['record'], $config ) );
		self::assertTrue( TeachingStorage::purge( $scan['record'], $config ) );
		self::assertFileDoesNotExist( TeachingStorage::storage_path( self::config_string( $config, 'storage_root' ), $scan['record'] ) );
	}

	public function test_scanner_outage_never_becomes_cleared(): void {
		$config = $this->make_config();
		$record = self::store_ok( 'outage.pdf', self::upload( self::pdf_bytes() ), $config );

		unset( $config['scanner_adapter'] );
		$scan = TeachingStorage::scan( $record, $config );
		self::assertSame( 'lps_scanner_missing', $scan['error'] );
		self::assertSame( 'quarantined', $scan['record']['state'] );
		self::assertSame( 'lps_scanner_missing', TeachingStorage::release_error( $scan['record'], $config ) );

		// A cleared record cannot be forged by editing state fields.
		$forged                 = $record;
		$forged['state']        = 'cleared';
		$forged['scan_verdict'] = 'clean';
		self::assertSame( 'lps_scanner_missing', TeachingStorage::release_error( $forged, $config ) );
	}

	public function test_release_requires_cleared_state_and_approved_production_config(): void {
		$config = $this->make_config();
		$record = self::store_ok( 'release.pdf', self::upload( self::pdf_bytes() ), $config );
		self::assertSame( 'lps_teaching_not_cleared', TeachingStorage::release_error( $record, $config ) );

		$scan    = TeachingStorage::scan( $record, $config );
		$cleared = $scan['record'];
		self::assertSame( 'cleared', $cleared['state'] );
		self::assertNull( TeachingStorage::release_error( $cleared, $config ), 'development release of a cleared file' );

		// Production additionally requires the institutionally approved scanner flag.
		self::assertSame( 'lps_scanner_unapproved', TeachingStorage::release_error( $cleared, $config, true ) );
		$approved                     = $config;
		$approved['scanner_approved'] = true;
		self::assertNull( TeachingStorage::release_error( $cleared, $approved, true ) );
	}

	public function test_default_cap_is_50_mib_and_override_is_administrative_and_bounded(): void {
		self::assertSame( 52_428_800, TeachingStorage::DEFAULT_MAX_BYTES );
		$config = $this->make_config();
		self::assertSame( TeachingStorage::DEFAULT_MAX_BYTES, TeachingStorage::effective_max_bytes( $config ) );
		self::assertNull( TeachingStorage::size_error( TeachingStorage::DEFAULT_MAX_BYTES, $config ) );
		self::assertSame( 'lps_teaching_file_too_large', TeachingStorage::size_error( TeachingStorage::DEFAULT_MAX_BYTES + 1, $config ) );
		self::assertSame( 'lps_teaching_file_empty', TeachingStorage::size_error( 0, $config ) );

		$override = $this->make_config( array( 'max_bytes' => 100 ) );
		self::assertSame( 100, TeachingStorage::effective_max_bytes( $override ) );
		$result = TeachingStorage::store( 'big.txt', self::upload( str_repeat( 'a', 101 ) . "\n" ), $override );
		self::assertSame( 'lps_teaching_file_too_large', $result['error'] );

		// Overrides above the hard ceiling are invalid configuration, not a silent raise.
		$excessive = $this->make_config( array( 'max_bytes' => TeachingStorage::ABSOLUTE_MAX_BYTES + 1 ) );
		self::assertContains( 'lps_storage_cap_invalid', TeachingStorage::config_errors( $excessive ) );
		self::assertSame( TeachingStorage::ABSOLUTE_MAX_BYTES, TeachingStorage::effective_max_bytes( $excessive ) );

		// Only technical administrators may adjust the cap; faculty roles cannot.
		self::assertTrue( TeachingStorage::cap_override_allowed( 'administrator' ) );
		foreach ( array( 'contributor', 'translator', 'section-editor', 'publisher', 'privacy-auditor', 'deployer' ) as $role ) {
			self::assertFalse( TeachingStorage::cap_override_allowed( $role ), $role );
		}
	}

	public function test_extension_spoofing_and_disguised_content_are_denied(): void {
		$config = $this->make_config();
		foreach (
			array(
				'fake.png'    => array( self::pdf_bytes(), 'lps_teaching_type_mismatch' ),
				'fake.pdf'    => array( self::png_bytes(), 'lps_teaching_type_mismatch' ),
				'fake.docx'   => array( self::pdf_bytes(), 'lps_teaching_type_mismatch' ),
				'fake.pdf2'   => array( self::pdf_bytes(), 'lps_teaching_extension_forbidden' ),
				'page.txt'    => array( "<html><body>spoof</body></html>\n", 'lps_teaching_active_markup' ),
				'run.csv'     => array( "<?php echo 1; ?>\n", 'lps_teaching_active_markup' ),
				'notes.txt'   => array( "see javascript:alert(1)\n", 'lps_teaching_active_markup' ),
				'legacy.docx' => array( "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1" . str_repeat( "\x00", 64 ), 'lps_teaching_type_mismatch' ),
				'raw.bin.pdf' => array( "\x00\x01\x02\x03\x04", 'lps_teaching_type_mismatch' ),
			) as $name => $expect
		) {
			$result = TeachingStorage::store( $name, self::upload( $expect[0] ), $config );
			self::assertSame( $expect[1], $result['error'], $name );
			self::assertNull( $result['record'] );
		}
	}

	public function test_malicious_paths_and_forbidden_extensions_are_denied(): void {
		$config = $this->make_config();
		foreach (
			array(
				'../escape.pdf'                 => 'lps_teaching_path_forbidden',
				'..\\escape.pdf'                => 'lps_teaching_path_forbidden',
				'dir/nested.pdf'                => 'lps_teaching_path_forbidden',
				'dir\\nested.pdf'               => 'lps_teaching_path_forbidden',
				'c:\\temp\\evil.pdf'            => 'lps_teaching_path_forbidden',
				"name\0null.pdf"                => 'lps_teaching_path_forbidden',
				'.hidden.pdf'                   => 'lps_teaching_name_invalid',
				'trailing.'                     => 'lps_teaching_name_invalid',
				'trailingdot.pdf '              => 'lps_teaching_name_invalid',
				str_repeat( 'a', 256 ) . '.pdf' => 'lps_teaching_name_invalid',
				'noextension'                   => 'lps_teaching_extension_missing',
				'vector.svg'                    => 'lps_teaching_executable_name_forbidden',
				'page.html'                     => 'lps_teaching_executable_name_forbidden',
				'shell.php'                     => 'lps_teaching_executable_name_forbidden',
				'shell.php.pdf'                 => 'lps_teaching_executable_name_forbidden',
				'macro.docm'                    => 'lps_teaching_extension_forbidden',
				'legacy.doc'                    => 'lps_teaching_extension_forbidden',
				'archive.zip'                   => 'lps_teaching_extension_forbidden',
				'data.json'                     => 'lps_teaching_extension_forbidden',
				'script.js'                     => 'lps_teaching_executable_name_forbidden',
				'program.exe'                   => 'lps_teaching_extension_forbidden',
			) as $name => $expect
		) {
			$result = TeachingStorage::store( $name, self::upload( self::pdf_bytes() ), $config );
			self::assertSame( $expect, $result['error'], $name );
		}
	}

	public function test_unsafe_office_packages_are_denied(): void {
		$config = $this->make_config();
		$types  = '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
			. '<Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/></Types>';
		$doc    = array(
			'name' => 'word/document.xml',
			'data' => '<w:document/>',
		);
		foreach (
			array(
				'vba'        => array(
					array(
						'name' => 'word/vbaProject.bin',
						'data' => 'x',
					),
					'lps_teaching_office_unsafe',
				),
				'ole'        => array(
					array(
						'name' => 'word/embeddings/oleObject1.bin',
						'data' => 'x',
					),
					'lps_teaching_office_unsafe',
				),
				'activex'    => array(
					array(
						'name' => 'word/activeX/activeX1.xml',
						'data' => 'x',
					),
					'lps_teaching_office_unsafe',
				),
				'zipslip'    => array(
					array(
						'name' => '../evil.xml',
						'data' => 'x',
					),
					'lps_teaching_office_unsafe',
				),
				'encrypted'  => array(
					array(
						'name'  => 'word/settings.xml',
						'data'  => 'x',
						'flags' => 0x1,
					),
					'lps_teaching_office_unsafe',
				),
				'exe-member' => array(
					array(
						'name' => 'word/media/update.exe',
						'data' => 'x',
					),
					'lps_teaching_office_unsafe',
				),
				'php-member' => array(
					array(
						'name' => 'word/media/run.php',
						'data' => 'x',
					),
					'lps_teaching_office_unsafe',
				),
				'no-main'    => array(
					array(
						'name' => 'word/styles.xml',
						'data' => 'x',
					),
					'lps_teaching_office_package_invalid',
				),
			) as $label => $expect
		) {
			$entries = array_merge(
				array(
					array(
						'name' => '[Content_Types].xml',
						'data' => $types,
					),
					$doc,
				),
				array( $expect[0] )
			);
			if ( 'no-main' === $label ) {
				$entries = array(
					array(
						'name' => '[Content_Types].xml',
						'data' => $types,
					),
					$expect[0],
				);
			}
			$result = TeachingStorage::store( 'unsafe-' . $label . '.docx', self::upload( self::zip_bytes( $entries ) ), $config );
			self::assertSame( $expect[1], $result['error'], $label );
		}

		// A macro-enabled content type is denied even when the file is named .docx.
		$macro_types = str_replace( 'document.main+xml', 'macroEnabled.main+xml', $types );
		$result      = TeachingStorage::store(
			'macro.docx',
			self::upload(
				self::zip_bytes(
					array(
						array(
							'name' => '[Content_Types].xml',
							'data' => $macro_types,
						),
						$doc,
					)
				)
			),
			$config
		);
		self::assertSame( 'lps_teaching_office_unsafe', $result['error'] );

		// A package whose main part declares another family is a type mismatch.
		$ppt_types = str_replace( 'wordprocessingml.document.main+xml', 'presentationml.presentation.main+xml', $types );
		$result    = TeachingStorage::store(
			'wrong.docx',
			self::upload(
				self::zip_bytes(
					array(
						array(
							'name' => '[Content_Types].xml',
							'data' => $ppt_types,
						),
						$doc,
					)
				)
			),
			$config
		);
		self::assertSame( 'lps_teaching_type_mismatch', $result['error'] );

		// Truncated packages fail closed.
		$result = TeachingStorage::store( 'truncated.docx', self::upload( "PK\x03\x04garbage" ), $config );
		self::assertSame( 'lps_teaching_office_package_invalid', $result['error'] );
	}

	public function test_notebooks_are_validated_as_data_and_never_executed(): void {
		$config = $this->make_config();
		foreach (
			array(
				'{"nbformat":4,"cells":"nope"}' => 'lps_teaching_notebook_invalid',
				'{"nbformat":3,"nbformat_minor":0,"cells":[]}' => 'lps_teaching_notebook_invalid',
				'{"nbformat":4}'                => 'lps_teaching_notebook_invalid',
				'{"nbformat":4,"cells":[{"cell_type":"widget","source":[]}]}' => 'lps_teaching_notebook_invalid',
				'not json at all'               => 'lps_teaching_notebook_invalid',
				'{"nbformat":4,"cells":[{"cell_type":"code","source":[],"outputs":[{"output_type":"display_data","data":{"text/html":"<script>alert(1)</script>"}}]}]}' => 'lps_teaching_notebook_active_output',
				'{"nbformat":4,"cells":[{"cell_type":"code","source":[],"outputs":[{"output_type":"display_data","data":{"image/svg+xml":"<svg/>"}}]}]}' => 'lps_teaching_notebook_active_output',
			) as $bytes => $expect
		) {
			$result = TeachingStorage::store( 'notebook.ipynb', self::upload( $bytes ), $config );
			self::assertSame( $expect, $result['error'], $bytes );
		}
	}

	public function test_configuration_absence_fails_closed(): void {
		$config = $this->make_config();

		$missing                 = $config;
		$missing['storage_root'] = self::config_string( $config, 'storage_root' ) . '/does-not-exist';
		self::assertContains( 'lps_storage_root_missing', TeachingStorage::config_errors( $missing ) );
		self::assertSame( 'lps_storage_root_missing', TeachingStorage::publication_gate_error( $missing ) );

		$unwritable = $this->make_config();
		chmod( self::config_string( $unwritable, 'storage_root' ), 0550 );
		self::assertContains( 'lps_storage_root_unwritable', TeachingStorage::config_errors( $unwritable ) );
		chmod( self::config_string( $unwritable, 'storage_root' ), 0750 );

		$inside = $this->make_config();
		$nested = self::config_string( $inside, 'public_root' ) . '/storage';
		mkdir( $nested, 0750, true );
		$inside['storage_root'] = $nested;
		self::assertContains( 'lps_storage_root_public', TeachingStorage::config_errors( $inside ) );
		$result = TeachingStorage::store( 'a.pdf', self::upload( self::pdf_bytes() ), $inside );
		self::assertSame( 'lps_storage_root_public', $result['error'] );

		$no_public                = $config;
		$no_public['public_root'] = '';
		self::assertContains( 'lps_storage_public_root_missing', TeachingStorage::config_errors( $no_public ) );

		$no_scanner = $config;
		unset( $no_scanner['scanner_adapter'] );
		self::assertContains( 'lps_scanner_missing', TeachingStorage::config_errors( $no_scanner ) );
		$result = TeachingStorage::store( 'a.pdf', self::upload( self::pdf_bytes() ), $no_scanner );
		self::assertSame( 'lps_scanner_missing', $result['error'] );
	}

	public function test_records_errors_and_diagnostics_never_expose_paths_or_secrets(): void {
		$config = $this->make_config( array( 'scanner_secret' => 's3cr3t-token-value' ) );
		$record = self::store_ok( 'secret.pdf', self::upload( self::pdf_bytes() ), $config );
		$json   = (string) json_encode( $record );
		self::assertStringNotContainsString( self::config_string( $config, 'storage_root' ), $json );
		self::assertStringNotContainsString( self::config_string( $config, 'public_root' ), $json );
		self::assertStringNotContainsString( 's3cr3t-token-value', $json );

		$description = (string) json_encode( TeachingStorage::describe_config( $config, true ) );
		self::assertStringNotContainsString( self::config_string( $config, 'storage_root' ), $description );
		self::assertStringNotContainsString( self::config_string( $config, 'public_root' ), $description );
		self::assertStringNotContainsString( 's3cr3t-token-value', $description );
		self::assertStringContainsString( 'lps_scanner_unapproved', $description );

		foreach ( array( 'lps_storage_root_missing', 'lps_scanner_missing', 'lps_teaching_not_cleared' ) as $code ) {
			self::assertMatchesRegularExpression( '/^[a-z0-9_]+$/', $code );
		}
	}

	public function test_download_headers_are_attachment_only_and_never_executable(): void {
		$config = $this->make_config();
		$record = self::store_ok( 'My Lecture "notes".PDF', self::upload( self::pdf_bytes() ), $config );
		self::assertSame( 'my-lecture-notes.pdf', $record['download_name'] );
		$headers = TeachingStorage::download_headers( $record );
		self::assertSame( 'application/pdf', $headers['Content-Type'] );
		self::assertStringStartsWith( 'attachment; filename="', $headers['Content-Disposition'] );
		self::assertStringContainsString( 'my-lecture-notes.pdf', $headers['Content-Disposition'] );
		self::assertSame( 'nosniff', $headers['X-Content-Type-Options'] );
		self::assertSame( 'no-store', $headers['Cache-Control'] );
		self::assertSame( "default-src 'none'", $headers['Content-Security-Policy'] );
		self::assertStringNotContainsString( 'inline', $headers['Content-Disposition'] );
	}

	public function test_teaching_lane_is_separate_from_controlled_media_uploads(): void {
		$media_extensions = array();
		foreach ( array_keys( MediaUploads::allowed_mimes() ) as $group ) {
			foreach ( explode( '|', $group ) as $extension ) {
				$media_extensions[] = $extension;
			}
		}
		foreach ( array( 'docx', 'pptx', 'xlsx', 'csv', 'txt', 'ipynb' ) as $teaching_only ) {
			self::assertNotContains( $teaching_only, $media_extensions, $teaching_only . ' must not enter public uploads' );
		}
		self::assertSame( 'teaching', TeachingStorage::KIND_TEACHING );
		self::assertSame( 'brand', TeachingStorage::KIND_BRAND );
		self::assertSame( 'lps-test-only-scanner', TeachingStorage::SCANNER_TEST_ONLY );
	}

	public function test_purge_never_removes_a_cleared_object(): void {
		$config = $this->make_config();
		$record = self::store_ok( 'keep.pdf', self::upload( self::pdf_bytes() ), $config );
		$scan   = TeachingStorage::scan( $record, $config );
		self::assertSame( 'cleared', $scan['record']['state'] );
		self::assertFalse( TeachingStorage::purge( $scan['record'], $config ) );
		self::assertFileExists( TeachingStorage::storage_path( self::config_string( $config, 'storage_root' ), $scan['record'] ) );
	}
}
