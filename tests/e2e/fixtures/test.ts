import { execFileSync } from 'child_process';
import path from 'path';

import { expect, type Page, test as base } from '@playwright/test';

import { storageState } from './users';

const E2E_DIR = path.join( __dirname, '..' );

/**
 * Runs WP-CLI in the wp-env site and returns its output.
 *
 * @param args WP-CLI arguments, e.g. wp( 'option', 'update', 'my_option', 'yes' ).
 */
export function wp( ...args: string[] ): string {
	return execFileSync( path.join( E2E_DIR, 'node_modules', '.bin', 'wp-env' ), [ 'run', 'cli', 'wp', ...args ], {
		cwd: E2E_DIR,
		encoding: 'utf8',
		stdio: [ 'ignore', 'pipe', 'pipe' ],
	} ).trim();
}

export const test = base.extend< { customerPage: Page } >( {
	customerPage: async ( { browser, baseURL }, use ) => {
		const context = await browser.newContext( { baseURL, storageState: storageState( 'customer' ) } );
		await use( await context.newPage() );
		await context.close();
	},
} );

export { expect };
