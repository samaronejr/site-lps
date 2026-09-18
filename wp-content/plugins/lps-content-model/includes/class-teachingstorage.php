<?php
/**
 * Nonpublic teaching-file quarantine, content inspection and release prerequisites.
 *
 * Faculty teaching downloads never enter the public uploads tree and never obtain a
 * raw URL. Files land in quarantine under an opaque key, pass extension/MIME/content
 * checks and the configured scanner, and only a `cleared` record may be released by
 * the authorized download endpoint. Controlled brand/media assets are a separate
 * lane owned by MediaUploads; this boundary stores bytes outside the web root.
 *
 * @package LPS\ContentModel
 */

declare(strict_types=1);

namespace LPS\ContentModel;

require_once __DIR__ . '/class-policy.php';
require_once __DIR__ . '/class-hardening.php';
require_once __DIR__ . '/class-securitypolicy.php';

/** Pure storage boundary shared by WordPress adapters and tests. */
final class TeachingStorage {
	/** Lane marker stored on every record produced by this boundary. */
	public const KIND_TEACHING = 'teaching';

	/** Lane marker for controlled brand/media assets, which never use this boundary. */
	public const KIND_BRAND = 'brand';

	/** Default per-file cap: 50 MiB. */
	public const DEFAULT_MAX_BYTES = 52_428_800;

	/** Hard ceiling no administrative override may exceed: 200 MiB. */
	public const ABSOLUTE_MAX_BYTES = 209_715_200;

	/** Label of the bundled test-only scanner adapter; never production-approved. */
	public const SCANNER_TEST_ONLY = 'lps-test-only-scanner';

	/** State-to-directory map inside the storage root; `cleared` is reachable only from `scanning` with a `clean` verdict. */
	private const STATE_DIRS = array(
		'quarantined' => 'quarantine',
		'scanning'    => 'scanning',
		'cleared'     => 'cleared',
		'failed'      => 'failed',
	);

	/** Reviewed teaching extensions mapped to the MIME type delivered to clients. */
	private const EXTENSION_MIME = array(
		'pdf'   => 'application/pdf',
		'txt'   => 'text/plain; charset=utf-8',
		'csv'   => 'text/csv; charset=utf-8',
		'docx'  => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
		'pptx'  => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
		'xlsx'  => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
		'png'   => 'image/png',
		'jpg'   => 'image/jpeg',
		'jpeg'  => 'image/jpeg',
		'webp'  => 'image/webp',
		'ipynb' => 'application/x-ipynb+json',
	);

	/** Required main document part per OOXML extension. */
	private const OFFICE_MAIN_PARTS = array(
		'docx' => 'word/document.xml',
		'pptx' => 'ppt/presentation.xml',
		'xlsx' => 'xl/workbook.xml',
	);

	/** Content type the package must declare for its main part. */
	private const OFFICE_MAIN_TYPES = array(
		'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml',
		'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation.main+xml',
		'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml',
	);

	/** OOXML member basenames that always mean active or embedded content. */
	private const OFFICE_UNSAFE_BASENAMES = array( 'vbaproject.bin' );

	/** OOXML member path segments that always mean active or embedded content. */
	private const OFFICE_UNSAFE_SEGMENTS = array( 'activex', 'oleobject', 'embeddings', 'macrosheets', 'ctrlprops' );

	/** Notebook output MIME types that would execute or render active markup. */
	private const NOTEBOOK_ACTIVE_MIMES = array( 'text/html', 'image/svg+xml', 'application/javascript', 'text/javascript' );

	/** Scanner verdicts the boundary understands. */
	private const VERDICTS = array( 'clean', 'pending', 'error', 'infected' );

	/**
	 * Returns configuration violations; any entry fails upload, scan and release closed.
	 *
	 * @param array<string, mixed> $config Storage adapter configuration.
	 * @param bool                 $production Whether institutionally approved configuration is required.
	 * @return array<int, string>
	 */
	public static function config_errors( array $config, bool $production = false ): array {
		$errors       = array();
		$storage_root = self::text( $config['storage_root'] ?? '' );
		$public_root  = self::text( $config['public_root'] ?? '' );
		if ( '' === $storage_root || ! is_dir( $storage_root ) ) {
			$errors[] = 'lps_storage_root_missing';
		} elseif ( ! is_writable( $storage_root ) ) {
			$errors[] = 'lps_storage_root_unwritable';
		}
		if ( '' === $public_root || ! is_dir( $public_root ) ) {
			$errors[] = 'lps_storage_public_root_missing';
		}
		if ( array() === $errors && self::path_within( $storage_root, $public_root ) ) {
			$errors[] = 'lps_storage_root_public';
		}
		if ( ! is_callable( $config['scanner_adapter'] ?? null ) ) {
			$errors[] = 'lps_scanner_missing';
		}
		if ( $production && true !== ( $config['scanner_approved'] ?? false ) ) {
			$errors[] = 'lps_scanner_unapproved';
		}
		if ( array_key_exists( 'max_bytes', $config ) && null !== $config['max_bytes'] ) {
			$cap = $config['max_bytes'];
			if ( ! is_int( $cap ) || $cap < 1 || $cap > self::ABSOLUTE_MAX_BYTES ) {
				$errors[] = 'lps_storage_cap_invalid';
			}
		}
		return $errors;
	}

