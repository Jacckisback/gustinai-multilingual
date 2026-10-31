<?php
/**
 * Creates and updates translated posts and terms.
 *
 * A translation is a real post (or term) in its language, linked to the
 * original: page builders, SEO plugins, caches and search work natively, and
 * every translation has its own URL, slug, categories and SEO fields.
 *
 * Translations are always made from the original, never from another
 * translation. A translation edited by hand is not overwritten by automatic
 * updates.
 *
 * @package GustinAI\Multilingual
 */

namespace GustinAI\Multilingual\Translation;

use GustinAI\Multilingual\Core\Content;
use GustinAI\Multilingual\Core\Links;
use GustinAI\Multilingual\Core\Query;
use GustinAI\Multilingual\Core\Slugs;
use GustinAI\Multilingual\Core\Translations;
use GustinAI\Multilingual\Frontend\Strings;
use GustinAI\Multilingual\Languages;
use GustinAI\Multilingual\Settings;
use GustinAI\Multilingual\Translation\Providers\Provider;

defined( 'ABSPATH' ) || exit;

final class Engine {

	private static bool $saving = false;

	public static function is_saving(): bool {
		return self::$saving;
	}

	/**
	 * SEO fields translated with the content: meta key => kind.
	 *
	 * @return array<string, string>
	 */
	public static function meta_keys(): array {
		$keys = array(
			// Yoast SEO.
			'_yoast_wpseo_title'                 => 'title',
			'_yoast_wpseo_metadesc'              => 'description',
			'_yoast_wpseo_focuskw'               => 'label',
			'_yoast_wpseo_opengraph-title'       => 'title',
			'_yoast_wpseo_opengraph-description' => 'description',
			'_yoast_wpseo_twitter-title'         => 'title',
			'_yoast_wpseo_twitter-description'   => 'description',
			'_yoast_wpseo_bctitle'               => 'label',
			// Rank Math.
			'rank_math_title'                    => 'title',
			'rank_math_description'              => 'description',
			'rank_math_focus_keyword'            => 'label',
			'rank_math_facebook_title'           => 'title',
			'rank_math_facebook_description'     => 'description',
			'rank_math_twitter_title'            => 'title',
			'rank_math_twitter_description'      => 'description',
			'rank_math_breadcrumb_title'         => 'label',
			// SEOPress.
			'_seopress_titles_title'             => 'title',
			'_seopress_titles_desc'              => 'description',
			'_seopress_analysis_target_kw'       => 'label',
			'_seopress_social_fb_title'          => 'title',
			'_seopress_social_fb_desc'           => 'description',
			'_seopress_social_twitter_title'     => 'title',
			'_seopress_social_twitter_desc'      => 'description',
			// The SEO Framework.
			'_genesis_title'                     => 'title',
			'_genesis_description'               => 'description',
			'_open_graph_title'                  => 'title',
			'_open_graph_description'            => 'description',
			'_twitter_title'                     => 'title',
			'_twitter_description'               => 'description',
			// All in One SEO.
			'_aioseo_title'                      => 'title',
			'_aioseo_description'                => 'description',
		);
		/**
		 * Filters the post meta translated with the content (key => kind:
		 * text, label, title or description). Add your custom fields here.
		 */
		return (array) apply_filters( 'gaiml_translatable_meta', $keys );
	}

	/**
	 * Post meta never copied to translations.
	 *
	 * @return string[]
	 */
	private static function meta_skip(): array {
		return (array) apply_filters(
			'gaiml_meta_skip',
			array( '_edit_lock', '_edit_last', '_wp_old_slug', '_wp_old_date', '_wp_trash_meta_status', '_wp_trash_meta_time', '_wp_desired_post_slug', '_encloseme', '_pingme', '_elementor_data', '_elementor_css', '_elementor_element_cache', '_elementor_page_assets', '_elementor_controls_usage', '_yoast_wpseo_canonical', '_yoast_wpseo_content_score', '_yoast_wpseo_linkdex', '_yoast_wpseo_estimated-reading-time-minutes', '_yoast_indexnow_last_ping', '_yoast_wpseo_primary_category', 'rank_math_primary_category', 'rank_math_canonical_url', 'rank_math_seo_score', 'rank_math_internal_links_processed', '_seopress_robots_canonical', '_genesis_canonical_uri' )
		);
	}

