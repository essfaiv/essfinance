<?php
/**
 * EssFinance — Auto-detect Bills/Loans/Financing plans from entry titles
 *
 * @package EssFinance
 * @license GPL-2.0-or-later
 */

defined( 'ABSPATH' ) || exit;

/**
 * Bills/Loans/Financing plans match `essf_cashflow` entries by exact title
 * (see ESSF_CPT::find_entries_by_title()) — but the plan term itself has
 * always had to be created by hand first. This class recognizes three
 * naming conventions already present in real, hand-typed or bank-imported
 * data and creates the missing plan term for them:
 *
 * - Loan: title starts with "Empréstimo " (e.g. "Empréstimo Lucia").
 * - Financing: title ends with " {index}/{n}" (e.g. "Carro 2/48") — the same
 *   shape ESSF_Financing_CPT::installment_title() always produces, so only
 *   an end-anchored suffix is recognized; a "N/N" appearing mid-title
 *   (e.g. "Advogado 2/2 Matheus Lobo Macedo") is out of scope, since a
 *   plan created from that base would never match future installments
 *   (which always get the suffix appended at the end).
 * - Bill: title ends with " {Mmm}-{YYYY}" (Portuguese 3-letter month
 *   abbreviation + 4-digit year, e.g. "Eletricidade Jan-2026"). Bill terms
 *   themselves carry no date in their name (occurrences are matched by
 *   `_order_date` + a per-month date_query, not by embedded text), so the
 *   *base* (date suffix stripped) becomes the term name — meaning
 *   historical dated entries like this won't retroactively match the new
 *   term; only future, undated occurrences will.
 *
 * Pure/static, no WordPress hooks of its own — mirrors ESSF_OFX_Parser /
 * ESSF_Category in style. Its single entry point, detect_for_new_entry(),
 * is called from ESSF_CPT::insert_entry() for every newly created entry, so
 * a plan is created automatically as soon as its naming convention appears —
 * no manual/backfill trigger exists.
 *
 * Silently skips a title whose target term already exists (`term_exists()`)
 * — safe to call repeatedly, additive only, never touches/renames existing
 * entries or terms.
 */
class ESSF_Plan_Detector {

	const PT_MONTHS = [
		'jan' => 1,
		'fev' => 2,
		'mar' => 3,
		'abr' => 4,
		'mai' => 5,
		'jun' => 6,
		'jul' => 7,
		'ago' => 8,
		'set' => 9,
		'out' => 10,
		'nov' => 11,
		'dez' => 12,
	];

	/* ── Pattern detectors ──────────────────────────────────────── */

	public static function is_loan_title( string $title ): bool {
		return 0 === stripos( $title, 'Empréstimo ' ) || 0 === stripos( $title, 'Loan ' );
	}

	/**
	 * @return array{base: string, index: int, n: int}|null
	 */
	public static function match_installment_suffix( string $title ): ?array {
		if ( ! preg_match( '/^(.+?)\s+(\d{1,3})\/(\d{1,3})$/u', trim( $title ), $m ) ) {
			return null;
		}
		$index = (int) $m[2];
		$n     = (int) $m[3];
		if ( $index < 1 || $index > $n || $n < 2 || $n > 600 ) {
			return null;
		}
		return [
			'base'  => trim( $m[1] ),
			'index' => $index,
			'n'     => $n,
		];
	}

	/**
	 * @return array{base: string, month: int, year: int}|null
	 */
	public static function match_bill_suffix( string $title ): ?array {
		$months = implode( '|', array_keys( self::PT_MONTHS ) );
		if ( ! preg_match( '/^(.+?)\s+(' . $months . ')[\-\/](\d{4})$/iu', trim( $title ), $m ) ) {
			return null;
		}
		return [
			'base'  => trim( $m[1] ),
			'month' => self::PT_MONTHS[ strtolower( $m[2] ) ],
			'year'  => (int) $m[3],
		];
	}

	/* ── Orchestration ──────────────────────────────────────────── */

