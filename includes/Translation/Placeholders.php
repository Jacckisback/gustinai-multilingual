<?php
/**
 * Placeholders, validation and sanitization of translated texts.
 *
 * Inline markup is replaced by numbered placeholders before translation:
 * <g1>…</g1> for a pair of tags (link, bold…), <x2/> for a fixed element
 * (line break, image, shortcode). The model never sees the real markup, so it
 * cannot break or inject it: anything else that looks like markup in its answer
 * is escaped.
 *
 * @package GustinAI\Multilingual
 */

namespace GustinAI\Multilingual\Translation;

defined( 'ABSPATH' ) || exit;

final class Placeholders {

	public const PATTERN = '~<\s*(/?)\s*([gx])\s*(\d+)\s*(/?)\s*>~i';

	private const VARIABLES = '~%%[a-z0-9_-]+%%|%[a-z][a-z0-9_-]*%|\{\{?\s*[a-z0-9_.-]+\s*\}?\}|%(?:\d+\$)?[sdf]~i';

	/**
	 * Canonical placeholder tokens of a text, in order.
	 *
	 * @return string[]
	 */
	public static function tokens( string $text ): array {
		if ( false === strpos( $text, '<' ) ) {
			return array();
		}
		preg_match_all( self::PATTERN, $text, $matches, PREG_SET_ORDER );
		$out = array();
		foreach ( $matches as $m ) {
			$out[] = self::canonical( $m );
		}
		return $out;
	}

	/**
	 * @param string[] $m Regex match of PATTERN.
	 */
	private static function canonical( array $m ): string {
		$type = strtolower( $m[2] );
		if ( 'x' === $type ) {
			return '<x' . $m[3] . '/>';
		}
		return '<' . ( '/' === $m[1] ? '/' : '' ) . 'g' . $m[3] . '>';
	}

