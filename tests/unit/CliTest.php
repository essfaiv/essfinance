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

	// ── predict_category() ───────────────────────────────────────────────

	public function test_predict_category_prefers_learned_suggestion(): void {
		$prediction = \ESSF_CLI::predict_category(
			'Compra no débito - KOMPRAO KOCH ATACADIST',
			'Komprao Koch Atacadist',
			[
				[
					'memo'  => 'Compra no débito - KOMPRAO KOCH ATACADIST',
					'title' => 'Groceries',
					'date'  => '2026-01-01',
				],
			]
		);

		$this->assertSame( 'Groceries', $prediction['title'] );
		$this->assertSame( 'suggestion', $prediction['source'] );
	}

	public function test_predict_category_falls_back_to_keyword_guess_when_no_suggestion(): void {
		$prediction = \ESSF_CLI::predict_category( 'Juros cartão de crédito', 'Juros cartão de crédito', [] );

		$this->assertSame( 'Financial fees', $prediction['title'] );
		$this->assertSame( 'keyword', $prediction['source'] );
	}

	public function test_predict_category_skips_glossary_and_falls_to_keyword_when_glossary_history_empty(): void {
		// Positive glossary-match coverage lives in CategoryTest (it needs
		// get_terms() mocked to resolve the matched name back to a term,
		// which this pure/mockless test file deliberately avoids) — this
		// just confirms the new $glossary_history param defaults safely and
		// the priority chain still reaches 'keyword' when it's empty.
		$prediction = \ESSF_CLI::predict_category( 'Juros cartão de crédito', 'Juros cartão de crédito', [], [] );

		$this->assertSame( 'Financial fees', $prediction['title'] );
		$this->assertSame( 'keyword', $prediction['source'] );
	}

	public function test_predict_category_falls_back_to_uncategorized_when_nothing_matches(): void {
		$prediction = \ESSF_CLI::predict_category( 'Trimania', 'Trimania', [] );

		$this->assertSame( 'Uncategorized', $prediction['title'] );
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

	public function test_replay_suggestions_learns_category_from_earlier_manual_corrections(): void {
		// "Loja Xyz Center" matches none of ESSF_Category's keyword rules, so
		// the first occurrence can't be keyword-guessed — only a learned
		// suggestion (from the prior row) can get the second one right.
		$rows = \ESSF_CLI::replay_suggestions(
			[
				[
					'id'       => 101,
					'date'     => '2026-01-05',
					'title'    => 'Presente Aniversário',
					'memo'     => 'Compra no débito - LOJA XYZ CENTER',
					'category' => 'Shopping',
				],
				[
					'id'       => 102,
					'date'     => '2026-02-10',
					'title'    => 'Presente Aniversário',
					'memo'     => 'Compra no débito - LOJA XYZ CENTER',
					'category' => 'Shopping',
				],
			]
		);

		$this->assertSame( 'Uncategorized', $rows[0]['Predicted Category'] );
		$this->assertSame( 'no', $rows[0]['Category Auto-fill'] );

		$this->assertSame( 'Shopping', $rows[1]['Predicted Category'] );
		$this->assertSame( 'yes', $rows[1]['Category Auto-fill'] );
	}

	public function test_replay_suggestions_keyword_guesses_category_when_no_history_yet(): void {
		$rows = \ESSF_CLI::replay_suggestions(
			[
				[
					'id'       => 201,
					'date'     => '2026-01-05',
					'title'    => 'Mercado',
					'memo'     => 'Compra no débito - KOMPRAO KOCH ATACADIST',
					'category' => 'Groceries',
				],
			]
		);

		$this->assertSame( 'Groceries', $rows[0]['Predicted Category'] );
		$this->assertSame( 'yes', $rows[0]['Category Auto-fill'] );
	}

	public function test_replay_suggestions_defaults_category_to_uncategorized_when_missing(): void {
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

		$this->assertSame( 'Uncategorized', $rows[0]['Category'] );
	}
}
