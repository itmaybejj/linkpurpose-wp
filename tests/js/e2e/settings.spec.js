/**
 * Settings → Link Purpose: the real form round-trips through options.php.
 */

import { test, expect } from './fixtures.js';
import { SCHEMA, deleteSiteSettings, getSiteSettings } from './helpers/wp-state.js';

const PAGE = '/wp-admin/options-general.php?page=linkpurpose';

async function openAllGroups( page ) {
	await page.locator( 'details.linkpurpose-group' ).evaluateAll( ( nodes ) => nodes.forEach( ( node ) => { node.open = true; } ) );
}

test.describe( 'settings page', () => {
	test.describe.configure( { mode: 'serial' } );

	test.beforeAll( () => deleteSiteSettings() );
	test.afterAll( () => deleteSiteSettings() );

	test( 'renders an input for every schema field', async ( { page } ) => {
		await page.goto( PAGE );
		await expect( page.getByRole( 'heading', { level: 1 } ) ).toContainText( 'Link Purpose' );
		for ( const key of Object.keys( SCHEMA ) ) {
			await expect( page.locator( `#linkpurpose-${ key }` ), key ).toHaveCount( 1 );
		}
	} );

	test( 'saves and reloads values, including an unchecked default-on checkbox', async ( { page } ) => {
		await page.goto( PAGE );
		await openAllGroups( page );
		await page.locator( '#linkpurpose-roots' ).fill( 'main, #footer' );
		await page.locator( '#linkpurpose-purposeTel' ).uncheck();
		await page.locator( '#linkpurpose-purposeExternalIconType' ).selectOption( 'classes' );
		await page.locator( '#linkpurpose-purposeExternalIconClasses' ).fill( 'fa-solid fa-link' );
		await page.locator( '#linkpurpose-purposeExternalMessage' ).fill( 'Leaves the site' );
		await page.getByRole( 'button', { name: 'Save settings' } ).click();
		await expect( page.locator( '#setting-error-settings_updated, .notice-success' ).first() ).toBeVisible();

		const stored = getSiteSettings();
		expect( stored.roots ).toBe( 'main, #footer' );
		expect( stored.purposeTel ).toBe( '' );
		expect( stored.purposeMail ).toBe( '1' );
		expect( stored.purposeExternalIconType ).toBe( 'classes' );
		expect( stored.purposeExternalIconClasses ).toBe( 'fa-solid, fa-link' );
		expect( stored.purposeExternalMessage ).toBe( 'Leaves the site' );
		expect( Object.keys( stored ).sort() ).toEqual( Object.keys( SCHEMA ).sort() );

		await page.goto( PAGE );
		await openAllGroups( page );
		await expect( page.locator( '#linkpurpose-roots' ) ).toHaveValue( 'main, #footer' );
		await expect( page.locator( '#linkpurpose-purposeTel' ) ).not.toBeChecked();
		await expect( page.locator( '#linkpurpose-purposeMail' ) ).toBeChecked();
		await expect( page.locator( '#linkpurpose-purposeExternalIconType' ) ).toHaveValue( 'classes' );
		await expect( page.locator( '#linkpurpose-purposeExternalIconClasses' ) ).toHaveValue( 'fa-solid, fa-link' );
	} );

	test( 'conditional rows follow their toggles', async ( { page } ) => {
		await page.goto( PAGE );
		await openAllGroups( page );
		const telMessageRow = page.locator( '#linkpurpose-purposeTelMessage' ).locator( 'xpath=ancestor::tr' );
		await expect( telMessageRow ).toBeHidden();
		await page.locator( '#linkpurpose-purposeTel' ).check();
		await expect( telMessageRow ).toBeVisible();
		await page.locator( '#linkpurpose-purposeTel' ).uncheck();
		await expect( telMessageRow ).toBeHidden();
	} );
} );
