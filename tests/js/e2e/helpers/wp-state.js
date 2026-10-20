/**
 * Mutate WordPress state from Playwright specs through wp-cli.
 *
 * Everything shells out to `ddev wp …` (override the prefix with
 * WP_TEST_RUNNER, e.g. `lando wp`). Failures throw so a broken setup fails
 * loudly instead of surfacing as a confusing assertion later.
 */

import { execSync } from 'node:child_process';
import { readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const here = dirname( fileURLToPath( import.meta.url ) );
const WP_RUNNER = process.env.WP_TEST_RUNNER || 'ddev wp';

export const SITE_OPTION = 'linkpurpose_settings';
export const NETWORK_OPTION = 'linkpurpose_network_settings';
export const NETWORK_HISTORY_OPTION = 'linkpurpose_network_history';
export const WORKER_STATE_OPTION = 'linkpurpose_network_backfill_state';

/** The generated schema fixture: every field PHP defines. */
export const SCHEMA = JSON.parse( readFileSync( join( here, '..', '..', 'fixtures', 'schema.json' ), 'utf8' ) );

/** Run a wp-cli command; returns trimmed stdout. */
export function wp( command ) {
	return execSync( `${ WP_RUNNER } ${ command }`, { encoding: 'utf8', stdio: [ 'ignore', 'pipe', 'pipe' ] } ).trim();
}

/** Shell-quote a value for the wp-cli line. */
function quote( value ) {
	return `'${ String( value ).replace( /'/g, `'\\''` ) }'`;
}

export function isMultisite() {
	return '1' === wp( `eval 'echo is_multisite() ? "1" : "0";'` );
}

/** URL of a subsite other than the main site, or null on single-site. */
export function firstSubsiteUrl() {
	if ( ! isMultisite() ) {
		return null;
	}
	const rows = wp( 'site list --fields=blog_id,url --format=json' );
	const sites = JSON.parse( rows || '[]' ).filter( ( s ) => 1 !== Number( s.blog_id ) );
	return sites.length ? sites[ 0 ].url : null;
}

/** Replace the per-site option on the given site URL (default: main site). */
export function setSiteSettings( settings, url = '' ) {
	const target = url ? ` --url=${ quote( url ) }` : '';
	wp( `option update ${ SITE_OPTION } ${ quote( JSON.stringify( settings ) ) } --format=json${ target }` );
}

export function getSiteSettings( url = '' ) {
	const target = url ? ` --url=${ quote( url ) }` : '';
	try {
		return JSON.parse( wp( `option get ${ SITE_OPTION } --format=json${ target }` ) || '{}' );
	} catch ( _e ) {
		return {};
	}
}

export function deleteSiteSettings( url = '' ) {
	const target = url ? ` --url=${ quote( url ) }` : '';
	try {
		wp( `option delete ${ SITE_OPTION }${ target }` );
	} catch ( _e ) {
		// Already absent.
	}
}

/** Replace the network defaults blob ({values, modes}). */
export function setNetworkSettings( blob ) {
	wp( `site option update ${ NETWORK_OPTION } ${ quote( JSON.stringify( blob ) ) } --format=json` );
}

export function deleteNetworkSettings() {
	for ( const option of [ NETWORK_OPTION, NETWORK_HISTORY_OPTION, WORKER_STATE_OPTION ] ) {
		try {
			wp( `site option delete ${ option }` );
		} catch ( _e ) {
			// Already absent.
		}
	}
}

/** Publish a post with the given HTML; returns its permalink. Pair with deletePost(). */
export function createPost( title, html ) {
	const id = wp( `post create --post_type=post --post_status=publish --post_title=${ quote( title ) } --post_content=${ quote( html ) } --porcelain` );
	const url = wp( `eval 'echo get_permalink( ${ Number( id ) } );'` );
	return { id: Number( id ), url };
}

export function deletePost( id ) {
	try {
		wp( `post delete ${ Number( id ) } --force` );
	} catch ( _e ) {
		// Already gone.
	}
}

/**
 * Resolve with `window.linkpurposeSettings` and the live library options
 * once the shim has started the library.
 *
 * @param {import('@playwright/test').Page} page
 */
export async function readLinkPurposeState( page ) {
	const handle = await page.waitForFunction(
		() => {
			// The library is a top-level `class` declaration: a global
			// binding, not a window property, so test by identifier.
			/* global LinkPurpose */
			if ( ! window.linkpurposeSettings || ! window.linkPurpose || 'undefined' === typeof LinkPurpose || ! LinkPurpose.options ) {
				return null;
			}
			return {
				settings: window.linkpurposeSettings,
				options: JSON.parse( JSON.stringify( LinkPurpose.options, ( _k, v ) => ( v instanceof RegExp ? String( v ) : v ) ) ),
				version: LinkPurpose.version,
			};
		},
		null,
		{ timeout: 10_000, polling: 100 }
	);
	return handle.jsonValue();
}
