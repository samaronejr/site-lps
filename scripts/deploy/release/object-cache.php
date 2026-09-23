<?php
/**
 * Persistent object-cache drop-in for the LPS staging runtime.
 *
 * Production requires a persistent object cache (Redis or Memcached) for
 * wp_cache_*; the staging runtime has neither daemon, so this drop-in
 * implements the same contract against an append-safe file store mounted at
 * /lps-cache/object (outside the release tree, shared across releases).
 * Correctness over throughput: atomic temp-file+rename writes, TTL expiry,
 * group namespacing, and the standard WP_Object_Cache API surface.
 *
 * @package LPS
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * File-backed persistent cache implementing the WordPress object-cache API.
 */
class WP_Object_Cache {
	/**
	 * In-request cache mirror.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $cache = array();

	/**
	 * Groups that must never persist across requests.
	 *
	 * @var array<int, string>
	 */
	private array $non_persistent = array( 'comment', 'counts', 'plugins' );

	/**
	 * Storage directory inside the mounted ops cache volume.
	 *
	 * @var string
	 */
	private string $dir;

	/**
	 * Cache accounting for diagnostics.
	 *
	 * @var int
	 */
	public int $cache_hits = 0;

	/**
	 * Cache accounting for diagnostics.
	 *
	 * @var int
	 */
	public int $cache_misses = 0;

	/** Binds the store to the mounted cache directory. */
	public function __construct() {
		$base = defined( 'LPS_OPS_CACHE_DIR' ) ? LPS_OPS_CACHE_DIR : '/lps-cache';
		$this->dir = $base . '/object';
		if ( ! is_dir( $this->dir ) ) {
			wp_mkdir_p( $this->dir );
		}
	}

	/**
	 * Maps a group/key pair to a storage file.
	 *
	 * @param string $key   Cache key.
	 * @param string $group Cache group.
	 */
	private function file( string $key, string $group ): string {
		return $this->dir . '/' . md5( $group . ':' . $key ) . '.cache';
	}

	/**
	 * Reads one entry from the store, honouring expiry.
	 *
	 * @param string $key   Cache key.
	 * @param string $group Cache group.
	 * @return array{expires:int, value:mixed}|null
	 */
	private function read_entry( string $key, string $group ): ?array {
		$file = $this->file( $key, $group );
		if ( ! is_file( $file ) ) {
			return null;
		}
		// The expiry sweep in another request can remove the file between
		// is_file() and the read, so the read is allowed to lose the race.
		$raw = @file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents,WordPress.PHP.NoSilencedErrors.Discouraged -- a missing entry means a cache miss, not an error.
		if ( false === $raw ) {
			return null;
		}
		// The store is local and trusted (the ops volume is mode-restricted);
		// WordPress caches WP_Post/WP_Term objects, so classes must inflate.
		$entry = unserialize( $raw );
		if ( ! is_array( $entry ) || ! array_key_exists( 'expires', $entry ) ) {
			return null;
		}
		if ( $entry['expires'] > 0 && $entry['expires'] < time() ) {
			wp_delete_file( $file );
			return null;
		}
		return $entry;
	}

	/**
	 * Writes one entry atomically.
	 *
	 * @param string $key    Cache key.
	 * @param string $group  Cache group.
	 * @param mixed  $value  Value.
	 * @param int    $expire Seconds until expiry; 0 means no expiry.
	 */
	private function write_entry( string $key, string $group, mixed $value, int $expire ): bool {
		$entry = array(
			'expires' => $expire > 0 ? time() + $expire : 0,
			'value'   => $value,
		);
		$tmp = $this->file( $key, $group ) . '.' . getmypid() . '.tmp';
		if ( false === file_put_contents( $tmp, serialize( $entry ) ) ) {
			return false;
		}
		$target = $this->file( $key, $group );
		// The ops volume does not support rename-over-existing, so an
		// occupied slot is removed first and the write stays atomic enough
		// for a cache (a concurrent reader simply misses).
		if ( is_file( $target ) && ! wp_delete_file( $target ) ) {
			wp_delete_file( $tmp );
			return false;
		}
		return rename( $tmp, $target );
	}