	/**
	 * Original of a translation group (translations are made from it).
	 */
	public static function source_of( string $type, int $id ): int {
		$group = Translations::group( $type, $id );
		if ( $group > 0 && $group !== $id ) {
			$members = Translations::members( $type, $id );
			if ( in_array( $group, $members, true ) ) {
				return $group;
			}
			$default = $members[ Languages::default_code() ] ?? 0;
			if ( $default ) {
				return $default;
			}
		}
		return $id;
	}

	/* ---------------------------------------------------------------------
	 * Job steps
	 * ------------------------------------------------------------------ */

	/**
	 * One step of a translation job: at most one API request, then the
	 * translation is saved once every text is translated.
	 *
	 * @param array<string, mixed> $state Persistent job state.
	 * @return array{status: string, message: string, target?: int, retry_after?: int}
	 */
	public static function step( string $type, int $id, string $lang, bool $force, array &$state ): array {
		if ( 'strings' === $type ) {
			return self::step_strings( $lang, $state );
		}
		$plan = 'term' === $type ? self::plan_term( $id, $lang ) : self::plan_post( $id, $lang, $force );
		if ( is_wp_error( $plan ) ) {
			return array(
				'status'  => 'failed',
				'message' => $plan->get_error_message(),
			);
		}
		if ( ! empty( $plan['skip'] ) ) {
			return array(
				'status'  => 'done',
				'message' => (string) $plan['skip'],
				'target'  => (int) ( $plan['target'] ?? 0 ),
			);
		}
		$progress = Translator::step( $plan['bag'], $plan['source_lang'], $lang, $state );
		if ( $progress['error'] instanceof \WP_Error ) {
			$data = $progress['error']->get_error_data();
			return array(
				'status'      => Provider::is_fatal( $progress['error'] ) ? 'failed' : 'retry',
				'message'     => $progress['error']->get_error_message(),
				'retry_after' => is_array( $data ) ? (int) ( $data['retry_after'] ?? 0 ) : 0,
			);
		}
		if ( ! $progress['done'] ) {
			return array(
				'status'  => 'pending',
				/* translators: %d: number of texts. */
				'message' => sprintf( _n( '%d text left', '%d texts left', $progress['missing'], 'gustinai-multilingual' ), $progress['missing'] ),
			);
		}
		$tr     = Translator::translations( $plan['bag'], $plan['source_lang'], $lang );
		$target = 'term' === $type ? self::save_term( $plan, $tr ) : self::save_post( $plan, $tr );
		if ( is_wp_error( $target ) ) {
			return array(
				'status'  => 'failed',
				'message' => $target->get_error_message(),
			);
		}
		$kept = $plan['bag']->count() - count( $tr );
		return array(
			'status'  => 'done',
			'target'  => $target,
			/* translators: %d: number of texts. */
			'message' => $kept > 0 ? sprintf( _n( '%d text kept in the original language', '%d texts kept in the original language', $kept, 'gustinai-multilingual' ), $kept ) : '',
		);
	}

	/**
	 * @param array<string, mixed> $state
	 * @return array{status: string, message: string}
	 */
	private static function step_strings( string $lang, array &$state ): array {
		$bag = new Bag();
		foreach ( Strings::registered() as $text ) {
			Html::value( $text, $bag, array(), 'label' );
		}
		$progress = Translator::step( $bag, Languages::default_code(), $lang, $state );
		if ( $progress['error'] instanceof \WP_Error ) {
			return array(
				'status'  => Provider::is_fatal( $progress['error'] ) ? 'failed' : 'retry',
				'message' => $progress['error']->get_error_message(),
			);
		}
		return array(
			'status'  => $progress['done'] ? 'done' : 'pending',
			'message' => '',
		);
	}

	/**
	 * Options of the extractors for a target language.
	 *
	 * @param int[] $attachments Collects the images found in builder data.
	 * @return array<string, mixed>
	 */
	private static function opts( string $lang, array &$attachments ): array {
		$opts = array(
			'attachment' => static function ( int $id ) use ( &$attachments ): void {
				$attachments[] = $id;
			},
		);
		if ( Settings::get( 'link_localization' ) ) {
			$opts['link'] = static fn( $url ) => Links::localize_url( (string) $url, $lang );
		}
		return $opts;
	}

	/* ---------------------------------------------------------------------
	 * Posts
	 * ------------------------------------------------------------------ */

