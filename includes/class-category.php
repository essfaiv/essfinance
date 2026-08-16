<?php
/**
 * EssFinance — Category taxonomy
 *
 * @package EssFinance
 * @license GPL-2.0-or-later
 */

defined( 'ABSPATH' ) || exit;

class ESSF_Category {

	const TAXONOMY            = 'essf_cashflow_cat';
	const SEEDED_OPTION       = 'essf_category_seeded';
	const RELABEL_CRON_HOOK   = 'essf_relabel_categories';
	const COUNTS_FIXED_OPTION = 'essf_category_counts_fixed';

	/**
	 * Canonical category list, order and slugs sourced from documents/categories.md.
	 * Slugs are always English-derived and stable regardless of which label
	 * language gets seeded — CSV import/export and any future re-seed keys off
	 * slug, never label.
	 *
	 * @return array<string, array{en: string, pt_BR: string}>
	 */
	private static function term_definitions(): array {
		return [
			'income'                 => [
				'en'    => 'Income',
				'pt_BR' => 'Renda',
			],
			'pet-care'               => [
				'en'    => 'Pet care',
				'pt_BR' => 'Animais de estimação',
			],
			'service-subscriptions'  => [
				'en'    => 'Service subscriptions',
				'pt_BR' => 'Assinatura de serviços',
			],
			'food-and-drink'         => [
				'en'    => 'Food and drink',
				'pt_BR' => 'Comida e bebida',
			],
			'shopping'               => [
				'en'    => 'Shopping',
				'pt_BR' => 'Compras',
			],
			'bills'                  => [
				'en'    => 'Bills',
				'pt_BR' => 'Contas',
			],
			'personal-care'          => [
				'en'    => 'Personal care',
				'pt_BR' => 'Cuidados pessoais',
			],
			'donations'              => [
				'en'    => 'Donations',
				'pt_BR' => 'Doações',
			],
			'education'              => [
				'en'    => 'Education',
				'pt_BR' => 'Educação',
			],
			'loans'                  => [
				'en'    => 'Loans',
				'pt_BR' => 'Empréstimos',
			],
			'entertainment'          => [
				'en'    => 'Entertainment',
				'pt_BR' => 'Entretenimento',
			],
			'sports'                 => [
				'en'    => 'Sports',
				'pt_BR' => 'Esportes',
			],
			'miscellaneous-expenses' => [
				'en'    => 'Miscellaneous expenses',
				'pt_BR' => 'Gastos diversos',
			],
			'taxes'                  => [
				'en'    => 'Taxes',
				'pt_BR' => 'Impostos',
			],
			'investments'            => [
				'en'    => 'Investments',
				'pt_BR' => 'Investimentos',
			],
			'groceries'              => [
				'en'    => 'Groceries',
				'pt_BR' => 'Mercado',
			],
			'housing'                => [
				'en'    => 'Housing',
				'pt_BR' => 'Moradia',
			],
			'health'                 => [
				'en'    => 'Health',
				'pt_BR' => 'Saúde',
			],
			'insurance'              => [
				'en'    => 'Insurance',
				'pt_BR' => 'Seguros',
			],
			'services'               => [
				'en'    => 'Services',
				'pt_BR' => 'Serviços',
			],
			'financial-fees'         => [
				'en'    => 'Financial fees',
				'pt_BR' => 'Tarifas financeiras',
			],
			'transfers'              => [
				'en'    => 'Transfers',
				'pt_BR' => 'Transferências',
			],
			'transportation'         => [
				'en'    => 'Transportation',
				'pt_BR' => 'Transporte',
			],
			'travel'                 => [
				'en'    => 'Travel',
				'pt_BR' => 'Viagem',
			],
			'uncategorized'          => [
				'en'    => 'Uncategorized',
				'pt_BR' => 'Não categorizado',
			],
		];
	}

	public function __construct() {
		add_action( 'init', [ $this, 'register' ] );
		// Fixes already-wrong stored term counts on existing installs — see
		// maybe_fix_term_counts(). Priority 20 so it always runs after
		// register() (10) has registered the taxonomy on this same request.
		add_action( 'init', [ __CLASS__, 'maybe_fix_term_counts' ], 20 );

		// WPLANG is the site-language option (Settings → General). Existing
		// installs have it as an empty string (en_US default), so a first-time
		// language change is an update, not an add — but add_option_WPLANG is
		// hooked too for safety, in case that option row doesn't pre-exist.
		// Renaming 25 terms is cheap, but it's deferred to WP-Cron rather than
		// run inline here so saving Settings → General never waits on it.
		add_action( 'add_option_WPLANG', [ __CLASS__, 'schedule_relabel' ] );
		add_action( 'update_option_WPLANG', [ __CLASS__, 'schedule_relabel' ] );
		add_action( self::RELABEL_CRON_HOOK, [ __CLASS__, 'relabel_terms' ] );
	}