	/**
	 * Returns the first configuration error, which is the publication gate denial.
	 *
	 * @param array<string, mixed> $config Storage adapter configuration.
	 * @param bool                 $production Whether approved configuration is required.
	 */
	public static function publication_gate_error( array $config, bool $production = false ): ?string {
		$errors = self::config_errors( $config, $production );
		return $errors[0] ?? null;
	}

	/**
	 * Returns the effective per-file cap in bytes.
	 *
	 * @param array<string, mixed> $config Storage adapter configuration.
	 */
	public static function effective_max_bytes( array $config ): int {
		$cap = $config['max_bytes'] ?? null;
		if ( ! is_int( $cap ) || $cap < 1 ) {
			return self::DEFAULT_MAX_BYTES;
		}
		return min( $cap, self::ABSOLUTE_MAX_BYTES );
	}

	/**
	 * Only technical administrators may adjust the upload cap; faculty never can.
	 *
	 * @param string $role Policy role.
	 */
	public static function cap_override_allowed( string $role ): bool {
		return SecurityPolicy::allows( $role, 'settings' );
	}

	/**
	 * Validates an untrusted upload filename without touching its bytes.
	 *
	 * @param string $name Client-supplied basename.
	 */
	public static function name_error( string $name ): ?string {
		if ( str_contains( $name, '/' ) || str_contains( $name, '\\' ) || str_contains( $name, "\0" ) || str_contains( $name, ':' ) || 1 === preg_match( '/[\x00-\x1F\x7F]/', $name ) ) {
			return 'lps_teaching_path_forbidden';
		}
		if ( '' === $name || strlen( $name ) > 255 || str_starts_with( $name, '.' ) || 1 === preg_match( '/[.\s]$/', $name ) ) {
			return 'lps_teaching_name_invalid';
		}
		if ( ! Hardening::safe_upload_name( $name ) ) {
			return 'lps_teaching_executable_name_forbidden';
		}
		$extension = self::extension( $name );
		if ( '' === $extension ) {
			return 'lps_teaching_extension_missing';
		}
		if ( ! isset( self::EXTENSION_MIME[ $extension ] ) ) {
			return 'lps_teaching_extension_forbidden';
		}
		return null;
	}

	/**
	 * Enforces the bounded upload size.
	 *
	 * @param int                  $bytes File size.
	 * @param array<string, mixed> $config Storage adapter configuration.
	 */
	public static function size_error( int $bytes, array $config ): ?string {
		if ( $bytes <= 0 ) {
			return 'lps_teaching_file_empty';
		}
		return $bytes > self::effective_max_bytes( $config ) ? 'lps_teaching_file_too_large' : null;
	}

	/**
	 * Detects the content type from magic bytes, never from the client claim.
	 *
	 * @param string $bytes File contents.
	 */
	public static function detect_mime( string $bytes ): string {
		if ( str_starts_with( $bytes, '%PDF-' ) ) {
			return 'application/pdf';
		}
		if ( str_starts_with( $bytes, "PK\x03\x04" ) || str_starts_with( $bytes, "PK\x05\x06" ) ) {
			return 'application/zip';
		}
		if ( str_starts_with( $bytes, "\x89PNG\r\n\x1a\n" ) ) {
			return 'image/png';
		}
		if ( str_starts_with( $bytes, "\xFF\xD8\xFF" ) ) {
			return 'image/jpeg';
		}
		if ( str_starts_with( $bytes, 'RIFF' ) && 'WEBP' === substr( $bytes, 8, 4 ) ) {
			return 'image/webp';
		}
		if ( str_starts_with( $bytes, "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1" ) ) {
			return 'application/x-ole-storage';
		}
		if ( '' !== $bytes && ! str_contains( $bytes, "\0" ) && 1 === preg_match( '//u', $bytes ) ) {
			return 'text/plain';
		}
		return 'application/octet-stream';
	}

