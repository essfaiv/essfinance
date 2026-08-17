<?php
/**
 * EssFinance — Admin Page
 *
 * @package EssFinance
 * @license GPL-2.0-or-later
 */

defined( 'ABSPATH' ) || exit;

class ESSF_Admin_Page {

	const ADD_NONCE                      = 'essf_add';
	const UPDATE_NONCE                   = 'essf_update';
	const DELETE_NONCE                   = 'essf_delete';
	const PAID_TODAY_NONCE               = 'essf_paid_today';
	const TOGGLE_TYPE_NONCE              = 'essf_toggle_type';
	const IMPORT_OFX_CONFIRM_NONCE       = 'essf_import_ofx_confirm';
	const FORGET_MEMO_NONCE              = 'essf_forget_memo';
	const FORGET_EXCLUDED_NONCE          = 'essf_forget_excluded';
	const FORGET_CATEGORY_GLOSSARY_NONCE = 'essf_forget_category_glossary';
	const GROUP_CONFIRM_NONCE            = 'essf_group_confirm';
	const SPLIT_CONFIRM_NONCE            = 'essf_split_confirm';
	const DETECT_PLANS_NONCE             = 'essf_detect_plans';

	/** Option storing normalized memos of rows the operator has excluded before (FIFO-capped). */
	const EXCLUDED_MEMOS_OPTION = 'essf_ofx_excluded_memos';
	const EXCLUDED_MEMOS_LIMIT  = 300;

	public function __construct() {
		add_action( 'admin_menu', [ $this, 'register_menus' ], 5 );
		add_action( 'current_screen', [ $this, 'register_screen_options' ] );
		add_filter(
			'set_screen_option_essf_entries_per_page',
			function ( $s, $o, $v ) {
				return absint( $v );
			},
			10,
			3
		);
		add_filter(
			'set_screen_option_essf_categories_per_page',
			function ( $s, $o, $v ) {
				return absint( $v );
			},
			10,
			3
		);
		// WP < 5.4.2 fallback (set-screen-option with hyphen).
		add_filter(
			'set-screen-option',
			function ( $s, $o, $v ) {
				return in_array( $o, [ 'essf_entries_per_page', 'essf_categories_per_page' ], true ) ? absint( $v ) : $s;
			},
			10,
			3
		);
		add_action( 'admin_init', [ $this, 'process_bulk_actions' ] );
		add_action( 'admin_init', [ $this, 'maybe_redirect_to_current_month' ] );
		add_action( 'admin_post_essf_add', [ $this, 'handle_add' ] );
		add_action( 'admin_post_essf_update', [ $this, 'handle_update' ] );
		add_action( 'admin_post_essf_delete', [ $this, 'handle_delete' ] );
		add_action( 'admin_post_essf_paid_today', [ $this, 'handle_paid_today' ] );
		add_action( 'admin_post_essf_toggle_type', [ $this, 'handle_toggle_type' ] );
		add_action( 'admin_post_essf_export', [ $this, 'handle_export' ] );
		add_action( 'admin_post_essf_import', [ $this, 'handle_import' ] );
		add_action( 'admin_post_essf_import_ofx_confirm', [ $this, 'handle_import_ofx_confirm' ] );
		add_action( 'admin_post_essf_forget_memo', [ $this, 'handle_forget_memo' ] );
		add_action( 'admin_post_essf_forget_excluded', [ $this, 'handle_forget_excluded' ] );
		add_action( 'admin_post_essf_forget_category_glossary', [ $this, 'handle_forget_category_glossary' ] );
		add_action( 'admin_post_essf_group_confirm', [ $this, 'handle_group_confirm' ] );
		add_action( 'admin_post_essf_split_confirm', [ $this, 'handle_split_confirm' ] );
		add_action( 'admin_post_essf_detect_plans', [ $this, 'handle_detect_plans' ] );
		add_filter( 'manage_essf_cashflow_posts_columns', [ $this, 'columns' ] );
		add_action( 'manage_essf_cashflow_posts_custom_column', [ $this, 'column_content' ], 10, 2 );
		add_filter( 'manage_edit-essf_cashflow_sortable_columns', [ $this, 'sortable_columns' ] );
	}

	public function register_menus(): void {
		add_menu_page(
			__( 'EssFinance', 'essfinance' ),
			__( 'EssFinance', 'essfinance' ),
			'manage_options',
			'essfinance',
			[ $this, 'render_dashboard' ],
			'dashicons-chart-bar',
			30
		);

		add_submenu_page(
			'essfinance',
			__( 'Cash Flow', 'essfinance' ),
			__( 'Cash Flow', 'essfinance' ),
			'manage_options',
			'essfinance',
			[ $this, 'render_dashboard' ]
		);

		// Custom read-only listing instead of linking straight to the native
		// edit-tags.php taxonomy screen — category names/slugs are managed
		// by ESSF_Category's seed/relabel logic (see relabel_terms()), so
		// letting admins freely rename/delete/bulk-edit them here would
		// fight that automation. The native screen is still reachable by a
		// direct URL for anyone with the taxonomy's manage_categories
		// capability; this page just isn't the front door to it anymore.
		add_submenu_page(
			'essfinance',
			__( 'Categories', 'essfinance' ),
			__( 'Categories', 'essfinance' ),
			'manage_options',
			'essfinance-categories',
			[ $this, 'render_categories_page' ]
		);

		// Both glossaries are reachable only from the Settings screen (see
		// ESSF_Settings::render()), not as their own EssFinance submenu — an
		// empty parent registers the page (URL, nonce actions, capability
		// check) without adding a visible nav item, WordPress's standard
		// technique for a "hidden" admin page (add_submenu_page() treats ''
		// the same as null; '' keeps the param typed as string for static
		// analysis). Registering under the real 'essfinance' parent and
		// calling remove_submenu_page() looks equivalent but isn't: this
		// plugin's own top-level menu registers itself into
		// $admin_page_hooks, so removing the submenu entry changes what
		// get_admin_page_parent() resolves at request time and breaks
		// user_can_access_admin_page()'s hookname lookup — the page 404s
		// with "Sorry, you are not allowed to access this page."
		$hidden_pages = [
			'essfinance-ofx-glossary'      => __( 'OFX Glossary', 'essfinance' ),
			'essfinance-category-glossary' => __( 'Category Glossary', 'essfinance' ),
		];

		// A '' parent also means get_admin_page_title() can't find this
		// page's title (it only walks top-level $menu entries when the
		// parent is empty) and leaves $title unset, which trips a
		// strip_tags( null ) deprecation notice in wp-admin/admin-header.php.
		// Pre-set it ourselves — get_admin_page_title() returns early once
		// $title is non-empty.
		$current_page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( isset( $hidden_pages[ $current_page ] ) ) {
			$GLOBALS['title'] = $hidden_pages[ $current_page ]; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		}

		add_submenu_page(
			'',
			__( 'OFX Glossary', 'essfinance' ),
			__( 'OFX Glossary', 'essfinance' ),
			'manage_options',
			'essfinance-ofx-glossary',
			[ $this, 'render_glossary_page' ]
		);

		add_submenu_page(
			'',
			__( 'Category Glossary', 'essfinance' ),
			__( 'Category Glossary', 'essfinance' ),
			'manage_options',
			'essfinance-category-glossary',
			[ $this, 'render_category_glossary_page' ]
		);
	}

	public function register_screen_options( WP_Screen $screen ): void {
		if ( 'essfinance_page_essfinance-categories' === $screen->id ) {
			add_screen_option(
				'per_page',
				[
					'label'   => __( 'Categories per page', 'essfinance' ),
					'default' => 20,
					'option'  => 'essf_categories_per_page',
				]
			);
			register_column_headers( $screen, ( new ESSF_Category_List_Table() )->get_columns() );
			$screen->add_help_tab(
				[
					'id'      => 'essf-categories-overview',
					'title'   => __( 'Overview', 'essfinance' ),
					'content' => '<p>' . esc_html__( 'This is a read-only view of the categories entries can be filed under. Names and slugs are managed automatically and change with the site language.', 'essfinance' ) . '</p>',
				]
			);
			return;
		}

		if ( 'toplevel_page_essfinance' !== $screen->id ) {
			return;
		}
		add_screen_option(
			'per_page',
			[
				'label'   => __( 'Entries per page', 'essfinance' ),
				'default' => 20,
				'option'  => 'essf_entries_per_page',
			]
		);
		register_column_headers( $screen, ( new ESSF_List_Table() )->get_columns() );
		add_filter(
			'default_hidden_columns',
			function ( array $hidden, WP_Screen $s ): array {
				if ( 'toplevel_page_essfinance' === $s->id ) {
					$hidden[] = 'essf_type';
				}
				return $hidden;
			},
			10,
			2
		);
	}

	/**
	 * No explicit month filter (not even the "-1" = All months sentinel —
	 * see ESSF_List_Table::render_months_dropdown()) means a bare/direct
	 * visit (menu click, bookmark, typed URL) rather than a real filter
	 * submission, so default it to the current month. Hooked on
	 * `admin_init` — like process_bulk_actions() below — because by the
	 * time render_dashboard() runs, wp-admin has already sent headers/HTML
	 * and wp_safe_redirect() would silently fail. Skipped for sub-views of
	 * this same page (edit entry, OFX review, import) that don't use the
	 * month filter at all. The redirect target always carries `m`, so this
	 * can't loop.
	 */
	public function maybe_redirect_to_current_month(): void {
		if ( ! isset( $_REQUEST['page'] ) || 'essfinance' !== $_REQUEST['page'] ) {
			return;
		}
		if ( isset( $_GET['m'] ) || isset( $_GET['entry'] ) || isset( $_GET['essf_review'] ) || isset( $_GET['essf_action'] ) || isset( $_GET['essf_group'] ) || isset( $_GET['essf_split'] ) ) {
			return;
		}
		wp_safe_redirect( esc_url_raw( add_query_arg( 'm', current_time( 'Ym' ) ) ) );
		exit;
	}

	/* ── Bulk actions ───────────────────────────────────── */

