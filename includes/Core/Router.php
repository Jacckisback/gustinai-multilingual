<?php
/**
 * Request language.
 *
 * The language comes from the URL only (/en/..., the default language has no
 * prefix by default), never from cookies or headers, so every URL has exactly
 * one language and pages stay cacheable by any page cache or CDN.
 *
 * Detection runs when the plugin file is loaded, before WordPress loads its
 * translations, so core, theme and plugin strings use the visitor's language.
 * Front-end AJAX requests use the `lang` parameter or the referring page.
 *
 * @package GustinAI\Multilingual
 */

namespace GustinAI\Multilingual\Core;

use GustinAI\Multilingual\Languages;
use GustinAI\Multilingual\Settings;

defined( 'ABSPATH' ) || exit;

final class Router {

	private static ?string $current = null;

	/** Sitemaps and robots.txt: default locale, no language filtering. */
	private static bool $neutral = false;

	/** front | ajax | rest | none */
	private static string $context = 'none';

	/** Language slug found at the start of the requested path ('' if none). */
	private static string $prefix = '';

	public static function init(): void {
		if ( ! Languages::is_ready() ) {
			return;
		}
		self::detect();
		if ( null === self::$current ) {
			return;
		}
		add_filter( 'locale', array( self::class, 'locale' ), 1 );
		add_action( 'init', array( self::class, 'text_direction' ), 0 );
		if ( 'ajax' === self::$context ) {
			add_filter( 'determine_locale', array( self::class, 'locale' ), 20 );
		}
		if ( 'front' === self::$context ) {
			add_action( 'template_redirect', array( self::class, 'canonical_prefix' ), 0 );
			add_filter( 'redirect_canonical', array( self::class, 'redirect_canonical' ), 20 );
		}
	}

	public static function current(): ?string {
		return self::$current;
	}

	public static function context(): string {
		return self::$context;
	}

	public static function is_neutral(): bool {
		return self::$neutral;
	}

	/**
	 * Whether generic URLs (home, archives, search) get the current language prefix.
	 */
	public static function filters_home(): bool {
		return null !== self::$current && ! self::$neutral && ( 'front' === self::$context || 'ajax' === self::$context );
	}

	/**
	 * Forces the current language (WP-CLI, tests, rendering in another language).
	 * Returns the previous language.
	 */
	public static function switch_to( ?string $lang, string $context = 'front' ): ?string {
		$previous      = self::$current;
		self::$current = ( null !== $lang && Languages::exists( $lang ) ) ? $lang : null;
		if ( null !== self::$current && 'none' === self::$context ) {
			self::$context = $context;
		}
		return $previous;
	}

	/**
	 * Right-to-left languages stay RTL even when their WordPress translation
	 * files are not installed.
	 */
	public static function text_direction(): void {
		global $wp_locale;
		$language = null !== self::$current ? Languages::get( self::$current ) : null;
		if ( $language && ! empty( $language['rtl'] ) && $wp_locale instanceof \WP_Locale ) {
			$wp_locale->text_direction = 'rtl';
		}
	}

	/**
	 * @param string $locale
	 */
	public static function locale( $locale ): string {
		return null !== self::$current ? Languages::locale( self::$current ) : (string) $locale;
	}

