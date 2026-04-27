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

	public function test_statuses_contains_pending_and_paid(): void {
		$this->assertArrayHasKey( 'pending', \ESSF_CPT::$statuses );
		$this->assertArrayHasKey( 'paid', \ESSF_CPT::$statuses );
	}

	public function test_statuses_does_not_include_overdue(): void {
		// Overdue is a computed state, not a registered WP post status.
		$this->assertArrayNotHasKey( 'overdue', \ESSF_CPT::$statuses );
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

	public function test_activate_calls_flush_rewrite_rules(): void {
		Functions\expect( 'register_post_type' )->once();
		Functions\expect( 'register_post_status' )->twice();
		Functions\expect( 'register_post_meta' )->once();
		Functions\expect( '__' )->andReturnFirstArg();
		Functions\expect( 'flush_rewrite_rules' )->once();

		\ESSF_CPT::activate();
		$this->addToAssertionCount( 1 );
	}
}
