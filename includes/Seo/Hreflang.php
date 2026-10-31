<?php
/**
 * hreflang annotations (Google, Bing, AI crawlers): one <link rel="alternate">
 * per existing translation, the page itself included, plus x-default.
 *
 * The <html lang> attribute and og:locale follow automatically: WordPress and
 * SEO plugins read the locale, which is switched to the page language.
 *
 * @package GustinAI\Multilingual
 */

namespace GustinAI\Multilingual\Seo;

use GustinAI\Multilingual\Core\Router;
use GustinAI\Multilingual\Frontend\Alternates;
use GustinAI\Multilingual\Languages;
use GustinAI\Multilingual\Settings;

defined( 'ABSPATH' ) || exit;

final class Hreflang {

	public static function init(): void {
		add_action( 'wp_head', array( self::class, 'output' ), 2 );
	}

	/**
	 * @return array<string, string> hreflang => URL
	 */
	public static function links(): array {
		if ( null === Router::current() || Router::is_neutral() || is_404() || is_search() || is_preview() || is_feed() || post_password_required() ) {
			return array();
		}
		if ( is_singular() && 'publish' !== get_post_status( get_queried_object_id() ) ) {
			return array();
		}
		$links = array();
		foreach ( Alternates::current() as $lang => $row ) {
			if ( $row['exists'] ) {
				$links[ Languages::hreflang( $lang ) ] = $row['url'];
			}
		}
		if ( count( $links ) < 2 ) {
			return array();
		}
		$default = Languages::default_code();
		$rows    = Alternates::current();
		if ( Settings::get( 'x_default' ) && ! empty( $rows[ $default ]['exists'] ) ) {
			$links['x-default'] = $rows[ $default ]['url'];
		}
		/**
		 * Filters the hreflang links of the current page.
		 *
		 * @param array<string, string> $links hreflang => URL
		 */
		return (array) apply_filters( 'gaiml_hreflang', $links );
	}

	public static function output(): void {
		foreach ( self::links() as $hreflang => $url ) {
			printf( '<link rel="alternate" hreflang="%s" href="%s" />' . "\n", esc_attr( (string) $hreflang ), esc_url( $url ) );
		}
	}
}
