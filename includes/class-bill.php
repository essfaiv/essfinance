<?php
/**
 * EssFinance — Recurring bills (taxonomy)
 *
 * @package EssFinance
 * @license GPL-2.0-or-later
 */

defined( 'ABSPATH' ) || exit;

/**
 * A bill "series" (e.g. "Electricity") is a term of `essf_bill_cat` — not a
 * generic category, the term *is* the recurring bill; its default
 * amount/category/due day/active flag live as term meta. There is no
 * per-month marker post — a month's occurrence is just an ordinary
 * `essf_cashflow` entry whose description matches the term's name exactly,
 * discovered at display/generation time via ESSF_CPT::find_entries_by_title()
 * (description + due date is treated as an exclusive-enough key), the same
 * way ESSF_Loan_CPT/ESSF_Financing_CPT find "their" entries. Nothing is
 * stored beyond the plain cash flow entry itself.
 *
 * Only the current month is ever materialized ahead of time — a daily
 * WP-Cron sweep creates next month's occurrence once the calendar rolls
 * over, so a bill series spanning years never pre-creates years of future
 * entries.
 *
 * No dedicated admin page: `essf_bill_cat` is registered with `show_ui` and
 * accessed only via WordPress's native taxonomy screen (see
 * ESSF_Admin_Page::register_menus() for the submenu link) — the term's own
 * edit screen renders a read-only virtual history table (see
 * render_history_table()) since there's no post type left to list.
 */
class ESSF_Bill_CPT {

	const TAXONOMY = 'essf_bill_cat';

	/** Shared with ESSF_Financing_CPT — one daily sweep materializes both. */
	const CRON_HOOK = 'essf_recurrence_sweep';

	const META_AMOUNT   = '_essf_bill_amount';
	const META_CATEGORY = '_essf_bill_category';
	const META_DUE_DAY  = '_essf_bill_due_day';
	const META_ACTIVE   = '_essf_bill_active';

	/** How many past months render_history_table() shows. */
	const HISTORY_MONTHS = 6;

	public function __construct() {
		add_action( 'init', [ $this, 'register' ] );
		add_action( 'admin_menu', [ $this, 'register_menu' ] );

		add_action( self::TAXONOMY . '_add_form_fields', [ $this, 'render_add_term_fields' ] );
		add_action( self::TAXONOMY . '_edit_form_fields', [ $this, 'render_edit_term_fields' ] );
		add_action( 'created_' . self::TAXONOMY, [ $this, 'save_term_fields' ] );
		add_action( 'edited_' . self::TAXONOMY, [ $this, 'save_term_fields' ] );
		add_action( 'created_' . self::TAXONOMY, [ $this, 'on_term_created' ] );
		add_filter( self::TAXONOMY . '_row_actions', [ $this, 'row_actions' ], 10, 2 );

		add_action( self::CRON_HOOK, [ $this, 'sweep' ] );

		self::maybe_schedule_sweep();
	}

	public function register(): void {
		register_taxonomy(
			self::TAXONOMY,
			[], // No object type — this taxonomy is only ever used as a config/term-meta holder.
			[
				'labels'             => [
					'name'          => __( 'Bills', 'essfinance' ),
					'singular_name' => __( 'Bill', 'essfinance' ),
					'add_new_item'  => __( 'Add New Bill', 'essfinance' ),
					'new_item_name' => __( 'New Bill Name (e.g. Electricity)', 'essfinance' ),
					'all_items'     => __( 'All Bills', 'essfinance' ),
					'menu_name'     => __( 'Bills', 'essfinance' ),
				],
				'hierarchical'       => false,
				'public'             => false,
				'show_ui'            => true,
				'show_in_menu'       => false,
				'show_admin_column'  => false,
				'show_in_quick_edit' => false,
				'show_in_rest'       => false,
				'query_var'          => false,
				'rewrite'            => false,
			]
		);
	}

	public function register_menu(): void {
		add_submenu_page(
			'essfinance',
			__( 'Bills', 'essfinance' ),
			__( 'Bills', 'essfinance' ),
			'manage_options',
			'edit-tags.php?taxonomy=' . self::TAXONOMY
		);
	}

	/**
	 * @param array   $actions
	 * @param WP_Term $term
	 * @return array
	 */
	public function row_actions( $actions, $term ): array {
		$actions['view_entries'] = '<a href="' . esc_url( ESSF_CPT::cashflow_search_url( $term->name ) ) . '">' . esc_html__( 'View entries', 'essfinance' ) . '</a>';
		return $actions;
	}

