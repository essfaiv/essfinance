<?php
/**
 * EssFinance — Shortcodes (frontend)
 *
 * @package EssFinance
 * @license GPL-2.0-or-later
 */

defined( 'ABSPATH' ) || exit;

class ESSF_Shortcodes {

	const OPTION_MYACCOUNT_PAGE = 'essf_myaccount_page_id';
	const OPTION_CASHFLOW_PAGE  = 'essf_cashflow_page_id';

	/* @var bool */
	private static $styles_printed = false;

	public function __construct() {
		add_shortcode( 'essfinance_myaccount', [ $this, 'shortcode_myaccount' ] );
		add_shortcode( 'essfinance_cashflow', [ $this, 'shortcode_cashflow' ] );
		add_action( 'template_redirect', [ $this, 'handle_forms' ] );
		add_action( 'wp_enqueue_scripts', [ $this, 'maybe_enqueue_dashicons' ] );
	}

	public function maybe_enqueue_dashicons(): void {
		if ( ESSF_Settings::show_status_icons() ) {
			wp_enqueue_style( 'dashicons' );
		}
	}

	/* ── URL helpers ─────────────────────────────────── */

	public static function get_myaccount_url(): string {
		$id = (int) get_option( self::OPTION_MYACCOUNT_PAGE );
		return $id ? (string) get_permalink( $id ) : home_url();
	}

	public static function get_cashflow_url(): string {
		$id = (int) get_option( self::OPTION_CASHFLOW_PAGE );
		return $id ? (string) get_permalink( $id ) : home_url();
	}

	/* ── Form dispatcher ─────────────────────────────── */

	public function handle_forms(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- dispatcher only; each sub-handler verifies its own nonce
		$post_action = sanitize_key( wp_unslash( $_POST['essf_action'] ?? '' ) );
		$get_action  = sanitize_key( wp_unslash( $_GET['essf_action'] ?? '' ) );

		if ( $post_action ) {
			switch ( $post_action ) {
				case 'login':
					$this->handle_login(); break;
				case 'register':
					$this->handle_register(); break;
				case 'add_entry':
				case 'edit_entry':
					$this->handle_save_entry( $post_action ); break;
				case 'import':
					$this->handle_import(); break;
				case 'bulk_action':
					$this->handle_bulk_action(); break;
			}
		}

		if ( $get_action ) {
			switch ( $get_action ) {
				case 'delete_entry':
					$this->handle_delete_entry(); break;
				case 'paid_today':
					$this->handle_paid_today(); break;
				case 'toggle_type':
					$this->handle_toggle_type(); break;
				case 'export':
					$this->handle_export(); break;
			}
		}
	}

