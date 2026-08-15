<?php
/**
 * Tests for ESSF_OFX_Suggestions.
 *
 * @package EssFinance\Tests
 * @license GPL-2.0-or-later
 */

declare( strict_types=1 );

namespace EssFinance\Tests\Unit;

use PHPUnit\Framework\TestCase;

class OfxSuggestionsTest extends TestCase {

	public function test_suggest_returns_empty_when_no_history(): void {
		$this->assertSame( [], \ESSF_OFX_Suggestions::suggest( 'Transferência enviada pelo Pix - Fulano', [] ) );
	}

	public function test_suggest_returns_empty_when_memo_is_blank(): void {
		$history = [ [ 'memo' => 'Compra no débito - Mercado', 'title' => 'Mercado', 'date' => '2026-01-01' ] ];
		$this->assertSame( [], \ESSF_OFX_Suggestions::suggest( '', $history ) );
	}

	public function test_suggest_matches_similar_memo_across_masked_account_numbers(): void {
		$history = [
			[
				'memo'  => 'Transferência enviada pelo Pix - Fulano de Tal - •••.694.949-•• - NU PAGAMENTOS - IP (0260) Agência: 1 Conta: 43901533-8',
				'title' => 'Empréstimo Fulano',
				'date'  => '2026-01-05',
			],
		];

		$new_memo = 'Transferência enviada pelo Pix - Fulano de Tal - •••.694.949-•• - NU PAGAMENTOS - IP (0260) Agência: 1 Conta: 99988877-2';

		$suggestions = \ESSF_OFX_Suggestions::suggest( $new_memo, $history );

		$this->assertNotEmpty( $suggestions );
		$this->assertSame( 'Empréstimo Fulano', $suggestions[0]['title'] );
	}

	public function test_suggest_ranks_by_frequency_then_recency(): void {
		$history = [
			[ 'memo' => 'Compra no débito - Mercado Komprão Atacadista', 'title' => 'Mercado Komprão', 'date' => '2026-01-01' ],
			[ 'memo' => 'Compra no débito - Mercado Komprão Atacadista', 'title' => 'Mercado Komprão', 'date' => '2026-02-01' ],
			[ 'memo' => 'Compra no débito - Mercado Komprão Atacadista', 'title' => 'Mercado (old label)', 'date' => '2025-12-01' ],
		];

		$suggestions = \ESSF_OFX_Suggestions::suggest( 'Compra no débito - Mercado Komprão Atacadista', $history );

		$this->assertSame( 'Mercado Komprão', $suggestions[0]['title'] );
		$this->assertSame( 'Mercado (old label)', $suggestions[1]['title'] );
	}

	public function test_suggest_ignores_dissimilar_memos(): void {
		$history = [
			[ 'memo' => 'Compra no débito - Padaria do Zé', 'title' => 'Padaria', 'date' => '2026-01-01' ],
		];

		$suggestions = \ESSF_OFX_Suggestions::suggest( 'Transferência enviada pelo Pix - Empresa XPTO Serviços Ltda', $history );

		$this->assertSame( [], $suggestions );
	}

	public function test_suggest_respects_limit(): void {
		$history = [
			[ 'memo' => 'Compra no débito - Loja A', 'title' => 'Loja A', 'date' => '2026-01-01' ],
			[ 'memo' => 'Compra no débito - Loja B', 'title' => 'Loja B', 'date' => '2026-01-01' ],
			[ 'memo' => 'Compra no débito - Loja C', 'title' => 'Loja C', 'date' => '2026-01-01' ],
			[ 'memo' => 'Compra no débito - Loja D', 'title' => 'Loja D', 'date' => '2026-01-01' ],
		];

		$suggestions = \ESSF_OFX_Suggestions::suggest( 'Compra no débito - Loja', $history, 2 );

		$this->assertLessThanOrEqual( 2, count( $suggestions ) );
	}

	public function test_suggest_deduplicates_by_title(): void {
		$history = [
			[ 'memo' => 'Compra no débito - Padaria do Zé - loja 1', 'title' => 'Padaria', 'date' => '2026-01-01' ],
			[ 'memo' => 'Compra no débito - Padaria do Zé - loja 2', 'title' => 'Padaria', 'date' => '2026-02-01' ],
		];

		$suggestions = \ESSF_OFX_Suggestions::suggest( 'Compra no débito - Padaria do Zé - loja 3', $history );

		$titles = array_column( $suggestions, 'title' );
		$this->assertSame( array_unique( $titles ), $titles );
	}

