<?php
/**
 * EssFinance — Loans (taxonomy)
 *
 * @package EssFinance
 * @license GPL-2.0-or-later
 */

defined( 'ABSPATH' ) || exit;

/**
 * A loan (e.g. "Loan to Lucia") is a term of `essf_loan_cat` — the term *is*
 * the loan, same shape as `essf_bill_cat`/`essf_financing_cat`; its
 * principal amount/counterparty/category live as term meta. Payments are
 * never pre-projected — they're `essf_cashflow` entries created as they
 * actually happen (via the "Add Payment" action on the term's edit screen,
 * or by hand/OFX import), discovered as belonging to this loan via
 * ESSF_CPT::find_entries_by_title() — any `essf_cashflow` entry whose
 * description matches the term's own name exactly. No stored link.
 *
 * A plain name (e.g. "Loan to Lucia") isn't always unique — the same
 * counterparty can show up in several unrelated transactions. When that
 * happens, disambiguate by including the origin date directly in the term
 * name itself (e.g. "Loan to Lucia 20260816") — there's no separate
 * "origin date" field; the full term name *is* the exact match key, and any
 * future payment must repeat that same text to link to it.
 *
 * "Paid off" is always computed from the matched sum vs. the principal,
 * never stored — same spirit as the virtual `overdue` status on
 * `essf_cashflow` itself.
 *
 * No dedicated admin page: `essf_loan_cat` is registered with `show_ui` and
 * accessed only via WordPress's native taxonomy screen (see
 * register_menu()).
 */
class ESSF_Loan_CPT {

	const TAXONOMY = 'essf_loan_cat';

	const META_PRINCIPAL   = '_essf_loan_principal';
	const META_COUNTERPART = '_essf_counterparty';
	const META_CATEGORY    = '_essf_loan_category';

	const ADD_PAYMENT_ACTION = 'essf_loan_add_payment';
	const ADD_PAYMENT_NONCE  = 'essf_loan_add_payment_nonce';

	public function __construct() {
		add_action( 'init', [ $this, 'register' ] );
		add_action( 'admin_menu', [ $this, 'register_menu' ] );

		add_action( self::TAXONOMY . '_add_form_fields', [ $this, 'render_add_term_fields' ] );
		add_action( self::TAXONOMY . '_edit_form_fields', [ $this, 'render_edit_term_fields' ] );
		add_action( 'created_' . self::TAXONOMY, [ $this, 'save_term_fields' ] );
		add_action( 'edited_' . self::TAXONOMY, [ $this, 'save_term_fields' ] );
		add_filter( self::TAXONOMY . '_row_actions', [ $this, 'row_actions' ], 10, 2 );

		add_action( 'admin_post_' . self::ADD_PAYMENT_ACTION, [ $this, 'handle_add_payment' ] );
	}