	private static function detect(): void {
		global $pagenow;
		if ( ( defined( 'WP_CLI' ) && WP_CLI ) || wp_doing_cron() ) {
			return;
		}
		$uri  = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : '/'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Only compared with known slugs.
		$path = (string) wp_parse_url( $uri, PHP_URL_PATH );

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only language selection.
		$requested = '';
		if ( isset( $_REQUEST['lang'] ) && is_string( $_REQUEST['lang'] ) ) {
			$requested = sanitize_key( wp_unslash( $_REQUEST['lang'] ) );
		}
		// phpcs:enable

		if ( wp_doing_ajax() ) {
			self::$context = 'ajax';
			if ( Languages::exists( $requested ) ) {
				self::$current = $requested;
				return;
			}
			$referer = wp_get_raw_referer();
			if ( $referer && false === strpos( $referer, '/wp-admin/' ) ) {
				$ref_host  = (string) wp_parse_url( $referer, PHP_URL_HOST );
				$home_host = (string) wp_parse_url( (string) get_option( 'home' ), PHP_URL_HOST );
				if ( '' !== $ref_host && strtolower( $ref_host ) === strtolower( $home_host ) ) {
					self::$current = self::lang_from_relative( self::relative_path( (string) wp_parse_url( $referer, PHP_URL_PATH ) ) );
				}
			}
			return;
		}

		if ( is_admin() ) {
			return;
		}

		$relative = self::relative_path( $path );
		if ( 0 === strpos( $relative . '/', rest_get_url_prefix() . '/' ) || isset( $_GET['rest_route'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			self::$context = 'rest';
			if ( Languages::exists( $requested ) ) {
				self::$current = $requested;
			}
			return;
		}

		if ( 'index.php' !== $pagenow ) {
			return;
		}

		self::$context = 'front';
		if ( 'robots.txt' === $relative || preg_match( '#(^|/)[^/]*sitemap[^/]*\.(xml|xsl)$#i', $relative ) ) {
			self::$neutral = true;
			self::$current = Languages::default_code();
			return;
		}
		self::$current = self::lang_from_relative( $relative );
	}

	/**
	 * Path relative to the home URL, without leading or trailing slash.
	 */
	public static function relative_path( string $path ): string {
		$path      = trim( $path, '/' );
		$home_path = trim( (string) wp_parse_url( (string) get_option( 'home' ), PHP_URL_PATH ), '/' );
		if ( '' !== $home_path ) {
			if ( 0 === stripos( $path . '/', $home_path . '/' ) ) {
				$path = trim( substr( $path, strlen( $home_path ) ), '/' );
			}
		}
		return $path;
	}

	private static function lang_from_relative( string $relative ): string {
		$first = (string) strtok( $relative, '/' );
		$code  = '' !== $first ? Languages::by_slug( $first ) : null;
		if ( null !== $code ) {
			self::$prefix = $first;
			return $code;
		}
		return Languages::default_code();
	}

	/**
	 * When WordPress redirects a mistyped URL to a page of another language,
	 * prefer the translation in the language of the requested URL.
	 *
	 * @param string|false $redirect
	 * @return string|false
	 */
	public static function redirect_canonical( $redirect ) {
		if ( ! is_string( $redirect ) || '' === $redirect || null === self::$current || ! is_404() ) {
			return $redirect;
		}
		list( , $target_lang ) = Links::strip_lang( $redirect );
		$target_lang           = $target_lang ?? Languages::default_code();
		if ( $target_lang === self::$current ) {
			return $redirect;
		}
		$localized = Links::localize_url( $redirect, self::$current );
		return $localized !== $redirect ? $localized : $redirect;
	}

	/**
	 * One URL per page: /fr/... redirects to /... when the default language is
	 * hidden, and / redirects to /fr/ when it is not.
	 */
	public static function canonical_prefix(): void {
		if ( self::$neutral || ! in_array( $_SERVER['REQUEST_METHOD'] ?? 'GET', array( 'GET', 'HEAD' ), true ) || is_preview() || is_robots() || is_favicon() ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			return;
		}
		$default = Languages::default_code();
		$hidden  = (bool) Settings::get( 'hide_default' );
		$uri     = (string) wp_unslash( $_SERVER['REQUEST_URI'] ?? '/' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$target  = '';

		if ( $hidden && $default === self::$current && '' !== self::$prefix ) {
			$target = Links::strip_lang( Links::origin() . $uri )[0];
		} elseif ( ! $hidden && '' === self::$prefix ) {
			// Every URL of the default language carries its prefix too: /contact/ → /fr/contact/.
			$target = Links::add_lang( Links::origin() . '/' . ltrim( $uri, '/' ), $default );
		}
		if ( '' !== $target ) {
			wp_safe_redirect( $target, 301, 'GustinAI Multilingual' );
			exit;
		}
	}
}
