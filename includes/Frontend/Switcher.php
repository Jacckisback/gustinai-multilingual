<?php
/**
 * Language switcher: shortcode [gaiml_switcher], block, menu item and
 * template tag gaiml_language_switcher().
 *
 * Plain links (crawlable, with hreflang and lang attributes), no JavaScript.
 * The dropdown is a native <details> element. Links go to the translation of
 * the current page, or to the language home when it is not translated.
 *
 * @package GustinAI\Multilingual
 */

namespace GustinAI\Multilingual\Frontend;

use GustinAI\Multilingual\Core\Router;
use GustinAI\Multilingual\Languages;
use GustinAI\Multilingual\Settings;

defined( 'ABSPATH' ) || exit;

final class Switcher {

	private static bool $styles_printed = false;

	public static function init(): void {
		add_shortcode( 'gaiml_switcher', array( self::class, 'shortcode' ) );
		add_action( 'init', array( self::class, 'register_block' ) );
		add_filter( 'wp_nav_menu_items', array( self::class, 'menu_items' ), 20, 2 );
	}

	/**
	 * @param array<string, mixed> $args style (dropdown|list), show (name|code|both),
	 *                                    hide_current, hide_missing, class.
	 */
	public static function render( array $args = array() ): string {
		if ( ! Languages::is_multilingual() ) {
			return '';
		}
		$args    = wp_parse_args(
			$args,
			array(
				'style'        => (string) Settings::get( 'switcher_style' ),
				'show'         => (string) Settings::get( 'switcher_show' ),
				'hide_current' => false,
				'hide_missing' => false,
				'class'        => '',
			)
		);
		$current = Router::current() ?? Languages::default_code();
		$items   = array();
		foreach ( Alternates::current() as $lang => $row ) {
			if ( ( $args['hide_current'] && $lang === $current ) || ( $args['hide_missing'] && ! $row['exists'] && $lang !== $current ) ) {
				continue;
			}
			$items[ $lang ] = $row['url'];
		}
		if ( ! $items ) {
			return '';
		}
		$style   = 'list' === $args['style'] ? 'list' : 'dropdown';
		$classes = trim( 'gaiml-switcher gaiml-switcher--' . $style . ' ' . sanitize_html_class( (string) $args['class'] ) );
		$label   = esc_attr__( 'Language', 'gustinai-multilingual' );
		$html    = self::styles() . '<nav class="' . esc_attr( $classes ) . '" aria-label="' . $label . '">';

		if ( 'dropdown' === $style ) {
			$html .= '<details><summary>' . self::label( $current, (string) $args['show'] ) . '</summary><ul>';
			foreach ( $items as $lang => $url ) {
				if ( $lang !== $current ) {
					$html .= '<li>' . self::link( $lang, $url, (string) $args['show'], false ) . '</li>';
				}
			}
			$html .= '</ul></details>';
		} else {
			$html .= '<ul>';
			foreach ( $items as $lang => $url ) {
				$html .= '<li' . ( $lang === $current ? ' class="is-current"' : '' ) . '>' . self::link( $lang, $url, (string) $args['show'], $lang === $current ) . '</li>';
			}
			$html .= '</ul>';
		}
		return $html . '</nav>';
	}

	private static function label( string $lang, string $show ): string {
		$name = Languages::name( $lang );
		$code = strtoupper( (string) ( Languages::get( $lang )['slug'] ?? $lang ) );
		$text = 'code' === $show ? $code : ( 'both' === $show ? $code . ' · ' . $name : $name );
		$html = '<span lang="' . esc_attr( Languages::hreflang( $lang ) ) . '">' . esc_html( $text ) . '</span>';
		if ( 'code' === $show ) {
			$html = '<abbr lang="' . esc_attr( Languages::hreflang( $lang ) ) . '" title="' . esc_attr( $name ) . '">' . esc_html( $code ) . '</abbr>';
		}
		return $html;
	}

	private static function link( string $lang, string $url, string $show, bool $current ): string {
		return sprintf(
			'<a href="%1$s" hreflang="%2$s" lang="%2$s"%3$s>%4$s</a>',
			esc_url( $url ),
			esc_attr( Languages::hreflang( $lang ) ),
			$current ? ' aria-current="true"' : '',
			self::label( $lang, $show )
		);
	}

