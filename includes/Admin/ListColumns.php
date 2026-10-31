<?php
/**
 * Post and term lists: translation status per language, one-click AI
 * translation, language filter and bulk action.
 *
 * @package GustinAI\Multilingual
 */

namespace GustinAI\Multilingual\Admin;

use GustinAI\Multilingual\Core\Content;
use GustinAI\Multilingual\Core\Translations;
use GustinAI\Multilingual\Languages;
use GustinAI\Multilingual\Translation\Engine;
use GustinAI\Multilingual\Translation\Queue;

defined( 'ABSPATH' ) || exit;

final class ListColumns {

	public static function init(): void {
		add_action( 'current_screen', array( self::class, 'setup' ) );
	}

	/**
	 * @param \WP_Screen $screen
	 */
	public static function setup( $screen ): void {
		if ( ! Languages::is_multilingual() || ! $screen instanceof \WP_Screen ) {
			return;
		}
		if ( 'edit' === $screen->base && Content::is_translatable_post_type( (string) $screen->post_type ) ) {
			$type = (string) $screen->post_type;
			add_filter( "manage_{$type}_posts_columns", array( self::class, 'columns' ) );
			add_action( "manage_{$type}_posts_custom_column", array( self::class, 'post_column' ), 10, 2 );
			add_action( 'restrict_manage_posts', array( self::class, 'filter' ) );
			add_filter( "bulk_actions-edit-{$type}", array( self::class, 'bulk_actions' ) );
			add_filter( "handle_bulk_actions-edit-{$type}", array( self::class, 'handle_bulk' ), 10, 3 );
			add_filter( 'the_posts', array( self::class, 'prime' ), 20 );
			add_action( 'admin_notices', array( self::class, 'bulk_notice' ) );
		}
		if ( 'edit-tags' === $screen->base && Content::is_translatable_taxonomy( (string) $screen->taxonomy ) ) {
			$taxonomy = (string) $screen->taxonomy;
			add_filter( "manage_edit-{$taxonomy}_columns", array( self::class, 'columns' ) );
			add_filter( "manage_{$taxonomy}_custom_column", array( self::class, 'term_column' ), 10, 3 );
		}
	}

	/**
	 * @param array<string, string> $columns
	 * @return array<string, string>
	 */
	public static function columns( $columns ): array {
		$out = array();
		foreach ( (array) $columns as $key => $label ) {
			$out[ $key ] = $label;
			if ( 'title' === $key || 'name' === $key ) {
				$out['gaiml'] = '<span class="dashicons dashicons-translation" aria-hidden="true"></span> ' . esc_html__( 'Languages', 'gustinai-multilingual' );
			}
		}
		if ( ! isset( $out['gaiml'] ) ) {
			$out['gaiml'] = esc_html__( 'Languages', 'gustinai-multilingual' );
		}
		return $out;
	}

	/**
	 * @param \WP_Post[] $posts
	 * @return \WP_Post[]
	 */
	public static function prime( $posts ) {
		if ( is_array( $posts ) && $posts ) {
			$ids = wp_list_pluck( $posts, 'ID' );
			Translations::prime( 'post', $ids );
			Queue::prime( 'post', $ids );
			update_meta_cache( 'post', $ids );
		}
		return $posts;
	}

	/**
	 * @param string $column
	 * @param int    $post_id
	 */
	public static function post_column( $column, $post_id ): void {
		if ( 'gaiml' !== $column ) {
			return;
		}
		$post_id = (int) $post_id;
		$source  = Engine::source_of( 'post', $post_id );
		$lang    = Translations::lang_or_default( 'post', $post_id );
		$members = Translations::members( 'post', $post_id );
		echo '<div class="gaiml-flags" data-gaiml-object="post" data-gaiml-id="' . esc_attr( (string) $source ) . '">';
		foreach ( Languages::all() as $code => $language ) {
			$label = strtoupper( (string) $language['slug'] );
			if ( $code === $lang ) {
				printf( '<span class="gaiml-flag is-current" title="%s">%s</span>', esc_attr( $language['name'] ), esc_html( $label ) );
				continue;
			}
			$status = Engine::status( $source, $code );
			list( $text, $class ) = Admin::status_label( $status );
			if ( isset( $members[ $code ] ) ) {
				printf(
					'<a class="gaiml-flag %s" href="%s" title="%s">%s</a>',
					esc_attr( $class ),
					esc_url( (string) get_edit_post_link( $members[ $code ] ) ),
					esc_attr( $language['name'] . ' — ' . $text ),
					esc_html( $label )
				);
			} elseif ( 'queued' === $status ) {
				printf( '<span class="gaiml-flag %s" title="%s">%s…</span>', esc_attr( $class ), esc_attr( $language['name'] . ' — ' . $text ), esc_html( $label ) );
			} elseif ( current_user_can( 'edit_post', $source ) ) {
				printf(
					'<button type="button" class="gaiml-flag gaiml-translate %s" data-langs="%s" data-force="0" title="%s">+%s</button>',
					esc_attr( $class ),
					esc_attr( $code ),
					/* translators: %s: language name. */
					esc_attr( sprintf( __( 'Translate into %s with AI', 'gustinai-multilingual' ), $language['name'] ) ),
					esc_html( $label )
				);
			}
		}
		echo '</div>';
	}

