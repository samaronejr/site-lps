<?php
/**
 * WordPress adapter for governed attachments and public media use.
 *
 * @package LPS\ContentModel
 */

declare(strict_types=1);

namespace LPS\ContentModel;

use WP_Error;
use WP_Post;
use WP_REST_Request;

/** Connects media policy to metadata, REST, editor, and publication boundaries. */
final class Media {
	/** Pending typed publication errors.
	 *
	 * @var array<string, string>
	 */
	private static array $pending_errors = array();

	/** Whether REST filters were registered.
	 *
	 * @var bool
	 */
	private static bool $rest_filters_registered = false;

	/** Registers hooks that must exist before init. */
	public static function boot(): void {
		MediaUploads::boot();
		MediaEditor::boot();
		add_filter( 'wp_insert_post_data', array( self::class, 'validate_direct_publish' ), 20, 4 );
		add_filter( 'allowed_block_types_all', array( self::class, 'allowed_blocks' ) );
		add_action( 'admin_notices', array( self::class, 'admin_notices' ) );
	}

	/** Registers attachment metadata, blocks, and REST publication gates. */
	public static function register(): void {
		foreach ( MediaContracts::attachment_fields() as $key => $definition ) {
			register_post_meta( 'attachment', $key, $definition );
		}
		MediaBlocks::register();
		if ( self::$rest_filters_registered ) {
			return;
		}
		foreach ( array_merge( array( 'post' ), array_keys( Contracts::post_types() ) ) as $post_type ) {
			add_filter( 'rest_pre_insert_' . $post_type, array( self::class, 'validate_rest_publish' ), 20, 2 );
		}
		self::$rest_filters_registered = true;
	}

	/**
	 * Returns the governed public values for one attachment.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return array<string, mixed>
	 */
	public static function attachment_values( int $attachment_id ): array {
		$metadata = wp_get_attachment_metadata( $attachment_id );
		$metadata = is_array( $metadata ) ? $metadata : array();
		$file     = get_attached_file( $attachment_id );
		$url      = wp_get_attachment_url( $attachment_id );
		$srcset   = wp_get_attachment_image_srcset( $attachment_id, 'full' );
		return array(
			'id'                => $attachment_id,
			'mime'              => (string) get_post_mime_type( $attachment_id ),
			'bytes'             => is_string( $file ) && is_file( $file ) ? (int) filesize( $file ) : 0,
			'url'               => is_string( $url ) ? $url : '',
			'filename'          => is_string( $file ) ? basename( $file ) : '',
			'width'             => Policy::sanitize_integer( get_post_meta( $attachment_id, '_lps_media_width', true ) ),
			'height'            => Policy::sanitize_integer( get_post_meta( $attachment_id, '_lps_media_height', true ) ),
			'duration'          => MediaPolicy::sanitize_duration( get_post_meta( $attachment_id, '_lps_media_duration', true ) ),
			'credit'            => MediaPolicy::string_value( get_post_meta( $attachment_id, '_lps_media_credit', true ) ),
			'rights_holder'     => MediaPolicy::string_value( get_post_meta( $attachment_id, '_lps_media_rights_holder', true ) ),
			'license'           => MediaPolicy::string_value( get_post_meta( $attachment_id, '_lps_media_license', true ) ),
			'source_url'        => MediaPolicy::string_value( get_post_meta( $attachment_id, '_lps_media_source_url', true ) ),
			'checksum'          => MediaPolicy::string_value( get_post_meta( $attachment_id, '_lps_media_checksum', true ) ),
			'rights_status'     => MediaPolicy::string_value( get_post_meta( $attachment_id, '_lps_media_rights_status', true ) ),
			'privacy_status'    => MediaPolicy::string_value( get_post_meta( $attachment_id, '_lps_media_privacy_status', true ) ),
			'transcript_status' => MediaPolicy::string_value( get_post_meta( $attachment_id, '_lps_media_transcript_status', true ) ),
			'caption_status'    => MediaPolicy::string_value( get_post_meta( $attachment_id, '_lps_media_caption_status', true ) ),
			'document_status'   => MediaPolicy::string_value( get_post_meta( $attachment_id, '_lps_media_document_status', true ) ),
			'srcset'            => is_string( $srcset ) ? $srcset : '',
			'metadata'          => $metadata,
		);
	}

