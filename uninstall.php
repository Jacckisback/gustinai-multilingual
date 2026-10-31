<?php
/**
 * Uninstall: data is deleted only when the option is enabled in the settings.
 * Translated pages and terms are regular content and are always kept.
 *
 * @package GustinAI\Multilingual
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

$gaiml_settings = get_option( 'gaiml_settings', array() );
if ( empty( $gaiml_settings['delete_data'] ) ) {
	return;
}

global $wpdb;
foreach ( array( 'gaiml_translations', 'gaiml_strings', 'gaiml_jobs' ) as $gaiml_table ) {
	$wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . $gaiml_table ); // phpcs:ignore WordPress.DB
}
foreach ( array( 'gaiml_settings', 'gaiml_secrets', 'gaiml_db_version', 'gaiml_usage', 'gaiml_model_caps', 'gaiml_links_version', 'gaiml_lock' ) as $gaiml_option ) {
	delete_option( $gaiml_option );
}
$wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE '\\_gaiml\\_%'" ); // phpcs:ignore WordPress.DB
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_gaiml\\_%' OR option_name LIKE '\\_transient\\_timeout\\_gaiml\\_%'" ); // phpcs:ignore WordPress.DB
wp_clear_scheduled_hook( 'gaiml_cron' );
