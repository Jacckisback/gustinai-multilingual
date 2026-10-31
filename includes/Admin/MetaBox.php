<?php
/**
 * "Languages" box of the editor (block editor, classic editor; Elementor and
 * Divi users reach it from the WordPress edit screen or the toolbar).
 *
 * @package GustinAI\Multilingual
 */

namespace GustinAI\Multilingual\Admin;

use GustinAI\Multilingual\Core\Content;
use GustinAI\Multilingual\Core\Translations;
use GustinAI\Multilingual\Languages;
use GustinAI\Multilingual\Settings;
use GustinAI\Multilingual\Translation\Engine;
use GustinAI\Multilingual\Translation\Models;
use GustinAI\Multilingual\Translation\Queue;

defined( 'ABSPATH' ) || exit;

final class MetaBox {

	public static function init(): void {
		add_action( 'add_meta_boxes', array( self::class, 'register' ), 10, 2 );
		add_action( 'save_post', array( self::class, 'save' ), 8, 2 );
	}

	/**
	 * @param string   $post_type
	 * @param \WP_Post $post
	 */
	public static function register( $post_type, $post = null ): void {
		if ( ! Content::is_translatable_post_type( (string) $post_type ) || ! Languages::is_ready() ) {
			return;
		}
		add_meta_box( 'gaiml-languages', __( 'Languages', 'gustinai-multilingual' ), array( self::class, 'render' ), (string) $post_type, 'side', 'high' );
	}

