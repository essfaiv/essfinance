<?php
/**
 * EssFinance — Category List Table
 *
 * @package EssFinance
 * @license GPL-2.0-or-later
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Read-only counterpart to the native taxonomy list table: same look
 * (search box, sortable columns, item count, pagination) but no "cb"
 * checkbox column, no bulk actions, and rows aren't edit links — see
 * ESSF_Admin_Page::render_categories_page() for why.
 */
class ESSF_Category_List_Table extends WP_List_Table {

	public function __construct() {
		parent::__construct(
			[
				'singular' => 'category',
				'plural'   => 'categories',
				'ajax'     => false,
			]
		);
	}

	public function get_columns(): array {
		return [
			'name'  => __( 'Name', 'essfinance' ),
			'slug'  => __( 'Slug', 'essfinance' ),
			'count' => __( 'Count', 'essfinance' ),
		];
	}

	public function get_sortable_columns(): array {
		return [
			'name'  => [ 'name', false ],
			'slug'  => [ 'slug', false ],
			'count' => [ 'count', false ],
		];
	}

	protected function get_default_primary_column_name(): string {
		return 'name';
	}

	public function no_items(): void {
		esc_html_e( 'No categories found.', 'essfinance' );
	}

	public function prepare_items(): void {
		$per_page     = $this->get_items_per_page( 'essf_categories_per_page', 20 );
		$current_page = $this->get_pagenum();
		$search       = isset( $_REQUEST['s'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['s'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$orderby      = isset( $_REQUEST['orderby'] ) ? sanitize_key( $_REQUEST['orderby'] ) : 'name'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$order        = isset( $_REQUEST['order'] ) && 'desc' === strtolower( sanitize_key( $_REQUEST['order'] ) ) ? 'DESC' : 'ASC'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( ! in_array( $orderby, [ 'name', 'slug', 'count' ], true ) ) {
			$orderby = 'name';
		}

		$terms = get_terms(
			[
				'taxonomy'   => ESSF_Category::TAXONOMY,
				'hide_empty' => false,
				'orderby'    => $orderby,
				'order'      => $order,
				'search'     => $search,
			]
		);
		$terms = is_wp_error( $terms ) ? [] : $terms;
		$total = count( $terms );

		$this->items = array_slice( $terms, ( $current_page - 1 ) * $per_page, $per_page );

		$this->set_pagination_args(
			[
				'total_items' => $total,
				'per_page'    => $per_page,
				'total_pages' => (int) ceil( $total / $per_page ),
			]
		);
	}

	/**
	 * @param WP_Term $item
	 */
	protected function column_default( $item, $column_name ) {
		switch ( $column_name ) {
			case 'name':
				return esc_html( $item->name );
			case 'slug':
				return esc_html( $item->slug );
			case 'count':
				return sprintf(
					'<a href="%s">%d</a>',
					esc_url( add_query_arg( 'essf_cat', $item->slug, admin_url( 'admin.php?page=essfinance' ) ) ),
					(int) $item->count
				);
			default:
				return '';
		}
	}
}
