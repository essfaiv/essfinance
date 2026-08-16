<?php
/**
 * EssFinance — WP-CLI commands
 *
 * @package EssFinance
 * @license GPL-2.0-or-later
 */

defined( 'ABSPATH' ) || exit;

/**
 * Read-only OFX diagnostics for EssFinance. Registered only under WP-CLI.
 */
class ESSF_CLI {

	public function __construct() {
		WP_CLI::add_command( 'essf', $this );
	}

	/**
	 * Parse an OFX/QFX file and print its transactions as a CSV or Markdown table.
	 *
	 * This command only parses and formats — it never touches the database,
	 * dedupes against existing entries, or writes anything.
	 *
	 * ## OPTIONS
	 *
	 * <file>
	 * : Path to the OFX/QFX file to parse.
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: csv
	 * options:
	 *   - csv
	 *   - md
	 * ---
	 *
	 * [--output=<path>]
	 * : Write the result to a file instead of STDOUT.
	 *
	 * ## EXAMPLES
	 *
	 *     wp essf ofx-view statement.ofx --format=md
	 *     wp essf ofx-view statement.ofx --output=statement.csv
	 *
	 * @subcommand ofx-view
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function ofx_view( array $args, array $assoc_args ): void {
		[ $file ] = $args;

		if ( ! is_readable( $file ) ) {
			WP_CLI::error( "File not readable: {$file}" );
		}

		$content = file_get_contents( $file );
		if ( false === $content ) {
			WP_CLI::error( "Could not read file: {$file}" );
		}

		$format = $assoc_args['format'] ?? 'csv';
		if ( ! in_array( $format, [ 'csv', 'md' ], true ) ) {
			WP_CLI::error( 'Invalid --format. Use csv or md.' );
		}

		$rows = self::format_rows( ESSF_OFX_Parser::parse( $content ) );

		$output = 'md' === $format ? self::format_markdown( $rows ) : self::format_csv( $rows );

		$output_path = $assoc_args['output'] ?? '';
		if ( $output_path ) {
			file_put_contents( $output_path, $output ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- WP_Filesystem isn't initialized in a WP-CLI context; this is a local CLI --output path, not a web request
			WP_CLI::success( "Wrote {$output_path}" );
			return;
		}

		WP_CLI::log( $output );
	}

	/**
	 * Replay the description-suggestion pipeline chronologically against every
	 * already-imported OFX entry, to find every case where today's algorithm
	 * would NOT propose the title actually chosen — i.e. would still require
	 * manual editing. Every row is included regardless of match, so the full
	 * output also serves as a complete export of the OFX "knowledge base"
	 * (raw memo + chosen title for every entry).
	 *
	 * Read-only — never writes to the database.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: csv
	 * options:
	 *   - csv
	 *   - md
	 * ---
	 *
	 * [--output=<path>]
	 * : Write the result to a file instead of STDOUT.
	 *
	 * [--only-opportunities]
	 * : Only include rows where today's algorithm would NOT have proposed the actual title or the actual category.
	 *
	 * ## EXAMPLES
	 *
	 *     wp essf ofx-audit --only-opportunities --format=md
	 *     wp essf ofx-audit --output=ofx-knowledge-base.csv
	 *
	 * @subcommand ofx-audit
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function ofx_audit( array $args, array $assoc_args ): void {
		$format = $assoc_args['format'] ?? 'csv';
		if ( ! in_array( $format, [ 'csv', 'md' ], true ) ) {
			WP_CLI::error( 'Invalid --format. Use csv or md.' );
		}

		$rows = self::replay_suggestions( self::query_ofx_history(), ESSF_Category::category_glossary_history() );

		if ( isset( $assoc_args['only-opportunities'] ) ) {
			$rows = array_values(
				array_filter(
					$rows,
					static function ( array $row ): bool {
						return 'no' === $row['Auto-fill'] || 'no' === $row['Category Auto-fill'];
					}
				)
			);
		}

		$output = 'md' === $format ? self::format_markdown( $rows ) : self::format_csv( $rows );

		$output_path = $assoc_args['output'] ?? '';
		if ( $output_path ) {
			file_put_contents( $output_path, $output ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- WP_Filesystem isn't initialized in a WP-CLI context; this is a local CLI --output path, not a web request
			WP_CLI::success( "Wrote {$output_path}" );
			return;
		}

		WP_CLI::log( $output );
	}

	/**
	 * Query every essf_cashflow post carrying a learned OFX memo,
	 * chronologically (oldest first, by _order_date then post ID) — the same
	 * underlying data as ESSF_Admin_Page::ofx_memo_history()/
	 * ofx_glossary_entries(), but ordered and carrying the post ID, since
	 * neither of those (private, on an admin-only class not loaded under
	 * WP-CLI) fits this command's needs.
	 *
	 * @return array<int, array{id: int, date: string, title: string, memo: string, category: string}>
	 */
	private static function query_ofx_history(): array {
		$posts = get_posts(
			[
				'post_type'      => 'essf_cashflow',
				'post_status'    => [ 'pending', 'paid' ],
				'posts_per_page' => -1,
				'meta_key'       => '_essf_ofx_memo',
			]
		);

		$entries = [];
		foreach ( $posts as $post ) {
			$memo = get_post_meta( $post->ID, '_essf_ofx_memo', true );
			if ( ! $memo ) {
				continue;
			}
			$terms     = wp_get_post_terms( $post->ID, ESSF_Category::TAXONOMY, [ 'fields' => 'names' ] );
			$entries[] = [
				'id'       => $post->ID,
				'date'     => (string) get_post_meta( $post->ID, '_order_date', true ),
				'title'    => $post->post_title,
				'memo'     => $memo,
				'category' => $terms[0] ?? __( 'Uncategorized', 'essfinance' ),
			];
		}

		usort(
			$entries,
			static function ( array $a, array $b ): int {
				return [ $a['date'], $a['id'] ] <=> [ $b['date'], $b['id'] ];
			}
		);

		return $entries;
	}

