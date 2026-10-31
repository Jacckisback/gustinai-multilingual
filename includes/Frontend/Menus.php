<?php
/**
 * Menus follow the visitor's language without duplicating them.
 *
 * Classic menus and navigation blocks built in the default language are mapped
 * at render time: each item points to the translation of its page, category…
 * with its translated title; custom labels come from the string translations;
 * items without translation are hidden (option) instead of mixing languages.
 *
 * @package GustinAI\Multilingual
 */

namespace GustinAI\Multilingual\Frontend;

use GustinAI\Multilingual\Core\Content;
use GustinAI\Multilingual\Core\Links;
use GustinAI\Multilingual\Core\Router;
use GustinAI\Multilingual\Core\Translations;
use GustinAI\Multilingual\Languages;
use GustinAI\Multilingual\Settings;
use GustinAI\Multilingual\Translation\Memory;
use GustinAI\Multilingual\Translation\Placeholders;

defined( 'ABSPATH' ) || exit;

final class Menus {

	public static function init(): void {
		add_filter( 'pre_wp_nav_menu', array( self::class, 'prime_menu' ), 5, 2 );
		add_filter( 'wp_get_nav_menu_items', array( self::class, 'items' ), 20 );
		add_filter( 'block_core_navigation_render_inner_blocks', array( self::class, 'navigation_blocks' ) );
		add_filter( 'render_block_data', array( self::class, 'block_data' ) );
		add_filter( 'render_block', array( self::class, 'hide_block' ), 10, 3 );
	}

	private static function lang(): ?string {
		$lang = Router::current();
		if ( null === $lang || Router::is_neutral() || is_admin() || ! in_array( Router::context(), array( 'front', 'ajax' ), true ) ) {
			return null;
		}
		return $lang;
	}

	/**
	 * Loads the languages of every object of a menu in two queries, before
	 * WordPress builds the item URLs.
	 *
	 * @param string|null $output
	 * @param \stdClass   $args
	 * @return string|null
	 */
	public static function prime_menu( $output, $args ) {
		global $wpdb;
		if ( null !== $output || null === self::lang() || ! is_object( $args ) ) {
			return $output;
		}
		$menu = wp_get_nav_menu_object( $args->menu ?? '' );
		if ( ! $menu && ! empty( $args->theme_location ) ) {
			$locations = get_nav_menu_locations();
			$menu      = isset( $locations[ $args->theme_location ] ) ? wp_get_nav_menu_object( $locations[ $args->theme_location ] ) : false;
		}
		if ( ! $menu instanceof \WP_Term ) {
			return $output;
		}
		$ids = array_map(
			'intval',
			$wpdb->get_col( // phpcs:ignore WordPress.DB
				$wpdb->prepare(
					"SELECT pm.meta_value FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->term_relationships} tr ON ( tr.object_id = pm.post_id ) WHERE tr.term_taxonomy_id = %d AND pm.meta_key = '_menu_item_object_id'",
					$menu->term_taxonomy_id
				)
			)
		);
		self::prime( $ids, $ids );
		return $output;
	}

	/**
	 * Languages and translated posts of menu targets (IDs may be posts or terms).
	 *
	 * @param int[] $posts
	 * @param int[] $terms
	 */
	private static function prime( array $posts, array $terms ): void {
		$lang = (string) self::lang();
		Translations::prime( 'post', $posts );
		Translations::prime( 'term', $terms );
		$targets = array();
		foreach ( $posts as $id ) {
			$translation = Translations::translation( 'post', (int) $id, $lang );
			if ( $translation ) {
				$targets[] = $translation;
			}
		}
		if ( $targets ) {
			_prime_post_caches( $targets, false, false );
		}
	}

