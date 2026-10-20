/**
 * Playwright config for end-to-end tests against the local DDEV install.
 *
 * Target: the editoria11y-wp-multisite DDEV project (override with
 * PLAYWRIGHT_BASE_URL). Tests assume the stock DDEV admin account; auth
 * setup runs once and persists a storage state under .auth/ that the
 * other specs reuse.
 *
 * E2E covers what Vitest + jsdom cannot:
 *   - the real library bundle loads and marks links on a real page;
 *   - the settings form round-trips through options.php;
 *   - a network lock disables the subsite field and reaches its payload.
 *
 * NOT part of scripts/test.sh — it needs DDEV up and a Chromium download.
 * Run `composer test-e2e` (or `npm run test:e2e`) on demand.
 */

import { defineConfig, devices } from '@playwright/test';

export default defineConfig( {
	testDir: './tests/js/e2e',
	timeout: 30_000,
	expect: { timeout: 5_000 },
	// Specs mutate the shared option rows through wp-cli; run serially.
	fullyParallel: false,
	workers: 1,
	forbidOnly: !! process.env.CI,
	retries: process.env.CI ? 2 : 0,
	reporter: [
		[ 'list' ],
		[ 'html', { outputFolder: 'playwright-report', open: 'never' } ],
	],
	use: {
		baseURL: process.env.PLAYWRIGHT_BASE_URL || 'https://editoria11y-wp-multisite.ddev.site',
		trace: 'on-first-retry',
		// DDEV's self-signed certificate.
		ignoreHTTPSErrors: true,
	},
	projects: [
		{
			name: 'setup',
			testMatch: /.*\.setup\.js/,
		},
		{
			name: 'chromium',
			use: {
				...devices[ 'Desktop Chrome' ],
				storageState: '.auth/admin.json',
			},
			dependencies: [ 'setup' ],
		},
	],
} );
