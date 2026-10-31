<?php
/**
 * Language-aware URLs.
 *
 * - home_url() returns the current language home (/en/) on the front end, so
 *   every generic link (home, archives, search, feeds) stays in the visitor's
 *   language and WordPress parses /en/... natively (the prefix is the home path).
 * - Permalinks of posts and terms always use the language of the object.
 * - Internal links inside translated content point to the translated pages.
 *
 * @package GustinAI\Multilingual
 */

namespace GustinAI\Multilingual\Core;

use GustinAI\Multilingual\Languages;
use GustinAI\Multilingual\Settings;

defined( 'ABSPATH' ) || exit;

final class Links {

	private static int $suspended = 0;

	private static ?string $raw_home = null;

	/** @var array<string, string> Request cache of localized URLs. */
	private static array $localized = array();

	/** @var array<string, array<string, string>> Persistent map per language. */
	private static array $map = array();

	/** @var array<string, bool> Languages whose map changed during the request. */
	private static array $map_dirty = array();

	/** The link maps must be invalidated at the end of the request. */
	private static bool $bump = false;

	public static function init(): void {
		add_filter( 'home_url', array( self::class, 'home_url' ), 10, 4 );
		add_filter( 'post_link', array( self::class, 'post_link' ), 20, 2 );
		add_filter( 'post_type_link', array( self::class, 'post_link' ), 20, 2 );
		add_filter( 'page_link', array( self::class, 'page_link' ), 20, 2 );
		add_filter( 'term_link', array( self::class, 'term_link' ), 20, 3 );
		if ( Settings::get( 'link_localization' ) ) {
			add_filter( 'the_content', array( self::class, 'localize_content' ), 999 );
		}
		add_action( 'shutdown', array( self::class, 'save_maps' ) );
		add_action( 'gaiml_translations_changed', array( self::class, 'bump' ) );
		add_action( 'post_updated', array( self::class, 'maybe_bump' ), 10, 3 );
	}

	/* ---------------------------------------------------------------------
	 * URL helpers
	 * ------------------------------------------------------------------ */

	/**
	 * Home URL as saved in the settings, without trailing slash.
	 */
	public static function raw_home(): string {
		if ( null === self::$raw_home ) {
			self::$raw_home = untrailingslashit( (string) get_option( 'home' ) );
		}
		return self::$raw_home;
	}

	/**
	 * Scheme and host of the site (https://example.com).
	 */
	public static function origin(): string {
		$parts = wp_parse_url( self::raw_home() );
		$out   = ( $parts['scheme'] ?? 'https' ) . '://' . ( $parts['host'] ?? '' );
		if ( ! empty( $parts['port'] ) ) {
			$out .= ':' . $parts['port'];
		}
		return $out;
	}

	/**
	 * Splits a URL of this site into (home part, rest). The rest starts with '/', '?',
	 * '#' or is empty. Returns null for external URLs.
	 *
	 * @return array{0: string, 1: string}|null
	 */
	public static function split( string $url ): ?array {
		$home      = preg_replace( '#^https?:#i', '', self::raw_home() );
		$no_scheme = preg_replace( '#^https?:#i', '', $url );
		if ( '' === $home || 0 !== stripos( (string) $no_scheme, (string) $home ) ) {
			return null;
		}
		$rest = (string) substr( (string) $no_scheme, strlen( (string) $home ) );
		if ( '' !== $rest && ! in_array( $rest[0], array( '/', '?', '#' ), true ) ) {
			return null;
		}
		$scheme = substr( $url, 0, strlen( $url ) - strlen( (string) $no_scheme ) );
		return array( $scheme . substr( (string) $no_scheme, 0, strlen( (string) $home ) ), $rest );
	}

