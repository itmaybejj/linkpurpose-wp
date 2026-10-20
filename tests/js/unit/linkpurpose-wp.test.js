/**
 * Unit tests for the front-end init shim (assets/js/linkpurpose-wp.js).
 *
 * The shim is a classic script. We stub the globals it reads
 * (`window.linkpurposeSettings`, the `LinkPurpose` class) and import it
 * fresh per test with vi.resetModules() so its top-level IIFE re-runs.
 * The settings payload is derived from the schema fixture so it always
 * carries exactly the fields PHP emits.
 */
import { SHIM, loadShim, payloadFromSchema } from './helpers.js';

/** Payload for an untouched site, with per-test overrides. */
function baseSettings( overrides = {} ) {
	return payloadFromSchema( { domain: 'a.net, https://test.example', ...overrides } );
}

beforeAll( () => {
	// jsdom lacks CSS.supports; the shim treats absence as "too old" and bails.
	globalThis.CSS = { supports: () => true };
} );

describe( 'initialization', () => {
	test( 'constructs the library once with built options', async () => {
		await loadShim( baseSettings() );
		expect( globalThis.LinkPurpose ).toHaveBeenCalledTimes( 1 );
		const options = globalThis.LinkPurpose.mock.calls[ 0 ][ 0 ];
		expect( options.domain ).toBe( 'a.net, https://test.example' );
		expect( options.roots ).toBe( 'body' );
		expect( options.purposes.external.selector ).toContain( '[href^="https://"]' );
		expect( window.linkPurpose ).toBeDefined();
	} );

	test( 'does nothing without settings or library, but still exposes helpers', async () => {
		const api = await loadShim( undefined );
		expect( globalThis.LinkPurpose ).not.toHaveBeenCalled();
		expect( typeof api.buildOptions ).toBe( 'function' );

		await loadShim( baseSettings(), { withLibrary: false } );
		expect( window.linkPurpose ).toBeUndefined();
	} );

	test( 'does not initialize twice', async () => {
		await loadShim( baseSettings() );
		window.linkPurpose = { already: true };
		vi.resetModules();
		await import( SHIM );
		expect( globalThis.LinkPurpose ).toHaveBeenCalledTimes( 1 );
	} );

	test( 'theme override hook receives and replaces options', async () => {
		vi.resetModules();
		window.linkPurposeOptions = vi.fn( ( options ) => ( { ...options, roots: '#themed' } ) );
		globalThis.LinkPurpose = vi.fn();
		window.linkpurposeSettings = baseSettings();
		delete window.linkPurpose;
		await import( SHIM );
		expect( window.linkPurposeOptions ).toHaveBeenCalledTimes( 1 );
		expect( globalThis.LinkPurpose.mock.calls[ 0 ][ 0 ].roots ).toBe( '#themed' );
	} );
} );

describe( 'buildOptions', () => {
	let api;
	beforeEach( async () => {
		api = await loadShim( baseSettings() );
	} );

	test( 'invalid selectors fall back to defaults with a warning', () => {
		const warn = vi.spyOn( console, 'warn' ).mockImplementation( () => {} );
		const options = api.buildOptions( baseSettings( { roots: 'main >>> [', ignore: '.ok a' } ) );
		expect( options.roots ).toBe( 'body' );
		expect( options.ignore ).toBe( '.ok a, #wpadminbar a' );
		expect( warn ).toHaveBeenCalledWith( expect.stringContaining( 'Check roots' ) );
		warn.mockRestore();
	} );

	test( 'admin bar links are always ignored', () => {
		expect( api.buildOptions( baseSettings() ).ignore ).toBe( '#wpadminbar a' );
	} );

	test( 'disabled categories get selector false', () => {
		const options = api.buildOptions( baseSettings( { purposeExternal: false, purposeMail: false, purposeDocument: false } ) );
		expect( options.purposes.external.selector ).toBe( false );
		expect( options.purposes.mail.selector ).toBe( false );
		expect( options.purposes.document.selector ).toBe( false );
		expect( options.purposes.tel.selector ).toBe( '[href^="tel:"]' );
	} );

	test( 'per-purpose fields apply with sanitized fallbacks', () => {
		const options = api.buildOptions( baseSettings( {
			purposeExternalIconType: 'classes',
			purposeExternalIconClasses: 'fa-solid fa-link, ext',
			purposeExternalClass: 'ext link',
			purposeExternalIconWrapperClass: '',
			purposeExternalMessage: '',
			purposeExternalIconPosition: '',
			purposeExternalNewWindow: true,
			purposeExternalNoReferrer: true,
			purposeExternalSelector: '.in-the-news a',
		} ) );
		const external = options.purposes.external;
		expect( external.iconType ).toBe( 'classes' );
		expect( external.iconClasses ).toEqual( [ 'fa-solid', 'fa-link', 'ext' ] );
		expect( external.linkClass ).toBe( 'extlink' );
		expect( external.iconWrapperClass ).toBe( 'link-purpose-external-icon' );
		expect( external.message ).toBe( 'Link is external' );
		expect( external.iconPosition ).toBe( 'beforeend' );
		expect( external.newWindow ).toBe( true );
		expect( external.additionalSelector ).toBe( '.in-the-news a' );
		expect( options.noReferrer ).toBe( true );
	} );

	test( 'message defaults are not swapped between tel and newWindow (Drupal bug)', () => {
		const options = api.buildOptions( baseSettings( { purposeTelMessage: '', purposeNewWindowMessage: '' } ) );
		expect( options.purposes.tel.message ).toBe( 'Link opens phone app' );
		expect( options.purposes.newWindow.message ).toBe( 'Link opens in new window' );
	} );

	test( 'developer-only keys pass through when present', () => {
		const options = api.buildOptions( baseSettings( { baseSelector: 'a[href], custom-link', baseLinkClass: 'lp' } ) );
		expect( options.baseSelector ).toBe( 'a[href], custom-link' );
		expect( options.baseLinkClass ).toBe( 'lp' );
		expect( api.buildOptions( baseSettings() ).baseSelector ).toBe( 'a[href]' );
	} );

	test( 'shadowCSS passes through, false when absent', () => {
		expect( api.buildOptions( baseSettings() ).shadowCSS ).toContain( 'linkpurpose.css' );
		expect( api.buildOptions( baseSettings( { shadowCSS: '' } ) ).shadowCSS ).toBe( false );
	} );
} );

describe( 'helpers', () => {
	let api;
	beforeEach( async () => {
		api = await loadShim( undefined );
	} );

	test( 'sanitizeClass', () => {
		expect( api.sanitizeClass( 'a b!', 'd' ) ).toBe( 'ab' );
		expect( api.sanitizeClass( '', 'd' ) ).toBe( 'd' );
		expect( api.sanitizeClass( '!!', 'd' ) ).toBe( 'd' );
	} );

	test( 'classArray', () => {
		expect( api.classArray( 'a, b c', [ 'd' ] ) ).toEqual( [ 'a', 'b', 'c' ] );
		expect( api.classArray( '', [ 'd' ] ) ).toEqual( [ 'd' ] );
		expect( api.classArray( ' , ', [ 'd' ] ) ).toEqual( [ 'd' ] );
	} );
} );
