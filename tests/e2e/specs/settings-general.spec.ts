import { expect, test, wp } from '../fixtures/test';

test.describe( 'General settings', { tag: '@flow-settings-general' }, () => {
	test.afterEach( () => {
		wp( 'option', 'delete', 'yayloyalty_settings' );
	} );

	test( 'renamed points keep their names after a reload', async ( { page } ) => {
		await page.goto( '/wp-admin/admin.php?page=yayloyalty#/settings/general' );

		await page.getByLabel( 'Singular' ).fill( 'Star' );
		await page.getByLabel( 'Plural' ).fill( 'Stars' );
		await page.getByRole( 'button', { name: 'Save changes' } ).click();
		await expect( page.getByText( 'General settings saved.' ) ).toBeVisible();

		await page.reload();

		await expect( page.getByLabel( 'Singular' ) ).toHaveValue( 'Star' );
		await expect( page.getByLabel( 'Plural' ) ).toHaveValue( 'Stars' );
		expect( JSON.parse( wp( 'option', 'get', 'yayloyalty_settings', '--format=json' ) ) ).toMatchObject( {
			points_label_singular: 'Star',
			points_label_plural: 'Stars',
		} );
	} );

	test( 'an empty name is refused and nothing is saved', async ( { page } ) => {
		await page.goto( '/wp-admin/admin.php?page=yayloyalty#/settings/general' );

		await page.getByLabel( 'Plural' ).fill( '' );
		await page.getByRole( 'button', { name: 'Save changes' } ).click();

		await expect( page.getByText( 'Enter a name of 1 to 30 characters.' ) ).toBeVisible();
		await expect( page.getByText( 'General settings saved.' ) ).toHaveCount( 0 );
	} );
} );