	/**
	 * Validates inspected content against the declared extension.
	 *
	 * @param string $extension Lowercase extension.
	 * @param string $detected  Detected content type.
	 * @param string $bytes     File contents.
	 * @param string $tmp_name  Temporary path for image geometry.
	 */
	public static function content_error( string $extension, string $detected, string $bytes, string $tmp_name ): ?string {
		if ( isset( self::OFFICE_MAIN_PARTS[ $extension ] ) ) {
			if ( 'application/zip' !== $detected ) {
				return 'lps_teaching_type_mismatch';
			}
			return self::office_error( $extension, $bytes );
		}
		if ( 'application/zip' === $detected || 'application/x-ole-storage' === $detected ) {
			return 'lps_teaching_type_mismatch';
		}
		switch ( $extension ) {
			case 'pdf':
				if ( 'application/pdf' !== $detected ) {
					return 'lps_teaching_type_mismatch';
				}
				return Hardening::safe_document( 'application/pdf', $bytes ) ? null : 'lps_teaching_active_document';
			case 'txt':
			case 'csv':
				if ( 'text/plain' !== $detected ) {
					return 'lps_teaching_type_mismatch';
				}
				return self::active_markup( $bytes ) ? 'lps_teaching_active_markup' : null;
			case 'ipynb':
				if ( 'text/plain' !== $detected ) {
					return 'lps_teaching_type_mismatch';
				}
				return self::notebook_error( $bytes );
			case 'png':
			case 'jpg':
			case 'jpeg':
			case 'webp':
				if ( self::EXTENSION_MIME[ $extension ] !== $detected ) {
					return 'lps_teaching_type_mismatch';
				}
				return self::image_error( $tmp_name );
		}
		return 'lps_teaching_extension_forbidden';
	}

	/**
	 * Runs the full intake boundary and returns a quarantined record.
	 *
	 * The result never contains a filesystem path or public URL; `storage_path()`
	 * derives the server-side location from the opaque key when the adapter needs it.
	 *
	 * @param string               $name     Client-supplied filename.
	 * @param string               $tmp_name Temporary upload path.
	 * @param array<string, mixed> $config   Storage adapter configuration.
	 * @return array{error: string|null, record: array<string, mixed>|null}
	 */
	public static function intake( string $name, string $tmp_name, array $config ): array {
		$error = self::name_error( $name );
		if ( null !== $error ) {
			return self::result( $error );
		}
		$error = self::publication_gate_error( $config );
		if ( null !== $error ) {
			return self::result( $error );
		}
		if ( ! is_file( $tmp_name ) ) {
			return self::result( 'lps_teaching_read_failed' );
		}
		$length = filesize( $tmp_name );
		if ( false === $length ) {
			return self::result( 'lps_teaching_read_failed' );
		}
		$error = self::size_error( $length, $config );
		if ( null !== $error ) {
			return self::result( $error );
		}
		$bytes = file_get_contents( $tmp_name );
		if ( false === $bytes ) {
			return self::result( 'lps_teaching_read_failed' );
		}
		$extension = self::extension( $name );
		$detected  = self::detect_mime( $bytes );
		$error     = self::content_error( $extension, $detected, $bytes, $tmp_name );
		if ( null !== $error ) {
			return self::result( $error );
		}
		$record = array(
			'key'           => self::new_key(),
			'kind'          => self::KIND_TEACHING,
			'state'         => 'quarantined',
			'extension'     => $extension,
			'mime'          => self::EXTENSION_MIME[ $extension ],
			'detected_mime' => $detected,
			'bytes'         => $length,
			'checksum'      => hash( 'sha256', $bytes ),
			'original_name' => $name,
			'download_name' => self::download_name( $name, $extension ),
			'scan_verdict'  => 'none',
			'created_at'    => '' !== self::text( $config['now'] ?? '' ) ? self::text( $config['now'] ) : gmdate( 'c' ),
		);
		return self::result( null, $record );
	}

	/**
	 * Moves an accepted upload into the quarantine directory under its opaque key.
	 *
	 * @param string               $name     Client-supplied filename.
	 * @param string               $tmp_name Temporary upload path.
	 * @param array<string, mixed> $config   Storage adapter configuration.
	 * @return array{error: string|null, record: array<string, mixed>|null}
	 */
	public static function store( string $name, string $tmp_name, array $config ): array {
		$intake = self::intake( $name, $tmp_name, $config );
		if ( null !== $intake['error'] || null === $intake['record'] ) {
			return $intake;
		}
		$record = $intake['record'];
		$dir    = self::state_dir( self::text( $config['storage_root'] ), 'quarantined' );
		if ( ! is_dir( $dir ) && ! mkdir( $dir, 0750, true ) && ! is_dir( $dir ) ) {
			return self::result( 'lps_storage_write_failed' );
		}
		$target = $dir . '/' . self::text( $record['key'] );
		if ( ! rename( $tmp_name, $target ) ) {
			if ( ! copy( $tmp_name, $target ) ) {
				return self::result( 'lps_storage_write_failed' );
			}
			unlink( $tmp_name );
		}
		chmod( $target, 0640 );
		return self::result( null, $record );
	}

