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
	const META_FIXED_VALUE = '_essf_bill_fixed_value';

	const LAUNCH_ACTION = 'essf_bill_launch';
	const LAUNCH_NONCE  = 'essf_bill_launch_nonce';

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
		add_action( 'admin_post_' . self::LAUNCH_ACTION, [ $this, 'handle_launch' ] );

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
			<label for="essf_bill_fixed_value"><?php esc_html_e( 'Fixed value', 'essfinance' ); ?></label>
			<input type="checkbox" name="essf_bill_fixed_value" id="essf_bill_fixed_value" value="1">
			<p><?php esc_html_e( 'When checked, the "Launch Next Occurrence" amount field is pre-filled with the last real occurrence\'s amount instead of starting blank — useful when the value never changes (e.g. weekly cleaning); leave unchecked when it varies (e.g. electricity).', 'essfinance' ); ?></p>
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
		$fixed_value = '1' === get_term_meta( $term->term_id, self::META_FIXED_VALUE, true );
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
			<th scope="row"><label for="essf_bill_fixed_value"><?php esc_html_e( 'Fixed value', 'essfinance' ); ?></label></th>
			<td>
				<input type="checkbox" name="essf_bill_fixed_value" id="essf_bill_fixed_value" value="1" <?php checked( $fixed_value ); ?>>
				<p class="description"><?php esc_html_e( 'When checked, the "Launch Next Occurrence" amount field is pre-filled with the last real occurrence\'s amount instead of starting blank — useful when the value never changes (e.g. weekly cleaning); leave unchecked when it varies (e.g. electricity).', 'essfinance' ); ?></p>
			</td>
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

	/** Whether a bill's name embeds either placeholder token. */
	private static function has_token( string $name ): bool {
		return false !== strpos( $name, self::TOKEN_FULL_DATE ) || false !== strpos( $name, self::TOKEN_YEAR_MONTH );
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
	 * A regex matching any title expand_title() could have produced for this
	 * bill's name — the literal name with each placeholder token's position
	 * swapped for a digit-shaped pattern (YYYY-MM-DD/YYYY-MM), everything
	 * else preg_quote()'d. Same "escape then substitute the escaped needle"
	 * approach as expand_title()'s own token handling.
	 *
	 * A name with neither token additionally tolerates an optional trailing
	 * date-shaped suffix ("Electricity" also matches "Electricity 2026-07")
	 * — real-world entries are often hand-typed with a date appended even
	 * when the bill's own name was never configured with a token, and
	 * without this a bill in that state would silently lose visibility into
	 * its own history (see find_last_entries()). Still anchored otherwise —
	 * "Electricity Backup Battery" or any other non-date-shaped trailing
	 * text keeps failing to match, exactly as before.
	 */
	private static function name_match_pattern( string $name ): string {
		$has_token = self::has_token( $name );

		$pattern = preg_quote( $name, '/' );
		$pattern = str_replace( preg_quote( self::TOKEN_FULL_DATE, '/' ), '\d{4}-\d{2}-\d{2}', $pattern );
		$pattern = str_replace( preg_quote( self::TOKEN_YEAR_MONTH, '/' ), '\d{4}-\d{2}', $pattern );

		$suffix = $has_token ? '' : '(\s+\d{4}(-\d{2}(-\d{2})?)?)?';

		return '/^' . $pattern . $suffix . '$/u';
	}

	/**
	 * The most recent $limit real occurrences of this bill, newest first —
	 * no assumption about which calendar slot they landed in, so it stays
	 * correct even after a due date has drifted from the configured
	 * day/weekday (see next_due_date_weekly()). Same defensive shape as
	 * ESSF_Loan_CPT::find_own_entries(): a cheap fuzzy `s` search on the
	 * token-stripped name as a candidate pre-filter, confirmed against the
	 * exact expected pattern in PHP so an unrelated bill sharing a word
	 * never matches.
	 *
	 * @return WP_Post[]
	 */
	private static function find_last_entries( WP_Term $term, int $limit ): array {
		$candidates = get_posts(
			[
				'post_type'      => 'essf_cashflow',
				'post_status'    => [ 'pending', 'paid' ],
				'posts_per_page' => -1,
				's'              => self::strip_tokens( $term->name ),
				'orderby'        => 'date',
				'order'          => 'DESC',
			]
		);

		$pattern = self::name_match_pattern( $term->name );
		$matched = [];
		foreach ( $candidates as $post ) {
			if ( preg_match( $pattern, $post->post_title ) ) {
				$matched[] = $post;
				if ( count( $matched ) >= $limit ) {
					break;
				}
			}
		}
		return $matched;
	}

	/**
	 * Fallback for render_history_rows_monthly() when the period's exact-title
	 * lookup (ESSF_CPT::find_entries_by_title()) finds nothing and the bill's
	 * name has no token — same fuzzy-prefilter-then-regex-confirm shape as
	 * find_last_entries(), but additionally scoped to $bounds's date range so
	 * a match can't leak in from a different period. Never used by
	 * ensure_current_occurrence() — the cron sweep's create-or-skip decision
	 * stays on the strict exact match, unaffected by this.
	 */
	private static function find_entry_in_period_lenient( WP_Term $term, array $bounds ): ?WP_Post {
		$candidates = get_posts(
			[
				'post_type'      => 'essf_cashflow',
				'post_status'    => [ 'pending', 'paid' ],
				'posts_per_page' => -1,
				's'              => $term->name,
				'date_query'     => self::period_date_query( $term, $bounds ), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_date_query -- small taxonomy-adjacent table, cost is negligible
			]
		);

		$pattern = self::name_match_pattern( $term->name );
		foreach ( $candidates as $post ) {
			if ( preg_match( $pattern, $post->post_title ) ) {
				return $post;
			}
		}
		return null;
	}

	/** The single most recent real occurrence, or null if this bill has no history yet. */
	private static function find_last_entry( WP_Term $term ): ?WP_Post {
		return self::find_last_entries( $term, 1 )[0] ?? null;
	}

	/**
	 * The absolute amount of the most recent real occurrence, or null for a
	 * brand new bill with no history yet.
	 */
	private static function find_last_amount( WP_Term $term ): ?float {
		$last = self::find_last_entry( $term );
		return $last ? abs( (float) $last->post_content ) : null;
	}

	/**
	 * The next due date for a weekly bill, anchored to its own history
	 * rather than recomputed from today's calendar week: the last real
	 * occurrence's due date + 7 days, whatever weekday that lands on — so a
	 * one-off that happened on, say, a Saturday instead of the configured
	 * weekday keeps the series anchored to Saturdays going forward, rather
	 * than snapping back to the configured weekday. The configured due
	 * weekday (period_bounds()) is only ever used to seed the very first
	 * occurrence, before any real entry exists.
	 */
	private static function next_due_date_weekly( WP_Term $term ): string {
		$last = self::find_last_entry( $term );
		if ( $last ) {
			return gmdate( 'Y-m-d', strtotime( substr( $last->post_date_gmt, 0, 10 ) . ' +7 days' ) );
		}
		return self::period_bounds( $term, 0 )['due'];
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
		$period_header = $is_weekly ? __( 'Date', 'essfinance' ) : __( 'Month', 'essfinance' );

		echo '<table class="widefat striped" style="max-width:400px;">';
		echo '<thead><tr><th>' . esc_html( $period_header ) . '</th><th>' . esc_html__( 'Amount', 'essfinance' ) . '</th><th>' . esc_html__( 'Status', 'essfinance' ) . '</th></tr></thead><tbody>';

		if ( $is_weekly ) {
			self::render_history_rows_weekly( $term );
		} else {
			self::render_history_rows_monthly( $term );
		}

		echo '</tbody></table>';
	}

	/**
	 * Weekly bills anchor their next due date to the last real occurrence
	 * (see next_due_date_weekly()), so a fixed "last 6 calendar weeks" grid
	 * (like render_history_rows_monthly()'s) would drift out of sync with
	 * reality and show spurious "Not generated" rows. Instead this lists
	 * the last HISTORY_PERIODS real occurrences directly, each row showing
	 * its own actual date — no synthetic period slots.
	 */
	private static function render_history_rows_weekly( WP_Term $term ): void {
		$entries = self::find_last_entries( $term, self::HISTORY_PERIODS );
		if ( ! $entries ) {
			echo '<tr><td colspan="3"><em>' . esc_html__( 'Not generated', 'essfinance' ) . '</em></td></tr>';
			return;
		}

		foreach ( $entries as $entry ) {
			$date     = substr( $entry->post_date_gmt, 0, 10 );
			$edit_url = add_query_arg(
				[
					'page'  => 'essfinance',
					'entry' => $entry->ID,
				],
				admin_url( 'admin.php' )
			);
			echo '<tr>';
			echo '<td><a href="' . esc_url( $edit_url ) . '">' . esc_html( date_i18n( get_option( 'date_format' ), strtotime( $date ) ) ) . '</a></td>';
			echo '<td>' . esc_html( ESSF_Settings::format_amount( (float) $entry->post_content ) ) . '</td>';
			echo '<td>' . esc_html( ESSF_CPT::status_label( $entry->post_status ) ) . '</td>';
			echo '</tr>';
		}
	}

	/**
	 * Given the HISTORY_PERIODS periods' entries (index 0 = current period,
	 * oldest last, WP_Post|null), decides which leading (older) indices
	 * render_history_rows_monthly() should drop — everything older than the
	 * oldest period with a real entry is noise (the bill wasn't being
	 * tracked back then, see the "Eletricidade" case that prompted this: one
	 * real entry in the current month, five older calendar months that
	 * never had one). A gap *between* real entries, or the current period
	 * even if not yet generated, still carries a useful "you might be
	 * missing this one" signal and is kept as-is. A brand new bill with no
	 * history at all keeps only the current period — a single reminder row,
	 * mirroring render_history_rows_weekly()'s equivalent fallback.
	 *
	 * @param array<int, WP_Post|null> $entries
	 * @return array<int, WP_Post|null>
	 */
	private static function trim_leading_ungenerated( array $entries ): array {
		$oldest_with_entry = null;
		foreach ( $entries as $i => $entry ) {
			if ( $entry ) {
				$oldest_with_entry = $i;
			}
		}

		if ( null === $oldest_with_entry ) {
			return array_slice( $entries, 0, 1, true );
		}

		return array_slice( $entries, 0, $oldest_with_entry + 1, true );
	}

	/** Monthly bills use a "last HISTORY_PERIODS calendar months" grid, trimmed to drop months older than the bill's own earliest real entry — see trim_leading_ungenerated(). */
	private static function render_history_rows_monthly( WP_Term $term ): void {
		$periods = [];
		$entries = [];
		for ( $i = 0; $i < self::HISTORY_PERIODS; $i++ ) {
			$bounds  = self::period_bounds( $term, $i );
			$title   = self::expand_title( $term->name, $bounds['due'] );
			$matches = ESSF_CPT::find_entries_by_title( $title, self::period_date_query( $term, $bounds ) );
			$entry   = $matches[0] ?? null;
			if ( ! $entry && ! self::has_token( $term->name ) ) {
				$entry = self::find_entry_in_period_lenient( $term, $bounds );
			}

			$periods[ $i ] = $bounds;
			$entries[ $i ] = $entry;
		}

		foreach ( self::trim_leading_ungenerated( $entries ) as $i => $entry ) {
			$label = date_i18n( 'F Y', strtotime( $periods[ $i ]['start'] ) );

			echo '<tr>';
			echo '<td>' . esc_html( $label ) . '</td>';
			if ( $entry ) {
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
	}

	/**
	 * Manual "launch the next occurrence" form — Date + Amount, submitted to
	 * handle_launch(). Complements the daily cron sweep (which always uses
	 * the current period's computed due date and the static Default
	 * amount, with no chance to adjust either): here the operator picks the
	 * actual day and amount, and the title is always built via
	 * expand_title() server-side rather than typed by hand, so it's
	 * guaranteed to match what the history table/existence check expect.
	 *
	 * Rendered on ESSF_Recurrence_Entries_Page, a standalone admin page (not
	 * nested inside WordPress's native edit-tag <form>), so a plain <form>
	 * is safe here — unlike ESSF_Loan_CPT's "Add Payment" widget, no need
	 * to build/submit the form via JS.
	 */
	/**
	 * The date pre-filled/falling back into the "Launch Next Occurrence"
	 * form — for weekly bills, anchored to the last real occurrence (see
	 * next_due_date_weekly()); for monthly, the current calendar month's
	 * configured due day, unchanged.
	 */
	private static function default_launch_date( WP_Term $term ): string {
		return 'week' === self::get_recurrence( $term )
			? self::next_due_date_weekly( $term )
			: self::period_bounds( $term, 0 )['due'];
	}

	public static function render_launch_widget( WP_Term $term ): void {
		if ( '0' === get_term_meta( $term->term_id, self::META_ACTIVE, true ) ) {
			return;
		}

		$default_date = self::default_launch_date( $term );

		$prefill_amount = null;
		if ( '1' === get_term_meta( $term->term_id, self::META_FIXED_VALUE, true ) ) {
			$prefill_amount = self::find_last_amount( $term );
			if ( null === $prefill_amount ) {
				$default        = (float) get_term_meta( $term->term_id, self::META_AMOUNT, true );
				$prefill_amount = $default ? abs( $default ) : null;
			}
		}
		?>
		<h2><?php esc_html_e( 'Launch Next Occurrence', 'essfinance' ); ?></h2>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="<?php echo esc_attr( self::LAUNCH_ACTION ); ?>">
			<input type="hidden" name="term_id" value="<?php echo esc_attr( (string) $term->term_id ); ?>">
			<?php wp_nonce_field( self::LAUNCH_NONCE . '_' . $term->term_id ); ?>
			<p>
				<label><?php esc_html_e( 'Date', 'essfinance' ); ?>
					<input type="date" name="launch_date" value="<?php echo esc_attr( $default_date ); ?>" required>
				</label>
				<label><?php esc_html_e( 'Amount', 'essfinance' ); ?>
					<input type="number" step="0.01" min="0" name="launch_amount" value="<?php echo esc_attr( null !== $prefill_amount ? (string) $prefill_amount : '' ); ?>" placeholder="0.00" required>
				</label>
				<?php submit_button( __( 'Launch', 'essfinance' ), 'secondary', 'submit', false ); ?>
			</p>
		</form>
		<?php
	}

	public function handle_launch(): void {
		$term_id = absint( $_POST['term_id'] ?? 0 );
		check_admin_referer( self::LAUNCH_NONCE . '_' . $term_id );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized.', 'essfinance' ) );
		}

		$term = get_term( $term_id, self::TAXONOMY );
		if ( ! $term || is_wp_error( $term ) ) {
			wp_die( esc_html__( 'Bill not found.', 'essfinance' ) );
		}

		$post     = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified above via check_admin_referer()
		$date_raw = isset( $post['launch_date'] ) ? sanitize_text_field( $post['launch_date'] ) : '';
		$date     = preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date_raw ) ? $date_raw : self::default_launch_date( $term );

		$amount   = abs( (float) ( $post['launch_amount'] ?? 0 ) );
		$stored   = (float) get_term_meta( $term_id, self::META_AMOUNT, true );
		$signed   = $stored >= 0 ? $amount : -$amount;
		$category = (string) get_term_meta( $term_id, self::META_CATEGORY, true ) ?: 'bills';
		$title    = self::expand_title( $term->name, $date );

		ESSF_CPT::insert_entry( $title, $signed, $date . ' 00:00:00', '0000-00-00 00:00:00', 'pending', $category, get_current_user_id() );

		wp_safe_redirect( ESSF_Recurrence_Entries_Page::url( self::TAXONOMY, $term_id ) );
		exit;
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

		$fixed_value = isset( $post['essf_bill_fixed_value'] ) && '1' === $post['essf_bill_fixed_value'];
		update_term_meta( $term_id, self::META_FIXED_VALUE, $fixed_value ? '1' : '0' );
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

		if ( 'week' === self::get_recurrence( $term ) ) {
			$this->ensure_current_occurrence_weekly( $term );
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

	/**
	 * Weekly counterpart of ensure_current_occurrence() — the due date comes
	 * from next_due_date_weekly() (anchored to the last real occurrence,
	 * not a fixed calendar-week grid) rather than period_bounds(). Nothing
	 * is materialized until that date actually arrives, same "only ever
	 * materialize what's due now" spirit as the monthly path and
	 * ESSF_Financing_CPT::maybe_materialize_next().
	 */
	private function ensure_current_occurrence_weekly( WP_Term $term ): void {
		$due = self::next_due_date_weekly( $term );
		if ( $due > current_time( 'Y-m-d' ) ) {
			return;
		}

		$title      = self::expand_title( $term->name, $due );
		$date_query = [
			[
				'column' => 'post_date_gmt',
				'year'   => (int) substr( $due, 0, 4 ),
				'month'  => (int) substr( $due, 5, 2 ),
				'day'    => (int) substr( $due, 8, 2 ),
			],
		];

		$existing = ESSF_CPT::find_entries_by_title( $title, $date_query );
		if ( $existing ) {
			return;
		}

		$amount   = (float) get_term_meta( $term->term_id, self::META_AMOUNT, true );
		$category = (string) get_term_meta( $term->term_id, self::META_CATEGORY, true ) ?: 'bills';

		ESSF_CPT::insert_entry( $title, $amount, $due . ' 00:00:00', '0000-00-00 00:00:00', 'pending', $category, get_current_user_id() );
	}
}