	/**
	 * @param string $content
	 * @param string $column
	 * @param int    $term_id
	 */
	public static function term_column( $content, $column, $term_id ): string {
		if ( 'gaiml' !== $column ) {
			return (string) $content;
		}
		$term_id = (int) $term_id;
		$lang    = Translations::lang_or_default( 'term', $term_id );
		$members = Translations::members( 'term', $term_id );
		$source  = Engine::source_of( 'term', $term_id );
		$jobs    = Queue::jobs_for( 'term', $source );
		$html    = '<div class="gaiml-flags" data-gaiml-object="term" data-gaiml-id="' . esc_attr( (string) $source ) . '">';
		foreach ( Languages::all() as $code => $language ) {
			$label = strtoupper( (string) $language['slug'] );
			if ( $code === $lang ) {
				$html .= '<span class="gaiml-flag is-current" title="' . esc_attr( $language['name'] ) . '">' . esc_html( $label ) . '</span>';
			} elseif ( isset( $members[ $code ] ) ) {
				$html .= '<a class="gaiml-flag gaiml-status--translated" href="' . esc_url( (string) get_edit_term_link( $members[ $code ] ) ) . '" title="' . esc_attr( $language['name'] ) . '">' . esc_html( $label ) . '</a>';
			} elseif ( isset( $jobs[ $code ] ) && 'failed' !== $jobs[ $code ]->status ) {
				$html .= '<span class="gaiml-flag gaiml-status--queued">' . esc_html( $label ) . '…</span>';
			} else {
				/* translators: %s: language name. */
				$html .= '<button type="button" class="gaiml-flag gaiml-translate gaiml-status--missing" data-langs="' . esc_attr( $code ) . '" data-force="0" title="' . esc_attr( sprintf( __( 'Translate into %s with AI', 'gustinai-multilingual' ), $language['name'] ) ) . '">+' . esc_html( $label ) . '</button>';
			}
		}
		return $html . '</div>';
	}

	/**
	 * @param string $post_type
	 */
	public static function filter( $post_type = '' ): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- List filter.
		$current = isset( $_GET['gaiml_lang'] ) ? sanitize_key( wp_unslash( $_GET['gaiml_lang'] ) ) : '';
		echo '<label class="screen-reader-text" for="gaiml-filter">' . esc_html__( 'Filter by language', 'gustinai-multilingual' ) . '</label>';
		echo '<select name="gaiml_lang" id="gaiml-filter"><option value="">' . esc_html__( 'All languages', 'gustinai-multilingual' ) . '</option>';
		foreach ( Languages::all() as $code => $language ) {
			printf( '<option value="%s"%s>%s</option>', esc_attr( $code ), selected( $current, $code, false ), esc_html( $language['name'] ) );
		}
		echo '</select>';
	}

	/**
	 * @param array<string, string> $actions
	 * @return array<string, string>
	 */
	public static function bulk_actions( $actions ): array {
		$actions['gaiml_translate'] = __( 'Translate with AI (all languages)', 'gustinai-multilingual' );
		$actions['gaiml_update']    = __( 'Update AI translations', 'gustinai-multilingual' );
		return $actions;
	}

	/**
	 * @param string $redirect
	 * @param string $action
	 * @param int[]  $ids
	 */
	public static function handle_bulk( $redirect, $action, $ids ): string {
		if ( ! in_array( $action, array( 'gaiml_translate', 'gaiml_update' ), true ) ) {
			return (string) $redirect;
		}
		$count = 0;
		foreach ( (array) $ids as $id ) {
			$source = Engine::source_of( 'post', (int) $id );
			if ( ! current_user_can( 'edit_post', $source ) ) {
				continue;
			}
			$langs = array();
			foreach ( Languages::others( Translations::lang_or_default( 'post', $source ) ) as $lang ) {
				$status = Engine::status( $source, $lang );
				if ( 'gaiml_translate' === $action ? in_array( $status, array( 'missing', 'failed' ), true ) : in_array( $status, array( 'outdated', 'failed' ), true ) ) {
					$langs[] = $lang;
				}
			}
			$count += Queue::enqueue( 'post', $source, $langs );
		}
		return add_query_arg( 'gaiml_queued', $count, (string) $redirect );
	}

	public static function bulk_notice(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display only.
		if ( ! isset( $_GET['gaiml_queued'] ) ) {
			return;
		}
		$count = absint( $_GET['gaiml_queued'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		printf(
			'<div class="notice notice-success is-dismissible gaiml-bulk-notice"><p>%s <span class="gaiml-progress" data-autorun="1" aria-live="polite"></span></p></div>',
			/* translators: %d: number of translations. */
			esc_html( sprintf( _n( '%d translation queued.', '%d translations queued.', $count, 'gustinai-multilingual' ), $count ) )
		);
	}
}