	private function handle_login(): void {
		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ?? '' ) ), 'essf_login' ) ) {
			wp_safe_redirect( add_query_arg( 'essf_error', 'invalid_nonce', self::get_myaccount_url() ) );
			exit;
		}

		$user = wp_signon(
			[
				'user_login'    => sanitize_user( wp_unslash( $_POST['log'] ?? '' ) ),
				'user_password' => wp_unslash( $_POST['pwd'] ?? '' ), // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- password must not be sanitized before wp_signon()
				'remember'      => ! empty( $_POST['rememberme'] ),
			],
			is_ssl()
		);

		if ( is_wp_error( $user ) ) {
			wp_safe_redirect( add_query_arg( 'essf_error', rawurlencode( $user->get_error_code() ), self::get_myaccount_url() ) );
		} else {
			$redirect = esc_url_raw( wp_unslash( $_POST['redirect_to'] ?? self::get_cashflow_url() ) );
			wp_safe_redirect( $redirect );
		}
		exit;
	}

	private function handle_register(): void {
		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ?? '' ) ), 'essf_register' ) ) {
			wp_safe_redirect( add_query_arg( 'essf_error', 'invalid_nonce', self::get_myaccount_url() ) );
			exit;
		}

		if ( ! get_option( 'users_can_register' ) ) {
			wp_safe_redirect( add_query_arg( 'essf_error', 'registration_disabled', self::get_myaccount_url() ) );
			exit;
		}

		$result = register_new_user(
			sanitize_user( wp_unslash( $_POST['user_login'] ?? '' ) ),
			sanitize_email( wp_unslash( $_POST['user_email'] ?? '' ) )
		);

		if ( is_wp_error( $result ) ) {
			wp_safe_redirect(
				add_query_arg(
					[
						'essf_tab'   => 'register',
						'essf_error' => rawurlencode( $result->get_error_code() ),
					],
					self::get_myaccount_url()
				)
			);
		} else {
			wp_safe_redirect( add_query_arg( 'essf_registered', '1', self::get_myaccount_url() ) );
		}
		exit;
	}

	private function handle_save_entry( string $action ): void {
		if ( ! is_user_logged_in() ) {
			wp_safe_redirect( self::get_myaccount_url() );
			exit;
		}

		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ?? '' ) ), 'essf_save_entry' ) ) {
			wp_safe_redirect( self::get_cashflow_url() );
			exit;
		}

		$description = sanitize_text_field( wp_unslash( $_POST['essf_description'] ?? '' ) );
		$amount      = (float) str_replace( ',', '.', wp_unslash( $_POST['essf_amount'] ?? '0' ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- cast to float immediately; value is never output
		$is_income   = ! empty( $_POST['essf_is_income'] ) && '1' === sanitize_text_field( wp_unslash( $_POST['essf_is_income'] ) );
		$status_raw  = sanitize_key( wp_unslash( $_POST['essf_status'] ?? '' ) );
		$status      = array_key_exists( $status_raw, ESSF_CPT::labels() ) ? $status_raw : 'pending';
		$due_raw     = sanitize_text_field( wp_unslash( $_POST['essf_due_date'] ?? '' ) );
		$pay_raw     = sanitize_text_field( wp_unslash( $_POST['essf_pay_date'] ?? '' ) );
		$category    = sanitize_key( wp_unslash( $_POST['essf_category'] ?? 'uncategorized' ) );

		$amount  = $is_income ? abs( $amount ) : -abs( $amount );
		$due_dt  = $due_raw ? DateTime::createFromFormat( 'Y-m-d', $due_raw ) : false;
		$pay_dt  = $pay_raw ? DateTime::createFromFormat( 'Y-m-d', $pay_raw ) : false;
		$due_gmt = $due_dt ? $due_dt->format( 'Y-m-d' ) . ' 00:00:00' : '0000-00-00 00:00:00';
		$pay_gmt = $pay_dt ? $pay_dt->format( 'Y-m-d' ) . ' 00:00:00' : '0000-00-00 00:00:00';

		global $wpdb;

		if ( 'edit_entry' === $action ) {
			$post_id = absint( wp_unslash( $_POST['essf_entry_id'] ?? 0 ) );
			$post    = get_post( $post_id );

			if ( ! $post || 'essf_cashflow' !== $post->post_type || get_current_user_id() !== (int) $post->post_author ) {
				wp_safe_redirect( self::get_cashflow_url() );
				exit;
			}

			$wpdb->update(
				$wpdb->posts,
				[
					'post_title'        => $description,
					'post_content'      => (string) $amount,
					'post_status'       => $status,
					'post_date_gmt'     => $due_gmt,
					'post_modified_gmt' => $pay_gmt,
				],
				[ 'ID' => $post_id ],
				[ '%s', '%s', '%s', '%s', '%s' ],
				[ '%d' ]
			);
			clean_post_cache( $post_id );

			$order_date = '0000-00-00 00:00:00' !== $pay_gmt ? $pay_gmt : $due_gmt;
			update_post_meta( $post_id, '_order_date', substr( $order_date, 0, 10 ) );
			wp_set_post_terms( $post_id, [ ESSF_Category::term_id_for_slug( $category ) ], ESSF_Category::TAXONOMY );

			wp_safe_redirect( add_query_arg( 'essf_msg', 'updated', self::get_cashflow_url() ) );
		} else {
			$post_date = $due_dt ? $due_dt->format( 'Y-m-d' ) . ' 00:00:00' : current_time( 'mysql' );

			$post_id = wp_insert_post(
				[
					'post_type'     => 'essf_cashflow',
					'post_title'    => $description,
					'post_content'  => (string) $amount,
					'post_status'   => $status,
					'post_author'   => get_current_user_id(),
					'post_date'     => $post_date,
					'post_date_gmt' => $due_gmt,
				]
			);

			if ( $post_id && ! is_wp_error( $post_id ) ) {
				$wpdb->update(
					$wpdb->posts,
					[ 'post_modified_gmt' => $pay_gmt ],
					[ 'ID' => $post_id ],
					[ '%s' ],
					[ '%d' ]
				);
				$order_date = '0000-00-00 00:00:00' !== $pay_gmt ? $pay_gmt : $due_gmt;
				update_post_meta( $post_id, '_order_date', substr( $order_date, 0, 10 ) );
				wp_set_post_terms( $post_id, [ ESSF_Category::term_id_for_slug( $category ) ], ESSF_Category::TAXONOMY );
			}

			wp_safe_redirect( add_query_arg( 'essf_msg', 'added', self::get_cashflow_url() ) );
		}
		exit;
	}

	private function handle_delete_entry(): void {
		if ( ! is_user_logged_in() ) {
			wp_safe_redirect( self::get_myaccount_url() );
			exit;
		}

		$post_id = absint( wp_unslash( $_GET['entry'] ?? 0 ) );

		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ?? '' ) ), 'essf_delete_' . $post_id ) ) {
			wp_safe_redirect( self::get_cashflow_url() );
			exit;
		}

		$post = get_post( $post_id );
		if ( $post && 'essf_cashflow' === $post->post_type && get_current_user_id() === (int) $post->post_author ) {
			wp_delete_post( $post_id, true );
		}

		wp_safe_redirect( add_query_arg( 'essf_msg', 'deleted', self::get_cashflow_url() ) );
		exit;
	}

	private function handle_paid_today(): void {
		if ( ! is_user_logged_in() ) {
			wp_safe_redirect( self::get_myaccount_url() );
			exit;
		}

		$post_id = absint( wp_unslash( $_GET['entry'] ?? 0 ) );

		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ?? '' ) ), 'essf_paid_today_' . $post_id ) ) {
			wp_safe_redirect( self::get_cashflow_url() );
			exit;
		}

		$post = get_post( $post_id );
		if ( $post && 'essf_cashflow' === $post->post_type && get_current_user_id() === (int) $post->post_author ) {
			global $wpdb;
			$now = current_time( 'mysql', true );
			$wpdb->update(
				$wpdb->posts,
				[
					'post_status'       => 'paid',
					'post_modified_gmt' => $now,
					'post_modified'     => current_time( 'mysql' ),
				],
				[ 'ID' => $post_id ]
			);
			update_post_meta( $post_id, '_order_date', substr( $now, 0, 10 ) );
			clean_post_cache( $post_id );
		}

		wp_safe_redirect( $this->cashflow_url_with_filters( [ 'essf_msg' => 'paid' ] ) );
		exit;
	}

	private function handle_toggle_type(): void {
		if ( ! is_user_logged_in() ) {
			wp_safe_redirect( self::get_myaccount_url() );
			exit;
		}

		$post_id = absint( wp_unslash( $_GET['entry'] ?? 0 ) );

		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ?? '' ) ), 'essf_toggle_type_' . $post_id ) ) {
			wp_safe_redirect( self::get_cashflow_url() );
			exit;
		}

		$post = get_post( $post_id );
		if ( $post && 'essf_cashflow' === $post->post_type && get_current_user_id() === (int) $post->post_author ) {
			global $wpdb;
			$wpdb->update(
				$wpdb->posts,
				[ 'post_content' => (string) -( (float) $post->post_content ) ],
				[ 'ID' => $post_id ],
				[ '%s' ],
				[ '%d' ]
			);
			clean_post_cache( $post_id );
		}

		wp_safe_redirect( $this->cashflow_url_with_filters( [ 'essf_msg' => 'updated' ] ) );
		exit;
	}

	private function handle_bulk_action(): void {
		if ( ! is_user_logged_in() ) {
			wp_safe_redirect( self::get_myaccount_url() );
			exit;
		}

		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ?? '' ) ), 'essf_bulk_action' ) ) {
			wp_safe_redirect( self::get_cashflow_url() );
			exit;
		}

		$action = sanitize_key( wp_unslash( $_POST['bulk_action'] ?? '' ) );
		$ids    = array_map( 'intval', (array) ( $_POST['entries'] ?? [] ) );

		if ( ! $action || empty( $ids ) ) {
			wp_safe_redirect( self::get_cashflow_url() );
			exit;
		}

		global $wpdb;

		foreach ( $ids as $post_id ) {
			$post = get_post( $post_id );
			if ( ! $post || 'essf_cashflow' !== $post->post_type || get_current_user_id() !== (int) $post->post_author ) {
				continue;
			}

			switch ( $action ) {
				case 'mark_paid':
					$now = current_time( 'mysql', true );
					$wpdb->update(
						$wpdb->posts,
						[
							'post_status'       => 'paid',
							'post_modified_gmt' => $now,
						],
						[ 'ID' => $post_id ],
						[ '%s', '%s' ],
						[ '%d' ]
					);
					update_post_meta( $post_id, '_order_date', substr( $now, 0, 10 ) );
					clean_post_cache( $post_id );
					break;
				case 'mark_pending':
					$wpdb->update( $wpdb->posts, [ 'post_status' => 'pending' ], [ 'ID' => $post_id ], [ '%s' ], [ '%d' ] );
					$due = substr( $post->post_date_gmt, 0, 10 );
					if ( $due && '0000-00-00' !== $due ) {
						update_post_meta( $post_id, '_order_date', $due );
					}
					clean_post_cache( $post_id );
					break;
				case 'make_income':
					$amount = (float) $post->post_content;
					if ( $amount < 0 ) {
						$wpdb->update( $wpdb->posts, [ 'post_content' => (string) -$amount ], [ 'ID' => $post_id ], [ '%s' ], [ '%d' ] );
						clean_post_cache( $post_id );
					}
					break;
				case 'make_expense':
					$amount = (float) $post->post_content;
					if ( $amount > 0 ) {
						$wpdb->update( $wpdb->posts, [ 'post_content' => (string) -$amount ], [ 'ID' => $post_id ], [ '%s' ], [ '%d' ] );
						clean_post_cache( $post_id );
					}
					break;
				case 'delete':
					wp_delete_post( $post_id, true );
					break;
				case 'set_category':
					$bulk_category = sanitize_key( wp_unslash( $_POST['essf_bulk_category'] ?? '' ) );
					if ( $bulk_category ) {
						wp_set_post_terms( $post_id, [ ESSF_Category::term_id_for_slug( $bulk_category ) ], ESSF_Category::TAXONOMY );
					}
					break;
				case 'auto_set_category':
					$guessed_slug = ESSF_Category::guess_slug_from_description( $post->post_title );
					wp_set_post_terms( $post_id, [ ESSF_Category::term_id_for_slug( $guessed_slug ) ], ESSF_Category::TAXONOMY );
					break;
			}
		}

		$carry = [];
		foreach ( [ 'essf_status', 'essf_type', 'essf_m', 'essf_cat', 'paged', 'essf_search' ] as $key ) {
			$val = sanitize_key( wp_unslash( $_POST[ $key ] ?? '' ) );
			if ( '' !== $val ) {
				$carry[ $key ] = $val;
			}
		}
		wp_safe_redirect( add_query_arg( array_merge( $carry, [ 'essf_msg' => 'bulk_done' ] ), self::get_cashflow_url() ) );
		exit;
	}

	/* ── Styles ──────────────────────────────────────── */

	private function print_styles(): void {
		if ( self::$styles_printed ) {
			return;
		}
		self::$styles_printed = true;
		?>
		<style>
		.essf-wrap { max-width: 900px; margin: 0 auto; font-family: inherit; }
		.essf-notice { padding: 10px 14px; border-radius: 4px; margin-bottom: 16px; }
		.essf-notice.essf-error { background: #fde8e8; color: #c0392b; border-left: 4px solid #c0392b; }
		.essf-notice.essf-success { background: #e8f8e8; color: #27ae60; border-left: 4px solid #27ae60; }
		.essf-btn { display: inline-block; padding: 8px 18px; background: #2271b1; color: #fff; border: none; border-radius: 4px; cursor: pointer; text-decoration: none; font-size: 14px; }
		.essf-btn:hover { background: #135e96; color: #fff; }
		.essf-btn.essf-btn-secondary { background: #f0f0f1; color: #2c3338; }
		.essf-btn.essf-btn-secondary:hover { background: #dcdcde; color: #2c3338; }
		/* Auth */
		.essf-auth { max-width: 420px; }
		.essf-auth-tabs { display: flex; gap: 4px; margin-bottom: 20px; border-bottom: 2px solid #ddd; padding-bottom: 0; }
		.essf-tab { padding: 8px 16px; text-decoration: none; color: #50575e; border-bottom: 2px solid transparent; margin-bottom: -2px; }
		.essf-tab.active { color: #2271b1; border-bottom-color: #2271b1; font-weight: 600; }
		.essf-form p { margin-bottom: 14px; }
		.essf-form label { display: block; font-size: 14px; font-weight: 500; margin-bottom: 4px; }
		.essf-form input[type=text], .essf-form input[type=email], .essf-form input[type=password] { width: 100%; padding: 8px 10px; border: 1px solid #c3c4c7; border-radius: 4px; font-size: 14px; box-sizing: border-box; }
		.essf-remember { display: flex; justify-content: space-between; align-items: center; }
		/* Account */
		.essf-account p { display: flex; gap: 10px; }
		/* Cash Flow list */
		.essf-toolbar-row { display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px; flex-wrap: wrap; gap: 10px; }
		.essf-toolbar-left, .essf-toolbar-right { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
		.essf-items-count { font-size: 13px; color: #50575e; white-space: nowrap; }
		.essf-filters { display: flex; gap: 8px; flex-wrap: wrap; }
		.essf-filters select { padding: 6px 8px; border: 1px solid #c3c4c7; border-radius: 4px; font-size: 13px; }
		.essf-table { width: 100%; border-collapse: collapse; font-size: 14px; }
		.essf-table th { text-align: left; padding: 8px 10px; border-bottom: 2px solid #c3c4c7; font-weight: 600; white-space: nowrap; }
		.essf-table td { padding: 8px 10px; border-bottom: 1px solid #ece9e9; vertical-align: middle; }
		.essf-table tr:last-child td { border-bottom: none; }
		.essf-status { display: inline-block; padding: 2px 8px; border-radius: 10px; font-size: 12px; font-weight: 600; }
		.essf-badges-on .essf-status-paid .essf-status { background: #d4edda; color: #155724; }
		.essf-badges-on .essf-status-pending .essf-status { background: #fff3cd; color: #856404; }
		.essf-badges-on .essf-status-overdue .essf-status { background: #f8d7da; color: #721c24; }
		.essf-amount { font-weight: 600; white-space: nowrap; }
		.essf-colors-on .essf-type-income .essf-amount { color: #27ae60; }
		.essf-colors-on .essf-type-expense .essf-amount { color: #c0392b; }
		.essf-row-actions { margin-top: 3px; visibility: hidden; }
		.essf-row:hover .essf-row-actions,
		.essf-row:focus-within .essf-row-actions { visibility: visible; }
		.essf-row-actions a { font-size: 12px; text-decoration: none; color: #2271b1; }
		.essf-row-actions a:hover { text-decoration: underline; }
		.essf-row-actions a.essf-delete { color: #b32d2e; }
		.essf-row-actions .essf-sep { color: #bbb; padding: 0 3px; }
		.essf-empty { color: #6b7280; font-style: italic; }
		.essf-col-toggle { position: relative; display: inline-block; }
		.essf-col-toggle-btn { padding: 6px 10px; background: #f0f0f1; color: #2c3338; border: 1px solid #c3c4c7; border-radius: 4px; font-size: 13px; cursor: pointer; }
		.essf-col-menu { display: none; position: absolute; right: 0; top: 100%; margin-top: 2px; background: #fff; border: 1px solid #c3c4c7; border-radius: 4px; padding: 8px; min-width: 140px; z-index: 100; box-shadow: 0 2px 8px rgba(0,0,0,.1); }
		.essf-col-toggle.open .essf-col-menu { display: block; }
		.essf-col-menu label { display: flex; align-items: center; gap: 6px; padding: 3px 0; font-size: 13px; cursor: pointer; white-space: nowrap; }
		.essf-hide-col-type [data-col="type"] { display: none; }
		.essf-hide-col-date [data-col="date"] { display: none; }
		.essf-hide-col-status [data-col="status"] { display: none; }
		.essf-hide-col-amount [data-col="amount"] { display: none; }
		/* Entry form */
		.essf-entry-form h3 { margin-top: 0; }
		.essf-entry-form .essf-form input[type=text],
		.essf-entry-form .essf-form input[type=number],
		.essf-entry-form .essf-form input[type=date],
		.essf-entry-form .essf-form select { width: 100%; max-width: 400px; padding: 8px 10px; border: 1px solid #c3c4c7; border-radius: 4px; font-size: 14px; box-sizing: border-box; }
		.essf-entry-form .essf-form-actions { display: flex; gap: 10px; }
		.essf-dates-row { display: flex; gap: 14px; }
		.essf-dates-row > p { margin: 0 0 14px; }
		.essf-field-row-amount { display: flex; align-items: flex-end; gap: 14px; }
		.essf-field-row-amount > p { margin: 0; }
		.essf-income-label { display: flex; align-items: center; gap: 5px; font-size: 14px; padding-bottom: 9px; white-space: nowrap; cursor: pointer; }
		/* Search */
		.essf-search-form { display: flex; gap: 6px; align-items: center; }
		.essf-search-form input[type=search] { padding: 6px 10px; border: 1px solid #c3c4c7; border-radius: 4px; font-size: 13px; width: 180px; }
		/* Bulk actions select inside row 2 */
		.essf-toolbar-row2 select[name=bulk_action] { padding: 5px 8px; border: 1px solid #c3c4c7; border-radius: 4px; font-size: 13px; }
		/* Checkbox column */
		.essf-table .essf-cb { width: 28px; padding-right: 4px; }
		.essf-table .essf-cb input[type=checkbox] { margin: 0; cursor: pointer; }
		/* Pagination */
		.essf-pagination { display: flex; gap: 4px; margin-top: 16px; }
		.essf-page { display: inline-block; padding: 6px 10px; border: 1px solid #c3c4c7; border-radius: 4px; text-decoration: none; color: #2271b1; font-size: 13px; }
		.essf-page.current { background: #2271b1; color: #fff; border-color: #2271b1; }
		</style>
		<?php
	}

	/* ── My Account shortcode ────────────────────────── */

	public function shortcode_myaccount(): string {
		ob_start();
		$this->print_styles();
		echo '<div class="essf-wrap">';
		if ( is_user_logged_in() ) {
			$this->render_account_dashboard();
		} else {
			$this->render_auth_forms();
		}
		echo '</div>';
		return ob_get_clean();
	}

	private function render_account_dashboard(): void {
		$user = wp_get_current_user();
		?>
		<div class="essf-account">
			<?php // translators: %s is the user's display name. ?>
			<h2><?php printf( esc_html__( 'Hello, %s', 'essfinance' ), esc_html( $user->display_name ) ); ?></h2>
			<p>
				<a href="<?php echo esc_url( self::get_cashflow_url() ); ?>" class="essf-btn">
					<?php esc_html_e( 'My Cash Flow', 'essfinance' ); ?>
				</a>
				<a href="<?php echo esc_url( wp_logout_url( self::get_myaccount_url() ) ); ?>" class="essf-btn essf-btn-secondary">
					<?php esc_html_e( 'Log out', 'essfinance' ); ?>
				</a>
			</p>
		</div>
		<?php
	}

	private function render_auth_forms(): void {
		$tab        = sanitize_key( wp_unslash( $_GET['essf_tab'] ?? 'login' ) );
		$error      = sanitize_key( wp_unslash( $_GET['essf_error'] ?? '' ) );
		$registered = ! empty( $_GET['essf_registered'] );
		$can_reg    = (bool) get_option( 'users_can_register' );
		?>
		<div class="essf-auth">
			<div class="essf-auth-tabs">
				<a href="<?php echo esc_url( remove_query_arg( 'essf_tab' ) ); ?>" class="essf-tab<?php echo 'login' !== $tab ? '' : ' active'; ?>">
					<?php esc_html_e( 'Login', 'essfinance' ); ?>
				</a>
				<?php if ( $can_reg ) : ?>
				<a href="<?php echo esc_url( add_query_arg( 'essf_tab', 'register' ) ); ?>" class="essf-tab<?php echo 'register' === $tab ? ' active' : ''; ?>">
					<?php esc_html_e( 'Register', 'essfinance' ); ?>
				</a>
				<?php endif; ?>
			</div>

			<?php if ( $error ) : ?>
				<div class="essf-notice essf-error"><?php echo esc_html( $this->error_message( $error ) ); ?></div>
			<?php endif; ?>

			<?php if ( $registered ) : ?>
				<div class="essf-notice essf-success"><?php esc_html_e( 'Registration successful. Check your email for your password.', 'essfinance' ); ?></div>
			<?php endif; ?>

			<?php if ( 'register' === $tab && $can_reg ) : ?>
				<form method="post" class="essf-form">
					<?php wp_nonce_field( 'essf_register' ); ?>
					<input type="hidden" name="essf_action" value="register">
					<p><label><?php esc_html_e( 'Username', 'essfinance' ); ?><br>
					<input type="text" name="user_login" required autocomplete="username"></label></p>
					<p><label><?php esc_html_e( 'Email address', 'essfinance' ); ?><br>
					<input type="email" name="user_email" required autocomplete="email"></label></p>
					<p><button type="submit" class="essf-btn"><?php esc_html_e( 'Register', 'essfinance' ); ?></button></p>
				</form>
			<?php else : ?>
				<form method="post" class="essf-form">
					<?php wp_nonce_field( 'essf_login' ); ?>
					<input type="hidden" name="essf_action" value="login">
					<input type="hidden" name="redirect_to" value="<?php echo esc_attr( self::get_cashflow_url() ); ?>">
					<p><label><?php esc_html_e( 'Username or email address', 'essfinance' ); ?><br>
					<input type="text" name="log" required autocomplete="username"></label></p>
					<p><label><?php esc_html_e( 'Password', 'essfinance' ); ?><br>
					<input type="password" name="pwd" required autocomplete="current-password"></label></p>
					<p class="essf-remember">
						<label><input type="checkbox" name="rememberme" value="forever"> <?php esc_html_e( 'Remember me', 'essfinance' ); ?></label>
						<a href="<?php echo esc_url( wp_lostpassword_url( self::get_myaccount_url() ) ); ?>"><?php esc_html_e( 'Lost password?', 'essfinance' ); ?></a>
					</p>
					<p><button type="submit" class="essf-btn"><?php esc_html_e( 'Log in', 'essfinance' ); ?></button></p>
				</form>
			<?php endif; ?>
		</div>
		<?php
	}

	private function error_message( string $code ): string {
		$map = [
			'invalid_username'      => __( 'Unknown username.', 'essfinance' ),
			'invalid_email'         => __( 'Unknown email address.', 'essfinance' ),
			'incorrect_password'    => __( 'Incorrect password.', 'essfinance' ),
			'empty_username'        => __( 'Please enter your username.', 'essfinance' ),
			'empty_password'        => __( 'Please enter your password.', 'essfinance' ),
			'registration_disabled' => __( 'User registration is currently not allowed.', 'essfinance' ),
			'username_exists'       => __( 'This username is already registered.', 'essfinance' ),
			'email_exists'          => __( 'This email is already registered.', 'essfinance' ),
			'invalid_nonce'         => __( 'Security check failed. Please try again.', 'essfinance' ),
		];
		return $map[ $code ] ?? __( 'An error occurred. Please try again.', 'essfinance' );
	}

	/* ── Cash Flow shortcode ─────────────────────────── */

	public function shortcode_cashflow(): string {
		ob_start();
		$this->print_styles();
		echo '<div class="essf-wrap">';

		if ( ! is_user_logged_in() ) {
			printf(
				'<div class="essf-notice"><a href="%s">%s</a> %s</div>',
				esc_url( self::get_myaccount_url() ),
				esc_html__( 'Log in', 'essfinance' ),
				esc_html__( 'to view your cash flow.', 'essfinance' )
			);
			echo '</div>';
			return ob_get_clean();
		}

		$view = sanitize_key( wp_unslash( $_GET['essf_view'] ?? 'list' ) );

		if ( 'add' === $view ) {
			$this->render_entry_form( null );
		} elseif ( 'edit' === $view ) {
			$entry_id = absint( wp_unslash( $_GET['entry'] ?? 0 ) );
			$entry    = get_post( $entry_id );
			if ( $entry && 'essf_cashflow' === $entry->post_type && get_current_user_id() === (int) $entry->post_author ) {
				$this->render_entry_form( $entry );
			} else {
				wp_safe_redirect( self::get_cashflow_url() );
				exit;
			}
		} elseif ( 'import' === $view ) {
			$this->render_import_form();
		} else {
			$this->render_cashflow_list();
		}

		echo '</div>';
		return ob_get_clean();
	}

	private function render_cashflow_list(): void {
		$paged         = max( 1, absint( wp_unslash( $_GET['paged'] ?? 1 ) ) );
		$status_filter = sanitize_key( wp_unslash( $_GET['essf_status'] ?? '' ) );
		$type_filter   = sanitize_key( wp_unslash( $_GET['essf_type'] ?? '' ) );
		$month_filter  = sanitize_key( wp_unslash( $_GET['essf_m'] ?? '' ) );
		$cat_filter    = sanitize_key( wp_unslash( $_GET['essf_cat'] ?? '' ) );
		$search        = sanitize_text_field( wp_unslash( $_GET['essf_search'] ?? '' ) );
		$msg           = sanitize_key( wp_unslash( $_GET['essf_msg'] ?? '' ) );
		$imported      = isset( $_GET['essf_imported'] ) ? (int) $_GET['essf_imported'] : -1;

		$needs_php_filter = $type_filter || 'overdue' === $status_filter || (bool) $month_filter;

		if ( 'overdue' === $status_filter ) {
			$post_statuses = [ 'pending' ];
		} elseif ( $status_filter ) {
			$post_statuses = [ $status_filter ];
		} else {
			$post_statuses = [ 'paid', 'pending' ];
		}

		$query_args = [
			'post_type'      => 'essf_cashflow',
			'post_status'    => $post_statuses,
			'author'         => get_current_user_id(),
			'posts_per_page' => $needs_php_filter ? -1 : 20,
			'paged'          => $needs_php_filter ? 1 : $paged,
			'orderby'        => 'date',
			'order'          => 'DESC',
		];
		if ( $search ) {
			$query_args['s'] = $search;
		}
		if ( $cat_filter ) {
			$query_args['tax_query'] = [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
				[
					'taxonomy' => ESSF_Category::TAXONOMY,
					'field'    => 'slug',
					'terms'    => [ $cat_filter ],
				],
			];
		}

		$query = new WP_Query( $query_args );

		$entries = $query->posts;
		$today   = gmdate( 'Y-m-d' );

		if ( 'overdue' === $status_filter ) {
			$entries = array_values(
				array_filter(
					$entries,
					function ( $p ) use ( $today ) {
						$due = substr( $p->post_date_gmt, 0, 10 );
						return '0000-00-00' !== $due && $due < $today;
					}
				)
			);
		} elseif ( 'income' === $type_filter ) {
			$entries = array_values(
				array_filter(
					$entries,
					function ( $p ) {
						return (float) $p->post_content >= 0;
					}
				)
			);
		} elseif ( 'expense' === $type_filter ) {
			$entries = array_values(
				array_filter(
					$entries,
					function ( $p ) {
						return (float) $p->post_content < 0;
					}
				)
			);
		}

		if ( $month_filter && preg_match( '/^\d{6}$/', $month_filter ) ) {
			$ym_prefix = substr( $month_filter, 0, 4 ) . '-' . substr( $month_filter, 4, 2 ) . '-';
			$entries   = array_values(
				array_filter(
					$entries,
					function ( $p ) use ( $ym_prefix ) {
						$od = get_post_meta( $p->ID, '_order_date', true );
						if ( ! $od ) {
							$pay = substr( $p->post_modified_gmt, 0, 10 );
							$due = substr( $p->post_date_gmt, 0, 10 );
							$od  = ( 'paid' === $p->post_status && $pay && '0000-00-00' !== $pay ) ? $pay : $due;
						}
						return $od && 0 === strpos( $od, $ym_prefix );
					}
				)
			);
		}

		$total_count = $needs_php_filter ? count( $entries ) : $query->found_posts;
		$months      = $this->get_due_date_months();
		?>
		<?php
		$show_badge      = ESSF_Settings::show_status_badge();
		$show_icons      = ESSF_Settings::show_status_icons();
		$show_colors     = ESSF_Settings::show_amount_colors();
		$show_pos_prefix = ESSF_Settings::show_positive_prefix();
		$show_neg_prefix = ESSF_Settings::show_negative_prefix();

		$export_url = wp_nonce_url( add_query_arg( 'essf_action', 'export', self::get_cashflow_url() ), 'essf_export' );
		$import_url = add_query_arg( 'essf_view', 'import', self::get_cashflow_url() );
		?>
		<div class="essf-cashflow<?php echo $show_badge ? ' essf-badges-on' : ''; ?><?php echo $show_colors ? ' essf-colors-on' : ''; ?>">
			<?php if ( $msg ) : ?>
				<div class="essf-notice essf-success"><?php echo esc_html( $this->action_message( $msg ) ); ?></div>
			<?php endif; ?>
			<?php if ( $imported >= 0 ) : ?>
				<?php // translators: %d is the number of imported entries. ?>
				<div class="essf-notice essf-success"><?php echo esc_html( sprintf( _n( '%d entry imported.', '%d entries imported.', $imported, 'essfinance' ), $imported ) ); ?></div>
			<?php endif; ?>

			<div class="essf-toolbar-row essf-toolbar-row1">
				<div class="essf-toolbar-left">
					<a href="<?php echo esc_url( add_query_arg( 'essf_view', 'add', self::get_cashflow_url() ) ); ?>" class="essf-btn">+ <?php esc_html_e( 'Add Entry', 'essfinance' ); ?></a>
					<a href="<?php echo esc_url( $import_url ); ?>" class="essf-btn essf-btn-secondary"><?php esc_html_e( 'Import', 'essfinance' ); ?></a>
					<a href="<?php echo esc_url( $export_url ); ?>" class="essf-btn essf-btn-secondary"><?php esc_html_e( 'Export', 'essfinance' ); ?></a>
				</div>
				<div class="essf-toolbar-right">
					<form method="get" action="<?php echo esc_url( self::get_cashflow_url() ); ?>" class="essf-search-form">
						<input type="hidden" name="essf_status" value="<?php echo esc_attr( $status_filter ); ?>">
						<input type="hidden" name="essf_type" value="<?php echo esc_attr( $type_filter ); ?>">
						<input type="hidden" name="essf_m" value="<?php echo esc_attr( $month_filter ); ?>">
						<input type="hidden" name="essf_cat" value="<?php echo esc_attr( $cat_filter ); ?>">
						<input type="search" name="essf_search" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'Search entries…', 'essfinance' ); ?>">
						<button type="submit" class="essf-btn essf-btn-secondary"><?php esc_html_e( 'Search', 'essfinance' ); ?></button>
						<?php if ( $search ) : ?>
							<a href="<?php echo esc_url( remove_query_arg( 'essf_search' ) ); ?>" class="essf-btn essf-btn-secondary">&times;</a>
						<?php endif; ?>
					</form>
					<div class="essf-col-toggle" id="essf-col-toggle">
						<button class="essf-col-toggle-btn" type="button"><?php esc_html_e( 'Columns', 'essfinance' ); ?> &#9662;</button>
						<div class="essf-col-menu" id="essf-col-menu">
							<label><input type="checkbox" value="date"> <?php esc_html_e( 'Date', 'essfinance' ); ?></label>
							<label><input type="checkbox" value="type"> <?php esc_html_e( 'Type', 'essfinance' ); ?></label>
							<label><input type="checkbox" value="category"> <?php esc_html_e( 'Category', 'essfinance' ); ?></label>
							<label><input type="checkbox" value="status"> <?php esc_html_e( 'Status', 'essfinance' ); ?></label>
							<label><input type="checkbox" value="amount"> <?php esc_html_e( 'Amount', 'essfinance' ); ?></label>
						</div>
					</div>
				</div>
			</div>

			<?php if ( empty( $entries ) ) : ?>
				<p class="essf-empty"><?php esc_html_e( 'No entries found.', 'essfinance' ); ?></p>
			<?php else : ?>
				<form method="post">
					<?php wp_nonce_field( 'essf_bulk_action' ); ?>
					<input type="hidden" name="essf_action" value="bulk_action">
					<input type="hidden" name="essf_status" value="<?php echo esc_attr( $status_filter ); ?>">
					<input type="hidden" name="essf_type" value="<?php echo esc_attr( $type_filter ); ?>">
					<input type="hidden" name="essf_m" value="<?php echo esc_attr( $month_filter ); ?>">
					<input type="hidden" name="essf_cat" value="<?php echo esc_attr( $cat_filter ); ?>">
					<input type="hidden" name="essf_search" value="<?php echo esc_attr( $search ); ?>">
					<input type="hidden" name="paged" value="<?php echo esc_attr( (string) $paged ); ?>">
					<div class="essf-toolbar-row essf-toolbar-row2">
						<div class="essf-toolbar-left">
							<select name="bulk_action">
								<option value=""><?php esc_html_e( 'Bulk actions', 'essfinance' ); ?></option>
								<option value="mark_paid"><?php esc_html_e( 'Mark as Paid Today', 'essfinance' ); ?></option>
								<option value="mark_pending"><?php esc_html_e( 'Mark as Pending', 'essfinance' ); ?></option>
								<option value="make_income"><?php esc_html_e( 'Make Income', 'essfinance' ); ?></option>
								<option value="make_expense"><?php esc_html_e( 'Make Expense', 'essfinance' ); ?></option>
								<option value="set_category"><?php esc_html_e( 'Set Category', 'essfinance' ); ?></option>
								<option value="auto_set_category"><?php esc_html_e( 'Auto Set Category', 'essfinance' ); ?></option>
								<option value="delete"><?php esc_html_e( 'Delete', 'essfinance' ); ?></option>
							</select>
							<select name="essf_bulk_category">
								<?php foreach ( ESSF_Category::get_ordered_terms() as $term ) : ?>
									<option value="<?php echo esc_attr( $term->slug ); ?>"><?php echo esc_html( $term->name ); ?></option>
								<?php endforeach; ?>
							</select>
							<button type="submit" class="essf-btn essf-btn-secondary"><?php esc_html_e( 'Apply', 'essfinance' ); ?></button>
						</div>
						<div class="essf-toolbar-right">
							<div class="essf-filters">
								<?php $this->render_filter_select(
									'essf_status',
									$status_filter,
									[
										''        => __( 'All statuses', 'essfinance' ),
										'paid'    => __( 'Paid', 'essfinance' ),
										'pending' => __( 'Pending', 'essfinance' ),
										'overdue' => __( 'Overdue', 'essfinance' ),
									]
								); ?>
								<?php $this->render_filter_select(
									'essf_type',
									$type_filter,
									[
										''        => __( 'All types', 'essfinance' ),
										'income'  => __( 'Income', 'essfinance' ),
										'expense' => __( 'Expense', 'essfinance' ),
									]
								); ?>
								<?php
								$cat_options = [ '' => __( 'All categories', 'essfinance' ) ];
								foreach ( ESSF_Category::get_ordered_terms() as $term ) {
									$cat_options[ $term->slug ] = $term->name;
								}
								$this->render_filter_select( 'essf_cat', $cat_filter, $cat_options );
								?>
								<?php if ( ! empty( $months ) ) : ?>
									<?php $this->render_filter_select(
										'essf_m',
										$month_filter,
										[ '' => __( 'All months', 'essfinance' ) ] + $months
									); ?>
								<?php endif; ?>
							</div>
							<?php // translators: %d is the total number of entries. ?>
							<span class="essf-items-count"><?php echo esc_html( sprintf( _n( '%d entry', '%d entries', $total_count, 'essfinance' ), $total_count ) ); ?></span>
						</div>
					</div>
					<table class="essf-table" id="essf-table">
						<thead>
							<tr>
								<th class="essf-cb"><input type="checkbox" id="essf-check-all"></th>
								<th><?php esc_html_e( 'Description', 'essfinance' ); ?></th>
								<th data-col="type"><?php esc_html_e( 'Type', 'essfinance' ); ?></th>
								<th data-col="category"><?php esc_html_e( 'Category', 'essfinance' ); ?></th>
								<th data-col="amount"><?php esc_html_e( 'Amount', 'essfinance' ); ?></th>
								<th data-col="date"><?php esc_html_e( 'Date', 'essfinance' ); ?></th>
								<th data-col="status"><?php esc_html_e( 'Status', 'essfinance' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $entries as $entry ) : ?>
								<?php $this->render_entry_row( $entry, $show_icons, $show_pos_prefix, $show_neg_prefix ); ?>
							<?php endforeach; ?>
						</tbody>
					</table>
					<?php if ( ! $needs_php_filter ) : ?>
						<?php $this->render_pagination( $query->max_num_pages, $paged ); ?>
					<?php endif; ?>
				</form>
				<script>
				(function(){
					var COLS = ['date','type','category','status','amount'];
					var DEFAULT_HIDDEN = ['type'];
					var KEY = 'essf_hidden_cols';
					function getHidden(){try{return JSON.parse(localStorage.getItem(KEY))||DEFAULT_HIDDEN;}catch(e){return DEFAULT_HIDDEN;}}
					function setHidden(a){localStorage.setItem(KEY,JSON.stringify(a));}
					function applyHidden(t,h){COLS.forEach(function(c){t.classList.toggle('essf-hide-col-'+c,h.indexOf(c)!==-1);});}
					document.addEventListener('DOMContentLoaded',function(){
						var table=document.getElementById('essf-table');
						if(!table)return;
						var hidden=getHidden();
						applyHidden(table,hidden);
						var toggle=document.getElementById('essf-col-toggle');
						var menu=document.getElementById('essf-col-menu');
						if(toggle){
							toggle.addEventListener('click',function(e){e.stopPropagation();toggle.classList.toggle('open');});
							document.addEventListener('click',function(){toggle.classList.remove('open');});
							menu.querySelectorAll('input[type=checkbox]').forEach(function(cb){
								cb.checked=hidden.indexOf(cb.value)===-1;
								cb.addEventListener('change',function(){
									var h=getHidden();
									if(cb.checked){h=h.filter(function(c){return c!==cb.value;});}
									else if(h.indexOf(cb.value)===-1){h.push(cb.value);}
									setHidden(h);applyHidden(table,h);
								});
							});
						}
						var checkAll=document.getElementById('essf-check-all');
						if(checkAll){
							checkAll.addEventListener('change',function(){
								table.querySelectorAll('input[name="entries[]"]').forEach(function(cb){cb.checked=checkAll.checked;});
							});
						}
					});
				})();
				</script>
			<?php endif; ?>
		</div>
		<?php
	}

	private function render_filter_select( string $param, string $current, array $options ): void {
		echo '<select onchange="location.href=this.value">';
		foreach ( $options as $key => $label ) {
			$url = $key ? add_query_arg( $param, $key ) : remove_query_arg( $param );
			printf(
				'<option value="%s"%s>%s</option>',
				esc_url( $url ),
				selected( $current, $key, false ),
				esc_html( $label )
			);
		}
		echo '</select>';
	}

	private function render_entry_row( WP_Post $entry, bool $show_icons = false, bool $show_pos_prefix = true, bool $show_neg_prefix = false ): void {
		$amount    = (float) $entry->post_content;
		$is_income = $amount >= 0;
		$status    = $entry->post_status;

		$due_str        = substr( $entry->post_date_gmt, 0, 10 );
		$is_overdue     = 'pending' === $status && '0000-00-00' !== $due_str && $due_str < gmdate( 'Y-m-d' );
		$display_status = $is_overdue ? 'overdue' : $status;

		$pay_date = $entry->post_modified_gmt;
		$date     = ( $pay_date && '0000-00-00 00:00:00' !== $pay_date )
			? mysql2date( get_option( 'date_format' ), $pay_date )
			: mysql2date( get_option( 'date_format' ), $entry->post_date );

		$edit_url        = add_query_arg(
			[
				'essf_view' => 'edit',
				'entry'     => $entry->ID,
			],
			self::get_cashflow_url()
		);
		$paid_date_url   = add_query_arg(
			[
				'essf_view'  => 'edit',
				'entry'      => $entry->ID,
				'essf_focus' => 'pay_date',
			],
			self::get_cashflow_url()
		);
		$toggle_type_url = wp_nonce_url(
			add_query_arg(
				[
					'essf_action' => 'toggle_type',
					'entry'       => $entry->ID,
				],
				self::get_cashflow_url()
			),
			'essf_toggle_type_' . $entry->ID
		);
		$delete_url      = wp_nonce_url(
			add_query_arg(
				[
					'essf_action' => 'delete_entry',
					'entry'       => $entry->ID,
				],
				self::get_cashflow_url()
			),
			'essf_delete_' . $entry->ID
		);
		$paid_url        = wp_nonce_url(
			add_query_arg(
				[
					'essf_action' => 'paid_today',
					'entry'       => $entry->ID,
				],
				self::get_cashflow_url()
			),
			'essf_paid_today_' . $entry->ID
		);

		$icon_html     = $show_icons ? ESSF_Settings::status_icon( $display_status ) : '';
		$sign          = ( $is_income && $show_pos_prefix ) ? '+' : ( ! $is_income && $show_neg_prefix ? '−' : '' );
		$category_name = wp_get_post_terms( $entry->ID, ESSF_Category::TAXONOMY, [ 'fields' => 'names' ] )[0] ?? __( 'Uncategorized', 'essfinance' );
		?>
		<tr class="essf-row essf-status-<?php echo esc_attr( $display_status ); ?> essf-type-<?php echo $is_income ? 'income' : 'expense'; ?>">
			<td class="essf-cb"><input type="checkbox" name="entries[]" value="<?php echo esc_attr( (string) $entry->ID ); ?>"></td>
			<td>
				<?php echo esc_html( $entry->post_title ); ?>
				<div class="essf-row-actions">
					<a href="<?php echo esc_url( $edit_url ); ?>"><?php esc_html_e( 'Edit', 'essfinance' ); ?></a>
					<span class="essf-sep">|</span><a href="<?php echo esc_url( $paid_url ); ?>"><?php esc_html_e( 'Paid today', 'essfinance' ); ?></a>
					<span class="essf-sep">|</span><a href="<?php echo esc_url( $paid_date_url ); ?>"><?php esc_html_e( 'Paid date', 'essfinance' ); ?></a>
					<span class="essf-sep">|</span><a href="<?php echo esc_url( $toggle_type_url ); ?>"><?php echo $is_income ? esc_html__( 'Make expense', 'essfinance' ) : esc_html__( 'Make income', 'essfinance' ); ?></a>
					<span class="essf-sep">|</span><a href="<?php echo esc_url( $delete_url ); ?>" class="essf-delete" onclick="return confirm('<?php esc_attr_e( 'Delete this entry?', 'essfinance' ); ?>')"><?php esc_html_e( 'Delete', 'essfinance' ); ?></a>
				</div>
			</td>
			<td data-col="type"><?php echo $is_income ? esc_html__( 'Income', 'essfinance' ) : esc_html__( 'Expense', 'essfinance' ); ?></td>
			<td data-col="category"><?php echo esc_html( $category_name ); ?></td>
			<td data-col="amount" class="essf-amount"><?php echo esc_html( $sign . ESSF_Settings::format_amount( $amount ) ); ?></td>
			<td data-col="date"><?php echo esc_html( $date ); ?></td>
			<td data-col="status"><span class="essf-status">
			<?php echo $icon_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped values in ESSF_Settings::status_icon()
			echo esc_html( ESSF_CPT::status_label( $display_status ) ); ?></span></td>
		</tr>
		<?php
	}

	private function render_entry_form( ?WP_Post $entry ): void {
		$is_edit    = null !== $entry;
		$focus      = sanitize_key( wp_unslash( $_GET['essf_focus'] ?? '' ) );
		$amount_val = $entry ? abs( (float) $entry->post_content ) : '';
		$type_val   = $entry ? ( (float) $entry->post_content >= 0 ? 'income' : 'expense' ) : 'expense';
		$status_val = $entry ? $entry->post_status : 'pending';
		$due_val    = ( $entry && '0000-00-00' !== substr( $entry->post_date_gmt, 0, 10 ) ) ? substr( $entry->post_date_gmt, 0, 10 ) : '';
		$pay_val    = ( $entry && '0000-00-00' !== substr( $entry->post_modified_gmt, 0, 10 ) ) ? substr( $entry->post_modified_gmt, 0, 10 ) : '';
		// A new entry's default is a canonical key, not necessarily a live
		// slug — normalize it so it matches a $term->slug below.
		$cat_val = ESSF_Category::normalize_slug( 'uncategorized' );
		if ( $entry ) {
			$entry_terms = wp_get_post_terms( $entry->ID, ESSF_Category::TAXONOMY, [ 'fields' => 'slugs' ] );
			$cat_val     = $entry_terms[0] ?? $cat_val;
		}

		if ( 'pay_date' === $focus ) {
			$pay_val    = gmdate( 'Y-m-d' );
			$status_val = 'paid';
		}
		?>
		<div class="essf-entry-form">
			<h3><?php echo $is_edit ? esc_html__( 'Edit Entry', 'essfinance' ) : esc_html__( 'Add Entry', 'essfinance' ); ?></h3>
			<form method="post" class="essf-form">
				<?php wp_nonce_field( 'essf_save_entry' ); ?>
				<input type="hidden" name="essf_action" value="<?php echo $is_edit ? 'edit_entry' : 'add_entry'; ?>">
				<?php if ( $is_edit ) : ?>
					<input type="hidden" name="essf_entry_id" value="<?php echo esc_attr( (string) $entry->ID ); ?>">
				<?php endif; ?>
				<p><label><?php esc_html_e( 'Description', 'essfinance' ); ?><br>
				<input type="text" name="essf_description" value="<?php echo esc_attr( $entry ? $entry->post_title : '' ); ?>" required></label></p>
				<div class="essf-dates-row">
					<p><label><?php esc_html_e( 'Due Date', 'essfinance' ); ?><br>
					<input type="date" name="essf_due_date" value="<?php echo esc_attr( $due_val ); ?>"></label></p>
					<p><label><?php esc_html_e( 'Pay Date', 'essfinance' ); ?><br>
					<input type="date" name="essf_pay_date" value="<?php echo esc_attr( $pay_val ); ?>"<?php echo 'pay_date' === $focus ? ' autofocus' : ''; ?>></label></p>
				</div>
				<div class="essf-field-row-amount">
					<p><label><?php esc_html_e( 'Amount', 'essfinance' ); ?><br>
					<input type="number" name="essf_amount" value="<?php echo esc_attr( (string) $amount_val ); ?>" step="0.01" min="0" placeholder="0.00" required></label></p>
					<label class="essf-income-label">
						<input type="checkbox" name="essf_is_income" value="1"<?php checked( 'income', $type_val ); ?>>
						<?php esc_html_e( 'Income', 'essfinance' ); ?>
					</label>
				</div>
				<p><label><?php esc_html_e( 'Status', 'essfinance' ); ?><br>
				<select name="essf_status">
					<?php foreach ( ESSF_CPT::labels() as $val => $label ) : ?>
						<option value="<?php echo esc_attr( $val ); ?>"<?php selected( $status_val, $val ); ?>><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select></label></p>
				<p><label><?php esc_html_e( 'Category', 'essfinance' ); ?><br>
				<select name="essf_category">
					<?php foreach ( ESSF_Category::get_ordered_terms() as $term ) : ?>
						<option value="<?php echo esc_attr( $term->slug ); ?>"<?php selected( $cat_val, $term->slug ); ?>><?php echo esc_html( $term->name ); ?></option>
					<?php endforeach; ?>
				</select></label></p>
				<p class="essf-form-actions">
					<button type="submit" class="essf-btn"><?php echo $is_edit ? esc_html__( 'Update', 'essfinance' ) : esc_html__( 'Add Entry', 'essfinance' ); ?></button>
					<a href="<?php echo esc_url( self::get_cashflow_url() ); ?>" class="essf-btn essf-btn-secondary"><?php esc_html_e( 'Cancel', 'essfinance' ); ?></a>
				</p>
			</form>
		</div>
		<?php
	}

	/* ── Export / Import ────────────────────────────── */

	private function handle_export(): void {
		if ( ! is_user_logged_in() ) {
			wp_safe_redirect( self::get_myaccount_url() );
			exit;
		}
		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ?? '' ) ), 'essf_export' ) ) {
			wp_safe_redirect( self::get_cashflow_url() );
			exit;
		}

		$query = new WP_Query(
			[
				'post_type'      => 'essf_cashflow',
				'post_status'    => array_keys( ESSF_CPT::labels() ),
				'author'         => get_current_user_id(),
				'posts_per_page' => -1,
				'orderby'        => 'date',
				'order'          => 'DESC',
			]
		);

		$filename = 'cashflow-' . gmdate( 'Y-m-d' ) . '.csv';
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		header( 'Pragma: no-cache' );
		header( 'Expires: 0' );

		$out = fopen( 'php://output', 'w' );
		fputcsv( $out, [ 'due_date', 'pay_date', 'description', 'type', 'amount', 'status', 'category' ] );

		foreach ( $query->posts as $entry ) {
			$amount    = (float) $entry->post_content;
			$is_income = $amount >= 0;
			$due_date  = '0000-00-00' !== substr( $entry->post_date_gmt, 0, 10 ) ? substr( $entry->post_date_gmt, 0, 10 ) : '';
			$pay_date  = '0000-00-00' !== substr( $entry->post_modified_gmt, 0, 10 ) ? substr( $entry->post_modified_gmt, 0, 10 ) : '';
			$cat_slugs = wp_get_post_terms( $entry->ID, ESSF_Category::TAXONOMY, [ 'fields' => 'slugs' ] );
			fputcsv(
				$out,
				[
					$due_date,
					$pay_date,
					$entry->post_title,
					$is_income ? 'income' : 'expense',
					abs( $amount ),
					$entry->post_status,
					$cat_slugs[0] ?? 'uncategorized',
				]
			);
		}

		fclose( $out );
		exit;
	}

	private function handle_import(): void {
		if ( ! is_user_logged_in() ) {
			wp_safe_redirect( self::get_myaccount_url() );
			exit;
		}
		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ?? '' ) ), 'essf_import' ) ) {
			wp_safe_redirect( self::get_cashflow_url() );
			exit;
		}

		$tmp = $_FILES['essf_csv']['tmp_name'] ?? ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- validated via is_uploaded_file(); value is read as a path, not output
		if ( ! $tmp || ! is_uploaded_file( $tmp ) ) {
			wp_safe_redirect(
				add_query_arg(
					[
						'essf_view'  => 'import',
						'essf_error' => 'no_file',
					],
					self::get_cashflow_url()
				)
			);
			exit;
		}

		$handle = fopen( $tmp, 'r' );
		if ( ! $handle ) {
			wp_safe_redirect(
				add_query_arg(
					[
						'essf_view'  => 'import',
						'essf_error' => 'read_error',
					],
					self::get_cashflow_url()
				)
			);
			exit;
		}

		$header = fgetcsv( $handle );
		if ( ! $header ) {
			fclose( $handle );
			wp_safe_redirect(
				add_query_arg(
					[
						'essf_view'  => 'import',
						'essf_error' => 'invalid_file',
					],
					self::get_cashflow_url()
				)
			);
			exit;
		}

		$col = array_flip( array_map( 'strtolower', array_map( 'trim', $header ) ) );
		foreach ( [ 'description', 'type', 'amount', 'status' ] as $req ) {
			if ( ! isset( $col[ $req ] ) ) {
				fclose( $handle );
				wp_safe_redirect(
					add_query_arg(
						[
							'essf_view'  => 'import',
							'essf_error' => 'missing_columns',
						],
						self::get_cashflow_url()
					)
				);
				exit;
			}
		}

		global $wpdb;
		$imported = 0;

		while ( ( $row = fgetcsv( $handle ) ) !== false ) {
			$description = sanitize_text_field( $row[ $col['description'] ] ?? '' );
			if ( '' === $description ) {
				continue;
			}

			$type      = strtolower( trim( $row[ $col['type'] ] ?? 'expense' ) );
			$is_income = 'income' === $type;
			$amount    = $is_income ? abs( (float) ( $row[ $col['amount'] ] ?? 0 ) ) : -abs( (float) ( $row[ $col['amount'] ] ?? 0 ) );
			$status    = sanitize_key( $row[ $col['status'] ] ?? 'pending' );
			if ( ! array_key_exists( $status, ESSF_CPT::labels() ) ) {
				$status = 'pending';
			}

			$due_raw   = isset( $col['due_date'] ) ? trim( $row[ $col['due_date'] ] ?? '' ) : '';
			$pay_raw   = isset( $col['pay_date'] ) ? trim( $row[ $col['pay_date'] ] ?? '' ) : '';
			$cat_raw   = isset( $col['category'] ) ? sanitize_key( trim( $row[ $col['category'] ] ?? '' ) ) : '';
			$category  = ( $cat_raw && get_term_by( 'slug', $cat_raw, ESSF_Category::TAXONOMY ) ) ? $cat_raw : 'uncategorized';
			$due_dt    = $due_raw ? DateTime::createFromFormat( 'Y-m-d', $due_raw ) : false;
			$pay_dt    = $pay_raw ? DateTime::createFromFormat( 'Y-m-d', $pay_raw ) : false;
			$due_gmt   = $due_dt ? $due_dt->format( 'Y-m-d' ) . ' 00:00:00' : '0000-00-00 00:00:00';
			$pay_gmt   = $pay_dt ? $pay_dt->format( 'Y-m-d' ) . ' 00:00:00' : '0000-00-00 00:00:00';
			$post_date = $due_dt ? $due_dt->format( 'Y-m-d' ) . ' 00:00:00' : current_time( 'mysql' );

			$post_id = wp_insert_post(
				[
					'post_type'     => 'essf_cashflow',
					'post_title'    => $description,
					'post_content'  => (string) $amount,
					'post_status'   => $status,
					'post_author'   => get_current_user_id(),
					'post_date'     => $post_date,
					'post_date_gmt' => $due_gmt,
				]
			);

			if ( $post_id && ! is_wp_error( $post_id ) ) {
				$wpdb->update( $wpdb->posts, [ 'post_modified_gmt' => $pay_gmt ], [ 'ID' => $post_id ], [ '%s' ], [ '%d' ] );
				$order_date = '0000-00-00 00:00:00' !== $pay_gmt ? $pay_gmt : $due_gmt;
				update_post_meta( $post_id, '_order_date', substr( $order_date, 0, 10 ) );
				wp_set_post_terms( $post_id, [ ESSF_Category::term_id_for_slug( $category ) ], ESSF_Category::TAXONOMY );
				++$imported;
			}
		}

		fclose( $handle );
		wp_safe_redirect( add_query_arg( 'essf_imported', $imported, self::get_cashflow_url() ) );
		exit;
	}

	private function render_import_form(): void {
		$error          = sanitize_key( wp_unslash( $_GET['essf_error'] ?? '' ) );
		$error_messages = [
			'no_file'         => __( 'Please select a CSV file to import.', 'essfinance' ),
			'read_error'      => __( 'Could not read the uploaded file.', 'essfinance' ),
			'invalid_file'    => __( 'The file appears to be empty or invalid.', 'essfinance' ),
			'missing_columns' => __( 'CSV must contain columns: description, type, amount, status.', 'essfinance' ),
		];
		?>
		<div class="essf-entry-form">
			<h3><?php esc_html_e( 'Import Entries', 'essfinance' ); ?></h3>
			<?php if ( $error && isset( $error_messages[ $error ] ) ) : ?>
				<div class="essf-notice essf-error"><?php echo esc_html( $error_messages[ $error ] ); ?></div>
			<?php endif; ?>
			<p><?php esc_html_e( 'Upload a CSV with columns: due_date, pay_date, description, type (income/expense), amount, status, category (optional).', 'essfinance' ); ?></p>
			<form method="post" enctype="multipart/form-data" class="essf-form">
				<?php wp_nonce_field( 'essf_import' ); ?>
				<input type="hidden" name="essf_action" value="import">
				<p><label><?php esc_html_e( 'CSV File', 'essfinance' ); ?><br>
				<input type="file" name="essf_csv" accept=".csv,text/csv" required></label></p>
				<p class="essf-form-actions">
					<button type="submit" class="essf-btn"><?php esc_html_e( 'Import', 'essfinance' ); ?></button>
					<a href="<?php echo esc_url( self::get_cashflow_url() ); ?>" class="essf-btn essf-btn-secondary"><?php esc_html_e( 'Cancel', 'essfinance' ); ?></a>
				</p>
			</form>
		</div>
		<?php
	}

	private function cashflow_url_with_filters( array $extra = [] ): string {
		$carry = [];
		foreach ( [ 'essf_status', 'essf_type', 'essf_m', 'essf_cat', 'paged', 'essf_search' ] as $key ) {
			$val = sanitize_key( wp_unslash( $_GET[ $key ] ?? '' ) );
			if ( '' !== $val ) {
				$carry[ $key ] = $val;
			}
		}
		return add_query_arg( array_merge( $carry, $extra ), self::get_cashflow_url() );
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
				'author'         => get_current_user_id(),
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

	private function render_pagination( int $total_pages, int $current_page ): void {
		if ( $total_pages <= 1 ) {
			return;
		}
		echo '<div class="essf-pagination">';
		for ( $i = 1; $i <= $total_pages; $i++ ) {
			if ( $i === $current_page ) {
				printf( '<span class="essf-page current">%d</span>', (int) $i ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- %d format ensures integer output
			} else {
				// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- %d format ensures integer output; URL escaped via esc_url()
				printf( '<a href="%s" class="essf-page">%d</a>', esc_url( add_query_arg( 'paged', $i ) ), (int) $i );
			}
		}
		echo '</div>';
	}

	private function action_message( string $msg ): string {
		$map = [
			'added'     => __( 'Entry added.', 'essfinance' ),
			'updated'   => __( 'Entry updated.', 'essfinance' ),
			'deleted'   => __( 'Entry deleted.', 'essfinance' ),
			'paid'      => __( 'Entry marked as paid.', 'essfinance' ),
			'bulk_done' => __( 'Bulk action applied.', 'essfinance' ),
		];
		return $map[ $msg ] ?? '';
	}

	/* ── Page creation ───────────────────────────────── */

	public static function create_pages(): void {
		$pages = [
			self::OPTION_MYACCOUNT_PAGE => [
				'title'   => __( 'My Account', 'essfinance' ),
				'content' => '[essfinance_myaccount]',
			],
			self::OPTION_CASHFLOW_PAGE  => [
				'title'   => __( 'Cash Flow', 'essfinance' ),
				'content' => '[essfinance_cashflow]',
			],
		];

		foreach ( $pages as $option => $data ) {
			$existing = (int) get_option( $option );
			if ( $existing && get_post( $existing ) ) {
				continue;
			}
			$id = wp_insert_post(
				[
					'post_title'   => $data['title'],
					'post_content' => $data['content'],
					'post_status'  => 'publish',
					'post_type'    => 'page',
				]
			);
			if ( $id && ! is_wp_error( $id ) ) {
				update_option( $option, $id );
			}
		}
	}
}
