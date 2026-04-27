/**
 * Filter tests — status, type, month, search, and combinations.
 */

import { test, expect, Page } from '@playwright/test';

const CASHFLOW_URL = '/wp-admin/admin.php?page=essfinance';

async function selectStatusFilter( page: Page, status: string ) {
	await page.selectOption( '#essf_status', status );
	await page.click( '#post-query-submit, [type="submit"][value="Filter"]' );
}

test.describe( 'Cash Flow Filters', () => {

	test.beforeEach( async ( { page } ) => {
		await page.goto( CASHFLOW_URL );
	} );

	test( 'status filter "All" shows all entries', async ( { page } ) => {
		await selectStatusFilter( page, '' );
		const rows = page.locator( 'table.wp-list-table tbody tr' );
		await expect( rows ).not.toHaveCount( 0 );
	} );

	test( 'status filter "paid" shows only paid entries', async ( { page } ) => {
		await selectStatusFilter( page, 'paid' );
		const statusCells = page.locator( 'table.wp-list-table td.column-status' );
		const count        = await statusCells.count();
		for ( let i = 0; i < count; i++ ) {
			await expect( statusCells.nth( i ) ).toContainText( /paid/i );
		}
	} );

	test( 'status filter "pending" shows only pending entries', async ( { page } ) => {
		await selectStatusFilter( page, 'pending' );
		const statusCells = page.locator( 'table.wp-list-table td.column-status' );
		const count        = await statusCells.count();
		for ( let i = 0; i < count; i++ ) {
			await expect( statusCells.nth( i ) ).toContainText( /pending/i );
		}
	} );

	test( 'month filter uses YYYYMM format in URL', async ( { page } ) => {
		const monthSelect = page.locator( '[name="essf_m"]' );
		const optionCount = await monthSelect.locator( 'option' ).count();

		if ( optionCount <= 1 ) {
			test.skip(); // No month options to test.
			return;
		}

		// Select the second option (first real month).
		const monthValue = await monthSelect.locator( 'option' ).nth( 1 ).getAttribute( 'value' );
		await monthSelect.selectOption( monthValue ?? '' );
		await page.click( '#post-query-submit, [type="submit"][value="Filter"]' );

		// YYYYMM must be 6 digits, not just the month number.
		expect( monthValue ).toMatch( /^\d{6}$/ );
		await expect( page ).toHaveURL( new RegExp( `essf_m=${monthValue}` ) );
	} );

	test( 'search narrows the list', async ( { page } ) => {
		// Only run if there are entries with a unique keyword.
		const firstTitle = await page.locator( 'table.wp-list-table tbody tr td strong' ).first().textContent();
		if ( ! firstTitle ) return;

		const keyword = firstTitle.split( ' ' )[0];
		await page.fill( '[name="s"]', keyword );
		await page.click( '#search-submit' );
		await expect( page.locator( 'table.wp-list-table' ) ).toContainText( keyword );
	} );

	test( 'filters are preserved after pagination navigation', async ( { page } ) => {
		await selectStatusFilter( page, 'paid' );
		const url = page.url();

		// If there's a next page, follow it and verify filter is retained.
		const nextLink = page.locator( '.tablenav-pages a.next-page' );
		if ( await nextLink.count() > 0 ) {
			await nextLink.click();
			await expect( page ).toHaveURL( /essf_status=paid/ );
		} else {
			// Single page — just verify current URL has the filter.
			expect( url ).toContain( 'essf_status=paid' );
		}
	} );

} );