	/**
	 * Removes the language prefix of a URL.
	 *
	 * @return array{0: string, 1: string|null} (URL without prefix, language of the prefix)
	 */
	public static function strip_lang( string $url ): array {
		$parts = self::split( $url );
		if ( ! $parts ) {
			return array( $url, null );
		}
		list( $base, $rest ) = $parts;
		if ( preg_match( '#^/([^/?\#]+)(?=$|[/?\#])#', $rest, $m ) ) {
			$code = Languages::by_slug( $m[1] );
			if ( null !== $code ) {
				$rest = (string) substr( $rest, strlen( $m[0] ) );
				if ( '' === $rest ) {
					$rest = '/';
				}
				return array( $base . $rest, $code );
			}
		}
		return array( $url, null );
	}

	/**
	 * URL in a language: replaces the language prefix.
	 */
	public static function add_lang( string $url, string $lang ): string {
		list( $url ) = self::strip_lang( $url );
		$slug        = Languages::url_slug( $lang );
		if ( '' === $slug ) {
			return $url;
		}
		$parts = self::split( $url );
		if ( ! $parts ) {
			return $url;
		}
		list( $base, $rest ) = $parts;
		if ( '' === $rest ) {
			return $base . '/' . $slug;
		}
		if ( '?' === $rest[0] || '#' === $rest[0] ) {
			return $base . '/' . $slug . '/' . $rest;
		}
		return $base . '/' . $slug . $rest;
	}

	public static function language_home( string $lang ): string {
		return self::add_lang( self::raw_home() . '/', $lang );
	}

	/**
	 * URL of the current request (built on the configured host, not the Host header).
	 */
	public static function current_url(): string {
		$uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : '/'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		return self::origin() . '/' . ltrim( $uri, '/' );
	}

	public static function suspend(): void {
		++self::$suspended;
		Query::suspend();
	}

	public static function resume(): void {
		self::$suspended = max( 0, self::$suspended - 1 );
		Query::resume();
	}

	/* ---------------------------------------------------------------------
	 * Filters
	 * ------------------------------------------------------------------ */

	/**
	 * @param string      $url
	 * @param string      $path
	 * @param string|null $scheme
	 * @param int|null    $blog_id
	 */
	public static function home_url( $url, $path = '', $scheme = null, $blog_id = null ): string {
		$url = (string) $url;
		if ( self::$suspended || ! Router::filters_home() || 'rest' === $scheme ) {
			return $url;
		}
		if ( $blog_id && is_multisite() && (int) $blog_id !== get_current_blog_id() ) {
			return $url;
		}
		$lang = (string) Router::current();
		if ( '' === Languages::url_slug( $lang ) ) {
			return $url;
		}
		$path = ltrim( (string) $path, '/' );
		if ( '' !== $path && preg_match( '#^(\?rest_route|' . preg_quote( rest_get_url_prefix(), '#' ) . '|wp-(admin|content|includes|json|login|cron|signup|activate|sitemap)|xmlrpc\.php|robots\.txt|favicon\.ico)#', $path ) ) {
			return $url;
		}
		return self::add_lang( $url, $lang );
	}

	/**
	 * @param string           $url
	 * @param \WP_Post|int|null $post
	 */
	public static function post_link( $url, $post ): string {
		$post = get_post( $post );
		if ( ! $post || ! Content::is_translatable_post_type( $post->post_type ) ) {
			return (string) $url;
		}
		$lang = Translations::lang_or_default( 'post', $post->ID );
		if ( 'page' === $post->post_type && self::is_front_page_group( $post->ID ) ) {
			return self::language_home( $lang );
		}
		return self::add_lang( (string) $url, $lang );
	}

	/**
	 * @param string $url
	 * @param int    $post_id
	 */
	public static function page_link( $url, $post_id ): string {
		return self::post_link( $url, (int) $post_id );
	}

	/**
	 * @param string   $url
	 * @param \WP_Term $term
	 * @param string   $taxonomy
	 */
	public static function term_link( $url, $term, $taxonomy ): string {
		if ( ! $term instanceof \WP_Term || ! Content::is_translatable_taxonomy( (string) $taxonomy ) ) {
			return (string) $url;
		}
		return self::add_lang( (string) $url, Translations::lang_or_default( 'term', $term->term_id ) );
	}