	/**
	 * @return array<string, mixed>|\WP_Error
	 */
	private static function plan_post( int $id, string $lang, bool $force ) {
		$post = get_post( $id );
		if ( ! $post instanceof \WP_Post || ! Content::is_translatable_post( $post ) || in_array( $post->post_status, array( 'trash', 'auto-draft', 'inherit' ), true ) ) {
			return new \WP_Error( 'gaiml_source', __( 'The original content is not available.', 'gustinai-multilingual' ) );
		}
		if ( ! Languages::exists( $lang ) ) {
			return new \WP_Error( 'gaiml_lang', __( 'This language is not active.', 'gustinai-multilingual' ) );
		}
		$source_lang = Translations::lang( 'post', $id );
		if ( null === $source_lang ) {
			$source_lang = Languages::default_code();
			Translations::set( 'post', $id, $source_lang );
		}
		if ( $source_lang === $lang ) {
			return new \WP_Error( 'gaiml_lang', __( 'The content is already in this language.', 'gustinai-multilingual' ) );
		}
		$target = self::target( $id, $lang );
		if ( $target && ! $force && self::is_edited( $target ) ) {
			return array(
				'skip'   => __( 'Translation edited by hand: kept as is.', 'gustinai-multilingual' ),
				'target' => $target,
			);
		}

		$attachments = array();
		$bag         = new Bag();
		$opts        = self::opts( $lang, $attachments );
		$plan        = array(
			'post'        => $post,
			'lang'        => $lang,
			'source_lang' => $source_lang,
			'bag'         => $bag,
			'fields'      => array(),
			'meta'        => array(),
			'terms'       => array(),
			'term_plans'  => array(),
			'attachments' => array(),
		);

		$plan['fields']['post_title'] = Html::extract( $post->post_title, $bag, array( 'kind' => 'title' ) );
		if ( '' !== trim( $post->post_excerpt ) ) {
			$plan['fields']['post_excerpt'] = Html::extract( $post->post_excerpt, $bag, $opts );
		}
		$plan['fields']['post_content'] = Html::extract( $post->post_content, $bag, $opts );

		$target_slug = $target ? (string) get_post_field( 'post_name', $target ) : '';
		if ( Settings::get( 'translate_slugs' ) && '' !== $post->post_name && '' === $target_slug ) {
			$plan['slug'] = $bag->add( urldecode( $post->post_name ), 'slug' );
		}

		if ( 'builder' === get_post_meta( $id, '_elementor_edit_mode', true ) ) {
			$raw  = get_post_meta( $id, '_elementor_data', true );
			$data = is_string( $raw ) ? json_decode( $raw, true ) : ( is_array( $raw ) ? $raw : null );
			if ( is_array( $data ) ) {
				$plan['elementor'] = Elementor::extract( $data, $bag, $opts );
			}
		}

		foreach ( self::meta_keys() as $key => $kind ) {
			$value = get_post_meta( $id, (string) $key, true );
			if ( is_string( $value ) && Placeholders::has_words( $value ) ) {
				$plan['meta'][ (string) $key ] = Html::value( $value, $bag, array(), (string) $kind );
			}
		}

		$thumbnail = (int) get_post_thumbnail_id( $post );
		if ( $thumbnail ) {
			$attachments[] = $thumbnail;
		}
		foreach ( array_unique( $attachments ) as $attachment ) {
			$alt = get_post_meta( (int) $attachment, '_wp_attachment_image_alt', true );
			if ( is_string( $alt ) && Placeholders::has_words( $alt ) ) {
				$plan['attachments'][ (int) $attachment ] = $bag->add( trim( Placeholders::decode( $alt ) ), 'label' );
			}
		}

		Query::suspend();
		try {
			foreach ( get_object_taxonomies( $post->post_type ) as $taxonomy ) {
				$ids = wp_get_object_terms( $id, $taxonomy, array( 'fields' => 'ids' ) );
				if ( is_wp_error( $ids ) || ! $ids ) {
					continue;
				}
				$plan['terms'][ $taxonomy ] = array_map( 'intval', $ids );
				if ( Content::is_translatable_taxonomy( $taxonomy ) ) {
					foreach ( $ids as $term_id ) {
						self::plan_term_items( (int) $term_id, $lang, $bag, $plan );
					}
				}
			}
		} finally {
			Query::resume();
		}

		$plan['source_hash'] = self::source_hash( $id );
		return $plan;
	}

