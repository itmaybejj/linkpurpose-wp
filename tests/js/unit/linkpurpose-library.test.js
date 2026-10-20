/**
 * The shim against the REAL vendored library (assets/lib/js/linkpurpose.js).
 *
 * The other suites stub `LinkPurpose`. This one evaluates the actual
 * library source so a typo in an option name (`hideIcons`, `purposes.mailto`)
 * — which the library would silently ignore — is caught: every key the shim
 * emits must be one the library's own defaults declare.
 */
import { readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { SHIM, payloadFromSchema } from './helpers.js';

const here = dirname( fileURLToPath( import.meta.url ) );
const LIBRARY = join( here, '..', '..', '..', 'assets', 'lib', 'js', 'linkpurpose.js' );

function loadLibrary() {
	const source = readFileSync( LIBRARY, 'utf8' );
	// Indirect eval runs in global scope; the class declaration itself stays
	// in the eval's lexical scope, so export it explicitly.
	( 0, eval )( `${ source }\n;globalThis.LinkPurpose = LinkPurpose;` );
	return globalThis.LinkPurpose;
}

async function runShim( settings ) {
	vi.resetModules();
	delete window.linkPurpose;
	delete window.linkPurposeWP;
	window.linkpurposeSettings = settings;
	await import( SHIM );
}

beforeAll( () => {
	globalThis.CSS = { supports: () => true };
} );

describe( 'shim + vendored library', () => {
	test( 'library defaults are reachable', () => {
		const LinkPurpose = loadLibrary();
		new LinkPurpose( {} );
		expect( LinkPurpose.options ).toBeDefined();
		expect( LinkPurpose.options.purposes ).toHaveProperty( 'external' );
		expect( LinkPurpose.version ).toMatch( /^\d+\.\d+\.\d+$/ );
	} );

	test( 'every option the shim emits is one the library declares', async () => {
		const LinkPurpose = loadLibrary();
		new LinkPurpose( {} );
		const defaults = JSON.parse( JSON.stringify( LinkPurpose.options, ( _k, v ) => ( v instanceof RegExp ? String( v ) : v ) ) );

		await runShim( payloadFromSchema() );
		const options = window.linkPurposeWP.buildOptions( payloadFromSchema() );

		for ( const key of Object.keys( options ) ) {
			expect( defaults, `top-level option "${ key }" is unknown to the library` ).toHaveProperty( key );
		}
		for ( const [ id, purpose ] of Object.entries( options.purposes ) ) {
			expect( defaults.purposes, `purpose "${ id }" is unknown to the library` ).toHaveProperty( id );
			for ( const prop of Object.keys( purpose ) ) {
				expect( defaults.purposes[ id ], `purposes.${ id }.${ prop } is unknown to the library` ).toHaveProperty( prop );
			}
		}
	} );

	test( 'the library receives and keeps the shim options', async () => {
		loadLibrary();
		document.body.innerHTML = '<main><a href="https://elsewhere.example/page">Away</a> <a href="mailto:x@example.com">Mail</a></main>';
		await runShim( payloadFromSchema( {
			roots: 'main',
			purposeExternalMessage: 'Leaves the site',
			purposeMailIconType: 'classes',
			purposeMailIconClasses: 'fa-solid, fa-at',
		} ) );
		expect( window.linkPurpose ).toBeInstanceOf( globalThis.LinkPurpose );
		const live = globalThis.LinkPurpose.options;
		expect( live.roots ).toBe( 'main' );
		expect( live.purposes.external.message ).toBe( 'Leaves the site' );
		expect( live.purposes.mail.iconType ).toBe( 'classes' );
		expect( live.purposes.mail.iconClasses ).toEqual( [ 'fa-solid', 'fa-at' ] );
		expect( live.ignore ).toBe( '#wpadminbar a' );
		expect( live.purposes.external.selector ).toContain( 'test.example' );
	} );
} );
