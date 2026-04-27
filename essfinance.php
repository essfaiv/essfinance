<?php
/**
 * Plugin Name: EssFinance
 * Description: Simple personal finance management
 * Version: 0.3.3
 * Author: EssFinance
 * Text Domain: essfinance
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Requires at least: 4.9
 * Requires PHP: 7.2
 * Tested up to: 6.9
 * Domain Path: /languages
 *
 * @package EssFinance
 */

defined( 'ABSPATH' ) || exit;

define( 'ESSF_VERSION', '0.3.3' );
define( 'ESSF_PATH', plugin_dir_path( __FILE__ ) );
define( 'ESSF_URL', plugin_dir_url( __FILE__ ) );

require_once ESSF_PATH . 'includes/class-cpt.php';
require_once ESSF_PATH . 'includes/class-settings.php';
require_once ESSF_PATH . 'includes/class-shortcodes.php';

register_activation_hook( __FILE__, [ 'ESSF_CPT', 'activate' ] );

add_action( 'plugins_loaded', 'essf_boot' );

/** Boots the plugin after all plugins are loaded. */
function essf_boot() {
	new ESSF_CPT();
	new ESSF_Shortcodes();

	if ( ! is_admin() ) {
		return;
	}

	require_once ESSF_PATH . 'includes/class-meta-boxes.php';
	require_once ESSF_PATH . 'includes/class-list-table.php';
	require_once ESSF_PATH . 'includes/class-admin-page.php';
	require_once ESSF_PATH . 'includes/class-assets.php';

	new ESSF_Meta_Boxes();
	new ESSF_Admin_Page();
	new ESSF_Assets();
	new ESSF_Settings();
}
