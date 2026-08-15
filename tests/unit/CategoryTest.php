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
}
