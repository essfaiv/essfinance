<?php
/**
 * EssFinance — Loans (taxonomy)
 *
 * @package EssFinance
 * @license GPL-2.0-or-later
 */

defined( 'ABSPATH' ) || exit;

/**
 * A loan (e.g. "Lucia") is a term of `essf_loan_cat` — the term *is* the
 * loan, same shape as `essf_bill_cat`/`essf_financing_cat`; its principal
 * amount/counterparty/category live as term meta. The taxonomy context
 * already says "this is a loan" — term names don't repeat "Loan"/
 * "Empréstimo". Payments are never pre-projected — they're `essf_cashflow`
 * entries created as they actually happen (via the "Add Payment" action on
 * the term's edit screen, or by hand/OFX import), discovered as belonging
 * to this loan via ESSF_CPT::find_entries_by_title() — any `essf_cashflow`
 * entry whose description matches the term's own name exactly. No stored
 * link.
 *
 * A plain name (e.g. "Lucia") isn't always unique — the same counterparty
 * can show up in several unrelated transactions. When that happens,
 * disambiguate by including the origin date directly in the term name
 * itself (e.g. "Lucia 20260816") — there's no separate "origin date" field;
 * the full term name *is* the exact match key, and any future payment must
 * repeat that same text to link to it.
 *
 * Matched entries are split by sign relative to the principal: entries with
 * the SAME sign as the principal are the origin/disbursement(s) (shown for
 * reference only), entries with the OPPOSITE sign are payments that count
 * toward "paid so far". Without this split, the very entry that establishes
 * the loan (e.g. the income entry recording money borrowed) would count as
 * a payment against itself and immediately look "paid off". "Paid off" /
 * "Outstanding" is always computed this way, never stored — same spirit as
 * the virtual `overdue` status on `essf_cashflow` itself.
 *
 * No dedicated admin page for editing: `essf_loan_cat` is registered with
 * `show_ui` and accessed only via WordPress's native taxonomy screen (see
 * register_menu()). ESSF_Recurrence_Entries_Page provides a dedicated
 * *listing* screen for the payments table (see render_payments_summary()).
 */
class ESSF_Loan_CPT {

	const TAXONOMY = 'essf_loan_cat';

	const META_PRINCIPAL   = '_essf_loan_principal';
	const META_COUNTERPART = '_essf_counterparty';
	const META_CATEGORY    = '_essf_loan_category';

	const ADD_PAYMENT_ACTION = 'essf_loan_add_payment';
	const ADD_PAYMENT_NONCE  = 'essf_loan_add_payment_nonce';

	/**
	 * Set only for the duration of create_term_from_detection() — lets
	 * save_term_fields() read inferred meta instead of $_POST when a loan is
	 * created programmatically by ESSF_Plan_Detector rather than through the
	 * native "Add New" term form.
	 */
	private static ?array $detection_meta = null;

	/**
	 * Creates a Loan term with the given meta already applied (bypassing
	 * $_POST — see $detection_meta above), used by ESSF_Plan_Detector for
	 * both the one-click backfill and the on-insert auto-detect hook.
	 *
	 * @param array $meta essf_loan_principal, essf_loan_is_lender,
	 *                    essf_counterparty, essf_loan_category — same shape
	 *                    as the term form's $_POST.
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
					'new_item_name' => __( 'New Loan Name (add a date if the name repeats, e.g. "Lucia 20260816")', 'essfinance' ),
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
			<td>
				<?php self::render_payments_overview( $term, $principal ); ?>
				<p><a href="<?php echo esc_url( ESSF_Recurrence_Entries_Page::url( self::TAXONOMY, $term->term_id ) ); ?>"><?php esc_html_e( 'View payments →', 'essfinance' ); ?></a></p>
			</td>
		</tr>
		<?php
	}

	/**
	 * Sums only the entries actually settled (post_status 'paid') —
	 * ESSF_Totals::compute_from_posts() sums every post it's given with no
	 * status filtering, which is fine as long as every matched payment is
	 * paid, but a payment added as "Pending" (scheduled, not yet realized —
	 * see handle_add_payment()) must not inflate "Paid so far" until it's
	 * actually marked paid.
	 *
	 * @param WP_Post[] $payments
	 */
	private static function sum_realized_payments( array $payments ): float {
		$realized = array_values( array_filter( $payments, static fn( $post ) => 'paid' === $post->post_status ) );
		return abs( ESSF_Totals::compute_from_posts( $realized )['net'] );
	}

