<?php
/**
 * Plugin Name: URL Usage Finder
 * Plugin URI: https://github.com/shoot56/url-usage-finder
 * Description: Find and replace URL usage in posts, meta, menus, and options.
 * Version: 0.1.1
 * Author: Dmitry Shutko
 * Author URI: https://procoders.tech
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: url-usage-finder
 * Domain Path: /languages
 * Requires at least: 5.0
 * Requires PHP: 7.2
 * GitHub Plugin URI: shoot56/url-usage-finder
 * Primary Branch: main
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'UUF_PLUGIN_FILE', __FILE__ );
define( 'UUF_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'UUF_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'UUF_PLUGIN_VERSION', '0.1.1' );

if ( ! defined( 'UUF_DEBUG' ) ) {
	define( 'UUF_DEBUG', false );
}

if ( ! defined( 'UUF_ENABLE_REPLACE' ) ) {
	define( 'UUF_ENABLE_REPLACE', false );
}

require_once UUF_PLUGIN_DIR . 'includes/class-url-usage-finder-matcher.php';
require_once UUF_PLUGIN_DIR . 'includes/class-url-usage-finder-scanner.php';
require_once UUF_PLUGIN_DIR . 'includes/class-url-usage-finder-replacer.php';
require_once UUF_PLUGIN_DIR . 'includes/class-url-usage-finder-admin.php';

add_action(
	'plugins_loaded',
	static function () {
		if ( is_admin() ) {
			URL_Usage_Finder_Admin::init();
		}
	}
);
