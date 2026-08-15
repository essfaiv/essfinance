<?php
/**
 * Tests for ESSF_CLI's pure formatting functions (no WP_CLI runtime needed —
 * these are called as static methods, so the class is never instantiated
 * and WP_CLI::add_command() in the constructor never runs).
 *
 * @package EssFinance\Tests
 * @license GPL-2.0-or-later
 */

declare( strict_types=1 );

namespace EssFinance\Tests\Unit;

use PHPUnit\Framework\TestCase;

class CliTest extends TestCase {

	public function test_format_rows_maps_ofx_transactions_to_export_columns(): void {
		$rows = \ESSF_CLI::format_rows(
			[
				[
					'fitid'    => 'TXN001',
					'amount'   => -62.0,
					'due_date' => '2026-08-01',
					'name'     => '',
					'memo'     => 'Transferência enviada pelo Pix - Fulano de Tal',
				],
			]
		);

		$this->assertSame(
			[
				'Date'        => '2026-08-01',
				'Amount'      => '-62.00',
				'FITID'       => 'TXN001',
				'Description' => 'Transferência enviada pelo Pix - Fulano de Tal',
				'Raw Memo'    => 'Transferência enviada pelo Pix - Fulano de Tal',
			],
			$rows[0]
		);
	}

	public function test_format_rows_description_prefers_name_over_memo(): void {
		$rows = \ESSF_CLI::format_rows(
			[
				[
					'fitid'    => 'TXN002',
					'amount'   => 500.0,
					'due_date' => '2026-08-05',
					'name'     => 'Salary',
					'memo'     => 'Payroll deposit ref 123',
				],
			]
		);

		$this->assertSame( 'Salary', $rows[0]['Description'] );
		$this->assertSame( 'Payroll deposit ref 123', $rows[0]['Raw Memo'] );
	}

	public function test_format_csv_produces_header_and_rows(): void {
		$csv = \ESSF_CLI::format_csv(
			[
				[
					'Date'        => '2026-08-01',
					'Amount'      => '-62.00',
					'FITID'       => 'TXN001',
					'Description' => 'Fulano de Tal',
					'Raw Memo'    => 'Transferência enviada pelo Pix',
				],
			]
		);

		// fputcsv's quoting is an implementation detail (this PHP version
		// quotes any field containing whitespace) — assert on parsed content
		// via str_getcsv rather than the raw quoted string.
		$lines = explode( "\n", trim( $csv ) );
		$this->assertSame( [ 'Date', 'Amount', 'FITID', 'Description', 'Raw Memo' ], str_getcsv( $lines[0], ',', '"', '\\' ) );
		$this->assertSame(
			[ '2026-08-01', '-62.00', 'TXN001', 'Fulano de Tal', 'Transferência enviada pelo Pix' ],
			str_getcsv( $lines[1], ',', '"', '\\' )
		);
	}

	public function test_format_csv_quotes_values_containing_the_delimiter(): void {
		$csv = \ESSF_CLI::format_csv(
			[
				[
					'Date'        => '2026-08-01',
					'Amount'      => '10.00',
					'FITID'       => 'TXN003',
					'Description' => 'Loja, Filial 2',
					'Raw Memo'    => 'Loja, Filial 2',
				],
			]
		);

		$this->assertStringContainsString( '"Loja, Filial 2"', $csv );
	}

	public function test_format_csv_empty_rows_returns_empty_string(): void {
		$this->assertSame( '', \ESSF_CLI::format_csv( [] ) );
	}

	public function test_format_markdown_produces_gfm_table(): void {
		$md = \ESSF_CLI::format_markdown(
			[
				[
					'Date'        => '2026-08-01',
					'Amount'      => '-62.00',
					'FITID'       => 'TXN001',
					'Description' => 'Fulano de Tal',
					'Raw Memo'    => 'Transferência enviada pelo Pix',
				],
			]
		);

		$expected = "| Date | Amount | FITID | Description | Raw Memo |\n"
			. "| --- | --- | --- | --- | --- |\n"
			. "| 2026-08-01 | -62.00 | TXN001 | Fulano de Tal | Transferência enviada pelo Pix |\n";

		$this->assertSame( $expected, $md );
	}

	public function test_format_markdown_escapes_pipes_in_values(): void {
		$md = \ESSF_CLI::format_markdown(
			[
				[
					'Date'        => '2026-08-01',
					'Amount'      => '10.00',
					'FITID'       => 'TXN003',
					'Description' => 'A | B',
					'Raw Memo'    => 'Raw | Memo',
				],
			]
		);

		$this->assertStringContainsString( 'A \\| B', $md );
		$this->assertStringContainsString( 'Raw \\| Memo', $md );
	}