	/**
	 * Drives one record through the configured scanner with fail-closed transitions.
	 *
	 * `pending` keeps the record in `scanning`; `error`, an adapter exception or an
	 * invalid verdict returns it to `quarantined`; only `clean` reaches `cleared`.
	 * In production an unapproved adapter can never produce a `cleared` record.
	 *
	 * @param array<string, mixed> $record     Stored record.
	 * @param array<string, mixed> $config     Storage adapter configuration.
	 * @param bool                 $production Whether approved configuration is required.
	 * @return array{error: string|null, record: array<string, mixed>}
	 */
	public static function scan( array $record, array $config, bool $production = false ): array {
		$adapter = $config['scanner_adapter'] ?? null;
		if ( ! is_callable( $adapter ) ) {
			return array(
				'error'  => 'lps_scanner_missing',
				'record' => $record,
			);
		}
		if ( $production ) {
			$errors = self::config_errors( $config, true );
			if ( array() !== $errors ) {
				return array(
					'error'  => $errors[0],
					'record' => $record,
				);
			}
		}
		if ( ! in_array( $record['state'] ?? '', array( 'quarantined', 'scanning' ), true ) ) {
			return array(
				'error'  => 'lps_teaching_state_forbidden',
				'record' => $record,
			);
		}
		if ( 'scanning' !== $record['state'] ) {
			$moved = self::move( $record, 'scanning', $config );
			if ( null !== $moved['error'] ) {
				return $moved;
			}
			$record = $moved['record'];
		}
		try {
			$verdict = $adapter( self::scan_request( $record, $config ) );
		} catch ( \Throwable $exception ) {
			unset( $exception );
			$verdict = 'error';
		}
		$verdict = is_string( $verdict ) ? $verdict : 'error';
		if ( ! in_array( $verdict, self::VERDICTS, true ) ) {
			$record['scan_verdict'] = 'error';
			$moved                  = self::move( $record, 'quarantined', $config );
			return array(
				'error'  => 'lps_scanner_invalid_verdict',
				'record' => $moved['record'],
			);
		}
		$record['scan_verdict'] = $verdict;
		if ( 'clean' === $verdict ) {
			return self::move( $record, 'cleared', $config );
		}
		if ( 'infected' === $verdict ) {
			return self::move( $record, 'failed', $config );
		}
		if ( 'error' === $verdict ) {
			return self::move( $record, 'quarantined', $config );
		}
		return array(
			'error'  => null,
			'record' => $record,
		);
	}

	/**
	 * Returns the release denial for one record, or null when release is permitted.
	 *
	 * Release requires a `cleared` state reached through a `clean` verdict plus a
	 * complete configuration; production additionally requires the institutionally
	 * approved scanner flag. Pending, missing or failed scans never release.
	 *
	 * @param array<string, mixed> $record     Stored record.
	 * @param array<string, mixed> $config     Storage adapter configuration.
	 * @param bool                 $production Whether approved configuration is required.
	 */
	public static function release_error( array $record, array $config, bool $production = false ): ?string {
		$errors = self::config_errors( $config, $production );
		if ( array() !== $errors ) {
			return $errors[0];
		}
		if ( 'cleared' !== ( $record['state'] ?? '' ) || 'clean' !== ( $record['scan_verdict'] ?? '' ) ) {
			return 'lps_teaching_not_cleared';
		}
		return null;
	}

	/**
	 * Returns the server-side path for a record; never exposed to clients.
	 *
	 * @param string               $storage_root Configured storage root.
	 * @param array<string, mixed> $record       Stored record.
	 */
	public static function storage_path( string $storage_root, array $record ): string {
		$state = self::text( $record['state'] ?? 'quarantined' );
		if ( ! isset( self::STATE_DIRS[ $state ] ) ) {
			$state = 'quarantined';
		}
		return self::state_dir( $storage_root, $state ) . '/' . self::text( $record['key'] ?? '' );
	}

	/**
	 * Teaching files are never directly addressable; downloads resolve through the
	 * authorized endpoint, which re-checks release state on every request.
	 *
	 * @param array<string, mixed> $record Stored record.
	 */
	public static function public_url( array $record ): null {
		unset( $record );
		return null;
	}

	/**
	 * Returns safe download headers: attachment disposition, nosniff, no-store.
	 *
	 * @param array<string, mixed> $record Cleared record.
	 * @return array<string, string>
	 */
	public static function download_headers( array $record ): array {
		return array(
			'Content-Type'            => self::text( $record['mime'] ?? 'application/octet-stream' ),
			'Content-Disposition'     => 'attachment; filename="' . self::download_name( self::text( $record['original_name'] ?? '' ), self::text( $record['extension'] ?? 'bin' ) ) . '"',
			'X-Content-Type-Options'  => 'nosniff',
			'Cache-Control'           => 'no-store',
			'Content-Security-Policy' => "default-src 'none'",
			'Content-Length'          => (string) Policy::sanitize_integer( $record['bytes'] ?? 0 ),
		);
	}

