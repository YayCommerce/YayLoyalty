import { expect, test as setup } from '@playwright/test';

import { type Role, storageState, users } from './users';

for ( const role of Object.keys( users ) as Role[] ) {
	setup( `log in as ${ role }`, async ( { page } ) => {
		await page.goto( '/wp-login.php' );
		await page.locator( '#user_login' ).fill( users[ role ].username );
		await page.locator( '#user_pass' ).fill( users[ role ].password );
		await page.locator( '#wp-submit' ).click();
		await expect( page ).not.toHaveURL( /wp-login\.php/ );
		await page.context().storageState( { path: storageState( role ) } );
	} );
}
