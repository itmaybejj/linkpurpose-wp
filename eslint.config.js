/**
 * ESLint flat config for the plugin's hand-written JS (assets/js/) and the
 * Vitest unit tests. The vendored library bundle in assets/lib/ comes from
 * upstream itmaybejj/linkpurpose and is intentionally excluded.
 */

import js from '@eslint/js';
import globals from 'globals';

export default [
	{
		ignores: [ 'assets/lib/**', 'build/**', 'vendor/**', 'node_modules/**' ],
	},
	js.configs.recommended,
	{
		// Classic (non-module) browser scripts. `LinkPurpose` is the library's
		// global class; `linkpurposeSettings` is the inline JSON printed by PHP.
		files: [ 'assets/js/*.js' ],
		languageOptions: {
			ecmaVersion: 2022,
			sourceType: 'script',
			globals: {
				...globals.browser,
				LinkPurpose: 'readonly',
			},
		},
		rules: {
			'no-console': 'off',
			'no-unused-vars': [ 'warn', { args: 'none', caughtErrorsIgnorePattern: '^_' } ],
		},
	},
	{
		files: [ 'tests/js/**/*.js' ],
		languageOptions: {
			ecmaVersion: 2022,
			sourceType: 'module',
			globals: {
				...globals.browser,
				...globals.vitest,
			},
		},
		rules: {
			'no-console': 'off',
		},
	},
	{
		files: [ '*.config.js' ],
		languageOptions: {
			ecmaVersion: 2022,
			sourceType: 'module',
			globals: {
				...globals.node,
			},
		},
	},
];
