import { fileURLToPath } from 'node:url';
import { defineConfig } from 'vitest/config';

// JS unit tests: run with yayh test run js. Use environment 'jsdom' (and add jsdom) for component tests.
export default defineConfig( {
	resolve: {
		alias: { '@': fileURLToPath( new URL( '../../apps/admin/src', import.meta.url ) ) },
	},
	test: {
		root: fileURLToPath( new URL( '../../', import.meta.url ) ),
		include: [ 'tests/js/**/*.test.{js,jsx,ts,tsx}' ],
		environment: 'node',
	},
} );