	/**
	 * Derives a safe ASCII download basename carrying the canonical extension.
	 *
	 * @param string $original  Client-supplied filename.
	 * @param string $extension Canonical stored extension.
	 */
	public static function download_name( string $original, string $extension ): string {
		$base = strtolower( trim( $original ) );
		$base = (string) preg_replace( '/\.[a-z0-9]{1,10}$/', '', $base );
		$base = (string) preg_replace( '/[^a-z0-9._-]+/', '-', $base );
		$base = trim( (string) preg_replace( '/-{2,}/', '-', $base ), '.-_' );
		if ( '' === $base ) {
			$base = 'download';
		}
		if ( strlen( $base ) > 80 ) {
			$base = trim( substr( $base, 0, 80 ), '.-_' );
		}
		$extension = isset( self::EXTENSION_MIME[ $extension ] ) ? $extension : 'bin';
		return $base . '.' . $extension;
	}

	/**
	 * Removes a non-released object; cleared records are never purged by this path.
	 *
	 * @param array<string, mixed> $record Stored record.
	 * @param array<string, mixed> $config Storage adapter configuration.
	 */
	public static function purge( array $record, array $config ): bool {
		if ( 'cleared' === ( $record['state'] ?? '' ) ) {
			return false;
		}
		$path = self::storage_path( self::text( $config['storage_root'] ?? '' ), $record );
		return is_file( $path ) ? unlink( $path ) : false;
	}

	/**
	 * Returns a redacted configuration view for diagnostics: no paths or secrets.
	 *
	 * @param array<string, mixed> $config     Storage adapter configuration.
	 * @param bool                 $production Whether approved configuration is required.
	 * @return array<string, mixed>
	 */
	public static function describe_config( array $config, bool $production = false ): array {
		$storage_root = self::text( $config['storage_root'] ?? '' );
		$public_root  = self::text( $config['public_root'] ?? '' );
		return array(
			'storage_root'          => '' !== $storage_root && is_dir( $storage_root ) ? 'configured' : 'missing',
			'public_root'           => '' !== $public_root && is_dir( $public_root ) ? 'configured' : 'missing',
			'storage_inside_public' => '' !== $storage_root && '' !== $public_root && self::path_within( $storage_root, $public_root ),
			'max_bytes'             => self::effective_max_bytes( $config ),
			'scanner_adapter'       => is_callable( $config['scanner_adapter'] ?? null ) ? 'configured' : 'missing',
			'scanner_approved'      => true === ( $config['scanner_approved'] ?? false ),
			'production'            => $production,
			'errors'                => self::config_errors( $config, $production ),
		);
	}

	/**
	 * Reads the deployed storage adapter configuration from wp-config constants.
	 *
	 * `LPS_TEACHING_STORAGE_ROOT` and `LPS_TEACHING_PUBLIC_ROOT` are absolute paths
	 * provisioned by the host; `LPS_TEACHING_MAX_BYTES` is the documented technical
	 * override; `LPS_TEACHING_SCANNER_APPROVED` may be defined `true` only after the
	 * institution approves the scanner. The adapter itself arrives through the
	 * `lps_teaching_scanner_adapter` filter so credentials never live in records.
	 *
	 * @return array<string, mixed>
	 */
	public static function config_from_constants(): array {
		$config = array(
			'storage_root'     => self::constant_or_env( 'LPS_TEACHING_STORAGE_ROOT' ),
			'public_root'      => self::constant_or_env( 'LPS_TEACHING_PUBLIC_ROOT' ),
			'max_bytes'        => null,
			'scanner_approved' => defined( 'LPS_TEACHING_SCANNER_APPROVED' ) && true === LPS_TEACHING_SCANNER_APPROVED,
			'scanner_adapter'  => null,
		);
		$cap    = self::constant_or_env( 'LPS_TEACHING_MAX_BYTES' );
		if ( '' !== $cap && is_numeric( $cap ) ) {
			$config['max_bytes'] = (int) $cap;
		}
		if ( function_exists( 'apply_filters' ) ) {
			$adapter = apply_filters( 'lps_teaching_scanner_adapter', null );
			if ( is_callable( $adapter ) ) {
				$config['scanner_adapter'] = $adapter;
			}
		}
		return $config;
	}

	/**
	 * Returns the bundled TEST-ONLY scanner adapter.
	 *
	 * It exists so development and PHPUnit can exercise quarantine transitions. It is
	 * never institutionally approved: `config_errors( ..., true )` always flags a
	 * configuration that relies on it, and production scans fail closed rather than
	 * fabricate a clean state. With an empty queue it reports `error`, never `clean`.
	 *
	 * @param array<int, string> $verdicts Queued verdicts consumed one per scan call.
	 * @return callable(array<string, mixed>): string
	 */
	public static function test_scanner( array $verdicts = array() ): callable {
		$queue = array_values( $verdicts );
		return static function ( array $request ) use ( &$queue ): string {
			unset( $request );
			return array_shift( $queue ) ?? 'error';
		};
	}

	/**
	 * Returns whether a storage key has the opaque generated shape.
	 *
	 * @param string $key Candidate key.
	 */
	public static function key_valid( string $key ): bool {
		return 1 === preg_match( '/^lps-file-[0-9a-f]{32}$/', $key );
	}