	/**
	 * Whether a translation keeps the placeholders (same set, properly nested)
	 * and the variables of its source.
	 */
	public static function valid( string $source, string $translation ): bool {
		if ( '' === trim( $translation ) && '' !== trim( $source ) ) {
			return false;
		}
		$expected = self::tokens( $source );
		$got      = self::tokens( $translation );
		$a        = $expected;
		$b        = $got;
		sort( $a );
		sort( $b );
		if ( $a !== $b ) {
			return false;
		}
		$stack = array();
		foreach ( $got as $token ) {
			if ( 0 === strpos( $token, '<g' ) ) {
				$stack[] = substr( $token, 2, -1 );
			} elseif ( 0 === strpos( $token, '</g' ) ) {
				if ( array_pop( $stack ) !== substr( $token, 3, -1 ) ) {
					return false;
				}
			}
		}
		if ( $stack ) {
			return false;
		}
		preg_match_all( self::VARIABLES, $source, $vars );
		foreach ( array_unique( $vars[0] ) as $variable ) {
			if ( false === stripos( $translation, $variable ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Rebuilds HTML from a translated masked text. Returns null when the
	 * translation does not keep the placeholders.
	 *
	 * @param array<string, string|\Closure> $map Canonical placeholder => markup (or renderer).
	 * @param array<string, string>          $tr  Translations, passed to renderers.
	 */
	public static function restore( string $translation, string $masked, array $map, array $tr ): ?string {
		$translation = trim( $translation );
		if ( ! self::valid( $masked, $translation ) ) {
			return null;
		}
		$out    = '';
		$offset = 0;
		if ( preg_match_all( self::PATTERN, $translation, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE ) ) {
			foreach ( $matches as $m ) {
				$out    .= self::html_text( substr( $translation, $offset, $m[0][1] - $offset ) );
				$token   = self::canonical( array( $m[0][0], $m[1][0], $m[2][0], $m[3][0], $m[4][0] ?? '' ) );
				$markup  = $map[ $token ] ?? '';
				$out    .= $markup instanceof \Closure ? $markup( $tr ) : $markup;
				$offset  = $m[0][1] + strlen( $m[0][0] );
			}
		}
		return $out . self::html_text( substr( $translation, $offset ) );
	}

	/**
	 * Text for an HTML context: no markup, valid entities kept, stray & escaped.
	 */
	public static function html_text( string $text ): string {
		$text = str_replace( array( '<', '>' ), array( '&lt;', '&gt;' ), $text );
		return (string) preg_replace( '/&(?!(?:[a-zA-Z][a-zA-Z0-9]{1,31}|#[0-9]{1,7}|#[xX][0-9a-fA-F]{1,6});)/', '&amp;', $text );
	}

	/**
	 * Plain text (builder settings, SEO fields, names): no markup at all.
	 */
	public static function plain( string $text ): string {
		$text = (string) preg_replace( '~<[a-zA-Z/!?][^>]*>~', '', $text );
		$text = (string) preg_replace( '~<(?=[a-zA-Z/!?])~', '', $text );
		return trim( $text );
	}

	/**
	 * Value for an HTML attribute delimited by $quote.
	 */
	public static function attr( string $text, string $quote = '"' ): string {
		$text = self::html_text( self::plain( $text ) );
		return '"' === $quote ? str_replace( '"', '&quot;', $text ) : str_replace( "'", '&#039;', $text );
	}

	/**
	 * Decodes HTML entities for the model (cleaner input), unless the result would
	 * look like a placeholder.
	 */
	public static function decode( string $text ): string {
		if ( false === strpos( $text, '&' ) ) {
			return $text;
		}
		$decoded = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		return preg_match( self::PATTERN, $decoded ) ? $text : $decoded;
	}

	public static function has_words( string $text ): bool {
		$text = (string) preg_replace( self::PATTERN, ' ', $text );
		$text = (string) preg_replace( self::VARIABLES, ' ', $text );
		return (bool) preg_match( '/\p{L}/u', $text );
	}

	/**
	 * Whether a builder setting value is human text (not a URL, color, CSS value,
	 * identifier…). $strict also rejects single lowercase words.
	 */
	public static function looks_like_text( string $value, bool $strict = false ): bool {
		$value = trim( $value );
		if ( '' === $value || ! self::has_words( $value ) ) {
			return false;
		}
		if ( preg_match( '~<[a-z!/]~i', $value ) ) {
			return true;
		}
		if ( in_array( strtolower( $value ), array( 'on', 'off', 'yes', 'no', 'true', 'false', 'none', 'auto', 'inherit', 'initial', 'default', 'left', 'right', 'center', 'top', 'bottom', 'custom', 'normal', 'bold', 'italic', 'solid' ), true ) ) {
			return false;
		}
		if ( preg_match( '~^(https?:|mailto:|tel:|www\.|/|\.|@ET-DC@|var\(|var:|rgba?\(|hsla?\(|calc\(|url\(|linear-gradient|radial-gradient)~i', $value )
			|| preg_match( '~^#[\w-]*$~', $value )
			|| preg_match( '~^-?\d+(\.\d+)?\s*(px|em|rem|%|vh|vw|s|ms|deg|fr)?$~i', $value )
			|| preg_match( '~^[\w.+-]+@[\w-]+\.[\w.]+$~', $value )
			|| preg_match( '~^[a-z0-9]+([_-][a-z0-9]+)+$~', $value )
			|| preg_match( '~^[a-z]+[A-Z][A-Za-z0-9]*$~', $value )
			|| preg_match( '~^[a-z]\d$~i', $value )
			|| ( false !== strpos( $value, '|' ) && false !== strpos( $value, 'var' ) ) ) {
			return false;
		}
		if ( $strict && ! preg_match( '~\s|[A-Z]|[^\x00-\x7F]~', $value ) ) {
			return false;
		}
		return true;
	}

	/**
	 * Slug from a model answer.
	 */
	public static function slug( string $text ): string {
		$text = strtolower( trim( self::plain( $text ) ) );
		$text = (string) preg_replace( '~^/+|/+$~', '', $text );
		return function_exists( 'sanitize_title' ) ? sanitize_title( $text ) : (string) preg_replace( '~[^a-z0-9-]+~', '-', $text );
	}
}
