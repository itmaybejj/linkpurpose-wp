/**
 * Vitest config for the plugin's JS unit tests (tests/js/unit/**\/*.test.js).
 *
 * The init shim (assets/js/linkpurpose-wp.js) is a classic browser script,
 * not an ES module: it reads `window.linkpurposeSettings` and constructs the
 * global `LinkPurpose` class the vendored library defines. Tests stub both
 * globals on jsdom's window, then load the shim through `import()` so each
 * test gets a fresh execution (`vi.resetModules()`).
 */
import { defineConfig } from 'vitest/config';

export default defineConfig( {
	test: {
		globals: true,
		environment: 'jsdom',
		environmentOptions: {
			jsdom: { url: 'https://test.example/' },
		},
		include: [ 'tests/js/unit/**/*.test.js' ],
		exclude: [ '**/node_modules/**', '**/vendor/**', '**/build/**' ],
		clearMocks: true,
	},
} );
