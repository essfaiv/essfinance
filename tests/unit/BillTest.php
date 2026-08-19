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

	// ── find_last_entries()/find_last_entry()/find_last_amount() ─────────

	private function call_find_last_entries( \WP_Term $term, int $limit ): array {
		$method = new ReflectionMethod( \ESSF_Bill_CPT::class, 'find_last_entries' );
		$method->setAccessible( true );
		return $method->invoke( null, $term, $limit );
	}

	private function call_find_last_amount( \WP_Term $term ): ?float {
		$method = new ReflectionMethod( \ESSF_Bill_CPT::class, 'find_last_amount' );
		$method->setAccessible( true );
		return $method->invoke( null, $term );
	}

	public function test_find_last_entries_matches_token_expanded_titles_only(): void {
		$matching_a   = new \WP_Post( [ 'ID' => 901, 'post_title' => 'Electricity 2026-07', 'post_content' => '-89.90' ] );
		$matching_b   = new \WP_Post( [ 'ID' => 902, 'post_title' => 'Electricity 2026-06', 'post_content' => '-75.00' ] );
		$unrelated    = new \WP_Post( [ 'ID' => 903, 'post_title' => 'Electricity Backup Battery', 'post_content' => '-500' ] );

		Functions\expect( 'get_posts' )->andReturn( [ $matching_a, $unrelated, $matching_b ] );

		$term    = new \WP_Term( [ 'term_id' => 1, 'name' => 'Electricity YYYY-MM' ] );
		$entries = $this->call_find_last_entries( $term, 5 );

		$this->assertSame( [ 901, 902 ], array_map( static fn( $p ) => $p->ID, $entries ) );
	}

	public function test_find_last_entries_tolerates_a_hand_typed_date_suffix_when_name_has_no_token(): void {
		// Real-world case: the term's own name was never configured with a
		// token, but the actual entry was hand-typed with a trailing date
		// anyway ("Limpeza semanal" vs "Limpeza semanal 2026-08-14") — this
		// must still be found, not silently invisible to the bill's history.
		$matching  = new \WP_Post( [ 'ID' => 230, 'post_title' => 'Limpeza semanal 2026-08-14', 'post_content' => '-210' ] );
		$unrelated = new \WP_Post( [ 'ID' => 231, 'post_title' => 'Limpeza semanal Extra', 'post_content' => '-50' ] );

		Functions\expect( 'get_posts' )->andReturn( [ $matching, $unrelated ] );

		$term    = new \WP_Term( [ 'term_id' => 1, 'name' => 'Limpeza semanal' ] );
		$entries = $this->call_find_last_entries( $term, 5 );

		// Only the date-shaped suffix matches — "Extra" still doesn't, same
		// protection as the "Electricity Backup Battery" case above.
		$this->assertSame( [ 230 ], array_map( static fn( $p ) => $p->ID, $entries ) );
	}

	public function test_find_last_entries_respects_the_limit(): void {
		$a = new \WP_Post( [ 'ID' => 1, 'post_title' => 'Cleaning', 'post_content' => '-10' ] );
		$b = new \WP_Post( [ 'ID' => 2, 'post_title' => 'Cleaning', 'post_content' => '-10' ] );
		$c = new \WP_Post( [ 'ID' => 3, 'post_title' => 'Cleaning', 'post_content' => '-10' ] );

		Functions\expect( 'get_posts' )->andReturn( [ $a, $b, $c ] );

		$term = new \WP_Term( [ 'term_id' => 1, 'name' => 'Cleaning' ] );
		$this->assertCount( 2, $this->call_find_last_entries( $term, 2 ) );
	}

	public function test_find_last_amount_returns_the_most_recent_matching_entry(): void {
		$latest = new \WP_Post( [ 'ID' => 901, 'post_title' => 'Electricity', 'post_content' => '-89.90' ] );

		Functions\expect( 'get_posts' )->andReturn( [ $latest ] ); // get_posts is already asked for DESC order

		$term = new \WP_Term( [ 'term_id' => 1, 'name' => 'Electricity' ] );
		$this->assertSame( 89.9, $this->call_find_last_amount( $term ) );
	}

	public function test_find_last_amount_returns_null_when_nothing_found(): void {
		Functions\expect( 'get_posts' )->andReturn( [] );

		$this->assertNull( $this->call_find_last_amount( new \WP_Term( [ 'term_id' => 1, 'name' => 'Cleaning' ] ) ) );
	}

	// ── find_entry_in_period_lenient() ────────────────────────────────────
	//
	// render_history_rows_monthly()'s fallback when the exact-title lookup
	// finds nothing and the bill's name has no token — real-world case:
	// "Eletricidade" (no token) vs the actual entry "Eletricidade 2026-07".

	private function call_find_entry_in_period_lenient( \WP_Term $term, array $bounds ): ?\WP_Post {
		$method = new ReflectionMethod( \ESSF_Bill_CPT::class, 'find_entry_in_period_lenient' );
		$method->setAccessible( true );
		return $method->invoke( null, $term, $bounds );
	}

	public function test_find_entry_in_period_lenient_matches_date_suffixed_title(): void {
		$entry = new \WP_Post( [ 'ID' => 205, 'post_title' => 'Eletricidade 2026-07', 'post_content' => '-150' ] );
		Functions\expect( 'get_posts' )->andReturn( [ $entry ] );
		Functions\expect( 'get_term_meta' )->andReturn( '' ); // no recurrence meta set — defaults to monthly

		$term   = new \WP_Term( [ 'term_id' => 1, 'name' => 'Eletricidade' ] );
		$bounds = [ 'start' => '2026-07-01', 'end' => '2026-07-31', 'due' => '2026-07-05' ];

		$found = $this->call_find_entry_in_period_lenient( $term, $bounds );

		$this->assertNotNull( $found );
		$this->assertSame( 205, $found->ID );
	}

	public function test_find_entry_in_period_lenient_ignores_non_date_shaped_suffix(): void {
		$unrelated = new \WP_Post( [ 'ID' => 206, 'post_title' => 'Eletricidade Extra Bateria', 'post_content' => '-500' ] );
		Functions\expect( 'get_posts' )->andReturn( [ $unrelated ] );
		Functions\expect( 'get_term_meta' )->andReturn( '' );

		$term   = new \WP_Term( [ 'term_id' => 1, 'name' => 'Eletricidade' ] );
		$bounds = [ 'start' => '2026-07-01', 'end' => '2026-07-31', 'due' => '2026-07-05' ];

		$this->assertNull( $this->call_find_entry_in_period_lenient( $term, $bounds ) );
	}

	public function test_find_entry_in_period_lenient_returns_null_when_nothing_found(): void {
		Functions\expect( 'get_posts' )->andReturn( [] );
		Functions\expect( 'get_term_meta' )->andReturn( '' );

		$term   = new \WP_Term( [ 'term_id' => 1, 'name' => 'Eletricidade' ] );
		$bounds = [ 'start' => '2026-07-01', 'end' => '2026-07-31', 'due' => '2026-07-05' ];

		$this->assertNull( $this->call_find_entry_in_period_lenient( $term, $bounds ) );
	}

	// ── trim_leading_ungenerated() ────────────────────────────────────────

	private function call_trim_leading_ungenerated( array $entries ): array {
		$method = new ReflectionMethod( \ESSF_Bill_CPT::class, 'trim_leading_ungenerated' );
		$method->setAccessible( true );
		return $method->invoke( null, $entries );
	}

	public function test_trim_leading_ungenerated_keeps_all_when_every_period_has_an_entry(): void {
		$entries = array_map( static fn( $i ) => new \WP_Post( [ 'ID' => $i ] ), range( 0, 5 ) );

		$this->assertSame( $entries, $this->call_trim_leading_ungenerated( $entries ) );
	}

	public function test_trim_leading_ungenerated_drops_months_older_than_the_earliest_real_entry(): void {
		// The real "Eletricidade" case: only the current period (index 0) has
		// ever had an entry — the other 5 calendar months are pure noise.
		$current       = new \WP_Post( [ 'ID' => 205 ] );
		$entries       = [ $current, null, null, null, null, null ];

		$this->assertSame( [ 0 => $current ], $this->call_trim_leading_ungenerated( $entries ) );
	}

	public function test_trim_leading_ungenerated_keeps_a_gap_between_real_entries(): void {
		// Current month not yet generated, a gap at index 1, a real entry at
		// index 2 — everything from the oldest real entry onward is kept,
		// including the gaps within that range.
		$older   = new \WP_Post( [ 'ID' => 42 ] );
		$entries = [ null, null, $older, null, null, null ];

		$this->assertSame(
			[
				0 => null,
				1 => null,
				2 => $older,
			],
			$this->call_trim_leading_ungenerated( $entries )
		);
	}

	public function test_trim_leading_ungenerated_keeps_only_current_period_when_no_history_at_all(): void {
		$entries = array_fill( 0, 6, null );

		$this->assertSame( [ 0 => null ], $this->call_trim_leading_ungenerated( $entries ) );
	}

	// ── next_due_date_weekly() ──────────────────────────────────────────

	private function call_next_due_date_weekly( \WP_Term $term ): string {
		$method = new ReflectionMethod( \ESSF_Bill_CPT::class, 'next_due_date_weekly' );
		$method->setAccessible( true );
		return $method->invoke( null, $term );
	}

	public function test_next_due_date_weekly_bootstraps_from_configured_weekday_with_no_history(): void {
		$this->mock_today( '2026-08-19' ); // a Wednesday
		Functions\expect( 'get_posts' )->andReturn( [] ); // no prior entries at all
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

		$term = new \WP_Term( [ 'term_id' => 1, 'name' => 'Cleaning' ] );
		$this->assertSame( '2026-08-21', $this->call_next_due_date_weekly( $term ) ); // Friday of this week
	}

	public function test_next_due_date_weekly_anchors_to_last_real_entry_plus_seven_days(): void {
		$last = new \WP_Post( [ 'ID' => 1, 'post_title' => 'Cleaning', 'post_date_gmt' => '2026-08-14 00:00:00' ] ); // Friday
		Functions\expect( 'get_posts' )->andReturn( [ $last ] );

		$term = new \WP_Term( [ 'term_id' => 1, 'name' => 'Cleaning' ] );
		$this->assertSame( '2026-08-21', $this->call_next_due_date_weekly( $term ) );
	}

	public function test_next_due_date_weekly_follows_drift_to_an_off_pattern_weekday(): void {
		// The real occurrence landed on a Saturday instead of the configured
		// Friday — the next one must keep following Saturdays (+7 days),
		// never snap back to Friday.
		$last = new \WP_Post( [ 'ID' => 1, 'post_title' => 'Cleaning', 'post_date_gmt' => '2026-08-15 00:00:00' ] ); // Saturday
		Functions\expect( 'get_posts' )->andReturn( [ $last ] );

		$term = new \WP_Term( [ 'term_id' => 1, 'name' => 'Cleaning' ] );
		$this->assertSame( '2026-08-22', $this->call_next_due_date_weekly( $term ) ); // following Saturday
	}
}
