<?php
/**
 * Tests for ESSF_Loan_CPT (find_own_entries, sync_principal_from_entries, renumber_and_sync).
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
use WP_Term;

class LoanTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function make_post( array $data ): WP_Post {
		return new WP_Post(
			array_merge(
				[
					'ID'                => 1,
					'post_title'        => 'Empréstimo Lucia',
					'post_content'      => '210',
					'post_status'       => 'pending',
					'post_date_gmt'     => '2026-08-01 00:00:00',
					'post_modified_gmt' => '0000-00-00 00:00:00',
				],
				$data
			)
		);
	}

	private function call_find_own_entries( WP_Term $term ): array {
		$method = new ReflectionMethod( \ESSF_Loan_CPT::class, 'find_own_entries' );
		$method->setAccessible( true );
		return $method->invoke( null, $term );
	}

	/** Installs a fresh $wpdb spy so renumber_and_sync()'s renames (via $wpdb->update(), never wp_update_post()) can be asserted on. */
	private function fresh_wpdb(): \wpdb {
		global $wpdb;
		$wpdb = new \wpdb();
		return $wpdb;
	}

	/** @return array<int, string> post ID => renamed post_title, from every $wpdb->update() call the test observed. */
	private function renamed_titles( \wpdb $wpdb ): array {
		$renamed = [];
		foreach ( $wpdb->update_calls as $call ) {
			$renamed[ $call['where']['ID'] ] = $call['data']['post_title'];
		}
		return $renamed;
	}

	// ── find_own_entries() ────────────────────────────────────────────────

	public function test_find_own_entries_returns_exact_title_match(): void {
		$term  = new WP_Term( [ 'term_id' => 1, 'name' => 'Empréstimo Lucia' ] );
		$exact = [ $this->make_post( [ 'ID' => 10, 'post_title' => 'Empréstimo Lucia' ] ) ];

		Functions\expect( 'get_posts' )
			->andReturnUsing( static function ( array $args ) use ( $exact ) {
				if ( isset( $args['title'] ) ) {
					return $exact;
				}
				return []; // fuzzy 's' candidate search — nothing else out there
			} );

		$entries = $this->call_find_own_entries( $term );
		$this->assertCount( 1, $entries );
		$this->assertSame( 10, $entries[0]->ID );
	}

	public function test_find_own_entries_recognizes_numbered_suffix(): void {
		$term       = new WP_Term( [ 'term_id' => 1, 'name' => 'Empréstimo Lucia 202608Q1' ] );
		$suffixed_1 = $this->make_post( [ 'ID' => 11, 'post_title' => 'Empréstimo Lucia 202608Q1 1/2' ] );
		$suffixed_2 = $this->make_post( [ 'ID' => 12, 'post_title' => 'Empréstimo Lucia 202608Q1 2/2' ] );

		Functions\expect( 'get_posts' )
			->andReturnUsing( static function ( array $args ) use ( $suffixed_1, $suffixed_2 ) {
				if ( isset( $args['title'] ) ) {
					return []; // no plain unsuffixed entry left
				}
				return [ $suffixed_1, $suffixed_2 ];
			} );

		$entries = $this->call_find_own_entries( $term );
		$ids     = array_map( static fn( $p ) => $p->ID, $entries );
		sort( $ids );
		$this->assertSame( [ 11, 12 ], $ids );
	}

	public function test_find_own_entries_ignores_unrelated_similarly_worded_title(): void {
		$term      = new WP_Term( [ 'term_id' => 1, 'name' => 'Empréstimo Lucia' ] );
		$unrelated = $this->make_post( [ 'ID' => 13, 'post_title' => 'Empréstimo Lucia Extra 1/2' ] );

		Functions\expect( 'get_posts' )
			->andReturnUsing( static function ( array $args ) use ( $unrelated ) {
				if ( isset( $args['title'] ) ) {
					return [];
				}
				return [ $unrelated ]; // fuzzy search surfaces it as a candidate...
			} );

		// ...but the exact-suffix regex must reject it: "Empréstimo Lucia Extra 1/2"
		// does not match "^Empréstimo Lucia\s+\d+/\d+$".
		$entries = $this->call_find_own_entries( $term );
		$this->assertCount( 0, $entries );
	}

	public function test_find_own_entries_deduplicates_entry_found_both_ways(): void {
		$term  = new WP_Term( [ 'term_id' => 1, 'name' => 'Empréstimo Lucia' ] );
		$post  = $this->make_post( [ 'ID' => 14, 'post_title' => 'Empréstimo Lucia' ] );

		Functions\expect( 'get_posts' )->andReturn( [ $post ] );

		$entries = $this->call_find_own_entries( $term );
		$this->assertCount( 1, $entries );
	}

	// ── sync_principal_from_entries() ───────────────────────────────────────

	public function test_sync_principal_bootstraps_from_zero_by_summing_everything(): void {
		$term = new WP_Term( [ 'term_id' => 1, 'name' => 'Empréstimo Lucia 202608Q1' ] );
		$a    = $this->make_post( [ 'ID' => 21, 'post_content' => '210' ] );
		$b    = $this->make_post( [ 'ID' => 22, 'post_content' => '600' ] );

		Functions\expect( 'get_posts' )->andReturn( [ $a, $b ] );
		Functions\expect( 'get_term_meta' )
			->with( 1, \ESSF_Loan_CPT::META_PRINCIPAL, true )
			->andReturn( '' ); // never set — stored principal reads as 0.0

		Functions\expect( 'update_term_meta' )
			->once()
			->with( 1, \ESSF_Loan_CPT::META_PRINCIPAL, '810' );

		\ESSF_Loan_CPT::sync_principal_from_entries( $term );
		$this->addToAssertionCount( 1 );
	}

	public function test_sync_principal_on_existing_term_only_resums_origin_side(): void {
		$term    = new WP_Term( [ 'term_id' => 2, 'name' => 'Lucia' ] );
		$origin  = $this->make_post( [ 'ID' => 31, 'post_content' => '1000' ] );
		$payment = $this->make_post( [ 'ID' => 32, 'post_content' => '-300' ] );

		Functions\expect( 'get_posts' )->andReturn( [ $origin, $payment ] );
		Functions\expect( 'get_term_meta' )
			->with( 2, \ESSF_Loan_CPT::META_PRINCIPAL, true )
			->andReturn( '1000' ); // already established, positive (lender) convention

		Functions\expect( 'update_term_meta' )
			->once()
			->with( 2, \ESSF_Loan_CPT::META_PRINCIPAL, '1000' ); // payment must not be netted in

		\ESSF_Loan_CPT::sync_principal_from_entries( $term );
		$this->addToAssertionCount( 1 );
	}

	// ── renumber_and_sync() ──────────────────────────────────────────────

	public function test_renumber_and_sync_leaves_single_entry_untouched(): void {
		$term  = new WP_Term( [ 'term_id' => 1, 'name' => 'Empréstimo Lucia' ] );
		$post  = $this->make_post( [ 'ID' => 40, 'post_title' => 'Empréstimo Lucia' ] );
		$wpdb  = $this->fresh_wpdb();

		Functions\expect( 'get_posts' )->andReturn( [ $post ] );
		Functions\expect( 'get_term_meta' )->never();
		Functions\expect( 'update_term_meta' )->never();

		\ESSF_Loan_CPT::renumber_and_sync( $term );

		$this->assertSame( [], $wpdb->update_calls );
	}

	public function test_renumber_and_sync_numbers_two_entries_by_date_order(): void {
		$term    = new WP_Term( [ 'term_id' => 1, 'name' => 'Empréstimo Lucia 202608Q1' ] );
		$earlier = $this->make_post(
			[
				'ID'            => 41,
				'post_title'    => 'Empréstimo Lucia 202608Q1',
				'post_content'  => '210',
				'post_date_gmt' => '2026-08-01 00:00:00',
			]
		);
		$later   = $this->make_post(
			[
				'ID'            => 42,
				'post_title'    => 'Empréstimo Lucia 202608Q1',
				'post_content'  => '600',
				'post_date_gmt' => '2026-08-15 00:00:00',
			]
		);

		Functions\expect( 'get_posts' )->andReturn( [ $later, $earlier ] ); // deliberately out of order
		$wpdb = $this->fresh_wpdb();

		Functions\expect( 'get_term_meta' )
			->with( 1, \ESSF_Loan_CPT::META_PRINCIPAL, true )
			->andReturn( '' );
		Functions\expect( 'update_term_meta' )->once();

		\ESSF_Loan_CPT::renumber_and_sync( $term );
		$renamed = $this->renamed_titles( $wpdb );

		$this->assertSame( 'Empréstimo Lucia 202608Q1 1/2', $renamed[41] );
		$this->assertSame( 'Empréstimo Lucia 202608Q1 2/2', $renamed[42] );
		foreach ( $wpdb->update_calls as $call ) {
			$this->assertSame( [ 'post_title' ], array_keys( $call['data'] ), 'Rename must only touch post_title — never post_modified_gmt (Pay Date)' );
		}
	}

	public function test_renumber_and_sync_numbers_origin_and_payments_independently(): void {
		$term      = new WP_Term( [ 'term_id' => 1, 'name' => 'Empréstimo Lucia' ] );
		$origin_1  = $this->make_post(
			[
				'ID'            => 61,
				'post_title'    => 'Empréstimo Lucia',
				'post_content'  => '210',
				'post_date_gmt' => '2026-08-01 00:00:00',
			]
		);
		$origin_2  = $this->make_post(
			[
				'ID'            => 62,
				'post_title'    => 'Empréstimo Lucia',
				'post_content'  => '600',
				'post_date_gmt' => '2026-08-15 00:00:00',
			]
		);
		$payment_1 = $this->make_post(
			[
				'ID'                => 63,
				'post_title'        => 'Empréstimo Lucia',
				'post_content'      => '-100',
				'post_status'       => 'paid',
				'post_date_gmt'     => '2026-08-10 00:00:00',
				'post_modified_gmt' => '2026-08-10 00:00:00',
			]
		);
		$payment_2 = $this->make_post(
			[
				'ID'                => 64,
				'post_title'        => 'Empréstimo Lucia',
				'post_content'      => '-200',
				'post_status'       => 'paid',
				'post_date_gmt'     => '2026-08-20 00:00:00',
				'post_modified_gmt' => '2026-08-20 00:00:00',
			]
		);

		Functions\expect( 'get_posts' )->andReturn( [ $payment_2, $payment_1, $origin_2, $origin_1 ] );
		$wpdb = $this->fresh_wpdb();

		Functions\expect( 'get_term_meta' )
			->with( 1, \ESSF_Loan_CPT::META_PRINCIPAL, true )
			->andReturn( '' );
		Functions\expect( 'update_term_meta' )->once()->with( 1, \ESSF_Loan_CPT::META_PRINCIPAL, '810' );

		\ESSF_Loan_CPT::renumber_and_sync( $term );
		$renamed = $this->renamed_titles( $wpdb );

		$this->assertCount( 4, $wpdb->update_calls, 'origin (2) and payments (2) each numbered 1/2, 2/2 — never 1/4..4/4' );
		$this->assertSame( 'Empréstimo Lucia 1/2', $renamed[61] );
		$this->assertSame( 'Empréstimo Lucia 2/2', $renamed[62] );
		$this->assertSame( 'Empréstimo Lucia 1/2', $renamed[63] );
		$this->assertSame( 'Empréstimo Lucia 2/2', $renamed[64] );
	}

	public function test_renumber_and_sync_leaves_lone_payment_unsuffixed(): void {
		$term     = new WP_Term( [ 'term_id' => 1, 'name' => 'Empréstimo Lucia' ] );
		$origin_1 = $this->make_post(
			[
				'ID'            => 65,
				'post_title'    => 'Empréstimo Lucia',
				'post_content'  => '210',
				'post_date_gmt' => '2026-08-01 00:00:00',
			]
		);
		$origin_2 = $this->make_post(
			[
				'ID'            => 66,
				'post_title'    => 'Empréstimo Lucia',
				'post_content'  => '600',
				'post_date_gmt' => '2026-08-15 00:00:00',
			]
		);
		$payment  = $this->make_post(
			[
				'ID'                => 67,
				'post_title'        => 'Empréstimo Lucia',
				'post_content'      => '-300',
				'post_status'       => 'paid',
				'post_date_gmt'     => '2026-08-10 00:00:00',
				'post_modified_gmt' => '2026-08-20 00:00:00',
			]
		);

		Functions\expect( 'get_posts' )->andReturn( [ $payment, $origin_2, $origin_1 ] );
		$wpdb = $this->fresh_wpdb();

		Functions\expect( 'get_term_meta' )
			->with( 1, \ESSF_Loan_CPT::META_PRINCIPAL, true )
			->andReturn( '' );
		Functions\expect( 'update_term_meta' )->once()->with( 1, \ESSF_Loan_CPT::META_PRINCIPAL, '810' );

		\ESSF_Loan_CPT::renumber_and_sync( $term );
		$renamed = $this->renamed_titles( $wpdb );

		$this->assertCount( 2, $wpdb->update_calls, 'only the two origin entries — the lone payment has no pair to number against' );
		$this->assertSame( 'Empréstimo Lucia 1/2', $renamed[65] );
		$this->assertSame( 'Empréstimo Lucia 2/2', $renamed[66] );
		$this->assertArrayNotHasKey( 67, $renamed, 'A lone payment with no pair stays unsuffixed' );
	}

	// ── sum_realized_payments() ─────────────────────────────────────────────

	private function call_sum_realized_payments( array $payments ): float {
		$method = new ReflectionMethod( \ESSF_Loan_CPT::class, 'sum_realized_payments' );
		$method->setAccessible( true );
		return $method->invoke( null, $payments );
	}

	public function test_sum_realized_payments_only_counts_paid_status(): void {
		$paid    = $this->make_post( [ 'ID' => 51, 'post_status' => 'paid', 'post_content' => '-300' ] );
		$pending = $this->make_post( [ 'ID' => 52, 'post_status' => 'pending', 'post_content' => '-450' ] );

		$this->assertSame( 300.0, $this->call_sum_realized_payments( [ $paid, $pending ] ) );
	}

	public function test_sum_realized_payments_returns_zero_when_all_pending(): void {
		$pending = $this->make_post( [ 'ID' => 53, 'post_status' => 'pending', 'post_content' => '-450' ] );

		$this->assertSame( 0.0, $this->call_sum_realized_payments( [ $pending ] ) );
	}

	// ── sort_entries() ──────────────────────────────────────────────────────

	private function call_sort_entries( array $entries ): array {
		$method = new ReflectionMethod( \ESSF_Loan_CPT::class, 'sort_entries' );
		$method->setAccessible( true );
		return $method->invoke( null, $entries );
	}

	public function test_sort_entries_orders_by_date_ascending(): void {
		$later   = $this->make_post( [ 'ID' => 71, 'post_status' => 'pending', 'post_date_gmt' => '2026-08-15 00:00:00' ] );
		$earlier = $this->make_post( [ 'ID' => 72, 'post_status' => 'pending', 'post_date_gmt' => '2026-08-01 00:00:00' ] );

		$sorted = $this->call_sort_entries( [ $later, $earlier ] );

		$this->assertSame( [ 72, 71 ], array_map( static fn( $p ) => $p->ID, $sorted ) );
	}

	public function test_sort_entries_breaks_same_date_tie_by_id_ascending(): void {
		$higher_id = $this->make_post( [ 'ID' => 82, 'post_status' => 'pending', 'post_date_gmt' => '2026-08-10 00:00:00' ] );
		$lower_id  = $this->make_post( [ 'ID' => 81, 'post_status' => 'pending', 'post_date_gmt' => '2026-08-10 00:00:00' ] );

		$sorted = $this->call_sort_entries( [ $higher_id, $lower_id ] );

		$this->assertSame( [ 81, 82 ], array_map( static fn( $p ) => $p->ID, $sorted ) );
	}

	public function test_sort_entries_uses_pay_date_for_paid_entries(): void {
		// Paid: sorted by post_modified_gmt (pay date). Pending: sorted by post_date_gmt (due date).
		$paid_but_later_due_date = $this->make_post(
			[
				'ID'                => 91,
				'post_status'       => 'paid',
				'post_date_gmt'     => '2026-08-20 00:00:00',
				'post_modified_gmt' => '2026-08-05 00:00:00',
			]
		);
		$pending = $this->make_post( [ 'ID' => 92, 'post_status' => 'pending', 'post_date_gmt' => '2026-08-10 00:00:00' ] );

		$sorted = $this->call_sort_entries( [ $pending, $paid_but_later_due_date ] );

		$this->assertSame( [ 91, 92 ], array_map( static fn( $p ) => $p->ID, $sorted ) );
	}
}
