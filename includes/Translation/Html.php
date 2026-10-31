<?php
/**
 * Extraction of translatable texts from HTML, Gutenberg blocks and shortcodes.
 *
 * The markup is never sent to the model and never re-serialized: only text
 * spans and a few attributes (alt, title, placeholder, aria-label) are replaced
 * in place, so Gutenberg block validation, Divi shortcodes and custom HTML stay
 * byte-identical outside the translated texts.
 *
 * - Block-level tags, comments and builder shortcodes split the content into
 *   segments; inline tags (links, bold…) become placeholders inside a segment,
 *   so a sentence is translated as a whole.
 * - <script>, <style>, <code>, <pre>, <svg>… and elements marked translate="no"
 *   or class="notranslate" are kept verbatim.
 * - Gutenberg block attributes (Divi 5 included) are handled by Attributes.
 * - Divi 4 / WPBakery shortcode attributes (title, button_text…) are translated.
 *
 * @package GustinAI\Multilingual
 */

namespace GustinAI\Multilingual\Translation;

defined( 'ABSPATH' ) || exit;

final class Html {

	private const INLINE = array( 'a', 'abbr', 'acronym', 'b', 'bdi', 'bdo', 'big', 'br', 'button', 'cite', 'data', 'del', 'dfn', 'em', 'font', 'i', 'img', 'input', 'ins', 'label', 'mark', 'q', 'rp', 'rt', 'ruby', 's', 'small', 'span', 'strike', 'strong', 'sub', 'sup', 'time', 'tt', 'u', 'wbr' );

	private const VOID = array( 'area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input', 'link', 'meta', 'param', 'source', 'track', 'wbr' );

	private const OPAQUE = array( 'script', 'style', 'code', 'pre', 'kbd', 'samp', 'var', 'svg', 'math', 'textarea', 'iframe', 'object', 'video', 'audio', 'canvas', 'noscript', 'template' );

	private const OPAQUE_INLINE = array( 'code', 'kbd', 'samp', 'var', 'svg', 'math' );

	private const RAW_TEXT = array( 'script', 'style', 'textarea', 'title' );

	private const OPAQUE_SHORTCODES = array( 'et_pb_code', 'et_pb_fullwidth_code', 'vc_raw_html', 'vc_raw_js', 'fusion_code' );

	/** Page builder shortcodes: they split the content like block-level tags. */
	private const STRUCTURAL = '~^(et_pb_|vc_|fusion_)~';

	private const TAG = '<!--.*?-->|<!\[CDATA\[.*?\]\]>|<![^>]*>|<\?.*?\?>|</?[a-zA-Z][a-zA-Z0-9:._-]*(?:[^>"\']|"[^"]*"|\'[^\']*\')*>|\[\[?/?[a-zA-Z][\w-]*(?![\w-])(?:[^\]"\'\[]|"[^"]*"|\'[^\']*\')*\]\]?';

	/** WordPress block delimiter (same expression as WP_Block_Parser). */
	private const BLOCK_COMMENT = '/^<!--\s+(\/)?wp:([a-z][a-z0-9_-]*\/)?([a-z][a-z0-9_-]*)\s+({(?:(?:[^}]+|}+(?=})|(?!}\s+\/?-->).)*+)?}\s+)?(\/)?-->$/s';

	private const DIVI_KEYS = array( 'title', 'button_text', 'button_one_text', 'button_two_text', 'heading', 'subhead', 'alt', 'title_text', 'name', 'position', 'author', 'job_title', 'company_name', 'field_title', 'submit_button_text', 'placeholder', 'subtitle', 'per', 'description', 'label', 'success_message', 'link_text', 'text', 'content_title' );

	/**
	 * Extracts the texts of an HTML fragment into $bag.
	 *
	 * @param array<string, mixed> $opts kind: bag kind of text segments; link: callable localizing internal URLs.
	 * @return \Closure(array<string, string>): string Rebuilds the fragment from translations.
	 */
	public static function extract( string $html, Bag $bag, array $opts = array() ): \Closure {
		if ( '' === trim( $html ) ) {
			return static fn() => $html;
		}
		$parts = array();
		$run   = array();
		foreach ( self::tokenize( $html ) as $token ) {
			if ( $token['inline'] ) {
				$run[] = $token;
				continue;
			}
			if ( $run ) {
				$parts[] = self::run( $run, $bag, $opts );
				$run     = array();
			}
			$parts[] = self::boundary( $token, $bag, $opts );
		}
		if ( $run ) {
			$parts[] = self::run( $run, $bag, $opts );
		}
		return self::join( $parts );
	}