	/**
	 * Whether a page is the static front page or one of its translations.
	 */
	public static function is_front_page_group( int $post_id ): bool {
		if ( 'page' !== get_option( 'show_on_front' ) ) {
			return false;
		}
		$front = (int) get_option( 'page_on_front' );
		if ( ! $front ) {
			return false;
		}
		if ( $front === $post_id ) {
			return true;
		}
		$group = Translations::group( 'post', $post_id );
		return $group > 0 && Translations::group( 'post', $front ) === $group;
	}

	/* ---------------------------------------------------------------------
	 * Internal link localization
	 * ------------------------------------------------------------------ */

	/**
	 * Points an internal URL to its translation in $lang when it exists.
	 * External URLs, files and URLs with an explicit language are returned as is.
	 */
	public static function localize_url( string $url, string $lang ): string {
		$url = trim( $url );
		if ( '' === $url || '#' === $url[0] || ! Languages::exists( $lang ) ) {
			return $url;
		}
		$relative = false;
		if ( '/' === $url[0] && ( ! isset( $url[1] ) || '/' !== $url[1] ) ) {
			$relative = true;
			$url      = self::origin() . $url;
		}
		$original = $relative ? (string) substr( $url, strlen( self::origin() ) ) : $url;
		$parts    = self::split( $url );
		if ( ! $parts ) {
			return $original;
		}
		$rest = $parts[1];
		if ( preg_match( '#^/(wp-(content|admin|includes|json|login)|xmlrpc|feed/)#', $rest )
			|| preg_match( '#\.(jpe?g|png|gif|webp|avif|svg|ico|pdf|zip|mp4|webm|mp3|wav|docx?|xlsx?|pptx?|csv|txt|xml|json|js|css)([?\#]|$)#i', $rest ) ) {
			return $original;
		}
		list( $clean, $url_lang ) = self::strip_lang( $url );
		if ( null !== $url_lang && ! Languages::is_default( $url_lang ) ) {
			return $original;
		}
		$suffix = '';
		$cut    = strcspn( $clean, '?#' );
		if ( $cut < strlen( $clean ) ) {
			$suffix = substr( $clean, $cut );
			$clean  = substr( $clean, 0, $cut );
		}
		$target = self::lookup( $clean, $lang );
		$result = '' === $target ? $url : $target . $suffix;
		if ( $relative && 0 === stripos( $result, self::origin() ) ) {
			return (string) substr( $result, strlen( self::origin() ) );
		}
		return $result;
	}

	/**
	 * Translated URL of an internal URL ('' when there is no published translation).
	 */
	private static function lookup( string $url, string $lang ): string {
		$key = $lang . '|' . $url;
		if ( isset( self::$localized[ $key ] ) ) {
			return self::$localized[ $key ];
		}
		$map  = self::map( $lang );
		$hash = md5( $url );
		if ( isset( $map[ $hash ] ) ) {
			return self::$localized[ $key ] = $map[ $hash ];
		}
		$target = '';
		$object = self::resolve_object( $url );
		if ( $object ) {
			if ( 'home' === $object[0] ) {
				$target = self::language_home( $lang );
			} elseif ( 'post' === $object[0] ) {
				$id = Translations::translation( 'post', $object[1], $lang );
				if ( $id && 'publish' === get_post_status( $id ) ) {
					$target = (string) get_permalink( $id );
				}
			} else {
				$id = Translations::translation( 'term', $object[1], $lang );
				if ( $id ) {
					$link   = get_term_link( $id );
					$target = is_wp_error( $link ) ? '' : (string) $link;
				}
			}
		}
		self::$localized[ $key ]     = $target;
		self::$map[ $lang ][ $hash ] = $target;
		self::$map_dirty[ $lang ]    = true;
		return $target;
	}