	/**
	 * @param \WP_Post[] $items
	 * @return \WP_Post[]
	 */
	public static function items( $items ) {
		$lang = self::lang();
		if ( null === $lang || ! is_array( $items ) || ! $items ) {
			return $items;
		}
		$posts  = array();
		$terms  = array();
		$labels = array();
		foreach ( $items as $item ) {
			if ( 'post_type' === ( $item->type ?? '' ) ) {
				$posts[] = (int) $item->object_id;
			} elseif ( 'taxonomy' === ( $item->type ?? '' ) ) {
				$terms[] = (int) $item->object_id;
			}
			foreach ( array( 'post_title', 'attr_title' ) as $field ) {
				if ( isset( $item->$field ) && '' !== trim( (string) $item->$field ) ) {
					$labels[] = (string) $item->$field;
				}
			}
		}
		self::prime( $posts, $terms );
		$found  = Languages::is_default( $lang ) ? array() : Memory::lookup( $labels, Languages::default_code(), $lang );
		$hide   = (bool) Settings::get( 'hide_untranslated' );
		$hidden = array();
		$out    = array();
		foreach ( $items as $item ) {
			$item = clone $item;
			$keep = true;
			switch ( $item->type ?? '' ) {
				case 'post_type':
					if ( Content::is_translatable_post_type( (string) $item->object ) ) {
						$keep = self::map_post( $item, $lang );
					}
					break;
				case 'taxonomy':
					if ( Content::is_translatable_taxonomy( (string) $item->object ) ) {
						$keep = self::map_term( $item, $lang );
					}
					break;
				case 'post_type_archive':
					$item->url = Links::add_lang( (string) $item->url, $lang );
					break;
				default:
					$item->url = Links::localize_url( (string) $item->url, $lang );
			}
			if ( ! $keep && $hide ) {
				$hidden[] = (int) $item->ID;
				continue;
			}
			if ( '' !== trim( (string) ( $item->post_title ?? '' ) ) && isset( $found[ $item->post_title ] ) ) {
				$item->title = Placeholders::plain( $found[ $item->post_title ] );
			}
			if ( '' !== trim( (string) ( $item->attr_title ?? '' ) ) && isset( $found[ $item->attr_title ] ) ) {
				$item->attr_title = Placeholders::plain( $found[ $item->attr_title ] );
			}
			$out[] = $item;
		}
		// Children of hidden items are hidden too.
		if ( $hidden ) {
			do {
				$changed = false;
				foreach ( $out as $k => $item ) {
					if ( in_array( (int) $item->menu_item_parent, $hidden, true ) ) {
						$hidden[] = (int) $item->ID;
						unset( $out[ $k ] );
						$changed = true;
					}
				}
			} while ( $changed );
			$out = array_values( $out );
		}
		return $out;
	}

	private static function map_post( object $item, string $lang ): bool {
		$id          = (int) $item->object_id;
		$translation = Translations::translation( 'post', $id, $lang );
		if ( ! $translation || ( $translation !== $id && 'publish' !== get_post_status( $translation ) ) ) {
			return Translations::lang_or_default( 'post', $id ) === $lang;
		}
		if ( $translation !== $id ) {
			$item->object_id = (string) $translation;
			$item->url       = (string) get_permalink( $translation );
			if ( '' === trim( (string) $item->post_title ) ) {
				$item->title = get_the_title( $translation );
			}
		}
		return true;
	}

	private static function map_term( object $item, string $lang ): bool {
		$id          = (int) $item->object_id;
		$translation = Translations::translation( 'term', $id, $lang );
		if ( ! $translation ) {
			return Translations::lang_or_default( 'term', $id ) === $lang;
		}
		if ( $translation !== $id ) {
			$term = get_term( $translation );
			$link = get_term_link( $translation );
			if ( ! $term instanceof \WP_Term || is_wp_error( $link ) ) {
				return false;
			}
			$item->object_id = (string) $translation;
			$item->url       = (string) $link;
			if ( '' === trim( (string) $item->post_title ) ) {
				$item->title = $term->name;
			}
		}
		return true;
	}

	/**
	 * Navigation block (block themes): links mapped like classic menu items.
	 *
	 * @param \WP_Block_List $inner_blocks
	 * @return \WP_Block_List
	 */
	public static function navigation_blocks( $inner_blocks ) {
		$lang = self::lang();
		if ( null !== $lang && $inner_blocks instanceof \WP_Block_List ) {
			$posts  = array();
			$terms  = array();
			$labels = array();
			self::collect( $inner_blocks, $posts, $terms, $labels );
			self::prime( $posts, $terms );
			if ( ! Languages::is_default( $lang ) && $labels ) {
				Memory::lookup( $labels, Languages::default_code(), $lang );
			}
			self::map_block_list( $inner_blocks, $lang );
		}
		return $inner_blocks;
	}

	/**
	 * @param int[]    $posts
	 * @param int[]    $terms
	 * @param string[] $labels
	 */
	private static function collect( \WP_Block_List $list, array &$posts, array &$terms, array &$labels ): void {
		foreach ( $list as $block ) {
			if ( ! $block instanceof \WP_Block ) {
				continue;
			}
			$id = (int) ( $block->attributes['id'] ?? 0 );
			if ( $id ) {
				if ( 'taxonomy' === ( $block->attributes['kind'] ?? '' ) ) {
					$terms[] = $id;
				} else {
					$posts[] = $id;
				}
			}
			if ( ! empty( $block->attributes['label'] ) ) {
				$labels[] = (string) $block->attributes['label'];
			}
			if ( $block->inner_blocks instanceof \WP_Block_List && count( $block->inner_blocks ) ) {
				self::collect( $block->inner_blocks, $posts, $terms, $labels );
			}
		}
	}

