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

	const META_AMOUNT      = '_essf_bill_amount';
	const META_CATEGORY    = '_essf_bill_category';
	const META_DUE_DAY     = '_essf_bill_due_day';
	const META_ACTIVE      = '_essf_bill_active';
	const META_RECURRENCE  = '_essf_bill_recurrence';
	const META_DUE_WEEKDAY = '_essf_bill_due_weekday';

	/**
	 * Optional placeholder tokens a bill's name may embed, substituted with
	 * the real due date when each occurrence's title is generated (see
	 * expand_title()). TOKEN_FULL_DATE must always be checked/replaced before
	 * TOKEN_YEAR_MONTH — it contains TOKEN_YEAR_MONTH as a literal substring,
	 * so the reverse order would corrupt it (e.g. "YYYY-MM-DD" would become
	 * "2026-08-DD" instead of "2026-08-14").
	 */
	const TOKEN_FULL_DATE  = 'YYYY-MM-DD';
	const TOKEN_YEAR_MONTH = 'YYYY-MM';

	/** How many past periods (months or weeks, depending on recurrence) render_history_table() shows. */
	const HISTORY_PERIODS = 6;

	/**
	 * Set only for the duration of create_term_from_detection() — lets
	 * save_term_fields() read inferred meta instead of $_POST when a bill is
	 * created programmatically by ESSF_Plan_Detector rather than through the
	 * native "Add New" term form.
	 */
	private static ?array $detection_meta = null;

	/**
	 * Creates a Bill term with the given meta already applied (bypassing
	 * $_POST — see $detection_meta above), used by ESSF_Plan_Detector for
	 * both the one-click backfill and the on-insert auto-detect hook.
	 * wp_insert_term() fires `created_essf_bill_cat` synchronously, which
	 * runs save_term_fields() then on_term_created() (immediate
	 * materialization of the current month) before this method returns.
	 *
	 * @param array $meta essf_bill_amount, essf_bill_is_income,
	 *                    essf_bill_category, essf_bill_due_day,
	 *                    essf_bill_active — same shape as the term form's $_POST.
	 */
	public static function create_term_from_detection( string $name, array $meta ): int {
		self::$detection_meta = $meta;
		$result               = wp_insert_term( $name, self::TAXONOMY );
		self::$detection_meta = null;
		return is_wp_error( $result ) ? 0 : (int) $result['term_id'];
	}

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
		$actions['view_history'] = '<a href="' . esc_url( ESSF_Recurrence_Entries_Page::url( self::TAXONOMY, $term->term_id ) ) . '">' . esc_html__( 'History', 'essfinance' ) . '</a>';
		$actions['view_entries'] = '<a href="' . esc_url( ESSF_CPT::cashflow_search_url( self::strip_tokens( $term->name ) ) ) . '">' . esc_html__( 'View entries', 'essfinance' ) . '</a>';
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
		<p class="description"><?php esc_html_e( 'Tip: the bill name above can optionally include YYYY-MM-DD or YYYY-MM as a placeholder — it will be replaced with the real due date (or year and month) each time an occurrence is generated, e.g. "Electricity YYYY-MM" becomes "Electricity 2026-08".', 'essfinance' ); ?></p>
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
			<label for="essf_bill_recurrence"><?php esc_html_e( 'Recurrence', 'essfinance' ); ?></label>
			<?php $this->render_recurrence_select( 'month' ); ?>
		</div>
		<div class="form-field">
			<label for="essf_bill_due_day"><?php esc_html_e( 'Due day of month (used when Recurrence = Monthly)', 'essfinance' ); ?></label>
			<input type="number" min="1" max="31" name="essf_bill_due_day" id="essf_bill_due_day" value="5">
		</div>
		<div class="form-field">
			<label for="essf_bill_due_weekday"><?php esc_html_e( 'Due day of week (used when Recurrence = Weekly)', 'essfinance' ); ?></label>
			<?php $this->render_due_weekday_select( 1 ); ?>
		</div>
		<div class="form-field">
			<label for="essf_bill_active"><?php esc_html_e( 'Active', 'essfinance' ); ?></label>
			<input type="checkbox" name="essf_bill_active" id="essf_bill_active" value="1" checked>
			<p><?php esc_html_e( 'Uncheck to stop generating new monthly occurrences for this bill.', 'essfinance' ); ?></p>
		</div>
		<?php
	}

	private function render_recurrence_select( string $selected ): void {
		$options = [
			'month' => __( 'Monthly', 'essfinance' ),
			'week'  => __( 'Weekly', 'essfinance' ),
		];
		echo '<select name="essf_bill_recurrence" id="essf_bill_recurrence">';
		foreach ( $options as $value => $label ) {
			printf( '<option value="%s"%s>%s</option>', esc_attr( $value ), selected( $selected, $value, false ), esc_html( $label ) );
		}
		echo '</select>';
	}

	private function render_due_weekday_select( int $selected ): void {
		echo '<select name="essf_bill_due_weekday" id="essf_bill_due_weekday">';
		for ( $n = 1; $n <= 7; $n++ ) {
			$dt = new DateTime();
			$dt->setISODate( (int) current_time( 'Y' ), 1, $n ); // ISO week 1 — only the weekday name is used
			printf( '<option value="%s"%s>%s</option>', esc_attr( (string) $n ), selected( $selected, $n, false ), esc_html( date_i18n( 'l', $dt->getTimestamp() ) ) );
		}
		echo '</select>';
	}

	/** @param WP_Term $term */
	public function render_edit_term_fields( $term ): void {
		$amount      = (float) get_term_meta( $term->term_id, self::META_AMOUNT, true );
		$category    = (string) get_term_meta( $term->term_id, self::META_CATEGORY, true );
		$recurrence  = self::get_recurrence( $term );
		$due_day     = self::get_due_day( $term );
		$due_weekday = self::get_due_weekday( $term );
		$active      = get_term_meta( $term->term_id, self::META_ACTIVE, true );
		$is_active   = '' === $active || (bool) $active;
		?>
		<tr class="form-field">
			<th scope="row"><?php esc_html_e( 'Tip', 'essfinance' ); ?></th>
			<td><p class="description"><?php esc_html_e( 'Tip: the bill name above can optionally include YYYY-MM-DD or YYYY-MM as a placeholder — it will be replaced with the real due date (or year and month) each time an occurrence is generated, e.g. "Electricity YYYY-MM" becomes "Electricity 2026-08".', 'essfinance' ); ?></p></td>
		</tr>
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
			<th scope="row"><label for="essf_bill_recurrence"><?php esc_html_e( 'Recurrence', 'essfinance' ); ?></label></th>
			<td><?php $this->render_recurrence_select( $recurrence ); ?></td>
		</tr>
		<tr class="form-field">
			<th scope="row"><label for="essf_bill_due_day"><?php esc_html_e( 'Due day of month (used when Recurrence = Monthly)', 'essfinance' ); ?></label></th>
			<td><input type="number" min="1" max="31" name="essf_bill_due_day" id="essf_bill_due_day" value="<?php echo esc_attr( (string) $due_day ); ?>"></td>
		</tr>
		<tr class="form-field">
			<th scope="row"><label for="essf_bill_due_weekday"><?php esc_html_e( 'Due day of week (used when Recurrence = Weekly)', 'essfinance' ); ?></label></th>
			<td><?php $this->render_due_weekday_select( $due_weekday ); ?></td>
		</tr>
		<tr class="form-field">
			<th scope="row"><label for="essf_bill_active"><?php esc_html_e( 'Active', 'essfinance' ); ?></label></th>
			<td><input type="checkbox" name="essf_bill_active" id="essf_bill_active" value="1" <?php checked( $is_active ); ?>></td>
		</tr>
		<tr class="form-field">
			<th scope="row"><?php esc_html_e( 'History', 'essfinance' ); ?></th>
			<td><a href="<?php echo esc_url( ESSF_Recurrence_Entries_Page::url( self::TAXONOMY, $term->term_id ) ); ?>"><?php esc_html_e( 'View history →', 'essfinance' ); ?></a></td>
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

	/* ── Recurrence/token helpers ───────────────────────────────── */

	/** 'month' (default) or 'week'. */
	private static function get_recurrence( WP_Term $term ): string {
		return 'week' === get_term_meta( $term->term_id, self::META_RECURRENCE, true ) ? 'week' : 'month';
	}

	private static function get_due_day( WP_Term $term ): int {
		return (int) get_term_meta( $term->term_id, self::META_DUE_DAY, true ) ?: 5;
	}

	/** 1 (Monday) .. 7 (Sunday), PHP date('N') convention. */
	private static function get_due_weekday( WP_Term $term ): int {
		$weekday = (int) get_term_meta( $term->term_id, self::META_DUE_WEEKDAY, true );
		return ( $weekday >= 1 && $weekday <= 7 ) ? $weekday : 1;
	}

	/**
	 * Substitutes optional YYYY-MM-DD/YYYY-MM placeholder tokens anywhere in
	 * a bill's name with the real due date, producing the actual entry
	 * title. A name with no tokens passes through unchanged.
	 */
	private static function expand_title( string $name, string $due_ymd ): string {
		$title = str_replace( self::TOKEN_FULL_DATE, $due_ymd, $name );
		$title = str_replace( self::TOKEN_YEAR_MONTH, substr( $due_ymd, 0, 7 ), $title );
		return $title;
	}

	/** Removes both placeholder tokens from a name, collapsing extra whitespace — used for search links. */
	private static function strip_tokens( string $name ): string {
		$stripped = str_replace( [ self::TOKEN_FULL_DATE, self::TOKEN_YEAR_MONTH ], '', $name );
		return trim( (string) preg_replace( '/\s+/', ' ', $stripped ) );
	}

	/**
	 * The start/end/due 'Y-m-d' dates of a bill's period, $periods_back
	 * periods before the current one (0 = current). For monthly bills this
	 * is a calendar month; for weekly bills, a Monday-Sunday ISO week.
	 * Shared by ensure_current_occurrence() (periods_back = 0) and
	 * render_history_table() (0..HISTORY_PERIODS-1), so both compute due
	 * dates identically.
	 *
	 * @return array{start:string,end:string,due:string}
	 */
	private static function period_bounds( WP_Term $term, int $periods_back = 0 ): array {
		if ( 'week' === self::get_recurrence( $term ) ) {
			$today_n       = (int) current_time( 'N' ); // 1 (Mon) .. 7 (Sun)
			$this_monday   = strtotime( current_time( 'Y-m-d' ) . ' -' . ( $today_n - 1 ) . ' days' );
			$target_monday = strtotime( gmdate( 'Y-m-d', $this_monday ) . ' -' . ( 7 * $periods_back ) . ' days' );

			$start = gmdate( 'Y-m-d', $target_monday );
			$end   = gmdate( 'Y-m-d', strtotime( $start . ' +6 days' ) );
			$due   = gmdate( 'Y-m-d', strtotime( $start . ' +' . ( self::get_due_weekday( $term ) - 1 ) . ' days' ) );

			return [
				'start' => $start,
				'end'   => $end,
				'due'   => $due,
			];
		}

		$target_ts     = strtotime( current_time( 'Y-m-01' ) . " -{$periods_back} months" );
		$days_in_month = (int) date_i18n( 't', $target_ts );
		$due_day       = min( self::get_due_day( $term ), $days_in_month );

		$year_month = date_i18n( 'Y-m', $target_ts );

		return [
			'start' => $year_month . '-01',
			'end'   => sprintf( '%s-%02d', $year_month, $days_in_month ),
			'due'   => sprintf( '%s-%02d', $year_month, $due_day ),
		];
	}

	/** @return array<int, array<string, mixed>> A date_query scoped to $bounds's period, matching $term's recurrence type. */
	private static function period_date_query( WP_Term $term, array $bounds ): array {
		if ( 'week' === self::get_recurrence( $term ) ) {
			return [
				[
					'column'    => 'post_date_gmt',
					'after'     => $bounds['start'] . ' 00:00:00',
					'before'    => $bounds['end'] . ' 23:59:59',
					'inclusive' => true,
				],
			];
		}
		return [
			[
				'column' => 'post_date_gmt',
				'year'   => (int) substr( $bounds['start'], 0, 4 ),
				'month'  => (int) substr( $bounds['start'], 5, 2 ),
			],
		];
	}

	/**
	 * Read-only "virtual" history — no stored link, just
	 * ESSF_CPT::find_entries_by_title() per period, same mechanism the
	 * generation sweep itself uses to decide what's already there.
	 *
	 * @param WP_Term $term
	 */
	public static function render_history_table( WP_Term $term ): void {
		$is_weekly     = 'week' === self::get_recurrence( $term );
		$period_header = $is_weekly ? __( 'Week', 'essfinance' ) : __( 'Month', 'essfinance' );

		echo '<table class="widefat striped" style="max-width:400px;">';
		echo '<thead><tr><th>' . esc_html( $period_header ) . '</th><th>' . esc_html__( 'Amount', 'essfinance' ) . '</th><th>' . esc_html__( 'Status', 'essfinance' ) . '</th></tr></thead><tbody>';

		for ( $i = 0; $i < self::HISTORY_PERIODS; $i++ ) {
			$bounds = self::period_bounds( $term, $i );
			$title  = self::expand_title( $term->name, $bounds['due'] );

			$label = $is_weekly
				? sprintf(
					/* translators: %s: the Monday date that starts the week, e.g. "Aug 17, 2026". */
					__( 'Week of %s', 'essfinance' ),
					date_i18n( get_option( 'date_format' ), strtotime( $bounds['start'] ) )
				)
				: date_i18n( 'F Y', strtotime( $bounds['start'] ) );

			$entries = ESSF_CPT::find_entries_by_title( $title, self::period_date_query( $term, $bounds ) );

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
		$via_detection = null !== self::$detection_meta;
		if ( $via_detection ) {
			$post = self::$detection_meta;
		} else {
			if ( ! isset( $_POST['essf_bill_amount'] ) || ! current_user_can( 'manage_options' ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- created_/edited_{taxonomy} already gates on core's own add-tag/update-tag nonce
				return;
			}
			$post = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- see above
		}

		$amount = abs( (float) $post['essf_bill_amount'] );
		$is_inc = isset( $post['essf_bill_is_income'] ) && '1' === $post['essf_bill_is_income'];
		update_term_meta( $term_id, self::META_AMOUNT, (string) ( $is_inc ? $amount : -$amount ) );

		$category = isset( $post['essf_bill_category'] ) ? sanitize_key( $post['essf_bill_category'] ) : 'bills';
		update_term_meta( $term_id, self::META_CATEGORY, $category );

		$due_day = isset( $post['essf_bill_due_day'] ) ? absint( $post['essf_bill_due_day'] ) : 5;
		update_term_meta( $term_id, self::META_DUE_DAY, min( 31, max( 1, $due_day ) ) );

		$recurrence = ( isset( $post['essf_bill_recurrence'] ) && 'week' === $post['essf_bill_recurrence'] ) ? 'week' : 'month';
		update_term_meta( $term_id, self::META_RECURRENCE, $recurrence );

		$due_weekday = isset( $post['essf_bill_due_weekday'] ) ? absint( $post['essf_bill_due_weekday'] ) : 1;
		update_term_meta( $term_id, self::META_DUE_WEEKDAY, min( 7, max( 1, $due_weekday ?: 1 ) ) );

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

		$bounds = self::period_bounds( $term, 0 );
		$title  = self::expand_title( $term->name, $bounds['due'] );

		$existing = ESSF_CPT::find_entries_by_title( $title, self::period_date_query( $term, $bounds ) );
		if ( $existing ) {
			return;
		}

		$amount   = (float) get_term_meta( $term->term_id, self::META_AMOUNT, true );
		$category = (string) get_term_meta( $term->term_id, self::META_CATEGORY, true ) ?: 'bills';

		ESSF_CPT::insert_entry( $title, $amount, $bounds['due'] . ' 00:00:00', '0000-00-00 00:00:00', 'pending', $category, get_current_user_id() );
	}
}
