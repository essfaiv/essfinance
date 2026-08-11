<?php
/**
 * EssFinance Core - Cash Flow Management
 *
 * Data Structure:
 * - post_title: Description
 * - post_date_gmt: Due Date (YYYY-MM-DD HH:MM:SS)
 * - post_modified_gmt: Pay Date (YYYY-MM-DD HH:MM:SS)
 * - post_content: Amount (numeric)
 * - post_status: pending|paid
 * - _order_date: Filter/sort key (YYYY-MM-DD)
 */

defined( 'ABSPATH' ) || exit;

/**
 * Format amount for storage/CSV: omit decimals if integer, convert .X0 to .X
 */
function essfinance_format_amount( $amount ) {
	$amount_float = (float) $amount;

	// If integer, return as integer string
	if ( $amount_float == (int) $amount_float ) {
		return (string) (int) $amount_float;
	}

	// Format to 2 decimals, then remove trailing zero if present
	$formatted = number_format( $amount_float, 2, '.', '' );

	// Convert .X0 to .X
	if ( substr( $formatted, -1 ) === '0' && substr( $formatted, -3, 1 ) !== '.' ) {
		$formatted = substr( $formatted, 0, -1 );
	}

	return $formatted;
}

/**
 * Format amount for display (listagem): BRL format with always 2 decimals, add +/- signs
 */
function essfinance_format_amount_display( $amount, $show_plus = true ) {
	$amount_float = (float) $amount;
	$abs_amount = abs( $amount_float );

	// Format as BRL: ponto para milhar, vírgula para decimal
	$formatted = number_format( $abs_amount, 2, ',', '.' );

	// Add + sign only for income (positive values)
	if ( $show_plus && $amount_float > 0 ) {
		return '+' . $formatted;
	}

	// Expenses (negative) show without sign
	return $formatted;
}

/**
 * Format amount for CSV/storage: remove sign, omit decimals if integer, convert .X0 to .X
 */
function essfinance_format_amount_csv( $amount ) {
	$amount_float = (float) $amount;

	// Format as integer or decimal without sign
	if ( $amount_float == (int) $amount_float ) {
		$formatted = (string) (int) $amount_float;
	} else {
		// Format to 2 decimals, then remove trailing zero if present
		$formatted = number_format( abs( $amount_float ), 2, '.', '' );
		// Convert .X0 to .X
		if ( substr( $formatted, -1 ) === '0' && substr( $formatted, -3, 1 ) !== '.' ) {
			$formatted = substr( $formatted, 0, -1 );
		}
	}

	// Add sign if negative
	if ( $amount_float < 0 ) {
	}

	return $formatted;
}

/**
 * Format date for display: uses WordPress date_format setting
 */
function essfinance_format_date( $date_str ) {
	if ( empty( $date_str ) || '0000-00-00' === $date_str ) {
		return '';
	}
	$timestamp = strtotime( $date_str );
	return date_i18n( get_option( 'date_format' ), $timestamp );
}

/**
 * Validate date format (YYYY-MM-DD or d/m/Y or m/d/Y based on format)
 */
function essfinance_validate_date( $date_str ) {
	if ( empty( $date_str ) ) {
		return true; // Empty dates are allowed
	}

	// Try YYYY-MM-DD format first (from HTML date input)
	if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date_str ) ) {
		$parts = explode( '-', $date_str );
		$year = (int) $parts[0];
		$month = (int) $parts[1];
		$day = (int) $parts[2];
	} else {
		return false; // Invalid format
	}

	// Validate month range
	if ( $month < 1 || $month > 12 ) {
		return false;
	}

	// Days in each month (non-leap year)
	$days_in_month = array( 31, 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31 );

	// Check for leap year
	if ( ( $year % 4 === 0 && $year % 100 !== 0 ) || ( $year % 400 === 0 ) ) {
		$days_in_month[1] = 29;
	}

	// Validate day range
	if ( $day < 1 || $day > $days_in_month[ $month - 1 ] ) {
		return false;
	}

	return true;
}

/**
 * Parse date from form input (handles d, m, y fields or HTML date input)
 */
