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

		// Bills/Loans/Financing term screens (native edit-tags.php/term.php,
		// shared by every taxonomy — scoped here to just our own three) and
		// their shared "History"/payments listing page (registered with an
		// empty parent slug, so it carries no distinctive $hook of its own).
		$essf_taxonomies = [ ESSF_Bill_CPT::TAXONOMY, ESSF_Loan_CPT::TAXONOMY, ESSF_Financing_CPT::TAXONOMY ];
		$is_term_screen  = in_array( $hook, [ 'edit-tags.php', 'term.php' ], true )
			&& isset( $_GET['taxonomy'] ) && in_array( $_GET['taxonomy'], $essf_taxonomies, true );
		$is_entries_page = isset( $_GET['page'] ) && ESSF_Recurrence_Entries_Page::SLUG === $_GET['page'];

		if ( ! $is_essf_screen && ! $is_cpt_screen && ! $is_essf_post && ! $is_term_screen && ! $is_entries_page ) {
			return;
		}

		wp_enqueue_style( 'essf-admin', ESSF_URL . 'assets/css/admin.css', [], ESSF_VERSION );
		wp_enqueue_script( 'essf-admin', ESSF_URL . 'assets/js/admin.js', [], ESSF_VERSION, true );
	}
}
