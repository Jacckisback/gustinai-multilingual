<?php
/**
 * llms.txt per language (GEO): /llms.txt for the default language and
 * /en/llms.txt, /es/llms.txt… for the others. A Markdown index of the main
 * pages and latest posts that AI assistants can read quickly.
 *
 * A physical llms.txt file at the site root takes precedence (the web server
 * serves it before WordPress).
 *
 * @package GustinAI\Multilingual
 */

namespace GustinAI\Multilingual\Seo;

use GustinAI\Multilingual\Core\Content;
use GustinAI\Multilingual\Core\Links;
use GustinAI\Multilingual\Core\Router;
use GustinAI\Multilingual\Languages;
use GustinAI\Multilingual\Settings;

defined( 'ABSPATH' ) || exit;

final class LlmsTxt {

	public static function init(): void {
		if ( ! Settings::get( 'llms_txt' ) ) {
			return;
		}
		add_filter( 'do_parse_request', array( self::class, 'maybe_serve' ), 1 );
		add_action( 'save_post', array( self::class, 'flush' ) );
		add_action( 'gaiml_translations_changed', array( self::class, 'flush' ) );
	}

	/**
	 * @param bool $parse
	 */
	public static function maybe_serve( $parse ) {
		if ( 'front' !== Router::context() || ! Languages::is_ready() ) {
			return $parse;
		}
		$path     = (string) wp_parse_url( (string) wp_unslash( $_SERVER['REQUEST_URI'] ?? '/' ), PHP_URL_PATH ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$relative = Router::relative_path( $path );
		$lang     = null;
		if ( 'llms.txt' === $relative ) {
			$lang = Languages::default_code();
			if ( '' !== Languages::url_slug( $lang ) ) {
				$lang = null;
			}
		} elseif ( preg_match( '~^([^/]+)/llms\.txt$~', $relative, $m ) ) {
			$code = Languages::by_slug( $m[1] );
			$lang = ( null !== $code && '' !== Languages::url_slug( $code ) ) ? $code : null;
		}
		if ( null === $lang ) {
			return $parse;
		}
		nocache_headers();
		header( 'Content-Type: text/plain; charset=utf-8' );
		header( 'X-Robots-Tag: noindex' );
		echo self::content( $lang ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Plain text document.
		exit;
	}

	public static function content( string $lang ): string {
		$cached = get_transient( 'gaiml_llms_' . $lang );
		if ( is_string( $cached ) ) {
			return $cached;
		}
		$previous = Router::switch_to( $lang );
		$lines    = array();
		$lines[]  = '# ' . self::clean( get_bloginfo( 'name' ) );
		$tagline  = self::clean( get_bloginfo( 'description' ) );
		if ( '' !== $tagline ) {
			$lines[] = '';
			$lines[] = '> ' . $tagline;
		}
		$lines[] = '';
		$lines[] = sprintf( 'Language: %1$s (%2$s). Website: %3$s', Languages::name( $lang ), Languages::hreflang( $lang ), Links::language_home( $lang ) );

		foreach ( Content::post_types() as $post_type ) {
			$object = get_post_type_object( $post_type );
			if ( ! $object || ! $object->public || in_array( $post_type, array( 'attachment', 'elementor_library', 'wp_block', 'wp_navigation' ), true ) ) {
				continue;
			}
			$query = new \WP_Query(
				array(
					'post_type'              => $post_type,
					'post_status'            => 'publish',
					'posts_per_page'         => 'page' === $post_type ? 100 : 50,
					'orderby'                => 'page' === $post_type ? 'menu_order title' : 'date',
					'order'                  => 'page' === $post_type ? 'ASC' : 'DESC',
					'has_password'           => false,
					'gaiml_lang'             => $lang,
					'no_found_rows'          => true,
					'update_post_term_cache' => false,
					'ignore_sticky_posts'    => true,
				)
			);
			$items = array();
			foreach ( $query->posts as $post ) {
				if ( self::noindex( $post->ID ) ) {
					continue;
				}
				$summary = self::clean( has_excerpt( $post ) ? $post->post_excerpt : self::summary( $post->post_content ) );
				$items[] = '- [' . self::clean( get_the_title( $post ) ) . '](' . get_permalink( $post ) . ')' . ( '' !== $summary ? ': ' . $summary : '' );
			}
			if ( $items ) {
				$lines[] = '';
				$lines[] = '## ' . self::clean( (string) $object->labels->name );
				$lines[] = '';
				array_push( $lines, ...$items );
			}
		}

		$others = array();
		foreach ( Languages::others( $lang ) as $other ) {
			$slug     = Languages::url_slug( $other );
			$others[] = '- [' . Languages::name( $other ) . '](' . Links::raw_home() . '/' . ( '' !== $slug ? $slug . '/' : '' ) . 'llms.txt)';
		}
		if ( $others ) {
			$lines[] = '';
			$lines[] = '## Other languages';
			$lines[] = '';
			array_push( $lines, ...$others );
		}
		Router::switch_to( $previous );

		/**
		 * Filters the llms.txt document of a language.
		 */
		$text = (string) apply_filters( 'gaiml_llms_txt', implode( "\n", $lines ) . "\n", $lang );
		set_transient( 'gaiml_llms_' . $lang, $text, 12 * HOUR_IN_SECONDS );
		return $text;
	}

	/**
	 * First words of a content: blocks, shortcodes and markup removed.
	 */
	private static function summary( string $content ): string {
		$content = (string) preg_replace( '~<!--.*?-->~s', ' ', $content );
		$content = (string) preg_replace( '~<(script|style|pre|code)\b.*?</\1>~is', ' ', $content );
		$content = (string) preg_replace( '~\[/?[a-zA-Z][^\]]*\]~', ' ', $content );
		$content = (string) preg_replace( '~<(br|/p|/h[1-6]|/li|/div|/td|/figcaption)\b[^>]*>~i', ' ', $content );
		return wp_trim_words( wp_strip_all_tags( $content ), 30, '…' );
	}

	private static function noindex( int $id ): bool {
		if ( '1' === (string) get_post_meta( $id, '_yoast_wpseo_meta-robots-noindex', true ) ) {
			return true;
		}
		$robots = get_post_meta( $id, 'rank_math_robots', true );
		return is_array( $robots ) && in_array( 'noindex', $robots, true );
	}

	private static function clean( string $text ): string {
		$text = html_entity_decode( wp_strip_all_tags( $text ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		return trim( (string) preg_replace( '~\s+~u', ' ', str_replace( array( '[', ']' ), array( '(', ')' ), $text ) ) );
	}

	public static function flush(): void {
		foreach ( Languages::codes() as $lang ) {
			delete_transient( 'gaiml_llms_' . $lang );
		}
	}
}
