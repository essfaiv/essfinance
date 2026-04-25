<?php
defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

class ESSF_List_Table extends WP_List_Table {

	private string $page_url;

	public function __construct() {
		parent::__construct( [
			'singular' => 'entry',
			'plural'   => 'entries',
			'ajax'     => false,
		] );
		$this->page_url = admin_url( 'admin.php?page=essfinance' );
	}

	/* ── Column definitions ─────────────────────────────── */

	public function get_columns(): array {
		return [
			'cb'          => '<input type="checkbox">',
			'description' => __( 'Description', 'essfinance' ),
			'essf_type'   => __( 'Type', 'essfinance' ),
			'essf_amount' => __( 'Amount', 'essfinance' ),
			'essf_due'    => __( 'Due Date', 'essfinance' ),
			'essf_status' => __( 'Status', 'essfinance' ),
		];
	}

	public function get_sortable_columns(): array {
		return [
			'essf_amount' => [ 'essf_amount', false ],
			'essf_due'    => [ 'essf_due', false ],
		];
	}

	protected function get_default_primary_column_name(): string {
		return 'description';
	}

	/* ── Bulk actions ───────────────────────────────────── */

	public function get_bulk_actions(): array {
		return [
			'delete' => __( 'Delete', 'essfinance' ),
		];
	}

	/* ── Status tabs (views) ────────────────────────────── */

	public function get_views(): array {
		$current  = isset( $_REQUEST['essf_status'] ) ? sanitize_key( $_REQUEST['essf_status'] ) : '';
		$base_url = $this->page_url;
		$today    = (string) wp_date( 'Y-m-d' );

		$all_statuses = [ 'pending', 'paid' ];

		$counts = wp_count_posts( 'essf_cashflow' );
		$all_count = 0;
		foreach ( $all_statuses as $s ) {
			$all_count += (int) ( $counts->$s ?? 0 );
		}

		$views = [];

		$views['all'] = sprintf(
			'<a href="%s"%s>%s <span class="count">(%d)</span></a>',
			esc_url( $base_url ),
			'' === $current ? ' class="current" aria-current="page"' : '',
			__( 'All', 'essfinance' ),
			$all_count
		);

		$pending_count = (int) ( $counts->pending ?? 0 );
		$paid_count    = (int) ( $counts->paid ?? 0 );

		$overdue_count = count( get_posts( [
			'post_type'      => 'essf_cashflow',
			'post_status'    => 'pending',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'date_query'     => [
				[
					'column' => 'post_date_gmt',
					'before' => $today,
				],
			],
		] ) );

		$status_counts = [
			'pending' => $pending_count,
			'paid'    => $paid_count,
			'overdue' => $overdue_count,
		];

		$status_labels = [
			'pending' => __( 'Pending', 'essfinance' ),
			'paid'    => __( 'Paid', 'essfinance' ),
			'overdue' => __( 'Overdue', 'essfinance' ),
		];

		foreach ( $status_labels as $status => $label ) {
			$count = $status_counts[ $status ];
			if ( $count > 0 ) {
				$url = add_query_arg( 'essf_status', $status, $base_url );
				$views[ $status ] = sprintf(
					'<a href="%s"%s>%s <span class="count">(%d)</span></a>',
					esc_url( $url ),
					$current === $status ? ' class="current" aria-current="page"' : '',
					esc_html( $label ),
					$count
				);
			}
		}

		return $views;
	}

	/* ── Extra filters (top nav) ────────────────────────── */

	protected function extra_tablenav( $which ): void {
		if ( 'top' !== $which ) {
			return;
		}
		$current_type = isset( $_REQUEST['essf_type'] ) ? sanitize_key( $_REQUEST['essf_type'] ) : '';
		$current_m    = isset( $_REQUEST['essf_m'] ) ? sanitize_key( $_REQUEST['essf_m'] ) : '';
		?>
		<div class="alignleft actions">
			<label for="essf_type" class="screen-reader-text"><?php esc_html_e( 'Filter by type', 'essfinance' ); ?></label>
			<select name="essf_type" id="essf_type">
				<option value=""><?php esc_html_e( 'All types', 'essfinance' ); ?></option>
				<option value="income" <?php selected( $current_type, 'income' ); ?>><?php esc_html_e( 'Income', 'essfinance' ); ?></option>
				<option value="expense" <?php selected( $current_type, 'expense' ); ?>><?php esc_html_e( 'Expense', 'essfinance' ); ?></option>
			</select>

			<?php $this->render_months_dropdown( $current_m ); ?>

			<input type="submit" class="button" value="<?php esc_attr_e( 'Filter', 'essfinance' ); ?>">
		</div>
		<?php
	}