	/**
	 * Whether the group survives the request.
	 *
	 * @param string $group Cache group.
	 */
	private function persistent( string $group ): bool {
		return ! in_array( $group, $this->non_persistent, true );
	}

	/**
	 * Adds a value only when the key is absent.
	 *
	 * @param string $key    Cache key.
	 * @param mixed  $value  Value.
	 * @param string $group  Cache group.
	 * @param int    $expire Seconds until expiry.
	 */
	public function add( $key, $value, $group = 'default', $expire = 0 ): bool {
		$group = (string) $group;
		if ( false !== $this->get( $key, $group ) ) {
			return false;
		}
		return $this->set( $key, $value, $group, (int) $expire );
	}

	/**
	 * Replaces a value only when the key exists.
	 *
	 * @param string $key    Cache key.
	 * @param mixed  $value  Value.
	 * @param string $group  Cache group.
	 * @param int    $expire Seconds until expiry.
	 */
	public function replace( $key, $value, $group = 'default', $expire = 0 ): bool {
		$group = (string) $group;
		if ( false === $this->get( $key, $group ) ) {
			return false;
		}
		return $this->set( $key, $value, $group, (int) $expire );
	}

	/**
	 * Stores a value.
	 *
	 * @param string $key    Cache key.
	 * @param mixed  $value  Value.
	 * @param string $group  Cache group.
	 * @param int    $expire Seconds until expiry.
	 */
	public function set( $key, $value, $group = 'default', $expire = 0 ): bool {
		$group = (string) $group;
		$this->cache[ $group ][ $key ] = $value;
		if ( ! $this->persistent( $group ) ) {
			return true;
		}
		return $this->write_entry( (string) $key, $group, $value, (int) $expire );
	}

	/**
	 * Reads a value.
	 *
	 * @param string $key   Cache key.
	 * @param string $group Cache group.
	 * @param bool   $force Unused; reads always consult the store.
	 * @param bool   $found Set to whether the key existed.
	 * @return mixed
	 */
	public function get( $key, $group = 'default', $force = false, &$found = null ): mixed {
		unset( $force );
		$group = (string) $group;
		if ( isset( $this->cache[ $group ][ $key ] ) ) {
			$found = true;
			$this->cache_hits++;
			return $this->cache[ $group ][ $key ];
		}
		if ( $this->persistent( $group ) ) {
			$entry = $this->read_entry( (string) $key, $group );
			if ( null !== $entry ) {
				$this->cache[ $group ][ $key ] = $entry['value'];
				$found = true;
				$this->cache_hits++;
				return $entry['value'];
			}
		}
		$found = false;
		$this->cache_misses++;
		return false;
	}

	/**
	 * Deletes a value.
	 *
	 * @param string $key   Cache key.
	 * @param string $group Cache group.
	 */
	public function delete( $key, $group = 'default' ): bool {
		$group = (string) $group;
		unset( $this->cache[ $group ][ $key ] );
		$file = $this->file( (string) $key, $group );
		if ( is_file( $file ) ) {
			return wp_delete_file( $file );
		}
		return true;
	}

	/**
	 * Increments a numeric value.
	 *
	 * @param string $key    Cache key.
	 * @param int    $offset Increment.
	 * @param string $group  Cache group.
	 */
	public function incr( $key, $offset = 1, $group = 'default' ): int|false {
		$value = $this->get( $key, $group );
		if ( false === $value || ! is_numeric( $value ) ) {
			return false;
		}
		$value = (int) $value + (int) $offset;
		$this->set( $key, $value, $group );
		return $value;
	}

