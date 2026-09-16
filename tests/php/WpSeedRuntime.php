<?php
/**
 * In-memory WordPress request runtime for server-free seed bring-up tests.
 *
 * The runtime models exactly the WordPress seams the mu-plugin seeds use: the
 * hook registry, the options table, the posts table and post meta. Repeatedly
 * firing a hook models successive HTTP requests against one persistent
 * database, which is how a fresh-database bring-up actually unfolds.
 *
 * @package LPS\Tests
 */

declare(strict_types=1);

namespace LPS\Tests {

	/** Records every WordPress write the seeds perform. */
	final class WpSeedRuntime {
		/**
		 * Registered hook callbacks, kept across resets because a real request
		 * re-registers the identical mu-plugin callbacks every time.
		 *
		 * @var array<string, list<array{priority: int, callback: callable}>>
		 */
		private static array $hooks = array();

		/**
		 * Options table.
		 *
		 * @var array<string, mixed>
		 */
		private static array $options = array();

		/**
		 * Posts table keyed by identifier.
		 *
		 * @var array<int, array{ID: int, post_type: string, post_name: string, post_status: string, post_parent: int, post_title: string, post_content: string}>
		 */
		private static array $posts = array();

		/**
		 * Post meta keyed by post identifier.
		 *
		 * @var array<int, array<string, mixed>>
		 */
		private static array $meta = array();

		private static int $next_id = 100;

		private static int $inserts = 0;

		private static int $post_updates = 0;

		private static int $meta_writes = 0;

		private static int $rewrite_flushes = 0;

		private static int $deletes = 0;

		/**
		 * Identifiers deleted since the last reset, in deletion order.
		 *
		 * @var array<int, int>
		 */
		private static array $deleted = array();

		/** Clears database state while keeping the mu-plugin hook registry. */
		public static function reset(): void {
			self::$options         = array();
			self::$posts           = array();
			self::$meta            = array();
			self::$next_id         = 100;
			self::$inserts         = 0;
			self::$post_updates    = 0;
			self::$meta_writes     = 0;
			self::$rewrite_flushes = 0;
			self::$deletes         = 0;
			self::$deleted         = array();
		}

		/** Clears only the write counters, so one request can be measured alone. */
		public static function reset_counters(): void {
			self::$inserts         = 0;
			self::$post_updates    = 0;
			self::$meta_writes     = 0;
			self::$rewrite_flushes = 0;
			self::$deletes         = 0;
		}

		/**
		 * Registers one hook callback.
		 *
		 * @param callable $callback Hook callback.
		 */
		public static function add_hook( string $hook, callable $callback, int $priority ): void {
			self::$hooks[ $hook ][] = array(
				'priority' => $priority,
				'callback' => $callback,
			);
		}

		/** Fires one hook in WordPress priority order, modelling a single request. */
		public static function fire( string $hook ): void {
			$callbacks = self::$hooks[ $hook ] ?? array();
			usort(
				$callbacks,
				static fn( array $left, array $right ): int => $left['priority'] <=> $right['priority']
			);
			foreach ( $callbacks as $registered ) {
				( $registered['callback'] )();
			}
		}

		/** Reports whether any callback is registered for a hook. */
		public static function has_hook( string $hook ): bool {
			return array() !== ( self::$hooks[ $hook ] ?? array() );
		}

		/**
		 * Reads one option.
		 *
		 * @param mixed $default_value Value returned when the option is absent.
		 * @return mixed Stored value.
		 */
		public static function get_option( string $option, mixed $default_value ): mixed {
			return self::$options[ $option ] ?? $default_value;
		}

		/**
		 * Writes one option.
		 *
		 * @param mixed $value Option value.
		 */
		public static function update_option( string $option, mixed $value ): void {
			self::$options[ $option ] = $value;
		}

		/** Removes one option. */
		public static function delete_option( string $option ): void {
			unset( self::$options[ $option ] );
		}

		/**
		 * Applies the migration receipts the content-model plugin writes when its
		 * own idempotent migrations and audit ledger install have completed.
		 */
		public static function apply_plugin_migration_receipts(): void {
			self::update_option( 'lps_relationship_schema_version', '1.0.0' );
			self::update_option( 'lps_audit_schema_version', '1.0.0' );
		}

		/**
		 * Loads the real content-model translation boundary, modelling plugin activation.
		 *
		 * The production class is loaded rather than a stand-in so the seed is
		 * exercised against the same API a live activation provides.
		 */
		public static function activate_content_model_plugin(): void {
			if ( class_exists( \LPS\ContentModel\Translations::class, false ) ) {
				return;
			}
			require_once dirname( __DIR__, 2 ) . '/wp-content/plugins/lps-content-model/includes/class-translations.php';
		}

