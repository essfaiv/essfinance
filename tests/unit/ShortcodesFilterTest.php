<?php
/**
 * Tests for ESSF_Shortcodes filter logic (entry_order_date, get_due_date_months).
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

class ShortcodesFilterTest extends TestCase {

	private \ESSF_Shortcodes $shortcodes;
	private ReflectionMethod $method_order_date;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\expect( '__' )->andReturnFirstArg();

		$this->shortcodes        = new \ESSF_Shortcodes();
		$this->method_order_date = new ReflectionMethod( \ESSF_Shortcodes::class, 'entry_order_date' );
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
		return $this->method_order_date->invoke( $this->shortcodes, $post );
	}

	// ── entry_order_date: cached meta ────────────────────────────────────────

	public function test_returns_cached_order_date_meta_without_recomputing(): void {
		$post = $this->make_post( [ 'ID' => 42 ] );

		Functions\expect( 'get_post_meta' )
			->once()
			->with( 42, '_order_date', true )
			->andReturn( '2026-01-15' );

		$result = $this->call_order_date( $post );
		$this->assertSame( '2026-01-15', $result );
	}

	// ── entry_order_date: paid entry uses pay_date ───────────────────────────

	public function test_paid_entry_returns_pay_date(): void {
		$post = $this->make_post( [
			'ID'                => 10,
			'post_status'       => 'paid',
			'post_date_gmt'     => '2025-11-01 00:00:00',
			'post_modified_gmt' => '2026-01-10 00:00:00',
		] );

		Functions\expect( 'get_post_meta' )
			->once()
			->with( 10, '_order_date', true )
			->andReturn( '' );

		Functions\expect( 'update_post_meta' )
			->once()
			->with( 10, '_order_date', '2026-01-10' );

		$result = $this->call_order_date( $post );
		$this->assertSame( '2026-01-10', $result );
	}

	// ── entry_order_date: pending entry uses due_date ────────────────────────

	public function test_pending_entry_returns_due_date(): void {
		$post = $this->make_post( [
			'ID'                => 20,
			'post_status'       => 'pending',
			'post_date_gmt'     => '2025-11-05 00:00:00',
			'post_modified_gmt' => '0000-00-00 00:00:00',
		] );

		Functions\expect( 'get_post_meta' )
			->once()
			->with( 20, '_order_date', true )
			->andReturn( '' );

		Functions\expect( 'update_post_meta' )
			->once()
			->with( 20, '_order_date', '2025-11-05' );

		$result = $this->call_order_date( $post );
		$this->assertSame( '2025-11-05', $result );
	}

	// ── entry_order_date: paid entry with zeroed pay_date falls back ─────────

	public function test_paid_entry_with_zero_pay_date_falls_back_to_due_date(): void {
		$post = $this->make_post( [
			'ID'                => 30,
			'post_status'       => 'paid',
			'post_date_gmt'     => '2025-11-01 00:00:00',
			'post_modified_gmt' => '0000-00-00 00:00:00',
		] );

		Functions\expect( 'get_post_meta' )
			->once()
			->andReturn( '' );

		Functions\expect( 'update_post_meta' )
			->once()
			->with( 30, '_order_date', '2025-11-01' );

		$result = $this->call_order_date( $post );
		$this->assertSame( '2025-11-01', $result );
	}

	// ── entry_order_date: no dates returns empty string ──────────────────────

	public function test_post_with_no_dates_returns_empty_string(): void {
		$post = $this->make_post( [
			'ID'                => 50,
			'post_status'       => 'pending',
			'post_date_gmt'     => '0000-00-00 00:00:00',
			'post_modified_gmt' => '0000-00-00 00:00:00',
		] );

		Functions\expect( 'get_post_meta' )
			->once()
			->andReturn( '' );

		Functions\expect( 'update_post_meta' )->never();

		$result = $this->call_order_date( $post );
		$this->assertSame( '', $result );
	}

	// ── entry_order_date: month assignment follows pay_date, not due_date ────

	public function test_item_due_in_november_paid_in_january_maps_to_january(): void {
		$post = $this->make_post( [
			'ID'                => 60,
			'post_status'       => 'paid',
			'post_date_gmt'     => '2025-11-15 00:00:00', // due: November
			'post_modified_gmt' => '2026-01-20 00:00:00', // paid: January
		] );

		Functions\expect( 'get_post_meta' )->andReturn( '' );
		Functions\expect( 'update_post_meta' )->once()->with( 60, '_order_date', '2026-01-20' );

		$result = $this->call_order_date( $post );

		// Confirms the v0.2 behavior: paid items list under pay_date's month.
		$this->assertStringStartsWith( '2026-01', $result );
	}
}
