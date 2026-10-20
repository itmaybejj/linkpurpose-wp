/**
 * The real library on a real page: the payload carries every schema
 * field, the shim starts the library, and links get marked.
 */

import { test, expect } from './fixtures.js';
import { SCHEMA, createPost, deletePost, deleteSiteSettings, setSiteSettings, readLinkPurposeState } from './helpers/wp-state.js';

// Link texts avoid the words the library treats as already-descriptive
// (`redundantStrings`: mail, download, document, window, tab…), which would
// suppress the hint by design.
const CONTENT = `
<p>Go <a href="https://elsewhere.example/page">somewhere else</a>,
<a href="mailto:someone@example.com">write to us</a>,
<a href="tel:+15555551212">call us</a>,
read the <a href="/wp-content/uploads/report.pdf">annual report</a>,
get the <a href="/wp-content/uploads/archive.zip" download>source bundle</a>,
<a href="/about/" target="_blank">open the about page elsewhere</a>
or stay on the <a href="/about/">about page</a>.</p>`;

test.describe( 'frontend (real browser)', () => {
	test.describe.configure( { mode: 'serial' } );
	let post;

	test.beforeAll( () => {
		deleteSiteSettings();
		post = createPost( 'Link Purpose e2e fixture', CONTENT );
	} );

	test.afterAll( () => {
		if ( post ) {
			deletePost( post.id );
		}
		deleteSiteSettings();
	} );

	test( 'loads without console errors and the payload carries every schema field', async ( { page } ) => {
		const errors = [];
		page.on( 'pageerror', ( err ) => errors.push( err.message ) );
		page.on( 'console', ( msg ) => {
			if ( 'error' === msg.type() ) {
				errors.push( msg.text() );
			}
		} );
		await page.goto( post.url );
		const state = await readLinkPurposeState( page );
		expect( errors.filter( ( m ) => /linkpurpose|link purpose/i.test( m ) ) ).toEqual( [] );

		for ( const key of Object.keys( SCHEMA ) ) {
			expect( state.settings, `payload is missing ${ key }` ).toHaveProperty( key );
		}
		expect( state.settings.shadowCSS ).toContain( 'linkpurpose.css' );
		expect( state.settings.domain ).toContain( new URL( page.url() ).origin );
		expect( state.version ).toMatch( /^\d+\.\d+\.\d+$/ );
		expect( state.options.ignore ).toContain( '#wpadminbar a' );
	} );

	test( 'marks each category of link with its icon and hint', async ( { page } ) => {
		await page.goto( post.url );
		await readLinkPurposeState( page );
		const article = page.locator( '.entry-content, article, main, body' ).first();

		const expectations = [
			[ 'somewhere else', 'link-purpose-external', 'Link is external' ],
			[ 'write to us', 'link-purpose-mailto', 'Link sends email' ],
			[ 'call us', 'link-purpose-tel', 'Link opens phone app' ],
			[ 'annual report', 'link-purpose-document', 'Link downloads document' ],
			[ 'source bundle', 'link-purpose-download', 'Link downloads file' ],
			[ 'open the about page elsewhere', 'link-purpose-window', 'Link opens in new window' ],
		];
		for ( const [ text, linkClass, hint ] of expectations ) {
			const link = article.locator( 'a', { hasText: text } ).first();
			await expect( link, text ).toHaveClass( new RegExp( `\\b${ linkClass }\\b` ) );
			await expect( link.locator( '.link-purpose-icon' ), `${ text } icon` ).toHaveCount( 1 );
			await expect( link, `${ text } hint` ).toContainText( hint );
		}
		const internal = article.locator( 'a', { hasText: /^about page$/ } ).first();
		await expect( internal ).not.toHaveClass( /link-purpose-/ );
		await expect( internal.locator( '.link-purpose-icon' ) ).toHaveCount( 0 );
	} );

	test( 'site settings change the live options', async ( { page } ) => {
		setSiteSettings( {
			purposeTel: '',
			purposeExternalMessage: 'Leaves this site',
			purposeExternalIconType: 'classes',
			purposeExternalIconClasses: 'fa-solid, fa-link',
		} );
		await page.goto( post.url );
		const state = await readLinkPurposeState( page );
		expect( state.settings.purposeTel ).toBe( false );
		expect( state.options.purposes.tel.selector ).toBe( false );
		expect( state.options.purposes.external.message ).toBe( 'Leaves this site' );
		expect( state.options.purposes.external.iconType ).toBe( 'classes' );
		expect( state.options.purposes.external.iconClasses ).toEqual( [ 'fa-solid', 'fa-link' ] );

		const article = page.locator( '.entry-content, article, main, body' ).first();
		const phone = article.locator( 'a', { hasText: 'call us' } ).first();
		await expect( phone.locator( '.link-purpose-icon' ) ).toHaveCount( 0 );
		const external = article.locator( 'a', { hasText: 'somewhere else' } ).first();
		await expect( external ).toContainText( 'Leaves this site' );
		await expect( external.locator( '.fa-link' ) ).toHaveCount( 1 );
	} );
} );