		/**
		 * Inserts one post and returns its identifier.
		 *
		 * @param array<string, mixed> $postarr Post fields.
		 */
		public static function insert_post( array $postarr ): int {
			$id = self::$next_id;
			++self::$next_id;
			++self::$inserts;
			self::$posts[ $id ] = array(
				'ID'           => $id,
				'post_type'    => is_string( $postarr['post_type'] ?? null ) ? $postarr['post_type'] : 'post',
				'post_name'    => is_string( $postarr['post_name'] ?? null ) ? $postarr['post_name'] : '',
				'post_status'  => is_string( $postarr['post_status'] ?? null ) ? $postarr['post_status'] : 'draft',
				'post_parent'  => is_int( $postarr['post_parent'] ?? null ) ? $postarr['post_parent'] : 0,
				'post_title'   => is_string( $postarr['post_title'] ?? null ) ? $postarr['post_title'] : '',
				'post_content' => is_string( $postarr['post_content'] ?? null ) ? $postarr['post_content'] : '',
			);
			$meta_input         = $postarr['meta_input'] ?? array();
			if ( is_array( $meta_input ) ) {
				foreach ( $meta_input as $key => $value ) {
					self::update_post_meta( $id, (string) $key, $value );
				}
			}
			return $id;
		}

		/**
		 * Updates one post.
		 *
		 * @param array<string, mixed> $postarr Post fields including ID.
		 */
		public static function update_post( array $postarr ): int {
			$id = is_int( $postarr['ID'] ?? null ) ? $postarr['ID'] : 0;
			if ( ! isset( self::$posts[ $id ] ) ) {
				return 0;
			}
			$status = is_string( $postarr['post_status'] ?? null ) ? $postarr['post_status'] : self::$posts[ $id ]['post_status'];
			if ( $status !== self::$posts[ $id ]['post_status'] ) {
				self::$posts[ $id ]['post_status'] = $status;
			}
			++self::$post_updates;
			return $id;
		}

		/**
		 * Deletes one post and its meta, mirroring a forced WordPress deletion.
		 *
		 * @return array{ID: int, post_type: string, post_name: string, post_status: string, post_parent: int, post_title: string, post_content: string}|null Deleted row, or null when absent.
		 */
		public static function delete_post( int $post_id ): ?array {
			if ( ! isset( self::$posts[ $post_id ] ) ) {
				return null;
			}
			$row = self::$posts[ $post_id ];
			unset( self::$posts[ $post_id ], self::$meta[ $post_id ] );
			++self::$deletes;
			self::$deleted[] = $post_id;
			return $row;
		}

		/**
		 * Stored rows whose parent is the given record.
		 *
		 * @return array<int, array{ID: int, post_type: string, post_name: string, post_status: string, post_parent: int, post_title: string, post_content: string}>
		 */
		public static function children( int $parent_id ): array {
			$children = array();
			foreach ( self::$posts as $id => $post ) {
				if ( $post['post_parent'] === $parent_id ) {
					$children[ $id ] = $post;
				}
			}
			return $children;
		}

		/**
		 * Finds one post by slug and type.
		 *
		 * @return array{ID: int, post_type: string, post_name: string, post_status: string, post_parent: int, post_title: string, post_content: string}|null
		 */
		public static function find_post( string $slug, string $post_type ): ?array {
			foreach ( self::$posts as $post ) {
				if ( $post['post_name'] === $slug && $post['post_type'] === $post_type ) {
					return $post;
				}
			}
			return null;
		}

		/**
		 * Writes one meta value.
		 *
		 * @param mixed $value Meta value.
		 */
		public static function update_post_meta( int $post_id, string $key, mixed $value ): void {
			++self::$meta_writes;
			self::$meta[ $post_id ][ $key ] = $value;
		}

		/**
		 * Reads one meta value.
		 *
		 * @return mixed Stored value or an empty string.
		 */
		public static function get_post_meta( int $post_id, string $key ): mixed {
			return self::$meta[ $post_id ][ $key ] ?? '';
		}

		/** Records one rewrite-rule flush. */
		public static function flush_rewrite_rules(): void {
			++self::$rewrite_flushes;
		}

		/** Total number of post inserts performed since the last counter reset. */
		public static function inserts(): int {
			return self::$inserts;
		}

		/** Total number of post updates performed since the last counter reset. */
		public static function post_updates(): int {
			return self::$post_updates;
		}

		/** Total number of meta writes performed since the last counter reset. */
		public static function meta_writes(): int {
			return self::$meta_writes;
		}

		/** Total number of rewrite flushes performed since the last counter reset. */
		public static function rewrite_flushes(): int {
			return self::$rewrite_flushes;
		}

