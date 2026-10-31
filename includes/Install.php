<?php
/**
 * Activation, database tables and upgrades.
 *
 * @package GustinAI\Multilingual
 */

namespace GustinAI\Multilingual;

use GustinAI\Multilingual\Core\Content;
use GustinAI\Multilingual\Core\Translations;

defined( 'ABSPATH' ) || exit;

final class Install {

	/**
	 * @param bool $network_wide
	 */
	public static function activate( $network_wide = false ): void {
		if ( is_multisite() && $network_wide ) {
			foreach ( get_sites( array( 'fields' => 'ids' ) ) as $site_id ) {
				switch_to_blog( (int) $site_id );
				self::activate_site();
				restore_current_blog();
			}
			return;
		}
		self::activate_site();
	}

	public static function activate_site(): void {
		self::create_tables();
		self::init_settings();
		self::backfill();
		update_option( 'gaiml_db_version', GAIML_DB_VERSION, true );
	}

	public static function deactivate(): void {
		wp_clear_scheduled_hook( 'gaiml_cron' );
		delete_option( 'gaiml_lock' );
	}

	public static function maybe_upgrade(): void {
		if ( get_option( 'gaiml_db_version' ) !== GAIML_DB_VERSION ) {
			self::create_tables();
			self::init_settings();
			update_option( 'gaiml_db_version', GAIML_DB_VERSION, true );
		}
	}

	public static function create_tables(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$collate = $wpdb->get_charset_collate();
		$p       = $wpdb->prefix;

		dbDelta(
			"CREATE TABLE {$p}gaiml_translations (
  object_type varchar(8) NOT NULL,
  object_id bigint(20) unsigned NOT NULL,
  lang varchar(12) NOT NULL,
  group_id bigint(20) unsigned NOT NULL,
  PRIMARY KEY  (object_type,object_id),
  UNIQUE KEY group_lang (object_type,group_id,lang),
  KEY lang (object_type,lang)
) $collate;"
		);

		dbDelta(
			"CREATE TABLE {$p}gaiml_strings (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  hash char(32) NOT NULL,
  lang varchar(12) NOT NULL,
  source longtext NOT NULL,
  translation longtext NOT NULL,
  context varchar(20) NOT NULL DEFAULT '',
  manual tinyint(1) NOT NULL DEFAULT 0,
  updated datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY hash_lang (hash,lang),
  KEY context (context,lang)
) $collate;"
		);

		dbDelta(
			"CREATE TABLE {$p}gaiml_jobs (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  object_type varchar(8) NOT NULL,
  object_id bigint(20) unsigned NOT NULL,
  lang varchar(12) NOT NULL,
  status varchar(10) NOT NULL DEFAULT 'pending',
  force_update tinyint(1) NOT NULL DEFAULT 0,
  attempts smallint(5) unsigned NOT NULL DEFAULT 0,
  locked_until datetime NULL DEFAULT NULL,
  message text NULL,
  data longtext NULL,
  created datetime NOT NULL,
  updated datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY job (object_type,object_id,lang),
  KEY status (status,id)
) $collate;"
		);
	}

	/**
	 * First activation: the site language becomes the default language.
	 */
	public static function init_settings(): void {
		Settings::reset_cache();
		if ( '' !== (string) Settings::get( 'default_lang' ) && Languages::is_ready() ) {
			return;
		}
		$code  = Languages::code_from_locale( (string) get_option( 'WPLANG', '' ) ?: 'en_US' );
		$entry = Languages::entry( $code );
		Settings::update(
			array(
				'default_lang' => $code,
				'languages'    => array( $entry ),
			)
		);
	}

	/**
	 * Gives the default language to existing content that has none.
	 */
	public static function backfill(): int {
		if ( ! Languages::is_ready() ) {
			return 0;
		}
		$lang  = Languages::default_code();
		$count = Translations::assign_missing( 'post', Content::post_types(), $lang );
		$count += Translations::assign_missing( 'term', Content::taxonomies(), $lang );
		return $count;
	}
}
