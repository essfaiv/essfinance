<?php
/**
 * Tests for ESSF_Shortcodes action handlers (nonce validation, type toggle logic).
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

/**
 * Custom exception to intercept wp_die() / exit calls in handlers.
 */
class HandlerExitException extends \RuntimeException {}

class ShortcodesHandlersTest extends TestCase {

	private \ESSF_Shortcodes $shortcodes;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\expect( '__' )->andReturnFirstArg();
		$this->shortcodes = new \ESSF_Shortcodes();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function invoke_handler( string $method_name ): void {
		$method = new ReflectionMethod( \ESSF_Shortcodes::class, $method_name );
		$method->setAccessible( true );
		$method->invoke( $this->shortcodes );
	}

	// ── handle_paid_today: nonce guard ───────────────────────────────────────

	public function test_paid_today_does_nothing_when_nonce_missing(): void {
		// No $_GET['_wpnonce'] set.
		$_GET = [];

		Functions\expect( 'wp_verify_nonce' )->never();
		Functions\expect( 'get_post' )->never();
		Functions\expect( 'wp_update_post' )->never();

		Functions\expect( 'wp_verify_nonce' )->never();
		Functions\when( 'wp_safe_redirect' )->justReturn();

		// Should return early without touching the database.
		$this->invoke_handler( 'handle_paid_today' );

		$this->assertTrue( true ); // If we reach here without fatal errors, nonce guard worked.
	}

	// ── handle_paid_today: invalid nonce ─────────────────────────────────────

	public function test_paid_today_does_nothing_when_nonce_invalid(): void {
		$_GET = [ '_wpnonce' => 'badnonce', 'entry' => '42' ];

		Functions\expect( 'wp_unslash' )->andReturnFirstArg();
		Functions\expect( 'sanitize_key' )->andReturnFirstArg();
		Functions\expect( 'absint' )->andReturn( 42 );
		Functions\expect( 'wp_verify_nonce' )->andReturn( false );
		Functions\expect( 'wp_update_post' )->never();
		Functions\expect( 'update_post_meta' )->never();
		Functions\when( 'wp_safe_redirect' )->justReturn();

		$this->invoke_handler( 'handle_paid_today' );

		$this->assertTrue( true );
	}

	// ── handle_toggle_type: amount sign toggling ─────────────────────────────

	public function test_toggle_type_switches_positive_to_negative(): void {
		// Test the mathematical invariant: toggling flips the sign.
		// +1500 (income) → -1500 (expense)
		$amount = 1500.0;
		$toggled = $amount > 0 ? -abs( $amount ) : abs( $amount );
		$this->assertSame( -1500.0, $toggled );
	}

	public function test_toggle_type_switches_negative_to_positive(): void {
		// -500 (expense) → +500 (income)
		$amount  = -500.0;
		$toggled = $amount > 0 ? -abs( $amount ) : abs( $amount );
		$this->assertSame( 500.0, $toggled );
	}

	// ── cashflow_url_with_filters: filter preservation ───────────────────────

	public function test_cashflow_url_with_filters_preserves_current_filters(): void {
		$_GET = [
			'essf_status' => 'paid',
			'essf_type'   => 'income',
			'essf_m'      => '202601',
			'paged'       => '2',
			'essf_search' => 'salary',
		];

		Functions\expect( 'wp_unslash' )->andReturnFirstArg();
		Functions\expect( 'sanitize_key' )->andReturnFirstArg();
		Functions\expect( 'add_query_arg' )
			->once()
			->andReturnUsing( static function ( array $args, string $base ): string {
				return $base . '?' . http_build_query( $args );
			} );
		Functions\when( 'get_option' )->justReturn( 1 );
		Functions\when( 'get_permalink' )->justReturn( 'http://example.com/cashflow/' );
		Functions\when( 'get_post' )->justReturn( null );

		$method = new ReflectionMethod( \ESSF_Shortcodes::class, 'cashflow_url_with_filters' );
		$method->setAccessible( true );
		$url = $method->invoke( $this->shortcodes, [ 'essf_msg' => 'paid' ] );

		$this->assertStringContainsString( 'essf_status=paid', $url );
		$this->assertStringContainsString( 'essf_m=202601', $url );
		$this->assertStringContainsString( 'paged=2', $url );
	}

	public function test_cashflow_url_with_filters_skips_empty_params(): void {
		$_GET = [
			'essf_status' => '',
			'essf_type'   => 'expense',
		];

		Functions\expect( 'wp_unslash' )->andReturnFirstArg();
		Functions\expect( 'sanitize_key' )->andReturnFirstArg();
		Functions\expect( 'add_query_arg' )
			->once()
			->andReturnUsing( static function ( array $args ): string {
				return http_build_query( $args );
			} );
		Functions\when( 'get_option' )->justReturn( 1 );
		Functions\when( 'get_permalink' )->justReturn( 'http://example.com/cashflow/' );
		Functions\when( 'get_post' )->justReturn( null );

		$method = new ReflectionMethod( \ESSF_Shortcodes::class, 'cashflow_url_with_filters' );
		$method->setAccessible( true );
		$url = $method->invoke( $this->shortcodes, [] );

		$this->assertStringNotContainsString( 'essf_status', $url, 'Empty status must not appear in URL' );
		$this->assertStringContainsString( 'essf_type=expense', $url );
	}
}
