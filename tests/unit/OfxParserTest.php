<?php
/**
 * Tests for ESSF_OFX_Parser.
 *
 * @package EssFinance\Tests
 * @license GPL-2.0-or-later
 */

declare( strict_types=1 );

namespace EssFinance\Tests\Unit;

use PHPUnit\Framework\TestCase;

class OfxParserTest extends TestCase {

	public function test_parse_ofx2_xml_format(): void {
		$ofx = <<<OFX
<OFX>
<BANKMSGSRSV1>
<STMTTRNRS>
<STMTRS>
<BANKTRANLIST>
<STMTTRN>
<TRNTYPE>CREDIT</TRNTYPE>
<DTPOSTED>20260115</DTPOSTED>
<TRNAMT>500.00</TRNAMT>
<FITID>TXN001</FITID>
<NAME>Salary</NAME>
</STMTTRN>
<STMTTRN>
<TRNTYPE>DEBIT</TRNTYPE>
<DTPOSTED>20260120</DTPOSTED>
<TRNAMT>-150.00</TRNAMT>
<FITID>TXN002</FITID>
<MEMO>Internet bill</MEMO>
</STMTTRN>
</BANKTRANLIST>
</STMTRS>
</STMTTRNRS>
</BANKMSGSRSV1>
</OFX>
OFX;

		$txns = \ESSF_OFX_Parser::parse( $ofx );
		$this->assertCount( 2, $txns );

		$this->assertSame( 'Salary', $txns[0]['name'] );
		$this->assertSame( '', $txns[0]['memo'] );
		$this->assertSame( 500.0, $txns[0]['amount'] );
		$this->assertSame( '2026-01-15', $txns[0]['due_date'] );
		$this->assertSame( 'TXN001', $txns[0]['fitid'] );

		$this->assertSame( '', $txns[1]['name'] );
		$this->assertSame( 'Internet bill', $txns[1]['memo'] );
		$this->assertSame( -150.0, $txns[1]['amount'] );
		$this->assertSame( '2026-01-20', $txns[1]['due_date'] );
	}

	public function test_parse_ofx1_sgml_format(): void {
		// OFX 1.x SGML — no closing tags.
		$ofx = <<<OFX
OFXHEADER:100
DATA:OFXSGML
VERSION:151

<OFX>
<BANKMSGSRSV1>
<STMTTRNRS>
<STMTRS>
<BANKTRANLIST>
<STMTTRN>
<TRNTYPE>CREDIT
<DTPOSTED>20260301
<TRNAMT>1200.00
<FITID>SGML001
<NAME>Freelance payment
</BANKTRANLIST>
</STMTRS>
</STMTTRNRS>
</BANKMSGSRSV1>
</OFX>
OFX;

		$txns = \ESSF_OFX_Parser::parse( $ofx );
		$this->assertCount( 1, $txns );
		$this->assertSame( 'Freelance payment', $txns[0]['name'] );
		$this->assertSame( 1200.0, $txns[0]['amount'] );
		$this->assertSame( '2026-03-01', $txns[0]['due_date'] );
	}

	public function test_parse_ofx_skips_transactions_missing_amount_or_date(): void {
		$ofx = <<<OFX
<OFX>
<BANKTRANLIST>
<STMTTRN>
<DTPOSTED>20260101</DTPOSTED>
</STMTTRN>
<STMTTRN>
<TRNAMT>99.00</TRNAMT>
</STMTTRN>
</BANKTRANLIST>
</OFX>
OFX;

		$txns = \ESSF_OFX_Parser::parse( $ofx );
		$this->assertCount( 0, $txns );
	}

	public function test_parse_ofx_returns_raw_memo_without_collapsing(): void {
		$ofx = <<<OFX
<OFX>
<BANKTRANLIST>
<STMTTRN>
<DTPOSTED>20260101</DTPOSTED>
<TRNAMT>50.00</TRNAMT>
<FITID>X1</FITID>
<MEMO>Transfer fee</MEMO>
</STMTTRN>
</BANKTRANLIST>
</OFX>
OFX;

		$txns = \ESSF_OFX_Parser::parse( $ofx );
		$this->assertSame( '', $txns[0]['name'] );
		$this->assertSame( 'Transfer fee', $txns[0]['memo'] );
	}

