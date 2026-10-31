<?php
/**
 * Alternative text of images in the visitor's language (featured images,
 * Elementor images…). Images are shared by all languages; their alt text is
 * translated with the content that uses them.
 *
 * @package GustinAI\Multilingual
 */

namespace GustinAI\Multilingual\Frontend;

use GustinAI\Multilingual\Core\Router;
use GustinAI\Multilingual\Languages;

defined( 'ABSPATH' ) || exit;

final class Media {

	public static function init(): void {
		add_filter( 'get_post_metadata', array( self::class, 'alt' ), 10, 4 );
	}

	/**
	 * @param mixed  $value
	 * @param int    $object_id
	 * @param string $meta_key
	 * @param bool   $single
	 * @return mixed
	 */
	public static function alt( $value, $object_id, $meta_key, $single ) {
		if ( '_wp_attachment_image_alt' !== $meta_key || null !== $value ) {
			return $value;
		}
		$lang = Router::current();
		if ( null === $lang || Languages::is_default( $lang ) || Router::is_neutral() || is_admin() ) {
			return $value;
		}
		$translated = get_post_meta( (int) $object_id, '_gaiml_alt_' . $lang, true );
		if ( is_string( $translated ) && '' !== $translated ) {
			return array( $translated );
		}
		return $value;
	}
}
