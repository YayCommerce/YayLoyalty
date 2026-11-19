import { balanceOf, setBalance, userId } from '../fixtures/loyalty';
import { expect, test, wp } from '../fixtures/test';

function emailOf( login: string ): string {
	return wp( 'user', 'get', login, '--field=user_email' );
}

test.describe( 'Tools import', { tag: [ '@flow-tools-import', '@P-8' ] }, () => {
	let customer: number;
	let admin: number;

	test.beforeAll( () => {
		customer = userId( 'customer' );
		admin = userId( 'admin' );
	} );

	test.beforeEach( () => {
		setBalance( customer, 0 );
		setBalance( admin, 0 );
	} );

	test.afterAll( () => {
		setBalance( customer, 0 );
		setBalance( admin, 0 );
	} );

	test( 'previews errors, applies the ready rows, and a re-upload applies nothing', async ( { page } ) => {
		// A note unique to this run gives the file its own import id, so an earlier run's rows don't count as imported.
		const csv = [
			'email,points,note',
			`${ emailOf( 'customer' ) },100,e2e ${ Date.now() }`,
			`${ emailOf( 'admin' ) },40,`,
			'nobody@example.invalid,10,',
		].join( '\n' );
		const file = { name: 'old-plugin.csv', mimeType: 'text/csv', buffer: Buffer.from( csv ) };

		await page.goto( '/wp-admin/admin.php?page=yayloyalty#/tools' );
		await page.getByLabel( 'Choose CSV file' ).setInputFiles( file );

		await expect( page.getByText( '2 ready · +140 points' ) ).toBeVisible();
		await expect( page.getByText( 'No customer with this email' ) ).toBeVisible();

		await page.getByRole( 'button', { name: 'Import 2 rows' } ).click();
		await page.getByRole( 'dialog' ).getByRole( 'button', { name: 'Import 2 rows' } ).click();

		await expect( page.getByText( 'Imported 2 rows (+140 points).' ) ).toBeVisible();
		expect( balanceOf( customer ) ).toBe( 100 );
		expect( balanceOf( admin ) ).toBe( 40 );

		await page.getByRole( 'button', { name: 'Done' } ).click();
		await page.getByLabel( 'Choose CSV file' ).setInputFiles( file );

		await expect( page.getByText( '0 ready · 0 points' ) ).toBeVisible();
		await expect( page.getByText( '2 already imported' ) ).toBeVisible();
		await expect( page.getByRole( 'button', { name: 'Import 0 rows' } ) ).toBeDisabled();
		expect( balanceOf( customer ) ).toBe( 100 );
	} );
} );
