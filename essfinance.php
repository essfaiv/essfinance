<?php
/**
 * Plugin Name: EssFinance
 * Description: Simple personal finance management
 * Version: 0.3.0
 * Author: EssFinance
 * Text Domain: essfinance
 */

defined( 'ABSPATH' ) || exit;

define( 'ESSF_VERSION', '0.3.0' );
define( 'ESSF_PATH', plugin_dir_path( __FILE__ ) );
define( 'ESSF_URL', plugin_dir_url( __FILE__ ) );

require_once ESSF_PATH . 'includes/class-cpt.php';

register_activation_hook( __FILE__, [ 'ESSF_CPT', 'activate' ] );

add_action( 'plugins_loaded', 'essf_boot' );

function essf_boot() {
	new ESSF_CPT();

	if ( ! is_admin() ) {
		return;
	}

	require_once ESSF_PATH . 'includes/class-meta-boxes.php';
	require_once ESSF_PATH . 'includes/class-list-table.php';
	require_once ESSF_PATH . 'includes/class-admin-page.php';
	require_once ESSF_PATH . 'includes/class-assets.php';
	require_once ESSF_PATH . 'includes/class-settings.php';

	new ESSF_Meta_Boxes();
	new ESSF_Admin_Page();
	new ESSF_Assets();
	new ESSF_Settings();
}