function essfinance_parse_form_date( $date_input ) {
	if ( empty( $date_input ) ) {
		return '';
	}

	// If it's already in YYYY-MM-DD format (from HTML date input), just validate
	if ( is_string( $date_input ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date_input ) ) {
		if ( essfinance_validate_date( $date_input ) ) {
			return $date_input;
		}
		return '';
	}

	// Handle array input (day, month, year fields)
	if ( is_array( $date_input ) ) {
		$day = isset( $date_input['d'] ) ? (int) $date_input['d'] : 0;
		$month = isset( $date_input['m'] ) ? (int) $date_input['m'] : 0;
		$year = isset( $date_input['y'] ) ? (int) $date_input['y'] : 0;

		if ( $day && $month && $year ) {
			$date_str = sprintf( '%04d-%02d-%02d', $year, $month, $day );
			if ( essfinance_validate_date( $date_str ) ) {
				return $date_str;
			}
		}
	}

	return '';
}

add_action( 'init', 'essfinance_register_post_types' );
function essfinance_register_post_types() {
	register_post_type( 'essf_cashflow', array(
		'label'              => 'Cash Flow',
		'public'             => false,
		'show_ui'            => true,
		'show_in_menu'       => true,
		'supports'           => array( 'title', 'editor', 'custom-fields' ),
		'has_archive'        => false,
		'publicly_queryable' => false,
	) );

	register_post_status( 'pending', array(
		'label'                     => __( 'Pending', 'essfinance' ),
		'public'                    => false,
		'show_in_admin_status_list' => true,
		'show_in_admin_all_list'    => true,
	) );

	register_post_status( 'paid', array(
		'label'                     => __( 'Paid', 'essfinance' ),
		'public'                    => false,
		'show_in_admin_status_list' => true,
		'show_in_admin_all_list'    => true,
	) );

	register_post_meta( 'essf_cashflow', '_order_date', array( 'type' => 'string', 'single' => true ) );
}

// Fix "All" filter to show custom post status
add_filter( 'posts_where', 'essfinance_fix_all_filter' );
function essfinance_fix_all_filter( $where ) {
	global $wpdb, $typenow;

	if ( $typenow === 'essf_cashflow' && is_admin() ) {
		// Check if we're viewing the "All" filter (no post_status in query params)
		if ( ! isset( $_GET['post_status'] ) || '' === $_GET['post_status'] ) {
			// Include both custom statuses in the query
			$where .= " AND {$wpdb->posts}.post_status IN ('pending', 'paid')";
		}
	}

	return $where;
}

add_action( 'admin_menu', 'essfinance_admin_menu' );
function essfinance_admin_menu() {
	add_menu_page(
		'Finance',
		'Finance',
		'manage_options',
		'essfinance',
		'essfinance_page',
		'dashicons-chart-line',
		30
	);
}