	/**
	 * Returns the reviewed extension allowlist.
	 *
	 * @return array<string, string>
	 */
	public static function allowed_extensions(): array {
		return self::EXTENSION_MIME;
	}

	/**
	 * Builds the scan request handed to the adapter; the only place a path leaves
	 * the boundary, and it goes to local scanning infrastructure, never to clients.
	 *
	 * @param array<string, mixed> $record Stored record.
	 * @param array<string, mixed> $config Storage adapter configuration.
	 * @return array<string, mixed>
	 */
	private static function scan_request( array $record, array $config ): array {
		return array(
			'path'     => self::storage_path( self::text( $config['storage_root'] ?? '' ), $record ),
			'key'      => self::text( $record['key'] ?? '' ),
			'mime'     => self::text( $record['mime'] ?? '' ),
			'bytes'    => Policy::sanitize_integer( $record['bytes'] ?? 0 ),
			'checksum' => self::text( $record['checksum'] ?? '' ),
		);
	}

	/**
	 * Moves a record's object between state directories.
	 *
	 * @param array<string, mixed> $record Stored record.
	 * @param string               $state  Target state.
	 * @param array<string, mixed> $config Storage adapter configuration.
	 * @return array{error: string|null, record: array<string, mixed>}
	 */
	private static function move( array $record, string $state, array $config ): array {
		$root = self::text( $config['storage_root'] ?? '' );
		$from = self::storage_path( $root, $record );
		if ( ! is_file( $from ) ) {
			return array(
				'error'  => 'lps_storage_object_missing',
				'record' => $record,
			);
		}
		$dir = self::state_dir( $root, $state );
		if ( ! is_dir( $dir ) && ! mkdir( $dir, 0750, true ) && ! is_dir( $dir ) ) {
			return array(
				'error'  => 'lps_storage_write_failed',
				'record' => $record,
			);
		}
		if ( ! rename( $from, $dir . '/' . self::text( $record['key'] ?? '' ) ) ) {
			return array(
				'error'  => 'lps_storage_write_failed',
				'record' => $record,
			);
		}
		$record['state'] = $state;
		return array(
			'error'  => null,
			'record' => $record,
		);
	}

	/**
	 * Validates an OOXML package: structure, main part and no active content.
	 *
	 * @param string $extension Lowercase extension.
	 * @param string $bytes     Package contents.
	 */
	private static function office_error( string $extension, string $bytes ): ?string {
		$entries = self::zip_entries( $bytes );
		if ( null === $entries ) {
			return 'lps_teaching_office_package_invalid';
		}
		$names = array();
		foreach ( $entries as $entry ) {
			$name = strtolower( $entry['name'] );
			if ( 0 !== ( $entry['flags'] & 0x1 ) ) {
				return 'lps_teaching_office_unsafe';
			}
			if ( str_contains( $name, '..' ) || str_starts_with( $name, '/' ) || str_contains( $name, '\\' ) || 1 === preg_match( '/^[a-z]:/', $name ) ) {
				return 'lps_teaching_office_unsafe';
			}
			$basename = basename( $name );
			if ( in_array( $basename, self::OFFICE_UNSAFE_BASENAMES, true ) ) {
				return 'lps_teaching_office_unsafe';
			}
			foreach ( self::OFFICE_UNSAFE_SEGMENTS as $segment ) {
				if ( str_contains( $name, '/' . $segment . '/' ) || str_starts_with( $name, $segment . '/' ) ) {
					return 'lps_teaching_office_unsafe';
				}
			}
			if ( ! Hardening::safe_upload_name( $basename ) || 1 === preg_match( '/\.(?:exe|dll|bat|cmd|com|msi|ps1|vbs|wsf|jar|lnk|scr|bin)$/i', $name ) ) {
				return 'lps_teaching_office_unsafe';
			}
			$names[ $name ] = $entry;
		}
		if ( ! isset( $names['[content_types].xml'] ) || ! isset( $names[ self::OFFICE_MAIN_PARTS[ $extension ] ] ) ) {
			return 'lps_teaching_office_package_invalid';
		}
		$content_types = self::zip_entry_bytes( $bytes, $names['[content_types].xml'] );
		if ( null === $content_types ) {
			return 'lps_teaching_office_package_invalid';
		}
		if ( 1 === preg_match( '/macroenabled|macrosheet|vbaproject/i', $content_types ) ) {
			return 'lps_teaching_office_unsafe';
		}
		if ( ! str_contains( $content_types, self::OFFICE_MAIN_TYPES[ $extension ] ) ) {
			return 'lps_teaching_type_mismatch';
		}
		return null;
	}