	/**
	 * Decrements a numeric value.
	 *
	 * @param string $key    Cache key.
	 * @param int    $offset Decrement.
	 * @param string $group  Cache group.
	 */
	public function decr( $key, $offset = 1, $group = 'default' ): int|false {
		return $this->incr( $key, -1 * (int) $offset, $group );
	}

	/** Empties the store. */
	public function flush(): bool {
		$this->cache = array();
		foreach ( glob( $this->dir . '/*.cache' ) ?: array() as $file ) {
			wp_delete_file( $file );
		}
		return true;
	}

	/** No-op; the file store needs no connection lifecycle. */
	public function close(): void {
		$this->cache = array();
	}

	/**
	 * Registers additional global groups (single-site: no-op).
	 *
	 * @param array<int, string>|string $groups Group names.
	 */
	public function add_global_groups( $groups ): void {
		unset( $groups );
	}

	/**
	 * Registers non-persistent groups.
	 *
	 * @param array<int, string>|string $groups Group names.
	 */
	public function add_non_persistent_groups( $groups ): void {
		foreach ( (array) $groups as $group ) {
			$this->non_persistent[] = (string) $group;
		}
	}

	/**
	 * Switches the blog context; keys are already site-scoped by group.
	 *
	 * @param int $blog_id Blog id.
	 */
	public function switch_to_blog( $blog_id ): void {
		unset( $blog_id );
	}

	/** Restores the blog context. */
	public function reset(): void {
	}
}

/**
 * Initializes the drop-in.
 */
function wp_cache_init(): void {
	$GLOBALS['wp_object_cache'] = new WP_Object_Cache();
}

/**
 * Closes the store.
 */
function wp_cache_close(): bool {
	$GLOBALS['wp_object_cache']->close();
	return true;
}

/**
 * Adds a value when absent.
 *
 * @param string $key    Cache key.
 * @param mixed  $value  Value.
 * @param string $group  Cache group.
 * @param int    $expire Seconds until expiry.
 */
function wp_cache_add( $key, $value, $group = '', $expire = 0 ): bool {
	return $GLOBALS['wp_object_cache']->add( $key, $value, '' === $group ? 'default' : $group, $expire );
}

/**
 * Adds multiple values.
 *
 * @param array<int|string, mixed> $data   Values keyed by cache key.
 * @param string                   $group  Cache group.
 * @param int                      $expire Seconds until expiry.
 * @return array<int|string, bool>
 */
function wp_cache_add_multiple( array $data, $group = '', $expire = 0 ): array {
	$results = array();
	foreach ( $data as $key => $value ) {
		$results[ $key ] = wp_cache_add( $key, $value, $group, $expire );
	}
	return $results;
}

/**
 * Replaces a value when present.
 *
 * @param string $key    Cache key.
 * @param mixed  $value  Value.
 * @param string $group  Cache group.
 * @param int    $expire Seconds until expiry.
 */
function wp_cache_replace( $key, $value, $group = '', $expire = 0 ): bool {
	return $GLOBALS['wp_object_cache']->replace( $key, $value, '' === $group ? 'default' : $group, $expire );
}

/**
 * Stores a value.
 *
 * @param string $key    Cache key.
 * @param mixed  $value  Value.
 * @param string $group  Cache group.
 * @param int    $expire Seconds until expiry.
 */
function wp_cache_set( $key, $value, $group = '', $expire = 0 ): bool {
	return $GLOBALS['wp_object_cache']->set( $key, $value, '' === $group ? 'default' : $group, $expire );
}

/**
 * Stores multiple values.
 *
 * @param array<int|string, mixed> $data   Values keyed by cache key.
 * @param string                   $group  Cache group.
 * @param int                      $expire Seconds until expiry.
 * @return array<int|string, bool>
 */
function wp_cache_set_multiple( array $data, $group = '', $expire = 0 ): array {
	$results = array();
	foreach ( $data as $key => $value ) {
		$results[ $key ] = wp_cache_set( $key, $value, $group, $expire );
	}
	return $results;
}

