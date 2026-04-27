/**
 * Auth setup — logs in once and saves browser storage state so all tests
 * share a persistent session without repeating the login flow.
 */

import { test as setup } from '@playwright/test';
import path from 'path';

const authFile = path.join( __dirname, '.auth.json' );

const adminUser = process.env.ESSF_ADMIN_USER ?? 'admin';
const adminPass = process.env.ESSF_ADMIN_PASS ?? '';

setup( 'authenticate', async ( { page } ) => {
	await page.goto( '/wp-login.php' );
	await page.fill( '#user_login', adminUser );
	await page.fill( '#user_pass', adminPass );
	await page.click( '#wp-submit' );
	await page.waitForURL( /wp-admin/ );
	await page.context().storageState( { path: authFile } );
} );