	public function test_parse_ofx_name_and_memo_empty_when_absent(): void {
		$ofx = <<<OFX
<OFX>
<BANKTRANLIST>
<STMTTRN>
<DTPOSTED>20260101</DTPOSTED>
<TRNAMT>10.00</TRNAMT>
<FITID>X2</FITID>
</STMTTRN>
</BANKTRANLIST>
</OFX>
OFX;

		$txns = \ESSF_OFX_Parser::parse( $ofx );
		$this->assertSame( '', $txns[0]['name'] );
		$this->assertSame( '', $txns[0]['memo'] );
	}

	// ── describe() — the NAME ?: MEMO ?: 'OFX Transaction' fallback ─────────

	public function test_describe_prefers_name(): void {
		$this->assertSame(
			'Salary',
			\ESSF_OFX_Parser::describe( [ 'name' => 'Salary', 'memo' => 'Payroll deposit' ] )
		);
	}

	public function test_describe_falls_back_to_memo_when_no_name(): void {
		$this->assertSame(
			'Transfer fee',
			\ESSF_OFX_Parser::describe( [ 'name' => '', 'memo' => 'Transfer fee' ] )
		);
	}

	public function test_describe_default_when_no_name_or_memo(): void {
		$this->assertSame( 'OFX Transaction', \ESSF_OFX_Parser::describe( [ 'name' => '', 'memo' => '' ] ) );
	}

	// ── parse_transfer() ─────────────────────────────────────────────────

	public function test_parse_transfer_sent_via_pix(): void {
		$result = \ESSF_OFX_Parser::parse_transfer( 'Transferência enviada pelo Pix - Maria Lucia Machado' );

		$this->assertNotNull( $result );
		$this->assertSame( 'Transferência Maria Lucia Machado', $result['title'] );
		$this->assertSame( 'Maria Lucia Machado', $result['detail'] );
	}

	public function test_parse_transfer_received_without_pelo_pix_suffix(): void {
		// Nubank phrases received transfers without "pelo Pix", unlike sent ones.
		$raw = 'Transferência Recebida - Maria Lucia Machado - •••.443.649-•• - NU PAGAMENTOS - IP (0260) Agência: 1 Conta: 58642591-2';

		$result = \ESSF_OFX_Parser::parse_transfer( $raw );

		$this->assertNotNull( $result );
		$this->assertSame( 'Transferência Maria Lucia Machado', $result['title'] );
		$this->assertSame(
			'Maria Lucia Machado - •••.443.649-•• - Nu Pagamentos - Ip (0260) Agência: 1 Conta: 58642591-2',
			$result['detail']
		);
	}

	public function test_parse_transfer_capitalizes_lowercase_connectors(): void {
		$result = \ESSF_OFX_Parser::parse_transfer( 'TRANSFERÊNCIA ENVIADA PELO PIX - MARIA DE SOUZA DOS SANTOS' );

		$this->assertSame( 'Transferência Maria de Souza dos Santos', $result['title'] );
	}

	public function test_parse_transfer_returns_null_for_non_transfer_memo(): void {
		$this->assertNull( \ESSF_OFX_Parser::parse_transfer( 'Compra no débito - Uber' ) );
	}

	public function test_parse_transfer_returns_null_for_blank_memo(): void {
		$this->assertNull( \ESSF_OFX_Parser::parse_transfer( '' ) );
	}

	public function test_parse_transfer_recognizes_pix_refund(): void {
		// "Reembolso" follows the same "<Kind> <direction> pelo Pix - Nome -
		// resto" shape as a transfer, just a different lead word.
		$raw = 'Reembolso recebido pelo Pix - Gilberto Edmundo Tavares - •••.162.199-•• - 99PAY IP S.A. (0769) Agência: 1 Conta: 2629340-4';

		$result = \ESSF_OFX_Parser::parse_transfer( $raw );

		$this->assertNotNull( $result );
		$this->assertSame( 'Reembolso Gilberto Edmundo Tavares', $result['title'] );
		// capitalize_pt_br() only title-cases the first character of each
		// token, so multi-capital abbreviations like "99PAY"/"S.A." come out
		// as "99pay"/"S.a." — a known rough edge, acceptable here since this
		// only feeds the mostly-unsurfaced _essf_ofx_detail meta, not the
		// visible title.
		$this->assertSame(
			'Gilberto Edmundo Tavares - •••.162.199-•• - 99pay Ip S.a. (0769) Agência: 1 Conta: 2629340-4',
			$result['detail']
		);
	}

