<?php
/**
 * EssFinance — Financing/installment plans (taxonomy)
 *
 * @package EssFinance
 * @license GPL-2.0-or-later
 */

defined( 'ABSPATH' ) || exit;

/**
 * A financing plan (e.g. "TV — 10x", "Pré-vestibular Filha — 12x") is a term
 * of `essf_financing_cat` — the term *is* the plan, same shape as
 * `essf_bill_cat`; its total/installment-count/interval/category live as
 * term meta. There is no per-plan post — each installment is just an
 * ordinary `essf_cashflow` entry titled `"{plan name} {index}/{n}"` (no
 * hidden token — that's plain, readable text matching the convention
 * bank-imported data already uses, e.g. "Pré-vestibular Filha 1/12"),
 * discovered via ESSF_CPT::find_entries_by_title(). Installments not yet
 * due stay purely virtual: computed on the fly (date, amount, "3/12") for
 * display, never written to the database until their turn comes.
 * Already-materialized and still-virtual installments are shown together
 * as one table on the term's own edit screen.
 *
 * No dedicated admin page: `essf_financing_cat` is registered with
 * `show_ui` and accessed only via WordPress's native taxonomy screen (see
 * register_menu()).
 */
class ESSF_Financing_CPT {

	const TAXONOMY = 'essf_financing_cat';

	/** Shared with ESSF_Bill_CPT — one daily sweep materializes both. */
	const CRON_HOOK = 'essf_recurrence_sweep';

	const META_TOTAL          = '_essf_financing_total';
	const META_INSTALLMENTS   = '_essf_financing_installments_total';
	const META_INTERVAL_UNIT  = '_essf_financing_interval_unit';
	const META_INTERVAL_COUNT = '_essf_financing_interval_count';
	const META_START          = '_essf_financing_start_date';
	const META_CATEGORY       = '_essf_financing_category';

	public function __construct() {
		add_action( 'init', [ $this, 'register' ] );
		add_action( 'admin_menu', [ $this, 'register_menu' ] );

		add_action( self::TAXONOMY . '_add_form_fields', [ $this, 'render_add_term_fields' ] );
		add_action( self::TAXONOMY . '_edit_form_fields', [ $this, 'render_edit_term_fields' ] );
		add_action( 'created_' . self::TAXONOMY, [ $this, 'save_term_fields' ] );
		add_action( 'edited_' . self::TAXONOMY, [ $this, 'save_term_fields' ] );
		add_filter( self::TAXONOMY . '_row_actions', [ $this, 'row_actions' ], 10, 2 );

		add_action( self::CRON_HOOK, [ $this, 'sweep' ] );

		ESSF_Bill_CPT::maybe_schedule_sweep();
	}

