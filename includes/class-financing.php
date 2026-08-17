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
	/** 'fixed' (default, total/n every installment) | 'amortization' (declining — see compute_step()). */
	const META_SCHEDULE_TYPE = '_essf_financing_schedule_type';
	/**
	 * Nominal annual interest rate (%), amortization mode only — optional
	 * SAC-formula fallback (see sac_amount()) for when there aren't yet 2
	 * real installments to calculate compute_step() from. META_TOTAL is
	 * read as the financed principal when this is set (not "sum of all
	 * installments" — for an amortized loan those aren't the same number).
	 */
	const META_INTEREST_RATE = '_essf_financing_interest_rate';

	/**
	 * Set only for the duration of create_term_from_detection() — lets
	 * save_term_fields() read inferred meta instead of $_POST when a plan is
	 * created programmatically by ESSF_Plan_Detector rather than through the
	 * native "Add New" term form.
	 */
	private static ?array $detection_meta = null;

	/**
	 * Creates a Financing term with the given meta already applied (bypassing
	 * $_POST — see $detection_meta above), used by ESSF_Plan_Detector for
	 * both the one-click backfill and the on-insert auto-detect hook.
	 * wp_insert_term() fires `created_essf_financing_cat` synchronously,
	 * which runs save_term_fields() (and, at its end, the initial
	 * maybe_materialize_next()) before this method returns.
	 *
	 * @param array $meta essf_financing_total, essf_financing_is_income,
	 *                    essf_financing_installments, essf_financing_start_date,
	 *                    essf_financing_interval_unit, essf_financing_interval_count,
	 *                    essf_financing_category — same shape as the term form's $_POST.
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
		$actions['view_installments'] = '<a href="' . esc_url( ESSF_Recurrence_Entries_Page::url( self::TAXONOMY, $term->term_id ) ) . '">' . esc_html__( 'Installments', 'essfinance' ) . '</a>';
		$actions['view_entries']      = '<a href="' . esc_url( ESSF_CPT::cashflow_search_url( $term->name ) ) . '">' . esc_html__( 'View entries', 'essfinance' ) . '</a>';
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
			<input type="number" min="1" max="600" name="essf_financing_installments" id="essf_financing_installments" value="1">
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
		<div class="form-field">
			<label for="essf_financing_schedule_type"><?php esc_html_e( 'Installment type', 'essfinance' ); ?></label>
			<?php $this->render_schedule_type_select( 'fixed' ); ?>
		</div>
		<div class="form-field">
			<label for="essf_financing_interest_rate"><?php esc_html_e( 'Nominal annual interest rate, % (amortization mode only)', 'essfinance' ); ?></label>
			<input type="number" step="0.0001" min="0" name="essf_financing_interest_rate" id="essf_financing_interest_rate" value="0">
			<p><?php esc_html_e( 'Used to estimate SAC-style declining installments (Total amount = financed principal) until enough real installments exist to calculate the trend from actual data instead.', 'essfinance' ); ?></p>
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
			<td><input type="number" min="1" max="600" name="essf_financing_installments" id="essf_financing_installments" value="<?php echo esc_attr( (string) $n ); ?>"></td>
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
			<th scope="row"><label for="essf_financing_interest_rate"><?php esc_html_e( 'Nominal annual interest rate, % (amortization mode only)', 'essfinance' ); ?></label></th>
			<td>
				<input type="number" step="0.0001" min="0" name="essf_financing_interest_rate" id="essf_financing_interest_rate" value="<?php echo esc_attr( (string) ( (float) get_term_meta( $term->term_id, self::META_INTEREST_RATE, true ) ) ); ?>">
				<p class="description"><?php esc_html_e( 'Used to estimate SAC-style declining installments (Total amount above = financed principal) until enough real installments exist to calculate the trend from actual data instead.', 'essfinance' ); ?></p>
			</td>
		</tr>
		<tr class="form-field">
			<th scope="row"><label for="essf_financing_schedule_type"><?php esc_html_e( 'Installment type', 'essfinance' ); ?></label></th>
			<td>
				<?php
				$schedule_type = (string) get_term_meta( $term->term_id, self::META_SCHEDULE_TYPE, true ) ?: 'fixed';
				$rate          = (float) get_term_meta( $term->term_id, self::META_INTEREST_RATE, true );
				$this->render_schedule_type_select( $schedule_type );
				if ( 'amortization' === $schedule_type ) :
					$step = self::compute_step( self::materialized_map( $term, $n ) );
					if ( null !== $step ) :
						// $step is signed math (post_content convention: expense
						// entries are negative) — for display, flip it by the
						// plan's own sign so "-R$5" always reads as "installments
						// getting smaller", regardless of income vs expense.
						$human_step = ( $total < 0 ? -1 : 1 ) * $step;
						?>
						<p class="description">
							<?php
							printf(
								/* translators: %s: change in installment size per period, e.g. "-R$0.19" means installments are getting smaller. */
								esc_html__( 'Step: %s per installment (calculated from the two most recent real installments).', 'essfinance' ),
								esc_html( ( $human_step < 0 ? '-' : '+' ) . ESSF_Settings::format_amount( $human_step ) )
							);
							?>
						</p>
						<?php
					elseif ( $rate > 0 ) :
						?>
						<p class="description"><?php esc_html_e( 'Not enough real installments yet to calculate the trend from actual data — estimating with the SAC formula from the principal/rate above for now. This won\'t exactly match the bank (monetary correction, insurance and fees aren\'t modeled) but is closer than a flat average.', 'essfinance' ); ?></p>
						<?php
					else :
						?>
						<p class="description"><?php esc_html_e( 'Not enough real installments yet to calculate the progression, and no interest rate set — using a flat estimate for now.', 'essfinance' ); ?></p>
						<?php
					endif;
				endif;
				?>
			</td>
		</tr>
		<tr class="form-field">
			<th scope="row"><?php esc_html_e( 'Installments', 'essfinance' ); ?></th>
			<td><a href="<?php echo esc_url( ESSF_Recurrence_Entries_Page::url( self::TAXONOMY, $term->term_id ) ); ?>"><?php esc_html_e( 'View installments →', 'essfinance' ); ?></a></td>
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

	private function render_schedule_type_select( string $selected ): void {
		$types = [
			'fixed'        => __( 'Fixed (same amount every installment)', 'essfinance' ),
			'amortization' => __( 'Amortization (decreasing — calculated automatically once 2+ real installments exist)', 'essfinance' ),
		];
		echo '<select name="essf_financing_schedule_type" id="essf_financing_schedule_type">';
		foreach ( $types as $value => $label ) {
			printf( '<option value="%s"%s>%s</option>', esc_attr( $value ), selected( $selected, $value, false ), esc_html( $label ) );
		}
		echo '</select>';
	}

	/** Reads term meta and renders the full virtual+real installments table — used by ESSF_Recurrence_Entries_Page. */
	public static function render_installments_table_for_term( WP_Term $term ): void {
		$total         = (float) get_term_meta( $term->term_id, self::META_TOTAL, true );
		$n             = (int) get_term_meta( $term->term_id, self::META_INSTALLMENTS, true ) ?: 1;
		$unit          = (string) get_term_meta( $term->term_id, self::META_INTERVAL_UNIT, true ) ?: 'month';
		$count         = (int) get_term_meta( $term->term_id, self::META_INTERVAL_COUNT, true ) ?: 1;
		$start         = (string) get_term_meta( $term->term_id, self::META_START, true ) ?: current_time( 'Y-m-d' );
		$schedule_type = (string) get_term_meta( $term->term_id, self::META_SCHEDULE_TYPE, true ) ?: 'fixed';
		$rate          = (float) get_term_meta( $term->term_id, self::META_INTEREST_RATE, true );
		self::render_installments_table( $term, $total, $n, $unit, $count, $start, $schedule_type, $rate );
	}

	private static function render_installments_table( WP_Term $term, float $total, int $n, string $unit, int $count, string $start, string $schedule_type = 'fixed', float $rate = 0.0 ): void {
		$map  = self::materialized_map( $term, $n );
		$step = 'amortization' === $schedule_type ? self::compute_step( $map ) : null;
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
				$due = self::installment_due_date( $unit, $count, $start, $i );
				if ( isset( $map[ $i ] ) ) {
					$entry  = $map[ $i ];
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
					$amount = self::projected_amount( $total, $i, $n, $map, $step, $rate );
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
	 * All installments of a plan that already exist as real `essf_cashflow`
	 * entries, keyed by index — single query pass reused by rendering,
	 * materialization, and the step calculation below (each of those used to
	 * loop and re-query independently).
	 *
	 * @return array<int, WP_Post>
	 */
	private static function materialized_map( WP_Term $term, int $n ): array {
		$map = [];
		for ( $i = 1; $i <= $n; $i++ ) {
			$matches = ESSF_CPT::find_entries_by_title( self::installment_title( $term->name, $i, $n ) );
			if ( $matches ) {
				$map[ $i ] = $matches[0];
			}
		}
		return $map;
	}

	/**
	 * The constant per-installment change (an arithmetic progression — what
	 * a SAC-style declining schedule produces) between the two most recent
	 * real installments, read from each entry's *base* amount
	 * (ESSF_CPT::base_amount() — discount/surcharge already stripped out, so
	 * a late fee on one installment doesn't throw off the trend). Null when
	 * fewer than 2 real installments exist yet — nothing to extrapolate
	 * from, callers fall back to a flat installment_amount() split.
	 */
	private static function compute_step( array $map ): ?float {
		if ( count( $map ) < 2 ) {
			return null;
		}
		$indices = array_keys( $map );
		sort( $indices );
		$high = $indices[ count( $indices ) - 1 ];
		$low  = $indices[ count( $indices ) - 2 ];
		return ( ESSF_CPT::base_amount( $map[ $high ] ) - ESSF_CPT::base_amount( $map[ $low ] ) ) / ( $high - $low );
	}

	/**
	 * Amount for a not-yet-materialized installment, in priority order:
	 * (1) extrapolated from the most recent real installment's base amount
	 * via $step, once 2+ real installments exist; (2) the SAC formula
	 * (sac_amount()) when an interest rate is configured — a reasonable
	 * starting estimate before there's enough real data for (1); (3) the
	 * flat total/n split (installment_amount()), same as a `fixed` plan.
	 * Used for both "Projected" display rows and the next real
	 * materialization.
	 */
	private static function projected_amount( float $total, int $index, int $n, array $map, ?float $step, float $rate = 0.0 ): float {
		if ( null !== $step && $map ) {
			$ref_index = max( array_keys( $map ) );
			$ref_base  = ESSF_CPT::base_amount( $map[ $ref_index ] );
			return round( $ref_base + $step * ( $index - $ref_index ), 2 );
		}
		if ( $rate > 0 ) {
			return self::sac_amount( $total, $n, $rate, $index );
		}
		return self::installment_amount( $total, $index, $n );
	}

	/**
	 * Standard SAC (Sistema de Amortização Constante) installment: constant
	 * amortization plus interest on the remaining balance — what actually
	 * produces a declining schedule. Approximate only: real contracts often
	 * apply monthly monetary correction (TR/IPCA) to the balance, plus
	 * insurance/admin fees, none of which this models — it's meant as a
	 * starting estimate before enough real installments exist for
	 * compute_step() to take over and self-correct from what the bank
	 * actually charges.
	 */
	public static function sac_amount( float $principal, int $n, float $annual_rate_pct, int $index ): float {
		$sign          = $principal < 0 ? -1 : 1;
		$abs_principal = abs( $principal );
		$amortization  = $abs_principal / $n;
		$monthly_rate  = $annual_rate_pct / 100 / 12;
		$balance       = $abs_principal - $amortization * ( $index - 1 );
		return $sign * round( $amortization + $balance * $monthly_rate, 2 );
	}

	/**
	 * Fired only from WP core's own `created_{taxonomy}`/`edited_{taxonomy}`
	 * hooks, which core already gates behind its own nonce check
	 * (`check_admin_referer( 'add-tag' | 'update-tag_' . $term_id )`) before
	 * ever calling wp_insert_term()/wp_update_term() — no separate nonce
	 * check needed here.
	 */
	public function save_term_fields( int $term_id ): void {
		$via_detection = null !== self::$detection_meta;
		if ( $via_detection ) {
			$post = self::$detection_meta;
		} else {
			if ( ! isset( $_POST['essf_financing_total'] ) || ! current_user_can( 'manage_options' ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- see docblock
				return;
			}
			$post = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- see docblock
		}

		$amount = abs( (float) $post['essf_financing_total'] );
		$income = isset( $post['essf_financing_is_income'] ) && '1' === $post['essf_financing_is_income'];
		update_term_meta( $term_id, self::META_TOTAL, (string) ( $income ? $amount : -$amount ) );

		$n = max( 1, min( 600, absint( $post['essf_financing_installments'] ?? 1 ) ) );
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

		$schedule_type = isset( $post['essf_financing_schedule_type'] ) ? sanitize_key( $post['essf_financing_schedule_type'] ) : 'fixed';
		update_term_meta( $term_id, self::META_SCHEDULE_TYPE, in_array( $schedule_type, [ 'fixed', 'amortization' ], true ) ? $schedule_type : 'fixed' );

		$rate = max( 0.0, (float) ( $post['essf_financing_interest_rate'] ?? 0 ) );
		update_term_meta( $term_id, self::META_INTEREST_RATE, $rate );

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
	 *
	 * Always the index right after the highest already-real one (or 1, if
	 * none exist yet) — never an earlier gap. A plan whose first known real
	 * installment starts mid-sequence (e.g. auto-detected from a mortgage
	 * already 56 payments in) must never have this silently backfill 1-55 as
	 * fake new "pending" entries — those predate tracking, they're not
	 * missing.
	 */
	private function maybe_materialize_next( WP_Term $term ): void {
		$n = (int) get_term_meta( $term->term_id, self::META_INSTALLMENTS, true );
		if ( $n < 1 ) {
			return;
		}

		$map = self::materialized_map( $term, $n );

		$next_index = $map ? max( array_keys( $map ) ) + 1 : 1;
		if ( $next_index > $n ) {
			return; // All installments already materialized.
		}

		$unit  = (string) get_term_meta( $term->term_id, self::META_INTERVAL_UNIT, true ) ?: 'month';
		$count = (int) get_term_meta( $term->term_id, self::META_INTERVAL_COUNT, true ) ?: 1;
		$start = (string) get_term_meta( $term->term_id, self::META_START, true ) ?: current_time( 'Y-m-d' );

		$due_date = self::installment_due_date( $unit, $count, $start, $next_index );
		if ( $due_date > current_time( 'Y-m-d' ) ) {
			return; // Not due yet — stays virtual.
		}

		$total         = (float) get_term_meta( $term->term_id, self::META_TOTAL, true );
		$schedule_type = (string) get_term_meta( $term->term_id, self::META_SCHEDULE_TYPE, true ) ?: 'fixed';
		$rate          = (float) get_term_meta( $term->term_id, self::META_INTEREST_RATE, true );
		$step          = 'amortization' === $schedule_type ? self::compute_step( $map ) : null;
		$amount        = self::projected_amount( $total, $next_index, $n, $map, $step, $rate );
		$category      = (string) get_term_meta( $term->term_id, self::META_CATEGORY, true ) ?: 'uncategorized';

		ESSF_CPT::insert_entry( self::installment_title( $term->name, $next_index, $n ), $amount, $due_date . ' 00:00:00', '0000-00-00 00:00:00', 'pending', $category, get_current_user_id() );
	}

	/** Public — reused by the split-into-installments tool (ESSF_Admin_Page). */
	public static function installment_title( string $plan_title, int $index, int $n ): string {
		return $plan_title . ' ' . $index . '/' . $n;
	}

	/** Public — reused by the split-into-installments tool (ESSF_Admin_Page). */
	public static function installment_due_date( string $unit, int $count, string $start, int $index ): string {
		$offset    = ( $index - 1 ) * $count;
		$unit_word = in_array( $unit, [ 'day', 'week', 'month', 'year' ], true ) ? $unit : 'month';
		$ts        = strtotime( "+{$offset} {$unit_word}", strtotime( $start ) );
		return date_i18n( 'Y-m-d', $ts );
	}

	/** Public — reused by the split-into-installments tool (ESSF_Admin_Page). */
	public static function installment_amount( float $total, int $index, int $n ): float {
		$sign      = $total < 0 ? -1 : 1;
		$abs_total = abs( $total );
		$base      = round( $abs_total / $n, 2 );
		if ( $index < $n ) {
			return $sign * $base;
		}
		return $sign * round( $abs_total - ( $base * ( $n - 1 ) ), 2 );
	}

	/**
	 * The next `$count` installment indices (1-based) not yet materialized
	 * for this plan, in order — used by the split-into-installments tool
	 * (ESSF_Admin_Page) to know which slots a lump-sum entry should become.
	 * Always counts forward from the highest already-real index (or 1, for a
	 * plan with no real installments yet) — never offers an earlier gap. A
	 * plan whose first known real installment is mid-sequence (e.g. an
	 * auto-detected mortgage already 56 payments in) has no record of
	 * installments 1-55 on purpose — they predate tracking, not "missing".
	 *
	 * @return int[]
	 */
	public static function next_unmaterialized_indices( WP_Term $term, int $count ): array {
		$n = (int) get_term_meta( $term->term_id, self::META_INSTALLMENTS, true );
		if ( $n < 1 ) {
			return [];
		}
		$map        = self::materialized_map( $term, $n );
		$next_index = $map ? max( array_keys( $map ) ) + 1 : 1;
		$end        = min( $n, $next_index + $count - 1 );

		return $next_index <= $end ? range( $next_index, $end ) : [];
	}
}