	/**
	 * Parses the ZIP central directory without any extension dependency.
	 *
	 * @param string $bytes Package contents.
	 * @return array<int, array{name: string, method: int, flags: int, comp_size: int, local_offset: int}>|null
	 */
	private static function zip_entries( string $bytes ): ?array {
		$size = strlen( $bytes );
		if ( $size < 22 ) {
			return null;
		}
		$tail = substr( $bytes, max( 0, $size - 65557 ) );
		$pos  = strrpos( $tail, "PK\x05\x06" );
		if ( false === $pos || $pos + 22 > strlen( $tail ) ) {
			return null;
		}
		$eocd = unpack( 'vdisk/vcd_disk/vdisk_entries/vtotal/Vcd_size/Vcd_offset/vcomment_len', substr( $tail, $pos + 4, 18 ) );
		if ( false === $eocd
			|| 0 !== self::unpacked_int( $eocd, 'disk' )
			|| 0 !== self::unpacked_int( $eocd, 'cd_disk' )
			|| self::unpacked_int( $eocd, 'disk_entries' ) !== self::unpacked_int( $eocd, 'total' )
		) {
			return null;
		}
		$cd_offset = self::unpacked_int( $eocd, 'cd_offset' );
		$cd_size   = self::unpacked_int( $eocd, 'cd_size' );
		if ( null === $cd_offset || null === $cd_size ) {
			return null;
		}
		$directory = substr( $bytes, $cd_offset, $cd_size );
		if ( strlen( $directory ) !== $cd_size ) {
			return null;
		}
		$entries = array();
		$offset  = 0;
		$length  = strlen( $directory );
		while ( $offset + 46 <= $length ) {
			if ( "PK\x01\x02" !== substr( $directory, $offset, 4 ) ) {
				return null;
			}
			$header = unpack( 'vversion_made/vversion_needed/vflags/vmethod/vmtime/vmdate/Vcrc/Vcomp_size/Vsize/vname_len/vextra_len/vcomment_len/vdisk/vint_attr/Vext_attr/Vlocal_offset', substr( $directory, $offset + 4, 42 ) );
			if ( false === $header ) {
				return null;
			}
			$name_len    = self::unpacked_int( $header, 'name_len' );
			$extra_len   = self::unpacked_int( $header, 'extra_len' );
			$comment_len = self::unpacked_int( $header, 'comment_len' );
			$method      = self::unpacked_int( $header, 'method' );
			$flags       = self::unpacked_int( $header, 'flags' );
			$comp_size   = self::unpacked_int( $header, 'comp_size' );
			$local       = self::unpacked_int( $header, 'local_offset' );
			if ( null === $name_len || null === $extra_len || null === $comment_len || null === $method || null === $flags || null === $comp_size || null === $local || $offset + 46 + $name_len > $length ) {
				return null;
			}
			$entries[] = array(
				'name'         => substr( $directory, $offset + 46, $name_len ),
				'method'       => $method,
				'flags'        => $flags,
				'comp_size'    => $comp_size,
				'local_offset' => $local,
			);
			$offset   += 46 + $name_len + $extra_len + $comment_len;
		}
		return $entries;
	}

	/**
	 * Inflates one stored or deflated ZIP member.
	 *
	 * @param string                                                                          $bytes Package contents.
	 * @param array{name: string, method: int, flags: int, comp_size: int, local_offset: int} $entry Central-directory entry.
	 */
	private static function zip_entry_bytes( string $bytes, array $entry ): ?string {
		$offset = $entry['local_offset'];
		if ( "PK\x03\x04" !== substr( $bytes, $offset, 4 ) ) {
			return null;
		}
		$header = unpack( 'vversion/vflags/vmethod/vmtime/vmdate/Vcrc/Vcomp_size/Vsize/vname_len/vextra_len', substr( $bytes, $offset + 4, 26 ) );
		if ( false === $header ) {
			return null;
		}
		$name_len  = self::unpacked_int( $header, 'name_len' );
		$extra_len = self::unpacked_int( $header, 'extra_len' );
		if ( null === $name_len || null === $extra_len ) {
			return null;
		}
		$data = substr( $bytes, $offset + 30 + $name_len + $extra_len, $entry['comp_size'] );
		if ( strlen( $data ) !== $entry['comp_size'] ) {
			return null;
		}
		if ( 0 === $entry['method'] ) {
			return $data;
		}
		if ( 8 === $entry['method'] ) {
			$inflated = gzinflate( $data );
			return is_string( $inflated ) ? $inflated : null;
		}
		return null;
	}

