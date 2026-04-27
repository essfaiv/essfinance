<?php
/**
 * Tests for ESSF_List_Table (entry_order_date, get_due_date_months, month filter).
 *
 * @package EssFinance\Tests
 * @license GPL-2.0-or-later
 */

declare( strict_types=1 );

namespace EssFinance\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use WP_Post;

class ListTableTest extends TestCase {

	private \ESSF_List_Table $table;
	private ReflectionMethod $method_order_date;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\expect( '__' )->andReturnFirstArg();
		Functions\expect( 'admin_url' )->andReturn( 'http://example.com/wp-admin/' );
		Functions\expect( 'add_query_arg' )->andReturnFirstArg();

		$this->table             = new \ESSF_List_Table();
		$this->method_order_date = new ReflectionMethod( \ESSF_List_Table::class, 'entry_order_date' );
		$this->method_order_date->setAccessible( true );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function make_post( array $data ): WP_Post {
		return new WP_Post( array_merge(
			[
				'ID'                => 1,
				'post_status'       => 'pending',
				'post_date_gmt'     => '2025-11-01 00:00:00',
				'post_modified_gmt' => '0000-00-00 00:00:00',
			],
			$data
		) );
	}

	private function call_order_date( WP_Post $post ): string {
		return $this->method_order_date->invoke( $this->table, $post );
	}

	// ── entry_order_date mirrors Shortcodes logic ────────────────────────────

	public function test_paid_entry_returns_pay_date(): void {
		$post = $this->make_post( [
			'ID'                => 11,
			'post_status'       => 'paid',
			'post_date_gmt'     => '2025-11-01 00:00:00',
			'post_modified_gmt' => '2026-01-10 00:00:00',
		] );

		Functions\expect( 'get_post_meta' )
			->with( 11, '_order_date', true )
			->andReturn( '' );
		Functions\expect( 'update_post_meta' )->once();

		$this->assertSame( '2026-01-10', $this->call_order_date( $post ) );
	}

	public function test_pending_entry_returns_due_date(): void {
		$post = $this->make_post( [
			'ID'            => 21,
			'post_status'   => 'pending',
			'post_date_gmt' => '2025-11-05 00:00:00',
		] );

		Functions\expect( 'get_post_meta' )
			->with( 21, '_order_date', true )
			->andReturn( '' );
		Functions\expect( 'update_post_meta' )->once();

		$this->assertSame( '2025-11-05', $this->call_order_date( $post ) );
	}

	public function test_cached_meta_returned_without_recompute(): void {
		$post = $this->make_post( [ 'ID' => 99 ] );

		Functions\expect( 'get_post_meta' )
			->with( 99, '_order_date', true )
			->andReturn( '2026-03-01' );

		Functions\expect( 'update_post_meta' )->never();

		$this->assertSame( '2026-03-01', $this->call_order_date( $post ) );
	}

	// ── get_due_date_months returns YYYYMM-keyed array ───────────────────────

	public function test_get_due_date_months_returns_yyyymm_keys(): void {
		$method = new ReflectionMethod( \ESSF_List_Table::class, 'get_due_date_months' );
		$method->setAccessible( true );

		$posts = [
			$this->make_post( [
				'ID'                => 101,
				'post_status'       => 'paid',
				'post_date_gmt'     => '2025-11-01 00:00:00',
				'post_modified_gmt' => '2026-01-15 00:00:00',
			] ),
			$this->make_post( [
				'ID'                => 102,
				'post_status'       => 'pending',
				'post_date_gmt'     => '2026-03-01 00:00:00',
				'post_modified_gmt' => '0000-00-00 00:00:00',
			] ),
		];

		Functions\expect( 'get_posts' )->andReturn( $posts );
		Functions\expect( 'get_post_meta' )
			->with( 101, '_order_date', true )->andReturn( '2026-01-15' );
		Functions\expect( 'get_post_meta' )
			->with( 102, '_order_date', true )->andReturn( '2026-03-01' );
		Functions\expect( 'wp_date' )
			->andReturnUsing( static function ( string $fmt, $ts ): string {
				return date( $fmt, $ts ); // phpcs:ignore
			} );

		$months = $method->invoke( $this->table );

		// Keys must be 6-digit YYYYMM integers or strings, not 0/1/2/3.
		foreach ( array_keys( $months ) as $key ) {
			$this->assertMatchesRegularExpression( '/^\d{6}$/', (string) $key, 'Month key must be YYYYMM' );
		}
		$this->assertArrayHasKey( '202601', $months );
		$this->assertArrayHasKey( '202603', $months );
	}

	// ── month key uniqueness ─────────────────────────────────────────────────

	public function test_get_due_date_months_deduplicates_same_month(): void {
		$method = new ReflectionMethod( \ESSF_List_Table::class, 'get_due_date_months' );
		$method->setAccessible( true );

		$posts = [
			$this->make_post( [ 'ID' => 201, 'post_date_gmt' => '2026-03-01 00:00:00' ] ),
			$this->make_post( [ 'ID' => 202, 'post_date_gmt' => '2026-03-15 00:00:00' ] ),
		];

		Functions\expect( 'get_posts' )->andReturn( $posts );
		Functions\expect( 'get_post_meta' )->andReturn( '' );
		Functions\expect( 'update_post_meta' )->zeroOrMoreTimes();
		Functions\expect( 'wp_date' )
			->andReturnUsing( static function ( string $fmt, $ts ): string {
				return date( $fmt, $ts ); // phpcs:ignore
			} );

		$months = $method->invoke( $this->table );
		$this->assertCount( 1, $months, 'Two entries in same month produce one dropdown option' );
	}
}