/**
 * Reads a value.
 *
 * @param string $key   Cache key.
 * @param string $group Cache group.
 * @param bool   $force Unused.
 * @param bool   $found Whether the key existed.
 * @return mixed
 */
function wp_cache_get( $key, $group = '', $force = false, &$found = null ): mixed {
	return $GLOBALS['wp_object_cache']->get( $key, '' === $group ? 'default' : $group, $force, $found );
}

/**
 * Reads multiple values.
 *
 * @param array<int, string> $keys  Cache keys.
 * @param string            $group Cache group.
 * @param bool              $force Unused.
 * @return array<string, mixed>
 */
function wp_cache_get_multiple( $keys, $group = '', $force = false ): array {
	$values = array();
	foreach ( $keys as $key ) {
		$values[ $key ] = wp_cache_get( $key, $group, $force );
	}
	return $values;
}

/**
 * Deletes a value.
 *
 * @param string $key   Cache key.
 * @param string $group Cache group.
 */
function wp_cache_delete( $key, $group = '' ): bool {
	return $GLOBALS['wp_object_cache']->delete( $key, '' === $group ? 'default' : $group );
}

/**
 * Deletes multiple values.
 *
 * @param array<int, string> $keys  Cache keys.
 * @param string            $group Cache group.
 * @return array<string, bool>
 */
function wp_cache_delete_multiple( array $keys, $group = '' ): array {
	$results = array();
	foreach ( $keys as $key ) {
		$results[ $key ] = wp_cache_delete( $key, $group );
	}
	return $results;
}

/**
 * Increments a value.
 *
 * @param string $key    Cache key.
 * @param int    $offset Increment.
 * @param string $group  Cache group.
 */
function wp_cache_incr( $key, $offset = 1, $group = 'default' ): int|false {
	return $GLOBALS['wp_object_cache']->incr( $key, $offset, '' === $group ? 'default' : $group );
}

/**
 * Decrements a value.
 *
 * @param string $key    Cache key.
 * @param int    $offset Decrement.
 * @param string $group  Cache group.
 */
function wp_cache_decr( $key, $offset = 1, $group = 'default' ): int|false {
	return $GLOBALS['wp_object_cache']->decr( $key, $offset, '' === $group ? 'default' : $group );
}

/**
 * Empties the store.
 */
function wp_cache_flush(): bool {
	return $GLOBALS['wp_object_cache']->flush();
}

/**
 * Empties the runtime group only; persistent groups survive.
 */
function wp_cache_flush_runtime(): bool {
	return true;
}

/**
 * Empties one group.
 *
 * @param string $group Cache group.
 */
function wp_cache_flush_group( $group ): bool {
	unset( $group );
	return wp_cache_flush();
}

/**
 * Reports a supported capability.
 *
 * @param string $feature Feature name.
 */
function wp_cache_supports( $feature ): bool {
	return 'flush_group' !== $feature;
}

/**
 * Registers global groups.
 *
 * @param array<int, string>|string $groups Group names.
 */
function wp_cache_add_global_groups( $groups ): void {
	$GLOBALS['wp_object_cache']->add_global_groups( $groups );
}

/**
 * Registers non-persistent groups.
 *
 * @param array<int, string>|string $groups Group names.
 */
function wp_cache_add_non_persistent_groups( $groups ): void {
	$GLOBALS['wp_object_cache']->add_non_persistent_groups( $groups );
}

/**
 * Switches blog context.
 *
 * @param int $blog_id Blog id.
 */
function wp_cache_switch_to_blog( $blog_id ): bool {
	$GLOBALS['wp_object_cache']->switch_to_blog( $blog_id );
	return true;
}

/**
 * Restores blog context.
 */
function wp_cache_reset(): bool {
	$GLOBALS['wp_object_cache']->reset();
	return true;
}
