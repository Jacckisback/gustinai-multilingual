<?php
/**
 * Template tags.
 *
 * @package GustinAI\Multilingual
 */

defined( 'ABSPATH' ) || exit;

use GustinAI\Multilingual\Core\Links;
use GustinAI\Multilingual\Core\Router;
use GustinAI\Multilingual\Core\Translations;
use GustinAI\Multilingual\Frontend\Switcher;
use GustinAI\Multilingual\Languages;

if ( ! function_exists( 'gaiml_current_language' ) ) {
	/**
	 * Code of the language of the current request (default language in the admin).
	 */
	function gaiml_current_language(): string {
		return Router::current() ?? Languages::default_code();
	}
}

if ( ! function_exists( 'gaiml_default_language' ) ) {
	function gaiml_default_language(): string {
		return Languages::default_code();
	}
}

if ( ! function_exists( 'gaiml_languages' ) ) {
	/**
	 * Active languages: code => array( code, locale, name, slug, hreflang, rtl ).
	 *
	 * @return array<string, array<string, mixed>>
	 */
	function gaiml_languages(): array {
		return Languages::all();
	}
}

if ( ! function_exists( 'gaiml_get_translation' ) ) {
	/**
	 * ID of the translation of a post (or term) in a language, 0 when there is none.
	 */
	function gaiml_get_translation( int $id, ?string $lang = null, string $type = 'post' ): int {
		return Translations::translation( 'term' === $type ? 'term' : 'post', $id, $lang ?? gaiml_current_language() );
	}
}

if ( ! function_exists( 'gaiml_post_language' ) ) {
	function gaiml_post_language( int $post_id ): string {
		return Translations::lang_or_default( 'post', $post_id );
	}
}

if ( ! function_exists( 'gaiml_home_url' ) ) {
	/**
	 * Home page URL of a language.
	 */
	function gaiml_home_url( ?string $lang = null ): string {
		return Links::language_home( $lang ?? gaiml_current_language() );
	}
}

if ( ! function_exists( 'gaiml_language_switcher' ) ) {
	/**
	 * Prints the language switcher.
	 *
	 * @param array<string, mixed> $args style (dropdown|list), show (name|code|both), hide_current, hide_missing.
	 */
	function gaiml_language_switcher( array $args = array() ): void {
		echo Switcher::render( $args ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in Switcher::render().
	}
}