	public function register() {
		register_taxonomy(
			self::TAXONOMY,
			'essf_cashflow',
			[
				'labels'                => [
					'name'          => __( 'Categories', 'essfinance' ),
					'singular_name' => __( 'Category', 'essfinance' ),
					'search_items'  => __( 'Search Categories', 'essfinance' ),
					'all_items'     => __( 'All Categories', 'essfinance' ),
					'edit_item'     => __( 'Edit Category', 'essfinance' ),
					'update_item'   => __( 'Update Category', 'essfinance' ),
					'add_new_item'  => __( 'Add New Category', 'essfinance' ),
					'new_item_name' => __( 'New Category Name', 'essfinance' ),
					'menu_name'     => __( 'Categories', 'essfinance' ),
				],
				'hierarchical'          => false,
				'public'                => false,
				'show_ui'               => true,
				'show_in_menu'          => false,
				'show_admin_column'     => false,
				'show_in_quick_edit'    => true,
				'show_in_rest'          => false,
				'meta_box_cb'           => false,
				'query_var'             => false,
				'rewrite'               => false,
				// essf_cashflow entries are never post_status 'publish' (only
				// the custom 'pending'/'paid'), but WordPress's default term-
				// count callback hardcodes post_status = 'publish' in its
				// query — every count would stay stuck at zero otherwise.
				// _update_generic_term_count() is a WP core callback made
				// exactly for this: it counts term_relationships without
				// filtering by post_status at all.
				'update_count_callback' => '_update_generic_term_count',
			]
		);
	}

	/** 'pt_BR' or 'en', the same rule seed_terms()/label_for_slug()/relabel_terms() all use. */
	private static function seed_lang(): string {
		return ( 0 === strpos( get_locale(), 'pt_BR' ) ) ? 'pt_BR' : 'en';
	}

	/**
	 * Seed the 25 default terms once, choosing the PT-BR or EN label per term
	 * based on the site's locale at seed time. Idempotent — never re-runs (and
	 * so never resurrects a term an admin has since deliberately deleted).
	 */
	public static function seed_terms(): void {
		if ( get_option( self::SEEDED_OPTION ) ) {
			return;
		}

		$lang = self::seed_lang();

		foreach ( self::term_definitions() as $slug => $labels ) {
			if ( ! term_exists( $slug, self::TAXONOMY ) ) {
				wp_insert_term( $labels[ $lang ], self::TAXONOMY, [ 'slug' => $slug ] );
			}
		}

		update_option( self::SEEDED_OPTION, true, false );
	}

	/**
	 * Rename every seeded term still present to its label in the site's
	 * *current* language — hooked to the WPLANG option so switching the site
	 * language (Settings → General) relabels the 25 standard categories
	 * in place, rather than only affecting terms seeded from then on. Only
	 * touches the known slugs from term_definitions(); a category the admin
	 * added themselves has no translation to relabel to and is left alone.
	 * Renaming only changes the term's display name — the slug (and
	 * therefore every post's assignment to it) never changes.
	 */
	public static function relabel_terms(): void {
		$lang = self::seed_lang();

		foreach ( self::term_definitions() as $slug => $labels ) {
			$term = get_term_by( 'slug', $slug, self::TAXONOMY );
			if ( $term && $term->name !== $labels[ $lang ] ) {
				wp_update_term( $term->term_id, self::TAXONOMY, [ 'name' => $labels[ $lang ] ] );
			}
		}
	}

	/**
	 * Queue relabel_terms() on WP-Cron instead of running it inline, so a
	 * site-language change (Settings → General) never waits on it — hooked
	 * to the WPLANG option rather than called directly.
	 */
	public static function schedule_relabel(): void {
		if ( ! wp_next_scheduled( self::RELABEL_CRON_HOOK ) ) {
			wp_schedule_single_event( time(), self::RELABEL_CRON_HOOK );
		}
	}