	/**
	 * @param array<string, mixed>  $plan
	 * @param array<string, string> $tr
	 * @return int|\WP_Error
	 */
	private static function save_post( array $plan, array $tr ) {
		$post      = $plan['post'];
		$lang      = $plan['lang'];
		$source_id = (int) $post->ID;
		$group     = Translations::group( 'post', $source_id );
		$group     = $group > 0 ? $group : $source_id;
		$target    = self::target( $source_id, $lang );
		$stale     = Translations::translation( 'post', $source_id, $lang );
		$title     = (string) $plan['fields']['post_title']( $tr );
		if ( $stale && $stale !== $target ) {
			// Trashed or abandoned translation: it leaves the group, a new one replaces it.
			Translations::unlink( 'post', $stale );
		}

		$data = array(
			'post_title'   => $title,
			'post_content' => (string) $plan['fields']['post_content']( $tr ),
			'post_excerpt' => isset( $plan['fields']['post_excerpt'] ) ? (string) $plan['fields']['post_excerpt']( $tr ) : '',
		);
		$parent = $post->post_parent ? self::target( (int) $post->post_parent, $lang ) : 0;
		if ( $target ) {
			$data['ID'] = $target;
			if ( $parent ) {
				$data['post_parent'] = $parent;
			}
			if ( isset( $plan['slug'], $tr[ $plan['slug'] ] ) ) {
				$data['post_name'] = $tr[ $plan['slug'] ];
			}
		} else {
			if ( isset( $plan['slug'], $tr[ $plan['slug'] ] ) ) {
				$slug = $tr[ $plan['slug'] ];
			} elseif ( ! Settings::get( 'translate_slugs' ) && '' !== $post->post_name ) {
				// Slugs are not translated: same slug in every language (unique per language).
				$slug = $post->post_name;
			} else {
				$slug = sanitize_title( wp_strip_all_tags( $title ) );
			}
			$status = ( 'draft' === Settings::get( 'new_status' ) && 'publish' === $post->post_status ) ? 'draft' : $post->post_status;
			$data  += array(
				'post_type'      => $post->post_type,
				'post_status'    => $status,
				'post_author'    => (int) $post->post_author,
				'post_name'      => $slug,
				'post_parent'    => $parent,
				'menu_order'     => (int) $post->menu_order,
				'comment_status' => $post->comment_status,
				'ping_status'    => $post->ping_status,
				'post_password'  => $post->post_password,
				'post_date'      => $post->post_date,
				'post_date_gmt'  => $post->post_date_gmt,
			);
		}

		self::$saving = true;
		$kses         = self::disable_kses();
		try {
			$result = Content::with_language(
				$lang,
				$group,
				static fn() => $target ? wp_update_post( wp_slash( $data ), true ) : wp_insert_post( wp_slash( $data ), true )
			);
			if ( is_wp_error( $result ) ) {
				return $result;
			}
			$target_id = (int) $result;
			if ( ! Translations::get( 'post', $source_id ) ) {
				Translations::set( 'post', $source_id, $plan['source_lang'] );
			}
			if ( ! Translations::set( 'post', $target_id, $lang, $group ) ) {
				if ( ! $target ) {
					// Another request created this translation at the same time: no duplicate.
					wp_delete_post( $target_id, true );
				}
				return new \WP_Error( 'gaiml_conflict', __( 'Another translation into this language was created at the same time. Try again.', 'gustinai-multilingual' ) );
			}
			// Provisional hashes: if the request dies before the end, the translation
			// is seen as outdated (redone later), not as edited by hand.
			update_post_meta( $target_id, '_gaiml_source_hash', 'pending' );
			update_post_meta( $target_id, '_gaiml_output_hash', self::output_hash( $target_id ) );
			self::copy_terms( $target_id, $plan, $tr );
			self::copy_meta( $source_id, $target_id, $plan, $tr );
			foreach ( $plan['attachments'] as $attachment => $id ) {
				if ( isset( $tr[ $id ] ) ) {
					update_post_meta( (int) $attachment, '_gaiml_alt_' . $lang, Placeholders::plain( $tr[ $id ] ) );
				}
			}
			self::fix_children( $post, $target_id, $lang );
			update_post_meta( $target_id, '_gaiml_source_hash', $plan['source_hash'] );
			update_post_meta( $target_id, '_gaiml_translated_at', time() );
			clean_post_cache( $target_id );
			update_post_meta( $target_id, '_gaiml_output_hash', self::output_hash( $target_id ) );
		} finally {
			self::restore_kses( $kses );
			self::$saving = false;
		}
		/**
		 * Fires after a post translation is created or updated.
		 */
		do_action( 'gaiml_post_translated', $target_id, $source_id, $lang );
		return $target_id;
	}

