<?php
/**
 * Tests for ESSF_CPT.
 *
 * @package EssFinance\Tests
 * @license GPL-2.0-or-later
 */

declare( strict_types=1 );

namespace EssFinance\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

class CptTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_labels_contains_pending_and_paid(): void {
		Functions\expect( '__' )->andReturnFirstArg();

		$labels = \ESSF_CPT::labels();

		$this->assertArrayHasKey( 'pending', $labels );
		$this->assertArrayHasKey( 'paid', $labels );
	}

	public function test_labels_does_not_include_overdue(): void {
		// Overdue is a computed state, not a registered WP post status.
		Functions\expect( '__' )->andReturnFirstArg();

		$this->assertArrayNotHasKey( 'overdue', \ESSF_CPT::labels() );
	}

	public function test_labels_are_translated_via_gettext(): void {
		Functions\expect( '__' )
			->once()
			->with( 'Pending', 'essfinance' )
			->andReturn( 'Pendente' );
		Functions\expect( '__' )
			->once()
			->with( 'Paid', 'essfinance' )
			->andReturn( 'Pago' );

		$labels = \ESSF_CPT::labels();

		$this->assertSame( 'Pendente', $labels['pending'] );
		$this->assertSame( 'Pago', $labels['paid'] );
	}

	// ── status_label() ───────────────────────────────────────────────────

	public function test_status_label_returns_translated_label_for_known_status(): void {
		Functions\expect( '__' )->andReturnFirstArg();

		$this->assertSame( 'Paid', \ESSF_CPT::status_label( 'paid' ) );
	}

	public function test_status_label_translates_the_virtual_overdue_status(): void {
		Functions\expect( '__' )
			->once()
			->with( 'Overdue', 'essfinance' )
			->andReturn( 'Atrasado' );

		$this->assertSame( 'Atrasado', \ESSF_CPT::status_label( 'overdue' ) );
	}

	public function test_status_label_falls_back_to_ucfirst_for_unknown_status(): void {
		Functions\expect( '__' )->andReturnFirstArg();

		$this->assertSame( 'Draft', \ESSF_CPT::status_label( 'draft' ) );
	}

	public function test_register_calls_register_post_type(): void {
		Functions\expect( 'register_post_type' )
			->once()
			->with(
				'essf_cashflow',
				\Mockery::type( 'array' )
			);

		Functions\expect( 'register_post_status' )->twice();
		Functions\expect( 'register_post_meta' )->once();
		Functions\expect( '__' )->andReturnFirstArg();

		$cpt = new \ESSF_CPT();
		$cpt->register();
		$this->addToAssertionCount( 1 );
	}

	public function test_register_post_meta_registers_order_date(): void {
		Functions\expect( 'register_post_type' )->once();
		Functions\expect( 'register_post_status' )->twice();
		Functions\expect( '__' )->andReturnFirstArg();
		Functions\expect( 'register_post_meta' )
			->once()
			->with(
				'essf_cashflow',
				'_order_date',
				\Mockery::type( 'array' )
			);

		$cpt = new \ESSF_CPT();
		$cpt->register();
		$this->addToAssertionCount( 1 );
	}

	public function test_constructor_hooks_register_to_init(): void {
		Monkey\Actions\expectAdded( 'init' )
			->once()
			->with( \Mockery::type( 'array' ) );

		new \ESSF_CPT();
		$this->addToAssertionCount( 1 );
	}

	public function test_constructor_hooks_default_admin_post_status_to_pre_get_posts(): void {
		Monkey\Actions\expectAdded( 'pre_get_posts' )
			->once()
			->with( \Mockery::type( 'array' ) );

		new \ESSF_CPT();
		$this->addToAssertionCount( 1 );
	}

	public function test_activate_calls_flush_rewrite_rules(): void {
		Functions\expect( 'register_post_type' )->once();
		Functions\expect( 'register_post_status' )->twice();
		Functions\expect( 'register_post_meta' )->once();
		Functions\expect( '__' )->andReturnFirstArg();
		Functions\expect( 'flush_rewrite_rules' )->once();
		// create_pages() checks existing pages; stub them as already present so
		// wp_insert_post() is never reached in this unit-test context.
		Functions\expect( 'get_option' )->andReturn( 1 );
		Functions\expect( 'get_post' )->andReturn( (object) [ 'ID' => 1 ] );

		\ESSF_CPT::activate();
		$this->addToAssertionCount( 1 );
	}
}