	/**
	 * One-time fix for term counts stored before update_count_callback was
	 * set on this taxonomy — WordPress's default counter never counted an
	 * essf_cashflow entry (see register()), so any term used before this fix
	 * shipped is stuck showing 0 until recounted here. Idempotent, like
	 * seed_terms() — runs once per site, not on every request.
	 */
	public static function maybe_fix_term_counts(): void {
		if ( get_option( self::COUNTS_FIXED_OPTION ) ) {
			return;
		}

		$term_ids = get_terms(
			[
				'taxonomy'   => self::TAXONOMY,
				'hide_empty' => false,
				'fields'     => 'ids',
			]
		);
		if ( ! is_wp_error( $term_ids ) && $term_ids ) {
			wp_update_term_count_now( $term_ids, self::TAXONOMY );
		}

		update_option( self::COUNTS_FIXED_OPTION, true, false );
	}

	public static function activate(): void {
		( new self() )->register();
		self::seed_terms();
	}

	/**
	 * Resolve a slug to its term ID, self-healing by recreating a seeded term
	 * that was deleted (e.g. via edit-tags.php) so "every post has exactly one
	 * category" stays true even after manual taxonomy cleanup.
	 */
	public static function term_id_for_slug( string $slug ): int {
		$term = get_term_by( 'slug', $slug, self::TAXONOMY );
		if ( $term ) {
			return (int) $term->term_id;
		}

		$labels  = self::term_definitions()[ $slug ] ?? null;
		$name    = $labels['en'] ?? ucfirst( str_replace( '-', ' ', $slug ) );
		$created = wp_insert_term( $name, self::TAXONOMY, [ 'slug' => $slug ] );

		return is_wp_error( $created ) ? 0 : (int) $created['term_id'];
	}

	public static function uncategorized_term_id(): int {
		return self::term_id_for_slug( 'uncategorized' );
	}

	/**
	 * Display label for a slug without a live term/DB lookup, in the site's
	 * seed-time locale (PT-BR vs EN, same rule as seed_terms()). Falls back
	 * to the raw slug for an unrecognized one.
	 */
	public static function label_for_slug( string $slug ): string {
		$labels = self::term_definitions()[ $slug ] ?? null;
		if ( ! $labels ) {
			return $slug;
		}

		return $labels[ self::seed_lang() ];
	}

	/**
	 * All terms in their curated seed order (term_id ascending) rather than
	 * alphabetical — alphabetical order differs between the EN and PT-BR label
	 * sets and would scramble the intentional grouping either way.
	 *
	 * @return WP_Term[]
	 */
	public static function get_ordered_terms(): array {
		$terms = get_terms(
			[
				'taxonomy'   => self::TAXONOMY,
				'hide_empty' => false,
				'orderby'    => 'term_id',
				'order'      => 'ASC',
			]
		);

		return is_wp_error( $terms ) ? [] : $terms;
	}