	/**
	 * Runs the same detection against a single just-created entry title and,
	 * if it matches a pattern with no existing backing term, creates one
	 * immediately — no review step (see ESSF_CPT::insert_entry()).
	 */
	public static function detect_for_new_entry( string $title ): void {
		if ( self::is_loan_title( $title ) ) {
			if ( term_exists( $title, ESSF_Loan_CPT::TAXONOMY ) ) {
				return;
			}
			$posts = ESSF_CPT::find_entries_by_title( $title );
			if ( ! $posts ) {
				return;
			}
			ESSF_Loan_CPT::create_term_from_detection( $title, self::infer_loan_meta( $posts, $title ) );
			return;
		}

		$fin = self::match_installment_suffix( $title );
		if ( $fin ) {
			if ( term_exists( $fin['base'], ESSF_Financing_CPT::TAXONOMY ) ) {
				return;
			}
			$posts = ESSF_CPT::find_entries_by_title( $title );
			if ( ! $posts ) {
				return;
			}
			$matches = array_map(
				static function ( $post ) use ( $fin ) {
					return [
						'index' => $fin['index'],
						'n'     => $fin['n'],
						'post'  => $post,
					];
				},
				$posts
			);
			ESSF_Financing_CPT::create_term_from_detection( $fin['base'], self::infer_financing_meta( $matches ) );
			return;
		}

		$bill = self::match_bill_suffix( $title );
		if ( $bill ) {
			if ( term_exists( $bill['base'], ESSF_Bill_CPT::TAXONOMY ) ) {
				return;
			}
			$posts = ESSF_CPT::find_entries_by_title( $title );
			if ( ! $posts ) {
				return;
			}
			ESSF_Bill_CPT::create_term_from_detection( $bill['base'], self::infer_bill_meta( $posts ) );
		}
	}

	/* ── Meta inference ─────────────────────────────────────────── */

	/**
	 * @param WP_Post[] $posts All entries sharing this exact loan title.
	 */
	private static function infer_loan_meta( array $posts, string $title ): array {
		$sum    = 0.0;
		$latest = null;
		foreach ( $posts as $post ) {
			$sum   += (float) $post->post_content;
			$latest = ( ! $latest || strtotime( $post->post_date_gmt ) > strtotime( $latest->post_date_gmt ) ) ? $post : $latest;
		}

		$counterparty = trim( (string) preg_replace( '/^(Empréstimo|Loan)\s+/iu', '', $title ) );

		return [
			'essf_loan_principal' => (string) abs( $sum ),
			'essf_loan_is_lender' => $sum >= 0 ? '1' : '0',
			'essf_counterparty'   => $counterparty,
			'essf_loan_category'  => self::post_category_slug( $latest ),
		];
	}

	/**
	 * @param array<int, array{index: int, n: int, post: WP_Post}> $matches
	 */
	private static function infer_financing_meta( array $matches ): array {
		usort(
			$matches,
			static function ( $a, $b ) {
				return $a['index'] <=> $b['index'];
			}
		);

		$first = $matches[0];
		$last  = $matches[ count( $matches ) - 1 ];
		$n     = $first['n'];

		$interval_count = 1;
		if ( $last['index'] > $first['index'] ) {
			$index_gap      = $last['index'] - $first['index'];
			$days_gap       = ( strtotime( substr( $last['post']->post_date_gmt, 0, 10 ) ) - strtotime( substr( $first['post']->post_date_gmt, 0, 10 ) ) ) / DAY_IN_SECONDS;
			$interval_count = max( 1, (int) round( $days_gap / ( 30.44 * $index_gap ) ) );
		}

		$start_ts = strtotime( '-' . ( ( $first['index'] - 1 ) * $interval_count ) . ' months', strtotime( substr( $first['post']->post_date_gmt, 0, 10 ) ) );

		$amount_abs = abs( (float) $first['post']->post_content );
		$is_income  = (float) $first['post']->post_content >= 0;

		return [
			'essf_financing_total'          => (string) ( $amount_abs * $n ),
			'essf_financing_is_income'      => $is_income ? '1' : '0',
			'essf_financing_installments'   => (string) $n,
			'essf_financing_start_date'     => date_i18n( 'Y-m-d', $start_ts ),
			'essf_financing_interval_unit'  => 'month',
			'essf_financing_interval_count' => (string) $interval_count,
			'essf_financing_category'       => self::post_category_slug( $first['post'] ),
		];
	}

	/**
	 * @param WP_Post[] $posts All entries sharing this bill's base title
	 *                         (across different month-year suffixes).
	 */
	private static function infer_bill_meta( array $posts ): array {
		usort(
			$posts,
			static function ( $a, $b ) {
				return strtotime( $a->post_date_gmt ) <=> strtotime( $b->post_date_gmt );
			}
		);
		$latest = $posts[ count( $posts ) - 1 ];
		$amount = (float) $latest->post_content;

		return [
			'essf_bill_amount'    => (string) abs( $amount ),
			'essf_bill_is_income' => $amount >= 0 ? '1' : '0',
			'essf_bill_category'  => self::post_category_slug( $latest ),
			'essf_bill_due_day'   => date_i18n( 'j', strtotime( substr( $latest->post_date_gmt, 0, 10 ) ) ),
			'essf_bill_active'    => '1',
		];
	}

	private static function post_category_slug( WP_Post $post ): string {
		$terms = wp_get_post_terms( $post->ID, ESSF_Category::TAXONOMY, [ 'fields' => 'slugs' ] );
		return ( ! is_wp_error( $terms ) && $terms ) ? $terms[0] : 'uncategorized';
	}
}
