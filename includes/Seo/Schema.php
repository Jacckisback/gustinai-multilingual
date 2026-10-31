<?php
/**
 * Structured data for search and AI answer engines (GEO).
 *
 * The WebPage node gets its language (inLanguage) and the links between
 * translations (workTranslation on the original, translationOfWork on the
 * translations). Yoast SEO and Rank Math graphs are extended; without an SEO
 * plugin, a minimal WebPage graph is printed.
 *
 * @package GustinAI\Multilingual
 */

namespace GustinAI\Multilingual\Seo;

use GustinAI\Multilingual\Core\Content;
use GustinAI\Multilingual\Core\Links;
use GustinAI\Multilingual\Core\Router;
use GustinAI\Multilingual\Core\Translations;
use GustinAI\Multilingual\Languages;
use GustinAI\Multilingual\Settings;
use GustinAI\Multilingual\Translation\Engine;

defined( 'ABSPATH' ) || exit;

final class Schema {

	public static function init(): void {
		if ( ! Settings::get( 'schema' ) ) {
			return;
		}
		add_filter( 'wpseo_schema_webpage', array( self::class, 'yoast' ) );
		add_filter( 'rank_math/json_ld', array( self::class, 'rank_math' ), 99 );
		add_action( 'wp_head', array( self::class, 'fallback' ), 20 );
	}

	public static function seo_plugin_active(): bool {
		return defined( 'WPSEO_VERSION' ) || class_exists( '\RankMath' ) || defined( 'SEOPRESS_VERSION' ) || defined( 'AIOSEO_VERSION' ) || defined( 'THE_SEO_FRAMEWORK_VERSION' );
	}

	/**
	 * Translation links of the current singular page.
	 *
	 * @return array<string, mixed>
	 */
	public static function properties(): array {
		$lang = Router::current();
		if ( null === $lang || ! is_singular() || ! Languages::is_multilingual() ) {
			return array();
		}
		$id   = (int) get_queried_object_id();
		$post = get_post( $id );
		if ( ! $post || ! Content::is_translatable_post_type( $post->post_type ) ) {
			return array();
		}
		$props   = array( 'inLanguage' => Languages::html_lang( Translations::lang_or_default( 'post', $id ) ) );
		$source  = Engine::source_of( 'post', $id );
		$members = Translations::members( 'post', $id );
		if ( $source !== $id && 'publish' === get_post_status( $source ) ) {
			$props['translationOfWork'] = array( '@id' => (string) get_permalink( $source ) );
		} else {
			$translations = array();
			foreach ( $members as $member ) {
				if ( $member !== $id && 'publish' === get_post_status( $member ) ) {
					$translations[] = array( '@id' => (string) get_permalink( $member ) );
				}
			}
			if ( $translations ) {
				$props['workTranslation'] = $translations;
			}
		}
		return $props;
	}

	/**
	 * @param array<string, mixed> $data Yoast WebPage piece.
	 * @return array<string, mixed>
	 */
	public static function yoast( $data ) {
		return is_array( $data ) ? array_merge( $data, self::properties() ) : $data;
	}

	/**
	 * @param array<string, mixed> $data Rank Math graph.
	 * @return array<string, mixed>
	 */
	public static function rank_math( $data ) {
		if ( ! is_array( $data ) ) {
			return $data;
		}
		$props = self::properties();
		if ( ! $props ) {
			return $data;
		}
		foreach ( $data as $key => $entity ) {
			$types = is_array( $entity ) ? (array) ( $entity['@type'] ?? array() ) : array();
			if ( in_array( 'WebPage', $types, true ) || 'WebPage' === $key ) {
				$data[ $key ] = array_merge( $entity, $props );
			}
		}
		return $data;
	}

	/**
	 * Minimal graph when no SEO plugin prints one.
	 */
	public static function fallback(): void {
		if ( self::seo_plugin_active() || ! is_singular() || post_password_required() ) {
			return;
		}
		$props = self::properties();
		if ( ! $props ) {
			return;
		}
		$id       = (int) get_queried_object_id();
		$url      = (string) get_permalink( $id );
		$lang     = (string) $props['inLanguage'];
		$home     = Links::language_home( Translations::lang_or_default( 'post', $id ) );
		$excerpt  = wp_strip_all_tags( (string) get_the_excerpt( $id ) );
		$webpage  = array_merge(
			array(
				'@type'    => 'WebPage',
				'@id'      => $url,
				'url'      => $url,
				'name'     => wp_strip_all_tags( get_the_title( $id ) ),
				'isPartOf' => array( '@id' => $home . '#website' ),
			),
			'' !== $excerpt ? array( 'description' => $excerpt ) : array(),
			$props
		);
		$website  = array(
			'@type'      => 'WebSite',
			'@id'        => $home . '#website',
			'url'        => $home,
			'name'       => get_bloginfo( 'name' ),
			'inLanguage' => $lang,
		);
		$graph    = array(
			'@context' => 'https://schema.org',
			'@graph'   => array( $website, $webpage ),
		);
		echo '<script type="application/ld+json" class="gaiml-schema">' . wp_json_encode( $graph, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG ) . "</script>\n";
	}
}