	public function test_parse_transfer_recognizes_pix_refund_sent_direction(): void {
		$result = \ESSF_OFX_Parser::parse_transfer( 'Reembolso enviado pelo Pix - Maria Lucia Machado' );

		$this->assertNotNull( $result );
		$this->assertSame( 'Reembolso Maria Lucia Machado', $result['title'] );
	}

	// ── parse_purchase() ─────────────────────────────────────────────────

	public function test_parse_purchase_strips_via_nupay_prefix(): void {
		$this->assertSame( 'Uber', \ESSF_OFX_Parser::parse_purchase( 'Compra no débito via NuPay - Uber' ) );
	}

	public function test_parse_purchase_strips_plain_debit_prefix_and_capitalizes(): void {
		$this->assertSame(
			'Komprao Koch Atacadist',
			\ESSF_OFX_Parser::parse_purchase( 'Compra no débito - KOMPRAO KOCH ATACADIST' )
		);
	}

	public function test_parse_purchase_matches_credit_purchases_too(): void {
		$this->assertSame( 'Loja X', \ESSF_OFX_Parser::parse_purchase( 'Compra no crédito - Loja X' ) );
	}

	public function test_parse_purchase_returns_null_for_non_purchase_memo(): void {
		$this->assertNull( \ESSF_OFX_Parser::parse_purchase( 'Transferência enviada pelo Pix - Fulano' ) );
	}

	public function test_parse_purchase_returns_null_for_blank_memo(): void {
		$this->assertNull( \ESSF_OFX_Parser::parse_purchase( '' ) );
	}

	public function test_parse_purchase_recognizes_estorno_prefix(): void {
		$this->assertSame(
			'Estorno Uber',
			\ESSF_OFX_Parser::parse_purchase( 'Estorno - Compra no débito via NuPay - Uber' )
		);
	}

	public function test_parse_purchase_returns_null_for_estorno_of_non_purchase_memo(): void {
		$this->assertNull( \ESSF_OFX_Parser::parse_purchase( 'Estorno - something unrelated' ) );
	}

	public function test_parse_purchase_preserves_single_letter_prefix_brand_names(): void {
		// A single lowercase letter before the capital ("iFood", "iPhone")
		// is a stylized brand name, not two run-together words — splitting
		// it would produce "I Food" and never again match a differently
		// formatted memo for the same merchant that normalizes to "ifood".
		$this->assertSame( 'Ifood', \ESSF_OFX_Parser::parse_purchase( 'Compra no débito via NuPay - iFood' ) );
	}

	// ── parse_boleto() ───────────────────────────────────────────────────

	public function test_parse_boleto_strips_prefix_and_capitalizes(): void {
		$this->assertSame(
			'Celesc Distribuicao S A',
			\ESSF_OFX_Parser::parse_boleto( 'Pagamento de boleto efetuado - CELESC DISTRIBUICAO S A' )
		);
	}

	public function test_parse_boleto_returns_null_for_non_boleto_memo(): void {
		$this->assertNull( \ESSF_OFX_Parser::parse_boleto( 'Compra no débito - Padaria' ) );
	}

	public function test_parse_boleto_returns_null_for_blank_memo(): void {
		$this->assertNull( \ESSF_OFX_Parser::parse_boleto( '' ) );
	}

	public function test_parse_purchase_splits_run_together_capitalized_words(): void {
		// Real case: some bank/POS systems drop the space between words in
		// a merchant name while keeping each word capitalized. Without
		// splitting on the internal capital first, "ConvenienciaDo" would
		// lowercase straight through to "Convenienciado".
		// "Do" lands on the lowercase-connector list ("do") since it's not
		// the first word — same rule as any other multi-word name.
		$this->assertSame(
			'Conveniencia do',
			\ESSF_OFX_Parser::parse_purchase( 'Compra no débito - ConvenienciaDo' )
		);
	}
}