	/**
	 * Validates a notebook as data; notebooks are never executed or rendered inline.
	 *
	 * @param string $bytes File contents.
	 */
	private static function notebook_error( string $bytes ): ?string {
		$notebook = json_decode( $bytes, true );
		if ( ! is_array( $notebook ) ) {
			return 'lps_teaching_notebook_invalid';
		}
		$nbformat = $notebook['nbformat'] ?? null;
		if ( ! is_int( $nbformat ) || $nbformat < 4 ) {
			return 'lps_teaching_notebook_invalid';
		}
		$cells = $notebook['cells'] ?? null;
		if ( ! is_array( $cells ) ) {
			return 'lps_teaching_notebook_invalid';
		}
		foreach ( $cells as $cell ) {
			if ( ! is_array( $cell ) || ! in_array( $cell['cell_type'] ?? '', array( 'code', 'markdown', 'raw' ), true ) ) {
				return 'lps_teaching_notebook_invalid';
			}
			$outputs = $cell['outputs'] ?? array();
			if ( ! is_array( $outputs ) ) {
				return 'lps_teaching_notebook_invalid';
			}
			foreach ( $outputs as $output ) {
				if ( ! is_array( $output ) ) {
					return 'lps_teaching_notebook_invalid';
				}
				$data = $output['data'] ?? array();
				if ( ! is_array( $data ) ) {
					return 'lps_teaching_notebook_invalid';
				}
				foreach ( array_keys( $data ) as $mime ) {
					if ( in_array( strtolower( (string) $mime ), self::NOTEBOOK_ACTIVE_MIMES, true ) ) {
						return 'lps_teaching_notebook_active_output';
					}
				}
			}
		}
		return null;
	}

	/**
	 * Validates image geometry through the real decoder.
	 *
	 * @param string $tmp_name Temporary path.
	 */
	private static function image_error( string $tmp_name ): ?string {
		$dimensions = getimagesize( $tmp_name );
		if ( ! is_array( $dimensions ) ) {
			return 'lps_teaching_invalid_image';
		}
		$width  = Policy::sanitize_integer( $dimensions[0] );
		$height = Policy::sanitize_integer( $dimensions[1] );
		return $width > 0 && $height > 0 && $width <= 12000 && $height <= 12000 ? null : 'lps_teaching_invalid_image';
	}

	/**
	 * Detects active markup smuggled inside a text download.
	 *
	 * @param string $bytes File contents.
	 */
	private static function active_markup( string $bytes ): bool {
		return 1 === preg_match( '/<\s*(?:script|iframe|object|embed|html\b)|<\?(?:php|=)|javascript:/i', $bytes );
	}

	/**
	 * Narrows one unpack() field to an integer.
	 *
	 * @param array<mixed> $row Unpacked row.
	 * @param string       $key Field name.
	 */
	private static function unpacked_int( array $row, string $key ): ?int {
		$value = $row[ $key ] ?? null;
		return is_numeric( $value ) ? (int) $value : null;
	}

	/**
	 * Returns the state directory path.
	 *
	 * @param string $storage_root Configured storage root.
	 * @param string $state        Record state.
	 */
	private static function state_dir( string $storage_root, string $state ): string {
		return rtrim( $storage_root, '/' ) . '/' . ( self::STATE_DIRS[ $state ] ?? 'quarantine' );
	}

	/**
	 * Extracts the lowercase final extension.
	 *
	 * @param string $name Filename.
	 */
	private static function extension( string $name ): string {
		$position = strrpos( $name, '.' );
		if ( false === $position ) {
			return '';
		}
		$extension = strtolower( substr( $name, $position + 1 ) );
		return 1 === preg_match( '/^[a-z0-9]{1,10}$/', $extension ) ? $extension : '';
	}

	/**
	 * Returns whether the child path resolves inside the parent path.
	 *
	 * @param string $child Candidate inner path.
	 * @param string $outer Candidate outer path.
	 */
	private static function path_within( string $child, string $outer ): bool {
		$child_real = realpath( $child );
		$outer_real = realpath( $outer );
		if ( false === $child_real || false === $outer_real ) {
			return false;
		}
		$outer_real = rtrim( $outer_real, '/' ) . '/';
		return str_starts_with( rtrim( $child_real, '/' ) . '/', $outer_real );
	}

	/**
	 * Reads a wp-config constant with an environment fallback.
	 *
	 * @param string $name Constant name.
	 */
	private static function constant_or_env( string $name ): string {
		if ( defined( $name ) ) {
			$value = constant( $name );
			return is_scalar( $value ) ? trim( (string) $value ) : '';
		}
		$value = getenv( $name );
		return is_string( $value ) ? trim( $value ) : '';
	}

	/**
	 * Generates an opaque storage key with no name, time or sequence content.
	 */
	private static function new_key(): string {
		return 'lps-file-' . bin2hex( random_bytes( 16 ) );
	}

	/**
	 * Converts boundary input to a trimmed string.
	 *
	 * @param mixed $value Boundary value.
	 */
	private static function text( mixed $value ): string {
		return is_scalar( $value ) ? trim( (string) $value ) : '';
	}

	/**
	 * Shapes an intake/store result.
	 *
	 * @param string|null               $error  Typed denial code.
	 * @param array<string, mixed>|null $record Stored record.
	 * @return array{error: string|null, record: array<string, mixed>|null}
	 */
	private static function result( ?string $error, ?array $record = null ): array {
		return array(
			'error'  => $error,
			'record' => $record,
		);
	}
}