	public static function render( \WP_Post $post ): void {
		$lang    = Translations::lang_or_default( 'post', $post->ID );
		$members = Translations::members( 'post', $post->ID );
		$source  = Engine::source_of( 'post', $post->ID );
		$alone   = count( $members ) <= 1;
		wp_nonce_field( 'gaiml_meta_box', 'gaiml_meta_nonce' );

		echo '<div class="gaiml-box" data-gaiml-object="post" data-gaiml-id="' . esc_attr( (string) $source ) . '">';
		echo '<p><label for="gaiml-lang"><strong>' . esc_html__( 'Language of this content', 'gustinai-multilingual' ) . '</strong></label><br>';
		if ( $alone ) {
			echo '<select name="gaiml_lang" id="gaiml-lang">';
			foreach ( Languages::all() as $code => $language ) {
				printf( '<option value="%s"%s>%s</option>', esc_attr( $code ), selected( $code, $lang, false ), esc_html( $language['name'] ) );
			}
			echo '</select>';
		} else {
			echo esc_html( Languages::name( $lang ) );
		}
		echo '</p>';

		if ( ! Languages::is_multilingual() ) {
			echo '<p class="description">' . esc_html__( 'Add languages in Multilingual > Settings.', 'gustinai-multilingual' ) . '</p></div>';
			return;
		}
		if ( 'auto-draft' === $post->post_status ) {
			$link_to = (int) get_post_meta( $post->ID, '_gaiml_link_to', true );
			if ( $link_to && current_user_can( 'edit_post', $link_to ) ) {
				printf(
					'<p class="description">%s <a href="%s">%s</a></p>',
					esc_html__( 'Translation of', 'gustinai-multilingual' ),
					esc_url( (string) get_edit_post_link( $link_to ) ),
					esc_html( get_the_title( $link_to ) )
				);
			}
			echo '<p class="description">' . esc_html__( 'Save the content first, then translate it here.', 'gustinai-multilingual' ) . '</p></div>';
			return;
		}

		if ( $source !== $post->ID ) {
			printf(
				'<p class="description">%s <a href="%s">%s</a></p>',
				esc_html__( 'Translation of', 'gustinai-multilingual' ),
				esc_url( (string) get_edit_post_link( $source ) ),
				esc_html( get_the_title( $source ) )
			);
		}

		$source_lang = Translations::lang_or_default( 'post', $source );
		$missing     = array();
		echo '<ul class="gaiml-langs">';
		foreach ( Languages::all() as $code => $language ) {
			if ( $code === $source_lang ) {
				continue;
			}
			$status = Engine::status( $source, $code );
			$target = Engine::target( $source, $code );
			list( $label, $class ) = Admin::status_label( $status );
			echo '<li class="gaiml-lang" data-lang="' . esc_attr( $code ) . '">';
			echo '<span class="gaiml-lang__name">' . esc_html( $language['name'] ) . '</span> ';
			echo '<span class="gaiml-status ' . esc_attr( $class ) . '">' . esc_html( $label ) . '</span>';
			echo '<span class="gaiml-lang__actions">';
			if ( $target && $target !== $post->ID ) {
				printf( '<a href="%s">%s</a> ', esc_url( (string) get_edit_post_link( $target ) ), esc_html__( 'Edit', 'gustinai-multilingual' ) );
				if ( 'publish' === get_post_status( $target ) ) {
					printf( '<a href="%s" target="_blank">%s</a> ', esc_url( (string) get_permalink( $target ) ), esc_html__( 'View', 'gustinai-multilingual' ) );
				}
			}
			if ( 'queued' !== $status && ( ! $target || $target === $post->ID || Permissions::can_overwrite( 'post', $target ) ) ) {
				$force = ( $target && $target !== $source && Engine::is_edited( $target ) ) ? '1' : '0';
				$text  = $target ? __( 'Update', 'gustinai-multilingual' ) : __( 'Translate', 'gustinai-multilingual' );
				printf(
					'<button type="button" class="button-link gaiml-translate" data-langs="%s" data-force="%s" data-confirm="%s">%s</button>',
					esc_attr( $code ),
					$target ? '1' : '0',
					esc_attr( $force ),
					esc_html( $text )
				);
			}
			if ( ! $target ) {
				$missing[] = $code;
				printf(
					' <a class="gaiml-manual" href="%s" title="%s">+</a>',
					esc_url( admin_url( 'post-new.php?post_type=' . $post->post_type . '&gaiml_lang=' . $code . '&gaiml_from=' . $source ) ),
					esc_attr__( 'Write this translation by hand', 'gustinai-multilingual' )
				);
			}
			echo '</span>';
			$job = Queue::job( 'post', $source, $code );
			if ( $job && 'failed' === $job->status && '' !== (string) $job->message ) {
				echo '<span class="gaiml-error">' . esc_html( (string) $job->message ) . '</span>';
			}
			echo '</li>';
		}
		echo '</ul>';

		if ( $missing ) {
			printf(
				'<p><button type="button" class="button button-primary gaiml-translate" data-langs="%s" data-force="0">%s</button></p>',
				esc_attr( implode( ',', $missing ) ),
				esc_html__( 'Translate into all languages', 'gustinai-multilingual' )
			);
			$provider = Settings::provider();
			$model    = Settings::model( $provider );
			$label    = Models::known( $provider )[ $model ]['label'] ?? $model;
			$chars    = Engine::chars( $source, $missing[0] );
			$cost     = Models::estimate( $provider, $model, $chars );
			if ( null !== $cost && $chars > 0 ) {
				echo '<p class="description">' . esc_html(
					sprintf(
						/* translators: 1: estimated cost in US dollars, 2: model name. */
						__( 'Estimated cost: about $%1$s per language with %2$s.', 'gustinai-multilingual' ),
						number_format_i18n( max( 0.01, $cost ), 2 ),
						$label
					)
				) . '</p>';
			}
		}
		$autorun = Queue::jobs_for( 'post', $source );
		echo '<p class="gaiml-progress" aria-live="polite"' . ( array_filter( $autorun, static fn( $j ) => 'failed' !== $j->status ) ? ' data-autorun="1"' : '' ) . '></p>';
		echo '</div>';
	}

	/**
	 * Language change from the box.
	 *
	 * @param int      $post_id
	 * @param \WP_Post $post
	 */
	public static function save( $post_id, $post ): void {
		if ( ! isset( $_POST['gaiml_meta_nonce'], $_POST['gaiml_lang'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['gaiml_meta_nonce'] ) ), 'gaiml_meta_box' ) ) {
			return;
		}
		if ( wp_is_post_revision( $post_id ) || ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		$lang = sanitize_key( wp_unslash( $_POST['gaiml_lang'] ) );
		if ( ! Languages::exists( $lang ) || count( Translations::members( 'post', (int) $post_id ) ) > 1 ) {
			return;
		}
		Translations::set( 'post', (int) $post_id, $lang );
	}
}
