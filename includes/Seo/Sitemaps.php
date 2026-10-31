<?php
/**
 * WordPress core sitemaps list the content of every language (each URL with
 * its language prefix) and the home page of every language.
 *
 * Yoast SEO, Rank Math and other sitemap generators read the content directly
 * and get language-aware permalinks from the permalink filters.
 *
 * @package GustinAI\Multilingual
 */

namespace GustinAI\Multilingual\Seo;

use GustinAI\Multilingual\Languages;

defined( 'ABSPATH' ) || exit;

final class Sitemaps {

	public static function init(): void {
		add_filter( 'wp_sitemaps_posts_query_args', array( self::class, 'all_languages' ) );
		add_filter( 'wp_sitemaps_taxonomies_query_args', array( self::class, 'all_languages' ) );
		add_action( 'wp_sitemaps_init', array( self::class, 'register' ) );
	}

	/**
	 * @param array<string, mixed> $args
	 * @return array<string, mixed>
	 */
	public static function all_languages( $args ) {
		$args['gaiml_lang'] = 'all';
		return $args;
	}

	/**
	 * @param \WP_Sitemaps $sitemaps
	 */
	public static function register( $sitemaps ): void {
		if ( Languages::is_multilingual() && 'page' !== get_option( 'show_on_front' ) ) {
			$sitemaps->registry->add_provider( 'languages', new SitemapProvider() );
		}
	}
}