	private function render_months_dropdown( string $current ): void {
		$months = $this->get_due_date_months();
		if ( empty( $months ) ) {
			return;
		}
		?>
		<label for="essf_m" class="screen-reader-text"><?php esc_html_e( 'Filter by month', 'essfinance' ); ?></label>
		<select name="essf_m" id="essf_m">
			<option value=""><?php esc_html_e( 'All months', 'essfinance' ); ?></option>
			<?php foreach ( $months as $ym => $label ) : ?>
				<option value="<?php echo esc_attr( $ym ); ?>" <?php selected( $current, $ym ); ?>>
					<?php echo esc_html( $label ); ?>
				</option>
			<?php endforeach; ?>
		</select>
		<?php
	}

	private function get_due_date_months(): array {
		$posts = get_posts( [
			'post_type'      => 'essf_cashflow',
			'post_status'    => [ 'pending', 'paid' ],
			'posts_per_page' => -1,
			'fields'         => 'ids',
		] );

		$months = [];
		foreach ( $posts as $id ) {
			$post = get_post( $id );
			$due  = substr( $post->post_date_gmt, 0, 10 );
			if ( ! $due || '0000-00-00' === $due ) {
				continue;
			}
			$ts    = strtotime( $due );
			$ym    = (string) wp_date( 'Ym', $ts );
			$label = (string) wp_date( 'F Y', $ts );
			$months[ $ym ] = $label;
		}

		krsort( $months );
		return $months;
	}

	/* ── Data preparation ───────────────────────────────── */

	public function prepare_items(): void {
		$per_page     = $this->get_items_per_page( 'essf_entries_per_page', 20 );
		$current_page = $this->get_pagenum();

		$search        = isset( $_REQUEST['s'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['s'] ) ) : '';
		$status_filter = isset( $_REQUEST['essf_status'] ) ? sanitize_key( $_REQUEST['essf_status'] ) : '';
		$type_filter   = isset( $_REQUEST['essf_type'] ) ? sanitize_key( $_REQUEST['essf_type'] ) : '';
		$month_filter  = isset( $_REQUEST['essf_m'] ) ? sanitize_key( $_REQUEST['essf_m'] ) : '';
		$orderby       = isset( $_REQUEST['orderby'] ) ? sanitize_key( $_REQUEST['orderby'] ) : '';
		$order         = isset( $_REQUEST['order'] ) && 'asc' === strtolower( sanitize_key( $_REQUEST['order'] ) ) ? 'ASC' : 'DESC';
		$today         = (string) wp_date( 'Y-m-d' );

		// Determine post_status for the query.
		if ( 'overdue' === $status_filter ) {
			$post_statuses = [ 'pending' ];
		} elseif ( $status_filter && array_key_exists( $status_filter, ESSF_CPT::$statuses ) ) {
			$post_statuses = [ $status_filter ];
		} else {
			$post_statuses = [ 'pending', 'paid' ];
		}

		$args = [
			'post_type'      => 'essf_cashflow',
			'post_status'    => $post_statuses,
			'posts_per_page' => $per_page,
			'paged'          => $current_page,
		];

		// Search by post_title.
		if ( $search ) {
			$args['s'] = $search;
		}

		// Overdue: pending entries with due date before today.
		if ( 'overdue' === $status_filter ) {
			$args['date_query'] = [
				[
					'column' => 'post_date_gmt',
					'before' => $today,
				],
			];
		}

		// Type filter: income = positive amount, expense = negative.
		// Handled post-query since post_content sign can't be filtered in WP_Query directly.
		// We fetch more and filter in PHP when type filter is active.
		if ( $type_filter ) {
			$args['posts_per_page'] = -1;
		}

		// Month filter by post_date_gmt.
		if ( $month_filter && preg_match( '/^\d{6}$/', $month_filter ) ) {
			$year  = (int) substr( $month_filter, 0, 4 );
			$month = (int) substr( $month_filter, 4, 2 );
			$args['date_query'] = array_merge( $args['date_query'] ?? [], [
				[
					'column' => 'post_date_gmt',
					'year'   => $year,
					'month'  => $month,
				],
			] );
		}

		// Sorting.
		if ( 'essf_amount' === $orderby ) {
			// Sort by post_content (numeric).
			$args['orderby'] = 'post_content';
			$args['order']   = $order;
		} elseif ( 'essf_due' === $orderby ) {
			$args['orderby'] = 'post_date_gmt';
			$args['order']   = $order;
		} else {
			$args['orderby'] = 'meta_value';
			$args['meta_key'] = '_order_date';
			$args['order']   = 'DESC';
		}

		$query = new WP_Query( $args );
		$posts = $query->posts;

		// Type filter in PHP.
		if ( $type_filter ) {
			$posts = array_values( array_filter( $posts, function( $post ) use ( $type_filter ) {
				$amount = (float) $post->post_content;
				return 'income' === $type_filter ? $amount > 0 : $amount <= 0;
			} ) );

			$total = count( $posts );
			$offset = ( $current_page - 1 ) * $per_page;
			$posts  = array_slice( $posts, $offset, $per_page );
		} else {
			$total = $query->found_posts;
		}

		$this->items = $posts;

		$this->set_pagination_args( [
			'total_items' => $total,
			'per_page'    => $per_page,
			'total_pages' => (int) ceil( $total / $per_page ),
		] );

		$this->_column_headers = [
			$this->get_columns(),
			get_hidden_columns( $this->screen ),
			$this->get_sortable_columns(),
			$this->get_default_primary_column_name(),
		];
	}

