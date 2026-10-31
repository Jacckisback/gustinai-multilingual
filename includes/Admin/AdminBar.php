<?php
/**
 * Toolbar menu on the front end: language of the page, links to its
 * translations and one-click AI translation (handy with Elementor and Divi,
 * whose editors run on the front end).
 *
 * @package GustinAI\Multilingual
 */

namespace GustinAI\Multilingual\Admin;

use GustinAI\Multilingual\Core\Content;
use GustinAI\Multilingual\Core\Router;
use GustinAI\Multilingual\Core\Translations;
use GustinAI\Multilingual\Languages;
use GustinAI\Multilingual\Translation\Engine;

defined( 'ABSPATH' ) || exit;

final class AdminBar {

	public static function init(): void {
		add_action( 'admin_bar_menu', array( self::class, 'menu' ), 90 );
	}

	/**
	 * @param \WP_Admin_Bar $bar
	 */
	public static function menu( $bar ): void {
		if ( is_admin() || ! is_admin_bar_showing() || ! current_user_can( 'edit_posts' ) || ! Languages::is_multilingual() ) {
			return;
		}
		$current = Router::current() ?? Languages::default_code();
		$bar->add_node(
			array(
				'id'    => 'gaiml',
				'title' => '<span class="ab-icon dashicons dashicons-translation" aria-hidden="true"></span>' . esc_html( strtoupper( (string) ( Languages::get( $current )['slug'] ?? $current ) ) ),
				'href'  => admin_url( 'admin.php?page=gaiml' ),
				'meta'  => array( 'title' => __( 'Languages', 'gustinai-multilingual' ) ),
			)
		);
		$id   = is_singular() ? (int) get_queried_object_id() : 0;
		$post = $id ? get_post( $id ) : null;
		if ( ! $post || ! Content::is_translatable_post_type( $post->post_type ) || ! current_user_can( 'edit_post', $id ) ) {
			return;
		}
		$source  = Engine::source_of( 'post', $id );
		$members = Translations::members( 'post', $id );
		foreach ( Languages::all() as $lang => $language ) {
			if ( isset( $members[ $lang ] ) ) {
				$member = $members[ $lang ];
				$bar->add_node(
					array(
						'parent' => 'gaiml',
						'id'     => 'gaiml-' . $lang,
						'title'  => esc_html( $language['name'] ) . ( $member === $id ? ' ✓' : '' ),
						'href'   => (string) get_permalink( $member ),
					)
				);
				$bar->add_node(
					array(
						'parent' => 'gaiml',
						'id'     => 'gaiml-edit-' . $lang,
						/* translators: %s: language name. */
						'title'  => '&nbsp;&nbsp;' . esc_html( sprintf( __( 'Edit (%s)', 'gustinai-multilingual' ), $language['name'] ) ),
						'href'   => (string) get_edit_post_link( $member, 'url' ),
					)
				);
			} elseif ( $lang !== Translations::lang_or_default( 'post', $source ) ) {
				$bar->add_node(
					array(
						'parent' => 'gaiml',
						'id'     => 'gaiml-translate-' . $lang,
						/* translators: %s: language name. */
						'title'  => esc_html( sprintf( __( 'Translate into %s with AI', 'gustinai-multilingual' ), $language['name'] ) ),
						'href'   => wp_nonce_url( admin_url( 'admin-post.php?action=gaiml_translate&post=' . $source . '&lang=' . rawurlencode( $lang ) ), 'gaiml_translate_' . $source ),
					)
				);
			}
		}
	}
}