	/**
	 * @param array<string, mixed>  $plan
	 * @param array<string, string> $tr
	 */
	private static function copy_meta( int $source, int $target, array $plan, array $tr ): void {
		$skip = self::meta_skip();
		foreach ( array_keys( (array) get_post_meta( $source ) ) as $key ) {
			$key = (string) $key;
			if ( isset( $plan['meta'][ $key ] ) || in_array( $key, $skip, true ) || 0 === strpos( $key, '_gaiml_' ) || 0 === strpos( $key, '_oembed_' ) ) {
				continue;
			}
			delete_post_meta( $target, $key );
			foreach ( get_post_meta( $source, $key, false ) as $value ) {
				add_post_meta( $target, $key, wp_slash( $value ) );
			}
		}
		foreach ( $plan['meta'] as $key => $render ) {
			update_post_meta( $target, $key, wp_slash( (string) $render( $tr ) ) );
		}
		foreach ( array( '_yoast_wpseo_primary_category', 'rank_math_primary_category' ) as $key ) {
			$term = (int) get_post_meta( $source, $key, true );
			if ( $term ) {
				$mapped = Translations::translation( 'term', $term, $plan['lang'] );
				if ( $mapped ) {
					update_post_meta( $target, $key, $mapped );
				}
			}
		}
		if ( isset( $plan['elementor'] ) ) {
			update_post_meta( $target, '_elementor_data', wp_slash( (string) wp_json_encode( $plan['elementor']( $tr ) ) ) );
			foreach ( array( '_elementor_css', '_elementor_element_cache', '_elementor_page_assets' ) as $key ) {
				delete_post_meta( $target, $key );
			}
		}
	}

	/**
	 * @param array<string, mixed>  $plan
	 * @param array<string, string> $tr
	 */
	private static function copy_terms( int $target, array $plan, array $tr ): void {
		foreach ( $plan['terms'] as $taxonomy => $ids ) {
			if ( ! Content::is_translatable_taxonomy( $taxonomy ) ) {
				wp_set_object_terms( $target, $ids, $taxonomy );
				continue;
			}
			$mapped = array();
			foreach ( $ids as $term_id ) {
				$new = self::ensure_term( (int) $term_id, $plan, $tr );
				if ( $new ) {
					$mapped[] = $new;
				}
			}
			wp_set_object_terms( $target, $mapped, $taxonomy );
		}
	}

	/**
	 * Children translated before their parent are attached to the new parent.
	 */
	private static function fix_children( \WP_Post $post, int $target_id, string $lang ): void {
		global $wpdb;
		if ( ! is_post_type_hierarchical( $post->post_type ) ) {
			return;
		}
		$children = $wpdb->get_col( // phpcs:ignore WordPress.DB
			$wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_parent = %d AND post_type = %s AND post_status NOT IN ('trash','auto-draft','inherit')", $post->ID, $post->post_type )
		);
		foreach ( $children as $child ) {
			$translation = Translations::translation( 'post', (int) $child, $lang );
			if ( $translation && (int) get_post_field( 'post_parent', $translation ) !== $target_id ) {
				wp_update_post(
					array(
						'ID'          => $translation,
						'post_parent' => $target_id,
					)
				);
			}
		}
	}

	/* ---------------------------------------------------------------------
	 * Terms
	 * ------------------------------------------------------------------ */

