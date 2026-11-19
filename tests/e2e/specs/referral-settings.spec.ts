import { expect, test, wp } from '../fixtures/test';

test.describe( 'Referral settings', { tag: [ '@P-3', '@flow-referral-settings' ] }, () => {
	test.afterEach( () => {
		wp( 'option', 'delete', 'yayloyalty_referral_settings' );
	} );

	test( 'saved values survive a reload and the preview shows the offer', async ( { page } ) => {
		await page.goto( '/wp-admin/admin.php?page=yayloyalty#/referral/settings' );

		await page.getByLabel( 'Program live' ).click();
		await page.getByLabel( 'Discount on their first order' ).fill( '10' );
		await page.getByLabel( 'Minimum order' ).fill( '50' );
		await page.getByLabel( 'Coupon expires' ).fill( '30' );
		await page.getByLabel( 'What the advocate gets' ).fill( '1000' );
		await page.getByLabel( 'Hold for' ).fill( '14' );
		await page.getByRole( 'button', { name: 'Save changes' } ).click();
		await expect( page.getByText( 'Referral settings saved' ) ).toBeVisible();

		await page.reload();

		await expect( page.getByLabel( 'Minimum order' ) ).toHaveValue( /^50/ );
		await expect( page.getByLabel( 'Program live' ) ).toBeChecked();
		await expect( page.getByText( 'Give $10.00, get 1,000 points' ) ).toBeVisible();
		expect( JSON.parse( wp( 'option', 'get', 'yayloyalty_referral_settings', '--format=json' ) ) ).toMatchObject( {
			enabled: true,
			min_order: '50',
			advocate_points: 1000,
			hold_days: 14,
		} );
	} );

	test( 'a percentage over 100 is refused and nothing is saved', async ( { page } ) => {
		await page.goto( '/wp-admin/admin.php?page=yayloyalty#/referral/settings' );

		await page.getByLabel( 'Percentage' ).click();
		await page.getByLabel( 'Discount on their first order' ).fill( '150' );
		await page.getByLabel( 'Discount on their first order' ).blur();
		await page.getByRole( 'button', { name: 'Save changes' } ).click();

		await expect( page.getByText( 'Percentage must be 1 to 100.' ) ).toBeVisible();
		await expect( page.getByText( 'Referral settings saved' ) ).toHaveCount( 0 );
	} );
} );
