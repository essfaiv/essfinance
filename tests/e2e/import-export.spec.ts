/**
 * Import / Export tests — CSV round-trip, duplicate deduplication.
 */

import { test, expect, Page } from '@playwright/test';
import fs from 'fs';
import path from 'path';
import os from 'os';

const CASHFLOW_URL = '/wp-admin/admin.php?page=essfinance';

async function getExportUrl( page: Page ): Promise<string | null> {
	const exportLink = page.locator( 'a:has-text("Export")' );
	return exportLink.getAttribute( 'href' );
}

test.describe( 'Import / Export', () => {

	test( 'export CSV button is present on the cashflow page', async ( { page } ) => {
		await page.goto( CASHFLOW_URL );
		await expect( page.locator( 'a:has-text("Export")' ) ).toBeVisible();
	} );

	test( 'export CSV triggers a file download', async ( { page, context } ) => {
		await page.goto( CASHFLOW_URL );

		const [ download ] = await Promise.all( [
			page.waitForEvent( 'download' ),
			page.locator( 'a:has-text("Export")' ).click(),
		] );

		expect( download.suggestedFilename() ).toMatch( /\.csv$/i );
	} );

	test( 'import page is accessible', async ( { page } ) => {
		await page.goto( `${CASHFLOW_URL}&essf_action=import` );
		await expect( page.locator( '[type="file"], input[name="essf_import"]' ) ).toBeVisible();
	} );

	test( 'import CSV file round-trip adds entries', async ( { page } ) => {
		// Write a minimal CSV to a temp file.
		const csvContent = [
			'Description,Amount,Due Date,Pay Date,Status',
			'Test Import Entry,500,2026-03-01,,pending',
		].join( '\n' );
		const tmpFile = path.join( os.tmpdir(), 'essf-test-import.csv' );
		fs.writeFileSync( tmpFile, csvContent );

		await page.goto( `${CASHFLOW_URL}&essf_action=import` );
		await page.setInputFiles( 'input[type="file"]', tmpFile );
		await page.click( 'button[type="submit"], input[type="submit"]' );

		// After import we should be back on the cashflow list.
		await expect( page ).toHaveURL( /page=essfinance/ );

		// Verify the imported entry appears in the list.
		await page.goto( CASHFLOW_URL );
		await expect( page.locator( 'table, .essf-list' ) ).toContainText( 'Test Import Entry' );

		fs.unlinkSync( tmpFile );
	} );

	test( 're-importing the same CSV does not duplicate entries', async ( { page } ) => {
		const csvContent = [
			'Description,Amount,Due Date,Pay Date,Status',
			'Dedup Import Entry,999,2026-04-01,,pending',
		].join( '\n' );
		const tmpFile = path.join( os.tmpdir(), 'essf-dedup-import.csv' );
		fs.writeFileSync( tmpFile, csvContent );

		// Import once.
		await page.goto( `${CASHFLOW_URL}&essf_action=import` );
		await page.setInputFiles( 'input[type="file"]', tmpFile );
		await page.click( 'button[type="submit"], input[type="submit"]' );

		// Import the same file again.
		await page.goto( `${CASHFLOW_URL}&essf_action=import` );
		await page.setInputFiles( 'input[type="file"]', tmpFile );
		await page.click( 'button[type="submit"], input[type="submit"]' );

		// Count how many times the entry appears — should not be doubled.
		await page.goto( CASHFLOW_URL );
		const matches = await page.locator( 'table tbody tr td strong' )
			.allTextContents();
		const duplicates = matches.filter( t => t.includes( 'Dedup Import Entry' ) );
		expect( duplicates.length ).toBeLessThanOrEqual( 1 );

		fs.unlinkSync( tmpFile );
	} );

} );
