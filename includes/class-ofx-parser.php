<?php
/**
 * EssFinance — OFX Parser
 *
 * @package EssFinance
 * @license GPL-2.0-or-later
 */

defined( 'ABSPATH' ) || exit;

class ESSF_OFX_Parser {

	/**
	 * Parse OFX/QFX content into a flat list of transactions.
	 *
	 * Handles both OFX 2.x (proper XML closing tags) and OFX 1.x (SGML, no
	 * closing tags). Returns the raw NAME/MEMO fields separately — callers
	 * decide how to fall back to a description via self::describe().
	 *
	 * @param string $content Raw OFX/QFX file contents.
	 * @return array<int, array{fitid: string, amount: float, due_date: string, name: string, memo: string}>
	 */
	public static function parse( string $content ): array {
		$content = str_replace( [ "\r\n", "\r" ], "\n", $content );

		// Strip OFX 1.x header — everything before <OFX>.
		if ( preg_match( '/<OFX>/i', $content, $m, PREG_OFFSET_CAPTURE ) ) {
			$content = substr( $content, (int) $m[0][1] );
		}

		$extract = static function ( string $tag, string $block ): string {
			return preg_match( '/<' . $tag . '>\s*([^\s<][^<\n\r]*)/i', $block, $m ) ? trim( $m[1] ) : '';
		};

		// OFX 2.x has proper closing tags; OFX 1.x (SGML) does not.
		if ( preg_match_all( '/<STMTTRN>(.*?)<\/STMTTRN>/si', $content, $matches ) ) {
			$blocks = $matches[1];
		} else {
			// SGML: split on opening tag, discard pre-first-transaction content.
			$parts = preg_split( '/<STMTTRN>/i', $content );
			array_shift( $parts );
			$blocks = array_map(
				static function ( string $p ): string {
					return preg_split( '/<\/BANKTRANLIST>|<LEDGERBAL>|<AVAILBAL>/i', $p )[0];
				},
				$parts
			);
		}

		$transactions = [];
		foreach ( $blocks as $block ) {
			$amount_raw = $extract( 'TRNAMT', $block );
			$date_raw   = $extract( 'DTPOSTED', $block );
			if ( '' === $amount_raw || '' === $date_raw ) {
				continue;
			}

			// OFX date: YYYYMMDD[HHMMSS[.mmm][tz]] — take first 8 digits.
			$digits = substr( preg_replace( '/[^0-9]/', '', $date_raw ), 0, 8 );
			if ( strlen( $digits ) < 8 ) {
				continue;
			}
			$due_date = substr( $digits, 0, 4 ) . '-' . substr( $digits, 4, 2 ) . '-' . substr( $digits, 6, 2 );

			$transactions[] = [
				'fitid'    => $extract( 'FITID', $block ),
				'amount'   => (float) str_replace( ',', '.', $amount_raw ),
				'due_date' => $due_date,
				'name'     => $extract( 'NAME', $block ),
				'memo'     => $extract( 'MEMO', $block ),
			];
		}

		return $transactions;
	}

	/**
	 * Fall back from a parsed transaction's raw NAME/MEMO to a description.
	 *
	 * @param array{name?: string, memo?: string} $transaction A row returned by self::parse().
	 */
	public static function describe( array $transaction ): string {
		return ( $transaction['name'] ?? '' ) ?: ( ( $transaction['memo'] ?? '' ) ?: 'OFX Transaction' );
	}

	/**
	 * Recognize a "<Kind> <direction> [pelo Pix] - <Nome> - <resto>" Pix
	 * memo — transfers ("Transferência enviada pelo Pix - <Nome>", or the
	 * receiving-side phrasing "Transferência Recebida - <Nome>", no "pelo
	 * Pix" suffix) and refunds ("Reembolso recebido pelo Pix - <Nome>") both
	 * follow this shape — and split it into a short "<Kind> <Nome>" title
	 * plus the remaining counterparty detail (document, bank, account), both
	 * capitalized. The direction word ("enviada(o)"/"recebida(o)") is
	 * dropped from the title since the entry's amount sign already encodes
	 * that direction.
	 *
	 * @param string $raw_memo Raw NAME or MEMO text from a parsed transaction.
	 * @return array{title: string, name: string, detail: string}|null Null when the memo isn't a recognized transfer/refund.
	 */
	public static function parse_transfer( string $raw_memo ): ?array {
		if ( ! preg_match( '/^(transferência|transferencia|reembolso)\s+(?:enviad[ao]|recebid[ao])(?:\s+pelo\s+pix)?\s*-\s*(.+)$/iu', trim( $raw_memo ), $m ) ) {
			return null;
		}

		$kind_map = [
			'transferência' => 'Transferência',
			'transferencia' => 'Transferência',
			'reembolso'     => 'Reembolso',
		];
		$kind     = $kind_map[ mb_strtolower( $m[1] ) ] ?? self::capitalize_pt_br( $m[1] );

		$parts = array_map( 'trim', preg_split( '/\s+-\s+/u', trim( $m[2] ) ) );
		$name  = self::capitalize_pt_br( $parts[0] );

		if ( '' === $name ) {
			return null;
		}

		return [
			'title'  => $kind . ' ' . $name,
			'name'   => $name,
			'detail' => self::capitalize_pt_br( implode( ' - ', $parts ) ),
		];
	}