	/**
	 * Adds a term (and its untranslated ancestors) to a plan.
	 *
	 * @param array<string, mixed> $plan
	 */
	private static function plan_term_items( int $term_id, string $lang, Bag $bag, array &$plan ): void {
		if ( isset( $plan['term_plans'][ $term_id ] ) || Translations::translation( 'term', $term_id, $lang ) ) {
			return;
		}
		$term = get_term( $term_id );
		if ( ! $term instanceof \WP_Term ) {
			return;
		}
		$item = array(
			'term' => $term,
			'name' => Html::value( $term->name, $bag, array(), 'label' ),
			'meta' => self::term_meta_plan( $term, $bag ),
		);
		if ( Settings::get( 'translate_slugs' ) ) {
			$item['slug'] = $bag->add( urldecode( $term->slug ), 'slug' );
		}
		if ( '' !== trim( $term->description ) ) {
			$item['description'] = Html::extract( $term->description, $bag );
		}
		$plan['term_plans'][ $term_id ] = $item;
		if ( $term->parent ) {
			self::plan_term_items( (int) $term->parent, $lang, $bag, $plan );
		}
	}

	/**
	 * SEO fields of a term (Rank Math term meta, Yoast taxonomy option).
	 *
	 * @return array<string, \Closure>
	 */
	private static function term_meta_plan( \WP_Term $term, Bag $bag ): array {
		$plan = array();
		foreach ( array(
			'rank_math_title'         => 'title',
			'rank_math_description'   => 'description',
			'rank_math_focus_keyword' => 'label',
		) as $key => $kind ) {
			$value = get_term_meta( $term->term_id, $key, true );
			if ( is_string( $value ) && Placeholders::has_words( $value ) ) {
				$plan[ $key ] = Html::value( $value, $bag, array(), $kind );
			}
		}
		$yoast = get_option( 'wpseo_taxonomy_meta' );
		$row   = is_array( $yoast ) ? ( $yoast[ $term->taxonomy ][ $term->term_id ] ?? array() ) : array();
		foreach ( array(
			'wpseo_title'                => 'title',
			'wpseo_desc'                 => 'description',
			'wpseo_focuskw'              => 'label',
			'wpseo_opengraph-title'      => 'title',
			'wpseo_opengraph-description' => 'description',
			'wpseo_twitter-title'        => 'title',
			'wpseo_twitter-description'  => 'description',
			'wpseo_bctitle'              => 'label',
		) as $key => $kind ) {
			if ( isset( $row[ $key ] ) && is_string( $row[ $key ] ) && Placeholders::has_words( $row[ $key ] ) ) {
				$plan[ 'yoast:' . $key ] = Html::value( $row[ $key ], $bag, array(), $kind );
			}
		}
		return $plan;
	}

	/**
	 * Translation of a term, created from the plan when it does not exist yet.
	 *
	 * @param array<string, mixed>  $plan
	 * @param array<string, string> $tr
	 */
	private static function ensure_term( int $term_id, array $plan, array $tr ): int {
		$lang     = $plan['lang'];
		$existing = Translations::translation( 'term', $term_id, $lang );
		if ( $existing ) {
			return $existing;
		}
		$item = $plan['term_plans'][ $term_id ] ?? null;
		if ( ! $item ) {
			return 0;
		}
		$term   = $item['term'];
		$parent = $term->parent ? self::ensure_term( (int) $term->parent, $plan, $tr ) : 0;
		$name   = trim( (string) $item['name']( $tr ) );
		if ( '' === $name ) {
			return 0;
		}
		if ( isset( $item['slug'], $tr[ $item['slug'] ] ) ) {
			$slug = $tr[ $item['slug'] ];
		} else {
			$slug = Settings::get( 'translate_slugs' ) ? sanitize_title( $name ) : $term->slug;
		}
		$slug = Slugs::term_slug( $slug, $term->taxonomy, $lang );
		$group = Translations::group( 'term', $term_id );
		if ( ! $group ) {
			Translations::set( 'term', $term_id, $plan['source_lang'] );
			$group = $term_id;
		}
		$args = array(
			'slug'        => $slug,
			'parent'      => $parent,
			'description' => isset( $item['description'] ) ? (string) $item['description']( $tr ) : '',
		);
		$kses = self::disable_kses();
		try {
			$result = Content::with_language( $lang, $group, static fn() => wp_insert_term( $name, $term->taxonomy, $args ) );
		} finally {
			self::restore_kses( $kses );
		}
		if ( is_wp_error( $result ) ) {
			$other = (int) $result->get_error_data( 'term_exists' );
			if ( $other && Translations::lang( 'term', $other ) === $lang && count( Translations::members( 'term', $other ) ) <= 1 ) {
				Translations::set( 'term', $other, $lang, $group );
				return $other;
			}
			return 0;
		}
		$new_id = (int) $result['term_id'];
		Translations::set( 'term', $new_id, $lang, $group );
		self::copy_term_meta( $term, $new_id, $item, $tr );
		return $new_id;
	}

