<?php
/**
 * Who may translate what.
 *
 * Translating an original requires editing it (and publishing it when it is
 * published). Overwriting an existing translation also requires editing that
 * translation (and publishing it when it is published): a contributor cannot
 * replace a live translation by sending their draft original to the AI.
 *
 * @package GustinAI\Multilingual
 */

namespace GustinAI\Multilingual\Admin;

use GustinAI\Multilingual\Core\Content;
use GustinAI\Multilingual\Core\Translations;

defined( 'ABSPATH' ) || exit;

final class Permissions {

	public static function can_translate( string $type, int $id ): bool {
		if ( 'term' === $type ) {
			$term = get_term( $id );
			return $term instanceof \WP_Term && Content::is_translatable_taxonomy( $term->taxonomy ) && current_user_can( 'edit_term', $id );
		}
		$post = get_post( $id );
		return $post instanceof \WP_Post && Content::is_translatable_post( $post ) && self::can_write( $post );
	}

	/**
	 * Languages among $langs that the current user may (re)translate $id into.
	 *
	 * @param string[] $langs
	 * @return string[]
	 */
	public static function allowed_langs( string $type, int $id, array $langs ): array {
		$out = array();
		foreach ( $langs as $lang ) {
			$target = Translations::translation( $type, $id, $lang );
			if ( $target && $target !== $id && ! self::can_overwrite( $type, $target ) ) {
				continue;
			}
			$out[] = $lang;
		}
		return $out;
	}

	public static function can_overwrite( string $type, int $target ): bool {
		if ( 'term' === $type ) {
			return current_user_can( 'edit_term', $target );
		}
		$post = get_post( $target );
		if ( ! $post instanceof \WP_Post || in_array( $post->post_status, array( 'trash', 'auto-draft' ), true ) ) {
			// Replaced by a new translation, the old post is not modified.
			return true;
		}
		return self::can_write( $post );
	}

	/**
	 * Edit rights, plus publish rights for published content.
	 */
	private static function can_write( \WP_Post $post ): bool {
		if ( ! current_user_can( 'edit_post', $post->ID ) ) {
			return false;
		}
		if ( 'publish' !== $post->post_status && 'future' !== $post->post_status ) {
			return true;
		}
		$object = get_post_type_object( $post->post_type );
		return $object instanceof \WP_Post_Type && current_user_can( $object->cap->publish_posts );
	}

	/**
	 * Whether the current user may see the queue status of an object.
	 */
	public static function can_view( string $type, int $id ): bool {
		if ( 'strings' === $type ) {
			return current_user_can( 'manage_options' );
		}
		return 'term' === $type ? current_user_can( 'edit_term', $id ) : current_user_can( 'edit_post', $id );
	}
}
