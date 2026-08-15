<?php
/**
 * EssFinance — OFX description suggestions
 *
 * @package EssFinance
 * @license GPL-2.0-or-later
 */

defined( 'ABSPATH' ) || exit;

/**
 * Suggests prior EssFinance descriptions for a raw OFX memo, based on
 * similarity against previously-imported entries' raw memos.
 *
 * Pure/dependency-free by design: callers supply the history (prior
 * memo/title/date rows) rather than this class querying the database, so
 * the ranking logic can be unit tested without WordPress.
 */
class ESSF_OFX_Suggestions {

	/**
	 * Now that normalize() compares recognized transfer/purchase memos on
	 * just their extracted counterparty/merchant name (see normalize()),
	 * genuine matches are almost always exact or near-exact — two raw
	 * memos for the same real transaction reduce to the identical clean
	 * name. A lower threshold, tuned for the old noisy-full-memo
	 * comparison, left too much room for two SHORT, unrelated names to
	 * coincidentally overlap: e.g. "Klostermann Nutricao L" vs a stray
	 * "Mauricio" (from a badly-formatted raw memo with no space before the
	 * name) scored 46.7% — a false positive at the old 45.0 threshold. 60.0
	 * still clears every legitimate case in this file's test suite (lowest
	 * observed: ~72.7%) with real margin on both sides.
	 */
	const SIMILARITY_THRESHOLD = 60.0;

	/**
	 * Rank prior descriptions by similarity to a raw memo, preferring a
	 * title with a same-category (transfer/refund/reversal kind) precedent
	 * over one that only matches by counterparty/merchant name, then by
	 * frequency of prior use, then recency.
	 *
	 * Category is a *preference*, not a hard filter: a Reembolso and a
	 * Transferência to the same person are usually different transactions
	 * and shouldn't default to each other's title just because one is more
	 * frequent — but for a counterparty where every past entry (regardless
	 * of kind) already carries the same title, or for the very first entry
	 * of a kind that's new for this counterparty, there may be no
	 * same-category precedent yet, and falling back to the best
	 * cross-category match is still better than no suggestion at all.
	 *
	 * @param string $raw_memo Raw MEMO/NAME text from the new transaction.
	 * @param array  $history  Prior rows: [ [ 'memo' => string, 'title' => string, 'date' => 'Y-m-d' ], ... ].
	 * @param int    $limit    Maximum number of suggestions to return.
	 * @return array<int, array{title: string, score: float}> Ranked best-first.
	 */
	public static function suggest( string $raw_memo, array $history, int $limit = 3 ): array {
		$target = self::normalize( $raw_memo );
		if ( '' === $target ) {
			return [];
		}

		$target_split = self::split_category( $target );

		$candidates = [];
		foreach ( $history as $entry ) {
			$normalized = self::normalize( (string) ( $entry['memo'] ?? '' ) );
			$title      = trim( (string) ( $entry['title'] ?? '' ) );
			if ( '' === $normalized || '' === $title ) {
				continue;
			}

			$candidate_split = self::split_category( $normalized );

			similar_text( $target_split['rest'], $candidate_split['rest'], $percent );
			if ( $percent < self::SIMILARITY_THRESHOLD ) {
				continue;
			}

			$same_category = $target_split['category'] === $candidate_split['category'];

			if ( ! isset( $candidates[ $title ] ) ) {
				$candidates[ $title ] = [
					'title'         => $title,
					'score'         => $percent,
					'frequency'     => 0,
					'recency'       => '',
					'same_category' => false,
				];
			}

			$candidates[ $title ]['score']         = max( $candidates[ $title ]['score'], $percent );
			$candidates[ $title ]['same_category'] = $candidates[ $title ]['same_category'] || $same_category;
			++$candidates[ $title ]['frequency'];
			$date = (string) ( $entry['date'] ?? '' );
			if ( $date > $candidates[ $title ]['recency'] ) {
				$candidates[ $title ]['recency'] = $date;
			}
		}

		usort(
			$candidates,
			static function ( array $a, array $b ): int {
				return $b['same_category'] <=> $a['same_category']
					?: $b['frequency'] <=> $a['frequency']
					?: $b['recency'] <=> $a['recency']
					?: $b['score'] <=> $a['score'];
			}
		);

		$ranked = array_map(
			static function ( array $c ): array {
				return [
					'title' => $c['title'],
					'score' => $c['score'],
				];
			},
			array_values( $candidates )
		);

		return array_slice( $ranked, 0, $limit );
	}