	/**
	 * @param array<string, mixed>  $item
	 * @param array<string, string> $tr
	 */
	private static function copy_term_meta( \WP_Term $source, int $target, array $item, array $tr ): void {
		foreach ( array_keys( (array) get_term_meta( $source->term_id ) ) as $key ) {
			$key = (string) $key;
			if ( 0 === strpos( $key, '_gaiml_' ) || isset( $item['meta'][ $key ] ) ) {
				continue;
			}
			delete_term_meta( $target, $key );
			foreach ( get_term_meta( $source->term_id, $key, false ) as $value ) {
				add_term_meta( $target, $key, wp_slash( $value ) );
			}
		}
		$yoast = array();
		foreach ( $item['meta'] as $key => $render ) {
			if ( 0 === strpos( (string) $key, 'yoast:' ) ) {
				$yoast[ substr( (string) $key, 6 ) ] = (string) $render( $tr );
			} else {
				update_term_meta( $target, (string) $key, wp_slash( (string) $render( $tr ) ) );
			}
		}
		$option = get_option( 'wpseo_taxonomy_meta' );
		if ( is_array( $option ) && isset( $option[ $source->taxonomy ][ $source->term_id ] ) ) {
			$row = array_merge( (array) $option[ $source->taxonomy ][ $source->term_id ], $yoast );
			unset( $row['wpseo_canonical'] );
			$option[ $source->taxonomy ][ $target ] = $row;
			update_option( 'wpseo_taxonomy_meta', $option );
		}
	}

	/**
	 * @return array<string, mixed>|\WP_Error
	 */
	private static function plan_term( int $term_id, string $lang ) {
		$term = get_term( $term_id );
		if ( ! $term instanceof \WP_Term || ! Content::is_translatable_taxonomy( $term->taxonomy ) ) {
			return new \WP_Error( 'gaiml_source', __( 'The original term is not available.', 'gustinai-multilingual' ) );
		}
		$source_lang = Translations::lang( 'term', $term_id );
		if ( null === $source_lang ) {
			$source_lang = Languages::default_code();
			Translations::set( 'term', $term_id, $source_lang );
		}
		if ( $source_lang === $lang || ! Languages::exists( $lang ) ) {
			return new \WP_Error( 'gaiml_lang', __( 'The term is already in this language.', 'gustinai-multilingual' ) );
		}
		$bag  = new Bag();
		$plan = array(
			'term_id'     => $term_id,
			'lang'        => $lang,
			'source_lang' => $source_lang,
			'bag'         => $bag,
			'term_plans'  => array(),
			'existing'    => Translations::translation( 'term', $term_id, $lang ),
		);
		if ( $plan['existing'] ) {
			$plan['update'] = array(
				'term' => $term,
				'name' => Html::value( $term->name, $bag, array(), 'label' ),
				'meta' => self::term_meta_plan( $term, $bag ),
			);
			if ( '' !== trim( $term->description ) ) {
				$plan['update']['description'] = Html::extract( $term->description, $bag );
			}
		} else {
			self::plan_term_items( $term_id, $lang, $bag, $plan );
		}
		return $plan;
	}

	/**
	 * @param array<string, mixed>  $plan
	 * @param array<string, string> $tr
	 * @return int|\WP_Error
	 */
	private static function save_term( array $plan, array $tr ) {
		if ( ! empty( $plan['existing'] ) ) {
			$item   = $plan['update'];
			$target = (int) $plan['existing'];
			$target_term = get_term( $target );
			if ( ! $target_term instanceof \WP_Term ) {
				return new \WP_Error( 'gaiml_term', __( 'The translated term is not available.', 'gustinai-multilingual' ) );
			}
			$kses = self::disable_kses();
			try {
				$result = wp_update_term(
					$target,
					$target_term->taxonomy,
					array(
						'name'        => (string) $item['name']( $tr ),
						'description' => isset( $item['description'] ) ? (string) $item['description']( $tr ) : $target_term->description,
					)
				);
			} finally {
				self::restore_kses( $kses );
			}
			if ( is_wp_error( $result ) ) {
				return $result;
			}
			self::copy_term_meta( $item['term'], $target, $item, $tr );
			return $target;
		}
		$plan['lang'] = (string) $plan['lang'];
		$id           = self::ensure_term( (int) $plan['term_id'], $plan, $tr );
		return $id ? $id : new \WP_Error( 'gaiml_term', __( 'The translated term could not be created.', 'gustinai-multilingual' ) );
	}

