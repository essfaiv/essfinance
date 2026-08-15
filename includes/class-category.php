<?php
/**
 * EssFinance — Category taxonomy
 *
 * @package EssFinance
 * @license GPL-2.0-or-later
 */

defined( 'ABSPATH' ) || exit;

class ESSF_Category {

	const TAXONOMY      = 'essf_cashflow_cat';
	const SEEDED_OPTION = 'essf_category_seeded';

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
	}

	public function register() {
		register_taxonomy(
			self::TAXONOMY,
			'essf_cashflow',
			[
				'labels'             => [
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
				'hierarchical'       => false,
				'public'             => false,
				'show_ui'            => true,
				'show_in_menu'       => false,
				'show_admin_column'  => false,
				'show_in_quick_edit' => true,
				'show_in_rest'       => false,
				'meta_box_cb'        => false,
				'query_var'          => false,
				'rewrite'            => false,
			]
		);
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

		$lang = ( 0 === strpos( get_locale(), 'pt_BR' ) ) ? 'pt_BR' : 'en';

		foreach ( self::term_definitions() as $slug => $labels ) {
			if ( ! term_exists( $slug, self::TAXONOMY ) ) {
				wp_insert_term( $labels[ $lang ], self::TAXONOMY, [ 'slug' => $slug ] );
			}
		}

		update_option( self::SEEDED_OPTION, true, false );
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

		$lang = ( 0 === strpos( get_locale(), 'pt_BR' ) ) ? 'pt_BR' : 'en';
		return $labels[ $lang ];
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
	 * PT-BR keyword rules for guess_slug_from_description(), derived from a
	 * real export of this plugin's own data. Declaration order only matters
	 * as a tie-breaker (see guess_slug_from_description()) — a category
	 * still matches regardless of where it's declared, so `groceries` is
	 * placed before `food-and-drink` (e.g. "Comida...Bodega..." should read
	 * as a market run, not a meal) and `entertainment` before
	 * `food-and-drink` (e.g. "Lazer comida cinema..." should read as an
	 * outing, not a meal) to resolve real equal-length ties found in that
	 * export the way a human would.
	 *
	 * `miscellaneous-expenses` and `uncategorized` intentionally have no
	 * keywords: `miscellaneous-expenses` stays a manually-assigned catch-all,
	 * distinct from the algorithmic no-match fallback (`uncategorized`).
	 *
	 * @return array<string, array<int, string>>
	 */
	private static function keyword_map(): array {
		return [
			'loans'                 => [ 'emprestimo', 'empréstimo' ],
			'income'                => [ 'recebimento', 'salario', 'salário', 'pro-labore', 'pró-labore', 'prolabore', 'lucro' ],
			'transfers'             => [ 'transferencia', 'transferência', 'reembolso', 'pix enviado', 'pix recebido' ],
			'pet-care'              => [ 'pet shop', 'petshop', 'animais', 'animal', 'cachorro', 'banho e tosa', 'banho cão', 'banho cao', 'veterinari', 'ração', 'racao' ],
			'service-subscriptions' => [ 'assinatura', 'copilot', 'netflix', 'spotify', 'streaming', 'amazon prime', 'youtube premium' ],
			'health'                => [ 'farmacia', 'farmácia', 'dentista', 'medico', 'médico', 'consulta', 'exame', 'plano de saude', 'plano de saúde', 'hospital' ],
			'insurance'             => [ 'seguro' ],
			'taxes'                 => [ 'ipva', 'imposto', 'iptu', 'irpf', 'licenciamento' ],
			'investments'           => [ 'investimento', 'aplicação', 'aplicacao', 'tesouro direto', 'cdb', 'ações', 'acoes' ],
			'housing'               => [ 'habitação', 'habitacao', 'condomínio', 'condominio', 'aluguel', 'financiamento imóvel', 'financiamento imovel', 'prestação casa', 'prestacao casa' ],
			'groceries'             => [ 'mercado', 'supermercado', 'bodega', 'komprão', 'komprao', 'atacad', 'hortifruti' ],
			'entertainment'         => [ 'cinema', 'lazer', 'ingresso', 'parque' ],
			'food-and-drink'        => [ 'comida', 'restaurante', 'almoço', 'almoco', 'jantar', 'lanche', 'mcdonald', 'ifood', 'café', 'cafe', 'padaria' ],
			'education'             => [ 'escola', 'faculdade', 'curso', 'vestibular', 'universidade', 'matrícula', 'matricula' ],
			'donations'             => [ 'doação', 'doacao', 'dízimo', 'dizimo' ],
			'sports'                => [ 'academia', 'gympass', 'personal trainer', 'futebol' ],
			'personal-care'         => [ 'cabeleireiro', 'salão de beleza', 'salao de beleza', 'manicure', 'barbearia' ],
			'services'              => [ 'advogado', 'serviço', 'servico', 'limpeza', 'encanador', 'eletricista', 'manutenção', 'manutencao' ],
			'financial-fees'        => [ 'tarifa', 'tarifa de manutenção', 'tarifa de manutencao', 'taxa bancária', 'taxa bancaria', 'anuidade', 'iof', 'juros' ],
			'transportation'        => [ 'transporte', 'uber', 'combustível', 'combustivel', 'estacionamento', 'pedágio', 'pedagio', 'multa', 'colisão', 'colisao', 'carro' ],
			'shopping'              => [ 'mercado livre', 'compra', 'shopping', 'meli', 'aliexpress' ],
			'bills'                 => [ 'eletricidade', 'energia elétrica', 'energia eletrica', 'internet', 'telefone' ],
			'travel'                => [ 'viagem', 'hotel', 'passagem aérea', 'passagem aerea', 'hospedagem', 'airbnb' ],
		];
	}

	/**
	 * Guess a category slug from a curated entry description using the
	 * keyword rules above, falling back to 'uncategorized' when nothing
	 * matches. Pure/WordPress-free by design — used from
	 * ESSF_Admin_Page::build_ofx_stage_rows(), which is deliberately
	 * unit-testable without mocking WordPress.
	 *
	 * The *longest* matching keyword wins, not the first category checked —
	 * e.g. "Compra Laptop Beatriz Mercado Livre" contains both "mercado"
	 * (groceries) and the more specific "mercado livre" (shopping); picking
	 * the longer match lets the more specific phrase win regardless of
	 * keyword_map()'s declaration order. Equal-length ties fall back to
	 * declaration order.
	 */
	public static function guess_slug_from_description( string $description ): string {
		$haystack  = mb_strtolower( $description );
		$best_slug = '';
		$best_len  = 0;

		foreach ( self::keyword_map() as $slug => $keywords ) {
			foreach ( $keywords as $keyword ) {
				$len = mb_strlen( $keyword );
				if ( $len > $best_len && false !== mb_stripos( $haystack, $keyword ) ) {
					$best_slug = $slug;
					$best_len  = $len;
				}
			}
		}

		return '' !== $best_slug ? $best_slug : 'uncategorized';
	}
}
