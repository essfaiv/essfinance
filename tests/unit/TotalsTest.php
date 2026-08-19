<?php
/**
 * Tests for ESSF_Totals::compute_running_balance_from_posts() — the pure
 * cumulative-balance math shared by the admin and frontend Balance columns
 * (the WP_Query-calling wrappers, compute_running_balances() and
 * balance_as_of_date(), stay untested, consistent with compute_from_args()).
 *
 * @package EssFinance\Tests
 * @license GPL-2.0-or-later
 */

declare( strict_types=1 );

namespace EssFinance\Tests\Unit;

use Brain\Monkey;
use PHPUnit\Framework\TestCase;
use WP_Post;

class TotalsTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function make_post( array $data ): WP_Post {
		return new WP_Post( array_merge(
			[
				'ID'           => 1,
				'post_status'  => 'paid',
				'post_content' => '0',
			],
			$data
		) );
	}

	public function test_empty_posts_returns_empty_map(): void {
		$result = \ESSF_Totals::compute_running_balance_from_posts( [], 100.0, 'paid', 'dash' );

		$this->assertSame( [], $result );
	}

	public function test_ascending_cumulative_sum_from_initial_balance(): void {
		$posts = [
			$this->make_post( [ 'ID' => 1, 'post_content' => '100' ] ),
			$this->make_post( [ 'ID' => 2, 'post_content' => '50' ] ),
			$this->make_post( [ 'ID' => 3, 'post_content' => '25' ] ),
		];

		$result = \ESSF_Totals::compute_running_balance_from_posts( $posts, 1000.0, 'all', 'dash' );

		$this->assertSame( 1100.0, $result[1] );
		$this->assertSame( 1150.0, $result[2] );
		$this->assertSame( 1175.0, $result[3] );
	}

	public function test_negative_amounts_decrement_the_running_total(): void {
		$posts = [
			$this->make_post( [ 'ID' => 1, 'post_content' => '-40' ] ),
			$this->make_post( [ 'ID' => 2, 'post_content' => '-10' ] ),
		];

		$result = \ESSF_Totals::compute_running_balance_from_posts( $posts, 100.0, 'all', 'dash' );

		$this->assertSame( 60.0, $result[1] );
		$this->assertSame( 50.0, $result[2] );
	}

	public function test_first_entrys_balance_is_initial_plus_first_amount(): void {
		$posts = [ $this->make_post( [ 'ID' => 7, 'post_content' => '30' ] ) ];

		$result = \ESSF_Totals::compute_running_balance_from_posts( $posts, 10.0, 'all', 'dash' );

		$this->assertSame( 40.0, $result[7] );
	}

	public function test_paid_basis_skips_pending_posts_in_dash_mode(): void {
		$posts = [
			$this->make_post( [ 'ID' => 1, 'post_status' => 'paid', 'post_content' => '100' ] ),
			$this->make_post( [ 'ID' => 2, 'post_status' => 'pending', 'post_content' => '9999' ] ),
			$this->make_post( [ 'ID' => 3, 'post_status' => 'paid', 'post_content' => '50' ] ),
		];

		$result = \ESSF_Totals::compute_running_balance_from_posts( $posts, 0.0, 'paid', 'dash' );

		$this->assertSame( [ 1 => 100.0, 3 => 150.0 ], $result );
		$this->assertArrayNotHasKey( 2, $result );
	}

	public function test_paid_basis_forward_fills_pending_posts(): void {
		$posts = [
			$this->make_post( [ 'ID' => 1, 'post_status' => 'paid', 'post_content' => '100' ] ),
			$this->make_post( [ 'ID' => 2, 'post_status' => 'pending', 'post_content' => '9999' ] ),
			$this->make_post( [ 'ID' => 3, 'post_status' => 'paid', 'post_content' => '50' ] ),
		];

		$result = \ESSF_Totals::compute_running_balance_from_posts( $posts, 0.0, 'paid', 'forward_fill' );

		$this->assertSame( 100.0, $result[1] );
		$this->assertSame( 100.0, $result[2], 'Pending row forward-fills the last paid balance' );
		$this->assertSame( 150.0, $result[3] );
	}

	public function test_all_basis_counts_pending_posts_too(): void {
		$posts = [
			$this->make_post( [ 'ID' => 1, 'post_status' => 'paid', 'post_content' => '100' ] ),
			$this->make_post( [ 'ID' => 2, 'post_status' => 'pending', 'post_content' => '25' ] ),
		];

		$result = \ESSF_Totals::compute_running_balance_from_posts( $posts, 0.0, 'all', 'dash' );

		$this->assertSame( 100.0, $result[1] );
		$this->assertSame( 125.0, $result[2] );
	}
}
