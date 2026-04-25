<?php
defined( 'ABSPATH' ) || exit;

class ESSF_CPT {

	public static $statuses = [
		'pending' => 'Pending',
		'paid'    => 'Paid',
	];

	public function __construct() {
		add_action( 'init', [ $this, 'register' ] );
	}

	public function register() {
		register_post_type( 'essf_cashflow', [
			'labels' => [
				'name'               => __( 'Cash Flow', 'essfinance' ),
				'singular_name'      => __( 'Entry', 'essfinance' ),
				'add_new'            => __( 'Add Entry', 'essfinance' ),
				'add_new_item'       => __( 'Add Entry', 'essfinance' ),
				'edit_item'          => __( 'Edit Entry', 'essfinance' ),
				'new_item'           => __( 'New Entry', 'essfinance' ),
				'view_item'          => __( 'View Entry', 'essfinance' ),
				'search_items'       => __( 'Search Entries', 'essfinance' ),
				'not_found'          => __( 'No entries found.', 'essfinance' ),
				'not_found_in_trash' => __( 'No entries in trash.', 'essfinance' ),
			],
			'public'       => false,
			'show_ui'      => true,
			'show_in_menu' => false,
			'supports'     => [ 'title', 'editor', 'custom-fields' ],
			'capabilities' => [
				'create_posts' => 'manage_options',
			],
			'map_meta_cap' => true,
		] );

		register_post_status( 'pending', [
			'label'                     => __( 'Pending', 'essfinance' ),
			'public'                    => false,
			'show_in_admin_status_list' => true,
			'show_in_admin_all_list'    => true,
		] );

		register_post_status( 'paid', [
			'label'                     => __( 'Paid', 'essfinance' ),
			'public'                    => false,
			'show_in_admin_status_list' => true,
			'show_in_admin_all_list'    => true,
		] );

		register_post_meta( 'essf_cashflow', '_order_date', [ 'type' => 'string', 'single' => true ] );
	}

	public static function activate() {
		( new self() )->register();
		flush_rewrite_rules();
	}
}
