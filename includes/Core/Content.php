<?php
/**
 * Translatable content types and content lifecycle: language assignment of new
 * posts and terms, cleanup on deletion, automatic update of translations.
 *
 * @package GustinAI\Multilingual
 */

namespace GustinAI\Multilingual\Core;

use GustinAI\Multilingual\Install;
use GustinAI\Multilingual\Languages;
use GustinAI\Multilingual\Settings;
use GustinAI\Multilingual\Translation\Engine;
use GustinAI\Multilingual\Translation\Queue;

defined( 'ABSPATH' ) || exit;

final class Content {

	/**
	 * Language (and group) forced for the objects created by the translation engine.
	 *
	 * @var array{lang: string, group: int}|null
	 */
	private static ?array $pending = null;

	/** @var array<int, true> Originals edited during this request. */
	private static array $dirty = array();

	public static function init(): void {
		add_action( 'save_post', array( self::class, 'on_save_post' ), 5, 3 );
		add_action( 'created_term', array( self::class, 'on_created_term' ), 5, 3 );
		add_action( 'deleted_post', array( self::class, 'on_deleted_post' ) );
		add_action( 'delete_term', array( self::class, 'on_deleted_term' ), 10, 3 );
		add_action( 'admin_init', array( self::class, 'maybe_backfill' ) );

		add_action( 'save_post', array( self::class, 'mark_dirty' ), 30, 2 );
		add_action( 'updated_post_meta', array( self::class, 'meta_dirty' ), 10, 3 );
		add_action( 'added_post_meta', array( self::class, 'meta_dirty' ), 10, 3 );
		add_action( 'shutdown', array( self::class, 'flush_dirty' ) );
	}

	/* ---------------------------------------------------------------------
	 * Translatable types
	 * ------------------------------------------------------------------ */

	/**
	 * @return string[]
	 */
	public static function post_types(): array {
		$types = array_values( array_filter( (array) Settings::get( 'post_types' ), 'is_string' ) );
		/**
		 * Filters the translatable post types.
		 *
		 * @param string[] $types
		 */
		return (array) apply_filters( 'gaiml_post_types', $types );
	}

	/**
	 * @return string[]
	 */
	public static function taxonomies(): array {
		$taxonomies = array_values( array_filter( (array) Settings::get( 'taxonomies' ), 'is_string' ) );
		/**
		 * Filters the translatable taxonomies.
		 *
		 * @param string[] $taxonomies
		 */
		return (array) apply_filters( 'gaiml_taxonomies', $taxonomies );
	}

	public static function is_translatable_post_type( string $post_type ): bool {
		return in_array( $post_type, self::post_types(), true );
	}

	public static function is_translatable_taxonomy( string $taxonomy ): bool {
		return in_array( $taxonomy, self::taxonomies(), true );
	}

	/**
	 * @param \WP_Post|int|null $post
	 */
	public static function is_translatable_post( $post ): bool {
		$post = get_post( $post );
		return $post instanceof \WP_Post && self::is_translatable_post_type( $post->post_type ) && ! self::is_excluded( $post );
	}

	/**
	 * Technical posts of a translatable type that are not content (Elementor kit).
	 */
	public static function is_excluded( \WP_Post $post ): bool {
		$excluded = 'elementor_library' === $post->post_type && 'kit' === get_post_meta( $post->ID, '_elementor_template_type', true );
		/**
		 * Filters whether a post is excluded from translation.
		 */
		return (bool) apply_filters( 'gaiml_exclude_post', $excluded, $post );
	}

	/* ---------------------------------------------------------------------
	 * Language assignment
	 * ------------------------------------------------------------------ */

	/**
	 * Runs $callback with a forced language for the objects it creates.
	 *
	 * @template T
	 * @param callable(): T $callback
	 * @return T
	 */
	public static function with_language( string $lang, int $group, callable $callback ) {
		$previous      = self::$pending;
		self::$pending = array(
			'lang'  => $lang,
			'group' => $group,
		);
		try {
			return $callback();
		} finally {
			self::$pending = $previous;
		}
	}

	public static function pending_language(): ?string {
		return self::$pending['lang'] ?? null;
	}