		/** Total number of post deletions performed since the last counter reset. */
		public static function deletes(): int {
			return self::$deletes;
		}

		/**
		 * Identifiers deleted since the last reset, in deletion order.
		 *
		 * @return array<int, int>
		 */
		public static function deleted(): array {
			return self::$deleted;
		}

		/** Every write kind combined. */
		public static function writes(): int {
			return self::$inserts + self::$post_updates + self::$meta_writes;
		}

		/** Number of stored posts. */
		public static function post_count(): int {
			return count( self::$posts );
		}

		/**
		 * All stored posts.
		 *
		 * @return array<int, array{ID: int, post_type: string, post_name: string, post_status: string, post_parent: int, post_title: string, post_content: string}>
		 */
		public static function posts(): array {
			return self::$posts;
		}

		/**
		 * All stored meta.
		 *
		 * @return array<int, array<string, mixed>>
		 */
		public static function meta(): array {
			return self::$meta;
		}
	}

	/** Minimal stand-in for the WordPress post object. */
	final class SeedPost {
		public int $ID; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.PropertyNotSnakeCase -- Mirrors the WordPress property name.

		public string $post_type;

		public string $post_name;

		public string $post_status;

		public int $post_parent;

		public string $post_title;

		public string $post_content;

		/**
		 * Builds the object from a stored row.
		 *
		 * @param array{ID: int, post_type: string, post_name: string, post_status: string, post_parent: int, post_title: string, post_content: string} $row Stored post row.
		 */
		public function __construct( array $row ) {
			$this->ID           = $row['ID'];
			$this->post_type    = $row['post_type'];
			$this->post_name    = $row['post_name'];
			$this->post_status  = $row['post_status'];
			$this->post_parent  = $row['post_parent'];
			$this->post_title   = $row['post_title'];
			$this->post_content = $row['post_content'];
		}
	}

	/** Minimal stand-in for the WordPress error object. */
	final class SeedError {
		/**
		 * Error payload.
		 *
		 * @param array<string, mixed> $data Error data.
		 */
		public function __construct(
			public readonly string $code = '',
			public readonly string $message = '',
			public readonly array $data = array()
		) {
		}
	}
}

namespace {

	if ( ! defined( 'OBJECT' ) ) {
		define( 'OBJECT', 'OBJECT' );
	}

	if ( ! defined( 'DAY_IN_SECONDS' ) ) {
		define( 'DAY_IN_SECONDS', 86400 );
	}

	if ( ! class_exists( 'WP_Post' ) ) {
		class_alias( \LPS\Tests\SeedPost::class, 'WP_Post' );
	}

	if ( ! class_exists( 'WP_Error' ) ) {
		class_alias( \LPS\Tests\SeedError::class, 'WP_Error' );
	}

	if ( ! function_exists( 'add_action' ) ) {
		/**
		 * Registers a hook callback in the seed runtime.
		 *
		 * @param callable $callback      Hook callback.
		 * @param int      $priority      Hook priority.
		 * @param int      $accepted_args Unused accepted-argument count.
		 */
		function add_action( string $hook, callable $callback, int $priority = 10, int $accepted_args = 1 ): bool {
			unset( $accepted_args );
			\LPS\Tests\WpSeedRuntime::add_hook( $hook, $callback, $priority );
			return true;
		}
	}

	if ( ! function_exists( 'get_option' ) ) {
		/**
		 * Reads one option from the seed runtime.
		 *
		 * @param mixed $default_value Value returned when the option is absent.
		 * @return mixed Stored value.
		 */
		function get_option( string $option, mixed $default_value = false ): mixed {
			return \LPS\Tests\WpSeedRuntime::get_option( $option, $default_value );
		}
	}

	if ( ! function_exists( 'update_option' ) ) {
		/**
		 * Writes one option to the seed runtime.
		 *
		 * @param mixed     $value    Option value.
		 * @param bool|null $autoload Unused autoload flag.
		 */
		function update_option( string $option, mixed $value, ?bool $autoload = null ): bool {
			unset( $autoload );
			\LPS\Tests\WpSeedRuntime::update_option( $option, $value );
			return true;
		}
	}

	if ( ! function_exists( 'get_page_by_path' ) ) {
		/**
		 * Finds one post by path in the seed runtime.
		 *
		 * @param string $output    Unused output format.
		 * @param string $post_type Record type.
		 */
		function get_page_by_path( string $page_path, string $output = 'OBJECT', string $post_type = 'page' ): ?\LPS\Tests\SeedPost {
			unset( $output );
			$row = \LPS\Tests\WpSeedRuntime::find_post( $page_path, $post_type );
			return null === $row ? null : new \LPS\Tests\SeedPost( $row );
		}
	}