	/**
	 * Predict what description the import-review pipeline would propose for
	 * a raw OFX memo today, replicating the exact fallback order
	 * ESSF_Admin_Page::build_ofx_stage_rows() applies: a learned suggestion
	 * first, then parse_transfer(), then parse_purchase(), then
	 * parse_boleto(), then the bare NAME/MEMO fallback via
	 * ESSF_OFX_Parser::describe().
	 *
	 * @param string $raw_memo Raw OFX NAME/MEMO text.
	 * @param array  $history  Prior entries, shape per ESSF_OFX_Suggestions::suggest().
	 * @return array{title: string, source: string, score: string}
	 */
	public static function predict_description( string $raw_memo, array $history ): array {
		$suggestions = ESSF_OFX_Suggestions::suggest( $raw_memo, $history, 1 );
		if ( $suggestions ) {
			return [
				'title'  => $suggestions[0]['title'],
				'source' => 'suggestion',
				'score'  => number_format( $suggestions[0]['score'], 1 ),
			];
		}

		$transfer = ESSF_OFX_Parser::parse_transfer( $raw_memo );
		if ( null !== $transfer ) {
			return [
				'title'  => $transfer['title'],
				'source' => 'transfer',
				'score'  => '',
			];
		}

		$purchase = ESSF_OFX_Parser::parse_purchase( $raw_memo );
		if ( null !== $purchase ) {
			return [
				'title'  => $purchase,
				'source' => 'purchase',
				'score'  => '',
			];
		}

		$boleto = ESSF_OFX_Parser::parse_boleto( $raw_memo );
		if ( null !== $boleto ) {
			return [
				'title'  => $boleto,
				'source' => 'boleto',
				'score'  => '',
			];
		}

		return [
			'title'  => ESSF_OFX_Parser::describe(
				[
					'name' => '',
					'memo' => $raw_memo,
				]
			),
			'source' => 'fallback',
			'score'  => '',
		];
	}

	/**
	 * Predict what category the import-review pipeline would suggest for a raw
	 * OFX memo today: a learned OFX-memo suggestion first (matched against
	 * the raw memo, same as ESSF_Admin_Page::render_review_page()), then the
	 * category glossary (ESSF_Category::match_glossary() — descriptions the
	 * operator has categorized before, regardless of whether via OFX or
	 * not), then ESSF_Category::guess_slug_from_description() (keyword
	 * classifier) against the *resolved* description (same as
	 * ESSF_Admin_Page::build_ofx_stage_rows() — runs on the cleaned title,
	 * not the noisy raw memo), then 'Uncategorized'.
	 *
	 * @param string $raw_memo         Raw OFX NAME/MEMO text.
	 * @param string $description      Resolved description for this entry (predict_description()'s title).
	 * @param array  $history          Prior OFX-memo entries, shape per ESSF_OFX_Suggestions::suggest().
	 * @param array  $glossary_history From ESSF_Category::category_glossary_history(), built once by the caller — see replay_suggestions()'s note on why this one isn't chronologically replayed.
	 * @return array{title: string, source: string, score: string}
	 */
	public static function predict_category( string $raw_memo, string $description, array $history, array $glossary_history = [] ): array {
		$suggestions = ESSF_OFX_Suggestions::suggest( $raw_memo, $history, 1 );
		if ( $suggestions ) {
			return [
				'title'  => $suggestions[0]['title'],
				'source' => 'suggestion',
				'score'  => number_format( $suggestions[0]['score'], 1 ),
			];
		}

		$glossary_match = ESSF_Category::match_glossary( $description, $glossary_history );
		if ( $glossary_match ) {
			return [
				'title'  => ESSF_Category::label_for_slug( $glossary_match['slug'] ),
				'source' => 'glossary',
				'score'  => number_format( $glossary_match['score'], 1 ),
			];
		}

		$slug = ESSF_Category::guess_slug_from_description( $description );
		if ( 'uncategorized' !== $slug ) {
			return [
				'title'  => ESSF_Category::label_for_slug( $slug ),
				'source' => 'keyword',
				'score'  => '',
			];
		}

		return [
			'title'  => __( 'Uncategorized', 'essfinance' ),
			'source' => 'fallback',
			'score'  => '',
		];
	}