	/* ---------------------------------------------------------------------
	 * Status
	 * ------------------------------------------------------------------ */

	public static function source_hash( int $id ): string {
		$post = get_post( $id );
		if ( ! $post ) {
			return '';
		}
		$parts = array( $post->post_title, $post->post_content, $post->post_excerpt, $post->post_name, self::meta_string( $id, '_elementor_data' ) );
		foreach ( array_keys( self::meta_keys() ) as $key ) {
			$parts[] = self::meta_string( $id, (string) $key );
		}
		return md5( implode( "\x1f", $parts ) );
	}

	public static function output_hash( int $id ): string {
		$post = get_post( $id );
		if ( ! $post ) {
			return '';
		}
		return md5( implode( "\x1f", array( $post->post_title, $post->post_content, $post->post_excerpt, self::meta_string( $id, '_elementor_data' ) ) ) );
	}

	private static function meta_string( int $id, string $key ): string {
		$value = get_post_meta( $id, $key, true );
		return is_scalar( $value ) ? (string) $value : (string) wp_json_encode( $value );
	}

	/**
	 * A translation changed by hand since it was generated (or written by hand).
	 */
	public static function is_edited( int $target ): bool {
		if ( 'pending' === get_post_meta( $target, '_gaiml_source_hash', true ) ) {
			// Save interrupted: the content is still the engine's.
			return false;
		}
		$stored = (string) get_post_meta( $target, '_gaiml_output_hash', true );
		return '' === $stored || $stored !== self::output_hash( $target );
	}

	/**
	 * Translation of a post, ignoring trashed and abandoned (auto-draft) posts:
	 * those are replaced by a new translation.
	 */
	public static function target( int $source_id, string $lang ): int {
		$target = Translations::translation( 'post', $source_id, $lang );
		if ( ! $target || $target === $source_id ) {
			return $target;
		}
		$status = get_post_status( $target );
		return ( false === $status || 'trash' === $status || 'auto-draft' === $status ) ? 0 : $target;
	}

	/**
	 * Status of a translation of an original post:
	 * missing, queued, failed, translated, outdated, edited, manual or source.
	 */
	public static function status( int $source_id, string $lang ): string {
		$job = Queue::job( 'post', $source_id, $lang );
		if ( $job ) {
			return 'failed' === $job->status ? 'failed' : 'queued';
		}
		$target = self::target( $source_id, $lang );
		if ( ! $target ) {
			return 'missing';
		}
		if ( $target === $source_id ) {
			return 'source';
		}
		$hash = (string) get_post_meta( $target, '_gaiml_source_hash', true );
		if ( '' === $hash ) {
			return 'manual';
		}
		if ( $hash !== self::source_hash( $source_id ) ) {
			return 'outdated';
		}
		return self::is_edited( $target ) ? 'edited' : 'translated';
	}

	/* ---------------------------------------------------------------------
	 * Helpers
	 * ------------------------------------------------------------------ */

	/**
	 * Translations are saved without kses: the markup comes from the original
	 * (already accepted), and the model can only change text (see Placeholders).
	 */
	private static function disable_kses(): bool {
		$active = false !== has_filter( 'content_save_pre', 'wp_filter_post_kses' );
		if ( $active ) {
			kses_remove_filters();
		}
		return $active;
	}

	private static function restore_kses( bool $active ): void {
		if ( $active ) {
			kses_init_filters();
		}
	}

	/**
	 * Characters to translate for an original post in one language (estimates).
	 */
	public static function chars( int $id, string $lang ): int {
		$plan = self::plan_post( $id, $lang, true );
		if ( is_wp_error( $plan ) || ! isset( $plan['bag'] ) ) {
			return 0;
		}
		$found = Translator::translations( $plan['bag'], $plan['source_lang'], $lang );
		$total = 0;
		foreach ( $plan['bag']->items() as $item_id => $item ) {
			if ( ! isset( $found[ $item_id ] ) ) {
				$total += strlen( $item['text'] );
			}
		}
		return $total;
	}
}