	public function test_suggest_matches_received_transfer_against_sent_transfer_by_same_person(): void {
		// Nubank phrases received Pix transfers without "pelo Pix" — a plain
		// "Transferência Recebida" — while sent ones say "enviada pelo Pix".
		// Both should reduce to the counterparty's name and match each other.
		$history = [
			[
				'memo'  => 'Transferência enviada pelo Pix - Maria Lucia Machado',
				'title' => 'Empréstimo Lucia',
				'date'  => '2026-01-05',
			],
		];

		$new_memo = 'Transferência Recebida - Maria Lucia Machado - •••.443.649-•• - NU PAGAMENTOS - IP (0260) Agência: 1 Conta: 58642591-2';

		$suggestions = \ESSF_OFX_Suggestions::suggest( $new_memo, $history );

		$this->assertNotEmpty( $suggestions );
		$this->assertSame( 'Empréstimo Lucia', $suggestions[0]['title'] );
	}

	public function test_suggest_matches_nupay_purchase_against_plain_debit_purchase(): void {
		$history = [
			[ 'memo' => 'Compra no débito - Uber', 'title' => 'Transporte App', 'date' => '2026-01-05' ],
		];

		$suggestions = \ESSF_OFX_Suggestions::suggest( 'Compra no débito via NuPay - Uber', $history );

		$this->assertNotEmpty( $suggestions );
		$this->assertSame( 'Transporte App', $suggestions[0]['title'] );
	}

	// ── matches_excluded() ───────────────────────────────────────────────

	public function test_matches_excluded_true_for_similar_memo(): void {
		$excluded = [ \ESSF_OFX_Suggestions::normalize( 'Saldo do dia anterior' ) ];
		$this->assertTrue( \ESSF_OFX_Suggestions::matches_excluded( 'Saldo do dia', $excluded ) );
	}

	public function test_matches_excluded_false_for_dissimilar_memo(): void {
		$excluded = [ \ESSF_OFX_Suggestions::normalize( 'Saldo do dia anterior' ) ];
		$this->assertFalse( \ESSF_OFX_Suggestions::matches_excluded( 'Compra no débito - Padaria', $excluded ) );
	}

	public function test_matches_excluded_false_when_list_empty(): void {
		$this->assertFalse( \ESSF_OFX_Suggestions::matches_excluded( 'Saldo do dia', [] ) );
	}

	public function test_matches_excluded_false_for_blank_memo(): void {
		$excluded = [ \ESSF_OFX_Suggestions::normalize( 'Saldo do dia anterior' ) ];
		$this->assertFalse( \ESSF_OFX_Suggestions::matches_excluded( '', $excluded ) );
	}

	// ── normalize() ───────────────────────────────────────────────────────

	public function test_normalize_strips_masked_cpf(): void {
		$normalized = \ESSF_OFX_Suggestions::normalize( 'Fulano de Tal - •••.694.949-••' );
		$this->assertStringNotContainsString( '694', $normalized );
	}

	public function test_normalize_strips_long_digit_runs(): void {
		$normalized = \ESSF_OFX_Suggestions::normalize( 'Conta: 43901533-8' );
		$this->assertStringNotContainsString( '43901533', $normalized );
	}

	public function test_normalize_strips_bank_noise_tokens(): void {
		$normalized = \ESSF_OFX_Suggestions::normalize( 'Transferência enviada pelo Pix - Fulano' );
		$this->assertStringNotContainsString( 'pix', $normalized );
		$this->assertStringContainsString( 'fulano', $normalized );
	}

	public function test_normalize_lowercases_and_collapses_whitespace(): void {
		$this->assertSame( 'mercado komprao', \ESSF_OFX_Suggestions::normalize( "  MERCADO   KOMPRAO  \n" ) );
	}

	// ── normalize() — recognized transfer/purchase memos compare on just  ──
	// ── the extracted name, not the full noise-stripped text             ──

	public function test_normalize_recognized_transfer_reduces_to_kind_and_name(): void {
		$raw = 'Transferência enviada pelo Pix - Outlet de Passagens - 45.718.545/0001-76 - PAYMEE BRASIL IP S.A. Agência: 1 Conta: 20847303-0';
		$this->assertSame( 'transferência outlet de passagens', \ESSF_OFX_Suggestions::normalize( $raw ) );
	}

	public function test_suggest_prefers_same_category_when_a_competing_title_exists(): void {
		// A Reembolso and a Transferência to the same person are usually
		// different transactions — when precedents for BOTH categories
		// exist, the one sharing this memo's category should win, even if
		// the other category's title has been used more often.
		$history = [
			[
				'memo'  => 'Transferência enviada pelo Pix - Maria Lucia Machado',
				'title' => 'Transferência Maria',
				'date'  => '2026-01-05',
			],
			[
				'memo'  => 'Transferência enviada pelo Pix - Maria Lucia Machado',
				'title' => 'Transferência Maria',
				'date'  => '2026-01-10',
			],
			[
				'memo'  => 'Reembolso recebido pelo Pix - Maria Lucia Machado',
				'title' => 'Reembolso Maria',
				'date'  => '2026-01-06',
			],
		];

		$new_memo = 'Reembolso recebido pelo Pix - Maria Lucia Machado';

		$suggestions = \ESSF_OFX_Suggestions::suggest( $new_memo, $history );

		$this->assertNotEmpty( $suggestions );
		$this->assertSame( 'Reembolso Maria', $suggestions[0]['title'] );
	}

