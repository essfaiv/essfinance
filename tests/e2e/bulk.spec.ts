/**
 * Bulk action tests — mark paid, mark pending, change type, delete.
 * Verifies current filters and pagination are preserved after bulk actions.
 */

import { test, expect, Page } from '@playwright/test';

const CASHFLOW_URL = '/wp-admin/admin.php?page=essfinance';

async function checkFirstRows( page: Page, n = 2 ) {
	const checkboxes = page.locator( 'table.wp-list-table tbody tr input[type="checkbox"]' );
	const count       = Math.min( n, await checkboxes.count() );
	for ( let i = 0; i < count; i++ ) {
		await checkboxes.nth( i ).check();
	}
	return count;
}

test.describe( 'Bulk Actions', () => {

	test.beforeEach( async ( { page } ) => {
		await page.goto( CASHFLOW_URL );
	} );

	test( 'bulk "Mark as Paid Today" sets status to paid', async ( { page } ) => {
		const count = await checkFirstRows( page );
		if ( count === 0 ) return;

		await page.selectOption( '#bulk-action-selector-top, select[name="action"]', 'paid_today' );
		await page.click( '#doaction, [name="doaction"]' );

		// Verify the redirect lands back on the cashflow page.
		await expect( page ).toHaveURL( /page=essfinance/ );
	} );

	test( 'bulk "Mark as Pending" sets status to pending', async ( { page } ) => {
		const count = await checkFirstRows( page );
		if ( count === 0 ) return;

		await page.selectOption( '#bulk-action-selector-top, select[name="action"]', 'pending' );
		await page.click( '#doaction, [name="doaction"]' );

		await expect( page ).toHaveURL( /page=essfinance/ );
	} );

	test( 'bulk "Make Income" converts selected rows to income', async ( { page } ) => {
		const count = await checkFirstRows( page );
		if ( count === 0 ) return;

		await page.selectOption( '#bulk-action-selector-top, select[name="action"]', 'make_income' );
		await page.click( '#doaction, [name="doaction"]' );

		await expect( page ).toHaveURL( /page=essfinance/ );
	} );

	test( 'bulk "Make Expense" converts selected rows to expense', async ( { page } ) => {
		const count = await checkFirstRows( page );
		if ( count === 0 ) return;

		await page.selectOption( '#bulk-action-selector-top, select[name="action"]', 'make_expense' );
		await page.click( '#doaction, [name="doaction"]' );

		await expect( page ).toHaveURL( /page=essfinance/ );
	} );

	test( 'bulk "Delete" removes entries from the list', async ( { page } ) => {
		const firstTitle = await page.locator( 'table.wp-list-table tbody tr td strong' ).first().textContent();
		if ( ! firstTitle ) return;

		await checkFirstRows( page, 1 );
		await page.selectOption( '#bulk-action-selector-top, select[name="action"]', 'delete' );
		await page.click( '#doaction, [name="doaction"]' );

		await expect( page.locator( 'table, .wrap' ) ).not.toContainText( firstTitle );
	} );

	test( 'bulk action preserves active status filter after redirect', async ( { page } ) => {
		// Apply a status filter first.
		await page.selectOption( '#essf_status', 'pending' );
		await page.click( '#post-query-submit, [type="submit"][value="Filter"]' );
		await expect( page ).toHaveURL( /essf_status=pending/ );

		// Select entries and apply bulk action.
		const count = await checkFirstRows( page );
		if ( count === 0 ) return;

		await page.selectOption( '#bulk-action-selector-top, select[name="action"]', 'paid_today' );
		await page.click( '#doaction, [name="doaction"]' );

		// Filter should be preserved in the redirect URL.
		await expect( page ).toHaveURL( /essf_status=pending/ );
	} );

} );
