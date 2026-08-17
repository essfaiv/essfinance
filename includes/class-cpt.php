<?php
/**
 * EssFinance — Custom Post Type
 *
 * @package EssFinance
 * @license GPL-2.0-or-later
 */

defined( 'ABSPATH' ) || exit;

class ESSF_CPT {

	/**
	 * Real post-status keys (never 'overdue' — that's a computed, never
	 * stored, virtual status) mapped to their translated label. A method
	 * rather than a property (like the old public static $statuses) so the
	 * label is resolved fresh via __() on every call, in the site's *current*
	 * language, instead of being fixed in English at class-load time (a
	 * plain property's value is set once, before WordPress even finishes
	 * booting — too early to translate anything).
	 *
	 * @return array<string, string>
	 */
	public static function labels(): array {
		return [
			'pending' => __( 'Pending', 'essfinance' ),
			'paid'    => __( 'Paid', 'essfinance' ),
		];
	}

	/**
	 * Translated label for a status, including the virtual 'overdue' state
	 * (pending + due date in the past — computed at render time, never
	 * stored, so it isn't in labels()). Falls back to ucfirst() for
	 * anything else, matching the previous untranslated behavior.
	 */
	public static function status_label( string $status ): string {
		if ( 'overdue' === $status ) {
			return __( 'Overdue', 'essfinance' );
		}
		return self::labels()[ $status ] ?? ucfirst( $status );
	}

	public function __construct() {
		add_action( 'init', [ $this, 'register' ] );
		add_action( 'pre_get_posts', [ $this, 'default_admin_post_status' ] );
	}

	/**
	 * `essf_cashflow` entries never use WordPress's default 'publish' status
	 * (only the custom 'pending'/'paid' — see labels()), but WP_Query
	 * defaults to 'publish' whenever a query doesn't set post_status
	 * explicitly. Every query this plugin's own admin/frontend code builds
	 * already passes post_status explicitly and is unaffected — this only
	 * fills the gap for WordPress's own native `edit.php` fallback screen
	 * (including its taxonomy/term-filtered views), which doesn't know about
	 * this post type's custom statuses and would otherwise always show zero
	 * results.
	 */
	public function default_admin_post_status( WP_Query $query ): void {
		if ( ! is_admin() || ! $query->is_main_query() ) {
			return;
		}
		if ( 'essf_cashflow' !== $query->get( 'post_type' ) ) {
			return;
		}
		if ( '' !== $query->get( 'post_status' ) ) {
			return; // Don't override an explicit filter (e.g. the Trash view).
		}

		$query->set( 'post_status', array_keys( self::labels() ) );
	}

	public function register() {
		register_post_type(
			'essf_cashflow',
			[
				'labels'       => [
					'name'               => __( 'Cash Flow', 'essfinance' ),
					'singular_name'      => __( 'Entry', 'essfinance' ),
					'add_new'            => __( 'Add Entry', 'essfinance' ),
					'add_new_item'       => __( 'Add Entry', 'essfinance' ),
					'edit_item'          => __( 'Edit Entry', 'essfinance' ),
					'new_item'           => __( 'New Entry', 'essfinance' ),
					'view_item'          => __( 'View Entry', 'essfinance' ),
					'search_items'       => __( 'Search Entries', 'essfinance' ),
					'not_found'          => __( 'No entries found.', 'essfinance' ),
					'not_found_in_trash' => __( 'No entries in trash.', 'essfinance' ),
				],
				'public'       => false,
				'show_ui'      => true,
				'show_in_menu' => false,
				'supports'     => [ 'title', 'editor', 'custom-fields' ],
				'capabilities' => [
					'create_posts' => 'manage_options',
				],
				'map_meta_cap' => true,
			]
		);

		register_post_status(
			'pending',
			[
				'label'                     => __( 'Pending', 'essfinance' ),
				'public'                    => false,
				'show_in_admin_status_list' => true,
				'show_in_admin_all_list'    => true,
			]
		);

		register_post_status(
			'paid',
			[
				'label'                     => __( 'Paid', 'essfinance' ),
				'public'                    => false,
				'show_in_admin_status_list' => true,
				'show_in_admin_all_list'    => true,
			]
		);

		register_post_meta(
			'essf_cashflow',
			'_order_date',
			[
				'type'   => 'string',
				'single' => true,
			]
		);
	}

	public static function activate() {
		( new self() )->register();
		flush_rewrite_rules();
		ESSF_Shortcodes::create_pages();
	}

	/**
	 * Shared insert path for any code that creates an `essf_cashflow` entry
	 * outside the normal add/edit forms (bills, loans, financing). Mirrors
	 * ESSF_Admin_Page::insert_entry() — writes post_date_gmt/post_modified_gmt
	 * via a direct $wpdb->update() rather than wp_update_post(), since
	 * WordPress doesn't reliably persist those two columns otherwise (see
	 * CLAUDE.md).
	 */
	public static function insert_entry( string $title, float $amount, string $due_gmt, string $pay_gmt, string $status, string $category_slug = 'uncategorized' ): int {
		$post_id = wp_insert_post(
			[
				'post_type'    => 'essf_cashflow',
				'post_title'   => $title,
				'post_content' => (string) $amount,
				'post_status'  => $status,
			],
			true
		);

		if ( is_wp_error( $post_id ) ) {
			return 0;
		}

		global $wpdb;
		$wpdb->update(
			$wpdb->posts,
			[
				'post_date_gmt'     => $due_gmt,
				'post_modified_gmt' => $pay_gmt,
			],
			[ 'ID' => $post_id ],
			[ '%s', '%s' ],
			[ '%d' ]
		);

		$order_date = '0000-00-00 00:00:00' !== $pay_gmt
			? substr( $pay_gmt, 0, 10 )
			: ( '0000-00-00 00:00:00' !== $due_gmt ? substr( $due_gmt, 0, 10 ) : '' );
		update_post_meta( $post_id, '_order_date', $order_date );
		wp_set_post_terms( $post_id, [ ESSF_Category::term_id_for_slug( $category_slug ) ], ESSF_Category::TAXONOMY );

		return $post_id;
	}

	/**
	 * `essf_cashflow` entries whose description matches `$title` exactly
	 * (WP_Query's `title` param does a real `post_title = %s` match, not a
	 * fuzzy search), optionally further scoped by `$date_query`. Bills,
	 * loans, and financing use this to find "their" entries at display time
	 * — description (+ due date, for bills) as an exclusive-enough key —
	 * instead of a stored link.
	 *
	 * @return WP_Post[]
	 */
	public static function find_entries_by_title( string $title, array $date_query = [] ): array {
		$args = [
			'post_type'      => 'essf_cashflow',
			'post_status'    => [ 'pending', 'paid' ],
			'posts_per_page' => -1,
			'title'          => $title,
		];
		if ( $date_query ) {
			$args['date_query'] = $date_query; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_date_query
		}
		return get_posts( $args );
	}

	/**
	 * URL to the Cash Flow dashboard, searched for `$title` across all
	 * months (`m=-1`, the explicit "All months" sentinel — see
	 * ESSF_List_Table::render_months_dropdown()) — the "view everything
	 * matching this plan" link shared by the Bills/Loans/Financing term list
	 * screens.
	 */
	public static function cashflow_search_url( string $title ): string {
		return add_query_arg(
			[
				'page' => 'essfinance',
				's'    => $title,
				'm'    => '-1',
			],
			admin_url( 'admin.php' )
		);
	}
}