	public function test_suggest_falls_back_to_cross_category_match_when_no_same_category_precedent_exists(): void {
		// A payment intermediary/marketplace can legitimately be labeled the
		// same way regardless of transaction kind (e.g. a Pix transfer and
		// an occasional refund both routed through the same checkout flow).
		// With no same-category precedent yet, a cross-category match is
		// still better than no suggestion at all.
		$history = [
			[
				'memo'  => 'Transferência enviada pelo Pix - Pix Marketplace',
				'title' => 'Compra',
				'date'  => '2026-01-05',
			],
		];

		$new_memo = 'Reembolso recebido pelo Pix - Pix Marketplace';

		$suggestions = \ESSF_OFX_Suggestions::suggest( $new_memo, $history );

		$this->assertNotEmpty( $suggestions );
		$this->assertSame( 'Compra', $suggestions[0]['title'] );
	}

	public function test_suggest_matches_boleto_payments_to_the_same_beneficiary_across_variant_memos(): void {
		// Real case: the same beneficiary's boleto memo varies month to
		// month (extra digits/branch codes), so the exact-string form never
		// repeats — only the extracted beneficiary name does.
		$history = [
			[
				'memo'  => 'Pagamento de boleto efetuado - CELESC DISTRIBUICAO S A 08 336 783',
				'title' => 'Energia',
				'date'  => '2026-01-10',
			],
		];

		$new_memo = 'Pagamento de boleto efetuado - CELESC DISTRIBUICAO S A';

		$suggestions = \ESSF_OFX_Suggestions::suggest( $new_memo, $history );

		$this->assertNotEmpty( $suggestions );
		$this->assertSame( 'Energia', $suggestions[0]['title'] );
	}

	public function test_suggest_does_not_confuse_estorno_of_different_merchants(): void {
		$history = [
			[
				'memo'  => 'Estorno - Compra no débito via NuPay - Uber',
				'title' => 'Estorno parcial Transporte App',
				'date'  => '2026-01-05',
			],
		];

		$new_memo = 'Estorno - Compra no débito via NuPay - iFood';

		$this->assertSame( [], \ESSF_OFX_Suggestions::suggest( $new_memo, $history ) );
	}

	public function test_normalize_strips_unmasked_cnpj_for_non_transfer_text(): void {
		// Same document number, but not inside a recognized transfer/purchase
		// shape, so it goes through the general noise-stripping pipeline —
		// which needs its own unmasked-CNPJ handling (the long-digit-run
		// strip alone doesn't catch a dotted CNPJ's short digit groups).
		$normalized = \ESSF_OFX_Suggestions::normalize( 'Ref 45.718.545/0001-76 pagamento' );
		$this->assertStringNotContainsString( '45', $normalized );
		$this->assertStringNotContainsString( '718', $normalized );
	}

	public function test_suggest_does_not_false_positive_across_shared_payment_intermediary(): void {
		// Two entirely unrelated transfers that both happen to route through
		// the same payment facilitator (an intermediary name not on the
		// noise-word list) and both carry an unmasked CNPJ — before the fix,
		// leftover boilerplate from both could inflate their similarity past
		// the threshold and suggest the wrong prior title.
		$history = [
			[
				'memo'  => 'Transferência enviada pelo Pix - Pet Shop Amigo Fiel - 12.345.678/0001-90 - PAYMEE BRASIL IP S.A. Agência: 1 Conta: 99988877-6',
				'title' => 'Animais',
				'date'  => '2026-01-01',
			],
		];

		$new_memo = 'Transferência enviada pelo Pix - Outlet de Passagens - 45.718.545/0001-76 - PAYMEE BRASIL IP S.A. Agência: 1 Conta: 20847303-0';

		$this->assertSame( [], \ESSF_OFX_Suggestions::suggest( $new_memo, $history ) );
	}

	public function test_suggest_does_not_false_positive_on_short_coincidental_overlap(): void {
		// Real case: a badly-formatted raw memo with no space before the
		// name ("29774948Mauricio") normalizes down to just "mauricio" once
		// the leading digit run is stripped — short enough that it
		// coincidentally scored 46.7% similar_text() overlap against an
		// unrelated merchant name at the old 45.0 threshold.
		$history = [
			[
				'memo'  => 'Compra no débito - 29774948Mauricio',
				'title' => '29774948mauricio',
				'date'  => '2026-01-01',
			],
		];

		$new_memo = 'Compra no débito - KLOSTERMANN NUTRICAO L';

		$this->assertSame( [], \ESSF_OFX_Suggestions::suggest( $new_memo, $history ) );
	}
}