	public function register(): void {
		register_taxonomy(
			self::TAXONOMY,
			[], // No object type — this taxonomy is only ever used as a config/term-meta holder.
			[
				'labels'             => [
					'name'          => __( 'Loans', 'essfinance' ),
					'singular_name' => __( 'Loan', 'essfinance' ),
					'add_new_item'  => __( 'Add New Loan', 'essfinance' ),
					'new_item_name' => __( 'New Loan Name (add a date if the name repeats, e.g. "Loan to Lucia 20260816")', 'essfinance' ),
					'all_items'     => __( 'All Loans', 'essfinance' ),
					'menu_name'     => __( 'Loans', 'essfinance' ),
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
			__( 'Loans', 'essfinance' ),
			__( 'Loans', 'essfinance' ),
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
			<label for="essf_loan_principal"><?php esc_html_e( 'Principal amount', 'essfinance' ); ?></label>
			<input type="number" step="0.01" min="0" name="essf_loan_principal" id="essf_loan_principal" value="0.00">
		</div>
		<div class="form-field">
			<label><input type="checkbox" name="essf_loan_is_lender" value="1" checked> <?php esc_html_e( 'I lent this money (unchecked = I borrowed it)', 'essfinance' ); ?></label>
		</div>
		<div class="form-field">
			<label for="essf_counterparty"><?php esc_html_e( 'Counterparty', 'essfinance' ); ?></label>
			<input type="text" name="essf_counterparty" id="essf_counterparty" placeholder="<?php esc_attr_e( 'e.g. Lucia', 'essfinance' ); ?>">
		</div>
		<div class="form-field">
			<label for="essf_loan_category"><?php esc_html_e( 'Category for payments', 'essfinance' ); ?></label>
			<?php $this->render_category_select( '' ); ?>
		</div>
		<?php
	}

	/** @param WP_Term $term */
	public function render_edit_term_fields( $term ): void {
		$principal    = (float) get_term_meta( $term->term_id, self::META_PRINCIPAL, true );
		$counterparty = (string) get_term_meta( $term->term_id, self::META_COUNTERPART, true );
		$category     = (string) get_term_meta( $term->term_id, self::META_CATEGORY, true );
		?>
		<tr class="form-field">
			<th scope="row"><label for="essf_loan_principal"><?php esc_html_e( 'Principal amount', 'essfinance' ); ?></label></th>
			<td>
				<input type="number" step="0.01" min="0" name="essf_loan_principal" id="essf_loan_principal" value="<?php echo esc_attr( (string) abs( $principal ) ); ?>">
				<label style="margin-left:10px;"><input type="checkbox" name="essf_loan_is_lender" value="1" <?php checked( $principal >= 0 ); ?>> <?php esc_html_e( 'I lent this money (unchecked = I borrowed it)', 'essfinance' ); ?></label>
			</td>
		</tr>
		<tr class="form-field">
			<th scope="row"><label for="essf_counterparty"><?php esc_html_e( 'Counterparty', 'essfinance' ); ?></label></th>
			<td><input type="text" name="essf_counterparty" id="essf_counterparty" value="<?php echo esc_attr( $counterparty ); ?>" placeholder="<?php esc_attr_e( 'e.g. Lucia', 'essfinance' ); ?>"></td>
		</tr>
		<tr class="form-field">
			<th scope="row"><label for="essf_loan_category"><?php esc_html_e( 'Category for payments', 'essfinance' ); ?></label></th>
			<td><?php $this->render_category_select( $category ); ?></td>
		</tr>
		<tr class="form-field">
			<th scope="row"><?php esc_html_e( 'Payments', 'essfinance' ); ?></th>
			<td><?php $this->render_payments_summary( $term, $principal ); ?></td>
		</tr>
		<?php
	}

	private function render_category_select( string $selected ): void {
		echo '<select name="essf_loan_category" id="essf_loan_category">';
		foreach ( ESSF_Category::get_ordered_terms() as $term ) {
			printf( '<option value="%s"%s>%s</option>', esc_attr( $term->slug ), selected( $selected, $term->slug, false ), esc_html( $term->name ) );
		}
		echo '</select>';
	}

	private function render_payments_summary( WP_Term $term, float $principal ): void {
		$entries = ESSF_CPT::find_entries_by_title( $term->name );
		$totals  = ESSF_Totals::compute_from_posts( $entries );
		$paid    = $totals['net'];
		$remain  = $principal - $paid;
		$settled = abs( $remain ) < 0.01;
		?>
		<p>
			<strong><?php esc_html_e( 'Paid so far:', 'essfinance' ); ?></strong> <?php echo esc_html( ESSF_Settings::format_amount( $paid ) ); ?>
			&nbsp;·&nbsp;
			<strong><?php esc_html_e( 'Remaining:', 'essfinance' ); ?></strong> <?php echo esc_html( ESSF_Settings::format_amount( $remain ) ); ?>
			&nbsp;·&nbsp;
			<?php echo $settled ? esc_html__( 'Paid off', 'essfinance' ) : esc_html__( 'Outstanding', 'essfinance' ); ?>
		</p>
		<?php if ( $entries ) : ?>
			<table class="widefat striped" style="max-width:500px;">
				<thead><tr><th><?php esc_html_e( 'Date', 'essfinance' ); ?></th><th><?php esc_html_e( 'Amount', 'essfinance' ); ?></th></tr></thead>
				<tbody>
				<?php foreach ( $entries as $entry ) : ?>
					<?php
					$date     = substr( 'paid' === $entry->post_status ? $entry->post_modified_gmt : $entry->post_date_gmt, 0, 10 );
					$edit_url = add_query_arg(
						[
							'page'  => 'essfinance',
							'entry' => $entry->ID,
						],
						admin_url( 'admin.php' )
					);
					?>
					<tr>
						<td><a href="<?php echo esc_url( $edit_url ); ?>"><?php echo esc_html( date_i18n( get_option( 'date_format' ), strtotime( $date ) ) ); ?></a></td>
						<td><?php echo esc_html( ESSF_Settings::format_amount( (float) $entry->post_content ) ); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>

		<?php
		/*
		 * The term edit screen renders inside WordPress's single edit-tag
		 * <form> — nesting another <form> here would be invalid HTML that
		 * browsers silently mangle (submitting to the outer form instead).
		 * Instead, this button builds and submits a real, non-nested <form>
		 * via JS, appended to <body> just before submit.
		 */
		?>
		<h4><?php esc_html_e( 'Add Payment', 'essfinance' ); ?></h4>
		<p>
			<input type="number" step="0.01" min="0" id="essf_payment_amount" placeholder="0.00" required>
			<input type="date" id="essf_payment_date" value="<?php echo esc_attr( current_time( 'Y-m-d' ) ); ?>" required>
			<button type="button" class="button" id="essf_add_payment_btn"><?php esc_html_e( 'Add Payment', 'essfinance' ); ?></button>
		</p>
		<script>
		document.getElementById( 'essf_add_payment_btn' ).addEventListener( 'click', function () {
			var amount = document.getElementById( 'essf_payment_amount' ).value;
			var date   = document.getElementById( 'essf_payment_date' ).value;
			if ( ! amount || ! date ) {
				return;
			}
			var form = document.createElement( 'form' );
			form.method = 'post';
			form.action = <?php echo wp_json_encode( admin_url( 'admin-post.php' ) ); ?>;
			[
				[ 'action', <?php echo wp_json_encode( self::ADD_PAYMENT_ACTION ); ?> ],
				[ 'loan_term_id', <?php echo wp_json_encode( (string) $term->term_id ); ?> ],
				[ '_wpnonce', <?php echo wp_json_encode( wp_create_nonce( self::ADD_PAYMENT_NONCE . '_' . $term->term_id ) ); ?> ],
				[ 'payment_amount', amount ],
				[ 'payment_date', date ],
			].forEach( function ( pair ) {
				var input = document.createElement( 'input' );
				input.type = 'hidden';
				input.name = pair[0];
				input.value = pair[1];
				form.appendChild( input );
			} );
			document.body.appendChild( form );
			form.submit();
		} );
		</script>
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
		if ( ! isset( $_POST['essf_loan_principal'] ) || ! current_user_can( 'manage_options' ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- see docblock
			return;
		}
		$post = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- see docblock

		$amount = abs( (float) $post['essf_loan_principal'] );
		$lender = isset( $post['essf_loan_is_lender'] ) && '1' === $post['essf_loan_is_lender'];
		update_term_meta( $term_id, self::META_PRINCIPAL, (string) ( $lender ? $amount : -$amount ) );

		$counterparty = isset( $post['essf_counterparty'] ) ? sanitize_text_field( $post['essf_counterparty'] ) : '';
		update_term_meta( $term_id, self::META_COUNTERPART, $counterparty );

		$category = isset( $post['essf_loan_category'] ) ? sanitize_key( $post['essf_loan_category'] ) : 'loans';
		update_term_meta( $term_id, self::META_CATEGORY, $category );
	}

	/* ── Add Payment handler ─────────────────────────────────────── */

	public function handle_add_payment(): void {
		$term_id = absint( $_POST['loan_term_id'] ?? 0 );
		check_admin_referer( self::ADD_PAYMENT_NONCE . '_' . $term_id );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized.', 'essfinance' ) );
		}

		$term = get_term( $term_id, self::TAXONOMY );
		if ( ! $term || is_wp_error( $term ) ) {
			wp_die( esc_html__( 'Loan not found.', 'essfinance' ) );
		}

		$principal = (float) get_term_meta( $term_id, self::META_PRINCIPAL, true );
		$category  = (string) get_term_meta( $term_id, self::META_CATEGORY, true ) ?: 'loans';

		$post   = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified above via check_admin_referer()
		$amount = abs( (float) ( $post['payment_amount'] ?? 0 ) );
		$signed = $principal >= 0 ? $amount : -$amount;

		$date_raw = isset( $post['payment_date'] ) ? sanitize_text_field( $post['payment_date'] ) : '';
		$date     = preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date_raw ) ? $date_raw : current_time( 'Y-m-d' );

		ESSF_CPT::insert_entry( $term->name, $signed, $date . ' 00:00:00', $date . ' 00:00:00', 'paid', $category );

		wp_safe_redirect(
			add_query_arg(
				[
					'taxonomy' => self::TAXONOMY,
					'tag_ID'   => $term_id,
				],
				admin_url( 'term.php' )
			)
		);
		exit;
	}
}