function essfinance_page() {
	// Check for edit page BEFORE any output
	if ( isset( $_GET['action'] ) && 'edit' === $_GET['action'] && isset( $_GET['id'] ) ) {
		essfinance_render_edit_page();
		return;
	}

	$year  = isset( $_GET['year'] ) ? (int) $_GET['year'] : (int) date_i18n( 'Y', time(), true );
	$month = isset( $_GET['month'] ) ? (int) $_GET['month'] : (int) date_i18n( 'm', time(), true );

	$all_posts = get_posts( array(
		'post_type'   => 'essf_cashflow',
		'numberposts' => -1,
		'post_status' => array( 'pending', 'paid' ),
	) );

	$months = array();
	foreach ( $all_posts as $post ) {
		$order_date = get_post_meta( $post->ID, '_order_date', true );
		if ( $order_date ) {
			$m = substr( $order_date, 0, 7 );
			$months[ $m ] = $m;
		}
	}
	ksort( $months );

	$entries = get_posts( array(
		'post_type'   => 'essf_cashflow',
		'numberposts' => -1,
		'post_status' => array( 'pending', 'paid' ),
	) );

	$items = array();
	$today = date( 'Y-m-d' );

	foreach ( $entries as $post ) {
		$due_date = substr( $post->post_date_gmt, 0, 10 );
		$pay_date = '0000-00-00' !== substr( $post->post_modified_gmt, 0, 10 ) ? substr( $post->post_modified_gmt, 0, 10 ) : '';
		$amount = (float) $post->post_content;
		$order_date = get_post_meta( $post->ID, '_order_date', true );

		if ( $order_date && substr( $order_date, 0, 7 ) === sprintf( '%04d-%02d', $year, $month ) ) {
			$status = $post->post_status;
			if ( 'pending' === $status && $due_date < $today ) {
				$status = 'overdue';
			}

			// Infer type from amount sign: amount > 0 = income, amount <= 0 = expense
			$entry_type = $amount > 0 ? 'income' : 'expense';

			$items[] = array(
				'ID'         => $post->ID,
				'title'      => $post->post_title,
				'due_date'   => $due_date,
				'pay_date'   => $pay_date,
				'status'     => $status,
				'amount'     => $amount,
				'order_date' => $order_date,
				'entry_type' => $entry_type,
			);
		}
	}

	usort( $items, function( $a, $b ) {
		return strcmp( $a['order_date'], $b['order_date'] );
	} );

	?>
	<style>
		#essfinance-wrap { display: flex; gap: 30px; }
		#essfinance-form { flex: 0 0 350px; }
		#essfinance-list { flex: 1; min-width: 0; }
		.essfinance-desc-cell { vertical-align: top; }
		.essfinance-desc-text { display: block; margin-bottom: 6px; }
		.essfinance-actions {
			display: block;
			font-size: 12px;
			line-height: 1.6;
		}
		.essfinance-actions a {
			text-decoration: none;
			color: #0073aa;
			margin-right: 12px;
			opacity: 0;
			transition: opacity 0.2s ease;
		}
		.essfinance-actions a:hover {
			text-decoration: underline;
		}
		tr:hover .essfinance-actions a {
			opacity: 1;
		}
		.essfinance-bulk-actions {
			margin-bottom: 20px;
			display: flex;
			gap: 10px;
			align-items: center;
		}
		.essfinance-checkbox-column {
			width: 40px;
			text-align: center;
		}
	</style>

	<div class="wrap" id="essfinance-main">
		<h1>💰 Cash Flow</h1>

		<form method="get" style="margin-bottom: 20px;">
			<input type="hidden" name="page" value="essfinance" />
			<select name="month" id="month-select" style="padding: 8px 12px; font-size: 14px;">
				<?php foreach ( $months as $m ) {
					$label = date_i18n( 'F Y', strtotime( $m . '-01' ) );
					$selected = ( $m === sprintf( '%04d-%02d', $year, $month ) ) ? 'selected' : '';
					echo "<option value='$m' $selected>$label</option>";
				} ?>
			</select>
			<script>
				document.getElementById('month-select').addEventListener('change', function() {
					const m = this.value.split('-');
					window.location.href = '?page=essfinance&year=' + m[0] + '&month=' + m[1];
				});
			</script>
		</form>

		<!-- Bulk Actions -->
		<div class="essfinance-bulk-actions">
			<select id="bulk-action-select" style="padding: 8px 12px; font-size: 14px;">
				<option value="">Bulk Actions</option>
				<option value="mark_paid">Mark as Paid Today</option>
				<option value="mark_pending">Mark as Pending</option>
				<option value="delete">Delete</option>
			</select>
			<button type="button" id="bulk-action-apply" class="button" style="padding: 8px 12px; font-size: 14px;">Apply</button>
			<a href="<?php echo esc_url( wp_nonce_url( add_query_arg( array( 'page' => 'essfinance', 'action' => 'export_csv' ), admin_url( 'admin.php' ) ), 'essfinance_export_csv' ) ); ?>" class="button" style="padding: 8px 12px; font-size: 14px; margin-left: auto;">📥 Export CSV</a>
		</div>

		<script>
			document.getElementById('bulk-action-apply').addEventListener('click', function() {
				const action = document.getElementById('bulk-action-select').value;
				const checkboxes = document.querySelectorAll('.essfinance-entry-checkbox:checked');

				if (!action) {
					alert('Please select an action');
					return;
				}

				if (checkboxes.length === 0) {
					alert('Please select at least one entry');
					return;
				}

				if (action === 'delete' && !confirm('Are you sure you want to delete the selected entries?')) {
					return;
				}

				const ids = Array.from(checkboxes).map(cb => cb.value).join(',');
				const current_url = new URL(window.location);
				current_url.searchParams.set('bulk_action', action);
				current_url.searchParams.set('entry_ids', ids);
				window.location = current_url.toString();
			});
		</script>

		<div id="essfinance-wrap">
			<div id="essfinance-form">
				<div style="background: #f5f5f5; padding: 15px; border-radius: 4px;">
					<h2 style="margin-top: 0;">Add Entry</h2>
					<form method="post">
						<?php wp_nonce_field( 'essfinance_add_entry' ); ?>
						<input type="hidden" name="action" value="add_entry" />
						<table class="form-table" style="background: none; border: none;">
							<tr>
								<th><label for="title">Description</label></th>
								<td><input type="text" name="title" id="title" class="regular-text" required /></td>
							</tr>
							<tr>
								<th><label for="due_date">Due Date</label></th>
								<td><input type="date" name="due_date" id="due_date" /></td>
							</tr>
							<tr>
								<th><label for="pay_date">Pay Date</label></th>
								<td><input type="date" name="pay_date" id="pay_date" /></td>
							</tr>
							<tr>
								<th><label for="amount">Amount</label></th>
								<td>
									<div style="display: flex; gap: 8px; align-items: center;">
										<input type="number" name="amount" id="amount" step="0.01" min="0" required style="flex: 1;" />
										<label style="flex: 0 0 auto; white-space: nowrap;">
											<input type="checkbox" name="is_income_checkbox" value="1" id="is_income" />
											Income
										</label>
									</div>
									<input type="hidden" name="entry_type" id="entry_type_hidden" value="expense" />
								</td>
							</tr>
							<tr>
								<th><label for="status">Status</label></th>
								<td>
									<select name="status" id="status">
										<option value="pending">Pending</option>
										<option value="paid">Paid</option>
									</select>
								</td>
							</tr>
						</table>
						<script>
							document.getElementById('is_income').addEventListener('change', function() {
								document.getElementById('entry_type_hidden').value = this.checked ? 'income' : 'expense';
							});
						</script>
						<?php submit_button( 'Add Entry', 'primary', 'submit', true ); ?>
					</form>
				</div>
			</div>

			<div id="essfinance-list">
				<div style="margin-bottom: 20px;">
					<h2 style="margin-top: 0;">Entries for <?php echo date_i18n( 'F Y', strtotime( sprintf( '%04d-%02d-01', $year, $month ) ) ); ?></h2>
				</div>
				<table class="wp-list-table widefat fixed striped essfinance-table">
					<thead>
						<tr>
							<th class="essfinance-checkbox-column"><input type="checkbox" id="select-all-entries" /></th>
							<th>Description</th>
							<th width="120">Date</th>
							<th width="100">Status</th>
							<th width="120">Amount</th>
						</tr>
					</thead>
					<tbody>
						<?php if ( empty( $items ) ) { ?>
							<tr>
								<td colspan="5" style="text-align: center;">No entries</td>
							</tr>
						<?php } else {
							foreach ( $items as $item ) {
								$icon = $item['status'] === 'paid' ? 'yes-alt' : ($item['status'] === 'overdue' ? 'warning' : 'clock');
								$label = ucfirst( $item['status'] );
								$display_date = ! empty( $item['pay_date'] ) ? $item['pay_date'] : $item['due_date'];
								$formatted_date = essfinance_format_date( $display_date );
								$edit_url = add_query_arg( array( 'page' => 'essfinance', 'action' => 'edit', 'id' => $item['ID'], 'year' => $year, 'month' => $month ), admin_url( 'admin.php' ) );
								$paid_url = wp_nonce_url( add_query_arg( array( 'page' => 'essfinance', 'action' => 'paid', 'id' => $item['ID'] ), admin_url( 'admin.php' ) ), 'essfinance_paid' );
								$paid_date_url = add_query_arg( array( 'page' => 'essfinance', 'action' => 'edit', 'id' => $item['ID'], 'status' => 'paid', 'focus' => 'pay_date' ), admin_url( 'admin.php' ) );
								$delete_url = wp_nonce_url( add_query_arg( array( 'page' => 'essfinance', 'action' => 'delete_entry', 'id' => $item['ID'] ), admin_url( 'admin.php' ) ), 'essfinance_delete' );
								$amount_formatted = essfinance_format_amount_display( $item['amount'], true );
								?>
								<tr>
									<td class="essfinance-checkbox-column"><input type="checkbox" class="essfinance-entry-checkbox" value="<?php echo (int) $item['ID']; ?>" /></td>
									<td class="essfinance-desc-cell">
										<span class="essfinance-desc-text"><?php echo esc_html( $item['title'] ); ?></span>
										<div class="essfinance-actions">
											<a href="<?php echo esc_url( $edit_url ); ?>">Edit</a>
											<a href="<?php echo esc_url( $paid_url ); ?>">Paid Today</a>
											<a href="<?php echo esc_url( $paid_date_url ); ?>">Paid Date</a>
											<a href="<?php echo esc_url( $delete_url ); ?>" onclick="return confirm('Delete this entry?');">Delete</a>
										</div>
									</td>
									<td><?php echo esc_html( $formatted_date ); ?></td>
									<td><span class="dashicons dashicons-<?php echo esc_attr( $icon ); ?>" title="<?php echo esc_attr( $label ); ?>"></span> <?php echo esc_html( $label ); ?></td>
									<td><?php echo esc_html( $amount_formatted ); ?></td>
								</tr>
							<?php }
						} ?>
					</tbody>
				</table>
			</div>
		</div>

		<script>
			// Select all checkboxes
			document.getElementById('select-all-entries').addEventListener('change', function() {
				const checkboxes = document.querySelectorAll('.essfinance-entry-checkbox');
				checkboxes.forEach(cb => cb.checked = this.checked);
			});
		</script>
	</div>
	<?php
}