	public function register(): void {
		register_taxonomy(
			self::TAXONOMY,
			[], // No object type — this taxonomy is only ever used as a config/term-meta holder.
			[
				'labels'             => [
					'name'          => __( 'Financing Plans', 'essfinance' ),
					'singular_name' => __( 'Financing Plan', 'essfinance' ),
					'add_new_item'  => __( 'Add New Financing Plan', 'essfinance' ),
					'new_item_name' => __( 'New Financing Plan Name', 'essfinance' ),
					'all_items'     => __( 'All Financing Plans', 'essfinance' ),
					'menu_name'     => __( 'Financing', 'essfinance' ),
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
			__( 'Financing', 'essfinance' ),
			__( 'Financing', 'essfinance' ),
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

	/* ── Term fields ─────────────────────────────────────────────── */

	public function render_add_term_fields(): void {
		?>
		<div class="form-field">
			<label for="essf_financing_total"><?php esc_html_e( 'Total amount', 'essfinance' ); ?></label>
			<input type="number" step="0.01" min="0" name="essf_financing_total" id="essf_financing_total" value="0.00">
		</div>
		<div class="form-field">
			<label for="essf_financing_is_income"><?php esc_html_e( 'Income (unchecked = expense)', 'essfinance' ); ?></label>
			<input type="checkbox" name="essf_financing_is_income" id="essf_financing_is_income" value="1">
		</div>
		<div class="form-field">
			<label for="essf_financing_installments"><?php esc_html_e( 'Number of installments', 'essfinance' ); ?></label>
			<input type="number" min="1" max="360" name="essf_financing_installments" id="essf_financing_installments" value="1">
		</div>
		<div class="form-field">
			<label for="essf_financing_start_date"><?php esc_html_e( 'First installment date', 'essfinance' ); ?></label>
			<input type="date" name="essf_financing_start_date" id="essf_financing_start_date" value="<?php echo esc_attr( current_time( 'Y-m-d' ) ); ?>">
		</div>
		<div class="form-field">
			<label for="essf_financing_interval_count"><?php esc_html_e( 'Every', 'essfinance' ); ?></label>
			<input type="number" min="1" style="width:70px;" name="essf_financing_interval_count" id="essf_financing_interval_count" value="1">
			<?php $this->render_interval_unit_select( 'month' ); ?>
		</div>
		<div class="form-field">
			<label for="essf_financing_category"><?php esc_html_e( 'Category', 'essfinance' ); ?></label>
			<?php $this->render_category_select( '' ); ?>
		</div>
		<?php
	}

	/** @param WP_Term $term */
	public function render_edit_term_fields( $term ): void {
		$total    = (float) get_term_meta( $term->term_id, self::META_TOTAL, true );
		$n        = (int) get_term_meta( $term->term_id, self::META_INSTALLMENTS, true ) ?: 1;
		$unit     = (string) get_term_meta( $term->term_id, self::META_INTERVAL_UNIT, true ) ?: 'month';
		$count    = (int) get_term_meta( $term->term_id, self::META_INTERVAL_COUNT, true ) ?: 1;
		$start    = (string) get_term_meta( $term->term_id, self::META_START, true ) ?: current_time( 'Y-m-d' );
		$category = (string) get_term_meta( $term->term_id, self::META_CATEGORY, true );
		?>
		<tr class="form-field">
			<th scope="row"><label for="essf_financing_total"><?php esc_html_e( 'Total amount', 'essfinance' ); ?></label></th>
			<td>
				<input type="number" step="0.01" min="0" name="essf_financing_total" id="essf_financing_total" value="<?php echo esc_attr( (string) abs( $total ) ); ?>">
				<label style="margin-left:10px;"><input type="checkbox" name="essf_financing_is_income" value="1" <?php checked( $total > 0 ); ?>> <?php esc_html_e( 'Income', 'essfinance' ); ?></label>
			</td>
		</tr>
		<tr class="form-field">
			<th scope="row"><label for="essf_financing_installments"><?php esc_html_e( 'Number of installments', 'essfinance' ); ?></label></th>
			<td><input type="number" min="1" max="360" name="essf_financing_installments" id="essf_financing_installments" value="<?php echo esc_attr( (string) $n ); ?>"></td>
		</tr>
		<tr class="form-field">
			<th scope="row"><label for="essf_financing_start_date"><?php esc_html_e( 'First installment date', 'essfinance' ); ?></label></th>
			<td><input type="date" name="essf_financing_start_date" id="essf_financing_start_date" value="<?php echo esc_attr( $start ); ?>"></td>
		</tr>
		<tr class="form-field">
			<th scope="row"><label for="essf_financing_interval_count"><?php esc_html_e( 'Every', 'essfinance' ); ?></label></th>
			<td>
				<input type="number" min="1" style="width:70px;" name="essf_financing_interval_count" id="essf_financing_interval_count" value="<?php echo esc_attr( (string) $count ); ?>">
				<?php $this->render_interval_unit_select( $unit ); ?>
			</td>
		</tr>
		<tr class="form-field">
			<th scope="row"><label for="essf_financing_category"><?php esc_html_e( 'Category', 'essfinance' ); ?></label></th>
			<td><?php $this->render_category_select( $category ); ?></td>
		</tr>
		<tr class="form-field">
			<th scope="row"><?php esc_html_e( 'Installments', 'essfinance' ); ?></th>
			<td><?php $this->render_installments_table( $term, $total, $n, $unit, $count, $start ); ?></td>
		</tr>
		<?php
	}

	private function render_interval_unit_select( string $selected ): void {
		$interval_units = [
			'day'   => __( 'day(s)', 'essfinance' ),
			'week'  => __( 'week(s)', 'essfinance' ),
			'month' => __( 'month(s)', 'essfinance' ),
			'year'  => __( 'year(s)', 'essfinance' ),
		];
		echo '<select name="essf_financing_interval_unit">';
		foreach ( $interval_units as $u => $label ) {
			printf( '<option value="%s"%s>%s</option>', esc_attr( $u ), selected( $selected, $u, false ), esc_html( $label ) );
		}
		echo '</select>';
	}

	private function render_category_select( string $selected ): void {
		echo '<select name="essf_financing_category" id="essf_financing_category">';
		foreach ( ESSF_Category::get_ordered_terms() as $term ) {
			printf( '<option value="%s"%s>%s</option>', esc_attr( $term->slug ), selected( $selected, $term->slug, false ), esc_html( $term->name ) );
		}
		echo '</select>';
	}

	private function render_installments_table( WP_Term $term, float $total, int $n, string $unit, int $count, string $start ): void {
		?>
		<table class="widefat striped" style="max-width:600px;">
			<thead>
				<tr>
					<th><?php esc_html_e( '#', 'essfinance' ); ?></th>
					<th><?php esc_html_e( 'Due date', 'essfinance' ); ?></th>
					<th><?php esc_html_e( 'Amount', 'essfinance' ); ?></th>
					<th><?php esc_html_e( 'Status', 'essfinance' ); ?></th>
				</tr>
			</thead>
			<tbody>
			<?php for ( $i = 1; $i <= $n; $i++ ) : ?>
				<?php
				$due     = self::installment_due_date( $unit, $count, $start, $i );
				$matches = ESSF_CPT::find_entries_by_title( self::installment_title( $term->name, $i, $n ) );
				if ( $matches ) {
					$entry  = $matches[0];
					$amount = (float) $entry->post_content;
					$status = ESSF_CPT::status_label( $entry->post_status );
					$link   = add_query_arg(
						[
							'page'  => 'essfinance',
							'entry' => $entry->ID,
						],
						admin_url( 'admin.php' )
					);
					?>
					<tr>
						<td><?php echo esc_html( (string) $i ); ?></td>
						<td><a href="<?php echo esc_url( $link ); ?>"><?php echo esc_html( date_i18n( get_option( 'date_format' ), strtotime( $due ) ) ); ?></a></td>
						<td><?php echo esc_html( ESSF_Settings::format_amount( $amount ) ); ?></td>
						<td><?php echo esc_html( $status ); ?></td>
					</tr>
					<?php
				} else {
					$amount = self::installment_amount( $total, $i, $n );
					?>
					<tr style="color:#787c82;">
						<td><?php echo esc_html( (string) $i ); ?></td>
						<td><?php echo esc_html( date_i18n( get_option( 'date_format' ), strtotime( $due ) ) ); ?></td>
						<td><?php echo esc_html( ESSF_Settings::format_amount( $amount ) ); ?></td>
						<td><em><?php esc_html_e( 'Projected', 'essfinance' ); ?></em></td>
					</tr>
					<?php
				}
				?>
			<?php endfor; ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Fired only from WP core's own `created_{taxonomy}`/`edited_{taxonomy}`
	 * hooks, which core already gates behind its own nonce check
	 * (`check_admin_referer( 'add-tag' | 'update-tag_' . $term_id )`) before
	 * ever calling wp_insert_term()/wp_update_term() — no separate nonce
	 * check needed here.
	 */
	public function save_term_fields( int $term_id ): void {
		if ( ! isset( $_POST['essf_financing_total'] ) || ! current_user_can( 'manage_options' ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- see docblock
			return;
		}
		$post = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- see docblock

		$amount = abs( (float) $post['essf_financing_total'] );
		$income = isset( $post['essf_financing_is_income'] ) && '1' === $post['essf_financing_is_income'];
		update_term_meta( $term_id, self::META_TOTAL, (string) ( $income ? $amount : -$amount ) );

		$n = max( 1, min( 360, absint( $post['essf_financing_installments'] ?? 1 ) ) );
		update_term_meta( $term_id, self::META_INSTALLMENTS, $n );

		$start_raw = isset( $post['essf_financing_start_date'] ) ? sanitize_text_field( $post['essf_financing_start_date'] ) : '';
		$start     = preg_match( '/^\d{4}-\d{2}-\d{2}$/', $start_raw ) ? $start_raw : current_time( 'Y-m-d' );
		update_term_meta( $term_id, self::META_START, $start );

		$unit = isset( $post['essf_financing_interval_unit'] ) ? sanitize_key( $post['essf_financing_interval_unit'] ) : 'month';
		update_term_meta( $term_id, self::META_INTERVAL_UNIT, in_array( $unit, [ 'day', 'week', 'month', 'year' ], true ) ? $unit : 'month' );

		$count = max( 1, absint( $post['essf_financing_interval_count'] ?? 1 ) );
		update_term_meta( $term_id, self::META_INTERVAL_COUNT, $count );

		$category = isset( $post['essf_financing_category'] ) ? sanitize_key( $post['essf_financing_category'] ) : 'uncategorized';
		update_term_meta( $term_id, self::META_CATEGORY, $category );

		$term = get_term( $term_id, self::TAXONOMY );
		if ( $term && ! is_wp_error( $term ) ) {
			$this->maybe_materialize_next( $term );
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
			$this->maybe_materialize_next( $term );
		}
	}

	/**
	 * Materializes the next due (and not-yet-materialized) installment of a
	 * plan, if any. Idempotent — safe from both save_term_fields()
	 * (immediate feedback) and the daily sweep (catch-up as installments
	 * come due). "Already materialized" is checked fresh each time via
	 * find_entries_by_title() — the "i/N" suffix already baked into each
	 * installment's title (see installment_title()) is exclusive enough per
	 * plan, no stored index.
	 */
	private function maybe_materialize_next( WP_Term $term ): void {
		$n = (int) get_term_meta( $term->term_id, self::META_INSTALLMENTS, true );
		if ( $n < 1 ) {
			return;
		}

		$next_index = 0;
		for ( $i = 1; $i <= $n; $i++ ) {
			if ( ! ESSF_CPT::find_entries_by_title( self::installment_title( $term->name, $i, $n ) ) ) {
				$next_index = $i;
				break;
			}
		}
		if ( ! $next_index ) {
			return; // All installments already materialized.
		}

		$unit  = (string) get_term_meta( $term->term_id, self::META_INTERVAL_UNIT, true ) ?: 'month';
		$count = (int) get_term_meta( $term->term_id, self::META_INTERVAL_COUNT, true ) ?: 1;
		$start = (string) get_term_meta( $term->term_id, self::META_START, true ) ?: current_time( 'Y-m-d' );

		$due_date = self::installment_due_date( $unit, $count, $start, $next_index );
		if ( $due_date > current_time( 'Y-m-d' ) ) {
			return; // Not due yet — stays virtual.
		}

		$total    = (float) get_term_meta( $term->term_id, self::META_TOTAL, true );
		$amount   = self::installment_amount( $total, $next_index, $n );
		$category = (string) get_term_meta( $term->term_id, self::META_CATEGORY, true ) ?: 'uncategorized';

		ESSF_CPT::insert_entry( self::installment_title( $term->name, $next_index, $n ), $amount, $due_date . ' 00:00:00', '0000-00-00 00:00:00', 'pending', $category );
	}

	private static function installment_title( string $plan_title, int $index, int $n ): string {
		return $plan_title . ' ' . $index . '/' . $n;
	}

	private static function installment_due_date( string $unit, int $count, string $start, int $index ): string {
		$offset    = ( $index - 1 ) * $count;
		$unit_word = in_array( $unit, [ 'day', 'week', 'month', 'year' ], true ) ? $unit : 'month';
		$ts        = strtotime( "+{$offset} {$unit_word}", strtotime( $start ) );
		return date_i18n( 'Y-m-d', $ts );
	}

	private static function installment_amount( float $total, int $index, int $n ): float {
		$sign      = $total < 0 ? -1 : 1;
		$abs_total = abs( $total );
		$base      = round( $abs_total / $n, 2 );
		if ( $index < $n ) {
			return $sign * $base;
		}
		return $sign * round( $abs_total - ( $base * ( $n - 1 ) ), 2 );
	}
}
