<?php
/**
 * EssFinance — Custom Post Type
 *
 * @package EssFinance
 * @license GPL-2.0-or-later
 */

defined( 'ABSPATH' ) || exit;

class ESSF_CPT {

	public static $statuses = [
		'pending' => 'Pending',
		'paid'    => 'Paid',
	];

	public function __construct() {
		add_action( 'init', [ $this, 'register' ] );
		add_action( 'pre_get_posts', [ $this, 'default_admin_post_status' ] );
	}

	/**
	 * `essf_cashflow` entries never use WordPress's default 'publish' status
	 * (only the custom 'pending'/'paid' — see $statuses), but WP_Query
	 * defaults to 'publish' whenever a query doesn't set post_status
	 * explicitly. Every query this plugin's own admin/frontend code builds
	 * already passes post_status explicitly and is unaffected — this only
	 * fills the gap for WordPress's own native `edit.php` fallback screen
	 * (including its taxonomy/term-filtered views), which doesn't know about
	 * this post type's custom statuses and would otherwise always show zero
	 * results.
	 */
	public function default_admin_post_status( WP_Query $query ): void {
		if ( ! is_admin() || ! $query->is_main_query() ) {
			return;
		}
		if ( 'essf_cashflow' !== $query->get( 'post_type' ) ) {
			return;
		}
		if ( '' !== $query->get( 'post_status' ) ) {
			return; // Don't override an explicit filter (e.g. the Trash view).
		}

		$query->set( 'post_status', array_keys( self::$statuses ) );
	}

	public function register() {
		register_post_type(
			'essf_cashflow',
			[
				'labels'       => [
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
			]
		);

		register_post_status(
			'pending',
			[
				'label'                     => __( 'Pending', 'essfinance' ),
				'public'                    => false,
				'show_in_admin_status_list' => true,
				'show_in_admin_all_list'    => true,
			]
		);

		register_post_status(
			'paid',
			[
				'label'                     => __( 'Paid', 'essfinance' ),
				'public'                    => false,
				'show_in_admin_status_list' => true,
				'show_in_admin_all_list'    => true,
			]
		);

		register_post_meta(
			'essf_cashflow',
			'_order_date',
			[
				'type'   => 'string',
				'single' => true,
			]
		);
	}

	public static function activate() {
		( new self() )->register();
		flush_rewrite_rules();
		ESSF_Shortcodes::create_pages();
	}
}