function essfinance_handle_form() {
	// This function is deprecated - form handling is now in admin_init hook
}


add_action( 'admin_init', function() {
	// Handle CSV export BEFORE any output
	if ( isset( $_GET['page'] ) && 'essfinance' === $_GET['page'] && isset( $_GET['action'] ) && 'export_csv' === $_GET['action'] ) {
		check_admin_referer( 'essfinance_export_csv' );

		// Get ALL entries (no month filter)
		$entries = get_posts( array(
			'post_type'   => 'essf_cashflow',
			'numberposts' => -1,
			'post_status' => array( 'pending', 'paid' ),
			'orderby'     => 'post_date_gmt',
			'order'       => 'ASC',
		) );

		// Build CSV data
		$csv_data = array();
		$csv_data[] = array( 'Description', 'Due Date', 'Pay Date', 'Status', 'Amount' );

		foreach ( $entries as $post ) {
			$due_date = substr( $post->post_date_gmt, 0, 10 );
			$pay_date = '0000-00-00' !== substr( $post->post_modified_gmt, 0, 10 ) ? substr( $post->post_modified_gmt, 0, 10 ) : '';
			$amount = (float) $post->post_content;
			$status = ucfirst( $post->post_status );

			// Format dates using WordPress settings
			$due_date_formatted = essfinance_format_date( $due_date );
			$pay_date_formatted = ! empty( $pay_date ) ? essfinance_format_date( $pay_date ) : '';

			// Format amount for CSV: omit .00, convert .X0 to .X, add - for expense
			$amount_csv = essfinance_format_amount_csv( $amount );

			$csv_data[] = array(
				$post->post_title,
				$due_date_formatted,
				$pay_date_formatted,
				$status,
				$amount_csv,
			);
		}

		// Set headers for download
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="essfinance-export-' . date( 'Y-m-d-His' ) . '.csv"' );

		// Output CSV
		$output = fopen( 'php://output', 'w' );
		foreach ( $csv_data as $row ) {
			fputcsv( $output, $row );
		}
		fclose( $output );
		exit;
	}

	// Handle POST form submissions BEFORE page rendering
	if ( isset( $_POST['action'] ) && isset( $_POST['_wpnonce'] ) ) {
		// ADD ENTRY
		if ( 'add_entry' === $_POST['action'] && check_admin_referer( 'essfinance_add_entry', '_wpnonce', false ) ) {
			$today = date_i18n( 'Y-m-d', current_time( 'timestamp' ) );

			// Due date: if empty, use today
			$due_date_input = sanitize_text_field( $_POST['due_date'] );
			if ( empty( $due_date_input ) ) {
				$due_date_input = $today;
			}

			// Validate date
			if ( ! essfinance_validate_date( $due_date_input ) ) {
				wp_die( 'Invalid due date format.' );
			}

			$due_date = $due_date_input . ' 00:00:00';

			// Pay date: check if due date is past and pay date is empty
			$pay_date_input = sanitize_text_field( $_POST['pay_date'] );
			if ( ! empty( $pay_date_input ) ) {
				if ( ! essfinance_validate_date( $pay_date_input ) ) {
					wp_die( 'Invalid pay date format.' );
				}
				$pay_date = $pay_date_input . ' 00:00:00';
			} else {
				$pay_date = '0000-00-00 00:00:00';
			}

			if ( '0000-00-00 00:00:00' === $pay_date && $due_date_input < $today ) {
				$pay_date = current_time( 'mysql', true );
			}

			$status = sanitize_text_field( $_POST['status'] );
			$entry_type = isset( $_POST['entry_type'] ) ? sanitize_text_field( $_POST['entry_type'] ) : 'expense';
			$amount_raw = (float) $_POST['amount'];

			// Validate amount is not zero
			if ( 0 === $amount_raw || 0.0 === $amount_raw ) {
				wp_die( 'Amount cannot be zero.' );
			}

			// Store: income as positive, expense as negative (omit decimals if integer)
			$amount_value = ( 'income' === $entry_type ) ? abs( $amount_raw ) : -abs( $amount_raw );
			$amount = essfinance_format_amount( $amount_value );

			if ( 'paid' === $status && '0000-00-00 00:00:00' === $pay_date ) {
				$pay_date = current_time( 'mysql', true );
			}

			$post_id = wp_insert_post( array(
				'post_type'         => 'essf_cashflow',
				'post_title'        => sanitize_text_field( $_POST['title'] ),
				'post_status'       => $status,
				'post_date_gmt'     => $due_date,
				'post_modified_gmt' => $pay_date,
				'post_content'      => (string) $amount,
			) );

			if ( $post_id ) {
				$order_date = '0000-00-00 00:00:00' !== $pay_date ? $pay_date : $due_date;
				update_post_meta( $post_id, '_order_date', substr( $order_date, 0, 10 ) );
			}

			// Redirect to essfinance page with current month
			wp_safe_redirect( admin_url( sprintf( 'admin.php?page=essfinance&year=%d&month=%02d', $year, $month ) ) );
			exit;
		}

		// UPDATE ENTRY
		if ( 'update_entry' === $_POST['action'] && check_admin_referer( 'essfinance_edit', '_wpnonce', false ) ) {
			$entry_id = (int) $_POST['entry_id'];
			$today = date_i18n( 'Y-m-d', current_time( 'timestamp' ) );

			// Get original entry to detect changes
			$original_entry = get_post( $entry_id );
			$original_due_date = substr( $original_entry->post_date_gmt, 0, 10 );

			// Due date: if empty, use today; otherwise use user input
			$due_date_input = sanitize_text_field( $_POST['due_date'] );
			if ( empty( $due_date_input ) ) {
				$due_date_input = $today;
			}

			// Validate date
			if ( ! essfinance_validate_date( $due_date_input ) ) {
				wp_die( 'Invalid due date format.' );
			}

			$due_date = $due_date_input . ' 00:00:00';

			// Pay date: respect the user's input
			$pay_date_input = sanitize_text_field( $_POST['pay_date'] );
			if ( ! empty( $pay_date_input ) ) {
				if ( ! essfinance_validate_date( $pay_date_input ) ) {
					wp_die( 'Invalid pay date format.' );
				}
				$pay_date = $pay_date_input . ' 00:00:00';
			} else {
				$pay_date = '0000-00-00 00:00:00';
			}

			// Only set pay_date to current time if status is being changed to paid AND pay_date is empty
			$status = sanitize_text_field( $_POST['status'] );
			if ( 'paid' === $status && '0000-00-00 00:00:00' === $pay_date ) {
				$pay_date = current_time( 'mysql', true );
			}

			// Format amount with sign based on entry_type
			$entry_type = isset( $_POST['entry_type'] ) ? sanitize_text_field( $_POST['entry_type'] ) : 'expense';
			$amount_raw = (float) $_POST['amount'];

			// Validate amount is not zero
			if ( 0 === $amount_raw || 0.0 === $amount_raw ) {
				wp_die( 'Amount cannot be zero.' );
			}

			// Store: income as positive, expense as negative (omit decimals if integer)
			$amount_value = ( 'income' === $entry_type ) ? abs( $amount_raw ) : -abs( $amount_raw );
			$amount = essfinance_format_amount( $amount_value );

			// Use wpdb to update post directly to avoid WordPress overriding post_date
			global $wpdb;
			$wpdb->update(
				$wpdb->posts,
				array(
					'post_title'        => sanitize_text_field( $_POST['title'] ),
					'post_status'       => $status,
					'post_date_gmt'     => $due_date,
					'post_modified_gmt' => $pay_date,
					'post_content'      => (string) $amount,
				),
				array( 'ID' => $entry_id ),
				array( '%s', '%s', '%s', '%s', '%s' ),
				array( '%d' )
			);

			$order_date = '0000-00-00 00:00:00' !== $pay_date ? $pay_date : $due_date;
			update_post_meta( $entry_id, '_order_date', substr( $order_date, 0, 10 ) );

			// Get year and month from query string to redirect back to same month
			$redirect_year = isset( $_GET['year'] ) ? (int) $_GET['year'] : date( 'Y' );
			$redirect_month = isset( $_GET['month'] ) ? (int) $_GET['month'] : date( 'm' );
			wp_safe_redirect( admin_url( sprintf( 'admin.php?page=essfinance&year=%d&month=%02d', $redirect_year, $redirect_month ) ) );
			exit;
		}
	}

	// Handle bulk actions BEFORE page rendering
	if ( isset( $_GET['page'] ) && 'essfinance' === $_GET['page'] && isset( $_GET['bulk_action'] ) && isset( $_GET['entry_ids'] ) ) {
		$bulk_action = sanitize_key( $_GET['bulk_action'] );
		$entry_ids = array_map( 'intval', explode( ',', sanitize_text_field( $_GET['entry_ids'] ) ) );

		if ( 'mark_paid' === $bulk_action ) {
			foreach ( $entry_ids as $entry_id ) {
				wp_update_post( array(
					'ID'                => $entry_id,
					'post_status'       => 'paid',
					'post_modified_gmt' => current_time( 'mysql', true ),
				) );
				$pay_date = current_time( 'mysql', true );
				update_post_meta( $entry_id, '_order_date', substr( $pay_date, 0, 10 ) );
			}
		} elseif ( 'mark_pending' === $bulk_action ) {
			foreach ( $entry_ids as $entry_id ) {
				wp_update_post( array(
					'ID'          => $entry_id,
					'post_status' => 'pending',
				) );
			}
		} elseif ( 'delete' === $bulk_action ) {
			foreach ( $entry_ids as $entry_id ) {
				wp_delete_post( $entry_id );
			}
		}

		wp_safe_redirect( remove_query_arg( array( 'bulk_action', 'entry_ids' ) ) );
		exit;
	}

	// Handle GET actions BEFORE page rendering
	if ( isset( $_GET['page'] ) && 'essfinance' === $_GET['page'] ) {
		// MARK AS PAID (Paid Today)
		if ( isset( $_GET['action'] ) && 'paid' === $_GET['action'] && isset( $_GET['id'] ) ) {
			check_admin_referer( 'essfinance_paid' );
			$entry_id = (int) $_GET['id'];
			wp_update_post( array(
				'ID'                => $entry_id,
				'post_status'       => 'paid',
				'post_modified_gmt' => current_time( 'mysql', true ),
			) );
			$pay_date = current_time( 'mysql', true );
			update_post_meta( $entry_id, '_order_date', substr( $pay_date, 0, 10 ) );
			wp_safe_redirect( remove_query_arg( array( 'action', 'id', '_wpnonce' ) ) );
			exit;
		}

		// DELETE ENTRY
		if ( isset( $_GET['action'] ) && 'delete_entry' === $_GET['action'] && isset( $_GET['id'] ) ) {
			check_admin_referer( 'essfinance_delete' );
			wp_delete_post( (int) $_GET['id'] );
			wp_safe_redirect( remove_query_arg( array( 'action', 'id', '_wpnonce' ) ) );
			exit;
		}
	}
} );

