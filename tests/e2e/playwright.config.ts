import { defineConfig, devices } from '@playwright/test';

import { storageState } from './fixtures/users';

// yayh sets E2E_BASE_URL to this checkout's site (each worktree has its own port).
const baseURL = process.env.E2E_BASE_URL ?? process.env.WP_BASE_URL ?? 'http://localhost:9190';
const CI = !! process.env.CI;

export default defineConfig( {
	testDir: './specs',
	outputDir: './test-results',
	timeout: 60_000,
	expect: { timeout: 10_000 },
	workers: 1,
	retries: CI ? 1 : 0,
	forbidOnly: CI,
	reporter: CI
		? [ [ 'list' ], [ 'junit', { outputFile: 'test-results/junit.xml' } ], [ 'html', { open: 'never' } ] ]
		: [ [ 'list' ], [ 'html', { open: 'never' } ] ],
	use: {
		...devices[ 'Desktop Chrome' ],
		baseURL,
		trace: 'retain-on-failure',
		screenshot: 'only-on-failure',
		video: 'retain-on-failure',
	},
	projects: [
		{ name: 'setup', testDir: './fixtures', testMatch: /auth\.setup\.ts/ },
		{ name: 'e2e', dependencies: [ 'setup' ], use: { storageState: storageState( 'admin' ) } },
	],
} );
