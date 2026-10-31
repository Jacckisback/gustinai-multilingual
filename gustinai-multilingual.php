<?php
/**
 * Plugin Name:       GustinAI Multilingual
 * Plugin URI:        https://gustinai.com
 * Description:       Turn any WordPress site into a fast, SEO & GEO-ready multilingual site. AI translation with Claude or ChatGPT, translated slugs, categories and menus, hreflang, llms.txt. Works with Gutenberg, Elementor and Divi.
 * Version:           1.0.0
 * Requires at least: 6.5
 * Requires PHP:      8.1
 * Author:            Thierry Gustin
 * Author URI:        https://gustinai.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       gustinai-multilingual
 * Domain Path:       /languages
 *
 * @package GustinAI\Multilingual
 */

defined( 'ABSPATH' ) || exit;

if ( defined( 'GAIML_VERSION' ) ) {
	return;
}

define( 'GAIML_VERSION', '1.0.0' );
define( 'GAIML_DB_VERSION', '1' );
define( 'GAIML_FILE', __FILE__ );
define( 'GAIML_DIR', plugin_dir_path( __FILE__ ) );
define( 'GAIML_URL', plugin_dir_url( __FILE__ ) );
define( 'GAIML_BASENAME', plugin_basename( __FILE__ ) );
define( 'GAIML_MIN_PHP', '8.1' );

if ( version_compare( PHP_VERSION, GAIML_MIN_PHP, '<' ) ) {
	add_action(
		'admin_notices',
		static function () {
			echo '<div class="notice notice-error"><p>';
			/* translators: 1: required PHP version, 2: current PHP version. */
			echo esc_html( sprintf( __( 'GustinAI Multilingual requires PHP %1$s or newer. Your server runs PHP %2$s.', 'gustinai-multilingual' ), GAIML_MIN_PHP, PHP_VERSION ) );
			echo '</p></div>';
		}
	);
	return;
}

require_once GAIML_DIR . 'includes/Autoloader.php';
\GustinAI\Multilingual\Autoloader::register();
require_once GAIML_DIR . 'includes/functions.php';

register_activation_hook( __FILE__, array( \GustinAI\Multilingual\Install::class, 'activate' ) );
register_deactivation_hook( __FILE__, array( \GustinAI\Multilingual\Install::class, 'deactivate' ) );

\GustinAI\Multilingual\Plugin::boot();
