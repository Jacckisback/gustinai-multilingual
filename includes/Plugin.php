<?php
/**
 * Plugin bootstrap: detects the request language as early as possible, then
 * boots every module.
 *
 * Each module is a class exposing a static init() method that only registers
 * hooks. The request language is detected when the plugin file is loaded so
 * that WordPress loads core, theme and plugin translations in that language.
 *
 * @package GustinAI\Multilingual
 */

namespace GustinAI\Multilingual;

defined( 'ABSPATH' ) || exit;

final class Plugin {

	/**
	 * Modules booted on every request.
	 */
	private const MODULES = array(
		Core\Links::class,
		Core\Query::class,
		Core\Slugs::class,
		Core\Content::class,
		Translation\Queue::class,
		Seo\Hreflang::class,
		Seo\Sitemaps::class,
		Seo\Schema::class,
		Seo\LlmsTxt::class,
		Frontend\Switcher::class,
		Frontend\Menus::class,
		Frontend\Strings::class,
		Frontend\Media::class,
		Compat\Elementor::class,
		Rest\Rest::class,
		Admin\AdminBar::class,
	);

	/**
	 * Modules booted only in wp-admin (admin-ajax.php included).
	 */
	private const ADMIN_MODULES = array(
		Admin\Admin::class,
		Admin\SettingsPage::class,
		Admin\MetaBox::class,
		Admin\ListColumns::class,
		Admin\TermFields::class,
		Admin\Ajax::class,
	);

	private static bool $booted = false;

	public static function boot(): void {
		if ( self::$booted ) {
			return;
		}
		self::$booted = true;

		Core\Router::init();

		add_action( 'plugins_loaded', array( self::class, 'load_modules' ), 5 );
		add_action( 'init', array( self::class, 'load_textdomain' ), 0 );
		add_action( 'admin_init', array( Install::class, 'maybe_upgrade' ), 1 );
		add_filter( 'cron_schedules', array( self::class, 'cron_schedules' ) );
	}

	public static function load_modules(): void {
		foreach ( self::MODULES as $module ) {
			$module::init();
		}
		if ( is_admin() ) {
			foreach ( self::ADMIN_MODULES as $module ) {
				$module::init();
			}
		}
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command( 'gaiml', Cli\Commands::class );
		}
		/**
		 * Fires once every GustinAI Multilingual module is loaded.
		 */
		do_action( 'gaiml_loaded' );
	}

	public static function load_textdomain(): void {
		load_plugin_textdomain( 'gustinai-multilingual', false, dirname( GAIML_BASENAME ) . '/languages' );
	}

	/**
	 * @param array<string, array<string, mixed>> $schedules
	 * @return array<string, array<string, mixed>>
	 */
	public static function cron_schedules( $schedules ) {
		if ( is_array( $schedules ) && ! isset( $schedules['gaiml_minute'] ) ) {
			$schedules['gaiml_minute'] = array(
				'interval' => MINUTE_IN_SECONDS,
				'display'  => __( 'Every minute (GustinAI Multilingual queue)', 'gustinai-multilingual' ),
			);
		}
		return $schedules;
	}
}
