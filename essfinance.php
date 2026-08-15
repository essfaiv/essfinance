<?php
/**
 * Plugin Name: EssFinance
 * Description: Simple personal finance management
 * Version: 0.3.4
 * Author: EssFinance
 * Text Domain: essfinance
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Requires at least: 4.9
 * Requires PHP: 8.0
 * Tested up to: 6.9
 * Domain Path: /languages
 * Directory: https://github.com/essfaiv/essfinance
 *
 * @package EssFinance
 */

defined( 'ABSPATH' ) || exit;

define( 'ESSF_VERSION', '0.3.4' );
define( 'ESSF_PATH', plugin_dir_path( __FILE__ ) );
define( 'ESSF_URL', plugin_dir_url( __FILE__ ) );

// SelfDirectory provides self-hosted update checking via GitHub Releases.
// It is bundled as a git submodule and optional — the plugin works fully
// without it (e.g. local/dev environments where the submodule was not
// initialised). Production deploys must include the submodule.
$_essf_selfdirectory = ESSF_PATH . 'lib/selfdirectory/class-selfdirectory.php';
if ( file_exists( $_essf_selfdirectory ) ) {
	require_once $_essf_selfdirectory;
	add_action(
		'selfd_register',
		function () {
			selfd( __FILE__ );
		}
	);
}
unset( $_essf_selfdirectory );

require_once ESSF_PATH . 'includes/class-cpt.php';
require_once ESSF_PATH . 'includes/class-category.php';
require_once ESSF_PATH . 'includes/class-ofx-parser.php';
require_once ESSF_PATH . 'includes/class-ofx-suggestions.php';
require_once ESSF_PATH . 'includes/class-settings.php';
require_once ESSF_PATH . 'includes/class-shortcodes.php';

register_activation_hook( __FILE__, [ 'ESSF_CPT', 'activate' ] );
register_activation_hook( __FILE__, [ 'ESSF_Category', 'activate' ] );

add_action( 'plugins_loaded', 'essf_boot' );

/** Boots the plugin after all plugins are loaded. */
function essf_boot() {
	load_plugin_textdomain( 'essfinance', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );

	new ESSF_CPT();
	new ESSF_Category();
	new ESSF_Shortcodes();

	if ( is_admin() ) {
		require_once ESSF_PATH . 'includes/class-meta-boxes.php';
		require_once ESSF_PATH . 'includes/class-list-table.php';
		require_once ESSF_PATH . 'includes/class-admin-page.php';
		require_once ESSF_PATH . 'includes/class-assets.php';

		new ESSF_Meta_Boxes();
		new ESSF_Admin_Page();
		new ESSF_Assets();
		new ESSF_Settings();
	}

	if ( defined( 'WP_CLI' ) && WP_CLI ) {
		require_once ESSF_PATH . 'includes/class-cli.php';
		new ESSF_CLI();
	}
}