	/**
	 * Object behind an internal URL: array( 'post'|'term'|'home', id ) or null.
	 *
	 * @return array{0: string, 1: int}|null
	 */
	public static function resolve_object( string $url ): ?array {
		$parts = self::split( $url );
		if ( ! $parts ) {
			return null;
		}
		$path = trim( (string) wp_parse_url( 'http://x' . $parts[1], PHP_URL_PATH ), '/' );
		if ( '' === $path ) {
			return array( 'home', 0 );
		}
		self::suspend();
		try {
			$post_id = url_to_postid( $url );
			if ( $post_id ) {
				return array( 'post', (int) $post_id );
			}
			foreach ( Content::taxonomies() as $taxonomy ) {
				$tax = get_taxonomy( $taxonomy );
				if ( ! $tax || empty( $tax->rewrite['slug'] ) ) {
					continue;
				}
				$base = trim( (string) $tax->rewrite['slug'], '/' );
				if ( 0 === strpos( $path . '/', $base . '/' ) && $path !== $base ) {
					$term = get_term_by( 'slug', sanitize_title( basename( $path ) ), $taxonomy );
					if ( $term instanceof \WP_Term ) {
						return array( 'term', (int) $term->term_id );
					}
				}
			}
		} finally {
			self::resume();
		}
		return null;
	}

	/**
	 * Localizes the href of every link of an HTML fragment.
	 */
	public static function localize_html( string $html, string $lang ): string {
		if ( false === stripos( $html, 'href' ) ) {
			return $html;
		}
		return (string) preg_replace_callback(
			'~(<a\b[^>]*?\shref\s*=\s*)(["\'])(.*?)\2~is',
			static function ( $m ) use ( $lang ) {
				return $m[1] . $m[2] . self::localize_url( $m[3], $lang ) . $m[2];
			},
			$html
		);
	}

	/**
	 * Safety net at render time: links of translated content to pages translated
	 * after it was saved. Results are cached per language.
	 *
	 * @param string $content
	 */
	public static function localize_content( $content ) {
		$lang = Router::current();
		if ( ! is_string( $content ) || null === $lang || Router::is_neutral() || Languages::is_default( $lang ) ) {
			return $content;
		}
		$host = (string) preg_replace( '#^https?:#i', '', self::raw_home() );
		if ( false === stripos( $content, $host ) ) {
			return $content;
		}
		return self::localize_html( $content, $lang );
	}

	/**
	 * @return array<string, string>
	 */
	private static function map( string $lang ): array {
		if ( ! isset( self::$map[ $lang ] ) ) {
			$stored             = get_transient( 'gaiml_links_' . $lang . '_' . self::version() );
			self::$map[ $lang ] = is_array( $stored ) ? $stored : array();
		}
		return self::$map[ $lang ];
	}

	public static function save_maps(): void {
		if ( self::$bump ) {
			self::$bump = false;
			update_option( 'gaiml_links_version', (string) microtime( true ), true );
			return;
		}
		foreach ( array_keys( self::$map_dirty ) as $lang ) {
			$map = array_slice( self::$map[ $lang ] ?? array(), -1000, null, true );
			set_transient( 'gaiml_links_' . $lang . '_' . self::version(), $map, WEEK_IN_SECONDS );
		}
		self::$map_dirty = array();
	}

	private static function version(): string {
		return (string) get_option( 'gaiml_links_version', '1' );
	}

	/**
	 * Invalidates the link maps (a translation was created, deleted or renamed).
	 */
	public static function bump(): void {
		self::$bump      = true;
		self::$map       = array();
		self::$localized = array();
		self::$map_dirty = array();
	}

	/**
	 * @param int      $post_id
	 * @param \WP_Post $after
	 * @param \WP_Post $before
	 */
	public static function maybe_bump( $post_id, $after, $before ): void {
		if ( $after instanceof \WP_Post && $before instanceof \WP_Post && Content::is_translatable_post_type( $after->post_type )
			&& ( $after->post_name !== $before->post_name || $after->post_status !== $before->post_status ) ) {
			self::bump();
		}
	}
}