	/**
	 * Whether a raw memo is similar to one the operator has excluded before
	 * (e.g. balance lines, internal transfers) — used to pre-mark a staged
	 * row as excluded by default so the same noise doesn't need excluding
	 * again on every import.
	 *
	 * @param string             $raw_memo        Raw MEMO/NAME text from the new transaction.
	 * @param array<int, string> $excluded_memos  Normalized memo strings from previously-excluded rows.
	 */
	public static function matches_excluded( string $raw_memo, array $excluded_memos ): bool {
		$target = self::normalize( $raw_memo );
		if ( '' === $target || ! $excluded_memos ) {
			return false;
		}

		foreach ( $excluded_memos as $excluded ) {
			similar_text( $target, $excluded, $percent );
			if ( $percent >= self::SIMILARITY_THRESHOLD ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Split a normalize()d string into its transfer/refund/reversal category
	 * (`transferência`, `reembolso`, `estorno`) and the remaining
	 * counterparty/merchant text, when normalize() reduced the memo through
	 * one of those recognized shapes.
	 *
	 * Comparing "category + name" as one concatenated string (normalize()'s
	 * raw output) lets a long shared name dominate similar_text()'s score
	 * regardless of the category word, so two different kinds of
	 * transaction to the same counterparty (a Reembolso and a Transferência)
	 * can score as near-identical even though they're not the same
	 * transaction. Comparing the `rest` alone — with `category` used
	 * separately as a ranking preference in suggest(), not a hard filter —
	 * avoids that without losing genuine cross-category matches (e.g. a
	 * merchant/intermediary the operator has always labeled the same way
	 * regardless of transaction kind).
	 *
	 * @return array{category: ?string, rest: string}
	 */
	private static function split_category( string $normalized ): array {
		if ( preg_match( '/^(transferência|reembolso|estorno)\s+(.*)$/u', $normalized, $m ) ) {
			return [
				'category' => $m[1],
				'rest'     => trim( $m[2] ),
			];
		}

		return [
			'category' => null,
			'rest'     => $normalized,
		];
	}

	/**
	 * Normalize a raw bank memo for similarity comparison.
	 *
	 * For a recognized transfer/refund, card purchase, or boleto payment
	 * memo, this compares on just the counterparty/merchant/beneficiary
	 * identity ESSF_OFX_Parser already extracts for that purpose, rather
	 * than the full memo text. That
	 * sidesteps two failure modes the noise-word list below can't fully
	 * cover on its own: an *unmasked* CNPJ/CPF (banks don't always mask
	 * business documents the way they mask personal ones — its dotted digit
	 * groups are mostly under 4 digits, so the long-digit-run strip below
	 * barely touches it) and an unrecognized payment-rail/intermediary name
	 * (only a couple are hardcoded below; any other one — e.g. a payment
	 * facilitator most transactions never go through — would otherwise
	 * survive and inflate similarity between two otherwise-unrelated
	 * counterparties that happen to share it).
	 *
	 * For a transfer/refund, this compares on parse_transfer()'s `title`
	 * (kind + name), not the bare `name` — a Reembolso and a Transferência
	 * to/from the same person are different transactions and must not
	 * collapse to an identical suggestion just because they share a
	 * counterparty.
	 *
	 * Anything that isn't a recognized transfer/purchase/boleto shape falls
	 * back to the noise-word pipeline below, unchanged.
	 */
	public static function normalize( string $text ): string {
		$text = trim( $text );
		if ( '' === $text ) {
			return '';
		}

		$transfer = ESSF_OFX_Parser::parse_transfer( $text );
		if ( $transfer ) {
			$text = $transfer['title'];
		} else {
			$purchase = ESSF_OFX_Parser::parse_purchase( $text );
			if ( null !== $purchase ) {
				$text = $purchase;
			} else {
				$boleto = ESSF_OFX_Parser::parse_boleto( $text );
				if ( null !== $boleto ) {
					$text = $boleto;
				}
			}
		}

		$text = mb_strtolower( $text );
		if ( '' === $text ) {
			return '';
		}

		// Unmasked CNPJ (XX.XXX.XXX/XXXX-XX) / CPF (XXX.XXX.XXX-XX) — banks
		// don't always mask business documents, and their dotted digit
		// groups are mostly under the long-digit-run threshold below.
		$text = preg_replace( '/\d{2}\.\d{3}\.\d{3}\/\d{4}-\d{2}/', ' ', $text );
		$text = preg_replace( '/\d{3}\.\d{3}\.\d{3}-\d{2}/', ' ', $text );
		// Masked CPF/CNPJ, e.g. •••.694.949-•• or ***.694.949-**.
		$text = preg_replace( '/[•*]+[.\-\d•*]*[•*]*/u', ' ', $text );
		// Long digit runs — account numbers, agency numbers, document IDs.
		$text = preg_replace( '/\d{4,}/', ' ', $text );

		$noise = [
			'nu pagamentos',
			'ip',
			'agência',
			'agencia',
			'conta',
			// "pelo"/"pix" stripped separately below so both "transferência
			// enviada pelo Pix" and the plain "transferência recebida" (no
			// "pelo Pix" suffix — Nubank uses that phrasing for received
			// transfers) reduce to the same counterparty-only text.
			'transferência enviada',
			'transferencia enviada',
			'transferência recebida',
			'transferencia recebida',
			'compra no débito',
			'compra no debito',
			'compra no crédito',
			'compra no credito',
			'via nupay',
			'pelo',
			'pix',
		];
		$text  = str_replace( $noise, ' ', $text );

		$text = preg_replace( '/[^\p{L}\p{N}\s]/u', ' ', $text );
		$text = trim( preg_replace( '/\s+/', ' ', $text ) );

		return $text;
	}
}
