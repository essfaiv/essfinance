<?php
/**
 * Tests for ESSF_Admin_Page's pure OFX staging/confirm helpers — the
 * transient-staging and confirm-merge logic factored out so it's testable
 * without mocking WordPress (get_transient/set_transient/exit are only
 * touched by the thin handler methods around these, which — like every
 * other admin-post handler in this class — aren't unit tested).
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

class AdminPageOfxStagingTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function call_remember_excluded_memos( array $excluded_rows ): void {
		$method = new ReflectionMethod( \ESSF_Admin_Page::class, 'remember_excluded_memos' );
		$method->setAccessible( true );
		$method->invoke( $this->make_admin_page(), $excluded_rows );
	}

	private function call_backfill_duplicate_memos( array $excluded_rows ): void {
		$method = new ReflectionMethod( \ESSF_Admin_Page::class, 'backfill_duplicate_memos' );
		$method->setAccessible( true );
		$method->invoke( $this->make_admin_page(), $excluded_rows );
	}

	private function make_admin_page(): \ESSF_Admin_Page {
		Functions\expect( 'add_action' )->zeroOrMoreTimes();
		Functions\expect( 'add_filter' )->zeroOrMoreTimes();
		return new \ESSF_Admin_Page();
	}

	// ── build_ofx_stage_rows() ───────────────────────────────────────────

	public function test_build_stage_rows_skips_known_fitid(): void {
		$transactions = [
			[ 'fitid' => 'TXN001', 'amount' => -50.0, 'due_date' => '2026-01-05', 'name' => '', 'memo' => 'Compra' ],
		];

		$result = \ESSF_Admin_Page::build_ofx_stage_rows( $transactions, [], [ 'TXN001' => true ] );

		$this->assertSame( [], $result['rows'] );
		$this->assertSame( 1, $result['skipped'] );
	}

	public function test_build_stage_rows_skips_exact_description_date_amount_match(): void {
		$transactions = [
			[ 'fitid' => '', 'amount' => -50.0, 'due_date' => '2026-01-05', 'name' => 'Aluguel', 'memo' => '' ],
		];
		$existing = [ 'aluguel|2026-01-05|-50' => true ];

		$result = \ESSF_Admin_Page::build_ofx_stage_rows( $transactions, $existing, [] );

		$this->assertSame( [], $result['rows'] );
		$this->assertSame( 1, $result['skipped'] );
	}

	public function test_build_stage_rows_keeps_new_transaction_with_resolved_description(): void {
		$transactions = [
			[ 'fitid' => 'TXN002', 'amount' => -62.0, 'due_date' => '2026-08-01', 'name' => '', 'memo' => 'Pix enviado' ],
		];

		$result = \ESSF_Admin_Page::build_ofx_stage_rows( $transactions, [], [] );

		$this->assertCount( 1, $result['rows'] );
		$this->assertSame( 'Pix enviado', $result['rows'][0]['description'] );
		$this->assertSame( 'TXN002', $result['rows'][0]['fitid'] );
		$this->assertSame( -62.0, $result['rows'][0]['amount'] );
		$this->assertNull( $result['rows'][0]['possible_duplicate'] );
	}

	public function test_build_stage_rows_defaults_category_to_uncategorized(): void {
		$transactions = [
			[ 'fitid' => 'TXN020', 'amount' => -20.0, 'due_date' => '2026-08-01', 'name' => '', 'memo' => 'Pix enviado' ],
		];

		$result = \ESSF_Admin_Page::build_ofx_stage_rows( $transactions, [], [] );

		$this->assertSame( 'uncategorized', $result['rows'][0]['category'] );
	}

	public function test_build_stage_rows_resolves_description_via_parse_boleto(): void {
		$transactions = [
			[
				'fitid'    => 'TXN010',
				'amount'   => -150.0,
				'due_date' => '2026-08-10',
				'name'     => '',
				'memo'     => 'Pagamento de boleto efetuado - CELESC DISTRIBUICAO S A',
			],
		];

		$result = \ESSF_Admin_Page::build_ofx_stage_rows( $transactions, [], [] );

		$this->assertCount( 1, $result['rows'] );
		$this->assertSame( 'Celesc Distribuicao S A', $result['rows'][0]['description'] );
	}

	public function test_build_stage_rows_flags_possible_duplicate_by_amount_and_date(): void {
		$transactions = [
			[ 'fitid' => 'TXN003', 'amount' => -100.0, 'due_date' => '2026-08-02', 'name' => '', 'memo' => 'Pix - Fulano' ],
		];
		$amount_index = [
			'-100' => [ [ 'id' => 42, 'date' => '2026-08-01', 'title' => 'Empréstimo Fulano' ] ],
		];

		$result = \ESSF_Admin_Page::build_ofx_stage_rows( $transactions, [], [], $amount_index );

		$this->assertCount( 1, $result['rows'] );
		$this->assertSame( 'Empréstimo Fulano', $result['rows'][0]['possible_duplicate']['title'] );
		$this->assertSame( 42, $result['rows'][0]['possible_duplicate']['id'] );
	}

	public function test_build_stage_rows_flags_suggested_exclude_from_learned_memos(): void {
		$transactions = [
			[ 'fitid' => 'TXN004', 'amount' => -5.0, 'due_date' => '2026-08-02', 'name' => '', 'memo' => 'Saldo do dia' ],
		];
		$excluded_memos = [ \ESSF_OFX_Suggestions::normalize( 'Saldo do dia anterior' ) ];

		$result = \ESSF_Admin_Page::build_ofx_stage_rows( $transactions, [], [], [], $excluded_memos );

		$this->assertTrue( $result['rows'][0]['suggested_exclude'] );
	}

	public function test_build_stage_rows_does_not_flag_suggested_exclude_when_dissimilar(): void {
		$transactions = [
			[ 'fitid' => 'TXN005', 'amount' => -5.0, 'due_date' => '2026-08-02', 'name' => '', 'memo' => 'Compra no débito - Padaria' ],
		];
		$excluded_memos = [ \ESSF_OFX_Suggestions::normalize( 'Saldo do dia anterior' ) ];

		$result = \ESSF_Admin_Page::build_ofx_stage_rows( $transactions, [], [], [], $excluded_memos );

		$this->assertFalse( $result['rows'][0]['suggested_exclude'] );
	}

	// ── find_possible_duplicate() ────────────────────────────────────────

	public function test_find_possible_duplicate_matches_within_tolerance(): void {
		$amount_index = [ '-100' => [ [ 'date' => '2026-08-01', 'title' => 'Existing' ] ] ];
		$match         = \ESSF_Admin_Page::find_possible_duplicate( -100.0, '2026-08-03', $amount_index, 2 );

		$this->assertNotNull( $match );
		$this->assertSame( 'Existing', $match['title'] );
	}

	public function test_find_possible_duplicate_null_outside_tolerance(): void {
		$amount_index = [ '-100' => [ [ 'date' => '2026-08-01', 'title' => 'Existing' ] ] ];
		$this->assertNull( \ESSF_Admin_Page::find_possible_duplicate( -100.0, '2026-08-10', $amount_index, 2 ) );
	}

	public function test_find_possible_duplicate_null_when_amount_not_indexed(): void {
		$this->assertNull( \ESSF_Admin_Page::find_possible_duplicate( -50.0, '2026-08-01', [] ) );
	}

	// ── resolve_ofx_confirm_rows() ───────────────────────────────────────

	public function test_resolve_confirm_rows_excludes_unchecked_rows(): void {
		$staged = [ [ 'description' => 'Loja A', 'amount' => -10.0 ] ];
		$posted = []; // No 'include' key posted — the row was toggled off.

		$result = \ESSF_Admin_Page::resolve_ofx_confirm_rows( $staged, $posted );

		$this->assertSame( [], $result['insert'] );
		$this->assertSame( 1, $result['skipped'] );
		$this->assertSame( $staged, $result['excluded'] );
	}

	public function test_resolve_confirm_rows_uses_edited_description(): void {
		$staged = [ [ 'description' => 'Pix enviado', 'amount' => -10.0, 'due_date' => '2026-08-01', 'fitid' => '', 'name' => '', 'memo' => 'Pix enviado' ] ];
		$posted = [ [ 'include' => '1', 'description' => 'Aluguel Ago/26' ] ];

		$result = \ESSF_Admin_Page::resolve_ofx_confirm_rows( $staged, $posted );

		$this->assertCount( 1, $result['insert'] );
		$this->assertSame( 'Aluguel Ago/26', $result['insert'][0]['description'] );
	}

	public function test_resolve_confirm_rows_falls_back_to_staged_description_when_blank(): void {
		$staged = [ [ 'description' => 'Pix enviado', 'amount' => -10.0 ] ];
		$posted = [ [ 'include' => '1', 'description' => '   ' ] ];

		$result = \ESSF_Admin_Page::resolve_ofx_confirm_rows( $staged, $posted );

		$this->assertSame( 'Pix enviado', $result['insert'][0]['description'] );
	}

	public function test_resolve_confirm_rows_uses_posted_category(): void {
		$staged = [ [ 'description' => 'Pix enviado', 'amount' => -10.0, 'category' => 'uncategorized' ] ];
		$posted = [ [ 'include' => '1', 'description' => 'Pix enviado', 'category' => 'groceries' ] ];

		$result = \ESSF_Admin_Page::resolve_ofx_confirm_rows( $staged, $posted );

		$this->assertSame( 'groceries', $result['insert'][0]['category'] );
	}

	public function test_resolve_confirm_rows_falls_back_to_staged_category_when_blank(): void {
		$staged = [ [ 'description' => 'Pix enviado', 'amount' => -10.0, 'category' => 'groceries' ] ];
		$posted = [ [ 'include' => '1', 'description' => 'Pix enviado', 'category' => '' ] ];

		$result = \ESSF_Admin_Page::resolve_ofx_confirm_rows( $staged, $posted );

		$this->assertSame( 'groceries', $result['insert'][0]['category'] );
	}

	public function test_resolve_confirm_rows_falls_back_to_uncategorized_when_staged_row_has_no_category(): void {
		$staged = [ [ 'description' => 'Pix enviado', 'amount' => -10.0 ] ];
		$posted = [ [ 'include' => '1', 'description' => 'Pix enviado' ] ];

		$result = \ESSF_Admin_Page::resolve_ofx_confirm_rows( $staged, $posted );

		$this->assertSame( 'uncategorized', $result['insert'][0]['category'] );
	}

	public function test_resolve_confirm_rows_round_trip_matches_staged_count_minus_excluded(): void {
		$staged = [
			[ 'description' => 'A', 'amount' => -10.0 ],
			[ 'description' => 'B', 'amount' => -20.0 ],
			[ 'description' => 'C', 'amount' => -30.0 ],
		];
		$posted = [
			0 => [ 'include' => '1', 'description' => 'A' ],
			// Index 1 (B) omitted entirely — excluded.
			2 => [ 'include' => '1', 'description' => 'C' ],
		];

		$result = \ESSF_Admin_Page::resolve_ofx_confirm_rows( $staged, $posted );

		$this->assertCount( 2, $result['insert'] );
		$this->assertSame( 1, $result['skipped'] );
		$this->assertSame( [ $staged[1] ], $result['excluded'] );
	}

	// ── remember_excluded_memos() ────────────────────────────────────────

	public function test_remember_excluded_memos_appends_normalized_memo(): void {
		Functions\expect( 'get_option' )
			->once()
			->with( \ESSF_Admin_Page::EXCLUDED_MEMOS_OPTION, [] )
			->andReturn( [] );

		$expected = \ESSF_OFX_Suggestions::normalize( 'Saldo do dia' );

		Functions\expect( 'update_option' )
			->once()
			->with( \ESSF_Admin_Page::EXCLUDED_MEMOS_OPTION, [ $expected ], false );

		$this->call_remember_excluded_memos( [ [ 'name' => '', 'memo' => 'Saldo do dia' ] ] );
		$this->addToAssertionCount( 1 );
	}

	public function test_remember_excluded_memos_does_not_duplicate_existing_entry(): void {
		$existing = \ESSF_OFX_Suggestions::normalize( 'Saldo do dia' );

		Functions\expect( 'get_option' )->once()->andReturn( [ $existing ] );
		Functions\expect( 'update_option' )->once()->with( \ESSF_Admin_Page::EXCLUDED_MEMOS_OPTION, [ $existing ], false );

		$this->call_remember_excluded_memos( [ [ 'name' => '', 'memo' => 'Saldo do dia' ] ] );
		$this->addToAssertionCount( 1 );
	}

	public function test_remember_excluded_memos_noop_when_nothing_excluded(): void {
		Functions\expect( 'get_option' )->never();
		Functions\expect( 'update_option' )->never();

		$this->call_remember_excluded_memos( [] );
		$this->addToAssertionCount( 1 );
	}

	public function test_remember_excluded_memos_caps_list_size(): void {
		$existing = array_map(
			static function ( int $i ): string {
				return 'memo-' . $i;
			},
			range( 1, \ESSF_Admin_Page::EXCLUDED_MEMOS_LIMIT )
		);

		Functions\expect( 'get_option' )->once()->andReturn( $existing );
		Functions\expect( 'update_option' )
			->once()
			->with(
				\ESSF_Admin_Page::EXCLUDED_MEMOS_OPTION,
				\Mockery::on(
					static function ( array $memos ): bool {
						return count( $memos ) === \ESSF_Admin_Page::EXCLUDED_MEMOS_LIMIT
							&& end( $memos ) === \ESSF_OFX_Suggestions::normalize( 'Novo saldo' );
					}
				),
				false
			);

		$this->call_remember_excluded_memos( [ [ 'name' => '', 'memo' => 'Novo saldo' ] ] );
		$this->addToAssertionCount( 1 );
	}

	// ── backfill_duplicate_memos() ───────────────────────────────────────

	public function test_backfill_duplicate_memos_tags_the_matched_post(): void {
		Functions\expect( 'get_post_meta' )
			->once()
			->with( 42, '_essf_ofx_memo', true )
			->andReturn( '' );

		Functions\expect( 'update_post_meta' )
			->once()
			->with( 42, '_essf_ofx_memo', 'Pix - Fulano de Tal' );

		$this->call_backfill_duplicate_memos(
			[
				[
					'name'               => '',
					'memo'               => 'Pix - Fulano de Tal',
					'possible_duplicate' => [ 'id' => 42, 'title' => 'Empréstimo Fulano', 'date' => '2026-08-01' ],
				],
			]
		);
		$this->addToAssertionCount( 1 );
	}

	public function test_backfill_duplicate_memos_skips_when_already_tagged(): void {
		Functions\expect( 'get_post_meta' )->once()->andReturn( 'Existing raw memo' );
		Functions\expect( 'update_post_meta' )->never();

		$this->call_backfill_duplicate_memos(
			[
				[
					'name'               => '',
					'memo'               => 'Pix - Fulano de Tal',
					'possible_duplicate' => [ 'id' => 42, 'title' => 'Empréstimo Fulano', 'date' => '2026-08-01' ],
				],
			]
		);
		$this->addToAssertionCount( 1 );
	}

	public function test_backfill_duplicate_memos_skips_rows_without_a_duplicate_match(): void {
		Functions\expect( 'get_post_meta' )->never();
		Functions\expect( 'update_post_meta' )->never();

		$this->call_backfill_duplicate_memos(
			[
				[ 'name' => '', 'memo' => 'Saldo do dia', 'possible_duplicate' => null ],
			]
		);
		$this->addToAssertionCount( 1 );
	}

	public function test_backfill_duplicate_memos_skips_blank_memo(): void {
		Functions\expect( 'get_post_meta' )->never();
		Functions\expect( 'update_post_meta' )->never();

		$this->call_backfill_duplicate_memos(
			[
				[ 'name' => '', 'memo' => '', 'possible_duplicate' => [ 'id' => 42, 'title' => 'X', 'date' => '2026-08-01' ] ],
			]
		);
		$this->addToAssertionCount( 1 );
	}
}
