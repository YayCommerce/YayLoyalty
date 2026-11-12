import { balanceOf, setBalance, userId } from '../fixtures/loyalty';
import { expect, test } from '../fixtures/test';

test.describe( 'Adjust a customer’s points', { tag: '@flow-customer-adjust-points' }, () => {
	let customer: number;

	test.beforeAll( () => {
		customer = userId( 'customer' );
	} );

	test.beforeEach( () => {
		setBalance( customer, 100 );
	} );

	test.afterAll( () => {
		setBalance( customer, 0 );
	} );

	test( 'an added amount reaches the balance and the drawer', async ( { page } ) => {
		await page.goto( `/wp-admin/admin.php?page=yayloyalty#/customers/${ customer }` );
		await page.getByRole( 'button', { name: 'Adjust points' } ).click();

		const dialog = page.getByRole( 'dialog', { name: 'Adjust points' } );
		await dialog.getByRole( 'textbox', { name: 'Amount' } ).fill( '25' );
		await dialog.getByLabel( 'Reason (internal)' ).fill( 'Goodwill for a late parcel' );
		await dialog.getByRole( 'button', { name: 'Add 25 points' } ).click();

		await expect( page.getByText( 'Adjusted points for Jane Smith.' ) ).toBeVisible();
		await expect( dialog ).toBeHidden();
		expect( balanceOf( customer ) ).toBe( 125 );
	} );

	test( 'nothing can be submitted until there is an amount', async ( { page } ) => {
		await page.goto( `/wp-admin/admin.php?page=yayloyalty#/customers/${ customer }` );
		await page.getByRole( 'button', { name: 'Adjust points' } ).click();

		const dialog = page.getByRole( 'dialog', { name: 'Adjust points' } );
		await dialog.getByLabel( 'Reason (internal)' ).fill( 'Correction' );

		await expect( dialog.getByRole( 'button', { name: 'Adjust points' } ) ).toBeDisabled();
		expect( balanceOf( customer ) ).toBe( 100 );
	} );
} );