	public function test_format_markdown_empty_rows_returns_empty_string(): void {
		$this->assertSame( '', \ESSF_CLI::format_markdown( [] ) );
	}

	// ── predict_description() ────────────────────────────────────────────

	public function test_predict_description_prefers_learned_suggestion_over_parsed_fallback(): void {
		$prediction = \ESSF_CLI::predict_description(
			'Compra no débito - KOMPRAO KOCH ATACADIST',
			[
				[
					'memo'  => 'Compra no débito - KOMPRAO KOCH ATACADIST',
					'title' => 'Mercado',
					'date'  => '2026-01-05',
				],
			]
		);

		$this->assertSame( 'Mercado', $prediction['title'] );
		$this->assertSame( 'suggestion', $prediction['source'] );
		$this->assertSame( '100.0', $prediction['score'] );
	}

	public function test_predict_description_falls_back_to_parse_transfer(): void {
		$prediction = \ESSF_CLI::predict_description(
			'Transferência enviada pelo Pix - Maria Lucia Machado',
			[]
		);

		$this->assertSame( 'Transferência Maria Lucia Machado', $prediction['title'] );
		$this->assertSame( 'transfer', $prediction['source'] );
		$this->assertSame( '', $prediction['score'] );
	}

	public function test_predict_description_falls_back_to_parse_purchase(): void {
		$prediction = \ESSF_CLI::predict_description( 'Compra no débito - KOMPRAO KOCH ATACADIST', [] );

		$this->assertSame( 'Komprao Koch Atacadist', $prediction['title'] );
		$this->assertSame( 'purchase', $prediction['source'] );
		$this->assertSame( '', $prediction['score'] );
	}

	public function test_predict_description_falls_back_to_parse_boleto(): void {
		$prediction = \ESSF_CLI::predict_description( 'Pagamento de boleto efetuado - CELESC DISTRIBUICAO S A', [] );

		$this->assertSame( 'Celesc Distribuicao S A', $prediction['title'] );
		$this->assertSame( 'boleto', $prediction['source'] );
		$this->assertSame( '', $prediction['score'] );
	}

	public function test_predict_description_falls_back_to_raw_memo_when_nothing_matches(): void {
		$prediction = \ESSF_CLI::predict_description( 'Tarifa de manutenção de conta', [] );

		$this->assertSame( 'Tarifa de manutenção de conta', $prediction['title'] );
		$this->assertSame( 'fallback', $prediction['source'] );
		$this->assertSame( '', $prediction['score'] );
	}

	// ── replay_suggestions() ─────────────────────────────────────────────

	public function test_replay_suggestions_learns_from_earlier_manual_corrections(): void {
		$rows = \ESSF_CLI::replay_suggestions(
			[
				[
					'id'    => 101,
					'date'  => '2026-01-05',
					'title' => 'Mercado',
					'memo'  => 'Compra no débito - KOMPRAO KOCH ATACADIST',
				],
				[
					'id'    => 102,
					'date'  => '2026-02-10',
					'title' => 'Mercado',
					'memo'  => 'Compra no débito - KOMPRAO KOCH ATACADIST',
				],
			]
		);

		// First occurrence: nothing in history yet, so the prediction falls
		// back to the raw merchant name — it doesn't match the title the
		// user actually chose, i.e. this row required a manual edit.
		$this->assertSame( 'Komprao Koch Atacadist', $rows[0]['Predicted'] );
		$this->assertSame( 'purchase', $rows[0]['Source'] );
		$this->assertSame( 'no', $rows[0]['Auto-fill'] );

		// Second occurrence: the first row's correction is now in history,
		// so the learned suggestion wins and matches what was actually
		// chosen — this row would have been auto-filled correctly.
		$this->assertSame( 'Mercado', $rows[1]['Predicted'] );
		$this->assertSame( 'suggestion', $rows[1]['Source'] );
		$this->assertSame( 'yes', $rows[1]['Auto-fill'] );
	}

	public function test_replay_suggestions_includes_every_row_regardless_of_match(): void {
		$rows = \ESSF_CLI::replay_suggestions(
			[
				[
					'id'    => 1,
					'date'  => '2026-01-01',
					'title' => 'Salário',
					'memo'  => 'Salary',
				],
			]
		);

		$this->assertCount( 1, $rows );
		$this->assertSame( 1, $rows[0]['ID'] );
		$this->assertSame( '2026-01-01', $rows[0]['Date'] );
		$this->assertSame( 'Salário', $rows[0]['Title'] );
		$this->assertSame( 'Salary', $rows[0]['Raw Memo'] );
	}
}
