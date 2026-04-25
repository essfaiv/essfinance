<?php
defined( 'ABSPATH' ) || exit;

class ESSF_Admin_Page {

	const ADD_NONCE    = 'essf_add';
	const UPDATE_NONCE = 'essf_update';
	const DELETE_NONCE = 'essf_delete';

	public function __construct() {
		add_action( 'admin_menu', [ $this, 'register_menus' ] );
		add_action( 'current_screen', [ $this, 'register_screen_options' ] );
		add_filter( 'set_screen_option_essf_entries_per_page', fn( $s, $o, $v ) => absint( $v ), 10, 3 );
		add_action( 'admin_init', [ $this, 'process_bulk_delete' ] );
		add_action( 'admin_post_essf_add', [ $this, 'handle_add' ] );
		add_action( 'admin_post_essf_update', [ $this, 'handle_update' ] );
		add_action( 'admin_post_essf_delete', [ $this, 'handle_delete' ] );
		add_action( 'admin_post_essf_export', [ $this, 'handle_export' ] );
		add_action( 'admin_post_essf_import', [ $this, 'handle_import' ] );
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

	}

	public function register_screen_options( WP_Screen $screen ): void {
		if ( 'toplevel_page_essfinance' !== $screen->id ) {
			return;
		}
		add_screen_option( 'per_page', [
			'label'   => __( 'Entries per page', 'essfinance' ),
			'default' => 20,
			'option'  => 'essf_entries_per_page',
		] );
		register_column_headers( $screen, ( new ESSF_List_Table() )->get_columns() );
		add_filter( 'default_hidden_columns', function( array $hidden, WP_Screen $s ): array {
			if ( 'toplevel_page_essfinance' === $s->id ) {
				$hidden[] = 'essf_type';
			}
			return $hidden;
		}, 10, 2 );
	}

	/* ── Bulk delete ────────────────────────────────────── */

	public function process_bulk_delete(): void {
		if ( ! isset( $_REQUEST['page'] ) || 'essfinance' !== $_REQUEST['page'] ) {
			return;
		}
		$action = isset( $_REQUEST['action'] ) && '-1' !== $_REQUEST['action']
			? sanitize_key( $_REQUEST['action'] )
			: ( isset( $_REQUEST['action2'] ) && '-1' !== $_REQUEST['action2'] ? sanitize_key( $_REQUEST['action2'] ) : '' );

		if ( 'delete' !== $action ) {
			return;
		}

		check_admin_referer( 'bulk-entries' );
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$ids = isset( $_REQUEST['entries'] ) ? array_map( 'absint', (array) $_REQUEST['entries'] ) : [];
		foreach ( $ids as $id ) {
			$post = get_post( $id );
			if ( $post && 'essf_cashflow' === $post->post_type ) {
				wp_delete_post( $id, true );
			}
		}

		wp_safe_redirect( add_query_arg( 'essf_msg', 'deleted', admin_url( 'admin.php?page=essfinance' ) ) );
		exit;
	}

	/* ── Single entry handlers ──────────────────────────── */

	public function handle_add(): void {
		check_admin_referer( self::ADD_NONCE );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized.', 'essfinance' ) );
		}

		$data = $this->parse_form_data( $_POST );

		$post_id = wp_insert_post( [
			'post_type'    => 'essf_cashflow',
			'post_title'   => $data['title'],
			'post_content' => $data['amount'],
			'post_status'  => $data['status'],
		], true );

		if ( is_wp_error( $post_id ) ) {
			wp_safe_redirect( add_query_arg( 'essf_msg', 'error', admin_url( 'admin.php?page=essfinance' ) ) );
			exit;
		}

		// wp_insert_post does not reliably persist post_date_gmt / post_modified_gmt,
		// so we write them directly — same approach as the update flow.
		global $wpdb;
		$wpdb->update(
			$wpdb->posts,
			[
				'post_date_gmt'     => $data['due_gmt'],
				'post_modified_gmt' => $data['pay_gmt'],
			],
			[ 'ID' => $post_id ],
			[ '%s', '%s' ],
			[ '%d' ]
		);

		update_post_meta( $post_id, '_order_date', $data['order_date'] );

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

