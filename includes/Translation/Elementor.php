<?php
/**
 * Translatable settings of Elementor data (_elementor_data).
 *
 * Text controls of core, Pro and most add-on widgets are detected by their keys
 * (title, editor, button_text, *_text, *_title, *_description…); repeaters
 * (tabs, slides, icon lists, form fields…) are walked recursively. Design
 * settings, dynamic tags, HTML/code widgets and form option values are kept.
 *
 * @package GustinAI\Multilingual
 */

namespace GustinAI\Multilingual\Translation;

defined( 'ABSPATH' ) || exit;

final class Elementor {

	private const KEYS = array( 'title', 'editor', 'text', 'description', 'caption', 'button_text', 'heading', 'sub_heading', 'subtitle', 'sub_title', 'link_text', 'prefix', 'suffix', 'before_text', 'highlighted_text', 'rotating_text', 'after_text', 'testimonial_content', 'testimonial_name', 'testimonial_job', 'tab_title', 'tab_content', 'alert_title', 'alert_description', 'inner_text', 'ribbon_title', 'item_description', 'read_more_text', 'field_label', 'placeholder', 'acceptance_text', 'success_message', 'error_message', 'required_field_message', 'invalid_message', 'server_message', 'step_next_label', 'step_previous_label', 'label', 'blockquote_content', 'author_name', 'tweet_button_label', 'title_text', 'description_text', 'text_before', 'text_after', 'period', 'question', 'answer', 'content', 'heading_text', 'name', 'job', 'cta_text', 'excerpt', 'item_title', 'list_title', 'list_content' );

	private const SUFFIX = '~_(text|title|label|description|content|heading|caption|placeholder|message)$~';

	private const SKIP = array( 'html', 'shortcode', 'anchor', 'custom_css', 'css_classes', 'form_id', 'form_name', 'field_options', 'field_value', 'field_custom_id', 'email_to', 'email_subject', 'email_from', 'email_from_name', 'email_to_cc', 'email_to_bcc', 'redirect_to', 'menu', 'url', 'icon', 'selected_icon', 'view', 'title_tag', 'header_size', 'html_tag', 'link_to', 'skin', 'layout', 'source' );

	private const SKIP_SUFFIX = '~_(color|size|typography|font_family|font_weight|icon|url|link|id|class|classes|css|animation|position|align|alignment|width|height|spacing|padding|margin|border|radius|shadow|tag|type|style|layout|view|skin|effect|source|image|media|html|code|selector|tablet|mobile|hover|duration|delay|columns|ratio)$~';

	/**
	 * @param array<int, mixed>    $elements Decoded _elementor_data.
	 * @param array<string, mixed> $opts     link: URL localizer, attachment: callable(int $id) collecting images.
	 * @return \Closure(array<string, string>): array<int, mixed>
	 */
	public static function extract( array $elements, Bag $bag, array $opts = array() ): \Closure {
		unset( $opts['kind'] );
		$tree = self::elements( $elements, $bag, $opts );
		return static fn( array $tr ) => Attributes::render( $tree, $tr );
	}

	/**
	 * @param array<int|string, mixed> $elements
	 * @param array<string, mixed>     $opts
	 * @return array<int|string, mixed>
	 */
	private static function elements( array $elements, Bag $bag, array $opts ): array {
		foreach ( $elements as $i => $element ) {
			if ( ! is_array( $element ) ) {
				continue;
			}
			if ( isset( $element['settings'] ) && is_array( $element['settings'] ) ) {
				$element['settings'] = self::settings( $element['settings'], $bag, $opts );
			}
			if ( isset( $element['elements'] ) && is_array( $element['elements'] ) ) {
				$element['elements'] = self::elements( $element['elements'], $bag, $opts );
			}
			$elements[ $i ] = $element;
		}
		return $elements;
	}

	/**
	 * @param array<int|string, mixed> $settings
	 * @param array<string, mixed>     $opts
	 * @return array<int|string, mixed>
	 */
	public static function settings( array $settings, Bag $bag, array $opts ): array {
		foreach ( $settings as $key => $value ) {
			$key = (string) $key;
			if ( '' === $key || '_' === $key[0] ) {
				continue;
			}
			if ( is_array( $value ) ) {
				$settings[ $key ] = self::array_value( $key, $value, $bag, $opts );
				continue;
			}
			if ( ! is_string( $value ) || in_array( $key, self::SKIP, true ) || preg_match( self::SKIP_SUFFIX, $key ) ) {
				continue;
			}
			$explicit = in_array( $key, self::KEYS, true );
			if ( ( $explicit || preg_match( self::SUFFIX, $key ) ) && Placeholders::looks_like_text( $value, ! $explicit ) ) {
				$settings[ $key ] = Html::value( $value, $bag, $opts, strlen( $value ) > 120 ? 'text' : 'label' );
			}
		}
		return $settings;
	}

	/**
	 * Link controls, media controls and repeaters.
	 *
	 * @param array<int|string, mixed> $value
	 * @param array<string, mixed>     $opts
	 * @return array<int|string, mixed>
	 */
	private static function array_value( string $key, array $value, Bag $bag, array $opts ): array {
		if ( isset( $value['url'] ) && is_string( $value['url'] ) ) {
			if ( isset( $value['id'] ) && is_numeric( $value['id'] ) && (int) $value['id'] > 0 ) {
				if ( isset( $opts['attachment'] ) && is_callable( $opts['attachment'] ) ) {
					call_user_func( $opts['attachment'], (int) $value['id'] );
				}
				return $value;
			}
			if ( '' !== $value['url'] && isset( $opts['link'] ) && is_callable( $opts['link'] ) ) {
				$value['url'] = (string) call_user_func( $opts['link'], $value['url'] );
			}
			return $value;
		}
		if ( array_is_list( $value ) ) {
			foreach ( $value as $i => $item ) {
				if ( ! is_array( $item ) || array_is_list( $item ) ) {
					continue;
				}
				if ( isset( $item['id'], $item['url'] ) && ! isset( $item['_id'] ) ) {
					// Gallery image.
					if ( isset( $opts['attachment'] ) && is_callable( $opts['attachment'] ) && is_numeric( $item['id'] ) ) {
						call_user_func( $opts['attachment'], (int) $item['id'] );
					}
					continue;
				}
				$value[ $i ] = self::settings( $item, $bag, $opts );
			}
		}
		return $value;
	}
}
