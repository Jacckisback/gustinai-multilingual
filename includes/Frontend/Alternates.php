<?php
/**
 * URLs of the current page in every language (hreflang, switcher, schema).
 *
 * @package GustinAI\Multilingual
 */

namespace GustinAI\Multilingual\Frontend;

use GustinAI\Multilingual\Core\Content;
use GustinAI\Multilingual\Core\Links;
use GustinAI\Multilingual\Core\Router;
use GustinAI\Multilingual\Core\Translations;
use GustinAI\Multilingual\Languages;

defined( 'ABSPATH' ) || exit;

final class Alternates {

	/** @var array<string, array{url: string, exists: bool}>|null */
	private static ?array $cache = null;

	/**
	 * lang => array( url, exists ). "exists" is false when the page has no
	 * equivalent in that language (the URL is then the language home).
	 *
	 * @return array<string, array{url: string, exists: bool}>
	 */
	public static function current(): array {
		if ( null !== self::$cache ) {
			return self::$cache;
		}
		$out = array();
		foreach ( Languages::codes() as $lang ) {
			$out[ $lang ] = array(
				'url'    => Links::language_home( $lang ),
				'exists' => false,
			);
		}
		if ( ! Languages::is_multilingual() || is_404() ) {
			return self::$cache = $out;
		}

		$paged = max( (int) get_query_var( 'paged' ), 1 );

		if ( is_front_page() ) {
			$front   = (int) get_option( 'page_on_front' );
			$members = ( 'page' === get_option( 'show_on_front' ) && $front ) ? Translations::members( 'post', $front ) : null;
			foreach ( $out as $lang => $row ) {
				$out[ $lang ]['exists'] = null === $members || ( isset( $members[ $lang ] ) && 'publish' === get_post_status( $members[ $lang ] ) );
				if ( $out[ $lang ]['exists'] && $paged > 1 ) {
					$out[ $lang ]['url'] = self::paged( $row['url'], $paged );
				}
			}
		} elseif ( is_singular() || ( is_home() && (int) get_option( 'page_for_posts' ) ) ) {
			$id   = is_singular() ? (int) get_queried_object_id() : (int) get_option( 'page_for_posts' );
			$post = get_post( $id );
			if ( $post && Content::is_translatable_post_type( $post->post_type ) ) {
				$members = Translations::members( 'post', $id );
				_prime_post_caches( array_values( $members ), false, false );
				foreach ( $members as $lang => $member ) {
					if ( isset( $out[ $lang ] ) && ( 'publish' === get_post_status( $member ) || $member === $id ) ) {
						$out[ $lang ] = array(
							'url'    => (string) get_permalink( $member ),
							'exists' => true,
						);
					}
				}
			} else {
				foreach ( $out as $lang => $row ) {
					$out[ $lang ]['url'] = Links::add_lang( Links::current_url(), $lang );
				}
				$current                    = Router::current() ?? Languages::default_code();
				$out[ $current ]['exists'] = true;
			}
		} elseif ( is_category() || is_tag() || is_tax() ) {
			$term = get_queried_object();
			if ( $term instanceof \WP_Term && Content::is_translatable_taxonomy( $term->taxonomy ) ) {
				foreach ( Translations::members( 'term', $term->term_id ) as $lang => $member ) {
					$link = get_term_link( $member );
					if ( isset( $out[ $lang ] ) && ! is_wp_error( $link ) ) {
						$out[ $lang ] = array(
							'url'    => self::paged( (string) $link, $paged ),
							'exists' => true,
						);
					}
				}
			}
		} elseif ( is_post_type_archive() || is_date() || is_author() || is_home() ) {
			foreach ( $out as $lang => $row ) {
				$out[ $lang ] = array(
					'url'    => Links::add_lang( Links::current_url(), $lang ),
					'exists' => true,
				);
			}
		} elseif ( is_search() ) {
			foreach ( $out as $lang => $row ) {
				$out[ $lang ]['url'] = add_query_arg( 's', rawurlencode( get_search_query( false ) ), Links::language_home( $lang ) );
			}
		}
		/**
		 * Filters the URLs of the current page in every language.
		 *
		 * @param array<string, array{url: string, exists: bool}> $out
		 */
		self::$cache = (array) apply_filters( 'gaiml_alternates', $out );
		return self::$cache;
	}

	private static function paged( string $url, int $paged ): string {
		if ( $paged <= 1 ) {
			return $url;
		}
		global $wp_rewrite;
		if ( $wp_rewrite->using_permalinks() ) {
			return trailingslashit( $url ) . user_trailingslashit( $wp_rewrite->pagination_base . '/' . $paged, 'paged' );
		}
		return add_query_arg( 'paged', $paged, $url );
	}

	public static function reset(): void {
		self::$cache = null;
	}
}
