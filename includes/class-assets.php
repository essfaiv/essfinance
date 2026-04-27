<?php
/**
 * EssFinance — Assets
 *
 * @package EssFinance
 * @license GPL-2.0-or-later
 */

defined( 'ABSPATH' ) || exit;

class ESSF_Assets {

	public function __construct() {
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue' ] );
	}

	public function enqueue( $hook ) {
		$screens = [ 'toplevel_page_essfinance', 'essf_cashflow_page_essfinance', 'post.php', 'post-new.php' ];

		$is_essf_screen = in_array( $hook, $screens, true );
		$is_cpt_screen  = isset( $_GET['post_type'] ) && 'essf_cashflow' === $_GET['post_type'];

		global $post;
		$is_essf_post = $post && 'essf_cashflow' === $post->post_type;

		if ( ! $is_essf_screen && ! $is_cpt_screen && ! $is_essf_post ) {
			return;
		}

		wp_enqueue_style( 'essf-admin', ESSF_URL . 'assets/css/admin.css', [], ESSF_VERSION );
		wp_enqueue_script( 'essf-admin', ESSF_URL . 'assets/js/admin.js', [], ESSF_VERSION, true );
	}
}