	/**
	 * Keyword rules for guess_slug_from_description(). Keywords are English
	 * words routed through __() — like every other user-facing string in
	 * this plugin — so the actual PT-BR keyword text lives only in
	 * languages/essfinance-pt_BR.po, never as a literal non-English string in
	 * this file. Brand names, acronyms, and loanwords that read identically
	 * in both languages (Netflix, IPVA, CDB, Uber, hotel, …) are the
	 * deliberate exception — they're identifiers, not language content, so
	 * they're listed bare.
	 *
	 * `guess_slug_from_description()` matches each keyword's English form
	 * *and* its PT-BR translation (see ptbr()) against the description, so
	 * this works against both an English description (the untranslated
	 * msgid) and a Portuguese one (the .po msgstr) — independent of the
	 * site's active locale, since ptbr() reads the .po file directly rather
	 * than relying on get_locale()/load_textdomain().
	 *
	 * Declaration order only matters as a tie-breaker (see
	 * guess_slug_from_description()) — a category still matches regardless
	 * of where it's declared, so `groceries` is placed before
	 * `food-and-drink` (e.g. "Comida...Bodega..." should read as a market
	 * run, not a meal) and `entertainment` before `food-and-drink` (e.g.
	 * "Lazer comida cinema..." should read as an outing, not a meal) to
	 * resolve real equal-length ties found in a sample export the way a
	 * human would.
	 *
	 * `miscellaneous-expenses` and `uncategorized` intentionally have no
	 * keywords: `miscellaneous-expenses` stays a manually-assigned catch-all,
	 * distinct from the algorithmic no-match fallback (`uncategorized`).
	 *
	 * @return array<string, array<int, string>>
	 */
	private static function keyword_map(): array {
		return [
			'loans'                 => [ __( 'loan', 'essfinance' ) ],
			'income'                => [ __( 'income received', 'essfinance' ), __( 'salary', 'essfinance' ), 'pro-labore', __( 'profit', 'essfinance' ) ],
			'transfers'             => [ __( 'transfer', 'essfinance' ), __( 'refund', 'essfinance' ), 'pix' ],
			'pet-care'              => [ 'pet shop', 'petshop', __( 'pet', 'essfinance' ), __( 'grooming', 'essfinance' ), __( 'veterinary', 'essfinance' ), __( 'pet food', 'essfinance' ) ],
			'service-subscriptions' => [ __( 'subscription', 'essfinance' ), 'copilot', 'netflix', 'spotify', 'streaming', 'amazon prime', 'youtube premium' ],
			'health'                => [ __( 'pharmacy', 'essfinance' ), __( 'dentist', 'essfinance' ), __( 'doctor', 'essfinance' ), __( 'appointment', 'essfinance' ), __( 'medical exam', 'essfinance' ), __( 'health plan', 'essfinance' ), 'hospital' ],
			'insurance'             => [ __( 'insurance', 'essfinance' ) ],
			'taxes'                 => [ 'ipva', __( 'tax', 'essfinance' ), 'iptu', 'irpf', __( 'licensing', 'essfinance' ) ],
			'investments'           => [ __( 'investment', 'essfinance' ), __( 'treasury bond', 'essfinance' ), 'cdb', __( 'stocks', 'essfinance' ) ],
			'housing'               => [ __( 'housing', 'essfinance' ), __( 'condo fee', 'essfinance' ), __( 'rent', 'essfinance' ), __( 'mortgage', 'essfinance' ) ],
			'groceries'             => [ __( 'groceries', 'essfinance' ), __( 'supermarket', 'essfinance' ), __( 'corner store', 'essfinance' ), 'komprão', __( 'wholesale', 'essfinance' ), __( 'produce market', 'essfinance' ) ],
			'entertainment'         => [ __( 'movie theater', 'essfinance' ), __( 'leisure', 'essfinance' ), __( 'ticket', 'essfinance' ), __( 'amusement park', 'essfinance' ) ],
			'food-and-drink'        => [ __( 'food', 'essfinance' ), __( 'restaurant', 'essfinance' ), __( 'lunch', 'essfinance' ), __( 'dinner', 'essfinance' ), __( 'snack', 'essfinance' ), 'mcdonald', 'ifood', __( 'coffee shop', 'essfinance' ), __( 'bakery', 'essfinance' ) ],
			'education'             => [ __( 'school', 'essfinance' ), __( 'college', 'essfinance' ), __( 'course', 'essfinance' ), __( 'entrance exam prep', 'essfinance' ), __( 'university', 'essfinance' ), __( 'enrollment', 'essfinance' ) ],
			'donations'             => [ __( 'donation', 'essfinance' ), __( 'tithe', 'essfinance' ) ],
			'sports'                => [ __( 'gym', 'essfinance' ), 'gympass', 'personal trainer', __( 'soccer', 'essfinance' ) ],
			'personal-care'         => [ __( 'hairdresser', 'essfinance' ), __( 'beauty salon', 'essfinance' ), 'manicure', __( 'barbershop', 'essfinance' ) ],
			'services'              => [ __( 'lawyer', 'essfinance' ), __( 'service', 'essfinance' ), __( 'cleaning', 'essfinance' ), __( 'plumber', 'essfinance' ), __( 'electrician', 'essfinance' ), __( 'maintenance', 'essfinance' ) ],
			'financial-fees'        => [ __( 'fee', 'essfinance' ), __( 'account maintenance fee', 'essfinance' ), __( 'bank fee', 'essfinance' ), __( 'annual fee', 'essfinance' ), 'iof', __( 'interest', 'essfinance' ) ],
			'transportation'        => [ __( 'transportation', 'essfinance' ), 'uber', __( 'fuel', 'essfinance' ), __( 'parking', 'essfinance' ), __( 'toll', 'essfinance' ), __( 'fine', 'essfinance' ), __( 'collision', 'essfinance' ), __( 'car', 'essfinance' ) ],
			'shopping'              => [ 'mercado livre', 'meli', 'aliexpress', __( 'purchase', 'essfinance' ), 'shopping' ],
			'bills'                 => [ __( 'electricity', 'essfinance' ), __( 'power bill', 'essfinance' ), 'internet', __( 'phone bill', 'essfinance' ) ],
			'travel'                => [ __( 'travel', 'essfinance' ), 'hotel', __( 'airfare', 'essfinance' ), __( 'lodging', 'essfinance' ), 'airbnb' ],
		];
	}

