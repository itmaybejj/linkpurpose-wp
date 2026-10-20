/**
 * Persist an admin login as Playwright storage state (.auth/admin.json).
 *
 * Runs once before the other specs (the `setup` project). Uses the stock
 * DDEV credentials admin/admin; override with WP_ADMIN_USER / WP_ADMIN_PASS.
 */

import { test as setup, expect } from '@playwright/test';

const ADMIN_USER = process.env.WP_ADMIN_USER || 'admin';
const ADMIN_PASS = process.env.WP_ADMIN_PASS || 'admin';
const AUTH_FILE = '.auth/admin.json';

async function login( page ) {
	await page.goto( '/wp-login.php' );
	await page.getByLabel( 'Username or Email Address' ).fill( ADMIN_USER );
	await page.getByLabel( 'Password', { exact: true } ).fill( ADMIN_PASS );
	await page.getByRole( 'button', { name: 'Log In' } ).click();
	return page.waitForURL( /\/wp-admin\//, { timeout: 10_000 } ).then( () => true, () => false );
}

setup( 'authenticate as admin', async ( { page } ) => {
	// wp-login.php occasionally bounces the first submit (test cookie not
	// yet set when the form posts); one retry settles it.
	if ( ! ( await login( page ) ) ) {
		await login( page );
	}
	await expect( page ).toHaveURL( /\/wp-admin\// );
	await page.context().storageState( { path: AUTH_FILE } );
} );
