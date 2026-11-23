import { expect, test as setup } from '@playwright/test';

import { type Role, storageState, users } from './users';

for ( const role of Object.keys( users ) as Role[] ) {
	setup( `log in as ${ role }`, async ( { page } ) => {
		await page.goto( '/wp-login.php' );
		// wp-login.php focuses the username field on a timer; typing before it lands can switch fields mid-fill.
		await expect( page.locator( '#user_login' ) ).toBeFocused();
		await page.locator( '#user_login' ).fill( users[ role ].username );
		await page.locator( '#user_pass' ).fill( users[ role ].password );
		await expect( page.locator( '#user_pass' ) ).toHaveValue( users[ role ].password );
		await page.locator( '#wp-submit' ).click();
		await expect( page ).not.toHaveURL( /wp-login\.php/ );
		await page.context().storageState( { path: storageState( role ) } );
	} );
}