	/** Short summary shown on the term edit screen — full table lives on ESSF_Recurrence_Entries_Page. */
	private static function render_payments_overview( WP_Term $term, float $principal ): void {
		[ , $payments ] = self::split_entries( $term, $principal );
		$paid           = self::sum_realized_payments( $payments );
		$remain         = abs( $principal ) - $paid;
		$settled        = abs( $remain ) < 0.01;
		?>
		<p>
			<strong><?php esc_html_e( 'Paid so far:', 'essfinance' ); ?></strong> <?php echo esc_html( ESSF_Settings::format_amount( $paid ) ); ?>
			&nbsp;·&nbsp;
			<strong><?php esc_html_e( 'Remaining:', 'essfinance' ); ?></strong> <?php echo esc_html( ESSF_Settings::format_amount( $remain ) ); ?>
			&nbsp;·&nbsp;
			<?php echo $settled ? esc_html__( 'Paid off', 'essfinance' ) : esc_html__( 'Outstanding', 'essfinance' ); ?>
		</p>
		<?php
	}

	/** @param WP_Post[] $entries */
	private static function render_entries_table( array $entries ): void {
		?>
		<table class="widefat striped" style="max-width:500px;">
			<thead><tr><th><?php esc_html_e( 'Date', 'essfinance' ); ?></th><th><?php esc_html_e( 'Amount', 'essfinance' ); ?></th><th><?php esc_html_e( 'Status', 'essfinance' ); ?></th></tr></thead>
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
					<td><span class="essf-badge essf-badge--<?php echo esc_attr( $entry->post_status ); ?>"><?php echo esc_html( ESSF_CPT::status_label( $entry->post_status ) ); ?></span></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	private function render_category_select( string $selected ): void {
		echo '<select name="essf_loan_category" id="essf_loan_category">';
		foreach ( ESSF_Category::get_ordered_terms() as $term ) {
			printf( '<option value="%s"%s>%s</option>', esc_attr( $term->slug ), selected( $selected, $term->slug, false ), esc_html( $term->name ) );
		}
		echo '</select>';
	}

	/**
	 * Discovers every essf_cashflow entry belonging to this loan — either
	 * titled exactly the term's name, or titled "{term name} i/n" (the
	 * numbered-suffix shape produced by renumber_and_sync()/"Group as
	 * Loan"). WP_Query's exact-match `title` param can't do this alone, so
	 * this uses the plugin's existing fuzzy `s` search (the same mechanism
	 * that already backs the "View entries" link) as a cheap candidate
	 * pre-filter, then confirms each candidate against the exact suffix
	 * shape in PHP — no false positives from an unrelated loan sharing a
	 * word.
	 *
	 * @return WP_Post[]
	 */
	private static function find_own_entries( WP_Term $term ): array {
		$exact = ESSF_CPT::find_entries_by_title( $term->name );

		$candidates = get_posts(
			[
				'post_type'      => 'essf_cashflow',
				'post_status'    => [ 'pending', 'paid' ],
				'posts_per_page' => -1,
				's'              => $term->name,
			]
		);

		$pattern = '/^' . preg_quote( $term->name, '/' ) . '\s+\d{1,3}\/\d{1,3}$/u';
		$by_id   = [];
		foreach ( $exact as $post ) {
			$by_id[ $post->ID ] = $post;
		}
		foreach ( $candidates as $post ) {
			if ( preg_match( $pattern, $post->post_title ) ) {
				$by_id[ $post->ID ] = $post;
			}
		}

		return array_values( $by_id );
	}

	/**
	 * Recomputes and saves the loan's principal from its currently-matched
	 * entries (see find_own_entries()) — called whenever a new entry joins
	 * an existing loan, so the stored principal never goes stale.
	 *
	 * A term with no principal recorded yet (brand new — e.g. just created
	 * by "Group as Loan", which leaves it at 0) bootstraps the same way
	 * ESSF_Plan_Detector::infer_loan_meta() already does: sum every matched
	 * entry, magnitude becomes the principal, sign decides is_lender.
	 *
	 * A term that already has a real principal keeps its existing sign
	 * convention and only re-sums the origin-side entries (same sign as the
	 * stored principal) — summing everything here would incorrectly net
	 * already-recorded payments against the principal.
	 */
	public static function sync_principal_from_entries( WP_Term $term ): void {
		$entries = self::find_own_entries( $term );
		if ( ! $entries ) {
			return;
		}

		$stored_principal = (float) get_term_meta( $term->term_id, self::META_PRINCIPAL, true );

		if ( 0.0 === $stored_principal ) {
			$sum = 0.0;
			foreach ( $entries as $entry ) {
				$sum += (float) $entry->post_content;
			}
			update_term_meta( $term->term_id, self::META_PRINCIPAL, (string) abs( $sum ) );
			return;
		}

		$principal_sign = $stored_principal >= 0 ? 1 : -1;
		$origin_sum     = 0.0;
		foreach ( $entries as $entry ) {
			$sign = ( (float) $entry->post_content ) >= 0 ? 1 : -1;
			if ( $sign === $principal_sign ) {
				$origin_sum += (float) $entry->post_content;
			}
		}
		update_term_meta( $term->term_id, self::META_PRINCIPAL, (string) abs( $origin_sum ) );
	}

	/**
	 * Renumbers every entry currently matched to this loan (see
	 * find_own_entries()) into a shared "{term name} i/n" description,
	 * ordered by due date — the same outcome "Group as Loan" produces by
	 * hand, but re-derivable at any time from whatever entries exist right
	 * now (a single entry is left untouched, since "1/1" carries no
	 * information). Called automatically by
	 * ESSF_Plan_Detector::detect_for_new_entry() the moment a second entry
	 * sharing a loan's exact name appears, so grouping needs no manual
	 * action; also safe to re-run later (e.g. a third same-titled entry
	 * shows up) — it always re-derives 1..n from scratch rather than
	 * trusting a stale count.
	 */
	public static function renumber_and_sync( WP_Term $term ): void {
		$entries = self::find_own_entries( $term );
		if ( count( $entries ) < 2 ) {
			return;
		}

		usort(
			$entries,
			static function ( $a, $b ) {
				return strcmp( $a->post_date_gmt, $b->post_date_gmt );
			}
		);

		$n = count( $entries );
		foreach ( $entries as $index => $entry ) {
			$new_title = $term->name . ' ' . ( $index + 1 ) . '/' . $n;
			if ( $entry->post_title !== $new_title ) {
				wp_update_post(
					[
						'ID'         => $entry->ID,
						'post_title' => $new_title,
					]
				);
			}
		}

		self::sync_principal_from_entries( $term );
	}

	/**
	 * Splits entries matched by title into two buckets relative to the
	 * loan's principal sign: same sign = the origin/disbursement(s) that
	 * established the loan (shown for reference, never counted as a
	 * payment — otherwise the very entry that creates the loan would look
	 * like it immediately paid itself off); opposite sign = real payments
	 * that reduce the outstanding balance.
	 *
	 * @return array{0: WP_Post[], 1: WP_Post[]} [ $origin, $payments ]
	 */
	private static function split_entries( WP_Term $term, float $principal ): array {
		$entries        = self::find_own_entries( $term );
		$principal_sign = $principal >= 0 ? 1 : -1;
		$origin         = [];
		$payments       = [];
		foreach ( $entries as $entry ) {
			$sign = ( (float) $entry->post_content ) >= 0 ? 1 : -1;
			if ( $sign === $principal_sign ) {
				$origin[] = $entry;
			} else {
				$payments[] = $entry;
			}
		}
		return [ $origin, $payments ];
	}

	/**
	 * Full payments table — origin/disbursements, payments, and the Add
	 * Payment form. Used by both the term edit screen and
	 * ESSF_Recurrence_Entries_Page's dedicated listing.
	 */
	public static function render_payments_summary( WP_Term $term, float $principal ): void {
		[ $origin, $payments ] = self::split_entries( $term, $principal );
		self::render_payments_overview( $term, $principal );

		if ( $origin ) {
			echo '<h4>' . esc_html__( 'Origin', 'essfinance' ) . '</h4>';
			self::render_entries_table( $origin );
		}

		if ( $payments ) {
			echo '<h4>' . esc_html__( 'Payments', 'essfinance' ) . '</h4>';
			self::render_entries_table( $payments );
		}

		?>
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
			<select id="essf_payment_status">
				<?php foreach ( ESSF_CPT::labels() as $status_value => $status_label ) : ?>
					<option value="<?php echo esc_attr( $status_value ); ?>" <?php selected( 'paid', $status_value ); ?>><?php echo esc_html( $status_label ); ?></option>
				<?php endforeach; ?>
			</select>
			<button type="button" class="button" id="essf_add_payment_btn"><?php esc_html_e( 'Add Payment', 'essfinance' ); ?></button>
		</p>
		<script>
		document.getElementById( 'essf_add_payment_btn' ).addEventListener( 'click', function () {
			var amount = document.getElementById( 'essf_payment_amount' ).value;
			var date   = document.getElementById( 'essf_payment_date' ).value;
			var status = document.getElementById( 'essf_payment_status' ).value;
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
				[ 'payment_status', status ],
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
		$via_detection = null !== self::$detection_meta;
		if ( $via_detection ) {
			$post = self::$detection_meta;
		} else {
			if ( ! isset( $_POST['essf_loan_principal'] ) || ! current_user_can( 'manage_options' ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- see docblock
				return;
			}
			$post = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- see docblock
		}

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
		// A payment is always the sign OPPOSITE the principal — see split_entries().
		$signed = $principal >= 0 ? -$amount : $amount;

		$date_raw = isset( $post['payment_date'] ) ? sanitize_text_field( $post['payment_date'] ) : '';
		$date     = preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date_raw ) ? $date_raw : current_time( 'Y-m-d' );

		// Effective (paid) = the date is when it was paid; Scheduled (pending)
		// = the date is only the expected due date, not yet paid.
		$status = isset( $post['payment_status'] ) && array_key_exists( $post['payment_status'], ESSF_CPT::labels() ) ? $post['payment_status'] : 'paid';
		$pay    = 'paid' === $status ? $date . ' 00:00:00' : '0000-00-00 00:00:00';

		ESSF_CPT::insert_entry( $term->name, $signed, $date . ' 00:00:00', $pay, $status, $category, get_current_user_id() );

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

	/**
	 * Loans not yet fully paid off — used by the "Link to loan" picker on
	 * the Add/Edit Entry form (see ESSF_Admin_Page::render_form()).
	 *
	 * @return array<int, array{term_id: int, name: string, remaining: float}>
	 */
	public static function get_unsettled(): array {
		$terms = get_terms(
			[
				'taxonomy'   => self::TAXONOMY,
				'hide_empty' => false,
			]
		);
		if ( is_wp_error( $terms ) ) {
			return [];
		}

		$unsettled = [];
		foreach ( $terms as $term ) {
			$principal      = (float) get_term_meta( $term->term_id, self::META_PRINCIPAL, true );
			[ , $payments ] = self::split_entries( $term, $principal );
			$paid           = self::sum_realized_payments( $payments );
			$remain         = abs( $principal ) - $paid;
			if ( $remain > 0.01 ) {
				$unsettled[] = [
					'term_id'   => $term->term_id,
					'name'      => $term->name,
					'remaining' => $remain,
				];
			}
		}

		usort( $unsettled, static fn( $a, $b ) => $b['remaining'] <=> $a['remaining'] );

		return $unsettled;
	}
}
