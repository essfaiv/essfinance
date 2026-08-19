<?php
/**
 * Tests for ESSF_Bill_CPT (expand_title, strip_tokens, period_bounds).
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

class BillTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function call_expand_title( string $name, string $due_ymd ): string {
		$method = new ReflectionMethod( \ESSF_Bill_CPT::class, 'expand_title' );
		$method->setAccessible( true );
		return $method->invoke( null, $name, $due_ymd );
	}

	private function call_strip_tokens( string $name ): string {
		$method = new ReflectionMethod( \ESSF_Bill_CPT::class, 'strip_tokens' );
		$method->setAccessible( true );
		return $method->invoke( null, $name );
	}

	private function call_period_bounds( \WP_Term $term, int $periods_back = 0 ): array {
		$method = new ReflectionMethod( \ESSF_Bill_CPT::class, 'period_bounds' );
		$method->setAccessible( true );
		return $method->invoke( null, $term, $periods_back );
	}

	/** current_time()/date_i18n() mocked against a fixed "today" so tests never depend on the real clock. */
	private function mock_today( string $ymd ): void {
		$ts = strtotime( $ymd );
		Functions\expect( 'current_time' )
			->andReturnUsing( static function ( $type ) use ( $ts ) {
				return 'Y-m-01' === $type ? gmdate( 'Y-m-01', $ts ) : gmdate( $type, $ts );
			} );
		Functions\expect( 'date_i18n' )
			->andReturnUsing( static function ( string $fmt, $timestamp ) {
				return gmdate( $fmt, $timestamp );
			} );
	}

	// ── expand_title() ────────────────────────────────────────────────────

	public function test_expand_title_passes_through_name_without_tokens(): void {
		$this->assertSame( 'Electricity', $this->call_expand_title( 'Electricity', '2026-08-14' ) );
	}

	public function test_expand_title_replaces_full_date_token(): void {
		$this->assertSame(
			'Cleaning 2026-08-14 Cleiri',
			$this->call_expand_title( 'Cleaning YYYY-MM-DD Cleiri', '2026-08-14' )
		);
	}

	public function test_expand_title_replaces_year_month_token(): void {
		$this->assertSame( 'Electricity 2026-08', $this->call_expand_title( 'Electricity YYYY-MM', '2026-08-14' ) );
	}

	public function test_expand_title_does_not_corrupt_full_date_token_with_year_month_replacement(): void {
		// YYYY-MM-DD contains YYYY-MM as a literal substring — replacing
		// YYYY-MM first would leave a broken "...2026-08-DD" behind.
		$this->assertSame(
			'X 2026-08-14 2026-08 Y',
			$this->call_expand_title( 'X YYYY-MM-DD YYYY-MM Y', '2026-08-14' )
		);
	}

	// ── strip_tokens() ────────────────────────────────────────────────────

	public function test_strip_tokens_removes_full_date_token_and_collapses_whitespace(): void {
		$this->assertSame( 'Cleaning Cleiri', $this->call_strip_tokens( 'Cleaning YYYY-MM-DD Cleiri' ) );
	}

	public function test_strip_tokens_removes_year_month_token(): void {
		$this->assertSame( 'Electricity', $this->call_strip_tokens( 'Electricity YYYY-MM' ) );
	}

	public function test_strip_tokens_is_identity_when_no_token_present(): void {
		$this->assertSame( 'Electricity', $this->call_strip_tokens( 'Electricity' ) );
	}

	// ── period_bounds() ───────────────────────────────────────────────────

	public function test_period_bounds_monthly_clamps_due_day_to_days_in_month(): void {
		$this->mock_today( '2026-02-10' ); // February — 28 days in 2026 (not a leap year)
		Functions\expect( 'get_term_meta' )
			->andReturnUsing( static function ( $term_id, $key ) {
				if ( \ESSF_Bill_CPT::META_DUE_DAY === $key ) {
					return 31;
				}
				return '';
			} );

		$term   = new \WP_Term( [ 'term_id' => 1, 'name' => 'Electricity' ] );
		$bounds = $this->call_period_bounds( $term, 0 );

		$this->assertSame( '2026-02-01', $bounds['start'] );
		$this->assertSame( '2026-02-28', $bounds['end'] );
		$this->assertSame( '2026-02-28', $bounds['due'] ); // clamped from 31 down to 28
	}

	public function test_period_bounds_weekly_is_monday_to_sunday(): void {
		$this->mock_today( '2026-08-19' ); // a Wednesday
		Functions\expect( 'get_term_meta' )
			->andReturnUsing( static function ( $term_id, $key ) {
				if ( \ESSF_Bill_CPT::META_RECURRENCE === $key ) {
					return 'week';
				}
				if ( \ESSF_Bill_CPT::META_DUE_WEEKDAY === $key ) {
					return 5; // Friday
				}
				return '';
			} );

		$term   = new \WP_Term( [ 'term_id' => 2, 'name' => 'Cleaning' ] );
		$bounds = $this->call_period_bounds( $term, 0 );

		$this->assertSame( '2026-08-17', $bounds['start'] ); // Monday of that week
		$this->assertSame( '2026-08-23', $bounds['end'] );   // Sunday
		$this->assertSame( '2026-08-21', $bounds['due'] );   // Friday
	}

	public function test_period_bounds_weekly_periods_back_goes_to_prior_week(): void {
		$this->mock_today( '2026-08-19' );
		Functions\expect( 'get_term_meta' )
			->andReturnUsing( static function ( $term_id, $key ) {
				if ( \ESSF_Bill_CPT::META_RECURRENCE === $key ) {
					return 'week';
				}
				return '';
			} );

		$term   = new \WP_Term( [ 'term_id' => 3, 'name' => 'Cleaning' ] );
		$bounds = $this->call_period_bounds( $term, 1 );

		$this->assertSame( '2026-08-10', $bounds['start'] );
	}
}