	private static function map_block_list( \WP_Block_List $list, string $lang ): void {
		foreach ( $list as $block ) {
			if ( ! $block instanceof \WP_Block ) {
				continue;
			}
			if ( in_array( $block->name, array( 'core/navigation-link', 'core/navigation-submenu' ), true ) ) {
				$block->attributes = self::link_attributes( (array) $block->attributes, $lang );
			}
			if ( $block->inner_blocks instanceof \WP_Block_List && count( $block->inner_blocks ) ) {
				self::map_block_list( $block->inner_blocks, $lang );
			}
		}
	}

	/**
	 * @param array<string, mixed> $attrs
	 * @return array<string, mixed>
	 */
	private static function link_attributes( array $attrs, string $lang ): array {
		if ( ! empty( $attrs['__gaiml_done'] ) ) {
			return $attrs;
		}
		$attrs['__gaiml_done'] = true;
		$id                    = (int) ( $attrs['id'] ?? 0 );
		$kind                  = (string) ( $attrs['kind'] ?? '' );
		$label                 = (string) ( $attrs['label'] ?? '' );
		$keep                  = true;
		if ( $id && 'post-type' === $kind && Content::is_translatable_post_type( (string) ( $attrs['type'] ?? 'page' ) ) ) {
			$translation = Translations::translation( 'post', $id, $lang );
			if ( $translation && ( $translation === $id || 'publish' === get_post_status( $translation ) ) ) {
				if ( $translation !== $id ) {
					$attrs['url'] = (string) get_permalink( $translation );
					if ( wp_strip_all_tags( $label ) === wp_strip_all_tags( get_the_title( $id ) ) ) {
						$attrs['label'] = get_the_title( $translation );
						$label          = '';
					}
					$attrs['id'] = $translation;
				}
			} else {
				$keep = Translations::lang_or_default( 'post', $id ) === $lang;
			}
		} elseif ( $id && 'taxonomy' === $kind ) {
			$translation = Translations::translation( 'term', $id, $lang );
			$term        = $translation ? get_term( $translation ) : null;
			if ( $term instanceof \WP_Term && $translation !== $id ) {
				$source = get_term( $id );
				$link   = get_term_link( $term );
				if ( ! is_wp_error( $link ) ) {
					$attrs['url'] = (string) $link;
				}
				if ( $source instanceof \WP_Term && $label === $source->name ) {
					$attrs['label'] = $term->name;
					$label          = '';
				}
				$attrs['id'] = $translation;
			} elseif ( ! $translation ) {
				$keep = Translations::lang_or_default( 'term', $id ) === $lang;
			}
		} elseif ( ! empty( $attrs['url'] ) ) {
			$attrs['url'] = Links::localize_url( (string) $attrs['url'], $lang );
		}
		if ( '' !== $label && ! Languages::is_default( $lang ) ) {
			$found = Memory::lookup( array( $label ), Languages::default_code(), $lang );
			if ( isset( $found[ $label ] ) ) {
				$attrs['label'] = Placeholders::plain( $found[ $label ] );
			}
		}
		if ( ! $keep && Settings::get( 'hide_untranslated' ) ) {
			$attrs['__gaiml_hide'] = true;
		}
		return $attrs;
	}

	/**
	 * Navigation links rendered outside a navigation block, synced patterns and
	 * navigation menus replaced by their translation.
	 *
	 * @param array<string, mixed> $block
	 * @return array<string, mixed>
	 */
	public static function block_data( $block ) {
		$lang = self::lang();
		if ( null === $lang || ! is_array( $block ) || empty( $block['blockName'] ) ) {
			return $block;
		}
		$name = (string) $block['blockName'];
		if ( in_array( $name, array( 'core/navigation-link', 'core/navigation-submenu' ), true ) ) {
			$block['attrs'] = self::link_attributes( (array) ( $block['attrs'] ?? array() ), $lang );
		} elseif ( in_array( $name, array( 'core/block', 'core/navigation' ), true ) && ! empty( $block['attrs']['ref'] ) ) {
			$ref         = (int) $block['attrs']['ref'];
			$type        = (string) get_post_type( $ref );
			$translation = Content::is_translatable_post_type( $type ) ? Translations::translation( 'post', $ref, $lang ) : 0;
			if ( $translation && 'publish' === get_post_status( $translation ) ) {
				$block['attrs']['ref'] = $translation;
			}
		}
		return $block;
	}

	/**
	 * @param string        $content
	 * @param array         $block
	 * @param \WP_Block|null $instance
	 */
	public static function hide_block( $content, $block, $instance = null ) {
		if ( ( $instance instanceof \WP_Block && ! empty( $instance->attributes['__gaiml_hide'] ) ) || ! empty( $block['attrs']['__gaiml_hide'] ) ) {
			return '';
		}
		return $content;
	}
}
