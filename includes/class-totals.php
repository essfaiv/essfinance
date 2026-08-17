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
}
