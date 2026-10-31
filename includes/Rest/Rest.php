<?php
/**
 * REST API: language and translations of every translatable post, and
 * ?lang=xx to list the content of one language (headless sites, apps).
 *
 * @package GustinAI\Multilingual
 */

namespace GustinAI\Multilingual\Rest;

use GustinAI\Multilingual\Core\Content;
use GustinAI\Multilingual\Core\Translations;

defined( 'ABSPATH' ) || exit;

final class Rest {

	public static function init(): void {
		add_action( 'rest_api_init', array( self::class, 'register' ) );
	}

	public static function register(): void {
		foreach ( Content::post_types() as $post_type ) {
			if ( ! post_type_exists( $post_type ) ) {
				continue;
			}
			register_rest_field(
				$post_type,
				'gaiml_lang',
				array(
					'get_callback' => static fn( $post ) => Translations::lang_or_default( 'post', (int) $post['id'] ),
					'schema'       => array(
						'description' => __( 'Language code of the content.', 'gustinai-multilingual' ),
						'type'        => 'string',
						'context'     => array( 'view', 'edit', 'embed' ),
						'readonly'    => true,
					),
				)
			);
			register_rest_field(
				$post_type,
				'gaiml_translations',
				array(
					'get_callback' => static function ( $post ) {
						$out = array();
						foreach ( Translations::members( 'post', (int) $post['id'] ) as $lang => $id ) {
							if ( 'publish' === get_post_status( $id ) || current_user_can( 'edit_post', $id ) ) {
								$out[ $lang ] = $id;
							}
						}
						return $out;
					},
					'schema'       => array(
						'description' => __( 'Translations of the content: language code => post ID.', 'gustinai-multilingual' ),
						'type'        => 'object',
						'context'     => array( 'view', 'edit' ),
						'readonly'    => true,
					),
				)
			);
		}
	}
}
