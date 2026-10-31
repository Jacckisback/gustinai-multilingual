<?php
/**
 * Admin menu, assets and notices.
 *
 * @package GustinAI\Multilingual
 */

namespace GustinAI\Multilingual\Admin;

use GustinAI\Multilingual\Core\Content;
use GustinAI\Multilingual\Languages;
use GustinAI\Multilingual\Settings;
use GustinAI\Multilingual\Translation\Queue;

defined( 'ABSPATH' ) || exit;

final class Admin {

	public static function init(): void {
		add_action( 'admin_menu', array( self::class, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( self::class, 'assets' ) );
		add_action( 'admin_notices', array( self::class, 'notices' ) );
		add_filter( 'plugin_action_links_' . GAIML_BASENAME, array( self::class, 'action_links' ) );
	}

	public static function menu(): void {
		add_menu_page(
			__( 'Multilingual', 'gustinai-multilingual' ),
			__( 'Multilingual', 'gustinai-multilingual' ),
			'manage_options',
			'gaiml',
			array( SettingsPage::class, 'render' ),
			'dashicons-translation',
			81
		);
		add_submenu_page( 'gaiml', __( 'Multilingual settings', 'gustinai-multilingual' ), __( 'Settings', 'gustinai-multilingual' ), 'manage_options', 'gaiml', array( SettingsPage::class, 'render' ) );
		add_submenu_page( 'gaiml', __( 'Bulk translation', 'gustinai-multilingual' ), __( 'Bulk translation', 'gustinai-multilingual' ), 'manage_options', 'gaiml-bulk', array( SettingsPage::class, 'render_bulk' ) );
		add_submenu_page( 'gaiml', __( 'Strings', 'gustinai-multilingual' ), __( 'Strings', 'gustinai-multilingual' ), 'manage_options', 'gaiml-strings', array( SettingsPage::class, 'render_strings' ) );
	}

	/**
	 * @param string $hook
	 */
	public static function assets( $hook ): void {
		global $typenow, $taxnow;
		$screens = array( 'toplevel_page_gaiml', 'multilingual_page_gaiml-bulk', 'multilingual_page_gaiml-strings' );
		$is_ours = in_array( $hook, $screens, true ) || 0 === strpos( (string) $hook, 'multilingual_page_' ) || false !== strpos( (string) $hook, 'gaiml' );
		$is_post = in_array( $hook, array( 'post.php', 'post-new.php', 'edit.php' ), true ) && Content::is_translatable_post_type( (string) $typenow );
		$is_term = in_array( $hook, array( 'edit-tags.php', 'term.php' ), true ) && Content::is_translatable_taxonomy( (string) $taxnow );
		if ( ! $is_ours && ! $is_post && ! $is_term ) {
			return;
		}
		wp_enqueue_style( 'gaiml-admin', GAIML_URL . 'assets/css/admin.css', array(), GAIML_VERSION );
		wp_enqueue_script( 'gaiml-admin', GAIML_URL . 'assets/js/admin.js', array(), GAIML_VERSION, true );
		wp_localize_script(
			'gaiml-admin',
			'gaimlAdmin',
			array(
				'ajax'  => admin_url( 'admin-ajax.php' ),
				'nonce' => wp_create_nonce( 'gaiml_admin' ),
				'i18n'  => array(
					'queued'      => __( 'Queued…', 'gustinai-multilingual' ),
					'working'     => __( 'Translating…', 'gustinai-multilingual' ),
					'done'        => __( 'Done', 'gustinai-multilingual' ),
					'failed'      => __( 'Failed', 'gustinai-multilingual' ),
					'error'       => __( 'Error', 'gustinai-multilingual' ),
					/* translators: %d: number of translations waiting. */
					'remaining'   => __( '%d translation(s) waiting', 'gustinai-multilingual' ),
					'finished'    => __( 'All translations are done.', 'gustinai-multilingual' ),
					'confirmEdit' => __( 'This translation was edited by hand. Overwrite it with a new AI translation?', 'gustinai-multilingual' ),
					'loading'     => __( 'Loading…', 'gustinai-multilingual' ),
					'stopped'     => __( 'Paused. The remaining translations continue in the background.', 'gustinai-multilingual' ),
					/* translators: 1: number of contents, 2: number of translations, 3: thousands of characters. */
					'estimate'    => __( '%1$s contents, %2$s translations, about %3$sk characters', 'gustinai-multilingual' ),
					/* translators: 1: estimated cost in US dollars, 2: model name. */
					'cost'        => __( '≈ $%1$s with %2$s', 'gustinai-multilingual' ),
				),
			)
		);
	}

	public static function notices(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$screen = get_current_screen();
		if ( $screen && false !== strpos( (string) $screen->id, 'gaiml' ) ) {
			return;
		}
		if ( ! Languages::is_multilingual() ) {
			printf(
				'<div class="notice notice-info"><p><strong>GustinAI Multilingual</strong> — %s <a class="button button-primary" href="%s">%s</a></p></div>',
				esc_html__( 'Add a second language and an API key to translate your site.', 'gustinai-multilingual' ),
				esc_url( admin_url( 'admin.php?page=gaiml' ) ),
				esc_html__( 'Set up languages', 'gustinai-multilingual' )
			);
			return;
		}
		$provider = Settings::provider();
		if ( '' === Settings::api_key( $provider ) && ! has_filter( 'gaiml_provider' ) ) {
			printf(
				'<div class="notice notice-warning"><p><strong>GustinAI Multilingual</strong> — %s <a href="%s">%s</a></p></div>',
				esc_html__( 'No API key: AI translation is disabled.', 'gustinai-multilingual' ),
				esc_url( admin_url( 'admin.php?page=gaiml&tab=translation' ) ),
				esc_html__( 'Add a Claude or OpenAI key', 'gustinai-multilingual' )
			);
		}
		$stats = Queue::stats();
		if ( $stats['failed'] > 0 ) {
			printf(
				'<div class="notice notice-error"><p><strong>GustinAI Multilingual</strong> — %s <a href="%s">%s</a></p></div>',
				/* translators: %d: number of failed translations. */
				esc_html( sprintf( _n( '%d translation failed.', '%d translations failed.', $stats['failed'], 'gustinai-multilingual' ), $stats['failed'] ) ),
				esc_url( admin_url( 'admin.php?page=gaiml-bulk' ) ),
				esc_html__( 'See why', 'gustinai-multilingual' )
			);
		}
	}

	/**
	 * @param string[] $links
	 * @return string[]
	 */
	public static function action_links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( admin_url( 'admin.php?page=gaiml' ) ) . '">' . esc_html__( 'Settings', 'gustinai-multilingual' ) . '</a>' );
		return $links;
	}

	/**
	 * Label and CSS class of a translation status.
	 *
	 * @return array{0: string, 1: string}
	 */
	public static function status_label( string $status ): array {
		$labels = array(
			'missing'    => __( 'Not translated', 'gustinai-multilingual' ),
			'queued'     => __( 'In progress', 'gustinai-multilingual' ),
			'failed'     => __( 'Failed', 'gustinai-multilingual' ),
			'translated' => __( 'Up to date', 'gustinai-multilingual' ),
			'outdated'   => __( 'Original changed', 'gustinai-multilingual' ),
			'edited'     => __( 'Edited by hand', 'gustinai-multilingual' ),
			'manual'     => __( 'Manual translation', 'gustinai-multilingual' ),
			'source'     => __( 'Original', 'gustinai-multilingual' ),
		);
		return array( $labels[ $status ] ?? $status, 'gaiml-status--' . sanitize_html_class( $status ) );
	}
}
