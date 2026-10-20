/**
 * Unit tests for the admin conditional-visibility script.
 */
const SCRIPT = '../../../assets/js/linkpurpose-admin.js';

function rule( conditions ) {
	return JSON.stringify( conditions ).replace( /"/g, '&quot;' );
}

async function mount( html ) {
	document.body.innerHTML = html;
	vi.resetModules();
	await import( SCRIPT );
}

function change( el ) {
	el.dispatchEvent( new Event( 'change', { bubbles: true } ) );
}

test( 'row follows a checkbox', async () => {
	await mount( `
		<table><tbody>
			<tr><td><input type="checkbox" name="s[on]" value="1" checked></td></tr>
			<tr id="dep" data-linkpurpose-when="${ rule( [ { input: 's[on]', value: '1' } ] ) }"><td>x</td></tr>
		</tbody></table>` );
	const box = document.querySelector( '[name="s[on]"]' );
	const dep = document.getElementById( 'dep' );
	expect( dep.hidden ).toBe( false );
	box.checked = false;
	change( box );
	expect( dep.hidden ).toBe( true );
	box.checked = true;
	change( box );
	expect( dep.hidden ).toBe( false );
} );

test( 'multiple conditions are AND-ed', async () => {
	await mount( `
		<input type="checkbox" name="s[on]" value="1" checked>
		<select name="s[type]"><option value="html" selected>a</option><option value="classes">b</option></select>
		<div id="dep" data-linkpurpose-when="${ rule( [ { input: 's[on]', value: '1' }, { input: 's[type]', value: 'classes' } ] ) }"></div>` );
	const dep = document.getElementById( 'dep' );
	const select = document.querySelector( '[name="s[type]"]' );
	expect( dep.hidden ).toBe( true );
	select.value = 'classes';
	change( select );
	expect( dep.hidden ).toBe( false );
	const box = document.querySelector( '[name="s[on]"]' );
	box.checked = false;
	change( box );
	expect( dep.hidden ).toBe( true );
} );

test( 'malformed or unmatched rules leave the row visible', async () => {
	await mount( `
		<div id="bad" data-linkpurpose-when="not json"></div>
		<div id="missing" data-linkpurpose-when="${ rule( [ { input: 'nope', value: '1' } ] ) }"></div>` );
	expect( document.getElementById( 'bad' ).hidden ).toBe( false );
	expect( document.getElementById( 'missing' ).hidden ).toBe( false );
} );