	/**
	 * Tiny stylesheet, printed once with the first switcher (no extra request).
	 */
	private static function styles(): string {
		if ( self::$styles_printed ) {
			return '';
		}
		self::$styles_printed = true;
		$css = '.gaiml-switcher ul{list-style:none;margin:0;padding:0}.gaiml-switcher--list ul{display:flex;flex-wrap:wrap;gap:.5em}.gaiml-switcher--list .is-current a{font-weight:600;text-decoration:none}.gaiml-switcher--dropdown{position:relative;display:inline-block}.gaiml-switcher--dropdown summary{cursor:pointer;list-style:none}.gaiml-switcher--dropdown summary::-webkit-details-marker{display:none}.gaiml-switcher--dropdown summary::after{content:"";display:inline-block;margin-left:.4em;border:.3em solid transparent;border-top-color:currentColor;vertical-align:middle;transform:translateY(.15em)}.gaiml-switcher--dropdown ul{position:absolute;z-index:1000;right:0;min-width:100%;padding:.4em 0;background:#fff;color:#111;box-shadow:0 4px 18px rgba(0,0,0,.15);border-radius:6px}.gaiml-switcher--dropdown li a{display:block;padding:.35em 1em;white-space:nowrap;color:inherit}';
		/**
		 * Filters the CSS of the language switcher ('' to style it in your theme).
		 */
		$css = (string) apply_filters( 'gaiml_switcher_css', $css );
		return '' !== $css ? '<style id="gaiml-switcher-css">' . wp_strip_all_tags( $css ) . '</style>' : '';
	}

	/**
	 * @param array<string, string>|string $atts
	 */
	public static function shortcode( $atts ): string {
		$atts = shortcode_atts(
			array(
				'style'        => (string) Settings::get( 'switcher_style' ),
				'show'         => (string) Settings::get( 'switcher_show' ),
				'hide_current' => '0',
				'hide_missing' => '0',
				'class'        => '',
			),
			(array) $atts,
			'gaiml_switcher'
		);
		$atts['hide_current'] = in_array( $atts['hide_current'], array( '1', 'true', 'yes' ), true );
		$atts['hide_missing'] = in_array( $atts['hide_missing'], array( '1', 'true', 'yes' ), true );
		return self::render( $atts );
	}

	public static function register_block(): void {
		wp_register_script(
			'gaiml-switcher-block',
			GAIML_URL . 'assets/js/switcher-block.js',
			array( 'wp-blocks', 'wp-element', 'wp-components', 'wp-block-editor', 'wp-server-side-render', 'wp-i18n' ),
			GAIML_VERSION,
			true
		);
		wp_set_script_translations( 'gaiml-switcher-block', 'gustinai-multilingual', GAIML_DIR . 'languages' );
		register_block_type(
			'gaiml/language-switcher',
			array(
				'api_version'     => 3,
				'title'           => __( 'Language switcher', 'gustinai-multilingual' ),
				'category'        => 'widgets',
				'icon'            => 'translation',
				'editor_script'   => 'gaiml-switcher-block',
				'render_callback' => static fn( $attributes ) => self::render( (array) $attributes ),
				'attributes'      => array(
					'style'        => array(
						'type'    => 'string',
						'default' => 'dropdown',
					),
					'show'         => array(
						'type'    => 'string',
						'default' => 'name',
					),
					'hide_current' => array(
						'type'    => 'boolean',
						'default' => false,
					),
					'hide_missing' => array(
						'type'    => 'boolean',
						'default' => false,
					),
				),
				'supports'        => array(
					'html'       => false,
					'align'      => array( 'left', 'center', 'right' ),
					'typography' => array( 'fontSize' => true ),
					'color'      => array(
						'text'       => true,
						'background' => false,
						'link'       => true,
					),
				),
			)
		);
	}

	/**
	 * Appends the switcher to the menu location chosen in the settings.
	 *
	 * @param string    $items
	 * @param \stdClass $args
	 */
	public static function menu_items( $items, $args ): string {
		$location = (string) Settings::get( 'switcher_menu' );
		if ( '' === $location || ! is_object( $args ) || ( $args->theme_location ?? '' ) !== $location || ! Languages::is_multilingual() ) {
			return (string) $items;
		}
		$current = Router::current() ?? Languages::default_code();
		$show    = (string) Settings::get( 'switcher_show' );
		$rows    = Alternates::current();
		$sub     = '';
		foreach ( $rows as $lang => $row ) {
			if ( $lang !== $current ) {
				$sub .= '<li class="menu-item gaiml-menu-language">' . self::link( $lang, $row['url'], $show, false ) . '</li>';
			}
		}
		return $items . '<li class="menu-item menu-item-has-children gaiml-menu-switcher">'
			. self::link( $current, $rows[ $current ]['url'] ?? '', $show, true )
			. '<ul class="sub-menu">' . $sub . '</ul></li>';
	}
}
