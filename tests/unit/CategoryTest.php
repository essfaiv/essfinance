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

	public function test_constructor_hooks_relabel_to_language_option_changes_via_cron(): void {
		Monkey\Actions\expectAdded( 'add_option_WPLANG' )
			->once()
			->with( [ 'ESSF_Category', 'schedule_relabel' ] );
		Monkey\Actions\expectAdded( 'update_option_WPLANG' )
			->once()
			->with( [ 'ESSF_Category', 'schedule_relabel' ] );
		Monkey\Actions\expectAdded( \ESSF_Category::RELABEL_CRON_HOOK )
			->once()
			->with( [ 'ESSF_Category', 'relabel_terms' ] );

		new \ESSF_Category();
		$this->addToAssertionCount( 1 );
	}

	public function test_constructor_hooks_term_count_fix_to_init(): void {
		Monkey\Actions\expectAdded( 'init' )
			->once()
			->with( [ 'ESSF_Category', 'maybe_fix_term_counts' ], 20 );

		new \ESSF_Category();
		$this->addToAssertionCount( 1 );
	}

	public function test_schedule_relabel_schedules_when_not_already_scheduled(): void {
		Functions\expect( 'wp_next_scheduled' )
			->once()
			->with( \ESSF_Category::RELABEL_CRON_HOOK )
			->andReturn( false );
		Functions\expect( 'wp_schedule_single_event' )
			->once()
			->with( \Mockery::type( 'int' ), \ESSF_Category::RELABEL_CRON_HOOK );

		\ESSF_Category::schedule_relabel();
		$this->addToAssertionCount( 1 );
	}

	public function test_schedule_relabel_does_not_double_schedule(): void {
		Functions\expect( 'wp_next_scheduled' )
			->once()
			->with( \ESSF_Category::RELABEL_CRON_HOOK )
			->andReturn( 12345 );
		Functions\expect( 'wp_schedule_single_event' )->never();

		\ESSF_Category::schedule_relabel();
		$this->addToAssertionCount( 1 );
	}

	// ── maybe_fix_term_counts() ──────────────────────────────────────────

	public function test_maybe_fix_term_counts_noop_when_already_fixed(): void {
		Functions\expect( 'get_option' )
			->once()
			->with( \ESSF_Category::COUNTS_FIXED_OPTION )
			->andReturn( true );
		Functions\expect( 'get_terms' )->never();
		Functions\expect( 'wp_update_term_count_now' )->never();

		\ESSF_Category::maybe_fix_term_counts();
		$this->addToAssertionCount( 1 );
	}

	public function test_maybe_fix_term_counts_recounts_and_sets_flag(): void {
		Functions\expect( 'get_option' )->once()->andReturn( false );
		Functions\expect( 'get_terms' )->once()->andReturn( [ 1, 2, 3 ] );
		Functions\expect( 'is_wp_error' )->once()->andReturn( false );
		Functions\expect( 'wp_update_term_count_now' )
			->once()
			->with( [ 1, 2, 3 ], 'essf_cashflow_cat' );
		Functions\expect( 'update_option' )
			->once()
			->with( \ESSF_Category::COUNTS_FIXED_OPTION, true, false );

		\ESSF_Category::maybe_fix_term_counts();
		$this->addToAssertionCount( 1 );
	}

	public function test_maybe_fix_term_counts_skips_recount_on_wp_error(): void {
		Functions\expect( 'get_option' )->once()->andReturn( false );
		Functions\expect( 'get_terms' )->once()->andReturn( new \stdClass() );
		Functions\expect( 'is_wp_error' )->once()->andReturn( true );
		Functions\expect( 'wp_update_term_count_now' )->never();
		Functions\expect( 'update_option' )->once();

		\ESSF_Category::maybe_fix_term_counts();
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
		// Direct slug hit — the common case, e.g. a value posted from a
		// freshly-rendered <select> — resolves without any name lookup.
		Functions\expect( 'get_term_by' )
			->once()
			->with( 'slug', 'income', 'essf_cashflow_cat' )
			->andReturn( (object) [ 'term_id' => 42 ] );

		$this->assertSame( 42, \ESSF_Category::term_id_for_slug( 'income' ) );
	}

	public function test_term_id_for_slug_finds_relabeled_term_by_name(): void {
		// Slug no longer matches (site was relabeled to pt_BR, so the
		// 'income' term is now sluggeded 'renda') — falls back to matching
		// by name against either known language variant.
		Functions\expect( 'get_term_by' )
			->once()
			->with( 'slug', 'income', 'essf_cashflow_cat' )
			->andReturn( false );
		Functions\expect( 'get_term_by' )
			->once()
			->with( 'name', 'Income', 'essf_cashflow_cat' )
			->andReturn( false );
		Functions\expect( 'get_term_by' )
			->once()
			->with( 'name', 'Renda', 'essf_cashflow_cat' )
			->andReturn( (object) [ 'term_id' => 55 ] );
		Functions\expect( 'wp_insert_term' )->never();

		$this->assertSame( 55, \ESSF_Category::term_id_for_slug( 'income' ) );
	}

	public function test_term_id_for_slug_self_heals_missing_term(): void {
		Functions\expect( 'get_term_by' )->times( 3 )->andReturn( false );
		Functions\expect( 'get_locale' )->once()->andReturn( 'en_US' );
		Functions\expect( 'wp_insert_term' )
			->once()
			->with( 'Income', 'essf_cashflow_cat' )
			->andReturn( [ 'term_id' => 99 ] );
		Functions\expect( 'is_wp_error' )->once()->andReturn( false );

		$this->assertSame( 99, \ESSF_Category::term_id_for_slug( 'income' ) );
	}

	public function test_term_id_for_slug_returns_zero_on_insert_error(): void {
		Functions\expect( 'get_term_by' )->times( 3 )->andReturn( false );
		Functions\expect( 'get_locale' )->once()->andReturn( 'en_US' );
		Functions\expect( 'wp_insert_term' )->once()->andReturn( new \stdClass() );
		Functions\expect( 'is_wp_error' )->once()->andReturn( true );

		$this->assertSame( 0, \ESSF_Category::term_id_for_slug( 'income' ) );
	}

	public function test_term_id_for_slug_unknown_slug_skips_name_lookup_and_self_heals(): void {
		// Not one of the 25 standard keys — find_term_by_key() has nothing to
		// match by name, so this goes straight to self-heal creation.
		Functions\expect( 'get_term_by' )
			->once()
			->with( 'slug', 'not-a-real-slug', 'essf_cashflow_cat' )
			->andReturn( false );
		Functions\expect( 'get_locale' )->once()->andReturn( 'en_US' );
		Functions\expect( 'wp_insert_term' )
			->once()
			->with( 'Not a real slug', 'essf_cashflow_cat' )
			->andReturn( [ 'term_id' => 12 ] );
		Functions\expect( 'is_wp_error' )->once()->andReturn( false );

		$this->assertSame( 12, \ESSF_Category::term_id_for_slug( 'not-a-real-slug' ) );
	}

	public function test_uncategorized_term_id_looks_up_uncategorized_slug(): void {
		Functions\expect( 'get_term_by' )
			->once()
			->with( 'slug', 'uncategorized', 'essf_cashflow_cat' )
			->andReturn( (object) [ 'term_id' => 7 ] );

		$this->assertSame( 7, \ESSF_Category::uncategorized_term_id() );
	}

	// ── relabel_terms() ──────────────────────────────────────────────────

	public function test_relabel_terms_renames_and_reslugs_term_found_by_name(): void {
		Functions\expect( 'get_locale' )->once()->andReturn( 'pt_BR' );
		Functions\expect( 'get_term_by' )->andReturnUsing(
			static function ( $field, $value ) {
				return 'Income' === $value ? (object) [
					'term_id' => 42,
					'name'    => 'Income',
				] : false;
			}
		);
		Functions\expect( 'sanitize_title' )->with( 'Renda' )->andReturn( 'renda' );
		Functions\expect( 'wp_update_term' )
			->once()
			->with(
				42,
				'essf_cashflow_cat',
				[
					'name' => 'Renda',
					'slug' => 'renda',
				]
			);

		\ESSF_Category::relabel_terms();
		$this->addToAssertionCount( 1 );
	}

	public function test_relabel_terms_skips_term_already_in_target_language(): void {
		Functions\expect( 'get_locale' )->once()->andReturn( 'en_US' );
		Functions\expect( 'get_term_by' )->andReturnUsing(
			static function ( $field, $value ) {
				return 'Income' === $value ? (object) [
					'term_id' => 42,
					'name'    => 'Income',
				] : false;
			}
		);
		Functions\expect( 'wp_update_term' )->never();

		\ESSF_Category::relabel_terms();
		$this->addToAssertionCount( 1 );
	}

	public function test_relabel_terms_skips_key_with_no_matching_term(): void {
		Functions\expect( 'get_locale' )->once()->andReturn( 'pt_BR' );
		Functions\expect( 'get_term_by' )->andReturn( false );
		Functions\expect( 'wp_update_term' )->never();

		\ESSF_Category::relabel_terms();
		$this->addToAssertionCount( 1 );
	}

	// ── normalize_slug() ─────────────────────────────────────────────────

	public function test_normalize_slug_returns_unchanged_when_already_live(): void {
		Functions\expect( 'get_term_by' )
			->once()
			->with( 'slug', 'income', 'essf_cashflow_cat' )
			->andReturn( (object) [ 'term_id' => 1, 'slug' => 'income' ] );

		$this->assertSame( 'income', \ESSF_Category::normalize_slug( 'income' ) );
	}

	public function test_normalize_slug_resolves_canonical_key_to_current_live_slug(): void {
		Functions\expect( 'get_term_by' )->andReturnUsing(
			static function ( $field, $value ) {
				return 'Não categorizado' === $value ? (object) [ 'term_id' => 25 ] : false;
			}
		);
		Functions\expect( 'get_term' )
			->once()
			->with( 25, 'essf_cashflow_cat' )
			->andReturn( (object) [ 'term_id' => 25, 'slug' => 'nao-categorizado' ] );
		Functions\expect( 'is_wp_error' )->once()->andReturn( false );

		$this->assertSame( 'nao-categorizado', \ESSF_Category::normalize_slug( 'uncategorized' ) );
	}

	public function test_normalize_slug_returns_original_when_nothing_resolves(): void {
		Functions\expect( 'get_term_by' )->andReturn( false )->zeroOrMoreTimes();
		Functions\expect( 'get_locale' )->once()->andReturn( 'en_US' );
		Functions\expect( 'wp_insert_term' )->once()->andReturn( new \stdClass() );
		Functions\expect( 'is_wp_error' )->once()->andReturn( true );

		$this->assertSame( 'garbage-value', \ESSF_Category::normalize_slug( 'garbage-value' ) );
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

	public function test_guess_slug_matches_english_descriptions_too(): void {
		// keyword_map() stores English msgids (translated to PT-BR only in
		// languages/essfinance-pt_BR.po, never as literal non-English text in
		// this file) — so an English description matches directly against
		// the untranslated msgid, with no .po lookup involved.
		$this->assertSame( 'transfers', \ESSF_Category::guess_slug_from_description( __( 'Transfer Wife', 'essfinance' ) ) );
		$this->assertSame( 'loans', \ESSF_Category::guess_slug_from_description( 'Loan from Lucia' ) );
		$this->assertSame( 'groceries', \ESSF_Category::guess_slug_from_description( 'Groceries at the corner store' ) );
	}

	public function test_guess_slug_falls_back_to_uncategorized_when_nothing_matches(): void {
		$this->assertSame( 'uncategorized', \ESSF_Category::guess_slug_from_description( 'Trimania' ) );
	}

	public function test_guess_slug_never_returns_empty_string(): void {
		$this->assertSame( 'uncategorized', \ESSF_Category::guess_slug_from_description( '' ) );
	}

	// ── match_glossary() / guess_slug() ──────────────────────────────────
	//
	// The user's own scenario: "Trimania" gets manually recategorized from
	// Uncategorized to Miscellaneous expenses — the next similar
	// description should propose that category, not fall through to the
	// (keyword-blind) "uncategorized" default.

	public function test_match_glossary_finds_similar_prior_description(): void {
		$glossary_history = [
			[
				'id'    => 1,
				'memo'  => 'Trimania',
				'title' => 'Miscellaneous expenses',
				'date'  => '2026-08-01',
			],
		];
		Functions\expect( 'get_terms' )
			->once()
			->andReturn( [ (object) [ 'term_id' => 13, 'slug' => 'miscellaneous-expenses', 'name' => 'Miscellaneous expenses' ] ] );
		Functions\expect( 'is_wp_error' )->once()->andReturn( false );

		$match = \ESSF_Category::match_glossary( 'Trimania', $glossary_history );

		$this->assertNotNull( $match );
		$this->assertSame( 'miscellaneous-expenses', $match['slug'] );
	}

	public function test_match_glossary_returns_null_with_empty_history(): void {
		Functions\expect( 'get_terms' )->never();

		$this->assertNull( \ESSF_Category::match_glossary( 'Trimania', [] ) );
	}

	public function test_match_glossary_returns_null_when_nothing_similar_enough(): void {
		$glossary_history = [
			[
				'id'    => 1,
				'memo'  => 'Completely unrelated text here',
				'title' => 'Housing',
				'date'  => '2026-08-01',
			],
		];
		Functions\expect( 'get_terms' )->never();

		$this->assertNull( \ESSF_Category::match_glossary( 'Trimania', $glossary_history ) );
	}

	public function test_guess_slug_prefers_glossary_match_over_keyword(): void {
		// "Trimania" matches no keyword rule at all — without the glossary
		// this would fall through to 'uncategorized'.
		$glossary_history = [
			[
				'id'    => 1,
				'memo'  => 'Trimania',
				'title' => 'Miscellaneous expenses',
				'date'  => '2026-08-01',
			],
		];
		Functions\expect( 'get_terms' )
			->once()
			->andReturn( [ (object) [ 'term_id' => 13, 'slug' => 'miscellaneous-expenses', 'name' => 'Miscellaneous expenses' ] ] );
		Functions\expect( 'is_wp_error' )->once()->andReturn( false );

		$this->assertSame( 'miscellaneous-expenses', \ESSF_Category::guess_slug( 'Trimania', $glossary_history ) );
	}

	public function test_guess_slug_falls_back_to_keyword_when_glossary_has_no_match(): void {
		$this->assertSame( 'loans', \ESSF_Category::guess_slug( 'Empréstimo Lucia', [] ) );
	}

	public function test_guess_slug_falls_back_to_uncategorized_when_nothing_matches_at_all(): void {
		$this->assertSame( 'uncategorized', \ESSF_Category::guess_slug( 'Trimania', [] ) );
	}
}
