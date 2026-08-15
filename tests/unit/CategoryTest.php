<?php
/**
 * Tests for ESSF_Category.
 *
 * @package EssFinance\Tests
 * @license GPL-2.0-or-later
 */

declare( strict_types=1 );

namespace EssFinance\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

class CategoryTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_register_calls_register_taxonomy(): void {
		Functions\expect( 'register_taxonomy' )
			->once()
			->with(
				'essf_cashflow_cat',
				'essf_cashflow',
				\Mockery::type( 'array' )
			);
		Functions\expect( '__' )->andReturnFirstArg();

		$category = new \ESSF_Category();
		$category->register();
		$this->addToAssertionCount( 1 );
	}

	public function test_constructor_hooks_register_to_init(): void {
		Monkey\Actions\expectAdded( 'init' )
			->once()
			->with( \Mockery::type( 'array' ) );

		new \ESSF_Category();
		$this->addToAssertionCount( 1 );
	}

	public function test_seed_terms_noop_when_already_seeded(): void {
		Functions\expect( 'get_option' )
			->once()
			->with( 'essf_category_seeded' )
			->andReturn( true );
		Functions\expect( 'wp_insert_term' )->never();
		Functions\expect( 'update_option' )->never();

		\ESSF_Category::seed_terms();
		$this->addToAssertionCount( 1 );
	}

	public function test_seed_terms_inserts_all_25_terms_and_sets_flag(): void {
		Functions\expect( 'get_option' )
			->once()
			->with( 'essf_category_seeded' )
			->andReturn( false );
		Functions\expect( 'get_locale' )->once()->andReturn( 'en_US' );
		Functions\expect( 'term_exists' )->times( 25 )->andReturn( false );
		Functions\expect( 'wp_insert_term' )
			->times( 25 )
			->with(
				\Mockery::type( 'string' ),
				'essf_cashflow_cat',
				\Mockery::type( 'array' )
			);
		Functions\expect( 'update_option' )
			->once()
			->with( 'essf_category_seeded', true, false );

		\ESSF_Category::seed_terms();
		$this->addToAssertionCount( 1 );
	}

	public function test_seed_terms_uses_ptbr_labels_for_ptbr_locale(): void {
		Functions\expect( 'get_option' )->once()->andReturn( false );
		Functions\expect( 'get_locale' )->once()->andReturn( 'pt_BR' );
		Functions\expect( 'term_exists' )->andReturn( false );
		Functions\expect( 'update_option' )->once();

		Functions\expect( 'wp_insert_term' )
			->once()
			->with( 'Renda', 'essf_cashflow_cat', \Mockery::type( 'array' ) );
		Functions\expect( 'wp_insert_term' )
			->once()
			->with( 'Não categorizado', 'essf_cashflow_cat', \Mockery::type( 'array' ) );
		Functions\expect( 'wp_insert_term' )->zeroOrMoreTimes();

		\ESSF_Category::seed_terms();
		$this->addToAssertionCount( 1 );
	}

	public function test_seed_terms_uses_en_labels_for_non_ptbr_locale(): void {
		Functions\expect( 'get_option' )->once()->andReturn( false );
		Functions\expect( 'get_locale' )->once()->andReturn( 'es_ES' );
		Functions\expect( 'term_exists' )->andReturn( false );
		Functions\expect( 'update_option' )->once();

		Functions\expect( 'wp_insert_term' )
			->once()
			->with( 'Income', 'essf_cashflow_cat', \Mockery::type( 'array' ) );
		Functions\expect( 'wp_insert_term' )->zeroOrMoreTimes();

		\ESSF_Category::seed_terms();
		$this->addToAssertionCount( 1 );
	}

	public function test_seed_terms_skips_terms_that_already_exist(): void {
		Functions\expect( 'get_option' )->once()->andReturn( false );
		Functions\expect( 'get_locale' )->once()->andReturn( 'en_US' );
		Functions\expect( 'term_exists' )->times( 25 )->andReturn( true );
		Functions\expect( 'wp_insert_term' )->never();
		Functions\expect( 'update_option' )->once();

		\ESSF_Category::seed_terms();
		$this->addToAssertionCount( 1 );
	}

	public function test_term_id_for_slug_returns_existing_term_id(): void {
		Functions\expect( 'get_term_by' )
			->once()
			->with( 'slug', 'income', 'essf_cashflow_cat' )
			->andReturn( (object) [ 'term_id' => 42 ] );

		$this->assertSame( 42, \ESSF_Category::term_id_for_slug( 'income' ) );
	}

	public function test_term_id_for_slug_self_heals_missing_term(): void {
		Functions\expect( 'get_term_by' )->once()->andReturn( false );
		Functions\expect( 'wp_insert_term' )
			->once()
			->with( 'Income', 'essf_cashflow_cat', \Mockery::type( 'array' ) )
			->andReturn( [ 'term_id' => 99 ] );
		Functions\expect( 'is_wp_error' )->once()->andReturn( false );

		$this->assertSame( 99, \ESSF_Category::term_id_for_slug( 'income' ) );
	}

	public function test_term_id_for_slug_returns_zero_on_insert_error(): void {
		Functions\expect( 'get_term_by' )->once()->andReturn( false );
		Functions\expect( 'wp_insert_term' )->once()->andReturn( new \stdClass() );
		Functions\expect( 'is_wp_error' )->once()->andReturn( true );

		$this->assertSame( 0, \ESSF_Category::term_id_for_slug( 'income' ) );
	}

	public function test_uncategorized_term_id_looks_up_uncategorized_slug(): void {
		Functions\expect( 'get_term_by' )
			->once()
			->with( 'slug', 'uncategorized', 'essf_cashflow_cat' )
			->andReturn( (object) [ 'term_id' => 7 ] );

		$this->assertSame( 7, \ESSF_Category::uncategorized_term_id() );
	}

	public function test_activate_registers_and_seeds(): void {
		Functions\expect( 'register_taxonomy' )->once();
		Functions\expect( '__' )->andReturnFirstArg();
		Functions\expect( 'get_option' )->once()->andReturn( true ); // already seeded

		\ESSF_Category::activate();
		$this->addToAssertionCount( 1 );
	}

	public function test_get_ordered_terms_returns_terms(): void {
		$terms = [ (object) [ 'term_id' => 1 ] ];
		Functions\expect( 'get_terms' )
			->once()
			->with(
				[
					'taxonomy'   => 'essf_cashflow_cat',
					'hide_empty' => false,
					'orderby'    => 'term_id',
					'order'      => 'ASC',
				]
			)
			->andReturn( $terms );
		Functions\expect( 'is_wp_error' )->once()->andReturn( false );

		$this->assertSame( $terms, \ESSF_Category::get_ordered_terms() );
	}

	public function test_get_ordered_terms_returns_empty_array_on_wp_error(): void {
		Functions\expect( 'get_terms' )->once()->andReturn( new \stdClass() );
		Functions\expect( 'is_wp_error' )->once()->andReturn( true );

		$this->assertSame( [], \ESSF_Category::get_ordered_terms() );
	}

	// ── label_for_slug() ─────────────────────────────────────────────────

	public function test_label_for_slug_returns_english_label_for_en_locale(): void {
		Functions\expect( 'get_locale' )->once()->andReturn( 'en_US' );

		$this->assertSame( 'Groceries', \ESSF_Category::label_for_slug( 'groceries' ) );
	}

	public function test_label_for_slug_returns_ptbr_label_for_ptbr_locale(): void {
		Functions\expect( 'get_locale' )->once()->andReturn( 'pt_BR' );

		$this->assertSame( 'Mercado', \ESSF_Category::label_for_slug( 'groceries' ) );
	}

	public function test_label_for_slug_returns_slug_itself_for_unknown_slug(): void {
		$this->assertSame( 'not-a-real-slug', \ESSF_Category::label_for_slug( 'not-a-real-slug' ) );
	}

	// ── guess_slug_from_description() ────────────────────────────────────
	//
	// Regression cases pulled from a real export of this plugin's own data
	// (essfinance-export-2026-08-15-233924.csv), spot-checking the longest-
	// match-wins algorithm and its declared tie-breaks.

	public function test_guess_slug_prefers_longer_more_specific_keyword(): void {
		// "mercado livre" (shopping) is longer than the substring "mercado"
		// (groceries) — the more specific phrase must win.
		$this->assertSame( 'shopping', \ESSF_Category::guess_slug_from_description( 'Compra Laptop Beatriz Mercado Livre' ) );
	}

	public function test_guess_slug_breaks_tie_toward_entertainment_over_food(): void {
		// "comida" (food-and-drink) and "cinema" (entertainment) tie at 6
		// characters; entertainment is declared first for this exact case.
		$this->assertSame( 'entertainment', \ESSF_Category::guess_slug_from_description( 'Lazer comida cinema GNC Garten' ) );
	}

	public function test_guess_slug_breaks_tie_toward_groceries_over_food(): void {
		// "comida" (food-and-drink) and "bodega" (groceries) tie at 6
		// characters; groceries is declared first for this exact case.
		$this->assertSame( 'groceries', \ESSF_Category::guess_slug_from_description( 'Comida queijo Bodega da Gih' ) );
	}

	public function test_guess_slug_prefers_longer_match_over_shorter_unrelated_one(): void {
		// "dentista" (8, health) beats "limpeza" (7, services).
		$this->assertSame( 'health', \ESSF_Category::guess_slug_from_description( 'Dentista Limpeza Interação' ) );
	}

	public function test_guess_slug_treats_reembolso_as_a_transfer(): void {
		// "reembolso" (9, transfers) beats "comida" (6, food-and-drink).
		$this->assertSame( 'transfers', \ESSF_Category::guess_slug_from_description( "Reembolso Comida McDonald's" ) );
	}

	public function test_guess_slug_matches_common_categories(): void {
		$this->assertSame( 'loans', \ESSF_Category::guess_slug_from_description( 'Empréstimo Lucia' ) );
		$this->assertSame( 'income', \ESSF_Category::guess_slug_from_description( 'Recebimento Pro-labore Essfaiv' ) );
		$this->assertSame( 'transfers', \ESSF_Category::guess_slug_from_description( 'Transferência Esposa' ) );
		$this->assertSame( 'housing', \ESSF_Category::guess_slug_from_description( 'Condomínio Nov-2025' ) );
		$this->assertSame( 'bills', \ESSF_Category::guess_slug_from_description( 'Eletricidade Nov-2025' ) );
		$this->assertSame( 'transportation', \ESSF_Category::guess_slug_from_description( 'Transporte App' ) );
		$this->assertSame( 'taxes', \ESSF_Category::guess_slug_from_description( 'IPVA (1a. Cota) 2026' ) );
		$this->assertSame( 'services', \ESSF_Category::guess_slug_from_description( 'Advogado 1/2' ) );
		$this->assertSame( 'pet-care', \ESSF_Category::guess_slug_from_description( 'Animais Agro Pet Shop Vila Nova' ) );
	}

	public function test_guess_slug_falls_back_to_uncategorized_when_nothing_matches(): void {
		$this->assertSame( 'uncategorized', \ESSF_Category::guess_slug_from_description( 'Trimania' ) );
	}

	public function test_guess_slug_never_returns_empty_string(): void {
		$this->assertSame( 'uncategorized', \ESSF_Category::guess_slug_from_description( '' ) );
	}
}
