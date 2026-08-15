<?php
/**
 * Tests for ESSF_Admin_Page (parse_form_data, OFX parser, CSV amount formatter).
 *
 * @package EssFinance\Tests
 * @license GPL-2.0-or-later
 */

declare( strict_types=1 );

namespace EssFinance\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class AdminPageTest extends TestCase {

	private \ESSF_Admin_Page $admin_page;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\expect( '__' )->zeroOrMoreTimes()->andReturnFirstArg();
		Functions\expect( 'add_action' )->zeroOrMoreTimes();
		Functions\expect( 'admin_url' )->zeroOrMoreTimes()->andReturn( 'http://example.com/wp-admin/' );

		$this->admin_page = new \ESSF_Admin_Page();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function call_parse_form( array $post_data ): array {
		$method = new ReflectionMethod( \ESSF_Admin_Page::class, 'parse_form_data' );
		$method->setAccessible( true );
		return $method->invoke( $this->admin_page, $post_data );
	}

	private function call_format_amount_csv( float $amount ): string {
		$method = new ReflectionMethod( \ESSF_Admin_Page::class, 'format_amount_csv' );
		$method->setAccessible( true );
		return $method->invoke( null, $amount );
	}

	// ── parse_form_data ──────────────────────────────────────────────────────

	public function test_parse_form_data_income_positive(): void {
		Functions\expect( 'sanitize_text_field' )->andReturnFirstArg();
		Functions\expect( 'wp_unslash' )->andReturnFirstArg();

		$data = $this->call_parse_form( [
			'essf_description' => 'Salary',
			'essf_amount'      => '3000',
			'essf_is_income'   => '1',
			'essf_due_date'    => '2026-03-01',
			'essf_pay_date'    => '2026-03-05',
			'essf_status'      => 'paid',
		] );

		$this->assertTrue( $data['is_income'] );
		$this->assertSame( '3000', $data['amount'] );
		$this->assertSame( 'paid', $data['status'] );
		$this->assertSame( 'Salary', $data['title'] );
		$this->assertSame( '2026-03-01 00:00:00', $data['due_gmt'] );
		$this->assertSame( '2026-03-05 00:00:00', $data['pay_gmt'] );
	}

	public function test_parse_form_data_expense_negative(): void {
		Functions\expect( 'sanitize_text_field' )->andReturnFirstArg();
		Functions\expect( 'wp_unslash' )->andReturnFirstArg();

		$data = $this->call_parse_form( [
			'essf_description' => 'Rent',
			'essf_amount'      => '1500',
			'essf_due_date'    => '2026-04-01',
			'essf_status'      => 'pending',
		] );

		$this->assertFalse( $data['is_income'] );
		$this->assertSame( '-1500', $data['amount'] );
		$this->assertSame( 'pending', $data['status'] );
	}

	public function test_parse_form_data_unknown_status_falls_back_to_pending(): void {
		Functions\expect( 'sanitize_text_field' )->andReturnFirstArg();
		Functions\expect( 'wp_unslash' )->andReturnFirstArg();
		Functions\expect( 'sanitize_key' )->andReturnFirstArg();

		$data = $this->call_parse_form( [
			'essf_description' => 'Test',
			'essf_amount'      => '100',
			'essf_due_date'    => '2026-04-01',
			'essf_status'      => 'invalid_status',
		] );

		$this->assertSame( 'pending', $data['status'] );
	}

	public function test_parse_form_data_empty_description_defaults_to_entry(): void {
		Functions\expect( 'sanitize_text_field' )->andReturnFirstArg();
		Functions\expect( 'wp_unslash' )->andReturnFirstArg();

		$data = $this->call_parse_form( [
			'essf_description' => '',
			'essf_amount'      => '100',
			'essf_due_date'    => '2026-04-01',
			'essf_status'      => 'pending',
		] );

		$this->assertSame( 'Entry', $data['title'] );
	}

	public function test_parse_form_data_order_date_uses_pay_date_when_paid(): void {
		Functions\expect( 'sanitize_text_field' )->andReturnFirstArg();
		Functions\expect( 'wp_unslash' )->andReturnFirstArg();

		$data = $this->call_parse_form( [
			'essf_description' => 'Test',
			'essf_amount'      => '50',
			'essf_is_income'   => '1',
			'essf_due_date'    => '2025-11-01',
			'essf_pay_date'    => '2026-01-15',
			'essf_status'      => 'paid',
		] );

		// _order_date should be the pay_date, not the due_date.
		$this->assertSame( '2026-01-15', $data['order_date'] );
	}

	public function test_parse_form_data_category_defaults_to_uncategorized(): void {
		Functions\expect( 'sanitize_text_field' )->andReturnFirstArg();
		Functions\expect( 'wp_unslash' )->andReturnFirstArg();

		$data = $this->call_parse_form( [
			'essf_description' => 'Test',
			'essf_amount'      => '100',
			'essf_due_date'    => '2026-04-01',
			'essf_status'      => 'pending',
		] );

		$this->assertSame( 'uncategorized', $data['category'] );
	}

	public function test_parse_form_data_category_from_posted_value(): void {
		Functions\expect( 'sanitize_text_field' )->andReturnFirstArg();
		Functions\expect( 'wp_unslash' )->andReturnFirstArg();

		$data = $this->call_parse_form( [
			'essf_description' => 'Test',
			'essf_amount'      => '100',
			'essf_due_date'    => '2026-04-01',
			'essf_status'      => 'pending',
			'essf_category'    => 'groceries',
		] );

		$this->assertSame( 'groceries', $data['category'] );
	}

	public function test_parse_form_data_order_date_uses_due_when_no_pay(): void {
		Functions\expect( 'sanitize_text_field' )->andReturnFirstArg();
		Functions\expect( 'wp_unslash' )->andReturnFirstArg();

		$data = $this->call_parse_form( [
			'essf_description' => 'Test',
			'essf_amount'      => '200',
			'essf_due_date'    => '2026-02-28',
			'essf_status'      => 'pending',
		] );

		$this->assertSame( '2026-02-28', $data['order_date'] );
	}

	// ── format_amount_csv ────────────────────────────────────────────────────

	/**
	 * @dataProvider amount_csv_provider
	 */
	public function test_format_amount_csv( float $amount, string $expected ): void {
		$this->assertSame( $expected, $this->call_format_amount_csv( $amount ) );
	}

	public function amount_csv_provider(): array {
		return [
			'integer positive'      => [ 3000.0,  '3000' ],
			'integer negative'      => [ -1500.0, '-1500' ],
			'decimal positive'      => [ 99.50,   '99.5' ],
			'decimal negative'      => [ -0.75,   '-0.75' ],
			'trailing zero trimmed' => [ 100.10,  '100.1' ],
			'zero'                  => [ 0.0,     '0' ],
		];
	}

}