	/**
	 * Replay predict_description() chronologically against a full history of
	 * already-imported OFX entries (oldest first), comparing what today's
	 * algorithm would propose for each entry against the title actually
	 * chosen for it.
	 *
	 * Pure/dependency-free, mirroring ESSF_OFX_Suggestions's own design, so
	 * it's unit-testable without WordPress — $glossary_history is passed in
	 * (not fetched here) for the same reason: the caller (ofx_audit()) is
	 * where a real WordPress query belongs. Unlike $history (the OFX-memo
	 * suggestion history), the glossary is intentionally *not* replayed
	 * chronologically — it's built once from the *current* state of every
	 * categorized entry, since it represents the user's corrections as they
	 * stand today, not a point-in-time snapshot.
	 *
	 * @param array $entries          Chronologically ordered (oldest first) rows: {id: int, date: string, title: string, memo: string, category: string}.
	 * @param array $glossary_history From ESSF_Category::category_glossary_history().
	 * @return array<int, array{ID: int, Date: string, Title: string, 'Raw Memo': string, Predicted: string, Source: string, Score: string, 'Auto-fill': string, Category: string, 'Predicted Category': string, 'Category Auto-fill': string}>
	 */
	public static function replay_suggestions( array $entries, array $glossary_history = [] ): array {
		$rows        = [];
		$history     = [];
		$cat_history = [];

		foreach ( $entries as $entry ) {
			$category      = $entry['category'] ?? __( 'Uncategorized', 'essfinance' );
			$predicted     = self::predict_description( $entry['memo'], $history );
			$predicted_cat = self::predict_category( $entry['memo'], $predicted['title'], $cat_history, $glossary_history );

			$rows[] = [
				'ID'                 => $entry['id'],
				'Date'               => $entry['date'],
				'Title'              => $entry['title'],
				'Raw Memo'           => $entry['memo'],
				'Predicted'          => $predicted['title'],
				'Source'             => $predicted['source'],
				'Score'              => $predicted['score'],
				'Auto-fill'          => $predicted['title'] === $entry['title'] ? 'yes' : 'no',
				'Category'           => $category,
				'Predicted Category' => $predicted_cat['title'],
				'Category Auto-fill' => $predicted_cat['title'] === $category ? 'yes' : 'no',
			];

			$history[]     = [
				'memo'  => $entry['memo'],
				'title' => $entry['title'],
				'date'  => $entry['date'],
			];
			$cat_history[] = [
				'memo'  => $entry['memo'],
				'title' => $category,
				'date'  => $entry['date'],
			];
		}

		return $rows;
	}

	/**
	 * Map parsed OFX transactions to the export column shape.
	 *
	 * @param array $transactions Rows returned by ESSF_OFX_Parser::parse().
	 * @return array<int, array{Date: string, Amount: string, FITID: string, Description: string, 'Raw Memo': string}>
	 */
	public static function format_rows( array $transactions ): array {
		return array_map(
			static function ( array $trn ): array {
				return [
					'Date'        => $trn['due_date'],
					'Amount'      => number_format( (float) $trn['amount'], 2, '.', '' ),
					'FITID'       => $trn['fitid'],
					'Description' => ESSF_OFX_Parser::describe( $trn ),
					'Raw Memo'    => $trn['memo'],
				];
			},
			$transactions
		);
	}

	/**
	 * Format rows (each an associative array with identical keys) as CSV.
	 *
	 * @param array $rows Rows keyed by column name.
	 */
	public static function format_csv( array $rows ): string {
		if ( ! $rows ) {
			return '';
		}

		$handle = fopen( 'php://temp', 'r+' );
		fputcsv( $handle, array_keys( $rows[0] ), ',', '"', '\\' );
		foreach ( $rows as $row ) {
			fputcsv( $handle, $row, ',', '"', '\\' );
		}
		rewind( $handle );
		$csv = stream_get_contents( $handle );
		fclose( $handle );

		return $csv;
	}

	/**
	 * Format rows (each an associative array with identical keys) as a GFM table.
	 *
	 * @param array $rows Rows keyed by column name.
	 */
	public static function format_markdown( array $rows ): string {
		if ( ! $rows ) {
			return '';
		}

		$headers = array_keys( $rows[0] );
		$escape  = static function ( $value ): string {
			return str_replace( '|', '\\|', (string) $value );
		};

		$lines   = [];
		$lines[] = '| ' . implode( ' | ', $headers ) . ' |';
		$lines[] = '| ' . implode( ' | ', array_fill( 0, count( $headers ), '---' ) ) . ' |';
		foreach ( $rows as $row ) {
			$lines[] = '| ' . implode( ' | ', array_map( $escape, $row ) ) . ' |';
		}

		return implode( "\n", $lines ) . "\n";
	}
}