	/**
	 * Resolves the first governed image usage declared in one record's content.
	 *
	 * Records expose homepage and surface imagery through the locked media
	 * blocks (`lps/media`, `lps/figure`, `lps/gallery`), never through raw URL
	 * fields. The returned pair is unvalidated on purpose: the caller re-checks
	 * it through `MediaPolicy::usage_errors()` at render time so a rights or
	 * privacy change after publication still fails closed.
	 *
	 * @param WP_Post $post Record whose content declares media usages.
	 * @return array{usage: array<string, mixed>, asset: array<string, mixed>}|array{}
	 */
	public static function record_image( WP_Post $post ): array {
		$usages = array();
		$errors = array();
		self::collect_blocks( parse_blocks( (string) $post->post_content ), $usages, $errors );
		foreach ( $usages as $usage ) {
			if ( ! in_array( $usage['block'] ?? '', array( 'image', 'figure', 'gallery' ), true ) ) {
				continue;
			}
			return array(
				'usage' => $usage,
				'asset' => self::attachment_values( Policy::sanitize_integer( $usage['media_id'] ?? 0 ) ),
			);
		}
		return array();
	}

	/**
	 * Prevents direct publication when media use is invalid.
	 *
	 * @param array<string, mixed> $data Prepared database values.
	 * @param array<string, mixed> $postarr Parsed input.
	 * @param array<string, mixed> $unsanitized Raw input.
	 * @param bool                 $update Whether this is an update.
	 * @return array<string, mixed>
	 */
	public static function validate_direct_publish( array $data, array $postarr, array $unsanitized, bool $update ): array {
		unset( $unsanitized );
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return $data;
		}
		$post_type = MediaPolicy::string_value( $data['post_type'] ?? '' );
		$governed  = 'post' === $post_type || isset( Contracts::post_types()[ $post_type ] );
		if ( 'publish' !== ( $data['post_status'] ?? '' ) || ! $governed ) {
			return $data;
		}
		$content = is_string( $data['post_content'] ?? null ) ? $data['post_content'] : '';
		$errors  = self::content_errors( $content );
		if ( array() === $errors ) {
			return $data;
		}
		self::$pending_errors = $errors;
		$post_id              = is_numeric( $postarr['ID'] ?? null ) ? (int) $postarr['ID'] : 0;
		$data['post_status']  = $update && 0 < $post_id ? (string) get_post_status( $post_id ) : 'draft';
		return $data;
	}

	/**
	 * Validates REST publication before any public mutation.
	 *
	 * @param mixed           $prepared Prepared record or prior error.
	 * @param WP_REST_Request $request REST request.
	 */
	public static function validate_rest_publish( mixed $prepared, WP_REST_Request $request ): mixed {
		if ( $prepared instanceof WP_Error ) {
			return $prepared;
		}
		$status = is_object( $prepared ) && isset( $prepared->post_status ) && is_string( $prepared->post_status ) ? $prepared->post_status : MediaPolicy::string_value( $request->get_param( 'status' ) );
		if ( 'publish' !== $status ) {
			return $prepared;
		}
		$content = is_object( $prepared ) && isset( $prepared->post_content ) && is_string( $prepared->post_content ) ? $prepared->post_content : MediaPolicy::string_value( $request->get_param( 'content' ) );
		if ( '' === $content ) {
			$post_id = MediaPolicy::string_value( $request->get_param( 'id' ) );
			$post    = '' === $post_id ? null : get_post( (int) $post_id );
			$content = $post instanceof WP_Post ? $post->post_content : '';
		}
		$errors = self::content_errors( $content );
		if ( array() === $errors ) {
			return $prepared;
		}
		$code = (string) reset( $errors );
		return new WP_Error(
			$code,
			__( 'Public media is blocked. Resolve every listed rights, privacy, accessibility, and playback error.', 'lps-content-model' ),
			array(
				'status' => 400,
				'fields' => $errors,
			)
		);
	}

	/**
	 * Returns publication errors for serialized block content.
	 *
	 * @param string $content Serialized block content.
	 * @return array<string, string>
	 */
	public static function content_errors( string $content ): array {
		$blocks = array_values( parse_blocks( $content ) );
		/* @var array<int, array<string, mixed>> $usages */
		$usages = array();
		/* @var array<string, string> $errors */
		$errors = array();
		self::collect_blocks( $blocks, $usages, $errors );
		$assets = array();
		foreach ( $usages as $usage ) {
			$media_id = Policy::sanitize_integer( $usage['media_id'] ?? 0 );
			if ( 0 < $media_id && ! isset( $assets[ $media_id ] ) ) {
				$assets[ $media_id ] = self::attachment_values( $media_id );
			}
		}
		return array_merge( $errors, MediaPolicy::collection_errors( $usages, $assets ) );
	}

	/**
	 * Removes bypassable core media blocks and exposes the governed set.
	 *
	 * @param bool|array<int, string> $allowed Current allowlist.
	 * @return array<int, string>
	 */
	public static function allowed_blocks( bool|array $allowed ): array {
		/* @var array<int, string> $blocks */
		$blocks  = is_array( $allowed ) ? $allowed : array_keys( \WP_Block_Type_Registry::get_instance()->get_all_registered() );
		$blocked = array( 'core/audio', 'core/cover', 'core/embed', 'core/file', 'core/gallery', 'core/html', 'core/image', 'core/media-text', 'core/video' );
		$blocks  = array_values( array_diff( $blocks, $blocked ) );
		return array_values( array_unique( array_merge( $blocks, array_keys( MediaBlocks::definitions() ) ) ) );
	}

	/** Emits actionable classic-editor denials. */
	public static function admin_notices(): void {
		foreach ( self::$pending_errors as $field => $code ) {
			printf(
				'<div class="notice notice-error"><p><code>%s</code>: %s</p></div>',
				esc_html( $code ),
				// translators: %s is the machine field path that blocked publication.
				esc_html( sprintf( __( 'Media publication blocked at %s. Correct the attachment or its contextual block fields.', 'lps-content-model' ), $field ) )
			);
		}
		self::$pending_errors = array();
	}

	/**
	 * Collects governed uses and bypass attempts recursively.
	 *
	 * @param array<int, array<mixed>>         $blocks Parsed blocks.
	 * @param array<int, array<string, mixed>> $usages Collected uses.
	 * @param array<string, string>            $errors Typed errors.
	 */
	private static function collect_blocks( array $blocks, array &$usages, array &$errors ): void {
		$forbidden = array( 'core/audio', 'core/cover', 'core/embed', 'core/file', 'core/gallery', 'core/html', 'core/image', 'core/media-text', 'core/video' );
		foreach ( $blocks as $index => $block ) {
			$name = is_string( $block['blockName'] ?? null ) ? $block['blockName'] : '';
			if ( in_array( $name, $forbidden, true ) ) {
				$errors[ "block:$index" ] = 'lps_media_governed_block_required';
			}
			$usages       = array_merge( $usages, MediaBlocks::usages( $block ) );
			$inner        = is_array( $block['innerBlocks'] ?? null ) ? $block['innerBlocks'] : array();
			$inner_blocks = array();
			foreach ( $inner as $child ) {
				if ( is_array( $child ) ) {
					$inner_blocks[] = $child;
				}
			}
			self::collect_blocks( $inner_blocks, $usages, $errors );
		}
	}
}
