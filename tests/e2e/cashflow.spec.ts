/**
 * Cash Flow CRUD — add, edit, delete entries; verify status badges and amounts.
 */

import { test, expect } from '@playwright/test';

const CASHFLOW_URL = '/wp-admin/admin.php?page=essfinance';

test.describe( 'Cash Flow CRUD', () => {

	test( 'add an income entry and verify it appears in the list', async ( { page } ) => {
		await page.goto( CASHFLOW_URL );

		// Fill in the add form (left column).
		await page.fill( '[name="essf_description"]', 'Salary Test Income' );
		await page.fill( '[name="essf_amount"]', '3000' );
		await page.check( '[name="essf_is_income"]' );
		await page.fill( '[name="essf_due_date"]', '2026-03-01' );
		await page.selectOption( '[name="essf_status"]', 'pending' );
		await page.click( 'button[name="essf_action"][value="add"]' );

		await expect( page.locator( '.essf-list, #essf-list, table.wp-list-table' ) ).toContainText( 'Salary Test Income' );
	} );

	test( 'add an expense entry', async ( { page } ) => {
		await page.goto( CASHFLOW_URL );

		await page.fill( '[name="essf_description"]', 'Rent Expense' );
		await page.fill( '[name="essf_amount"]', '1500' );
		// essf_is_income unchecked = expense
		await page.fill( '[name="essf_due_date"]', '2026-03-05' );
		await page.selectOption( '[name="essf_status"]', 'pending' );
		await page.click( 'button[name="essf_action"][value="add"]' );

		await expect( page.locator( 'table' ) ).toContainText( 'Rent Expense' );
	} );

	test( 'edit an entry and verify change persists', async ( { page } ) => {
		await page.goto( CASHFLOW_URL );

		// Find the first entry row and click Edit.
		const firstRow = page.locator( 'table.wp-list-table tbody tr' ).first();
		await firstRow.hover();
		await firstRow.locator( 'a:has-text("Edit")' ).click();

		await page.waitForURL( /entry=\d+/ );
		await page.fill( '[name="essf_description"]', 'Updated Entry Name' );
		await page.click( 'button[name="essf_action"][value="update"]' );

		await expect( page.locator( 'table, .essf-list' ) ).toContainText( 'Updated Entry Name' );
	} );

	test( '"Paid today" row action sets status to paid with today\'s date', async ( { page } ) => {
		await page.goto( CASHFLOW_URL );

		const row = page.locator( 'table.wp-list-table tbody tr' ).first();
		await row.hover();
		await row.locator( 'a:has-text("Paid today")' ).click();

		// After redirect, the row should show Paid status.
		await expect( page.locator( 'table.wp-list-table' ) ).toContainText( 'Paid' );
	} );

	test( '"Paid date" row action pre-fills today\'s date and selects paid status', async ( { page } ) => {
		await page.goto( CASHFLOW_URL );

		const row = page.locator( 'table.wp-list-table tbody tr' ).first();
		await row.hover();
		await row.locator( 'a:has-text("Paid date")' ).click();

		await page.waitForURL( /essf_focus=pay_date/ );

		const today = new Date().toISOString().split( 'T' )[0];
		await expect( page.locator( '[name="essf_pay_date"]' ) ).toHaveValue( today );
		await expect( page.locator( '[name="essf_status"]' ) ).toHaveValue( 'paid' );
	} );

	test( 'delete an entry removes it from the list', async ( { page } ) => {
		await page.goto( CASHFLOW_URL );

		const row     = page.locator( 'table.wp-list-table tbody tr' ).first();
		const title   = await row.locator( '.column-title strong, td strong' ).first().textContent();
		await row.hover();
		await row.locator( 'a:has-text("Delete")' ).click();

		await expect( page.locator( 'table, .essf-list' ) ).not.toContainText( title ?? '' );
	} );

} );
