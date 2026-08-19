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
		$term = new WP_Term( [ 'term_id' => 1, 'name' => 'Empréstimo Lucia' ] );
		$post = $this->make_post( [ 'ID' => 40, 'post_title' => 'Empréstimo Lucia' ] );

		Functions\expect( 'get_posts' )->andReturn( [ $post ] );
		Functions\expect( 'wp_update_post' )->never();
		Functions\expect( 'get_term_meta' )->never();
		Functions\expect( 'update_term_meta' )->never();

		\ESSF_Loan_CPT::renumber_and_sync( $term );
		$this->addToAssertionCount( 1 );
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

		$renamed = [];
		Functions\expect( 'wp_update_post' )
			->twice()
			->andReturnUsing( static function ( array $args ) use ( &$renamed ) {
				$renamed[ $args['ID'] ] = $args['post_title'];
				return $args['ID'];
			} );

		Functions\expect( 'get_term_meta' )
			->with( 1, \ESSF_Loan_CPT::META_PRINCIPAL, true )
			->andReturn( '' );
		Functions\expect( 'update_term_meta' )->once();

		\ESSF_Loan_CPT::renumber_and_sync( $term );

		$this->assertSame( 'Empréstimo Lucia 202608Q1 1/2', $renamed[41] );
		$this->assertSame( 'Empréstimo Lucia 202608Q1 2/2', $renamed[42] );
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
}
