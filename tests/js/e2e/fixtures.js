/**
 * Playwright fixture: per-test WP debug.log capture.
 *
 * Any PHP Deprecated / Warning / Notice / Fatal written to wp-content/debug.log
 * while a test runs fails that test, with the lines attached. No-op when
 * WP_DEBUG_LOG is off (the file is never written).
 *
 * Use `import { test, expect } from './fixtures.js'` in specs.
 */

import { test as base, expect } from '@playwright/test';
import { writeFileSync, readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const here = dirname( fileURLToPath( import.meta.url ) );
// plugin dir → wp-content/debug.log
const HOST_LOG = join( here, '..', '..', '..', '..', '..', 'debug.log' );
// Mutagen needs a moment to sync container writes to the host.
const SYNC_WAIT_MS = 300;

const PHP_ISSUE_LINE = /^\[[^\]]+\]\s+PHP\s+(Deprecated|Warning|Notice|Fatal|Parse|Recoverable)/i;

function truncateDebugLog() {
	try {
		writeFileSync( HOST_LOG, '' );
	} catch ( _e ) {
		// Not created yet; fine.
	}
}

async function readDebugLog() {
	await new Promise( ( resolve ) => setTimeout( resolve, SYNC_WAIT_MS ) );
	try {
		return readFileSync( HOST_LOG, 'utf8' );
	} catch ( _e ) {
		return '';
	}
}

export const test = base.extend( {
	page: async ( { page }, use ) => {
		truncateDebugLog();
		await use( page );
		const issues = ( await readDebugLog() ).split( '\n' ).filter( ( line ) => PHP_ISSUE_LINE.test( line ) );
		if ( issues.length > 0 ) {
			throw new Error( `WP debug.log captured ${ issues.length } PHP issue(s) during this test:\n` + issues.map( ( m ) => '  ' + m ).join( '\n' ) );
		}
	},
} );

export { expect };
