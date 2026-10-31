<?php
/**
 * Site strings: title, tagline, custom menu labels and navigation labels.
 *
 * Translated with AI from Multilingual > Strings (or by hand) and stored in
 * the translation memory; shown in the visitor's language.
 *
 * @package GustinAI\Multilingual
 */

namespace GustinAI\Multilingual\Frontend;

use GustinAI\Multilingual\Core\Router;
use GustinAI\Multilingual\Languages;
use GustinAI\Multilingual\Translation\Memory;
use GustinAI\Multilingual\Translation\Placeholders;

defined( 'ABSPATH' ) || exit;

final class Strings {

	private static bool $raw = false;

	/** @var array<string, string>|null Translations of the title and tagline. */
	private static ?array $options = null;

	public static function init(): void {
		add_filter( 'option_blogname', array( self::class, 'translate_option' ) );
		add_filter( 'option_blogdescription', array( self::class, 'translate_option' ) );
	}

	/**
	 * @param mixed $value
	 * @return mixed
	 */
	public static function translate_option( $value ) {
		$lang = Router::current();
		if ( self::$raw || ! is_string( $value ) || '' === $value || null === $lang || Languages::is_default( $lang ) || Router::is_neutral() ) {
			return $value;
		}
		if ( null === self::$options ) {
			// Title and tagline are looked up together, once per request.
			self::$options = Memory::lookup( array( self::raw_option( 'blogname' ), self::raw_option( 'blogdescription' ) ), Languages::default_code(), $lang );
		}
		$found = isset( self::$options[ $value ] ) ? self::$options : Memory::lookup( array( $value ), Languages::default_code(), $lang );
		// Stored like core stores these options: escaped (sanitize_option() runs esc_html()).
		return isset( $found[ $value ] ) ? esc_html( Placeholders::plain( $found[ $value ] ) ) : $value;
	}

	/**
	 * Option value in the default language.
	 */
	public static function raw_option( string $name ): string {
		self::$raw = true;
		try {
			return (string) get_option( $name, '' );
		} finally {
			self::$raw = false;
		}
	}

	/**
	 * Every site string to translate, grouped: group label => list of texts.
	 *
	 * @return array<string, string[]>
	 */
	public static function groups(): array {
		global $wpdb;
		$groups = array(
			__( 'Site identity', 'gustinai-multilingual' ) => array_filter( array( self::raw_option( 'blogname' ), self::raw_option( 'blogdescription' ) ) ),
		);
		$menu = array();
		$rows = $wpdb->get_results( "SELECT post_title, post_excerpt FROM {$wpdb->posts} WHERE post_type = 'nav_menu_item' AND post_status = 'publish'" ); // phpcs:ignore WordPress.DB
		foreach ( (array) $rows as $row ) {
			foreach ( array( $row->post_title, $row->post_excerpt ) as $text ) {
				if ( '' !== trim( (string) $text ) ) {
					$menu[] = (string) $text;
				}
			}
		}
		$navigation = get_posts(
			array(
				'post_type'        => 'wp_navigation',
				'post_status'      => 'publish',
				'numberposts'      => 50,
				'suppress_filters' => true,
			)
		);
		foreach ( $navigation as $post ) {
			self::collect_labels( parse_blocks( $post->post_content ), $menu );
		}
		$groups[ __( 'Menus', 'gustinai-multilingual' ) ] = $menu;
		/**
		 * Filters the site strings offered for translation (group => texts).
		 * Add theme or plugin texts stored in options here.
		 */
		$groups = (array) apply_filters( 'gaiml_strings', $groups );
		foreach ( $groups as $label => $texts ) {
			$groups[ $label ] = array_values( array_unique( array_filter( array_map( 'trim', (array) $texts ), static fn( $t ) => Placeholders::has_words( $t ) ) ) );
		}
		return array_filter( $groups );
	}

	/**
	 * @param array<int, array<string, mixed>> $blocks
	 * @param string[]                         $out
	 */
	private static function collect_labels( array $blocks, array &$out ): void {
		foreach ( $blocks as $block ) {
			if ( in_array( $block['blockName'] ?? '', array( 'core/navigation-link', 'core/navigation-submenu' ), true ) && ! empty( $block['attrs']['label'] ) ) {
				$out[] = wp_strip_all_tags( (string) $block['attrs']['label'] );
			}
			if ( ! empty( $block['innerBlocks'] ) ) {
				self::collect_labels( $block['innerBlocks'], $out );
			}
		}
	}

	/**
	 * @return string[]
	 */
	public static function registered(): array {
		$all = array();
		foreach ( self::groups() as $texts ) {
			$all = array_merge( $all, $texts );
		}
		return array_values( array_unique( $all ) );
	}
}
