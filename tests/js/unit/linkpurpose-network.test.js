/**
 * Unit tests for the network backfill heartbeat client.
 */
const SCRIPT = '../../../assets/js/linkpurpose-network.js';

function stubJQuery() {
	const handlers = {};
	const $doc = {
		on( eventName, handler ) {
			handlers[ eventName ] = handler;
			return $doc;
		},
		trigger: vi.fn(),
	};
	window.jQuery = vi.fn( () => $doc );
	return { handlers, $doc };
}

async function mount( html ) {
	document.body.innerHTML = html;
	const jq = stubJQuery();
	vi.resetModules();
	await import( SCRIPT );
	return jq;
}

afterEach( () => {
	delete window.jQuery;
} );

test( 'does nothing without the panel', async () => {
	const { handlers } = await mount( '<div id="other"></div>' );
	expect( Object.keys( handlers ) ).toEqual( [] );
} );

test( 'requests the panel only while running', async () => {
	const { handlers } = await mount( '<div id="linkpurpose-backfill-panel" data-running="1"></div>' );
	const data = {};
	handlers[ 'heartbeat-send' ]( {}, data );
	expect( data.linkpurpose_backfill_request ).toBe( 1 );

	document.getElementById( 'linkpurpose-backfill-panel' ).setAttribute( 'data-running', '0' );
	const idle = {};
	handlers[ 'heartbeat-send' ]( {}, idle );
	expect( idle ).toEqual( {} );
} );

test( 'swaps the panel, mirrors the running flag and rebinds notices', async () => {
	const { handlers, $doc } = await mount( '<div id="linkpurpose-backfill-panel" data-running="1">old</div>' );
	const panel = document.getElementById( 'linkpurpose-backfill-panel' );

	handlers[ 'heartbeat-tick' ]( {}, { unrelated: true } );
	expect( panel.innerHTML ).toBe( 'old' );
	expect( $doc.trigger ).not.toHaveBeenCalled();

	handlers[ 'heartbeat-tick' ]( {}, {
		linkpurpose_backfill_panel: '<p class="notice">done</p>',
		linkpurpose_backfill_running: false,
	} );
	expect( panel.innerHTML ).toBe( '<p class="notice">done</p>' );
	expect( panel.getAttribute( 'data-running' ) ).toBe( '0' );
	expect( $doc.trigger ).toHaveBeenCalledWith( 'wp-notice-added' );

	handlers[ 'heartbeat-tick' ]( {}, { linkpurpose_backfill_panel: '', linkpurpose_backfill_running: true } );
	expect( panel.getAttribute( 'data-running' ) ).toBe( '1' );
} );