	/**
	 * @param int      $post_id
	 * @param \WP_Post $post
	 * @param bool     $update
	 */
	public static function on_save_post( $post_id, $post, $update ): void {
		if ( ! $post instanceof \WP_Post || wp_is_post_revision( $post_id ) || ! self::is_translatable_post_type( $post->post_type ) ) {
			return;
		}
		if ( null !== self::$pending ) {
			Translations::set( 'post', (int) $post_id, self::$pending['lang'], self::$pending['group'] );
			return;
		}
		if ( Translations::get( 'post', (int) $post_id ) ) {
			return;
		}
		$lang  = Languages::default_code();
		$group = 0;
		// "Add a translation manually" links: post-new.php?gaiml_lang=en&gaiml_from=123.
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only context for a new auto-draft, permissions checked below.
		if ( 'auto-draft' === $post->post_status && isset( $_GET['gaiml_lang'] ) ) {
			$requested = sanitize_key( wp_unslash( $_GET['gaiml_lang'] ) );
			$from      = isset( $_GET['gaiml_from'] ) ? absint( $_GET['gaiml_from'] ) : 0;
			if ( Languages::exists( $requested ) ) {
				$lang = $requested;
				if ( $from && current_user_can( 'edit_post', $from ) && get_post_type( $from ) === $post->post_type
					&& ! Translations::translation( 'post', $from, $requested ) ) {
					$group = Translations::group( 'post', $from ) ?: $from;
				}
			}
		} elseif ( is_admin() && isset( $_GET['gaiml_lang'] ) && Languages::exists( sanitize_key( wp_unslash( $_GET['gaiml_lang'] ) ) ) ) {
			$lang = sanitize_key( wp_unslash( $_GET['gaiml_lang'] ) );
		} elseif ( null !== Router::current() && ! is_admin() ) {
			$lang = (string) Router::current();
		}
		// phpcs:enable
		if ( $group && ! Translations::get( 'post', $group ) ) {
			Translations::set( 'post', $group, Languages::default_code() );
		}
		Translations::set( 'post', (int) $post_id, $lang, $group );
	}

	/**
	 * @param int    $term_id
	 * @param int    $tt_id
	 * @param string $taxonomy
	 */
	public static function on_created_term( $term_id, $tt_id, $taxonomy ): void {
		if ( ! self::is_translatable_taxonomy( (string) $taxonomy ) ) {
			return;
		}
		if ( null !== self::$pending ) {
			Translations::set( 'term', (int) $term_id, self::$pending['lang'], self::$pending['group'] );
			return;
		}
		$lang = Languages::default_code();
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- The term form is nonce-checked by WordPress core before this hook runs.
		$posted = isset( $_POST['gaiml_lang'] ) ? sanitize_key( wp_unslash( $_POST['gaiml_lang'] ) ) : '';
		if ( Languages::exists( $posted ) ) {
			$lang = $posted;
		} elseif ( null !== Router::current() && ! is_admin() ) {
			$lang = (string) Router::current();
		}
		Translations::set( 'term', (int) $term_id, $lang );
	}

	/**
	 * @param int $post_id
	 */
	public static function on_deleted_post( $post_id ): void {
		Translations::delete( 'post', (int) $post_id );
		Queue::forget( 'post', (int) $post_id );
	}

	/**
	 * @param int    $term_id
	 * @param int    $tt_id
	 * @param string $taxonomy
	 */
	public static function on_deleted_term( $term_id, $tt_id, $taxonomy ): void {
		Translations::delete( 'term', (int) $term_id );
		Queue::forget( 'term', (int) $term_id );
	}

	public static function maybe_backfill(): void {
		if ( get_transient( 'gaiml_backfill' ) ) {
			return;
		}
		set_transient( 'gaiml_backfill', 1, DAY_IN_SECONDS );
		Install::backfill();
	}

	/* ---------------------------------------------------------------------
	 * Automatic update of translations when an original changes
	 * ------------------------------------------------------------------ */

	/**
	 * @param int      $post_id
	 * @param \WP_Post $post
	 */
	public static function mark_dirty( $post_id, $post ): void {
		if ( ! $post instanceof \WP_Post || ! Settings::get( 'auto_update' ) || Engine::is_saving() || wp_is_post_revision( $post_id ) ) {
			return;
		}
		if ( 'publish' !== $post->post_status || ! self::is_translatable_post_type( $post->post_type ) ) {
			return;
		}
		if ( Translations::group( 'post', (int) $post_id ) === (int) $post_id ) {
			self::$dirty[ (int) $post_id ] = true;
		}
	}

	/**
	 * @param int    $meta_id
	 * @param int    $object_id
	 * @param string $meta_key
	 */
	public static function meta_dirty( $meta_id, $object_id, $meta_key ): void {
		if ( '_elementor_data' === $meta_key ) {
			$post = get_post( (int) $object_id );
			if ( $post ) {
				self::mark_dirty( (int) $object_id, $post );
			}
		}
	}

	public static function flush_dirty(): void {
		if ( ! self::$dirty ) {
			return;
		}
		$dirty       = array_keys( self::$dirty );
		self::$dirty = array();
		foreach ( $dirty as $source_id ) {
			$langs = array();
			foreach ( Translations::members( 'post', $source_id ) as $lang => $target_id ) {
				if ( $target_id === $source_id ) {
					continue;
				}
				$status = Engine::status( $source_id, $lang );
				if ( 'outdated' === $status ) {
					$langs[] = $lang;
				}
			}
			if ( $langs ) {
				Queue::enqueue( 'post', $source_id, $langs );
			}
		}
	}
}