		wp_safe_redirect( add_query_arg( 'essf_msg', 'updated', admin_url( 'admin.php?page=essfinance' ) ) );
		exit;
	}

	public function handle_export(): void {
		check_admin_referer( 'essf_export' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized.', 'essfinance' ) );
		}

		$posts = get_posts( [
			'post_type'      => 'essf_cashflow',
			'post_status'    => [ 'pending', 'paid' ],
			'posts_per_page' => -1,
			'orderby'        => 'meta_value',
			'meta_key'       => '_order_date',
			'order'          => 'ASC',
		] );

		$filename = 'essfinance-export-' . gmdate( 'Y-m-d-His' ) . '.csv';

		header( 'Content-Type: text/csv; charset=UTF-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		header( 'Pragma: no-cache' );

		$out = fopen( 'php://output', 'w' );
		fprintf( $out, "\xEF\xBB\xBF" ); // UTF-8 BOM — ensures Excel opens accented chars correctly
		fputcsv( $out, [ 'Description', 'Due Date', 'Pay Date', 'Status', 'Amount' ] );

		foreach ( $posts as $post ) {
			$amount  = (float) $post->post_content;
			$due     = substr( $post->post_date_gmt, 0, 10 );
			$pay     = substr( $post->post_modified_gmt, 0, 10 );
			fputcsv( $out, [
				$post->post_title,
				( $due && '0000-00-00' !== $due ) ? $due : '',
				( $pay && '0000-00-00' !== $pay ) ? $pay : '',
				$post->post_status,
				self::format_amount_csv( $amount ),
			] );
		}

		fclose( $out );
		exit;
	}

	public function handle_import(): void {
		check_admin_referer( 'essf_import' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized.', 'essfinance' ) );
		}

		$file = $_FILES['essf_csv_file'] ?? null;
		if ( ! $file || UPLOAD_ERR_OK !== (int) $file['error'] || ! is_uploaded_file( $file['tmp_name'] ) ) {
			wp_safe_redirect( add_query_arg( 'essf_msg', 'import_error', admin_url( 'admin.php?page=essfinance' ) ) );
			exit;
		}

		// Build dedup lookups (description+date+amount, and FITID for OFX).
		$existing     = [];
		$fitid_lookup = [];
		foreach ( get_posts( [
			'post_type'      => 'essf_cashflow',
			'post_status'    => [ 'pending', 'paid' ],
			'posts_per_page' => -1,
		] ) as $p ) {
			$due = substr( $p->post_date_gmt, 0, 10 );
			$existing[ strtolower( trim( $p->post_title ) ) . '|' . $due . '|' . round( (float) $p->post_content, 2 ) ] = true;
			$fitid = get_post_meta( $p->ID, '_essf_fitid', true );
			if ( $fitid ) {
				$fitid_lookup[ $fitid ] = true;
			}
		}

		$ext = strtolower( pathinfo( $file['name'], PATHINFO_EXTENSION ) );

		if ( in_array( $ext, [ 'ofx', 'qfx' ], true ) ) {
			$content = file_get_contents( $file['tmp_name'] );
			if ( false === $content ) {
				wp_safe_redirect( add_query_arg( 'essf_msg', 'import_error', admin_url( 'admin.php?page=essfinance' ) ) );
				exit;
			}
			$result = $this->import_ofx( $content, $existing, $fitid_lookup );
		} else {
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
		}

		wp_safe_redirect( add_query_arg( [
			'essf_msg'      => 'imported',
			'essf_imported' => $result['imported'],
			'essf_skipped'  => $result['skipped'],
		], admin_url( 'admin.php?page=essfinance' ) ) );
		exit;
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
			if ( ! array_key_exists( $status, ESSF_CPT::$statuses ) ) {
				$status = 'pending';
			}
			$due_key = $due_dt ? $due_dt->format( 'Y-m-d' ) : '';

			$key = strtolower( $description ) . '|' . $due_key . '|' . $amount;
			if ( isset( $existing[ $key ] ) ) {
				$skipped++;
				continue;
			}

			if ( $this->insert_entry( $description, $amount, $due_gmt, $pay_gmt, $status ) ) {
				$existing[ $key ] = true;
				$imported++;
			}
		}

		return compact( 'imported', 'skipped' );
	}

	private function import_ofx( string $content, array &$existing, array &$fitid_lookup ): array {
		$imported = 0;
		$skipped  = 0;

		foreach ( $this->parse_ofx_transactions( $content ) as $trn ) {
			$description = sanitize_text_field( $trn['description'] );
			$amount      = round( $trn['amount'], 2 );
			$due_key     = $trn['due_date'];
			$fitid       = $trn['fitid'];

			if ( $fitid && isset( $fitid_lookup[ $fitid ] ) ) {
				$skipped++;
				continue;
			}

			$key = strtolower( $description ) . '|' . $due_key . '|' . $amount;
			if ( isset( $existing[ $key ] ) ) {
				$skipped++;
				continue;
			}

			$due_dt  = $due_key ? DateTime::createFromFormat( 'Y-m-d', $due_key ) : false;
			$due_gmt = $due_dt ? $due_dt->format( 'Y-m-d' ) . ' 00:00:00' : '0000-00-00 00:00:00';

			$post_id = $this->insert_entry( $description, $amount, $due_gmt, '0000-00-00 00:00:00', 'pending' );
			if ( $post_id ) {
				if ( $fitid ) {
					update_post_meta( $post_id, '_essf_fitid', $fitid );
					$fitid_lookup[ $fitid ] = true;
				}
				$existing[ $key ] = true;
				$imported++;
			}
		}

		return compact( 'imported', 'skipped' );
	}

	private function parse_ofx_transactions( string $content ): array {
		$content = str_replace( [ "\r\n", "\r" ], "\n", $content );

		// Strip OFX 1.x header — everything before <OFX>.
		if ( preg_match( '/<OFX>/i', $content, $m, PREG_OFFSET_CAPTURE ) ) {
			$content = substr( $content, (int) $m[0][1] );
		}

		$extract = static function ( string $tag, string $block ): string {
			return preg_match( '/<' . $tag . '>\s*([^\s<][^<\n\r]*)/i', $block, $m ) ? trim( $m[1] ) : '';
		};

		// OFX 2.x has proper closing tags; OFX 1.x (SGML) does not.
		if ( preg_match_all( '/<STMTTRN>(.*?)<\/STMTTRN>/si', $content, $matches ) ) {
			$blocks = $matches[1];
		} else {
			// SGML: split on opening tag, discard pre-first-transaction content.
			$parts  = preg_split( '/<STMTTRN>/i', $content );
			array_shift( $parts );
			$blocks = array_map( static function ( string $p ): string {
				return preg_split( '/<\/BANKTRANLIST>|<LEDGERBAL>|<AVAILBAL>/i', $p )[0];
			}, $parts );
		}

		$transactions = [];
		foreach ( $blocks as $block ) {
			$amount_raw = $extract( 'TRNAMT', $block );
			$date_raw   = $extract( 'DTPOSTED', $block );
			if ( '' === $amount_raw || '' === $date_raw ) {
				continue;
			}

			// OFX date: YYYYMMDD[HHMMSS[.mmm][tz]] — take first 8 digits.
			$digits = substr( preg_replace( '/[^0-9]/', '', $date_raw ), 0, 8 );
			if ( strlen( $digits ) < 8 ) {
				continue;
			}
			$due_date = substr( $digits, 0, 4 ) . '-' . substr( $digits, 4, 2 ) . '-' . substr( $digits, 6, 2 );

			$name        = $extract( 'NAME', $block );
			$memo        = $extract( 'MEMO', $block );
			$description = $name ?: $memo ?: 'OFX Transaction';

			$transactions[] = [
				'fitid'       => $extract( 'FITID', $block ),
				'description' => $description,
				'amount'      => (float) str_replace( ',', '.', $amount_raw ),
				'due_date'    => $due_date,
			];
		}

		return $transactions;
	}

	private function insert_entry( string $title, float $amount, string $due_gmt, string $pay_gmt, string $status ): int {
		$post_id = wp_insert_post( [
			'post_type'    => 'essf_cashflow',
			'post_title'   => $title,
			'post_content' => (string) $amount,
			'post_status'  => $status,
		], true );

		if ( is_wp_error( $post_id ) ) {
			return 0;
		}

		global $wpdb;
		$wpdb->update(
			$wpdb->posts,
			[ 'post_date_gmt' => $due_gmt, 'post_modified_gmt' => $pay_gmt ],
			[ 'ID' => $post_id ],
			[ '%s', '%s' ],
			[ '%d' ]
		);

		$order_date = '0000-00-00 00:00:00' !== $pay_gmt
			? substr( $pay_gmt, 0, 10 )
			: ( '0000-00-00 00:00:00' !== $due_gmt ? substr( $due_gmt, 0, 10 ) : '' );
		update_post_meta( $post_id, '_order_date', $order_date );

		return $post_id;
	}

	private static function format_amount_csv( float $amount ): string {
		$prefix = $amount < 0 ? '-' : '';
		$abs    = abs( $amount );
		if ( $abs == (int) $abs ) {
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
		if ( ! array_key_exists( $status, ESSF_CPT::$statuses ) ) {
			$status = 'pending';
		}

		$order_date = '0000-00-00 00:00:00' !== $pay_gmt
			? substr( $pay_gmt, 0, 10 )
			: ( '0000-00-00 00:00:00' !== $due_gmt ? substr( $due_gmt, 0, 10 ) : '' );

		return compact( 'title', 'amount', 'is_income', 'due_gmt', 'pay_gmt', 'status', 'order_date' );
	}

	/* ── Pages ──────────────────────────────────────────── */

	public function render_dashboard(): void {
		$msg = isset( $_GET['essf_msg'] ) ? sanitize_key( $_GET['essf_msg'] ) : '';

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

	private function render_edit_page( WP_Post $entry, string $msg ): void {
		$back_url = admin_url( 'admin.php?page=essfinance' );
		?>
		<div class="wrap">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Edit Entry', 'essfinance' ); ?></h1>
			<a href="<?php echo esc_url( $back_url ); ?>" class="page-title-action">
				&larr; <?php esc_html_e( 'Cash Flow', 'essfinance' ); ?>
			</a>
			<hr class="wp-header-end">

			<?php $this->render_notices( $msg ); ?>

			<div style="max-width:480px; margin-top:16px;">
				<?php $this->render_form( $entry, false ); ?>
			</div>
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
					<p class="description"><?php esc_html_e( 'Supports CSV (EssFinance export) and OFX. Duplicates are skipped automatically.', 'essfinance' ); ?></p>
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

	private function render_form( ?WP_Post $entry = null, bool $show_heading = true ): void {
		$is_edit = null !== $entry;

		$description = $due_date = $pay_date = $status = '';
		$amount      = '';
		$is_income   = false;

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
					<input type="hidden" name="entry_id" value="<?php echo esc_attr( $entry->ID ); ?>">
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
						<?php foreach ( ESSF_CPT::$statuses as $val => $label ) : ?>
							<option value="<?php echo esc_attr( $val ); ?>" <?php selected( $status, $val ); ?>>
								<?php echo esc_html( $label ); ?>
							</option>
						<?php endforeach; ?>
					</select>
				</div>

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
		<?php
	}

	private function render_notices( string $msg ): void {
		if ( 'imported' === $msg ) {
			$n = absint( $_GET['essf_imported'] ?? 0 );
			$s = absint( $_GET['essf_skipped'] ?? 0 );
			printf(
				'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
				esc_html( sprintf( __( '%d %s imported, %d skipped as duplicates.', 'essfinance' ), $n, _n( 'entry', 'entries', $n, 'essfinance' ), $s ) )
			);
			return;
		}

		$map = [
			'added'        => [ 'success', __( 'Entry added.', 'essfinance' ) ],
			'updated'      => [ 'success', __( 'Entry updated.', 'essfinance' ) ],
			'deleted'      => [ 'success', __( 'Entry deleted.', 'essfinance' ) ],
			'error'        => [ 'error',   __( 'Something went wrong. Please try again.', 'essfinance' ) ],
			'import_error' => [ 'error',   __( 'Import failed. Please upload a valid CSV file.', 'essfinance' ) ],
		];
		if ( isset( $map[ $msg ] ) ) {
			[ $type, $text ] = $map[ $msg ];
			printf( '<div class="notice notice-%s is-dismissible"><p>%s</p></div>', esc_attr( $type ), esc_html( $text ) );
		}
	}

	/* ── WP List Table columns (edit.php fallback screen) ── */

	public function columns( $cols ): array {
		return [
			'cb'          => $cols['cb'],
			'title'       => __( 'Title', 'essfinance' ),
			'essf_type'   => __( 'Type', 'essfinance' ),
			'essf_amount' => __( 'Amount', 'essfinance' ),
			'essf_due'    => __( 'Due Date', 'essfinance' ),
			'essf_status' => __( 'Status', 'essfinance' ),
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
				$formatted = esc_html( $sign . number_format( abs( $amount ), 2, '.', ',' ) );
				if ( ESSF_Settings::show_amount_colors() ) {
					echo '<span class="essf-amount--' . ( $is_inc ? 'income' : 'expense' ) . '">' . $formatted . '</span>';
				} else {
					echo $formatted;
				}
				break;
			case 'essf_due':
				$d = substr( $post->post_date_gmt, 0, 10 );
				echo ( $d && '0000-00-00' !== $d ) ? esc_html( (string) wp_date( 'd/m/Y', strtotime( $d ) ) ) : '—';
				break;
			case 'essf_status':
				$s = $post->post_status;
				echo $s ? '<span class="essf-badge essf-badge--' . esc_attr( $s ) . '">' . esc_html( ucfirst( $s ) ) . '</span>' : '—';
				break;
		}
	}

	public function sortable_columns( $cols ): array {
		$cols['essf_amount'] = 'essf_amount';
		$cols['essf_due']    = 'essf_due';
		return $cols;
	}
}
