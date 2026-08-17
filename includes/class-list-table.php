<?php
/**
 * EssFinance — Admin List Table
 *
 * @package EssFinance
 * @license GPL-2.0-or-later
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

class ESSF_List_Table extends WP_List_Table {

	/* @var string */
	private $page_url;

	/* @var array|null */
	private $filtered_totals = null;

	public function __construct() {
		parent::__construct(
			[
				'singular' => 'entry',
				'plural'   => 'entries',
				'ajax'     => false,
			]
		);
		$this->page_url = admin_url( 'admin.php?page=essfinance' );
	}

	/* ── Column definitions ─────────────────────────────── */

	public function get_columns(): array {
		return [
			'cb'            => '<input type="checkbox">',
			'description'   => __( 'Description', 'essfinance' ),
			'essf_type'     => __( 'Type', 'essfinance' ),
			'essf_category' => __( 'Category', 'essfinance' ),
			'essf_amount'   => __( 'Amount', 'essfinance' ),
			'essf_due'      => __( 'Date', 'essfinance' ),
			'essf_status'   => __( 'Status', 'essfinance' ),
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
			'mark_paid'         => __( 'Mark as Paid Today', 'essfinance' ),
			'mark_pending'      => __( 'Mark as Pending', 'essfinance' ),
			'make_income'       => __( 'Make Income', 'essfinance' ),
			'make_expense'      => __( 'Make Expense', 'essfinance' ),
			'set_category'      => __( 'Set Category', 'essfinance' ),
			'auto_set_category' => __( 'Auto Set Category', 'essfinance' ),
			'delete'            => __( 'Delete', 'essfinance' ),
		];
	}

	/* ── Status tabs (views) ────────────────────────────── */

	public function get_views(): array {
		$current  = isset( $_REQUEST['essf_status'] ) ? sanitize_key( $_REQUEST['essf_status'] ) : '';
		$base_url = $this->page_url;
		$today    = (string) date_i18n( 'Y-m-d' );

		$all_statuses = [ 'pending', 'paid' ];

		$counts    = wp_count_posts( 'essf_cashflow' );
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

		$overdue_count = count(
			get_posts(
				[
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
				]
			)
		);

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
				$url              = add_query_arg( 'essf_status', $status, $base_url );
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
		$current_m    = isset( $_REQUEST['m'] ) ? sanitize_key( $_REQUEST['m'] ) : '';
		$current_cat  = isset( $_REQUEST['category_name'] ) ? sanitize_key( $_REQUEST['category_name'] ) : '';
		?>
		<div class="alignleft actions">
			<?php if ( ESSF_Settings::show_type_filter() ) : ?>
				<label for="essf_type" class="screen-reader-text"><?php esc_html_e( 'Filter by type', 'essfinance' ); ?></label>
				<select name="essf_type" id="essf_type">
					<option value=""><?php esc_html_e( 'All types', 'essfinance' ); ?></option>
					<option value="income" <?php selected( $current_type, 'income' ); ?>><?php esc_html_e( 'Income', 'essfinance' ); ?></option>
					<option value="expense" <?php selected( $current_type, 'expense' ); ?>><?php esc_html_e( 'Expense', 'essfinance' ); ?></option>
				</select>
			<?php endif; ?>

			<label for="category_name" class="screen-reader-text"><?php esc_html_e( 'Filter by category', 'essfinance' ); ?></label>
			<select name="category_name" id="category_name">
				<option value=""><?php esc_html_e( 'All categories', 'essfinance' ); ?></option>
				<?php foreach ( ESSF_Category::get_ordered_terms() as $term ) : ?>
					<option value="<?php echo esc_attr( $term->slug ); ?>" <?php selected( $current_cat, $term->slug ); ?>>
						<?php echo esc_html( $term->name ); ?>
					</option>
				<?php endforeach; ?>
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
		<label for="m" class="screen-reader-text"><?php esc_html_e( 'Filter by month', 'essfinance' ); ?></label>
		<select name="m" id="m">
			<option value="-1" <?php selected( in_array( $current, [ '', '-1' ], true ) ); ?>><?php esc_html_e( 'All months', 'essfinance' ); ?></option>
			<?php foreach ( $months as $ym => $label ) : ?>
				<option value="<?php echo esc_attr( $ym ); ?>" <?php selected( $current, $ym ); ?>>
					<?php echo esc_html( $label ); ?>
				</option>
			<?php endforeach; ?>
		</select>
		<?php
	}

	private function entry_order_date( WP_Post $post ): string {
		$od = get_post_meta( $post->ID, '_order_date', true );
		if ( $od && '0000-00-00' !== $od ) {
			return $od;
		}
		$pay = substr( $post->post_modified_gmt, 0, 10 );
		$due = substr( $post->post_date_gmt, 0, 10 );
		$od  = ( 'paid' === $post->post_status && $pay && '0000-00-00' !== $pay ) ? $pay : $due;
		if ( $od && '0000-00-00' !== $od ) {
			update_post_meta( $post->ID, '_order_date', $od );
		}
		return ( $od && '0000-00-00' !== $od ) ? $od : '';
	}

	private function get_due_date_months(): array {
		$posts = get_posts(
			[
				'post_type'      => 'essf_cashflow',
				'post_status'    => [ 'pending', 'paid' ],
				'posts_per_page' => -1,
			]
		);

		$months = [];
		foreach ( $posts as $post ) {
			$od = $this->entry_order_date( $post );
			if ( ! $od || '0000-00-00' === $od ) {
				continue;
			}
			$ts            = strtotime( $od );
			$ym            = (string) date_i18n( 'Ym', $ts );
			$months[ $ym ] = (string) date_i18n( 'F Y', $ts );
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
		$month_filter  = isset( $_REQUEST['m'] ) ? sanitize_key( $_REQUEST['m'] ) : '';
		if ( '-1' === $month_filter ) {
			$month_filter = ''; // "-1" is the explicit "All months" sentinel — treat like unset.
		}
		$cat_filter = isset( $_REQUEST['category_name'] ) ? sanitize_key( $_REQUEST['category_name'] ) : '';
		$orderby    = isset( $_REQUEST['orderby'] ) ? sanitize_key( $_REQUEST['orderby'] ) : '';
		$order      = isset( $_REQUEST['order'] ) && 'asc' === strtolower( sanitize_key( $_REQUEST['order'] ) ) ? 'ASC' : 'DESC';
		$today      = (string) date_i18n( 'Y-m-d' );

		if ( 'overdue' === $status_filter ) {
			$post_statuses = [ 'pending' ];
		} elseif ( $status_filter && array_key_exists( $status_filter, ESSF_CPT::labels() ) ) {
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

		if ( $search ) {
			$args['s'] = $search;
		}

		if ( 'overdue' === $status_filter ) {
			$args['date_query'] = [
				[
					'column' => 'post_date_gmt',
					'before' => $today,
				],
			];
		}

		if ( $type_filter || $month_filter ) {
			$args['posts_per_page'] = -1;
		}

		if ( $cat_filter ) {
			$args['tax_query'] = [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
				[
					'taxonomy' => ESSF_Category::TAXONOMY,
					'field'    => 'slug',
					'terms'    => [ $cat_filter ],
				],
			];
		}

		if ( 'essf_amount' === $orderby ) {
			$args['orderby'] = 'post_content';
			$args['order']   = $order;
		} elseif ( 'essf_due' === $orderby ) {
			$args['orderby'] = 'post_date_gmt';
			$args['order']   = $order;
		} else {
			$args['orderby']  = 'meta_value';
			$args['meta_key'] = '_order_date';
			$args['order']    = 'DESC';
		}

		$query = new WP_Query( $args );
		$posts = $query->posts;

		if ( ! ( $type_filter || $month_filter ) ) {
			$this->filtered_totals = ESSF_Totals::compute_from_args( $args );
		}

		if ( $type_filter || $month_filter ) {
			if ( $type_filter ) {
				$posts = array_values(
					array_filter(
						$posts,
						function ( $post ) use ( $type_filter ) {
							$amount = (float) $post->post_content;
							return 'income' === $type_filter ? $amount > 0 : $amount <= 0;
						}
					)
				);
			}
			if ( $month_filter && preg_match( '/^\d{6}$/', $month_filter ) ) {
				$ym_prefix = substr( $month_filter, 0, 4 ) . '-' . substr( $month_filter, 4, 2 ) . '-';
				$posts     = array_values(
					array_filter(
						$posts,
						function ( $post ) use ( $ym_prefix ) {
							$od = get_post_meta( $post->ID, '_order_date', true );
							if ( ! $od ) {
								$pay = substr( $post->post_modified_gmt, 0, 10 );
								$due = substr( $post->post_date_gmt, 0, 10 );
								$od  = ( 'paid' === $post->post_status && $pay && '0000-00-00' !== $pay ) ? $pay : $due;
							}
							return $od && 0 === strpos( $od, $ym_prefix );
						}
					)
				);
			}
			$this->filtered_totals = ESSF_Totals::compute_from_posts( $posts );

			$total  = count( $posts );
			$offset = ( $current_page - 1 ) * $per_page;
			$posts  = array_slice( $posts, $offset, $per_page );
		} else {
			$total = $query->found_posts;
		}

		$this->items = $posts;

		$this->set_pagination_args(
			[
				'total_items' => $total,
				'per_page'    => $per_page,
				'total_pages' => (int) ceil( $total / $per_page ),
			]
		);

		$this->_column_headers = [
			$this->get_columns(),
			get_hidden_columns( $this->screen ),
			$this->get_sortable_columns(),
			$this->get_default_primary_column_name(),
		];
	}

	/**
	 * Income/expense/net totals for the currently active filters. Only
	 * meaningful after prepare_items() has run.
	 */
	public function get_filtered_totals(): array {
		return $this->filtered_totals ?? [
			'income'  => 0.0,
			'expense' => 0.0,
			'net'     => 0.0,
			'count'   => 0,
		];
	}

	/* ── Column renderers ───────────────────────────────── */

	/** @param WP_Post $item */
	public function column_cb( $item ): string {
		return '<input type="checkbox" name="entries[]" value="' . esc_attr( (string) $item->ID ) . '">';
	}

	public function column_description( $item ): string {
		$amount = (float) $item->post_content;
		$is_inc = $amount > 0;

		$edit_url = add_query_arg(
			[
				'page'  => 'essfinance',
				'entry' => $item->ID,
			],
			admin_url( 'admin.php' )
		);

		$paid_today_url = wp_nonce_url(
			add_query_arg(
				[
					'action' => 'essf_paid_today',
					'entry'  => $item->ID,
				],
				admin_url( 'admin-post.php' )
			),
			'essf_paid_today_' . $item->ID
		);

		$paid_date_url = add_query_arg(
			[
				'page'       => 'essfinance',
				'entry'      => $item->ID,
				'essf_focus' => 'pay_date',
			],
			admin_url( 'admin.php' )
		);

		$toggle_url = wp_nonce_url(
			add_query_arg(
				[
					'action' => 'essf_toggle_type',
					'entry'  => $item->ID,
				],
				admin_url( 'admin-post.php' )
			),
			'essf_toggle_type_' . $item->ID
		);

		$delete_url = wp_nonce_url(
			add_query_arg(
				[
					'action' => 'essf_delete',
					'entry'  => $item->ID,
				],
				admin_url( 'admin-post.php' )
			),
			'essf_delete_' . $item->ID
		);

		$actions = [
			'edit'        => '<a href="' . esc_url( $edit_url ) . '">' . __( 'Edit', 'essfinance' ) . '</a>',
			'paid_today'  => '<a href="' . esc_url( $paid_today_url ) . '">' . __( 'Paid Today', 'essfinance' ) . '</a>',
			'paid_date'   => '<a href="' . esc_url( $paid_date_url ) . '">' . __( 'Paid Date', 'essfinance' ) . '</a>',
			'toggle_type' => $is_inc
				? '<a href="' . esc_url( $toggle_url ) . '">' . __( 'Make Expense', 'essfinance' ) . '</a>'
				: '<a href="' . esc_url( $toggle_url ) . '">' . __( 'Make Income', 'essfinance' ) . '</a>',
			'delete'      => '<a href="' . esc_url( $delete_url ) . '" class="submitdelete" onclick="return confirm(\'' . esc_js( __( 'Delete this entry? This cannot be undone.', 'essfinance' ) ) . '\')">' . __( 'Delete', 'essfinance' ) . '</a>',
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

	public function column_essf_category( $item ): string {
		$terms = wp_get_post_terms( $item->ID, ESSF_Category::TAXONOMY, [ 'fields' => 'names' ] );
		return esc_html( $terms[0] ?? __( 'Uncategorized', 'essfinance' ) );
	}

	public function column_essf_amount( $item ): string {
		$amount    = (float) $item->post_content;
		$is_inc    = $amount > 0;
		$sign      = $is_inc ? '+' : ( ESSF_Settings::show_negative_prefix() ? '−' : '' );
		$formatted = esc_html( $sign . ESSF_Settings::format_amount( $amount ) );

		if ( ESSF_Settings::show_amount_colors() ) {
			$cls = $is_inc ? 'essf-amount--income' : 'essf-amount--expense';
			return '<span class="' . esc_attr( $cls ) . '">' . $formatted . '</span>';
		}

		return $formatted;
	}

	public function column_essf_due( $item ): string {
		$pay = substr( $item->post_modified_gmt, 0, 10 );
		$due = substr( $item->post_date_gmt, 0, 10 );

		if ( $pay && '0000-00-00' !== $pay ) {
			return esc_html( (string) date_i18n( get_option( 'date_format' ), strtotime( $pay ) ) );
		}
		if ( $due && '0000-00-00' !== $due ) {
			return esc_html( (string) date_i18n( get_option( 'date_format' ), strtotime( $due ) ) );
		}
		return '—';
	}

	public function column_essf_status( $item ): string {
		$today  = (string) date_i18n( 'Y-m-d' );
		$status = $item->post_status;
		$due    = substr( $item->post_date_gmt, 0, 10 );

		if ( 'pending' === $status && $due && '0000-00-00' !== $due && $due < $today ) {
			$status = 'overdue';
		}

		$label = ESSF_CPT::status_label( $status );

		if ( ESSF_Settings::show_status_badge() ) {
			return '<span class="essf-badge essf-badge--' . esc_attr( $status ) . '">' . esc_html( $label ) . '</span>';
		}

		if ( ESSF_Settings::show_status_icons() ) {
			return ESSF_Settings::status_icon( $status ) . esc_html( $label );
		}

		return esc_html( $label );
	}
}
