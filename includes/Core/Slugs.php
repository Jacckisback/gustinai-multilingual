<?php
/**
 * Slugs: the same slug can be used in several languages (/contact/ and /en/contact/)
 * because every URL carries its language. Uniqueness is checked within a language.
 *
 * @package GustinAI\Multilingual
 */

namespace GustinAI\Multilingual\Core;

use GustinAI\Multilingual\Languages;

defined( 'ABSPATH' ) || exit;

final class Slugs {

	public static function init(): void {
		add_filter( 'pre_wp_unique_post_slug', array( self::class, 'unique_post_slug' ), 10, 6 );
	}

	/**
	 * @param string|null $override
	 * @param string      $slug
	 * @param int         $post_id
	 * @param string      $post_status
	 * @param string      $post_type
	 * @param int         $post_parent
	 * @return string|null
	 */
	public static function unique_post_slug( $override, $slug, $post_id, $post_status, $post_type, $post_parent ) {
		global $wp_rewrite;
		if ( null !== $override || 'attachment' === $post_type || ! Content::is_translatable_post_type( (string) $post_type ) || ! Languages::is_ready() ) {
			return $override;
		}
		$slug         = (string) $slug;
		$hierarchical = is_post_type_hierarchical( (string) $post_type );
		$feeds        = is_array( $wp_rewrite->feeds ) ? $wp_rewrite->feeds : array();
		// Reserved words and numeric slugs: let WordPress handle them.
		if ( '' === $slug || in_array( $slug, $feeds, true ) || 'embed' === $slug || preg_match( '/^[0-9]+$/', $slug )
			|| ( $hierarchical && preg_match( "@^({$wp_rewrite->pagination_base})?\d+$@", $slug ) ) ) {
			return null;
		}
		$lang      = Content::pending_language() ?? Translations::lang_or_default( 'post', (int) $post_id );
		$is_root   = ! $hierarchical || ! $post_parent;
		$candidate = $slug;
		$suffix    = 2;
		while ( self::post_slug_taken( $candidate, (int) $post_id, (string) $post_type, (int) $post_parent, $hierarchical, $lang )
			|| ( $is_root && Languages::is_reserved_slug( $candidate ) )
			|| ( $hierarchical && apply_filters( 'wp_unique_post_slug_is_bad_hierarchical_slug', false, $candidate, $post_type, $post_parent ) )
			|| ( ! $hierarchical && apply_filters( 'wp_unique_post_slug_is_bad_flat_slug', false, $candidate, $post_type ) ) ) {
			$candidate = _truncate_post_slug( $slug, 200 - ( strlen( (string) $suffix ) + 1 ) ) . "-$suffix";
			++$suffix;
		}
		/** This filter is documented in wp-includes/post.php */
		return apply_filters( 'wp_unique_post_slug', $candidate, $post_id, $post_status, $post_type, $post_parent, $slug );
	}

	private static function post_slug_taken( string $slug, int $post_id, string $post_type, int $post_parent, bool $hierarchical, string $lang ): bool {
		global $wpdb;
		$table = Translations::table();
		if ( $hierarchical ) {
			$sql = $wpdb->prepare(
				"SELECT p.ID FROM {$wpdb->posts} p
				LEFT JOIN {$table} gaiml_t ON ( gaiml_t.object_type = 'post' AND gaiml_t.object_id = p.ID )
				WHERE p.post_name = %s AND p.post_type IN ( %s, 'attachment' ) AND p.ID != %d AND p.post_parent = %d
				AND ( p.post_type = 'attachment' OR " . Query::lang_condition( 'gaiml_t', $lang ) . ' ) LIMIT 1', // phpcs:ignore WordPress.DB.PreparedSQL
				$slug,
				$post_type,
				$post_id,
				$post_parent
			);
		} else {
			$sql = $wpdb->prepare(
				"SELECT p.ID FROM {$wpdb->posts} p
				LEFT JOIN {$table} gaiml_t ON ( gaiml_t.object_type = 'post' AND gaiml_t.object_id = p.ID )
				WHERE p.post_name = %s AND p.post_type = %s AND p.ID != %d
				AND " . Query::lang_condition( 'gaiml_t', $lang ) . ' LIMIT 1', // phpcs:ignore WordPress.DB.PreparedSQL
				$slug,
				$post_type,
				$post_id
			);
		}
		return (bool) $wpdb->get_var( $sql ); // phpcs:ignore WordPress.DB
	}

	/**
	 * Slug for a translated term: the translated slug, or slug-{lang} when another
	 * language already uses it (term slugs stay unique per taxonomy).
	 */
	public static function term_slug( string $slug, string $taxonomy, string $lang, int $exclude = 0 ): string {
		$slug = sanitize_title( $slug );
		if ( '' === $slug ) {
			return '';
		}
		Query::suspend();
		try {
			$existing = get_term_by( 'slug', $slug, $taxonomy );
			if ( $existing instanceof \WP_Term && (int) $existing->term_id !== $exclude ) {
				$slug = sanitize_title( $slug . '-' . ( Languages::get( $lang )['slug'] ?? $lang ) );
			}
		} finally {
			Query::resume();
		}
		return $slug;
	}
}