	public function process_bulk_actions(): void {
		if ( ! isset( $_REQUEST['page'] ) || 'essfinance' !== $_REQUEST['page'] ) {
			return;
		}
		$action = isset( $_REQUEST['action'] ) && '-1' !== $_REQUEST['action']
			? sanitize_key( $_REQUEST['action'] )
			: ( isset( $_REQUEST['action2'] ) && '-1' !== $_REQUEST['action2'] ? sanitize_key( $_REQUEST['action2'] ) : '' );

		$valid = [ 'delete', 'mark_paid', 'mark_pending', 'make_income', 'make_expense', 'set_category', 'auto_set_category', 'group_as_loan', 'split_installment' ];
		if ( ! in_array( $action, $valid, true ) ) {
			return;
		}

		check_admin_referer( 'bulk-entries' );
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$ids = isset( $_REQUEST['entries'] ) ? array_map( 'absint', (array) $_REQUEST['entries'] ) : [];

		// These two stage-and-redirect to a confirmation screen instead of
		// mutating anything here, same as OFX import review.
		if ( 'group_as_loan' === $action ) {
			$this->stage_group_as_loan( $ids );
			return;
		}
		if ( 'split_installment' === $action ) {
			$this->stage_split_installment( $ids );
			return;
		}

		$today            = current_time( 'mysql', true );
		$glossary_history = null;
		global $wpdb;

		foreach ( $ids as $id ) {
			$post = get_post( $id );
			if ( ! $post || 'essf_cashflow' !== $post->post_type ) {
				continue;
			}
			switch ( $action ) {
				case 'delete':
					wp_delete_post( $id, true );
					break;
				case 'mark_paid':
					$wpdb->update(
						$wpdb->posts,
						[
							'post_status'       => 'paid',
							'post_modified_gmt' => $today,
						],
						[ 'ID' => $id ],
						[ '%s', '%s' ],
						[ '%d' ]
					);
					update_post_meta( $id, '_order_date', substr( $today, 0, 10 ) );
					break;
				case 'mark_pending':
					$wpdb->update( $wpdb->posts, [ 'post_status' => 'pending' ], [ 'ID' => $id ], [ '%s' ], [ '%d' ] );
					break;
				case 'make_income':
					$wpdb->update( $wpdb->posts, [ 'post_content' => (string) abs( (float) $post->post_content ) ], [ 'ID' => $id ], [ '%s' ], [ '%d' ] );
					break;
				case 'make_expense':
					$wpdb->update( $wpdb->posts, [ 'post_content' => (string) ( -abs( (float) $post->post_content ) ) ], [ 'ID' => $id ], [ '%s' ], [ '%d' ] );
					break;
				case 'set_category':
					// Reuses the category *filter* dropdown (category_name) as
					// the bulk-apply target too — no separate dropdown.
					$bulk_category = sanitize_key( wp_unslash( $_REQUEST['category_name'] ?? '' ) );
					if ( $bulk_category ) {
						wp_set_post_terms( $id, [ ESSF_Category::term_id_for_slug( $bulk_category ) ], ESSF_Category::TAXONOMY );
					}
					break;
				case 'auto_set_category':
					$glossary_history ??= ESSF_Category::category_glossary_history();
					$guessed_slug       = ESSF_Category::guess_slug( $post->post_title, $glossary_history );
					wp_set_post_terms( $id, [ ESSF_Category::term_id_for_slug( $guessed_slug ) ], ESSF_Category::TAXONOMY );
					break;
			}
		}

		$msg_map = [
			'delete'            => 'deleted',
			'mark_paid'         => 'mark_paid',
			'mark_pending'      => 'mark_pending',
			'make_income'       => 'make_income',
			'make_expense'      => 'make_expense',
			'set_category'      => 'set_category',
			'auto_set_category' => 'auto_set_category',
		];

		$carry = [ 'page' => 'essfinance' ];
		foreach ( [ 'essf_status', 'essf_type', 'm', 'category_name', 'paged', 's' ] as $key ) {
			$val = sanitize_key( wp_unslash( $_GET[ $key ] ?? '' ) );
			if ( '' !== $val ) {
				$carry[ $key ] = $val;
			}
		}
		wp_safe_redirect( add_query_arg( array_merge( $carry, [ 'essf_msg' => $msg_map[ $action ] ] ), admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * Stages selected entries for the "Group as Loan" confirmation screen —
	 * same staged-transient-then-redirect pattern as OFX import review, just
	 * without the short-URL-token step (this is a same-session confirmation,
	 * not something that needs a shareable short link).
	 *
	 * @param int[] $ids Selected post IDs.
	 */
	private function stage_group_as_loan( array $ids ): void {
		$ids = array_values(
			array_filter(
				$ids,
				static function ( $id ) {
					$post = get_post( $id );
					return $post && 'essf_cashflow' === $post->post_type;
				}
			)
		);

		if ( count( $ids ) < 2 ) {
			wp_safe_redirect( add_query_arg( 'essf_msg', 'group_error', admin_url( 'admin.php?page=essfinance' ) ) );
			exit;
		}

		usort(
			$ids,
			static function ( $a, $b ) {
				return strcmp( get_post( $a )->post_date_gmt, get_post( $b )->post_date_gmt );
			}
		);

		$token = bin2hex( random_bytes( 20 ) );
		set_transient(
			'essf_group_review_' . $token,
			[
				'user_id' => get_current_user_id(),
				'ids'     => $ids,
			],
			15 * MINUTE_IN_SECONDS
		);

		wp_safe_redirect(
			add_query_arg(
				[
					'page'       => 'essfinance',
					'essf_group' => $token,
				],
				admin_url( 'admin.php' )
			)
		); // phpcs:ignore WordPress.Arrays.MultipleStatementAlignment.DoubleArrowNotAligned
		exit;
	}

	/**
	 * Stages a single selected entry for the "Split into installments"
	 * confirmation screen.
	 *
	 * @param int[] $ids Selected post IDs — exactly one is required.
	 */
	private function stage_split_installment( array $ids ): void {
		$ids = array_values(
			array_filter(
				$ids,
				static function ( $id ) {
					$post = get_post( $id );
					return $post && 'essf_cashflow' === $post->post_type;
				}
			)
		);

		if ( 1 !== count( $ids ) ) {
			wp_safe_redirect( add_query_arg( 'essf_msg', 'split_error', admin_url( 'admin.php?page=essfinance' ) ) );
			exit;
		}

		$token = bin2hex( random_bytes( 20 ) );
		set_transient(
			'essf_split_review_' . $token,
			[
				'user_id' => get_current_user_id(),
				'id'      => $ids[0],
			],
			15 * MINUTE_IN_SECONDS
		);

		wp_safe_redirect(
			add_query_arg(
				[
					'page'       => 'essfinance',
					'essf_split' => $token,
				],
				admin_url( 'admin.php' )
			)
		); // phpcs:ignore WordPress.Arrays.MultipleStatementAlignment.DoubleArrowNotAligned
		exit;
	}

	/* ── Single entry handlers ──────────────────────────── */

	public function handle_add(): void {
		check_admin_referer( self::ADD_NONCE );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized.', 'essfinance' ) );
		}

		if ( empty( $_POST['essf_due_date'] ) ) {
			$_POST['essf_due_date'] = current_time( 'Y-m-d' );
		}
		if ( empty( $_POST['essf_pay_date'] ) && 'paid' === sanitize_key( wp_unslash( $_POST['essf_status'] ?? '' ) ) ) {
			$_POST['essf_pay_date'] = sanitize_text_field( wp_unslash( $_POST['essf_due_date'] ) );
		}
		$data = $this->parse_form_data( $_POST );

		$post_id = ESSF_CPT::insert_entry( $data['title'], (float) $data['amount'], $data['due_gmt'], $data['pay_gmt'], $data['status'], $data['category'], get_current_user_id() );

		if ( ! $post_id ) {
			wp_safe_redirect( add_query_arg( 'essf_msg', 'error', admin_url( 'admin.php?page=essfinance' ) ) );
			exit;
		}

		wp_safe_redirect( add_query_arg( 'essf_msg', 'added', admin_url( 'admin.php?page=essfinance' ) ) );
		exit;
	}

	public function handle_update(): void {
		check_admin_referer( self::UPDATE_NONCE );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized.', 'essfinance' ) );
		}

		$post_id = absint( $_POST['entry_id'] ?? 0 );
		$post    = get_post( $post_id );

		if ( ! $post || 'essf_cashflow' !== $post->post_type ) {
			wp_safe_redirect( add_query_arg( 'essf_msg', 'error', admin_url( 'admin.php?page=essfinance' ) ) );
			exit;
		}

		$data = $this->parse_form_data( $_POST );

		global $wpdb;
		$wpdb->update(
			$wpdb->posts,
			[
				'post_title'        => $data['title'],
				'post_content'      => $data['amount'],
				'post_status'       => $data['status'],
				'post_date_gmt'     => $data['due_gmt'],
				'post_modified_gmt' => $data['pay_gmt'],
			],
			[ 'ID' => $post_id ],
			[ '%s', '%s', '%s', '%s', '%s' ],
			[ '%d' ]
		);

		update_post_meta( $post_id, '_order_date', $data['order_date'] );
		wp_set_post_terms( $post_id, [ ESSF_Category::term_id_for_slug( $data['category'] ) ], ESSF_Category::TAXONOMY );

		wp_safe_redirect( add_query_arg( 'essf_msg', 'updated', admin_url( 'admin.php?page=essfinance' ) ) );
		exit;
	}

	public function handle_export(): void {
		check_admin_referer( 'essf_export' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized.', 'essfinance' ) );
		}

		$posts = get_posts(
			[
				'post_type'      => 'essf_cashflow',
				'post_status'    => [ 'pending', 'paid' ],
				'posts_per_page' => -1,
				'orderby'        => 'meta_value',
				'meta_key'       => '_order_date',
				'order'          => 'ASC',
			]
		);

		$filename = 'essfinance-export-' . gmdate( 'Y-m-d-His' ) . '.csv';

		header( 'Content-Type: text/csv; charset=UTF-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		header( 'Pragma: no-cache' );

		$out = fopen( 'php://output', 'w' );
		fprintf( $out, "\xEF\xBB\xBF" ); // UTF-8 BOM — ensures Excel opens accented chars correctly
		fputcsv( $out, [ 'Description', 'Due Date', 'Pay Date', 'Status', 'Amount' ] );

		foreach ( $posts as $post ) {
			$amount = (float) $post->post_content;
			$due    = substr( $post->post_date_gmt, 0, 10 );
			$pay    = substr( $post->post_modified_gmt, 0, 10 );
			fputcsv(
				$out,
				[
					$post->post_title,
					( $due && '0000-00-00' !== $due ) ? $due : '',
					( $pay && '0000-00-00' !== $pay ) ? $pay : '',
					$post->post_status,
					self::format_amount_csv( $amount ),
				]
			);
		}

		fclose( $out );
		exit;
	}

	public function handle_import(): void {
		check_admin_referer( 'essf_import' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized.', 'essfinance' ) );
		}

		$file = $_FILES['essf_csv_file'] ?? null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- validated via UPLOAD_ERR_OK + is_uploaded_file(); only tmp_name is read
		if ( ! $file || UPLOAD_ERR_OK !== (int) $file['error'] || ! is_uploaded_file( $file['tmp_name'] ) ) {
			wp_safe_redirect( add_query_arg( 'essf_msg', 'import_error', admin_url( 'admin.php?page=essfinance' ) ) );
			exit;
		}

		[ $existing, $fitid_lookup, $amount_index ] = $this->build_dedup_lookups();

		$ext = strtolower( pathinfo( $file['name'], PATHINFO_EXTENSION ) );

		if ( in_array( $ext, [ 'ofx', 'qfx' ], true ) ) {
			$content = file_get_contents( $file['tmp_name'] );
			if ( false === $content ) {
				wp_safe_redirect( add_query_arg( 'essf_msg', 'import_error', admin_url( 'admin.php?page=essfinance' ) ) );
				exit;
			}
			// OFX transactions are staged for review — never inserted directly. Always exits.
			$this->stage_ofx_import( $content, $existing, $fitid_lookup, $amount_index );
			exit;
		}

		$handle = fopen( $file['tmp_name'], 'r' );
		if ( ! $handle ) {
			wp_safe_redirect( add_query_arg( 'essf_msg', 'import_error', admin_url( 'admin.php?page=essfinance' ) ) );
			exit;
		}
		$bom = fread( $handle, 3 );
		if ( "\xEF\xBB\xBF" !== $bom ) {
			rewind( $handle );
		}
		fgetcsv( $handle ); // skip header row
		$result = $this->import_csv( $handle, $existing );
		fclose( $handle );

		wp_safe_redirect(
			add_query_arg(
				[
					'essf_msg'      => 'imported',
					'essf_imported' => $result['imported'],
					'essf_skipped'  => $result['skipped'],
				],
				admin_url( 'admin.php?page=essfinance' )
			)
		);
		exit;
	}

	/**
	 * Build dedup lookups shared by CSV and OFX imports: description+date+amount
	 * keys for exact-match dedup, FITID for OFX, and an amount→[id,date,title]
	 * index used to flag *possible* duplicates that differ only by
	 * description (the common case when a bank memo doesn't match the
	 * curated title of an already-recorded entry). The post ID travels with
	 * the match so a confirmed duplicate can be backfilled with the raw memo
	 * — see backfill_duplicate_memos().
	 *
	 * @return array{0: array<string, bool>, 1: array<string, bool>, 2: array<string, array<int, array{id: int, date: string, title: string}>>}
	 */
	private function build_dedup_lookups(): array {
		$existing     = [];
		$fitid_lookup = [];
		$amount_index = [];
		foreach ( get_posts(
			[
				'post_type'      => 'essf_cashflow',
				'post_status'    => [ 'pending', 'paid' ],
				'posts_per_page' => -1,
			]
		) as $p ) {
			$due    = substr( $p->post_date_gmt, 0, 10 );
			$pay    = substr( $p->post_modified_gmt, 0, 10 );
			$amount = round( (float) $p->post_content, 2 );

			$existing[ strtolower( trim( $p->post_title ) ) . '|' . $due . '|' . $amount ] = true;

			$fitid = get_post_meta( $p->ID, '_essf_fitid', true );
			if ( $fitid ) {
				$fitid_lookup[ $fitid ] = true;
			}

			$is_real_date = static function ( string $d ): bool {
				return '' !== $d && '0000-00-00' !== $d;
			};
			foreach ( array_unique( array_filter( [ $due, $pay ], $is_real_date ) ) as $date ) {
				$amount_index[ (string) $amount ][] = [
					'id'    => $p->ID,
					'date'  => $date,
					'title' => $p->post_title,
				];
			}
		}

		return [ $existing, $fitid_lookup, $amount_index ];
	}

	private function import_csv( $handle, array &$existing ): array {
		$imported = 0;
		$skipped  = 0;

		while ( ( $row = fgetcsv( $handle ) ) !== false ) {
			if ( count( $row ) < 5 ) {
				continue;
			}
			[ $description, $due_raw, $pay_raw, $status_raw, $amount_raw ] = $row;

			$description = sanitize_text_field( trim( $description ) );
			if ( '' === $description ) {
				continue;
			}

			$amount  = round( (float) str_replace( ',', '', $amount_raw ), 2 );
			$due_dt  = trim( $due_raw ) ? DateTime::createFromFormat( 'Y-m-d', trim( $due_raw ) ) : false;
			$pay_dt  = trim( $pay_raw ) ? DateTime::createFromFormat( 'Y-m-d', trim( $pay_raw ) ) : false;
			$due_gmt = $due_dt ? $due_dt->format( 'Y-m-d' ) . ' 00:00:00' : '0000-00-00 00:00:00';
			$pay_gmt = $pay_dt ? $pay_dt->format( 'Y-m-d' ) . ' 00:00:00' : '0000-00-00 00:00:00';
			$status  = sanitize_key( $status_raw );
			if ( ! array_key_exists( $status, ESSF_CPT::labels() ) ) {
				$status = 'pending';
			}
			$due_key = $due_dt ? $due_dt->format( 'Y-m-d' ) : '';

			$key = strtolower( $description ) . '|' . $due_key . '|' . $amount;
			if ( isset( $existing[ $key ] ) ) {
				++$skipped;
				continue;
			}

			if ( ESSF_CPT::insert_entry( $description, $amount, $due_gmt, $pay_gmt, $status, 'uncategorized', get_current_user_id() ) ) {
				$existing[ $key ] = true;
				++$imported;
			}
		}

		return compact( 'imported', 'skipped' );
	}

	/**
	 * Parse + dedupe an OFX file's transactions, stage the survivors in a
	 * short-lived transient, and redirect to the review page. Nothing is
	 * written to the database until the operator confirms.
	 *
	 * @param string $content      Raw OFX/QFX file contents.
	 * @param array  $existing     Exact-match dedup lookup from build_dedup_lookups().
	 * @param array  $fitid_lookup FITID dedup lookup from build_dedup_lookups().
	 * @param array  $amount_index Amount→[id,date,title] index from build_dedup_lookups().
	 */
	private function stage_ofx_import( string $content, array $existing, array $fitid_lookup, array $amount_index ): void {
		$excluded_memos   = get_option( self::EXCLUDED_MEMOS_OPTION, [] );
		$glossary_history = ESSF_Category::category_glossary_history();
		$staged           = self::build_ofx_stage_rows( ESSF_OFX_Parser::parse( $content ), $existing, $fitid_lookup, $amount_index, $excluded_memos, $glossary_history );

		if ( ! $staged['rows'] ) {
			wp_safe_redirect(
				add_query_arg(
					[
						'essf_msg'      => 'imported',
						'essf_imported' => 0,
						'essf_skipped'  => $staged['skipped'],
					],
					admin_url( 'admin.php?page=essfinance' )
				)
			);
			exit;
		}

		// A 40-char hex token, shaped like a git SHA-1 (short-form display
		// uses the first 7 chars, same as `git log --oneline`) rather than
		// wp_generate_password()'s mixed-case/no-symbol charset.
		$token = bin2hex( random_bytes( 20 ) );
		set_transient(
			self::review_transient_key( $token ),
			[
				'user_id' => get_current_user_id(),
				'skipped' => $staged['skipped'],
				'rows'    => $staged['rows'],
			],
			15 * MINUTE_IN_SECONDS
		);

		wp_safe_redirect(
			add_query_arg(
				[
					'page'        => 'essfinance',
					// Short in the URL, like a git ref — resolve_review_token()
					// looks the full token back up server-side; the full form
					// is still what actually secures the transient lookup.
					'essf_review' => substr( $token, 0, 7 ),
				],
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Pure dedup/staging logic for OFX transactions — no WordPress calls, so
	 * it's directly unit testable. Exact-match duplicates (by FITID, or by
	 * description+date+amount) are dropped silently, same as before this
	 * feature. Amount+date-only matches (different description — the normal
	 * case when a bank memo hasn't been mapped yet) are kept but flagged as
	 * `possible_duplicate`, and rows whose memo resembles one the operator
	 * has excluded before are flagged `suggested_exclude` — the review UI
	 * defaults both to excluded, dimmed, but still shown for confirmation.
	 *
	 * @param array $transactions   Rows returned by ESSF_OFX_Parser::parse().
	 * @param array $existing       Exact-match dedup lookup.
	 * @param array $fitid_lookup   FITID dedup lookup.
	 * @param array $amount_index   Amount→[id,date,title] index.
	 * @param array $excluded_memos Normalized memos of previously-excluded rows.
	 * @return array{rows: array<int, array>, skipped: int}
	 */
	public static function build_ofx_stage_rows( array $transactions, array $existing, array $fitid_lookup, array $amount_index = [], array $excluded_memos = [], array $glossary_history = [] ): array {
		$rows    = [];
		$skipped = 0;

		foreach ( $transactions as $trn ) {
			$fitid = $trn['fitid'] ?? '';
			if ( $fitid && isset( $fitid_lookup[ $fitid ] ) ) {
				++$skipped;
				continue;
			}

			$amount   = round( (float) ( $trn['amount'] ?? 0 ), 2 );
			$due_date = (string) ( $trn['due_date'] ?? '' );
			$name     = $trn['name'] ?? '';
			$memo     = $trn['memo'] ?? '';

			// "Transferência enviada/recebida ... - Nome - resto" reduces to
			// a short "Transferência Nome" title (direction is already the
			// amount's sign) with the counterparty detail kept for
			// _essf_ofx_detail; "Compra no débito/crédito [via X] - Merchant"
			// reduces to just the merchant name; "Pagamento de boleto
			// efetuado - Beneficiário" reduces to just the beneficiary name;
			// anything else falls back to describe(). Either way,
			// ESSF_OFX_Suggestions::suggest() still gets first pick if it
			// finds a learned mapping — this is only the fallback shown when
			// nothing in history matches yet.
			$transfer    = ESSF_OFX_Parser::parse_transfer( $name ?: $memo );
			$purchase    = $transfer ? null : ESSF_OFX_Parser::parse_purchase( $name ?: $memo );
			$boleto      = ( $transfer || null !== $purchase ) ? null : ESSF_OFX_Parser::parse_boleto( $name ?: $memo );
			$description = $transfer['title'] ?? $purchase ?? $boleto ?? ESSF_OFX_Parser::describe( $trn );

			$key = strtolower( $description ) . '|' . $due_date . '|' . $amount;
			if ( isset( $existing[ $key ] ) ) {
				++$skipped;
				continue;
			}

			$rows[] = [
				'fitid'              => $fitid,
				'amount'             => $amount,
				'due_date'           => $due_date,
				'name'               => $name,
				'memo'               => $memo,
				'description'        => $description,
				'category'           => ESSF_Category::guess_slug( $description, $glossary_history ),
				'transfer_detail'    => $transfer['detail'] ?? '',
				'possible_duplicate' => self::find_possible_duplicate( $amount, $due_date, $amount_index ),
				'suggested_exclude'  => ESSF_OFX_Suggestions::matches_excluded( $name ?: $memo, $excluded_memos ),
			];
		}

		return [
			'rows'    => $rows,
			'skipped' => $skipped,
		];
	}

	/**
	 * Look for an existing entry with the same amount within a small date
	 * tolerance, regardless of description. Used to flag likely duplicates
	 * that the exact-match key (which includes description) can't catch —
	 * bank memos rarely match a curated title verbatim.
	 *
	 * @param float  $amount         Transaction amount, already rounded.
	 * @param string $date           Transaction date, `Y-m-d`.
	 * @param array  $amount_index   Amount→[id,date,title] index.
	 * @param int    $tolerance_days Max day difference still considered a match.
	 * @return array{id: int, date: string, title: string}|null
	 */
	public static function find_possible_duplicate( float $amount, string $date, array $amount_index, int $tolerance_days = 2 ): ?array {
		$bucket = $amount_index[ (string) $amount ] ?? [];
		if ( ! $bucket || '' === $date ) {
			return null;
		}

		$target_ts = strtotime( $date );
		if ( false === $target_ts ) {
			return null;
		}

		foreach ( $bucket as $entry ) {
			$entry_ts = strtotime( $entry['date'] );
			if ( false === $entry_ts ) {
				continue;
			}
			if ( abs( $target_ts - $entry_ts ) / 86400 <= $tolerance_days ) {
				return $entry;
			}
		}

		return null;
	}

	/**
	 * Prior OFX memo→title history used to power review-page suggestions.
	 *
	 * @return array<int, array{memo: string, title: string, date: string}>
	 */
	private function ofx_memo_history(): array {
		$posts = get_posts(
			[
				'post_type'      => 'essf_cashflow',
				'post_status'    => [ 'pending', 'paid' ],
				'posts_per_page' => -1,
				'meta_key'       => '_essf_ofx_memo',
			]
		);

		$history = [];
		foreach ( $posts as $post ) {
			$memo = get_post_meta( $post->ID, '_essf_ofx_memo', true );
			if ( ! $memo ) {
				continue;
			}
			$history[] = [
				'memo'  => $memo,
				'title' => $post->post_title,
				'date'  => (string) get_post_meta( $post->ID, '_order_date', true ),
			];
		}

		return $history;
	}

	/**
	 * Prior OFX memo→category history used to power review-page category
	 * suggestions — same shape as ofx_memo_history(), but 'title' holds the
	 * post's category term name instead of its post_title, so the generic
	 * ESSF_OFX_Suggestions::suggest() ranking can be reused unmodified.
	 *
	 * @return array<int, array{memo: string, title: string, date: string}>
	 */
	private function ofx_category_history(): array {
		$posts = get_posts(
			[
				'post_type'      => 'essf_cashflow',
				'post_status'    => [ 'pending', 'paid' ],
				'posts_per_page' => -1,
				'meta_key'       => '_essf_ofx_memo',
			]
		);

		$history = [];
		foreach ( $posts as $post ) {
			$memo = get_post_meta( $post->ID, '_essf_ofx_memo', true );
			if ( ! $memo ) {
				continue;
			}
			$terms     = wp_get_post_terms( $post->ID, ESSF_Category::TAXONOMY, [ 'fields' => 'names' ] );
			$history[] = [
				'memo'  => $memo,
				'title' => $terms[0] ?? __( 'Uncategorized', 'essfinance' ),
				'date'  => (string) get_post_meta( $post->ID, '_order_date', true ),
			];
		}

		return $history;
	}

	private static function review_transient_key( string $token ): string {
		return 'essf_ofx_review_' . $token;
	}

	/**
	 * Resolve the short (7-char) token shown in the review URL back to the
	 * full 40-char one the transient is actually keyed on. Transients don't
	 * support a prefix lookup, so this is a direct LIKE query against
	 * wp_options — same idea as `git show <short-sha>` resolving against the
	 * full object database. The full token is what secures the lookup; the
	 * short form only exists for a shorter, human-readable URL.
	 *
	 * @param string $short The token prefix from `$_GET['essf_review']`.
	 */
	private function resolve_review_token( string $short ): ?string {
		$short = preg_replace( '/[^a-f0-9]/', '', strtolower( $short ) );
		if ( '' === $short ) {
			return null;
		}

		global $wpdb;
		$option_prefix = '_transient_' . self::review_transient_key( $short );
		$option_name   = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $wpdb->options is the table name, not user input
				$wpdb->esc_like( $option_prefix ) . '%'
			)
		);

		if ( ! $option_name ) {
			return null;
		}

		$full_token = substr( $option_name, strlen( '_transient_' . self::review_transient_key( '' ) ) );

		return '' === $full_token ? null : $full_token;
	}

	public function handle_import_ofx_confirm(): void {
		check_admin_referer( self::IMPORT_OFX_CONFIRM_NONCE );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized.', 'essfinance' ) );
		}

		$token  = isset( $_POST['essf_review_token'] ) ? sanitize_text_field( wp_unslash( $_POST['essf_review_token'] ) ) : '';
		$staged = $token ? get_transient( self::review_transient_key( $token ) ) : false;

		if ( ! is_array( $staged ) || (int) ( $staged['user_id'] ?? 0 ) !== get_current_user_id() ) {
			wp_safe_redirect( add_query_arg( 'essf_msg', 'import_error', admin_url( 'admin.php?page=essfinance' ) ) );
			exit;
		}

		// Only the description and include flag are trusted from the client —
		// amount/date/FITID/memo are re-read from the transient, never from
		// resubmitted form fields. resolve_ofx_confirm_rows() trims/
		// sanitizes the description; include is boolean-checked.
		$posted_rows = isset( $_POST['essf_rows'] ) && is_array( $_POST['essf_rows'] ) ? wp_unslash( $_POST['essf_rows'] ) : []; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- values are trimmed/checked downstream in resolve_ofx_confirm_rows() and sanitize_text_field()'d again before insert
		$resolved    = self::resolve_ofx_confirm_rows( $staged['rows'], $posted_rows );

		$imported = 0;
		foreach ( $resolved['insert'] as $row ) {
			$description = sanitize_text_field( $row['description'] );
			$category    = sanitize_key( $row['category'] );
			$due_dt      = $row['due_date'] ? DateTime::createFromFormat( 'Y-m-d', $row['due_date'] ) : false;
			$due_gmt     = $due_dt ? $due_dt->format( 'Y-m-d' ) . ' 00:00:00' : '0000-00-00 00:00:00';

			// A bank statement transaction has already cleared, so OFX
			// imports always land paid — there's no pending state to review.
			$post_id = ESSF_CPT::insert_entry( $description, (float) $row['amount'], $due_gmt, $due_gmt, 'paid', $category, get_current_user_id() );
			if ( ! $post_id ) {
				continue;
			}
			if ( $row['fitid'] ) {
				update_post_meta( $post_id, '_essf_fitid', $row['fitid'] );
			}
			$raw_memo = $row['memo'] ?: $row['name'];
			if ( $raw_memo ) {
				update_post_meta( $post_id, '_essf_ofx_memo', $raw_memo );
			}
			if ( ! empty( $row['transfer_detail'] ) ) {
				update_post_meta( $post_id, '_essf_ofx_detail', $row['transfer_detail'] );
			}
			++$imported;
		}

		$this->remember_excluded_memos( $resolved['excluded'] );
		$this->backfill_duplicate_memos( $resolved['excluded'] );
		delete_transient( self::review_transient_key( $token ) );

		wp_safe_redirect(
			add_query_arg(
				[
					'essf_msg'      => 'imported',
					'essf_imported' => $imported,
					'essf_skipped'  => (int) ( $staged['skipped'] ?? 0 ) + $resolved['skipped'],
				],
				admin_url( 'admin.php?page=essfinance' )
			)
		);
		exit;
	}

	/**
	 * Merge the operator's review-grid edits (description/include) onto the
	 * server-trusted staged rows. Pure logic, no WordPress calls. Excluded
	 * rows are returned in full (not just counted) so the caller can learn
	 * from them — see remember_excluded_memos().
	 *
	 * @param array $staged_rows Rows from the transient, indexed 0..n-1.
	 * @param array $posted_rows `$_POST['essf_rows']`, same indices.
	 * @return array{insert: array<int, array>, excluded: array<int, array>, skipped: int}
	 */
	public static function resolve_ofx_confirm_rows( array $staged_rows, array $posted_rows ): array {
		$to_insert = [];
		$excluded  = [];

		foreach ( $staged_rows as $i => $row ) {
			$posted = $posted_rows[ $i ] ?? null;
			if ( ! $posted || empty( $posted['include'] ) ) {
				$excluded[] = $row;
				continue;
			}

			$description = trim( (string) ( $posted['description'] ?? '' ) );
			if ( '' === $description ) {
				$description = $row['description'];
			}

			$category = trim( (string) ( $posted['category'] ?? '' ) );
			if ( '' === $category ) {
				$category = $row['category'] ?? 'uncategorized';
			}

			$to_insert[] = array_merge(
				$row,
				[
					'description' => $description,
					'category'    => $category,
				]
			);
		}

		return [
			'insert'   => $to_insert,
			'excluded' => $excluded,
			'skipped'  => count( $excluded ),
		];
	}

	/**
	 * Learn from rows the operator excluded this round so similar memos
	 * (balance lines, internal transfers, etc.) default to excluded on
	 * future imports too — see ESSF_OFX_Suggestions::matches_excluded().
	 *
	 * @param array $excluded_rows Rows the operator left unchecked, from resolve_ofx_confirm_rows().
	 */
	private function remember_excluded_memos( array $excluded_rows ): void {
		if ( ! $excluded_rows ) {
			return;
		}

		$memos = get_option( self::EXCLUDED_MEMOS_OPTION, [] );
		if ( ! is_array( $memos ) ) {
			$memos = [];
		}

		foreach ( $excluded_rows as $row ) {
			$raw_memo = ( $row['name'] ?? '' ) ?: ( $row['memo'] ?? '' );
			if ( ! $raw_memo ) {
				continue;
			}
			$normalized = ESSF_OFX_Suggestions::normalize( $raw_memo );
			if ( '' === $normalized || in_array( $normalized, $memos, true ) ) {
				continue;
			}
			$memos[] = $normalized;
		}

		if ( count( $memos ) > self::EXCLUDED_MEMOS_LIMIT ) {
			$memos = array_slice( $memos, -self::EXCLUDED_MEMOS_LIMIT );
		}

		update_option( self::EXCLUDED_MEMOS_OPTION, $memos, false );
	}

	/**
	 * When the operator leaves a `possible_duplicate` row excluded, they've
	 * implicitly confirmed it's the same real-world transaction as the
	 * existing entry it matched — so tag that existing entry with the raw
	 * memo (if it doesn't already have one) rather than only ever learning
	 * from newly-inserted rows. Without this, an entry created before this
	 * feature existed (or by hand, or via CSV) never feeds
	 * ESSF_OFX_Suggestions, even after we've just proven its memo pattern.
	 *
	 * Only applies to possible_duplicate matches, not suggested_exclude
	 * (noise like balance lines) — those have no matched post to tag, and
	 * are already learned via remember_excluded_memos().
	 *
	 * @param array $excluded_rows Rows the operator left excluded, from resolve_ofx_confirm_rows().
	 */
	private function backfill_duplicate_memos( array $excluded_rows ): void {
		foreach ( $excluded_rows as $row ) {
			$dupe = $row['possible_duplicate'] ?? null;
			if ( empty( $dupe['id'] ) ) {
				continue;
			}

			$raw_memo = ( $row['name'] ?? '' ) ?: ( $row['memo'] ?? '' );
			if ( ! $raw_memo ) {
				continue;
			}

			if ( get_post_meta( $dupe['id'], '_essf_ofx_memo', true ) ) {
				continue;
			}

			update_post_meta( $dupe['id'], '_essf_ofx_memo', $raw_memo );
		}
	}

	/* ── OFX glossary (learned suggestions) ──────────────── */

	/**
	 * Every essf_cashflow post currently feeding ESSF_OFX_Suggestions —
	 * i.e. one row per _essf_ofx_memo, for the glossary admin page. Unlike
	 * ofx_memo_history() (which only returns what suggest() itself needs),
	 * this also carries the post ID so each row can be individually
	 * "forgotten".
	 *
	 * @return array<int, array{id: int, title: string, memo: string, detail: string}>
	 */
	private function ofx_glossary_entries(): array {
		$posts = get_posts(
			[
				'post_type'      => 'essf_cashflow',
				'post_status'    => [ 'pending', 'paid' ],
				'posts_per_page' => -1,
				'meta_key'       => '_essf_ofx_memo',
				'orderby'        => 'title',
				'order'          => 'ASC',
			]
		);

		$entries = [];
		foreach ( $posts as $post ) {
			$memo = get_post_meta( $post->ID, '_essf_ofx_memo', true );
			if ( ! $memo ) {
				continue;
			}
			$entries[] = [
				'id'     => $post->ID,
				'title'  => $post->post_title,
				'memo'   => $memo,
				'detail' => (string) get_post_meta( $post->ID, '_essf_ofx_detail', true ),
			];
		}

		return $entries;
	}

	public function render_categories_page(): void {
		$table = new ESSF_Category_List_Table();
		$table->prepare_items();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Categories', 'essfinance' ); ?></h1>
			<hr class="wp-header-end">

			<form method="get">
				<input type="hidden" name="page" value="essfinance-categories">
				<?php
				$table->search_box( __( 'Search Categories', 'essfinance' ), 'essf-categories' );
				$table->display();
				?>
			</form>
		</div>
		<?php
	}

	public function render_glossary_page(): void {
		$msg          = isset( $_GET['essf_msg'] ) ? sanitize_key( $_GET['essf_msg'] ) : '';
		$settings_url = admin_url( 'admin.php?page=essfinance-settings' );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'OFX Glossary', 'essfinance' ); ?></h1>
			<a href="<?php echo esc_url( $settings_url ); ?>" class="page-title-action">
				&larr; <?php esc_html_e( 'Settings', 'essfinance' ); ?>
			</a>
			<hr class="wp-header-end">

			<?php if ( 'forgotten' === $msg ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Entry forgotten.', 'essfinance' ); ?></p></div>
			<?php endif; ?>

			<p class="description">
				<?php esc_html_e( 'These are the raw bank memos EssFinance has learned to recognize during OFX review, and what each currently suggests. "Forget" clears the learned mapping only — the underlying entry stays exactly as it is.', 'essfinance' ); ?>
			</p>

			<h2><?php esc_html_e( 'Learned descriptions', 'essfinance' ); ?></h2>
			<?php $glossary = $this->ofx_glossary_entries(); ?>
			<?php if ( ! $glossary ) : ?>
				<p><?php esc_html_e( 'Nothing learned yet — this fills in as you confirm OFX imports.', 'essfinance' ); ?></p>
			<?php else : ?>
				<table class="wp-list-table widefat fixed striped">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Description', 'essfinance' ); ?></th>
							<th><?php esc_html_e( 'Raw Memo', 'essfinance' ); ?></th>
							<th><?php esc_html_e( 'Detail', 'essfinance' ); ?></th>
							<th></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $glossary as $entry ) : ?>
							<tr>
								<td><?php echo esc_html( $entry['title'] ); ?></td>
								<td><?php echo esc_html( $entry['memo'] ); ?></td>
								<td><?php echo esc_html( $entry['detail'] ); ?></td>
								<td>
									<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=essf_forget_memo&entry=' . $entry['id'] ), self::FORGET_MEMO_NONCE . '_' . $entry['id'] ) ); ?>"
										onclick="return confirm('<?php echo esc_js( __( 'Forget this mapping? The entry itself will not be changed.', 'essfinance' ) ); ?>')">
										<?php esc_html_e( 'Forget', 'essfinance' ); ?>
									</a>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>

			<h2><?php esc_html_e( 'Excluded patterns', 'essfinance' ); ?></h2>
			<p class="description">
				<?php esc_html_e( 'Memo patterns you\'ve excluded during review before (e.g. balances, internal transfers) — future imports matching one of these default to excluded too.', 'essfinance' ); ?>
			</p>
			<?php $excluded = get_option( self::EXCLUDED_MEMOS_OPTION, [] ); ?>
			<?php if ( ! is_array( $excluded ) || ! $excluded ) : ?>
				<p><?php esc_html_e( 'Nothing excluded yet.', 'essfinance' ); ?></p>
			<?php else : ?>
				<table class="wp-list-table widefat fixed striped">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Pattern', 'essfinance' ); ?></th>
							<th></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $excluded as $pattern ) : ?>
							<tr>
								<td><?php echo esc_html( $pattern ); ?></td>
								<td>
									<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=essf_forget_excluded&pattern=' . rawurlencode( $pattern ) ), self::FORGET_EXCLUDED_NONCE ) ); ?>">
										<?php esc_html_e( 'Forget', 'essfinance' ); ?>
									</a>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
		<?php
	}

