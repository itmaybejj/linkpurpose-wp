/**
 * Shared helpers for the init-shim tests.
 *
 * `schema.json` is generated from the PHP schema (`composer schema-fixture`)
 * and checked against it by PHPUnit, so every field PHP knows about is
 * visible to these tests without hand-maintaining a list.
 */
import schema from '../fixtures/schema.json';

export const SHIM = '../../../assets/js/linkpurpose-wp.js';
export const SCHEMA = schema;
export const SCHEMA_KEYS = Object.keys( schema );

/**
 * The payload PHP's ConfigBuilder would print for a site that has never
 * saved anything: checkboxes as booleans, blank messages resolved to their
 * translated defaults, everything else as the stored string.
 */
export function payloadFromSchema( overrides = {} ) {
	const payload = {};
	for ( const [ key, def ] of Object.entries( schema ) ) {
		if ( 'checkbox' === def.type ) {
			payload[ key ] = '1' === def.default;
		} else if ( def.messageDefault && '' === def.default ) {
			payload[ key ] = def.messageDefault;
		} else {
			payload[ key ] = def.default;
		}
	}
	payload.domain = 'https://test.example';
	payload.shadowCSS = 'https://test.example/wp-content/plugins/linkpurpose-wp/assets/lib/css/linkpurpose.css';
	payload.version = '0.0.0-test';
	return { ...payload, ...overrides };
}

/**
 * A value for `key` that differs from its default, survives the PHP
 * sanitizer for its format, and is a valid selector/class where one is
 * required (so the shim's fallbacks do not swallow it).
 */
export function distinctiveValue( key ) {
	const def = schema[ key ];
	switch ( def.type ) {
		case 'checkbox':
			return '1' !== def.default;
		case 'select':
			return def.options.find( ( option ) => option !== def.default );
		default:
			break;
	}
	switch ( def.format ) {
		case 'selector':
			return `.lp-${ key }`;
		case 'domain':
			return `lp-${ key.toLowerCase() }.example`;
		case 'css_class':
			return `lp-${ key }`;
		case 'css_classes':
			return `lp-${ key }-a, lp-${ key }-b`;
		default:
			return `Hint ${ key }`;
	}
}

/**
 * Load the shim fresh with the given settings; returns window.linkPurposeWP.
 */
export async function loadShim( settings, { withLibrary = true } = {} ) {
	vi.resetModules();
	delete window.linkPurpose;
	delete window.linkPurposeWP;
	delete window.linkPurposeOptions;
	if ( withLibrary ) {
		globalThis.LinkPurpose = vi.fn();
	} else {
		delete globalThis.LinkPurpose;
	}
	window.linkpurposeSettings = settings;
	await import( SHIM );
	return window.linkPurposeWP;
}
