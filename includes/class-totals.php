<?php
/**
 * EssFinance — Balance/sum aggregation
 *
 * @package EssFinance
 * @license GPL-2.0-or-later
 */

defined( 'ABSPATH' ) || exit;

/**
 * Sums income/expense/net over `essf_cashflow` entries. Static-only, no
 * state — callers supply either WP_Query args or an already-fetched post
 * array, so a caller that already paid for a query (e.g. the list table's
 * PHP-side filter branch) doesn't pay for a second one.
 */
class ESSF_Totals {

	/**
	 * @param array $query_args WP_Query args, as built by
	 *   ESSF_List_Table::prepare_items() / ESSF_Shortcodes::render_cashflow_list().
	 *   Pagination is ignored — always summed over every matching post.
	 */
	public static function compute_from_args( array $query_args ): array {
		$query_args['posts_per_page'] = -1;
		$query_args['paged']          = 1;
		$query_args['fields']         = 'all';
		$query_args['orderby']        = 'none';

		$query = new WP_Query( $query_args );
		return self::compute_from_posts( $query->posts );
	}

	/**
	 * @param WP_Post[] $posts
	 */
	public static function compute_from_posts( array $posts ): array {
		$income  = 0.0;
		$expense = 0.0;

		foreach ( $posts as $post ) {
			$amount = (float) $post->post_content;
			if ( $amount > 0 ) {
				$income += $amount;
			} else {
				$expense += $amount;
			}
		}

		return [
			'income'  => $income,
			'expense' => $expense,
			'net'     => $income + $expense,
			'count'   => count( $posts ),
		];
	}

	/**
	 * Always-on summary cards, independent of the current list filters.
	 *
	 * @param int $author_id 0 = all authors (admin dashboard); otherwise
	 *   scopes to that author (frontend, `[essfinance_cashflow]`).
	 */
	public static function global_summary( int $author_id = 0 ): array {
		$today = (string) date_i18n( 'Y-m-d' );

		$base_args = [
			'post_type'      => 'essf_cashflow',
			'posts_per_page' => -1,
			'fields'         => 'all',
			'orderby'        => 'none',
		];
		if ( $author_id ) {
			$base_args['author'] = $author_id;
		}

		$pending_posts = ( new WP_Query( array_merge( $base_args, [ 'post_status' => 'pending' ] ) ) )->posts;
		$pending       = self::compute_from_posts( $pending_posts );

		$overdue_posts = array_filter(
			$pending_posts,
			static function ( $post ) use ( $today ) {
				$due = substr( $post->post_date_gmt, 0, 10 );
				return $due && '0000-00-00' !== $due && $due < $today;
			}
		);
		$overdue       = self::compute_from_posts( array_values( $overdue_posts ) );

		$paid_posts      = ( new WP_Query(
			array_merge(
				$base_args,
				[
					'post_status' => 'paid',
					'date_query'  => [
						[
							'column' => 'post_modified_gmt',
							'year'   => (int) date_i18n( 'Y' ),
							'month'  => (int) date_i18n( 'n' ),
						],
					],
				]
			)
		) )->posts;
		$paid_this_month = self::compute_from_posts( $paid_posts );

		return [
			'pending_balance' => $pending['net'],
			'overdue_total'   => $overdue['net'],
			'paid_this_month' => $paid_this_month['net'],
		];
	}

	/**
	 * Pure: cumulative running balance over an already chronologically
	 * ordered (oldest-first) WP_Post[] slice, offset by $initial_balance.
	 * A post whose status isn't counted by $basis (e.g. a pending post when
	 * $basis is 'paid') doesn't advance the running total; depending on
	 * $pending_mode it either gets the last counted balance ('forward_fill')
	 * or is omitted from the returned map entirely ('dash' — callers render
	 * a placeholder for any post ID missing from the result).
	 *
	 * @param WP_Post[] $posts
	 * @param string    $basis         'paid' | 'all'.
	 * @param string    $pending_mode  'dash' | 'forward_fill'.
	 * @return array<int,float> post ID => displayed balance, in input order.
	 */
	public static function compute_running_balance_from_posts( array $posts, float $initial_balance, string $basis, string $pending_mode ): array {
		$running = $initial_balance;
		$out     = [];

		foreach ( $posts as $post ) {
			$counts = 'all' === $basis || 'paid' === $post->post_status;
			if ( $counts ) {
				$running         += (float) $post->post_content;
				$out[ $post->ID ] = $running;
			} elseif ( 'forward_fill' === $pending_mode ) {
				$out[ $post->ID ] = $running;
			}
		}

		return $out;
	}

	/**
	 * WP_Query wrapper around compute_running_balance_from_posts(). $order_args
	 * supplies post_type/author/orderby/meta_key only — post_status,
	 * pagination, search, and tax_query are stripped/overridden, because the
	 * running balance must reflect every entry's true chronological position
	 * regardless of which page or *display* filter is currently active
	 * (filters narrow what is shown, never what is summed).
	 *
	 * @param array $order_args WP_Query args (post_type/author/orderby/meta_key).
	 * @return array<int,float> post ID => displayed balance.
	 */
	public static function compute_running_balances( array $order_args ): array {
		unset( $order_args['posts_per_page'], $order_args['paged'], $order_args['s'], $order_args['tax_query'], $order_args['post_status'] );
		$order_args['post_status']    = [ 'pending', 'paid' ];
		$order_args['posts_per_page'] = -1;
		$order_args['no_found_rows']  = true;

		$query = new WP_Query( $order_args );

		return self::compute_running_balance_from_posts(
			$query->posts,
			ESSF_Settings::initial_balance(),
			ESSF_Settings::balance_basis(),
			ESSF_Settings::pending_balance_mode()
		);
	}

	/**
	 * The single running-balance figure as of the end of a given date —
	 * every entry with `_order_date` on or before it, summed under the
	 * current balance basis (ESSF_Settings::balance_basis()), starting from
	 * the configured initial balance. Used by the balance-reconciliation
	 * ("Adjust Balance") admin action to compute the gap against an
	 * operator-supplied real-world figure.
	 */
	public static function balance_as_of_date( string $date ): float {
		$query = new WP_Query(
			[
				'post_type'      => 'essf_cashflow',
				'post_status'    => [ 'pending', 'paid' ],
				'posts_per_page' => -1,
				'no_found_rows'  => true,
				'orderby'        => [
					'meta_value' => 'ASC', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- same _order_date ordering used elsewhere for the running balance
					'ID'         => 'ASC',
				],
				'meta_key'       => '_order_date', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_query'     => [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- same table/scale as the running balance query above
					[
						'key'     => '_order_date',
						'value'   => $date,
						'compare' => '<=',
						'type'    => 'DATE',
					],
				],
			]
		);

		$balances = self::compute_running_balance_from_posts(
			$query->posts,
			ESSF_Settings::initial_balance(),
			ESSF_Settings::balance_basis(),
			'dash'
		);

		return $balances ? end( $balances ) : ESSF_Settings::initial_balance();
	}
}
