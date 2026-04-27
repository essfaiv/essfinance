/**
 * Visual regression tests — screenshot comparisons for key UI states.
 *
 * Run once to create baselines:
 *   ESSF_BASE_URL=http://localhost:8883 npm run test:update-snapshots
 *
 * Then on every run:
 *   ESSF_BASE_URL=http://localhost:8883 npm run test:visual
 */

import { test, expect } from '@playwright/test';

const CASHFLOW_URL  = '/wp-admin/admin.php?page=essfinance';
const SETTINGS_URL  = '/wp-admin/admin.php?page=essfinance-settings';

test.describe( 'Visual Regression', () => {

	test( 'cashflow list — desktop', async ( { page } ) => {
		await page.goto( CASHFLOW_URL );
		await page.waitForLoadState( 'networkidle' );
		await expect( page ).toHaveScreenshot( 'cashflow-list-desktop.png', {
			fullPage:  true,
			maxDiffPixelRatio: 0.02,
		} );
	} );

	test( 'cashflow list — mobile', async ( { page } ) => {
		await page.setViewportSize( { width: 390, height: 844 } );
		await page.goto( CASHFLOW_URL );
		await page.waitForLoadState( 'networkidle' );
		await expect( page ).toHaveScreenshot( 'cashflow-list-mobile.png', {
			fullPage: true,
			maxDiffPixelRatio: 0.02,
		} );
	} );

	test( 'add entry form', async ( { page } ) => {
		await page.goto( CASHFLOW_URL );
		await page.waitForLoadState( 'networkidle' );
		// Capture the left column add form.
		const form = page.locator( '#col-left, .col-left' ).first();
		await expect( form ).toHaveScreenshot( 'add-entry-form.png', {
			maxDiffPixelRatio: 0.02,
		} );
	} );

	test( 'settings page', async ( { page } ) => {
		await page.goto( SETTINGS_URL );
		await page.waitForLoadState( 'networkidle' );
		await expect( page ).toHaveScreenshot( 'settings-page.png', {
			fullPage: true,
			maxDiffPixelRatio: 0.02,
		} );
	} );

	test( 'cashflow with paid filter active', async ( { page } ) => {
		await page.goto( `${CASHFLOW_URL}&essf_status=paid` );
		await page.waitForLoadState( 'networkidle' );
		await expect( page ).toHaveScreenshot( 'cashflow-filtered-paid.png', {
			fullPage: true,
			maxDiffPixelRatio: 0.02,
		} );
	} );

	test( 'edit entry form', async ( { page } ) => {
		// Navigate to the edit form of the first entry.
		await page.goto( CASHFLOW_URL );
		const firstEditLink = page.locator( 'table.wp-list-table tbody tr a:has-text("Edit")' ).first();
		if ( await firstEditLink.count() === 0 ) {
			test.skip();
			return;
		}
		await firstEditLink.click();
		await page.waitForURL( /entry=\d+/ );
		await page.waitForLoadState( 'networkidle' );
		await expect( page ).toHaveScreenshot( 'edit-entry-form.png', {
			fullPage: true,
			maxDiffPixelRatio: 0.02,
		} );
	} );

} );
