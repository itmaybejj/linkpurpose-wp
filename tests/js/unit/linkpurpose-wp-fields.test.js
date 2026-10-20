/**
 * Every schema field must reach the library options.
 *
 * History: fields have been dropped between the settings form and the
 * library more than once. This suite walks the generated schema fixture
 * (every field PHP defines) and proves each one changes what the shim
 * hands to `new LinkPurpose()`. A field that is stored, sanitized and
 * printed into the payload but never read by the shim fails here.
 */
import { SCHEMA, SCHEMA_KEYS, loadShim, payloadFromSchema, distinctiveValue } from './helpers.js';

/**
 * Fields the shim does not read on purpose. Each needs a reason and a
 * test elsewhere that proves it is consumed.
 */
const CONSUMED_BY_PHP = {
	// Assets::enqueue() skips the shim entirely; covered by
	// Test_LinkPurpose_FrontendAssets::test_theme_preprocess_skips_init_shim_but_keeps_payload.
	themePreprocess: true,
};

describe( 'schema fixture', () => {
	test( 'is non-trivial and covers every purpose', () => {
		expect( SCHEMA_KEYS.length ).toBeGreaterThan( 60 );
		for ( const purpose of [ 'External', 'Document', 'Download', 'App', 'Mail', 'Tel', 'NewWindow' ] ) {
			for ( const suffix of [ '', 'IconType', 'IconClasses', 'IconPosition', 'Message', 'Class', 'IconWrapperClass' ] ) {
				expect( SCHEMA_KEYS ).toContain( `purpose${ purpose }${ suffix }` );
			}
		}
	} );

	test( 'distinctive values differ from the defaults', () => {
		const base = payloadFromSchema();
		for ( const key of SCHEMA_KEYS ) {
			expect( distinctiveValue( key ), key ).not.toBe( base[ key ] );
		}
	} );
} );

describe( 'every schema field changes the library options', () => {
	let api;
	let baseline;
	beforeAll( async () => {
		api = await loadShim( undefined );
		baseline = JSON.stringify( api.buildOptions( payloadFromSchema() ) );
	} );

	test.each( SCHEMA_KEYS.filter( ( key ) => ! CONSUMED_BY_PHP[ key ] ) )( '%s', ( key ) => {
		const warn = vi.spyOn( console, 'warn' ).mockImplementation( () => {} );
		const options = api.buildOptions( payloadFromSchema( { [ key ]: distinctiveValue( key ) } ) );
		expect( warn, `${ key }: the distinctive value was rejected as an invalid selector` ).not.toHaveBeenCalled();
		warn.mockRestore();
		expect( JSON.stringify( options ), `${ key } is stored but never reaches the library options` ).not.toBe( baseline );
	} );

	test( 'the PHP-consumed exclusion list only names real fields', () => {
		for ( const key of Object.keys( CONSUMED_BY_PHP ) ) {
			expect( SCHEMA, key ).toHaveProperty( key );
		}
	} );
} );

describe( 'field values land where the library expects them', () => {
	let api;
	beforeAll( async () => {
		api = await loadShim( undefined );
	} );

	const PURPOSES = {
		External: 'external',
		Document: 'document',
		Download: 'download',
		App: 'app',
		Mail: 'mail',
		Tel: 'tel',
		NewWindow: 'newWindow',
	};

	test.each( Object.entries( PURPOSES ) )( 'purpose%s → purposes.%s', ( suffix, id ) => {
		const prefix = `purpose${ suffix }`;
		const settings = payloadFromSchema( {
			[ `${ prefix }IconType` ]: 'classes',
			[ `${ prefix }IconClasses` ]: 'fa-x, fa-y',
			[ `${ prefix }IconPosition` ]: 'afterend',
			[ `${ prefix }Message` ]: `Custom ${ id }`,
			[ `${ prefix }Class` ]: `cls-${ id }`,
			[ `${ prefix }IconWrapperClass` ]: `wrap-${ id }`,
		} );
		const purpose = api.buildOptions( settings ).purposes[ id ];
		expect( purpose.iconType ).toBe( 'classes' );
		expect( purpose.iconClasses ).toEqual( [ 'fa-x', 'fa-y' ] );
		expect( purpose.iconPosition ).toBe( 'afterend' );
		expect( purpose.message ).toBe( `Custom ${ id }` );
		expect( purpose.linkClass ).toBe( `cls-${ id }` );
		expect( purpose.iconWrapperClass ).toBe( `wrap-${ id }` );
		expect( purpose.selector ).not.toBe( false );

		const off = api.buildOptions( payloadFromSchema( { [ prefix ]: false } ) ).purposes[ id ];
		expect( off.selector ).toBe( false );
	} );

	test.each( [
		[ 'purposeExternalSelector', 'external', 'additionalSelector' ],
		[ 'purposeDocumentSelector', 'document', 'selector' ],
		[ 'purposeDownloadSelector', 'download', 'selector' ],
		[ 'purposeAppSelector', 'app', 'selector' ],
	] )( '%s → purposes.%s.%s', ( key, id, prop ) => {
		const options = api.buildOptions( payloadFromSchema( { [ key ]: '.custom-selector a' } ) );
		expect( options.purposes[ id ][ prop ] ).toBe( '.custom-selector a' );
	} );

	test.each( [
		[ 'roots', 'roots', '.lp-roots' ],
		[ 'hideIcon', 'hideIcon', '.lp-hide' ],
		[ 'shadowComponents', 'shadowComponents', 'lp-widget' ],
		[ 'suppressNoBreak', 'suppressNoBreak', '.lp-nobreak' ],
		[ 'insertIconOutsideHiddenSpan', 'insertIconOutsideHiddenSpan', '.lp-sr' ],
		[ 'noRunIfPresent', 'noRunIfPresent', '.lp-present' ],
		[ 'noRunIfAbsent', 'noRunIfAbsent', '.lp-absent' ],
		[ 'domain', 'domain', 'lp.example, https://test.example' ],
	] )( '%s → %s', ( key, prop, value ) => {
		expect( api.buildOptions( payloadFromSchema( { [ key ]: value } ) )[ prop ] ).toBe( value );
	} );

	test( 'ignore is appended with the admin-bar rule', () => {
		expect( api.buildOptions( payloadFromSchema( { ignore: '.lp-ignore a' } ) ).ignore ).toBe( '.lp-ignore a, #wpadminbar a' );
	} );

	test.each( [
		[ 'noIconOnImages', 'noIconOnImages' ],
		[ 'purposeExternalNoReferrer', 'noReferrer' ],
	] )( '%s → %s', ( key, prop ) => {
		expect( api.buildOptions( payloadFromSchema( { [ key ]: true } ) )[ prop ] ).toBe( true );
		expect( api.buildOptions( payloadFromSchema( { [ key ]: false } ) )[ prop ] ).toBe( false );
	} );

	test( 'purposeExternalNewWindow → purposes.external.newWindow', () => {
		expect( api.buildOptions( payloadFromSchema( { purposeExternalNewWindow: true } ) ).purposes.external.newWindow ).toBe( true );
		expect( api.buildOptions( payloadFromSchema( { purposeExternalNewWindow: false } ) ).purposes.external.newWindow ).toBe( false );
	} );
} );
