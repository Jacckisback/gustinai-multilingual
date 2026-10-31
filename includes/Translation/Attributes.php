<?php
/**
 * Translatable values inside JSON structures: Gutenberg block attributes,
 * Divi 5 block attributes and Elementor data.
 *
 * Divi 5 stores module texts under "innerContent" (…innerContent.desktop.value),
 * core and third-party blocks use keys such as label, placeholder, buttonText.
 * Design settings (style, layout, module…) are never touched.
 *
 * @package GustinAI\Multilingual
 */

namespace GustinAI\Multilingual\Translation;

defined( 'ABSPATH' ) || exit;

final class Attributes {

	private const KEYS = array( 'label', 'placeholder', 'buttonText', 'moreText', 'content', 'title', 'heading', 'subheading', 'subtitle', 'description', 'text', 'caption', 'alt', 'altText', 'tooltip', 'prefix', 'suffix', 'summary', 'question', 'answer', 'linkText', 'buttonLabel', 'submitText', 'message', 'emptyMessage', 'citation' );

	private const SUFFIX = '~[a-z](Text|Label|Title|Heading|Caption|Placeholder|Description|Message)$~';

	private const SKIP_KEYS = array( 'style', 'layout', 'metadata', 'lock', 'className', 'anchor', 'align', 'backgroundColor', 'textColor', 'fontSize', 'fontFamily', 'gradient', 'borderColor', 'linkTarget', 'rel', 'id', 'ref', 'slug', 'tagName', 'templateLock', 'providerNameSlug', 'type', 'kind', 'mediaId', 'mediaUrl', 'mediaType', 'src', 'sizeSlug', 'linkDestination', 'module', 'builderVersion', 'icon', 'iconName', 'svg', 'className', 'overlayColor', 'customOverlayColor', 'focalPoint', 'dimRatio', 'backgroundImage', 'query', 'namespace', 'taxonomy', 'postType', 'templateSlug', 'theme', 'area' );

	private const INNER_KEYS = array( 'value', 'text', 'title', 'alt', 'titleText', 'linkText', 'label', 'placeholder', 'caption', 'heading', 'subtitle', 'content', 'description', 'buttonText' );

	private const URL_KEYS = array( 'url', 'href', 'link', 'linkUrl' );

	/**
	 * Replaces translatable leaves by renderers (closures).
	 *
	 * @param mixed                $data
	 * @param array<string, mixed> $opts
	 * @return mixed
	 */
	public static function walk( $data, Bag $bag, array $opts, bool &$found, string $key = '', bool $inner = false, string $block = '' ) {
		if ( is_string( $data ) ) {
			if ( in_array( $key, self::URL_KEYS, true ) ) {
				if ( '' !== $data && isset( $opts['link'] ) && is_callable( $opts['link'] ) ) {
					$localized = (string) call_user_func( $opts['link'], $data );
					if ( $localized !== $data ) {
						$found = true;
						return $localized;
					}
				}
				return $data;
			}
			$translatable = $inner
				? in_array( $key, self::INNER_KEYS, true )
				: ( in_array( $key, self::KEYS, true ) || (bool) preg_match( self::SUFFIX, $key ) );
			if ( ! $translatable || ! Placeholders::looks_like_text( $data ) ) {
				return $data;
			}
			$found = true;
			return Html::value( $data, $bag, $opts, strlen( $data ) > 120 ? 'text' : 'label' );
		}
		if ( is_array( $data ) || is_object( $data ) ) {
			$is_object = is_object( $data );
			$copy      = $is_object ? clone $data : $data;
			foreach ( ( $is_object ? get_object_vars( $data ) : $data ) as $k => $value ) {
				$child_key = is_int( $k ) ? $key : (string) $k;
				if ( ! $inner && ! is_int( $k ) && in_array( $child_key, self::SKIP_KEYS, true ) ) {
					continue;
				}
				$child = self::walk( $value, $bag, $opts, $found, $child_key, $inner || 'innerContent' === $child_key, $block );
				if ( $is_object ) {
					$copy->$k = $child;
				} else {
					$copy[ $k ] = $child;
				}
			}
			return $copy;
		}
		return $data;
	}

	/**
	 * Replaces renderers by their output.
	 *
	 * @param mixed                 $data
	 * @param array<string, string> $tr
	 * @return mixed
	 */
	public static function render( $data, array $tr ) {
		if ( $data instanceof \Closure ) {
			return $data( $tr );
		}
		if ( is_array( $data ) ) {
			foreach ( $data as $k => $value ) {
				$data[ $k ] = self::render( $value, $tr );
			}
			return $data;
		}
		if ( is_object( $data ) ) {
			$copy = clone $data;
			foreach ( get_object_vars( $copy ) as $k => $value ) {
				$copy->$k = self::render( $value, $tr );
			}
			return $copy;
		}
		return $data;
	}

	/**
	 * Block attributes JSON, encoded exactly like serialize_block_attributes().
	 *
	 * @param mixed $attributes
	 */
	public static function serialize( $attributes ): string {
		$json = (string) json_encode( $attributes, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
		return strtr(
			$json,
			array(
				'\\\\' => '\\u005c',
				'--'   => '\\u002d\\u002d',
				'<'    => '\\u003c',
				'>'    => '\\u003e',
				'&'    => '\\u0026',
				'\\"'  => '\\u0022',
			)
		);
	}
}
