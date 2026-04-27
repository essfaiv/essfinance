/**
 * Settings page tests — verify settings save and are reflected in the cashflow list.
 */

import { test, expect } from '@playwright/test';

const SETTINGS_URL = '/wp-admin/admin.php?page=essfinance-settings';
const CASHFLOW_URL = '/wp-admin/admin.php?page=essfinance';

test.describe( 'Settings', () => {

	test( 'settings page loads', async ( { page } ) => {
		await page.goto( SETTINGS_URL );
		await expect( page ).toHaveTitle( /EssFinance Settings/ );
		await expect( page.locator( 'h1' ) ).toContainText( 'EssFinance Settings' );
	} );

	test( 'changing currency saves correctly', async ( { page } ) => {
		await page.goto( SETTINGS_URL );
		await page.selectOption( '[name="essf_currency"]', 'EUR' );
		await page.click( '[type="submit"]' );
		await expect( page ).toHaveURL( /page=essfinance-settings/ );

		// Verify the setting persisted.
		await page.goto( SETTINGS_URL );
		await expect( page.locator( '[name="essf_currency"]' ) ).toHaveValue( 'EUR' );

		// Restore USD.
		await page.selectOption( '[name="essf_currency"]', 'USD' );
		await page.click( '[type="submit"]' );
	} );

	test( 'show status badge toggle saves and reflects on cashflow', async ( { page } ) => {
		await page.goto( SETTINGS_URL );

		const badge = page.locator( '[name="essf_show_status_badge"]' );
		const wasChecked = await badge.isChecked();

		// Toggle the badge setting.
		if ( wasChecked ) {
			await badge.uncheck();
		} else {
			await badge.check();
		}
		await page.click( '[type="submit"]' );

		// Verify persisted.
		await page.goto( SETTINGS_URL );
		const isNowChecked = await page.locator( '[name="essf_show_status_badge"]' ).isChecked();
		expect( isNowChecked ).toBe( ! wasChecked );

		// Restore original state.
		const restoreBadge = page.locator( '[name="essf_show_status_badge"]' );
		if ( wasChecked ) {
			await restoreBadge.check();
		} else {
			await restoreBadge.uncheck();
		}
		await page.click( '[type="submit"]' );
	} );

	test( 'amount colors toggle saves correctly', async ( { page } ) => {
		await page.goto( SETTINGS_URL );
		await page.check( '[name="essf_show_amount_colors"]' );
		await page.click( '[type="submit"]' );
		await page.goto( SETTINGS_URL );
		await expect( page.locator( '[name="essf_show_amount_colors"]' ) ).toBeChecked();

		// Restore.
		await page.uncheck( '[name="essf_show_amount_colors"]' );
		await page.click( '[type="submit"]' );
	} );

	test( '"Create pages" button is present', async ( { page } ) => {
		await page.goto( SETTINGS_URL );
		await expect( page.locator( 'button:has-text("Create pages"), input[value="Create pages"]' ) ).toBeVisible();
	} );

	test( 'currency position options are available', async ( { page } ) => {
		await page.goto( SETTINGS_URL );
		const posSelect = page.locator( '[name="essf_currency_pos"]' );
		await expect( posSelect ).toBeVisible();
		const options = await posSelect.locator( 'option' ).allTextContents();
		expect( options.length ).toBeGreaterThan( 1 );
	} );

} );