	/**
	 * Recognize a card purchase memo ("Compra no débito/crédito [via
	 * <method>] - <Merchant>", e.g. "Compra no débito via NuPay - Uber") and
	 * return just the capitalized merchant name — the payment-rail prefix
	 * ("no débito", "via NuPay", ...) carries no information once you're
	 * choosing a description, only noise.
	 *
	 * A leading "Estorno - " (refund/reversal) prefix is recognized too,
	 * e.g. "Estorno - Compra no débito via NuPay - Uber" — the remainder is
	 * parsed the same way and "Estorno" is prepended to the result, so an
	 * estorno for one merchant doesn't fall through to the generic
	 * noise-similarity comparison and coincidentally match a different
	 * merchant's estorno.
	 *
	 * @param string $raw_memo Raw NAME or MEMO text from a parsed transaction.
	 * @return string|null Null when the memo isn't a recognized card purchase.
	 */
	public static function parse_purchase( string $raw_memo ): ?string {
		$memo = trim( $raw_memo );

		if ( preg_match( '/^estorno\s*-\s*(.+)$/iu', $memo, $estorno_m ) ) {
			$inner = self::parse_purchase( $estorno_m[1] );
			return null === $inner ? null : 'Estorno ' . $inner;
		}

		if ( ! preg_match( '/^compra\s+no\s+(?:débito|debito|crédito|credito)(?:\s+via\s+\S+)?\s*-\s*(.+)$/iu', $memo, $m ) ) {
			return null;
		}

		$merchant = self::capitalize_pt_br( trim( $m[1] ) );

		return '' === $merchant ? null : $merchant;
	}

	/**
	 * Recognize a boleto (bank slip) payment memo ("Pagamento de boleto
	 * efetuado - <Beneficiário>") and return just the capitalized
	 * beneficiary name — the "Pagamento de boleto efetuado - " prefix is
	 * always identical and carries no information.
	 *
	 * @param string $raw_memo Raw NAME or MEMO text from a parsed transaction.
	 * @return string|null Null when the memo isn't a recognized boleto payment.
	 */
	public static function parse_boleto( string $raw_memo ): ?string {
		if ( ! preg_match( '/^pagamento\s+de\s+boleto\s+efetuado\s*-\s*(.+)$/iu', trim( $raw_memo ), $m ) ) {
			return null;
		}

		$beneficiary = self::capitalize_pt_br( trim( $m[1] ) );

		return '' === $beneficiary ? null : $beneficiary;
	}

	/**
	 * Title-case Portuguese text: capitalize each word except common
	 * lowercase connectors (de/da/do/das/dos/e) when not the first word, and
	 * leave tokens with no letters (masked CPF/CNPJ, account numbers, codes)
	 * untouched rather than mangling them.
	 */
	private static function capitalize_pt_br( string $text ): string {
		// Some bank/POS systems compress a merchant name by dropping the
		// spaces between words while keeping each word capitalized (e.g.
		// "ConvenienciaDo" for what was probably "Convenencia Do..."). Split
		// those back into separate words first, or the run-together
		// capital just gets silently lowercased with the rest of the token.
		// Require 2+ lowercase letters before the capital, not just 1 — a
		// single-letter run ("iFood", "iPhone") is a stylized brand name,
		// not two run-together words, and splitting it produces "I Food".
		$text = preg_replace( '/(\p{Ll}{2,})(\p{Lu})/u', '$1 $2', $text );

		$lowercase_words = [ 'de', 'da', 'do', 'das', 'dos', 'e' ];
		$tokens          = preg_split( '/(\s+)/u', $text, -1, PREG_SPLIT_DELIM_CAPTURE );
		if ( false === $tokens ) {
			return $text;
		}

		$result     = '';
		$word_index = 0;
		foreach ( $tokens as $token ) {
			if ( '' === trim( $token ) ) {
				$result .= $token;
				continue;
			}

			$lower = mb_strtolower( $token );
			if ( $word_index > 0 && in_array( $lower, $lowercase_words, true ) ) {
				$result .= $lower;
			} elseif ( preg_match( '/\p{L}/u', $token ) ) {
				$result .= mb_strtoupper( mb_substr( $lower, 0, 1 ) ) . mb_substr( $lower, 1 );
			} else {
				$result .= $token;
			}
			++$word_index;
		}

		return $result;
	}
}
