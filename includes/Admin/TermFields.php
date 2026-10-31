<?php
/**
 * Language of terms: choice on the "Add" form, translations on the edit form.
 *
 * @package GustinAI\Multilingual
 */

namespace GustinAI\Multilingual\Admin;

use GustinAI\Multilingual\Core\Content;
use GustinAI\Multilingual\Core\Translations;
use GustinAI\Multilingual\Languages;
use GustinAI\Multilingual\Translation\Engine;

defined( 'ABSPATH' ) || exit;

final class TermFields {

	public static function init(): void {
		add_action( 'admin_init', array( self::class, 'register' ) );
	}

	public static function register(): void {
		if ( ! Languages::is_multilingual() ) {
			return;
		}
		foreach ( Content::taxonomies() as $taxonomy ) {
			add_action( "{$taxonomy}_add_form_fields", array( self::class, 'add_field' ) );
			add_action( "{$taxonomy}_edit_form_fields", array( self::class, 'edit_field' ), 10, 2 );
		}
	}

	public static function add_field(): void {
		echo '<div class="form-field"><label for="gaiml-term-lang">' . esc_html__( 'Language', 'gustinai-multilingual' ) . '</label><select name="gaiml_lang" id="gaiml-term-lang">';
		foreach ( Languages::all() as $code => $language ) {
			printf( '<option value="%s">%s</option>', esc_attr( $code ), esc_html( $language['name'] ) );
		}
		echo '</select></div>';
	}

	/**
	 * @param \WP_Term $term
	 */
	public static function edit_field( $term ): void {
		if ( ! $term instanceof \WP_Term ) {
			return;
		}
		$lang    = Translations::lang_or_default( 'term', $term->term_id );
		$members = Translations::members( 'term', $term->term_id );
		$source  = Engine::source_of( 'term', $term->term_id );
		echo '<tr class="form-field"><th scope="row">' . esc_html__( 'Language', 'gustinai-multilingual' ) . '</th><td>';
		echo '<p><strong>' . esc_html( Languages::name( $lang ) ) . '</strong></p>';
		echo '<div class="gaiml-flags" data-gaiml-object="term" data-gaiml-id="' . esc_attr( (string) $source ) . '">';
		foreach ( Languages::all() as $code => $language ) {
			if ( $code === $lang ) {
				continue;
			}
			if ( isset( $members[ $code ] ) ) {
				printf( '<a class="gaiml-flag gaiml-status--translated" href="%s">%s</a> ', esc_url( (string) get_edit_term_link( $members[ $code ] ) ), esc_html( $language['name'] ) );
			} else {
				printf( '<button type="button" class="button gaiml-translate" data-langs="%s" data-force="0">%s</button> ', esc_attr( $code ), esc_html( sprintf( /* translators: %s: language name. */ __( 'Translate into %s', 'gustinai-multilingual' ), $language['name'] ) ) );
			}
		}
		echo '</div><p class="gaiml-progress" aria-live="polite"></p></td></tr>';
	}
}