	public static function maybe_schedule_sweep(): void {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time(), 'daily', self::CRON_HOOK );
		}
	}

	public static function deactivate(): void {
		wp_clear_scheduled_hook( self::CRON_HOOK );
	}

	/* ── Term fields (default amount/category/due day/active) ──────── */

	public function render_add_term_fields(): void {
		?>
		<div class="form-field">
			<label for="essf_bill_amount"><?php esc_html_e( 'Default amount', 'essfinance' ); ?></label>
			<input type="number" step="0.01" min="0" name="essf_bill_amount" id="essf_bill_amount" value="0.00">
			<p><?php esc_html_e( 'Suggested amount for each monthly occurrence — editable per month on the generated entry.', 'essfinance' ); ?></p>
		</div>
		<div class="form-field">
			<label for="essf_bill_is_income"><?php esc_html_e( 'Income', 'essfinance' ); ?></label>
			<input type="checkbox" name="essf_bill_is_income" id="essf_bill_is_income" value="1">
		</div>
		<div class="form-field">
			<label for="essf_bill_category"><?php esc_html_e( 'Category', 'essfinance' ); ?></label>
			<?php $this->render_category_select( '' ); ?>
		</div>
		<div class="form-field">
			<label for="essf_bill_due_day"><?php esc_html_e( 'Due day of month', 'essfinance' ); ?></label>
			<input type="number" min="1" max="31" name="essf_bill_due_day" id="essf_bill_due_day" value="5">
		</div>
		<div class="form-field">
			<label for="essf_bill_active"><?php esc_html_e( 'Active', 'essfinance' ); ?></label>
			<input type="checkbox" name="essf_bill_active" id="essf_bill_active" value="1" checked>
			<p><?php esc_html_e( 'Uncheck to stop generating new monthly occurrences for this bill.', 'essfinance' ); ?></p>
		</div>
		<?php
	}

	/** @param WP_Term $term */
	public function render_edit_term_fields( $term ): void {
		$amount    = (float) get_term_meta( $term->term_id, self::META_AMOUNT, true );
		$category  = (string) get_term_meta( $term->term_id, self::META_CATEGORY, true );
		$due_day   = (int) get_term_meta( $term->term_id, self::META_DUE_DAY, true ) ?: 5;
		$active    = get_term_meta( $term->term_id, self::META_ACTIVE, true );
		$is_active = '' === $active || (bool) $active;
		?>
		<tr class="form-field">
			<th scope="row"><label for="essf_bill_amount"><?php esc_html_e( 'Default amount', 'essfinance' ); ?></label></th>
			<td>
				<input type="number" step="0.01" min="0" name="essf_bill_amount" id="essf_bill_amount" value="<?php echo esc_attr( (string) abs( $amount ) ); ?>">
				<label style="margin-left:10px;"><input type="checkbox" name="essf_bill_is_income" value="1" <?php checked( $amount > 0 ); ?>> <?php esc_html_e( 'Income', 'essfinance' ); ?></label>
			</td>
		</tr>
		<tr class="form-field">
			<th scope="row"><label for="essf_bill_category"><?php esc_html_e( 'Category', 'essfinance' ); ?></label></th>
			<td><?php $this->render_category_select( $category ); ?></td>
		</tr>
		<tr class="form-field">
			<th scope="row"><label for="essf_bill_due_day"><?php esc_html_e( 'Due day of month', 'essfinance' ); ?></label></th>
			<td><input type="number" min="1" max="31" name="essf_bill_due_day" id="essf_bill_due_day" value="<?php echo esc_attr( (string) $due_day ); ?>"></td>
		</tr>
		<tr class="form-field">
			<th scope="row"><label for="essf_bill_active"><?php esc_html_e( 'Active', 'essfinance' ); ?></label></th>
			<td><input type="checkbox" name="essf_bill_active" id="essf_bill_active" value="1" <?php checked( $is_active ); ?>></td>
		</tr>
		<tr class="form-field">
			<th scope="row"><?php esc_html_e( 'Recent occurrences', 'essfinance' ); ?></th>
			<td><?php $this->render_history_table( $term ); ?></td>
		</tr>
		<?php
	}

	private function render_category_select( string $selected ): void {
		echo '<select name="essf_bill_category" id="essf_bill_category">';
		foreach ( ESSF_Category::get_ordered_terms() as $term ) {
			printf( '<option value="%s"%s>%s</option>', esc_attr( $term->slug ), selected( $selected, $term->slug, false ), esc_html( $term->name ) );
		}
		echo '</select>';
	}

	/**
	 * Read-only "virtual" history — no stored link, just
	 * ESSF_CPT::find_entries_by_title() per month, same mechanism the
	 * generation sweep itself uses to decide what's already there.
	 *
	 * @param WP_Term $term
	 */
	private function render_history_table( WP_Term $term ): void {
		echo '<table class="widefat striped" style="max-width:400px;">';
		echo '<thead><tr><th>' . esc_html__( 'Month', 'essfinance' ) . '</th><th>' . esc_html__( 'Amount', 'essfinance' ) . '</th><th>' . esc_html__( 'Status', 'essfinance' ) . '</th></tr></thead><tbody>';

		for ( $i = 0; $i < self::HISTORY_MONTHS; $i++ ) {
			$ts      = strtotime( current_time( 'Y-m-01' ) . " -{$i} months" );
			$year    = (int) date_i18n( 'Y', $ts );
			$month   = (int) date_i18n( 'n', $ts );
			$label   = date_i18n( 'F Y', $ts );
			$entries = ESSF_CPT::find_entries_by_title(
				$term->name,
				[
					[
						'column' => 'post_date_gmt',
						'year'   => $year,
						'month'  => $month,
					],
				]
			);

			echo '<tr>';
			echo '<td>' . esc_html( $label ) . '</td>';
			if ( $entries ) {
				$entry    = $entries[0];
				$edit_url = add_query_arg(
					[
						'page'  => 'essfinance',
						'entry' => $entry->ID,
					],
					admin_url( 'admin.php' )
				);
				echo '<td><a href="' . esc_url( $edit_url ) . '">' . esc_html( ESSF_Settings::format_amount( (float) $entry->post_content ) ) . '</a></td>';
				echo '<td>' . esc_html( ESSF_CPT::status_label( $entry->post_status ) ) . '</td>';
			} else {
				echo '<td>—</td><td><em>' . esc_html__( 'Not generated', 'essfinance' ) . '</em></td>';
			}
			echo '</tr>';
		}

		echo '</tbody></table>';
	}

	public function save_term_fields( int $term_id ): void {
		if ( ! isset( $_POST['essf_bill_amount'] ) || ! current_user_can( 'manage_options' ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- created_/edited_{taxonomy} already gates on core's own add-tag/update-tag nonce
			return;
		}
		$post = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- see above

		$amount = abs( (float) $post['essf_bill_amount'] );
		$is_inc = isset( $post['essf_bill_is_income'] ) && '1' === $post['essf_bill_is_income'];
		update_term_meta( $term_id, self::META_AMOUNT, (string) ( $is_inc ? $amount : -$amount ) );

		$category = isset( $post['essf_bill_category'] ) ? sanitize_key( $post['essf_bill_category'] ) : 'bills';
		update_term_meta( $term_id, self::META_CATEGORY, $category );

		$due_day = isset( $post['essf_bill_due_day'] ) ? absint( $post['essf_bill_due_day'] ) : 5;
		update_term_meta( $term_id, self::META_DUE_DAY, min( 31, max( 1, $due_day ) ) );

		$active = isset( $post['essf_bill_active'] ) && '1' === $post['essf_bill_active'];
		update_term_meta( $term_id, self::META_ACTIVE, $active ? '1' : '0' );
	}

	public function on_term_created( int $term_id ): void {
		$term = get_term( $term_id, self::TAXONOMY );
		if ( $term && ! is_wp_error( $term ) ) {
			$this->ensure_current_occurrence( $term );
		}
	}

	/* ── Generation ──────────────────────────────────────────────── */

	public function sweep(): void {
		$terms = get_terms(
			[
				'taxonomy'   => self::TAXONOMY,
				'hide_empty' => false,
			]
		);
		if ( is_wp_error( $terms ) ) {
			return;
		}
		foreach ( $terms as $term ) {
			$this->ensure_current_occurrence( $term );
		}
	}

	/**
	 * Materializes the current month's occurrence for a bill series, if it
	 * doesn't exist yet. Idempotent — safe to call from both the
	 * `created_{taxonomy}` hook (immediate feedback on creation) and the
	 * daily sweep (catch-up on month rollover). Existence is always checked
	 * fresh via find_entries_by_title() — no cursor to go stale.
	 */
	private function ensure_current_occurrence( WP_Term $term ): void {
		$active = get_term_meta( $term->term_id, self::META_ACTIVE, true );
		if ( '0' === $active ) {
			return;
		}

		$current_ym = current_time( 'Y-m' );

		$existing = ESSF_CPT::find_entries_by_title(
			$term->name,
			[
				[
					'column' => 'post_date_gmt',
					'year'   => (int) substr( $current_ym, 0, 4 ),
					'month'  => (int) substr( $current_ym, 5, 2 ),
				],
			]
		);
		if ( $existing ) {
			return;
		}

		$due_day       = (int) get_term_meta( $term->term_id, self::META_DUE_DAY, true ) ?: 5;
		$days_in_month = (int) date_i18n( 't', strtotime( $current_ym . '-01' ) );
		$due_date      = sprintf( '%s-%02d', $current_ym, min( $due_day, $days_in_month ) );

		$amount   = (float) get_term_meta( $term->term_id, self::META_AMOUNT, true );
		$category = (string) get_term_meta( $term->term_id, self::META_CATEGORY, true ) ?: 'bills';

		ESSF_CPT::insert_entry( $term->name, $amount, $due_date . ' 00:00:00', '0000-00-00 00:00:00', 'pending', $category );
	}
}