	/**
	 * PT-BR translations of keyword_map()'s English msgids, read directly
	 * from languages/essfinance-pt_BR.po — independent of get_locale()/
	 * load_textdomain(), so matching against Portuguese descriptions works
	 * regardless of the site's active locale (this plugin's users write
	 * entries in whatever language they choose, not necessarily the site's
	 * configured one). Pure file I/O + regex, no WordPress functions, so
	 * this stays usable from guess_slug_from_description()'s pure context.
	 *
	 * @return array<string, string> msgid => msgstr, translated entries only.
	 */
	private static function ptbr_translations(): array {
		static $translations = null;
		if ( null !== $translations ) {
			return $translations;
		}

		$translations = [];
		$po_file      = defined( 'ESSF_PATH' ) ? ESSF_PATH . 'languages/essfinance-pt_BR.po' : '';
		$content      = ( $po_file && is_readable( $po_file ) ) ? file_get_contents( $po_file ) : false;
		if ( false === $content ) {
			return $translations;
		}

		foreach ( preg_split( '/\n{2,}/', $content ) as $block ) {
			if ( ! preg_match( '/^msgid\s+"((?:[^"\\\\]|\\\\.)*)"/m', $block, $id_match )
				|| ! preg_match( '/^msgstr\s+"((?:[^"\\\\]|\\\\.)*)"/m', $block, $str_match ) ) {
				continue;
			}
			$msgid  = stripcslashes( $id_match[1] );
			$msgstr = stripcslashes( $str_match[1] );
			if ( '' !== $msgid && '' !== $msgstr ) {
				$translations[ $msgid ] = $msgstr;
			}
		}

		return $translations;
	}

	/** PT-BR translation of an English keyword, or the keyword itself when untranslated. */
	private static function ptbr( string $msgid ): string {
		return self::ptbr_translations()[ $msgid ] ?? $msgid;
	}

	/**
	 * Fold common Portuguese diacritics to their base letter, so a single
	 * canonical keyword spelling (however it's written in the .po file)
	 * matches both accented and unaccented real-world descriptions (e.g.
	 * "condomínio" and "condominio") without listing every variant.
	 */
	private static function fold_accents( string $text ): string {
		static $map = [
			'á' => 'a',
			'à' => 'a',
			'ã' => 'a',
			'â' => 'a',
			'ä' => 'a',
			'é' => 'e',
			'è' => 'e',
			'ê' => 'e',
			'ë' => 'e',
			'í' => 'i',
			'ì' => 'i',
			'î' => 'i',
			'ï' => 'i',
			'ó' => 'o',
			'ò' => 'o',
			'õ' => 'o',
			'ô' => 'o',
			'ö' => 'o',
			'ú' => 'u',
			'ù' => 'u',
			'û' => 'u',
			'ü' => 'u',
			'ç' => 'c',
			'ñ' => 'n',
		];
		return strtr( $text, $map );
	}

	/**
	 * Guess a category slug from a curated entry description using the
	 * keyword rules above, falling back to 'uncategorized' when nothing
	 * matches. Pure/WordPress-free by design — used from
	 * ESSF_Admin_Page::build_ofx_stage_rows(), which is deliberately
	 * unit-testable without mocking WordPress.
	 *
	 * Each keyword is checked in both its English (msgid) and PT-BR (.po
	 * msgstr) form, both accent-folded, against the accent-folded
	 * description. The *longest* matching variant wins, not the first
	 * category checked — e.g. "Compra Laptop Beatriz Mercado Livre" contains
	 * both the brand "mercado livre" (shopping) and the substring "mercado"
	 * (from "groceries" → "mercado"); picking the longer match lets the more
	 * specific phrase win regardless of keyword_map()'s declaration order.
	 * Equal-length ties fall back to declaration order.
	 */
	public static function guess_slug_from_description( string $description ): string {
		$haystack  = self::fold_accents( mb_strtolower( $description ) );
		$best_slug = '';
		$best_len  = 0;

		foreach ( self::keyword_map() as $slug => $keywords ) {
			foreach ( $keywords as $keyword ) {
				foreach ( array_unique( [ $keyword, self::ptbr( $keyword ) ] ) as $variant ) {
					$variant = self::fold_accents( mb_strtolower( $variant ) );
					$len     = mb_strlen( $variant );
					if ( $len > $best_len && false !== mb_stripos( $haystack, $variant ) ) {
						$best_slug = $slug;
						$best_len  = $len;
					}
				}
			}
		}

		return '' !== $best_slug ? $best_slug : 'uncategorized';
	}
}