	public function handle_forget_memo(): void {
		$post_id = absint( $_GET['entry'] ?? 0 );
		check_admin_referer( self::FORGET_MEMO_NONCE . '_' . $post_id );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized.', 'essfinance' ) );
		}

		$post = get_post( $post_id );
		if ( $post && 'essf_cashflow' === $post->post_type ) {
			delete_post_meta( $post_id, '_essf_ofx_memo' );
			delete_post_meta( $post_id, '_essf_ofx_detail' );
		}

		wp_safe_redirect( add_query_arg( 'essf_msg', 'forgotten', admin_url( 'admin.php?page=essfinance-ofx-glossary' ) ) );
		exit;
	}

	/* ── Category glossary (learned from repetition) ─────── */

	public function render_category_glossary_page(): void {
		$msg          = isset( $_GET['essf_msg'] ) ? sanitize_key( $_GET['essf_msg'] ) : '';
		$settings_url = admin_url( 'admin.php?page=essfinance-settings' );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Category Glossary', 'essfinance' ); ?></h1>
			<a href="<?php echo esc_url( $settings_url ); ?>" class="page-title-action">
				&larr; <?php esc_html_e( 'Settings', 'essfinance' ); ?>
			</a>
			<hr class="wp-header-end">

			<?php if ( 'forgotten' === $msg ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Entry forgotten.', 'essfinance' ); ?></p></div>
			<?php endif; ?>

			<p class="description">
				<?php esc_html_e( 'Every categorized entry teaches "Auto Set Category" by example — correct one and similar descriptions use that category from then on. "Forget" stops an entry from teaching by example; its own category is not changed.', 'essfinance' ); ?>
			</p>

			<?php $glossary = ESSF_Category::category_glossary_history(); ?>
			<?php if ( ! $glossary ) : ?>
				<p><?php esc_html_e( 'Nothing learned yet — this fills in as entries get categorized.', 'essfinance' ); ?></p>
			<?php else : ?>
				<table class="wp-list-table widefat fixed striped">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Description', 'essfinance' ); ?></th>
							<th><?php esc_html_e( 'Category', 'essfinance' ); ?></th>
							<th></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $glossary as $entry ) : ?>
							<tr>
								<td><?php echo esc_html( $entry['memo'] ); ?></td>
								<td><?php echo esc_html( $entry['title'] ); ?></td>
								<td>
									<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=essf_forget_category_glossary&entry=' . $entry['id'] ), self::FORGET_CATEGORY_GLOSSARY_NONCE . '_' . $entry['id'] ) ); ?>"
										onclick="return confirm('<?php echo esc_js( __( 'Forget this mapping? The entry itself will not be changed.', 'essfinance' ) ); ?>')">
										<?php esc_html_e( 'Forget', 'essfinance' ); ?>
									</a>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
		<?php
	}

	public function handle_forget_category_glossary(): void {
		$post_id = absint( $_GET['entry'] ?? 0 );
		check_admin_referer( self::FORGET_CATEGORY_GLOSSARY_NONCE . '_' . $post_id );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized.', 'essfinance' ) );
		}

		$post = get_post( $post_id );
		if ( $post && 'essf_cashflow' === $post->post_type ) {
			update_post_meta( $post_id, ESSF_Category::GLOSSARY_EXCLUDED_META, '1' );
		}

		wp_safe_redirect( add_query_arg( 'essf_msg', 'forgotten', admin_url( 'admin.php?page=essfinance-category-glossary' ) ) );
		exit;
	}

	public function handle_forget_excluded(): void {
		check_admin_referer( self::FORGET_EXCLUDED_NONCE );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized.', 'essfinance' ) );
		}

		$pattern = isset( $_GET['pattern'] ) ? sanitize_text_field( wp_unslash( $_GET['pattern'] ) ) : '';
		if ( '' !== $pattern ) {
			$excluded = get_option( self::EXCLUDED_MEMOS_OPTION, [] );
			if ( is_array( $excluded ) ) {
				$excluded = array_values( array_diff( $excluded, [ $pattern ] ) );
				update_option( self::EXCLUDED_MEMOS_OPTION, $excluded, false );
			}
		}

		wp_safe_redirect( add_query_arg( 'essf_msg', 'forgotten', admin_url( 'admin.php?page=essfinance-ofx-glossary' ) ) );
		exit;
	}

	private static function format_amount_csv( float $amount ): string {
		$prefix = $amount < 0 ? '-' : '';
		$abs    = abs( $amount );
		if ( (float) (int) $abs === $abs ) {
			return $prefix . (string) (int) $abs;
		}
		return $prefix . rtrim( number_format( $abs, 2, '.', '' ), '0' );
	}

	public function handle_delete(): void {
		$post_id = absint( $_GET['entry'] ?? 0 );
		check_admin_referer( self::DELETE_NONCE . '_' . $post_id );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized.', 'essfinance' ) );
		}

		$post = get_post( $post_id );
		if ( $post && 'essf_cashflow' === $post->post_type ) {
			wp_delete_post( $post_id, true );
		}

		wp_safe_redirect( add_query_arg( 'essf_msg', 'deleted', admin_url( 'admin.php?page=essfinance' ) ) );
		exit;
	}

	public function handle_paid_today(): void {
		$post_id = absint( $_GET['entry'] ?? 0 );
		check_admin_referer( self::PAID_TODAY_NONCE . '_' . $post_id );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized.', 'essfinance' ) );
		}

		$post = get_post( $post_id );
		if ( ! $post || 'essf_cashflow' !== $post->post_type ) {
			wp_safe_redirect( add_query_arg( 'essf_msg', 'error', admin_url( 'admin.php?page=essfinance' ) ) );
			exit;
		}

		$today = current_time( 'mysql', true );
		global $wpdb;
		$wpdb->update(
			$wpdb->posts,
			[
				'post_status'       => 'paid',
				'post_modified_gmt' => $today,
			],
			[ 'ID' => $post_id ],
			[ '%s', '%s' ],
			[ '%d' ]
		);
		update_post_meta( $post_id, '_order_date', substr( $today, 0, 10 ) );

		$back = wp_get_referer() ?: admin_url( 'admin.php?page=essfinance' );
		wp_safe_redirect( add_query_arg( 'essf_msg', 'paid_today', $back ) );
		exit;
	}

	public function handle_toggle_type(): void {
		$post_id = absint( $_GET['entry'] ?? 0 );
		check_admin_referer( self::TOGGLE_TYPE_NONCE . '_' . $post_id );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized.', 'essfinance' ) );
		}

		$post = get_post( $post_id );
		if ( ! $post || 'essf_cashflow' !== $post->post_type ) {
			wp_safe_redirect( add_query_arg( 'essf_msg', 'error', admin_url( 'admin.php?page=essfinance' ) ) );
			exit;
		}

		$new_amount = (string) ( - (float) $post->post_content );
		global $wpdb;
		$wpdb->update( $wpdb->posts, [ 'post_content' => $new_amount ], [ 'ID' => $post_id ], [ '%s' ], [ '%d' ] );

		$back = wp_get_referer() ?: admin_url( 'admin.php?page=essfinance' );
		wp_safe_redirect( add_query_arg( 'essf_msg', 'toggled', $back ) );
		exit;
	}

	/**
	 * Confirms the "Group as Loan" staging: renames every staged entry to
	 * `"{base} {i}/{n}"` (title-only, so wp_update_post() is safe here — see
	 * CLAUDE.md's post_date_gmt/post_modified_gmt caveat, which doesn't apply
	 * to post_title), and creates the matching Loan term if it doesn't exist
	 * yet. Principal is left at 0 — the operator sets it on the term after.
	 */
	public function handle_group_confirm(): void {
		check_admin_referer( self::GROUP_CONFIRM_NONCE );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized.', 'essfinance' ) );
		}

		$token  = isset( $_POST['token'] ) ? sanitize_text_field( wp_unslash( $_POST['token'] ) ) : '';
		$staged = $token ? get_transient( 'essf_group_review_' . $token ) : false;
		if ( ! is_array( $staged ) || (int) ( $staged['user_id'] ?? 0 ) !== get_current_user_id() ) {
			wp_safe_redirect( add_query_arg( 'essf_msg', 'group_error', admin_url( 'admin.php?page=essfinance' ) ) );
			exit;
		}

		$base = sanitize_text_field( wp_unslash( $_POST['base_description'] ?? '' ) );
		if ( '' === $base ) {
			wp_safe_redirect( add_query_arg( 'essf_msg', 'group_error', admin_url( 'admin.php?page=essfinance' ) ) );
			exit;
		}

		$entries = array_filter( array_map( 'get_post', $staged['ids'] ?? [] ) );
		$n       = count( $entries );
		$i       = 1;
		foreach ( $entries as $entry ) {
			wp_update_post(
				[
					'ID'         => $entry->ID,
					'post_title' => $base . ' ' . $i . '/' . $n,
				]
			);
			++$i;
		}

		if ( ! term_exists( $base, ESSF_Loan_CPT::TAXONOMY ) ) {
			wp_insert_term( $base, ESSF_Loan_CPT::TAXONOMY );
		}

		delete_transient( 'essf_group_review_' . $token );

		wp_safe_redirect( add_query_arg( 'essf_msg', 'grouped', admin_url( 'admin.php?page=essfinance' ) ) );
		exit;
	}

	/**
	 * Confirms the "Split into installments" staging: deletes the single
	 * lump-sum entry and inserts N new entries in its place, one per the next
	 * N not-yet-materialized slots of the chosen Financing plan (title/date
	 * from the plan's schedule, amount = original amount / N — not the
	 * plan's own per-installment amount, since this lump sum may not match
	 * it exactly).
	 */
	public function handle_split_confirm(): void {
		check_admin_referer( self::SPLIT_CONFIRM_NONCE );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized.', 'essfinance' ) );
		}

		$token  = isset( $_POST['token'] ) ? sanitize_text_field( wp_unslash( $_POST['token'] ) ) : '';
		$staged = $token ? get_transient( 'essf_split_review_' . $token ) : false;
		if ( ! is_array( $staged ) || (int) ( $staged['user_id'] ?? 0 ) !== get_current_user_id() ) {
			wp_safe_redirect( add_query_arg( 'essf_msg', 'split_error', admin_url( 'admin.php?page=essfinance' ) ) );
			exit;
		}

		$post = get_post( $staged['id'] ?? 0 );
		if ( ! $post || 'essf_cashflow' !== $post->post_type ) {
			wp_safe_redirect( add_query_arg( 'essf_msg', 'split_error', admin_url( 'admin.php?page=essfinance' ) ) );
			exit;
		}

		$term_id = absint( $_POST['plan_term_id'] ?? 0 );
		$term    = get_term( $term_id, ESSF_Financing_CPT::TAXONOMY );
		if ( ! $term || is_wp_error( $term ) ) {
			wp_safe_redirect( add_query_arg( 'essf_msg', 'split_error', admin_url( 'admin.php?page=essfinance' ) ) );
			exit;
		}

		$count = max( 2, min( 60, absint( $_POST['split_count'] ?? 0 ) ) );

		$indices = ESSF_Financing_CPT::next_unmaterialized_indices( $term, $count );
		if ( count( $indices ) < $count ) {
			wp_safe_redirect( add_query_arg( 'essf_msg', 'split_error', admin_url( 'admin.php?page=essfinance' ) ) );
			exit;
		}

		$n            = (int) get_term_meta( $term_id, ESSF_Financing_CPT::META_INSTALLMENTS, true );
		$unit         = (string) get_term_meta( $term_id, ESSF_Financing_CPT::META_INTERVAL_UNIT, true ) ?: 'month';
		$interval_cnt = (int) get_term_meta( $term_id, ESSF_Financing_CPT::META_INTERVAL_COUNT, true ) ?: 1;
		$start        = (string) get_term_meta( $term_id, ESSF_Financing_CPT::META_START, true ) ?: current_time( 'Y-m-d' );
		$category     = (string) get_term_meta( $term_id, ESSF_Financing_CPT::META_CATEGORY, true ) ?: 'uncategorized';

		$total  = (float) $post->post_content;
		$status = $post->post_status;
		$pay    = $post->post_modified_gmt;
		$part   = round( $total / $count, 2 );

		wp_delete_post( $post->ID, true );

		$i = 0;
		foreach ( $indices as $index ) {
			$due    = ESSF_Financing_CPT::installment_due_date( $unit, $interval_cnt, $start, $index );
			$amount = ( $count - 1 === $i ) ? round( $total - $part * ( $count - 1 ), 2 ) : $part;
			ESSF_CPT::insert_entry(
				ESSF_Financing_CPT::installment_title( $term->name, $index, $n ),
				$amount,
				$due . ' 00:00:00',
				$pay,
				$status,
				$category,
				(int) $post->post_author
			);
			++$i;
		}

		delete_transient( 'essf_split_review_' . $token );

		wp_safe_redirect( add_query_arg( 'essf_msg', 'split', admin_url( 'admin.php?page=essfinance' ) ) );
		exit;
	}

	/**
	 * One-click backfill: scans every existing entry via
	 * ESSF_Plan_Detector::find_missing_terms() and creates every candidate
	 * plan term right away — no review step (candidates are additive only
	 * and re-running is always safe, since each type checks term_exists()
	 * before creating).
	 */
	public function handle_detect_plans(): void {
		check_admin_referer( self::DETECT_PLANS_NONCE );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized.', 'essfinance' ) );
		}

		$counts = [
			'loan'      => 0,
			'financing' => 0,
			'bill'      => 0,
		];

		foreach ( ESSF_Plan_Detector::find_missing_terms() as $candidate ) {
			switch ( $candidate['type'] ) {
				case 'loan':
					if ( ESSF_Loan_CPT::create_term_from_detection( $candidate['name'], $candidate['meta'] ) ) {
						++$counts['loan'];
					}
					break;
				case 'financing':
					if ( ESSF_Financing_CPT::create_term_from_detection( $candidate['name'], $candidate['meta'] ) ) {
						++$counts['financing'];
					}
					break;
				case 'bill':
					if ( ESSF_Bill_CPT::create_term_from_detection( $candidate['name'], $candidate['meta'] ) ) {
						++$counts['bill'];
					}
					break;
			}
		}

		wp_safe_redirect(
			add_query_arg(
				[
					'essf_msg'                => 'plans_detected',
					'essf_detected_loan'      => $counts['loan'],
					'essf_detected_financing' => $counts['financing'],
					'essf_detected_bill'      => $counts['bill'],
				],
				admin_url( 'admin.php?page=essfinance' )
			)
		);
		exit;
	}

	private function parse_form_data( array $post ): array {
		$title     = sanitize_text_field( wp_unslash( $post['essf_description'] ?? '' ) ) ?: __( 'Entry', 'essfinance' );
		$amount_in = (float) wp_unslash( $post['essf_amount'] ?? 0 );
		$is_income = isset( $post['essf_is_income'] ) && '1' === wp_unslash( $post['essf_is_income'] );
		$amount    = (string) ( $is_income ? abs( $amount_in ) : -abs( $amount_in ) );

		$due_raw = sanitize_text_field( wp_unslash( $post['essf_due_date'] ?? '' ) );
		$pay_raw = sanitize_text_field( wp_unslash( $post['essf_pay_date'] ?? '' ) );
		$due_dt  = $due_raw ? DateTime::createFromFormat( 'Y-m-d', $due_raw ) : false;
		$pay_dt  = $pay_raw ? DateTime::createFromFormat( 'Y-m-d', $pay_raw ) : false;
		$due_gmt = $due_dt ? $due_dt->format( 'Y-m-d' ) . ' 00:00:00' : '0000-00-00 00:00:00';
		$pay_gmt = $pay_dt ? $pay_dt->format( 'Y-m-d' ) . ' 00:00:00' : '0000-00-00 00:00:00';

		$status = sanitize_key( wp_unslash( $post['essf_status'] ?? 'pending' ) );
		if ( ! array_key_exists( $status, ESSF_CPT::labels() ) ) {
			$status = 'pending';
		}

		$category = sanitize_key( wp_unslash( $post['essf_category'] ?? 'uncategorized' ) );
		if ( ESSF_Category::AUTO_SLUG === $category ) {
			$category = ESSF_Category::guess_slug( $title, ESSF_Category::category_glossary_history() );
		}

		$order_date = '0000-00-00 00:00:00' !== $pay_gmt
			? substr( $pay_gmt, 0, 10 )
			: ( '0000-00-00 00:00:00' !== $due_gmt ? substr( $due_gmt, 0, 10 ) : '' );

		return compact( 'title', 'amount', 'is_income', 'due_gmt', 'pay_gmt', 'status', 'order_date', 'category' );
	}

	/* ── Pages ──────────────────────────────────────────── */

	private function render_totals_summary( array $filtered ): void {
		if ( ! ESSF_Settings::show_totals() ) {
			return;
		}
		$global = ESSF_Totals::global_summary();

		$cards = [
			[ __( 'Filtered income', 'essfinance' ), $filtered['income'], 'is-positive' ],
			[ __( 'Filtered expenses', 'essfinance' ), $filtered['expense'], 'is-negative' ],
			[ __( 'Filtered net', 'essfinance' ), $filtered['net'], $filtered['net'] < 0 ? 'is-negative' : 'is-positive' ],
			[ __( 'Pending balance', 'essfinance' ), $global['pending_balance'], $global['pending_balance'] < 0 ? 'is-negative' : 'is-positive' ],
			[ __( 'Overdue total', 'essfinance' ), $global['overdue_total'], 'is-negative' ],
			[ __( 'Paid this month', 'essfinance' ), $global['paid_this_month'], '' ],
		];
		?>
		<div class="essf-summary">
			<?php foreach ( $cards as list( $label, $amount, $cls ) ) : ?>
				<div class="essf-summary__card">
					<span class="essf-summary__label"><?php echo esc_html( $label ); ?></span>
					<span class="essf-summary__value<?php echo $cls ? ' ' . esc_attr( $cls ) : ''; ?>"><?php echo esc_html( ESSF_Settings::format_amount( $amount ) ); ?></span>
				</div>
			<?php endforeach; ?>
		</div>
		<?php
	}

	public function render_dashboard(): void {
		$msg = isset( $_GET['essf_msg'] ) ? sanitize_key( $_GET['essf_msg'] ) : '';

		if ( ! empty( $_GET['essf_review'] ) && current_user_can( 'manage_options' ) ) {
			$short  = sanitize_text_field( wp_unslash( $_GET['essf_review'] ) );
			$token  = $this->resolve_review_token( $short );
			$staged = $token ? get_transient( self::review_transient_key( $token ) ) : false;
			if ( $token && is_array( $staged ) && (int) ( $staged['user_id'] ?? 0 ) === get_current_user_id() ) {
				$this->render_review_page( $token, $staged );
				return;
			}
			$msg = 'import_error';
		}

		if ( ! empty( $_GET['essf_group'] ) && current_user_can( 'manage_options' ) ) {
			$token  = sanitize_text_field( wp_unslash( $_GET['essf_group'] ) );
			$staged = get_transient( 'essf_group_review_' . $token );
			if ( is_array( $staged ) && (int) ( $staged['user_id'] ?? 0 ) === get_current_user_id() ) {
				$this->render_group_review_page( $token, $staged );
				return;
			}
			$msg = 'group_error';
		}

		if ( ! empty( $_GET['essf_split'] ) && current_user_can( 'manage_options' ) ) {
			$token  = sanitize_text_field( wp_unslash( $_GET['essf_split'] ) );
			$staged = get_transient( 'essf_split_review_' . $token );
			if ( is_array( $staged ) && (int) ( $staged['user_id'] ?? 0 ) === get_current_user_id() ) {
				$this->render_split_review_page( $token, $staged );
				return;
			}
			$msg = 'split_error';
		}

		if ( ! empty( $_GET['entry'] ) && current_user_can( 'manage_options' ) ) {
			$post = get_post( absint( $_GET['entry'] ) );
			if ( $post && 'essf_cashflow' === $post->post_type ) {
				$this->render_edit_page( $post, $msg );
				return;
			}
		}

		$essf_action = isset( $_GET['essf_action'] ) ? sanitize_key( $_GET['essf_action'] ) : '';

		$table = new ESSF_List_Table();
		$table->prepare_items();
		?>
		<div class="wrap">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Cash Flow', 'essfinance' ); ?></h1>
			<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=essf_export' ), 'essf_export' ) ); ?>" class="page-title-action">
				<?php esc_html_e( 'Export', 'essfinance' ); ?>
			</a>
			<a href="<?php echo esc_url( add_query_arg( 'essf_action', 'import', admin_url( 'admin.php?page=essfinance' ) ) ); ?>" class="page-title-action<?php echo 'import' === $essf_action ? ' button-primary' : ''; ?>">
				<?php esc_html_e( 'Import', 'essfinance' ); ?>
			</a>
			<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=essf_detect_plans' ), self::DETECT_PLANS_NONCE ) ); ?>" class="page-title-action">
				<?php esc_html_e( 'Auto-detect Plans', 'essfinance' ); ?>
			</a>
			<hr class="wp-header-end">

			<?php $this->render_notices( $msg ); ?>

			<div id="col-container" class="wp-clearfix">
				<div id="col-left">
					<div class="col-wrap">
						<?php if ( 'import' === $essf_action ) : ?>
							<?php $this->render_import_form(); ?>
						<?php else : ?>
							<?php $this->render_form(); ?>
						<?php endif; ?>
					</div>
				</div>
				<div id="col-right">
					<div class="col-wrap">
						<?php $this->render_totals_summary( $table->get_filtered_totals() ); ?>

						<?php $table->views(); ?>

						<form id="essf-filter" method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>">
							<input type="hidden" name="page" value="essfinance">
							<?php
							$table->search_box( __( 'Search Entries', 'essfinance' ), 'essf' );
							$table->display();
							?>
						</form>
					</div>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Confirmation screen for the "Group as Loan" bulk action — lets the
	 * operator type one unambiguous base description before anything is
	 * renamed. Renders `"{base} {i}/{n}"` previews live via JS since the base
	 * text doesn't exist server-side yet.
	 *
	 * @param string $token  Full staging token (see stage_group_as_loan()).
	 * @param array  $staged Transient payload: user_id, ids.
	 */
	private function render_group_review_page( string $token, array $staged ): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized.', 'essfinance' ) );
		}
		$entries  = array_filter( array_map( 'get_post', $staged['ids'] ?? [] ) );
		$n        = count( $entries );
		$back_url = admin_url( 'admin.php?page=essfinance' );
		?>
		<div class="wrap">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Group as Loan', 'essfinance' ); ?></h1>
			<a href="<?php echo esc_url( $back_url ); ?>" class="page-title-action">&larr; <?php esc_html_e( 'Cash Flow', 'essfinance' ); ?></a>
			<hr class="wp-header-end">

			<p><?php esc_html_e( 'These entries will be renamed to a shared, numbered description and grouped under a Loan of the same name.', 'essfinance' ); ?></p>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="essf_group_confirm">
				<input type="hidden" name="token" value="<?php echo esc_attr( $token ); ?>">
				<?php wp_nonce_field( self::GROUP_CONFIRM_NONCE ); ?>

				<p>
					<label for="essf_group_base"><?php esc_html_e( 'Base description', 'essfinance' ); ?></label><br>
					<input type="text" id="essf_group_base" name="base_description" class="regular-text" required
						placeholder="<?php esc_attr_e( 'e.g. Lucia 202608', 'essfinance' ); ?>">
				</p>

				<table class="widefat striped" style="max-width:700px;">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Date', 'essfinance' ); ?></th>
							<th><?php esc_html_e( 'Amount', 'essfinance' ); ?></th>
							<th><?php esc_html_e( 'Current description', 'essfinance' ); ?></th>
							<th><?php esc_html_e( 'New description', 'essfinance' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php
						$i = 1;
						foreach ( $entries as $entry ) :
							?>
							<tr>
								<td><?php echo esc_html( date_i18n( get_option( 'date_format' ), strtotime( substr( $entry->post_date_gmt, 0, 10 ) ) ) ); ?></td>
								<td><?php echo esc_html( ESSF_Settings::format_amount( (float) $entry->post_content ) ); ?></td>
								<td><?php echo esc_html( $entry->post_title ); ?></td>
								<td><em class="essf-group-preview" data-index="<?php echo esc_attr( (string) $i ); ?>" data-n="<?php echo esc_attr( (string) $n ); ?>">&mdash;</em></td>
							</tr>
							<?php
							++$i;
						endforeach;
						?>
					</tbody>
				</table>

				<p class="submit">
					<button type="submit" class="button button-primary"><?php esc_html_e( 'Confirm', 'essfinance' ); ?></button>
				</p>
			</form>
		</div>
		<script>
		( function () {
			var base = document.getElementById( 'essf_group_base' );
			var rows = document.querySelectorAll( '.essf-group-preview' );
			function update() {
				rows.forEach( function ( el ) {
					el.textContent = base.value ? base.value + ' ' + el.dataset.index + '/' + el.dataset.n : '—';
				} );
			}
			base.addEventListener( 'input', update );
		} )();
		</script>
		<?php
	}

	/**
	 * Confirmation screen for "Split into installments" — pick which
	 * Financing plan the single selected lump-sum entry belongs to, and how
	 * many of that plan's installments it represents.
	 *
	 * @param string $token  Full staging token (see stage_split_installment()).
	 * @param array  $staged Transient payload: user_id, id.
	 */
	private function render_split_review_page( string $token, array $staged ): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized.', 'essfinance' ) );
		}
		$post = get_post( $staged['id'] ?? 0 );
		if ( ! $post ) {
			wp_die( esc_html__( 'Entry not found.', 'essfinance' ) );
		}
		$terms = get_terms(
			[
				'taxonomy'   => ESSF_Financing_CPT::TAXONOMY,
				'hide_empty' => false,
			]
		); // phpcs:ignore WordPress.Arrays.MultipleStatementAlignment.DoubleArrowNotAligned
		?>
		<div class="wrap">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Split into Installments', 'essfinance' ); ?></h1>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=essfinance' ) ); ?>" class="page-title-action">&larr; <?php esc_html_e( 'Cash Flow', 'essfinance' ); ?></a>
			<hr class="wp-header-end">

			<p>
				<strong><?php echo esc_html( $post->post_title ); ?></strong>
				&mdash; <?php echo esc_html( ESSF_Settings::format_amount( (float) $post->post_content ) ); ?>
				&mdash; <?php echo esc_html( date_i18n( get_option( 'date_format' ), strtotime( substr( $post->post_date_gmt, 0, 10 ) ) ) ); ?>
			</p>

			<?php if ( is_wp_error( $terms ) || ! $terms ) : ?>
				<p><?php esc_html_e( 'No Financing plans exist yet — create one first.', 'essfinance' ); ?></p>
			<?php else : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="essf_split_confirm">
					<input type="hidden" name="token" value="<?php echo esc_attr( $token ); ?>">
					<?php wp_nonce_field( self::SPLIT_CONFIRM_NONCE ); ?>

					<p>
						<label for="essf_split_plan"><?php esc_html_e( 'Financing plan', 'essfinance' ); ?></label><br>
						<select id="essf_split_plan" name="plan_term_id" required>
							<option value=""><?php esc_html_e( '— choose —', 'essfinance' ); ?></option>
							<?php foreach ( $terms as $term ) : ?>
								<option value="<?php echo esc_attr( (string) $term->term_id ); ?>"><?php echo esc_html( $term->name ); ?></option>
							<?php endforeach; ?>
						</select>
					</p>
					<p>
						<label for="essf_split_count"><?php esc_html_e( 'How many installments does this entry represent?', 'essfinance' ); ?></label><br>
						<input type="number" id="essf_split_count" name="split_count" min="2" max="60" value="2" required>
					</p>

					<p class="submit">
						<button type="submit" class="button button-primary"><?php esc_html_e( 'Split', 'essfinance' ); ?></button>
					</p>
				</form>
			<?php endif; ?>
		</div>
		<?php
	}

	private function render_edit_page( WP_Post $entry, string $msg ): void {
		$back_url = admin_url( 'admin.php?page=essfinance' );
		$focus    = isset( $_GET['essf_focus'] ) ? sanitize_key( $_GET['essf_focus'] ) : '';
		?>
		<div class="wrap">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Edit Entry', 'essfinance' ); ?></h1>
			<a href="<?php echo esc_url( $back_url ); ?>" class="page-title-action">
				&larr; <?php esc_html_e( 'Cash Flow', 'essfinance' ); ?>
			</a>
			<hr class="wp-header-end">

			<?php $this->render_notices( $msg ); ?>

			<div style="max-width:480px; margin-top:16px;">
				<?php $this->render_form( $entry, false, $focus ); ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Review-and-confirm page for a staged OFX import. Presented as a modal
	 * `<dialog>` backed by a real page (the staged rows live in the
	 * transient, not JS state, so a reload doesn't lose them).
	 *
	 * @param string $token  The `essf_review` token.
	 * @param array  $staged Transient payload: user_id, skipped, rows.
	 */
	private function render_review_page( string $token, array $staged ): void {
		$back_url    = admin_url( 'admin.php?page=essfinance' );
		$history     = $this->ofx_memo_history();
		$cat_history = $this->ofx_category_history();
		$cat_terms   = ESSF_Category::get_ordered_terms();

		// The From/To pickers are only worth showing (and only get a real
		// min/max) when the batch actually spans more than one date — a
		// single-day statement has nothing to filter. Bounding the native
		// date input to the batch's own range also means a single-month
		// statement opens its picker already scoped to that month, without
		// needing a separate day-only widget.
		$staged_dates = array_filter( array_column( $staged['rows'], 'due_date' ) );
		$date_min     = $staged_dates ? min( $staged_dates ) : '';
		$date_max     = $staged_dates ? max( $staged_dates ) : '';
		$show_filter  = $date_min && $date_max && $date_min !== $date_max;
		?>
		<div class="wrap">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Review OFX Import', 'essfinance' ); ?></h1>
			<a href="<?php echo esc_url( $back_url ); ?>" class="page-title-action">
				&larr; <?php esc_html_e( 'Cash Flow', 'essfinance' ); ?>
			</a>
			<hr class="wp-header-end">

			<?php if ( ! empty( $staged['skipped'] ) ) : ?>
				<div class="notice notice-info">
					<p>
						<?php
						/* translators: %d: number of duplicate transactions skipped automatically. */
						$dupe_notice = _n(
							'%d duplicate transaction was skipped automatically.',
							'%d duplicate transactions were skipped automatically.',
							(int) $staged['skipped'],
							'essfinance'
						);
						printf( esc_html( $dupe_notice ), absint( $staged['skipped'] ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $dupe_notice is pre-escaped via esc_html()
						?>
					</p>
				</div>
			<?php endif; ?>

			<?php
			/* translators: %s is replaced client-side (assets/js/admin.js) with the description being offered, not by PHP. */
			$apply_label = __( 'Use "%s"', 'essfinance' );
			?>
			<dialog id="essf-ofx-review-modal" class="essf-ofx-review-modal" data-token="<?php echo esc_attr( $token ); ?>" data-apply-label="<?php echo esc_attr( $apply_label ); ?>" open>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="essf_import_ofx_confirm">
					<input type="hidden" name="essf_review_token" value="<?php echo esc_attr( $token ); ?>">
					<?php wp_nonce_field( self::IMPORT_OFX_CONFIRM_NONCE ); ?>

					<h2><?php esc_html_e( 'Confirm entries to import', 'essfinance' ); ?></h2>
					<p class="description">
						<?php esc_html_e( 'Hover a description to see the raw bank memo, or click it to edit before these transactions are saved. Click the ✕ to exclude a row (e.g. balances, internal transfers) — click again to bring it back.', 'essfinance' ); ?>
					</p>

					<?php if ( $show_filter ) : ?>
						<div class="essf-ofx-review-filter">
							<label for="essf-ofx-filter-from"><?php esc_html_e( 'From', 'essfinance' ); ?></label>
							<input type="date" id="essf-ofx-filter-from" class="essf-ofx-filter-input" data-filter="from"
								min="<?php echo esc_attr( $date_min ); ?>" max="<?php echo esc_attr( $date_max ); ?>">
							<label for="essf-ofx-filter-to"><?php esc_html_e( 'To', 'essfinance' ); ?></label>
							<input type="date" id="essf-ofx-filter-to" class="essf-ofx-filter-input" data-filter="to"
								min="<?php echo esc_attr( $date_min ); ?>" max="<?php echo esc_attr( $date_max ); ?>">
							<span class="essf-ofx-filter-count" aria-live="polite"></span>
						</div>
					<?php endif; ?>

					<div class="essf-ofx-review-table-wrap">
						<table class="essf-ofx-review-table">
							<thead>
								<tr>
									<th class="check-column"></th>
									<th><?php esc_html_e( 'Date', 'essfinance' ); ?></th>
									<th><?php esc_html_e( 'Amount', 'essfinance' ); ?></th>
									<th><?php esc_html_e( 'Description', 'essfinance' ); ?></th>
									<th><?php esc_html_e( 'Category', 'essfinance' ); ?></th>
								</tr>
							</thead>
							<tbody>
								<?php foreach ( $staged['rows'] as $i => $row ) : ?>
									<?php
									$raw_memo        = $row['memo'] ?: $row['name'];
									$suggestions     = ESSF_OFX_Suggestions::suggest( $raw_memo, $history );
									$cat_suggestions = ESSF_OFX_Suggestions::suggest( $raw_memo, $cat_history );
									// $row['category'] is guess_slug_from_description()'s canonical
									// key (e.g. 'transfers'), not necessarily a live slug — normalize
									// it to that category's *current* live slug, so the selected()
									// comparisons below against $term->slug work.
									$default_category_slug = ESSF_Category::normalize_slug( $row['category'] );
									if ( $cat_suggestions ) {
										foreach ( $cat_terms as $cat_term ) {
											if ( $cat_term->name === $cat_suggestions[0]['title'] ) {
												$default_category_slug = $cat_term->slug;
												break;
											}
										}
									}
									$is_dupe    = ! empty( $row['possible_duplicate'] );
									$is_learned = ! empty( $row['suggested_exclude'] );
									$is_income  = $row['amount'] > 0;

									if ( $is_dupe ) {
										$dupe_title = $row['possible_duplicate']['title'];
										$has_dupe   = false;
										foreach ( $suggestions as $s ) {
											if ( $s['title'] === $dupe_title ) {
												$has_dupe = true;
												break;
											}
										}
										if ( ! $has_dupe ) {
											array_unshift(
												$suggestions,
												[
													'title' => $dupe_title,
													'score' => 100.0,
												]
											);
										}
									}
									$default        = $suggestions[0]['title'] ?? $row['description'];
									$has_suggestion = ! empty( $suggestions );

									$datalist_id  = 'essf-ofx-suggestions-' . $i;
									$excluded_row = $is_dupe || $is_learned;
									?>
									<tr class="<?php echo $excluded_row ? 'is-excluded' : ''; ?>" data-row="<?php echo esc_attr( (string) $i ); ?>" data-date="<?php echo esc_attr( $row['due_date'] ); ?>">
										<td class="essf-ofx-row-action">
											<input type="hidden" class="essf-ofx-include-input" name="essf_rows[<?php echo esc_attr( (string) $i ); ?>][include]" value="<?php echo $excluded_row ? '0' : '1'; ?>">
											<button type="button" class="essf-ofx-row-toggle"
												aria-label="<?php echo $excluded_row ? esc_attr__( 'Include this transaction', 'essfinance' ) : esc_attr__( 'Exclude this transaction', 'essfinance' ); ?>"
												data-include-label="<?php esc_attr_e( 'Include this transaction', 'essfinance' ); ?>"
												data-exclude-label="<?php esc_attr_e( 'Exclude this transaction', 'essfinance' ); ?>">
												<?php echo $excluded_row ? '↺' : '✕'; ?>
											</button>
										</td>
										<td><?php echo esc_html( $row['due_date'] ); ?></td>
										<td>
											<span class="essf-amount--<?php echo $is_income ? 'income' : 'expense'; ?>">
												<?php echo esc_html( ESSF_Settings::format_amount( $row['amount'] ) ); ?>
											</span>
										</td>
										<td class="essf-ofx-review-desc">
											<input type="hidden" class="essf-ofx-desc-value"
												name="essf_rows[<?php echo esc_attr( (string) $i ); ?>][description]"
												value="<?php echo esc_attr( $default ); ?>">

											<button type="button" class="essf-ofx-desc-display" title="<?php echo esc_attr( $raw_memo ); ?>">
												<span class="essf-ofx-desc-text"><?php echo esc_html( $default ); ?></span>
												<?php if ( $has_suggestion ) : ?>
													<span class="essf-ofx-desc-indicator essf-ofx-desc-indicator--match" title="<?php esc_attr_e( 'Suggested from a previous import — click to change', 'essfinance' ); ?>">✓</span>
												<?php else : ?>
													<span class="essf-ofx-desc-indicator essf-ofx-desc-indicator--needs-input" title="<?php esc_attr_e( 'No match found — please review this description', 'essfinance' ); ?>">✎</span>
												<?php endif; ?>
											</button>

											<?php if ( $is_dupe ) : ?>
												<p class="essf-ofx-review-warning">
													<?php
													printf(
														/* translators: 1: existing entry title, 2: existing entry date. */
														esc_html__( 'Possibly matches "%1$s" on %2$s.', 'essfinance' ),
														esc_html( $row['possible_duplicate']['title'] ),
														esc_html( $row['possible_duplicate']['date'] )
													);
													?>
												</p>
											<?php elseif ( $is_learned ) : ?>
												<p class="essf-ofx-review-warning">
													<?php esc_html_e( 'Looks like a transaction you\'ve excluded before.', 'essfinance' ); ?>
												</p>
											<?php endif; ?>

											<span class="essf-ofx-desc-edit" hidden>
												<input type="text" class="essf-ofx-desc-input" list="<?php echo esc_attr( $datalist_id ); ?>" value="<?php echo esc_attr( $default ); ?>">
												<datalist id="<?php echo esc_attr( $datalist_id ); ?>">
													<?php foreach ( $suggestions as $s ) : ?>
														<option value="<?php echo esc_attr( $s['title'] ); ?>"></option>
													<?php endforeach; ?>
												</datalist>
												<button type="button" class="essf-ofx-desc-confirm" aria-label="<?php esc_attr_e( 'Confirm description', 'essfinance' ); ?>">✓</button>
												<button type="button" class="essf-ofx-desc-cancel" aria-label="<?php esc_attr_e( 'Cancel edit', 'essfinance' ); ?>">✕</button>
											</span>
										</td>
										<td class="essf-ofx-review-cat">
											<select class="essf-ofx-category-select" name="essf_rows[<?php echo esc_attr( (string) $i ); ?>][category]">
												<?php foreach ( $cat_terms as $term ) : ?>
													<option value="<?php echo esc_attr( $term->slug ); ?>" <?php selected( $default_category_slug, $term->slug ); ?>>
														<?php echo esc_html( $term->name ); ?>
													</option>
												<?php endforeach; ?>
											</select>
										</td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					</div>

					<p class="submit">
						<input type="submit" class="button button-primary" value="<?php esc_attr_e( 'Finish', 'essfinance' ); ?>">
						<a href="<?php echo esc_url( $back_url ); ?>" class="button button-secondary"><?php esc_html_e( 'Cancel', 'essfinance' ); ?></a>
					</p>
				</form>
			</dialog>
		</div>
		<?php
	}

	public function render_add_page(): void {
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Add Entry', 'essfinance' ); ?></h1>
			<hr class="wp-header-end">
			<div style="max-width:480px; margin-top:16px;">
				<?php $this->render_form( null ); ?>
			</div>
		</div>
		<?php
	}

	/* ── Import form ───────────────────────────────────── */

	private function render_import_form(): void {
		?>
		<h2><?php esc_html_e( 'Import', 'essfinance' ); ?></h2>
		<div class="form-wrap">
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data">
				<input type="hidden" name="action" value="essf_import">
				<?php wp_nonce_field( 'essf_import' ); ?>
				<div class="form-field form-required">
					<label for="essf_csv_file"><?php esc_html_e( 'File', 'essfinance' ); ?></label>
					<input type="file" id="essf_csv_file" name="essf_csv_file" accept=".csv,.ofx,.qfx">
					<p class="description"><?php esc_html_e( 'Supports CSV (EssFinance export) and OFX. Duplicates are skipped automatically. OFX files open a review step before anything is saved.', 'essfinance' ); ?></p>
				</div>
				<p class="submit">
					<input type="submit" class="button button-primary" value="<?php esc_attr_e( 'Import', 'essfinance' ); ?>">
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=essfinance' ) ); ?>" class="button button-secondary"><?php esc_html_e( 'Cancel', 'essfinance' ); ?></a>
				</p>
			</form>
		</div>
		<?php
	}

	/* ── Shared form ────────────────────────────────────── */

	private function render_form( ?WP_Post $entry = null, bool $show_heading = true, string $focus = '' ): void {
		$is_edit = null !== $entry;

		$description = $due_date = $pay_date = $status = '';
		$amount      = '';
		$is_income   = false;
		// A new entry defaults to the Auto-categorize sentinel rather than a
		// real term, so it doesn't need normalize_slug() below.
		$category = ESSF_Category::AUTO_SLUG;

		if ( $is_edit ) {
			$amount_val  = (float) $entry->post_content;
			$is_income   = $amount_val > 0;
			$description = $entry->post_title;
			$amount      = (string) abs( $amount_val );
			$status      = $entry->post_status;

			$due_raw  = substr( $entry->post_date_gmt, 0, 10 );
			$pay_raw  = substr( $entry->post_modified_gmt, 0, 10 );
			$due_date = ( $due_raw && '0000-00-00' !== $due_raw ) ? $due_raw : '';
			$pay_date = ( $pay_raw && '0000-00-00' !== $pay_raw ) ? $pay_raw : '';

			$entry_terms = wp_get_post_terms( $entry->ID, ESSF_Category::TAXONOMY, [ 'fields' => 'slugs' ] );
			$category    = $entry_terms[0] ?? $category; // every entry has exactly one category, so this always resolves
		}

		if ( 'pay_date' === $focus ) {
			$pay_date = gmdate( 'Y-m-d' );
			$status   = 'paid';
		}

		$form_action  = $is_edit ? 'essf_update' : 'essf_add';
		$nonce_action = $is_edit ? self::UPDATE_NONCE : self::ADD_NONCE;
		?>
		<?php if ( $show_heading ) : ?>
		<h2><?php echo $is_edit ? esc_html__( 'Edit Entry', 'essfinance' ) : esc_html__( 'Add Entry', 'essfinance' ); ?></h2>
		<?php endif; ?>

		<div class="form-wrap">
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="<?php echo esc_attr( $form_action ); ?>">
				<?php if ( $is_edit ) : ?>
					<input type="hidden" name="entry_id" value="<?php echo esc_attr( (string) $entry->ID ); ?>">
				<?php endif; ?>
				<?php wp_nonce_field( $nonce_action ); ?>

				<div class="form-field form-required">
					<label for="essf_description"><?php esc_html_e( 'Description', 'essfinance' ); ?></label>
					<input type="text" id="essf_description" name="essf_description"
						value="<?php echo esc_attr( $description ); ?>" class="widefat">
					<p class="description"><?php esc_html_e( 'A short label for this entry.', 'essfinance' ); ?></p>
				</div>

				<div class="form-field">
					<label for="essf_due_date"><?php esc_html_e( 'Due Date', 'essfinance' ); ?></label>
					<input type="date" id="essf_due_date" name="essf_due_date"
						value="<?php echo esc_attr( $due_date ); ?>">
				</div>

				<div class="form-field">
					<label for="essf_pay_date"><?php esc_html_e( 'Pay Date', 'essfinance' ); ?></label>
					<input type="date" id="essf_pay_date" name="essf_pay_date"
						value="<?php echo esc_attr( $pay_date ); ?>">
				</div>

				<div class="form-field essf-field-row--amount">
					<div class="essf-field--amount-wrap">
						<label for="essf_amount"><?php esc_html_e( 'Amount', 'essfinance' ); ?></label>
						<input type="number" id="essf_amount" name="essf_amount"
							value="<?php echo esc_attr( $amount ); ?>"
							step="0.01" min="0" placeholder="0.00" class="widefat">
					</div>
					<label class="essf-income-label essf-income-label--form">
						<input type="checkbox" name="essf_is_income" value="1" <?php checked( $is_income ); ?>>
						<?php esc_html_e( 'Income', 'essfinance' ); ?>
					</label>
				</div>

				<div class="form-field">
					<label for="essf_status"><?php esc_html_e( 'Status', 'essfinance' ); ?></label>
					<select id="essf_status" name="essf_status">
						<?php foreach ( ESSF_CPT::labels() as $val => $label ) : ?>
							<option value="<?php echo esc_attr( $val ); ?>" <?php selected( $status, $val ); ?>>
								<?php echo esc_html( $label ); ?>
							</option>
						<?php endforeach; ?>
					</select>
				</div>

				<div class="form-field">
					<label for="essf_category"><?php esc_html_e( 'Category', 'essfinance' ); ?></label>
					<select id="essf_category" name="essf_category">
						<option value="<?php echo esc_attr( ESSF_Category::AUTO_SLUG ); ?>" <?php selected( $category, ESSF_Category::AUTO_SLUG ); ?>>
							<?php esc_html_e( 'Auto-categorize', 'essfinance' ); ?>
						</option>
						<?php foreach ( ESSF_Category::get_ordered_terms() as $term ) : ?>
							<option value="<?php echo esc_attr( $term->slug ); ?>" <?php selected( $category, $term->slug ); ?>>
								<?php echo esc_html( $term->name ); ?>
							</option>
						<?php endforeach; ?>
					</select>
				</div>

				<?php $this->render_link_loan_field(); ?>

				<p class="submit">
					<?php if ( $is_edit ) : ?>
						<input type="submit" class="button button-primary" value="<?php esc_attr_e( 'Update Entry', 'essfinance' ); ?>">
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=essfinance' ) ); ?>" class="button button-secondary"><?php esc_html_e( 'Cancel', 'essfinance' ); ?></a>
					<?php else : ?>
						<input type="submit" class="button button-primary" value="<?php esc_attr_e( 'Add Entry', 'essfinance' ); ?>">
					<?php endif; ?>
				</p>
			</form>
		</div>
		<?php if ( $focus && $is_edit ) :
			$focus_map = [ 'pay_date' => 'essf_pay_date' ];
			$field_id  = $focus_map[ $focus ] ?? '';
			if ( $field_id ) : ?>
			<script>
			document.getElementById( '<?php echo esc_js( $field_id ); ?>' ).focus();
			</script>
		<?php endif; endif; ?>
		<?php
	}

	/**
	 * Optional "Link to loan" picker — choosing one just fills the
	 * Description (and Amount, if empty) with the loan's exact term name,
	 * so the entry matches it via ESSF_CPT::find_entries_by_title() once
	 * saved. No extra field is submitted server-side; this is purely a
	 * client-side autofill convenience. Options client-side re-sort toward
	 * whatever the operator is currently typing in Description/Amount, so
	 * the most likely match floats to the top without a round trip.
	 */
	private function render_link_loan_field(): void {
		$unsettled = ESSF_Loan_CPT::get_unsettled();
		if ( ! $unsettled ) {
			return;
		}
		?>
		<div class="form-field">
			<label for="essf_link_loan"><?php esc_html_e( 'Link to loan (optional)', 'essfinance' ); ?></label>
			<select id="essf_link_loan">
				<option value=""><?php esc_html_e( '— none —', 'essfinance' ); ?></option>
				<?php foreach ( $unsettled as $row ) : ?>
					<option value="<?php echo esc_attr( $row['name'] ); ?>" data-amount="<?php echo esc_attr( (string) $row['remaining'] ); ?>">
						<?php echo esc_html( $row['name'] . ' — ' . ESSF_Settings::format_amount( $row['remaining'] ) . ' ' . __( 'remaining', 'essfinance' ) ); ?>
					</option>
				<?php endforeach; ?>
			</select>
			<p class="description"><?php esc_html_e( 'Picking a loan fills in the Description (and Amount, if empty) so this entry links to it.', 'essfinance' ); ?></p>
		</div>
		<script>
		( function () {
			var select = document.getElementById( 'essf_link_loan' );
			var desc   = document.getElementById( 'essf_description' );
			var amt    = document.getElementById( 'essf_amount' );
			if ( ! select || ! desc || ! amt ) {
				return;
			}

			var options = Array.prototype.slice.call( select.options, 1 );

			function resort() {
				var q = desc.value.toLowerCase();
				var a = parseFloat( amt.value ) || 0;
				options
					.map( function ( opt ) {
						var remaining = parseFloat( opt.dataset.amount ) || 0;
						var score = 0;
						if ( q && opt.value.toLowerCase().indexOf( q ) !== -1 ) {
							score += 100;
						}
						if ( a > 0 ) {
							score -= Math.abs( remaining - a );
						}
						return { opt: opt, score: score };
					} )
					.sort( function ( x, y ) { return y.score - x.score; } )
					.forEach( function ( s ) { select.appendChild( s.opt ); } );
			}
			desc.addEventListener( 'input', resort );
			amt.addEventListener( 'input', resort );

			select.addEventListener( 'change', function () {
				if ( ! select.value ) {
					return;
				}
				desc.value = select.value;
				if ( ! amt.value || 0 === parseFloat( amt.value ) ) {
					amt.value = select.selectedOptions[0].dataset.amount;
				}
			} );
		} )();
		</script>
		<?php
	}

	private function render_notices( string $msg ): void {
		if ( 'imported' === $msg ) {
			$n = absint( $_GET['essf_imported'] ?? 0 );
			$s = absint( $_GET['essf_skipped'] ?? 0 );
			// translators: 1: number of entries, 2: singular/plural "entry/entries", 3: number of skipped duplicates.
			$import_notice = esc_html( sprintf( __( '%1$d %2$s imported, %3$d skipped as duplicates.', 'essfinance' ), $n, _n( 'entry', 'entries', $n, 'essfinance' ), $s ) );
			printf( '<div class="notice notice-success is-dismissible"><p>%s</p></div>', $import_notice ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $import_notice is pre-escaped via esc_html()
			return;
		}

		if ( 'plans_detected' === $msg ) {
			$loan      = absint( $_GET['essf_detected_loan'] ?? 0 );
			$financing = absint( $_GET['essf_detected_financing'] ?? 0 );
			$bill      = absint( $_GET['essf_detected_bill'] ?? 0 );
			if ( 0 === $loan + $financing + $bill ) {
				printf( '<div class="notice notice-info is-dismissible"><p>%s</p></div>', esc_html__( 'No new plans detected — everything already has a matching Loan/Financing/Bill.', 'essfinance' ) );
				return;
			}
			// translators: 1: number of loans, 2: number of financing plans, 3: number of bills.
			$detect_notice = esc_html( sprintf( __( '%1$d loan(s), %2$d financing plan(s), and %3$d bill(s) created.', 'essfinance' ), $loan, $financing, $bill ) );
			printf( '<div class="notice notice-success is-dismissible"><p>%s</p></div>', $detect_notice ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $detect_notice is pre-escaped via esc_html()
			return;
		}

		$map = [
			'added'             => [ 'success', __( 'Entry added.', 'essfinance' ) ],
			'updated'           => [ 'success', __( 'Entry updated.', 'essfinance' ) ],
			'deleted'           => [ 'success', __( 'Entry deleted.', 'essfinance' ) ],
			'paid_today'        => [ 'success', __( 'Entry marked as paid today.', 'essfinance' ) ],
			'toggled'           => [ 'success', __( 'Entry type changed.', 'essfinance' ) ],
			'mark_paid'         => [ 'success', __( 'Selected entries marked as paid today.', 'essfinance' ) ],
			'mark_pending'      => [ 'success', __( 'Selected entries marked as pending.', 'essfinance' ) ],
			'make_income'       => [ 'success', __( 'Selected entries changed to income.', 'essfinance' ) ],
			'make_expense'      => [ 'success', __( 'Selected entries changed to expense.', 'essfinance' ) ],
			'set_category'      => [ 'success', __( 'Category updated for selected entries.', 'essfinance' ) ],
			'auto_set_category' => [ 'success', __( 'Category auto-detected for selected entries.', 'essfinance' ) ],
			'grouped'           => [ 'success', __( 'Entries renamed and grouped into a Loan.', 'essfinance' ) ],
			'split'             => [ 'success', __( 'Entry split into installments.', 'essfinance' ) ],
			'error'             => [ 'error', __( 'Something went wrong. Please try again.', 'essfinance' ) ],
			'import_error'      => [ 'error', __( 'Import failed. Please upload a valid CSV file.', 'essfinance' ) ],
			'group_error'       => [ 'error', __( 'Select at least 2 entries to group as a Loan.', 'essfinance' ) ],
			'split_error'       => [ 'error', __( 'Select exactly 1 entry, and make sure the chosen Financing plan has enough remaining installments.', 'essfinance' ) ],
		];
		if ( isset( $map[ $msg ] ) ) {
			[ $type, $text ] = $map[ $msg ];
			printf( '<div class="notice notice-%s is-dismissible"><p>%s</p></div>', esc_attr( $type ), esc_html( $text ) );
		}
	}

	/* ── WP List Table columns (edit.php fallback screen) ── */

	public function columns( $cols ): array {
		return [
			'cb'            => $cols['cb'],
			'title'         => __( 'Title', 'essfinance' ),
			'essf_type'     => __( 'Type', 'essfinance' ),
			'essf_category' => __( 'Category', 'essfinance' ),
			'essf_amount'   => __( 'Amount', 'essfinance' ),
			'essf_due'      => __( 'Due Date', 'essfinance' ),
			'essf_status'   => __( 'Status', 'essfinance' ),
		];
	}

	public function column_content( $col, $post_id ): void {
		$post   = get_post( $post_id );
		$amount = (float) $post->post_content;
		$is_inc = $amount > 0;

		switch ( $col ) {
			case 'essf_type':
				echo '<span class="essf-badge essf-badge--' . ( $is_inc ? 'income' : 'expense' ) . '">' . esc_html( $is_inc ? 'Income' : 'Expense' ) . '</span>';
				break;
			case 'essf_amount':
				$sign      = $is_inc ? '+' : ( ESSF_Settings::show_negative_prefix() ? '−' : '' );
				$formatted = esc_html( $sign . ESSF_Settings::format_amount( $amount ) );
				if ( ESSF_Settings::show_amount_colors() ) {
					// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $formatted is already esc_html(); span class built from boolean
					echo '<span class="essf-amount--' . ( $is_inc ? 'income' : 'expense' ) . '">' . $formatted . '</span>';
				} else {
					echo $formatted; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $formatted is already esc_html()
				}
				break;
			case 'essf_category':
				$terms = wp_get_post_terms( $post_id, ESSF_Category::TAXONOMY, [ 'fields' => 'names' ] );
				echo esc_html( $terms[0] ?? __( 'Uncategorized', 'essfinance' ) );
				break;
			case 'essf_due':
				$d = substr( $post->post_date_gmt, 0, 10 );
				echo ( $d && '0000-00-00' !== $d ) ? esc_html( (string) date_i18n( get_option( 'date_format' ), strtotime( $d ) ) ) : '—';
				break;
			case 'essf_status':
				$s = $post->post_status;
				echo $s ? '<span class="essf-badge essf-badge--' . esc_attr( $s ) . '">' . esc_html( ESSF_CPT::status_label( $s ) ) . '</span>' : '—';
				break;
		}
	}

	public function sortable_columns( $cols ): array {
		$cols['essf_amount'] = 'essf_amount';
		$cols['essf_due']    = 'essf_due';
		return $cols;
	}
}
