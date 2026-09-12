<?php
/**
 * Locked dynamic media block contracts.
 *
 * @package LPS\ContentModel
 */

declare(strict_types=1);

namespace LPS\ContentModel;

/** Defines and normalizes the only public media blocks. */
final class MediaBlocks {
	/**
	 * Returns block schemas.
	 *
	 * @return array<string, array{api_version: int, supports: array{html: bool, reusable: bool}, attributes: array<string, array<string, mixed>>}>
	 */
	public static function definitions(): array {
		$shared           = array(
			'api_version' => 3,
			'supports'    => array(
				'html'     => false,
				'reusable' => false,
			),
		);
		$contextual_image = array(
			'mediaId'    => array( 'type' => 'integer' ),
			'locale'     => array( 'type' => 'string' ),
			'alt'        => array( 'type' => 'string' ),
			'decorative' => array(
				'type'    => 'boolean',
				'default' => false,
			),
			'caption'    => array( 'type' => 'string' ),
			'context'    => array( 'type' => 'string' ),
			'placement'  => array(
				'type'    => 'string',
				'default' => 'content',
			),
		);
		return array(
			'lps/media'   => $shared + array(
				'attributes' => array(
					'mediaId'        => array( 'type' => 'integer' ),
					'locale'         => array( 'type' => 'string' ),
					'kind'           => array( 'type' => 'string' ),
					'label'          => array( 'type' => 'string' ),
					'alt'            => array( 'type' => 'string' ),
					'decorative'     => array(
						'type'    => 'boolean',
						'default' => false,
					),
					'caption'        => array( 'type' => 'string' ),
					'context'        => array( 'type' => 'string' ),
					'essential'      => array(
						'type'    => 'boolean',
						'default' => false,
					),
					'htmlEquivalent' => array( 'type' => 'string' ),
					'placement'      => array(
						'type'    => 'string',
						'default' => 'content',
					),
				),
			),
			'lps/figure'  => $shared + array( 'attributes' => $contextual_image ),
			'lps/gallery' => $shared + array(
				'attributes' => array(
					'locale' => array( 'type' => 'string' ),
					'items'  => array(
						'type'    => 'array',
						'default' => array(),
					),
				),
			),
			'lps/video'   => $shared + array(
				'attributes' => array(
					'mediaId'       => array( 'type' => 'integer' ),
					'locale'        => array( 'type' => 'string' ),
					'label'         => array( 'type' => 'string' ),
					'caption'       => array( 'type' => 'string' ),
					'transcriptUrl' => array( 'type' => 'string' ),
					'captionsUrl'   => array( 'type' => 'string' ),
					'autoplay'      => array(
						'type'    => 'boolean',
						'default' => false,
					),
					'iframe'        => array(
						'type'    => 'boolean',
						'default' => false,
					),
					'placement'     => array(
						'type'    => 'string',
						'default' => 'content',
					),
				),
			),
		);
	}

	/** Registers server-rendered blocks. */
	public static function register(): void {
		foreach ( self::definitions() as $name => $definition ) {
			$attributes = $definition['attributes'];
			register_block_type(
				$name,
				array(
					'attributes'      => $attributes,
					'supports'        => array(
						'html'     => false,
						'reusable' => false,
					),
					'render_callback' => array( self::class, 'render' ),
				)
			);
		}
	}

	/**
	 * Renders a validated dynamic block.
	 *
	 * @param array<string, mixed> $attributes Block attributes.
	 * @param string               $content Saved content.
	 * @param object               $block Block instance.
	 */
	public static function render( array $attributes, string $content, object $block ): string {
		unset( $content );
		$name   = property_exists( $block, 'name' ) && is_string( $block->name ) ? $block->name : '';
		$usages = self::usages(
			array(
				'blockName' => $name,
				'attrs'     => $attributes,
			)
		);
		$output = '';
		foreach ( $usages as $usage ) {
			$asset = Media::attachment_values( Policy::sanitize_integer( $usage['media_id'] ?? 0 ) );
			if ( array() !== MediaPolicy::usage_errors( $usage, $asset ) ) {
				return '';
			}
			$output .= match ( $usage['block'] ) {
				'video' => true === ( $usage['iframe'] ?? false ) ? MediaPolicy::render_iframe( $usage, $asset ) : MediaPolicy::render_video( $usage, $asset ),
				'document' => MediaPolicy::render_document( $usage, $asset ),
				default => MediaPolicy::render_image( $usage, $asset ),
			};
		}
		return $output;
	}

	/**
	 * Normalizes one parsed block into usages.
	 *
	 * @param array<mixed> $block Parsed block.
	 * @return array<int, array<string, mixed>>
	 */
	public static function usages( array $block ): array {
		$name = self::value( $block['blockName'] ?? '' );
		/* @var array<string, mixed> $attrs */
		$attrs = is_array( $block['attrs'] ?? null ) ? $block['attrs'] : array();
		if ( 'lps/gallery' === $name ) {
			/* @var array<int, mixed> $items */
			$items  = is_array( $attrs['items'] ?? null ) ? array_values( $attrs['items'] ) : array();
			$result = array();
			foreach ( $items as $item ) {
				/* @var array<string, mixed> $item_attributes */
				$item_attributes = is_array( $item ) ? $item : array();
				$result[]        = self::normalize( $item_attributes, 'gallery', self::value( $attrs['locale'] ?? '' ) );
			}
			return $result;
		}
		$kind = match ( $name ) {
			'lps/video' => 'video',
			'lps/media' => self::value( $attrs['kind'] ?? 'image' ),
			'lps/figure' => 'figure',
			default => '',
		};
		return '' === $kind ? array() : array( self::normalize( $attrs, $kind, self::value( $attrs['locale'] ?? '' ) ) );
	}

	/**
	 * Normalizes block attributes.
	 *
	 * @param array<mixed> $attributes Block attributes.
	 * @param string       $kind Media kind.
	 * @param string       $locale Usage locale.
	 * @return array<string, mixed>
	 */
	private static function normalize( array $attributes, string $kind, string $locale ): array {
		return array(
			'block'           => $kind,
			'media_id'        => Policy::sanitize_integer( $attributes['mediaId'] ?? 0 ),
			'locale'          => $locale,
			'alt'             => self::value( $attributes['alt'] ?? '' ),
			'decorative'      => true === ( $attributes['decorative'] ?? false ),
			'caption'         => self::value( $attributes['caption'] ?? '' ),
			'context'         => self::value( $attributes['context'] ?? '' ),
			'label'           => self::value( $attributes['label'] ?? '' ),
			'essential'       => true === ( $attributes['essential'] ?? false ),
			'html_equivalent' => self::value( $attributes['htmlEquivalent'] ?? '' ),
			'transcript_url'  => self::value( $attributes['transcriptUrl'] ?? '' ),
			'captions_url'    => self::value( $attributes['captionsUrl'] ?? '' ),
			'autoplay'        => true === ( $attributes['autoplay'] ?? false ),
			'iframe'          => true === ( $attributes['iframe'] ?? false ),
			'placement'       => self::value( $attributes['placement'] ?? 'content' ),
		);
	}

	/**
	 * Converts a boundary value to a string.
	 *
	 * @param mixed $value Boundary value.
	 */
	private static function value( mixed $value ): string {
		return is_string( $value ) ? $value : '';
	}
}
