<?php
/**
 * Elementor: templates (header, footer, popups, sections, global widgets)
 * are swapped for their translation on the front end.
 *
 * Translate the "Templates" post type (elementor_library) in the settings:
 * the display conditions of the original template keep applying.
 *
 * @package GustinAI\Multilingual
 */

namespace GustinAI\Multilingual\Compat;

use GustinAI\Multilingual\Core\Content;
use GustinAI\Multilingual\Core\Router;
use GustinAI\Multilingual\Core\Translations;

defined( 'ABSPATH' ) || exit;

final class Elementor {

	public static function init(): void {
		add_filter( 'elementor/documents/get/post_id', array( self::class, 'template_id' ) );
		add_filter( 'elementor/theme/get_location_templates/template_id', array( self::class, 'template_id' ) );
	}

	/**
	 * @param int|string $post_id
	 * @return int|string
	 */
	public static function template_id( $post_id ) {
		$lang = Router::current();
		if ( null === $lang || is_admin() || Router::is_neutral() || ! Content::is_translatable_post_type( 'elementor_library' ) ) {
			return $post_id;
		}
		$id          = (int) $post_id;
		$translation = $id > 0 ? Translations::translation( 'post', $id, $lang ) : 0;
		if ( ! $translation || $translation === $id || 'elementor_library' !== get_post_type( $id ) || 'publish' !== get_post_status( $translation ) ) {
			return $post_id;
		}
		$post = get_post( $id );
		return ( $post && ! Content::is_excluded( $post ) ) ? $translation : $post_id;
	}
}