function essfinance_render_edit_page() {
	$id = (int) $_GET['id'];
	$post = get_post( $id );

	if ( ! $post || 'essf_cashflow' !== $post->post_type ) {
		return;
	}

	$status_param = isset( $_GET['status'] ) ? sanitize_key( $_GET['status'] ) : '';
	$focus_field = isset( $_GET['focus'] ) ? sanitize_key( $_GET['focus'] ) : '';
	$js_focus = ( 'pay_date' === $focus_field ) ? "document.getElementById('edit-pay').focus();" : '';

	// Determine which status to use: URL param if provided, else post status
	$display_status = $status_param ? $status_param : $post->post_status;
	// Infer type from amount sign: amount > 0 = income, amount <= 0 = expense
	$amount = (float) $post->post_content;
	$entry_type = $amount > 0 ? 'income' : 'expense';
	?>
	<!DOCTYPE html>
	<html>
	<head>
		<meta charset="UTF-8">
		<meta name="viewport" content="width=device-width, initial-scale=1.0">
		<title>Edit Entry</title>
		<?php wp_print_styles( 'common' ); ?>
		<style>
			body { margin: 0; padding: 40px 20px; background: #f1f1f1; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen-Sans, Ubuntu, Cantarell, sans-serif; }
			.wrap { max-width: 600px; margin: 0 auto; background: #fff; border-radius: 4px; padding: 30px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); }
			h1 { margin: 0 0 30px 0; font-size: 24px; color: #1d2327; }
			.form-table { width: 100%; border-collapse: collapse; }
			.form-table tr { border-bottom: 1px solid #f0f0f0; }
			.form-table th { text-align: right; padding: 10px 15px; width: 150px; font-weight: 600; color: #1d2327; }
			.form-table td { padding: 10px 15px; }
			.form-table input[type="text"],
			.form-table input[type="date"],
			.form-table input[type="number"],
			.form-table select { width: 100%; padding: 8px 10px; border: 1px solid #ddd; border-radius: 3px; font-size: 14px; box-sizing: border-box; }
			.form-table input[type="text"]:focus,
			.form-table input[type="date"]:focus,
			.form-table input[type="number"]:focus,
			.form-table select:focus { border-color: #0073aa; outline: none; box-shadow: 0 0 0 2px rgba(0,115,170,0.1); }
			.submit-buttons { display: flex; gap: 10px; justify-content: flex-end; margin-top: 25px; }
			.button { padding: 8px 16px; border-radius: 3px; border: 1px solid #ccc; background: #f6f7f7; color: #1d2327; text-decoration: none; font-size: 14px; cursor: pointer; transition: all 0.2s; }
			.button:hover { background: #f0f0f0; border-color: #999; }
			.button-primary { background: #0073aa; color: #fff; border-color: #005a87; }
			.button-primary:hover { background: #005a87; }
		</style>
	</head>
	<body>
		<div class="wrap">
			<h1>Edit Entry</h1>
			<form method="post" action="">
				<?php wp_nonce_field( 'essfinance_edit' ); ?>
				<input type="hidden" name="action" value="update_entry" />
				<input type="hidden" name="entry_id" value="<?php echo (int) $post->ID; ?>" />
				<?php if ( $status_param ) { ?>
					<input type="hidden" name="status_redirect" value="<?php echo esc_attr( $status_param ); ?>" />
				<?php } ?>
				<table class="form-table">
					<tr>
						<th><label for="edit-title">Description</label></th>
						<td><input type="text" name="title" id="edit-title" class="regular-text" value="<?php echo esc_attr( $post->post_title ); ?>" required /></td>
					</tr>
					<tr>
						<th><label for="edit-due">Due Date</label></th>
						<td><input type="date" name="due_date" id="edit-due" value="<?php echo esc_attr( substr( $post->post_date_gmt, 0, 10 ) ); ?>" /></td>
					</tr>
					<tr>
						<th><label for="edit-pay">Pay Date</label></th>
						<td><input type="date" name="pay_date" id="edit-pay" value="<?php echo esc_attr( '0000-00-00' === substr( $post->post_modified_gmt, 0, 10 ) ? '' : substr( $post->post_modified_gmt, 0, 10 ) ); ?>" /></td>
					</tr>
					<tr>
						<th><label for="edit-amount">Amount</label></th>
						<td>
							<div style="display: flex; gap: 8px; align-items: center;">
								<input type="number" name="amount" id="edit-amount" step="0.01" min="0" value="<?php echo esc_attr( abs( $post->post_content ) ); ?>" required style="flex: 1;" />
								<label style="flex: 0 0 auto; white-space: nowrap;">
									<input type="checkbox" name="is_income_checkbox" value="1" id="edit-is-income" <?php checked( $entry_type, 'income' ); ?> />
									Income
								</label>
							</div>
							<input type="hidden" name="entry_type" id="edit-entry-type-hidden" value="<?php echo esc_attr( $entry_type ); ?>" />
						</td>
					</tr>
					<tr>
						<th><label for="edit-status">Status</label></th>
						<td>
							<select name="status" id="edit-status">
								<option value="pending" <?php selected( $display_status, 'pending' ); ?>>Pending</option>
								<option value="paid" <?php selected( $display_status, 'paid' ); ?>>Paid</option>
							</select>
						</td>
					</tr>
				</table>
				<div class="submit-buttons">
					<a href="<?php echo esc_url( remove_query_arg( array( 'action', 'id', 'status', 'focus' ) ) ); ?>" class="button">Cancel</a>
					<button type="submit" class="button button-primary">Update</button>
				</div>
			</form>
		</div>
		<script>
			document.getElementById('edit-is-income').addEventListener('change', function() {
				document.getElementById('edit-entry-type-hidden').value = this.checked ? 'income' : 'expense';
			});
			<?php echo $js_focus; ?>
		</script>
	</body>
	</html>
	<?php
	exit;
}

if ( file_exists( ESSFINANCE_PATH . 'includes/modules/autoload.php' ) ) {
	require_once ESSFINANCE_PATH . 'includes/modules/autoload.php';
}
