/**
 * Multisite: a network lock disables the subsite input and wins in the
 * subsite payload; a blank text lock pins the plugin default.
 */

import { test, expect } from './fixtures.js';
import {
	isMultisite,
	firstSubsiteUrl,
	setNetworkSettings,
	deleteNetworkSettings,
	setSiteSettings,
	deleteSiteSettings,
	readLinkPurposeState,
} from './helpers/wp-state.js';

test.describe( 'network defaults', () => {
	test.describe.configure( { mode: 'serial' } );
	let subsite;

	test.beforeAll( () => {
		test.skip( ! isMultisite(), 'multisite only' );
		subsite = firstSubsiteUrl();
		test.skip( ! subsite, 'needs at least one subsite' );
		deleteNetworkSettings();
		setSiteSettings( { roots: 'custom-root', purposeExternal: '1', purposeExternalMessage: 'Subsite hint' }, subsite );
		setNetworkSettings( {
			values: { roots: '', purposeExternal: '', purposeTelMessage: 'Network phone hint' },
			modes: { roots: 'lock', purposeExternal: 'lock', purposeTelMessage: 'lock' },
		} );
	} );

	test.afterAll( () => {
		deleteNetworkSettings();
		if ( subsite ) {
			deleteSiteSettings( subsite );
		}
	} );

	test( 'network page renders with a propagation dropdown per field', async ( { page } ) => {
		await page.goto( '/wp-admin/network/settings.php?page=linkpurpose-network' );
		await expect( page.getByRole( 'heading', { level: 1 } ) ).toContainText( 'Link Purpose defaults' );
		await expect( page.locator( '#linkpurpose-roots-mode' ) ).toHaveValue( 'lock' );
		await expect( page.locator( '#linkpurpose-purposeExternal-mode' ) ).toHaveValue( 'lock' );
		await expect( page.locator( '#linkpurpose-purposeMail-mode' ) ).toHaveValue( '' );
		await expect( page.locator( '#linkpurpose-roots' ) ).toHaveValue( '' );
		await expect( page.locator( '#linkpurpose-roots' ) ).toBeEnabled();
	} );

	test( 'locked fields are disabled on the subsite settings page', async ( { page } ) => {
		await page.goto( `${ subsite }wp-admin/options-general.php?page=linkpurpose` );
		await page.locator( 'details.linkpurpose-group' ).evaluateAll( ( nodes ) => nodes.forEach( ( node ) => { node.open = true; } ) );
		await expect( page.locator( '#linkpurpose-roots' ) ).toBeDisabled();
		await expect( page.locator( '#linkpurpose-roots' ) ).toHaveValue( '', { message: 'blank lock shows the placeholder, not the stale custom value' } );
		await expect( page.locator( '#linkpurpose-purposeExternal' ) ).toBeDisabled();
		await expect( page.locator( '#linkpurpose-purposeExternal' ) ).not.toBeChecked();
		await expect( page.locator( '#linkpurpose-purposeTelMessage' ) ).toBeDisabled();
		await expect( page.locator( '#linkpurpose-purposeTelMessage' ) ).toHaveValue( 'Network phone hint' );
		await expect( page.locator( '#linkpurpose-purposeMail' ) ).toBeEnabled();
		await expect( page.locator( '.linkpurpose-locked-by-network' ).first() ).toBeVisible();
	} );

	test( 'locks win in the subsite payload', async ( { page } ) => {
		await page.goto( subsite );
		const state = await readLinkPurposeState( page );
		expect( state.settings.roots ).toBe( '' );
		expect( state.options.roots ).toBe( 'body' );
		expect( state.settings.purposeExternal ).toBe( false );
		expect( state.options.purposes.external.selector ).toBe( false );
		expect( state.settings.purposeTelMessage ).toBe( 'Network phone hint' );
		expect( state.settings.purposeExternalMessage ).toBe( 'Subsite hint' );
		expect( state.settings.domain ).toContain( new URL( subsite ).origin );
	} );
} );