	/* ── Column renderers ───────────────────────────────── */

	public function column_cb( $item ): string {
		return '<input type="checkbox" name="entries[]" value="' . esc_attr( $item->ID ) . '">';
	}

	public function column_description( $item ): string {
		$edit_url   = add_query_arg( [ 'page' => 'essfinance', 'entry' => $item->ID ], admin_url( 'admin.php' ) );
		$delete_url = wp_nonce_url(
			add_query_arg( [ 'action' => 'essf_delete', 'entry' => $item->ID ], admin_url( 'admin-post.php' ) ),
			'essf_delete_' . $item->ID
		);

		$actions = [
			'edit'   => '<a href="' . esc_url( $edit_url ) . '">' . __( 'Edit', 'essfinance' ) . '</a>',
			'delete' => '<a href="' . esc_url( $delete_url ) . '" class="submitdelete" onclick="return confirm(\'' . esc_js( __( 'Delete this entry? This cannot be undone.', 'essfinance' ) ) . '\')">' . __( 'Delete', 'essfinance' ) . '</a>',
		];

		return '<strong><a href="' . esc_url( $edit_url ) . '">' . esc_html( $item->post_title ) . '</a></strong>'
			. $this->row_actions( $actions );
	}

	public function column_essf_type( $item ): string {
		$is_inc = (float) $item->post_content > 0;
		$cls    = $is_inc ? 'essf-badge--income' : 'essf-badge--expense';
		$lbl    = $is_inc ? __( 'Income', 'essfinance' ) : __( 'Expense', 'essfinance' );
		return '<span class="essf-badge ' . esc_attr( $cls ) . '">' . esc_html( $lbl ) . '</span>';
	}

	public function column_essf_amount( $item ): string {
		$amount    = (float) $item->post_content;
		$is_inc    = $amount > 0;
		$sign      = $is_inc ? '+' : ( ESSF_Settings::show_negative_prefix() ? '−' : '' );
		$formatted = esc_html( $sign . self::format_amount( $amount ) );

		if ( ESSF_Settings::show_amount_colors() ) {
			$cls = $is_inc ? 'essf-amount--income' : 'essf-amount--expense';
			return '<span class="' . esc_attr( $cls ) . '">' . $formatted . '</span>';
		}

		return $formatted;
	}

	public function column_essf_due( $item ): string {
		$d = substr( $item->post_date_gmt, 0, 10 );
		return ( $d && '0000-00-00' !== $d ) ? esc_html( (string) wp_date( 'd/m/Y', strtotime( $d ) ) ) : '—';
	}

	public function column_essf_status( $item ): string {
		$today  = (string) wp_date( 'Y-m-d' );
		$status = $item->post_status;
		$due    = substr( $item->post_date_gmt, 0, 10 );

		if ( 'pending' === $status && $due && '0000-00-00' !== $due && $due < $today ) {
			$status = 'overdue';
		}

		$label = ucfirst( $status );

		if ( ESSF_Settings::show_status_badge() ) {
			return '<span class="essf-badge essf-badge--' . esc_attr( $status ) . '">' . esc_html( $label ) . '</span>';
		}

		if ( ESSF_Settings::show_status_icons() ) {
			return ESSF_Settings::status_icon( $status ) . esc_html( $label );
		}

		return esc_html( $label );
	}

	/* ── Helpers ────────────────────────────────────────── */

	private static function format_amount( float $n ): string {
		return number_format( abs( $n ), 2, '.', ',' );
	}
}