	if ( ! function_exists( 'wp_insert_post' ) ) {
		/**
		 * Inserts one post into the seed runtime.
		 *
		 * @param array<string, mixed> $postarr  Post fields.
		 * @param bool                 $wp_error Unused error flag.
		 */
		function wp_insert_post( array $postarr, bool $wp_error = false ): int {
			unset( $wp_error );
			return \LPS\Tests\WpSeedRuntime::insert_post( $postarr );
		}
	}

	if ( ! function_exists( 'wp_update_post' ) ) {
		/**
		 * Updates one post in the seed runtime.
		 *
		 * @param array<string, mixed> $postarr  Post fields.
		 * @param bool                 $wp_error Unused error flag.
		 */
		function wp_update_post( array $postarr, bool $wp_error = false ): int {
			unset( $wp_error );
			return \LPS\Tests\WpSeedRuntime::update_post( $postarr );
		}
	}

	if ( ! function_exists( 'wp_delete_post' ) ) {
		/**
		 * Deletes one post from the seed runtime.
		 *
		 * @param bool $force_delete Unused force flag: the runtime has no trash.
		 * @return \LPS\Tests\SeedPost|false Deleted record, or false when absent.
		 */
		function wp_delete_post( int $postid, bool $force_delete = false ): \LPS\Tests\SeedPost|false {
			unset( $force_delete );
			$row = \LPS\Tests\WpSeedRuntime::delete_post( $postid );
			return null === $row ? false : new \LPS\Tests\SeedPost( $row );
		}
	}

	if ( ! function_exists( 'get_children' ) ) {
		/**
		 * Lists child records of one post in the seed runtime.
		 *
		 * @param array<string, mixed> $args   Query arguments; only post_parent is honoured.
		 * @param string               $output Unused output format.
		 * @return array<int, \LPS\Tests\SeedPost> Child records keyed by identifier.
		 */
		function get_children( array $args = array(), string $output = 'OBJECT' ): array {
			unset( $output );
			$parent   = is_int( $args['post_parent'] ?? null ) ? $args['post_parent'] : 0;
			$children = array();
			foreach ( \LPS\Tests\WpSeedRuntime::children( $parent ) as $id => $row ) {
				$children[ $id ] = new \LPS\Tests\SeedPost( $row );
			}
			return $children;
		}
	}

	if ( ! function_exists( 'get_permalink' ) ) {
		/**
		 * Builds the public URL of one record in the seed runtime.
		 *
		 * @param \LPS\Tests\SeedPost|int $post      Record or identifier.
		 * @param bool                    $leavename Unused name-placeholder flag.
		 * @return string|false Permalink, or false when the record is absent.
		 */
		function get_permalink( \LPS\Tests\SeedPost|int $post = 0, bool $leavename = false ): string|false {
			unset( $leavename );
			if ( is_int( $post ) ) {
				$row = \LPS\Tests\WpSeedRuntime::posts()[ $post ] ?? null;
				if ( null === $row ) {
					return false;
				}
				$post = new \LPS\Tests\SeedPost( $row );
			}
			return 'https://lps.test/' . $post->post_name . '/';
		}
	}

	if ( ! function_exists( 'update_post_meta' ) ) {
		/**
		 * Writes one meta value in the seed runtime.
		 *
		 * @param mixed $meta_value Meta value.
		 */
		function update_post_meta( int $post_id, string $meta_key, mixed $meta_value ): bool {
			\LPS\Tests\WpSeedRuntime::update_post_meta( $post_id, $meta_key, $meta_value );
			return true;
		}
	}

	if ( ! function_exists( 'get_post_meta' ) ) {
		/**
		 * Reads one meta value from the seed runtime.
		 *
		 * @param bool $single Unused single flag.
		 * @return mixed Stored value.
		 */
		function get_post_meta( int $post_id, string $meta_key = '', bool $single = false ): mixed {
			unset( $single );
			return \LPS\Tests\WpSeedRuntime::get_post_meta( $post_id, $meta_key );
		}
	}

	if ( ! function_exists( 'is_wp_error' ) ) {
		/**
		 * Mirrors the WordPress error predicate.
		 *
		 * @param mixed $thing Value under test.
		 */
		function is_wp_error( mixed $thing ): bool {
			return $thing instanceof \LPS\Tests\SeedError;
		}
	}

	if ( ! function_exists( 'flush_rewrite_rules' ) ) {
		/**
		 * Records a rewrite-rule flush.
		 *
		 * @param bool $hard Unused hard-flush flag.
		 */
		function flush_rewrite_rules( bool $hard = true ): void {
			unset( $hard );
			\LPS\Tests\WpSeedRuntime::flush_rewrite_rules();
		}
	}
}