	/**
	 * A builder or block setting value: HTML is extracted, plain text becomes one item.
	 *
	 * @param array<string, mixed> $opts
	 * @return \Closure(array<string, string>): string
	 */
	public static function value( string $value, Bag $bag, array $opts = array(), string $kind = 'label' ): \Closure {
		if ( preg_match( '~<[a-z!/]~i', $value ) ) {
			unset( $opts['kind'] );
			return self::extract( $value, $bag, $opts );
		}
		$text = Placeholders::decode( $value );
		if ( ! Placeholders::has_words( $text ) ) {
			return static fn() => $value;
		}
		$id = $bag->add( trim( $text ), $kind );
		return static fn( array $tr ) => isset( $tr[ $id ] ) ? Placeholders::plain( $tr[ $id ] ) : $value;
	}

	/* ---------------------------------------------------------------------
	 * Tokenizer
	 * ------------------------------------------------------------------ */

	/**
	 * @return array<int, array<string, mixed>>
	 */
	private static function tokenize( string $html ): array {
		$tokens = array();
		$offset = 0;
		$length = strlen( $html );
		while ( $offset < $length && preg_match( '~' . self::TAG . '~s', $html, $m, PREG_OFFSET_CAPTURE, $offset ) ) {
			list( $string, $position ) = $m[0];
			if ( $position > $offset ) {
				$tokens[] = self::text( substr( $html, $offset, $position - $offset ) );
			}
			$token = self::classify( $string );
			$end   = $position + strlen( $string );
			if ( 'opaque_start' === $token['type'] ) {
				$close = $token['shortcode']
					? self::shortcode_close( $html, $end, $token['bare'] )
					: self::tag_close( $html, $end, $token['name'] );
				if ( null !== $close ) {
					$token = array(
						'type'   => 'opaque',
						's'      => substr( $html, $position, $close - $position ),
						'name'   => $token['name'],
						'inline' => $token['inline'],
					);
					$end   = $close;
				} else {
					$token['type'] = 'literal';
				}
			}
			$tokens[] = $token;
			$offset   = $end;
		}
		if ( $offset < $length ) {
			$tokens[] = self::text( substr( $html, $offset ) );
		}
		return $tokens;
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function text( string $string ): array {
		return array(
			'type'   => 'text',
			's'      => $string,
			'name'   => '',
			'inline' => true,
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function classify( string $string ): array {
		if ( 0 === strpos( $string, '<!--' ) ) {
			return array(
				'type'   => 'comment',
				's'      => $string,
				'name'   => '',
				'inline' => false,
			);
		}
		if ( 0 === strpos( $string, '<!' ) || 0 === strpos( $string, '<?' ) ) {
			return array(
				'type'   => 'literal',
				's'      => $string,
				'name'   => '',
				'inline' => false,
			);
		}
		if ( '[' === $string[0] ) {
			return self::classify_shortcode( $string );
		}
		preg_match( '~^<(/?)([a-zA-Z][a-zA-Z0-9:._-]*)~', $string, $m );
		$name   = strtolower( $m[2] );
		$inline = in_array( $name, self::INLINE, true );
		if ( '/' === $m[1] ) {
			return array(
				'type'   => 'close',
				's'      => $string,
				'name'   => $name,
				'inline' => $inline,
			);
		}
		$void = in_array( $name, self::VOID, true ) || '/>' === substr( $string, -2 );
		if ( ! $void && ( in_array( $name, self::OPAQUE, true ) || self::no_translate( $string ) ) ) {
			return array(
				'type'      => 'opaque_start',
				's'         => $string,
				'name'      => $name,
				'inline'    => $inline || in_array( $name, self::OPAQUE_INLINE, true ),
				'shortcode' => false,
			);
		}
		return array(
			'type'   => $void ? 'void' : 'open',
			's'      => $string,
			'name'   => $name,
			'inline' => $inline,
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function classify_shortcode( string $string ): array {
		if ( 0 === strpos( $string, '[[' ) ) {
			// Escaped shortcode: displayed as is.
			return array(
				'type'   => 'literal',
				's'      => $string,
				'name'   => '',
				'inline' => true,
			);
		}
		preg_match( '~^\[(/?)([a-zA-Z][\w-]*)~', $string, $m );
		$closing    = '/' === $m[1];
		$bare       = strtolower( $m[2] );
		$structural = (bool) preg_match( self::STRUCTURAL, $bare );
		$registered = function_exists( 'shortcode_exists' ) && shortcode_exists( $bare );
		// "[Read more]" is text, "[gallery]", "[contact-form-7 id=1]", "[/button]" are shortcodes.
		if ( ! $structural && ! $registered && ! $closing && false === strpos( $string, '=' ) && ! preg_match( '~^\[[a-z0-9]+[_-]~', $string ) ) {
			return self::text( $string );
		}
		if ( ! $closing && in_array( $bare, self::OPAQUE_SHORTCODES, true ) ) {
			return array(
				'type'      => 'opaque_start',
				's'         => $string,
				'name'      => 'sc:' . $bare,
				'bare'      => $bare,
				'inline'    => false,
				'shortcode' => true,
			);
		}
		$self_closing = ! $closing && '/' === substr( rtrim( $string, ']' ), -1 );
		return array(
			'type'       => $closing ? 'sc_close' : ( $self_closing ? 'sc_self' : 'sc_open' ),
			's'          => $string,
			'name'       => 'sc:' . $bare,
			'inline'     => ! $structural,
			'structural' => $structural,
		);
	}

	private static function no_translate( string $tag ): bool {
		return (bool) preg_match( '~\stranslate\s*=\s*["\']?no\b~i', $tag )
			|| (bool) preg_match( '~\sclass\s*=\s*(["\'])[^"\']*\bnotranslate\b~i', $tag );
	}

	/**
	 * Offset just after the closing tag of an element opened before $from.
	 */
	private static function tag_close( string $html, int $from, string $name ): ?int {
		if ( in_array( $name, self::RAW_TEXT, true ) ) {
			$position = stripos( $html, '</' . $name, $from );
			if ( false === $position ) {
				return null;
			}
			$gt = strpos( $html, '>', $position );
			return false === $gt ? null : $gt + 1;
		}
		$depth  = 1;
		$offset = $from;
		while ( preg_match( '~<(/?)' . preg_quote( $name, '~' ) . '(?=[\s/>])~i', $html, $m, PREG_OFFSET_CAPTURE, $offset ) ) {
			$gt = strpos( $html, '>', $m[0][1] );
			if ( false === $gt ) {
				return null;
			}
			if ( '/' === $m[1][0] ) {
				--$depth;
				if ( 0 === $depth ) {
					return $gt + 1;
				}
			} elseif ( '/' !== $html[ $gt - 1 ] ) {
				++$depth;
			}
			$offset = $gt + 1;
		}
		return null;
	}

	private static function shortcode_close( string $html, int $from, string $bare ): ?int {
		$position = stripos( $html, '[/' . $bare . ']', $from );
		return false === $position ? null : $position + strlen( $bare ) + 3;
	}

	/* ---------------------------------------------------------------------
	 * Segments
	 * ------------------------------------------------------------------ */

	/**
	 * A run of inline tokens: one segment with placeholders for the inline markup.
	 *
	 * @param array<int, array<string, mixed>> $tokens
	 * @param array<string, mixed>             $opts
	 * @return string|\Closure
	 */
	private static function run( array $tokens, Bag $bag, array $opts ) {
		$text = '';
		foreach ( $tokens as $token ) {
			if ( 'text' === $token['type'] ) {
				$text .= $token['s'];
			}
		}
		if ( ! Placeholders::has_words( Placeholders::decode( $text ) ) ) {
			return self::join( array_map( static fn( $t ) => self::prepare( $t, $bag, $opts ), $tokens ) );
		}

		$tokens = self::split_edge_spaces( $tokens );
		$parts  = array_map( static fn( $t ) => self::prepare( $t, $bag, $opts ), $tokens );
		$pairs  = self::pairs( $tokens );
		$start  = 0;
		$end    = count( $tokens ) - 1;
		$before = array();
		$after  = array();
		do {
			$moved = false;
			if ( $start <= $end && self::is_edge( $tokens[ $start ] ) ) {
				$before[] = $parts[ $start ];
				++$start;
				$moved = true;
			}
			if ( $start <= $end && self::is_edge( $tokens[ $end ] ) ) {
				array_unshift( $after, $parts[ $end ] );
				--$end;
				$moved = true;
			}
			if ( $start < $end && isset( $pairs[ $start ] ) && $pairs[ $start ] === $end ) {
				$before[] = $parts[ $start ];
				array_unshift( $after, $parts[ $end ] );
				++$start;
				--$end;
				$moved = true;
			}
		} while ( $moved && $start <= $end );

		if ( $start > $end ) {
			return self::join( $parts );
		}

		$masked  = '';
		$map     = array();
		$number  = 0;
		$closers = array();
		for ( $k = $start; $k <= $end; $k++ ) {
			$token = $tokens[ $k ];
			if ( 'text' === $token['type'] ) {
				$masked .= Placeholders::decode( $token['s'] );
			} elseif ( isset( $pairs[ $k ] ) && $pairs[ $k ] <= $end ) {
				++$number;
				$closers[ $pairs[ $k ] ] = $number;
				$masked                 .= '<g' . $number . '>';
				$map[ '<g' . $number . '>' ] = $parts[ $k ];
			} elseif ( isset( $closers[ $k ] ) ) {
				$masked .= '</g' . $closers[ $k ] . '>';
				$map[ '</g' . $closers[ $k ] . '>' ] = $parts[ $k ];
			} else {
				++$number;
				$masked                       .= '<x' . $number . '/>';
				$map[ '<x' . $number . '/>' ] = $parts[ $k ];
			}
		}
		$masked = trim( $masked );
		$id     = $bag->add( $masked, (string) ( $opts['kind'] ?? 'text' ) );
		$middle = array_slice( $parts, $start, $end - $start + 1 );

		return static function ( array $tr ) use ( $id, $masked, $map, $middle, $before, $after ): string {
			$body = isset( $tr[ $id ] ) ? Placeholders::restore( $tr[ $id ], $masked, $map, $tr ) : null;
			if ( null === $body ) {
				$body = self::render( $middle, $tr );
			}
			return self::render( $before, $tr ) . $body . self::render( $after, $tr );
		};
	}

	/**
	 * Whitespace and line breaks at the edges stay outside the segment.
	 *
	 * @param array<string, mixed> $token
	 */
	private static function is_edge( array $token ): bool {
		if ( 'text' === $token['type'] ) {
			return '' === trim( Placeholders::decode( $token['s'] ), " \t\n\r\0\x0B\xC2\xA0" );
		}
		return 'void' === $token['type'] && in_array( $token['name'], array( 'br', 'wbr' ), true );
	}

	/**
	 * @param array<int, array<string, mixed>> $tokens
	 * @return array<int, array<string, mixed>>
	 */
	private static function split_edge_spaces( array $tokens ): array {
		$first = $tokens[0];
		if ( 'text' === $first['type'] && preg_match( '~^(\s+)(.+)$~s', $first['s'], $m ) ) {
			array_splice( $tokens, 0, 1, array( self::text( $m[1] ), self::text( $m[2] ) ) );
		}
		$last_index = count( $tokens ) - 1;
		$last       = $tokens[ $last_index ];
		if ( 'text' === $last['type'] && preg_match( '~^(.*?)(\s+)$~s', $last['s'], $m ) && '' !== $m[1] ) {
			array_splice( $tokens, $last_index, 1, array( self::text( $m[1] ), self::text( $m[2] ) ) );
		}
		return array_values( $tokens );
	}

	/**
	 * Matching inline tags: index of an opening token => index of its closing token.
	 *
	 * @param array<int, array<string, mixed>> $tokens
	 * @return array<int, int>
	 */
	private static function pairs( array $tokens ): array {
		$pairs = array();
		$stack = array();
		foreach ( $tokens as $i => $token ) {
			if ( 'open' === $token['type'] || 'sc_open' === $token['type'] ) {
				$stack[] = $i;
			} elseif ( 'close' === $token['type'] || 'sc_close' === $token['type'] ) {
				for ( $k = count( $stack ) - 1; $k >= 0; $k-- ) {
					if ( $tokens[ $stack[ $k ] ]['name'] === $token['name'] ) {
						$pairs[ $stack[ $k ] ] = $i;
						array_splice( $stack, $k );
						break;
					}
				}
			}
		}
		return $pairs;
	}

	/**
	 * A token outside of segments.
	 *
	 * @param array<string, mixed> $token
	 * @param array<string, mixed> $opts
	 * @return string|\Closure
	 */
	private static function boundary( array $token, Bag $bag, array $opts ) {
		if ( 'comment' === $token['type'] ) {
			return self::block_comment( $token['s'], $bag, $opts );
		}
		return self::prepare( $token, $bag, $opts );
	}

	/**
	 * Renderer of one token: attributes translated, links localized.
	 *
	 * @param array<string, mixed> $token
	 * @param array<string, mixed> $opts
	 * @return string|\Closure
	 */
	private static function prepare( array $token, Bag $bag, array $opts ) {
		switch ( $token['type'] ) {
			case 'open':
			case 'void':
				return self::prepare_tag( $token['s'], $token['name'], $bag, $opts );
			case 'sc_open':
			case 'sc_self':
				return ! empty( $token['structural'] ) ? self::prepare_shortcode( $token['s'], $bag, $opts ) : $token['s'];
			default:
				return $token['s'];
		}
	}

	/**
	 * @param array<string, mixed> $opts
	 * @return string|\Closure
	 */
	private static function prepare_tag( string $tag, string $name, Bag $bag, array $opts ) {
		$pattern = '~(\s)(alt|title|placeholder|aria-label|value)(\s*=\s*)(?:"([^"]*)"|\'([^\']*)\')~i';
		$attrs   = array();
		if ( ! self::no_translate( $tag ) && preg_match_all( $pattern, $tag, $matches, PREG_SET_ORDER ) ) {
			foreach ( $matches as $a ) {
				$attr = strtolower( $a[2] );
				if ( 'value' === $attr && ! ( 'input' === $name && preg_match( '~\stype\s*=\s*["\']?(submit|button|reset)\b~i', $tag ) ) ) {
					continue;
				}
				$value = Placeholders::decode( isset( $a[5] ) && '' !== $a[5] ? $a[5] : $a[4] );
				if ( Placeholders::has_words( $value ) ) {
					$attrs[ $attr ] = $bag->add( trim( $value ), 'label' );
				}
			}
		}
		$link = isset( $opts['link'] ) && is_callable( $opts['link'] ) && in_array( $name, array( 'a', 'area' ), true ) && false !== stripos( $tag, 'href' );
		if ( ! $attrs && ! $link ) {
			return $tag;
		}
		$localize = $link ? $opts['link'] : null;
		return static function ( array $tr ) use ( $tag, $attrs, $pattern, $localize ): string {
			$out = $tag;
			if ( $attrs ) {
				$out = (string) preg_replace_callback(
					$pattern,
					static function ( $a ) use ( $attrs, $tr ) {
						$attr = strtolower( $a[2] );
						if ( ! isset( $attrs[ $attr ], $tr[ $attrs[ $attr ] ] ) ) {
							return $a[0];
						}
						$quote = "'" === substr( $a[0], -1 ) ? "'" : '"';
						return $a[1] . $a[2] . $a[3] . $quote . Placeholders::attr( $tr[ $attrs[ $attr ] ], $quote ) . $quote;
					},
					$out
				);
			}
			if ( null !== $localize ) {
				$out = (string) preg_replace_callback(
					'~(\shref\s*=\s*)(["\'])(.*?)\2~is',
					static fn( $h ) => $h[1] . $h[2] . call_user_func( $localize, $h[3] ) . $h[2],
					$out
				);
			}
			return $out;
		};
	}

	/**
	 * Divi 4 / WPBakery shortcode: text attributes translated, URLs localized.
	 *
	 * @param array<string, mixed> $opts
	 * @return string|\Closure
	 */
	private static function prepare_shortcode( string $tag, Bag $bag, array $opts ) {
		$pattern = '~(\s)([\w-]+)=(?:"([^"]*)"|\'([^\']*)\')~';
		if ( ! preg_match_all( $pattern, $tag, $matches, PREG_SET_ORDER ) ) {
			return $tag;
		}
		$texts = array();
		$urls  = false;
		foreach ( $matches as $a ) {
			$key = strtolower( $a[2] );
			$raw = isset( $a[4] ) && '' !== $a[4] ? $a[4] : $a[3];
			if ( self::is_divi_url_key( $key ) ) {
				$urls = $urls || ( isset( $opts['link'] ) && is_callable( $opts['link'] ) && '' !== $raw );
				continue;
			}
			if ( ! self::is_divi_text_key( $key ) ) {
				continue;
			}
			$value = str_replace( array( '%22', '%91', '%93' ), array( '"', '[', ']' ), $raw );
			if ( Placeholders::looks_like_text( Placeholders::decode( $value ) ) ) {
				$texts[ $key ] = self::value( $value, $bag, $opts, 'label' );
			}
		}
		if ( ! $texts && ! $urls ) {
			return $tag;
		}
		$localize = $urls ? $opts['link'] : null;
		return static function ( array $tr ) use ( $tag, $texts, $pattern, $localize ): string {
			return (string) preg_replace_callback(
				$pattern,
				static function ( $a ) use ( $texts, $tr, $localize ) {
					$key   = strtolower( $a[2] );
					$quote = "'" === substr( $a[0], -1 ) ? "'" : '"';
					if ( isset( $texts[ $key ] ) ) {
						$value = str_replace( array( '"', '[', ']' ), array( '%22', '%91', '%93' ), $texts[ $key ]( $tr ) );
						return $a[1] . $a[2] . '=' . $quote . $value . $quote;
					}
					if ( null !== $localize && self::is_divi_url_key( $key ) ) {
						$raw = isset( $a[4] ) && '' !== $a[4] ? $a[4] : $a[3];
						return $a[1] . $a[2] . '=' . $quote . call_user_func( $localize, $raw ) . $quote;
					}
					return $a[0];
				},
				$tag
			);
		};
	}

	private static function is_divi_text_key( string $key ): bool {
		if ( in_array( $key, self::DIVI_KEYS, true ) ) {
			return true;
		}
		if ( 'admin_label' === $key || '_' === $key[0] ) {
			return false;
		}
		return (bool) preg_match( '~_(text|title|label|message|placeholder|heading)$~', $key );
	}

	private static function is_divi_url_key( string $key ): bool {
		return in_array( $key, array( 'url', 'link_url', 'button_url', 'button_one_url', 'button_two_url', 'link_option_url' ), true );
	}

	/**
	 * Gutenberg block delimiter: translatable attributes of the block JSON.
	 *
	 * @param array<string, mixed> $opts
	 * @return string|\Closure
	 */
	private static function block_comment( string $comment, Bag $bag, array $opts ) {
		if ( ! preg_match( self::BLOCK_COMMENT, $comment, $m ) || '/' === $m[1] || empty( $m[4] ) ) {
			return $comment;
		}
		$attrs = json_decode( trim( $m[4] ) );
		if ( ! is_object( $attrs ) ) {
			return $comment;
		}
		$found = false;
		$block = ( '' !== $m[2] ? $m[2] : 'core/' ) . $m[3];
		unset( $opts['kind'] );
		$tree = Attributes::walk( $attrs, $bag, $opts, $found, '', false, $block );
		if ( ! $found ) {
			return $comment;
		}
		$name = $m[2] . $m[3];
		$void = ! empty( $m[5] );
		return static function ( array $tr ) use ( $tree, $name, $void ): string {
			$json = Attributes::serialize( Attributes::render( $tree, $tr ) );
			return '<!-- wp:' . $name . ' ' . $json . ' ' . ( $void ? '/-->' : '-->' );
		};
	}

	/* ---------------------------------------------------------------------
	 * Rendering
	 * ------------------------------------------------------------------ */

	/**
	 * @param array<int, string|\Closure> $parts
	 * @return \Closure(array<string, string>): string
	 */
	public static function join( array $parts ): \Closure {
		foreach ( $parts as $part ) {
			if ( $part instanceof \Closure ) {
				return static fn( array $tr ) => self::render( $parts, $tr );
			}
		}
		$string = implode( '', $parts );
		return static fn() => $string;
	}

	/**
	 * @param array<int, string|\Closure> $parts
	 * @param array<string, string>       $tr
	 */
	public static function render( array $parts, array $tr ): string {
		$out = '';
		foreach ( $parts as $part ) {
			$out .= $part instanceof \Closure ? $part( $tr ) : $part;
		}
		return $out;
	}
}
