<?php
/**
 * Tests for ESSF_Settings static helpers.
 *
 * @package EssFinance\Tests
 * @license GPL-2.0-or-later
 */

declare( strict_types=1 );

namespace EssFinance\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

class SettingsTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\expect( '__' )->zeroOrMoreTimes()->andReturnFirstArg();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	// ── format_amount ────────────────────────────────────────────────────────

	/**
	 * @dataProvider format_amount_provider
	 */
	public function test_format_amount( float $amount, int $decimals, string $dec_sep, string $thou_sep, string $symbol, string $pos, string $expected ): void {
		Functions\expect( 'get_option' )->andReturnUsing(
			function ( string $option, $default = null ) use ( $decimals, $dec_sep, $thou_sep, $symbol, $pos ) {
				return match ( $option ) {
					\ESSF_Settings::OPTION_NUM_DECIMALS   => $decimals,
					\ESSF_Settings::OPTION_DECIMAL_SEP    => $dec_sep,
					\ESSF_Settings::OPTION_THOUSANDS_SEP  => $thou_sep,
					\ESSF_Settings::OPTION_CURRENCY        => [ '$' => 'USD', '€' => 'EUR', 'R$' => 'BRL' ][ $symbol ] ?? 'USD',
					\ESSF_Settings::OPTION_CURRENCY_POS    => $pos,
					default                                => $default,
				};
			}
		);

		// Override symbol look-up: USD maps to $, but we test with the passed $symbol via BRL → R$.
		// Simpler: stub currency_symbol indirectly by mapping the mocked option.
		// We need to stub the internal symbols() lookup — do it by injecting BRL/EUR code and
		// verifying the known mapping. Just use well-known codes that match passed $symbol.
		$this->assertSame( $expected, \ESSF_Settings::format_amount( $amount ) );
	}

	public function format_amount_provider(): array {
		return [
			'USD left, 2 decimals, 1000'    => [ 1000.0, 2, '.', ',', '$', 'left', '$1,000.00' ],
			'no currency pos hides symbol'  => [ 500.5,  2, '.', ',', '$', '',     '500.50' ],
			'right position'                => [ 99.9,   1, '.', '.', '€', 'right', '99.9€' ],
			'left_space adds nbsp'          => [ 10.0,   0, '.', ',', '€', 'left_space', "€\u{00A0}10" ],
			'right_space adds nbsp'         => [ 10.0,   0, '.', ',', '$', 'right_space', "10\u{00A0}$" ],
			'decimal comma'                 => [ 1234.5, 2, ',', '.', '',  '',     '1.234,50' ],
		];
	}

	// ── show_* getters ───────────────────────────────────────────────────────

	public function test_show_status_badge_defaults_false(): void {
		Functions\expect( 'get_option' )
			->with( \ESSF_Settings::OPTION_STATUS_BADGE, false )
			->andReturn( false );

		$this->assertFalse( \ESSF_Settings::show_status_badge() );
	}

	public function test_show_status_badge_true_when_option_set(): void {
		Functions\expect( 'get_option' )
			->with( \ESSF_Settings::OPTION_STATUS_BADGE, false )
			->andReturn( '1' ); // WP options are strings

		$this->assertTrue( \ESSF_Settings::show_status_badge() );
	}

	public function test_show_status_icons_defaults_true(): void {
		Functions\expect( 'get_option' )
			->with( \ESSF_Settings::OPTION_STATUS_ICONS, true )
			->andReturn( true );

		$this->assertTrue( \ESSF_Settings::show_status_icons() );
	}

	public function test_show_amount_colors_defaults_false(): void {
		Functions\expect( 'get_option' )
			->with( \ESSF_Settings::OPTION_AMOUNT_COLORS, false )
			->andReturn( false );

		$this->assertFalse( \ESSF_Settings::show_amount_colors() );
	}

	public function test_show_positive_prefix_defaults_true(): void {
		Functions\expect( 'get_option' )
			->with( \ESSF_Settings::OPTION_POSITIVE_PREFIX, true )
			->andReturn( true );

		$this->assertTrue( \ESSF_Settings::show_positive_prefix() );
	}

	public function test_show_negative_prefix_defaults_false(): void {
		Functions\expect( 'get_option' )
			->with( \ESSF_Settings::OPTION_NEGATIVE_PREFIX, false )
			->andReturn( false );

		$this->assertFalse( \ESSF_Settings::show_negative_prefix() );
	}

	// ── currency_symbol ──────────────────────────────────────────────────────

	public function test_currency_symbol_usd(): void {
		Functions\expect( 'get_option' )
			->with( \ESSF_Settings::OPTION_CURRENCY, 'USD' )
			->andReturn( 'USD' );

		$this->assertSame( '$', \ESSF_Settings::currency_symbol() );
	}

	public function test_currency_symbol_eur(): void {
		Functions\expect( 'get_option' )
			->with( \ESSF_Settings::OPTION_CURRENCY, 'USD' )
			->andReturn( 'EUR' );

		$this->assertSame( '€', \ESSF_Settings::currency_symbol() );
	}

	public function test_currency_symbol_brl(): void {
		Functions\expect( 'get_option' )
			->with( \ESSF_Settings::OPTION_CURRENCY, 'USD' )
			->andReturn( 'BRL' );

		$this->assertSame( 'R$', \ESSF_Settings::currency_symbol() );
	}

	public function test_currency_symbol_unknown_falls_back_to_dollar(): void {
		Functions\expect( 'get_option' )
			->with( \ESSF_Settings::OPTION_CURRENCY, 'USD' )
			->andReturn( 'XXX' );

		$this->assertSame( '$', \ESSF_Settings::currency_symbol() );
	}

	// ── status_icon ──────────────────────────────────────────────────────────

	public function test_status_icon_paid_contains_dashicon(): void {
		Functions\expect( 'esc_attr' )->andReturnFirstArg();

		$icon = \ESSF_Settings::status_icon( 'paid' );
		$this->assertStringContainsString( 'dashicons-yes-alt', $icon );
	}

	public function test_status_icon_pending_contains_dashicon(): void {
		Functions\expect( 'esc_attr' )->andReturnFirstArg();

		$icon = \ESSF_Settings::status_icon( 'pending' );
		$this->assertStringContainsString( 'dashicons-clock', $icon );
	}

	public function test_status_icon_overdue_contains_dashicon(): void {
		Functions\expect( 'esc_attr' )->andReturnFirstArg();

		$icon = \ESSF_Settings::status_icon( 'overdue' );
		$this->assertStringContainsString( 'dashicons-warning', $icon );
	}

	public function test_status_icon_unknown_returns_empty(): void {
		$icon = \ESSF_Settings::status_icon( 'unknown_status' );
		$this->assertSame( '', $icon );
	}

	// ── separator/decimal getters ────────────────────────────────────────────

	public function test_thousands_sep_default(): void {
		Functions\expect( 'get_option' )
			->with( \ESSF_Settings::OPTION_THOUSANDS_SEP, ',' )
			->andReturn( ',' );

		$this->assertSame( ',', \ESSF_Settings::thousands_sep() );
	}

	public function test_decimal_sep_default(): void {
		Functions\expect( 'get_option' )
			->with( \ESSF_Settings::OPTION_DECIMAL_SEP, '.' )
			->andReturn( '.' );

		$this->assertSame( '.', \ESSF_Settings::decimal_sep() );
	}

	public function test_num_decimals_default(): void {
		Functions\expect( 'get_option' )
			->with( \ESSF_Settings::OPTION_NUM_DECIMALS, 2 )
			->andReturn( 2 );

		$this->assertSame( 2, \ESSF_Settings::num_decimals() );
	}

	public function test_show_balance_column_default(): void {
		Functions\expect( 'get_option' )
			->with( \ESSF_Settings::OPTION_SHOW_BALANCE_COLUMN, true )
			->andReturn( true );

		$this->assertTrue( \ESSF_Settings::show_balance_column() );
	}

	public function test_initial_balance_default(): void {
		Functions\expect( 'get_option' )
			->with( \ESSF_Settings::OPTION_INITIAL_BALANCE, 0.0 )
			->andReturn( 0.0 );

		$this->assertSame( 0.0, \ESSF_Settings::initial_balance() );
	}

	public function test_initial_balance_returns_stored_value(): void {
		Functions\expect( 'get_option' )
			->with( \ESSF_Settings::OPTION_INITIAL_BALANCE, 0.0 )
			->andReturn( -150.75 );

		$this->assertSame( -150.75, \ESSF_Settings::initial_balance() );
	}

	public function test_balance_basis_default(): void {
		Functions\expect( 'get_option' )
			->with( \ESSF_Settings::OPTION_BALANCE_BASIS, 'paid' )
			->andReturn( 'paid' );

		$this->assertSame( 'paid', \ESSF_Settings::balance_basis() );
	}

	public function test_balance_basis_accepts_all(): void {
		Functions\expect( 'get_option' )
			->with( \ESSF_Settings::OPTION_BALANCE_BASIS, 'paid' )
			->andReturn( 'all' );

		$this->assertSame( 'all', \ESSF_Settings::balance_basis() );
	}

	public function test_balance_basis_normalizes_invalid_stored_value(): void {
		Functions\expect( 'get_option' )
			->with( \ESSF_Settings::OPTION_BALANCE_BASIS, 'paid' )
			->andReturn( 'bogus' );

		$this->assertSame( 'paid', \ESSF_Settings::balance_basis() );
	}

	public function test_pending_balance_mode_default(): void {
		Functions\expect( 'get_option' )
			->with( \ESSF_Settings::OPTION_PENDING_BALANCE_MODE, 'dash' )
			->andReturn( 'dash' );

		$this->assertSame( 'dash', \ESSF_Settings::pending_balance_mode() );
	}

	public function test_pending_balance_mode_accepts_forward_fill(): void {
		Functions\expect( 'get_option' )
			->with( \ESSF_Settings::OPTION_PENDING_BALANCE_MODE, 'dash' )
			->andReturn( 'forward_fill' );

		$this->assertSame( 'forward_fill', \ESSF_Settings::pending_balance_mode() );
	}
}
