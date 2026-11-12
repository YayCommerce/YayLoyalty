import { balanceOf, emptyCart, setBalance, userId } from '../fixtures/loyalty';
import { expect, test, wp } from '../fixtures/test';

test.describe( 'Redeem points at the block checkout', { tag: '@flow-redeem-block-checkout' }, () => {
	let customer: number;
	let product: string;

	test.beforeAll( () => {
		customer = userId( 'customer' );
		product = wp( 'post', 'list', '--post_type=product', '--name=e2e-simple-product', '--field=ID' );
	} );

	test.beforeEach( () => {
		setBalance( customer, 500 );
		emptyCart( customer );
	} );

	test.afterAll( () => {
		setBalance( customer, 0 );
	} );

	test( 'the points discount comes off the order and out of the balance', async ( { customerPage: page } ) => {
		await page.goto( `/?add-to-cart=${ product }` );
		await page.goto( '/cart/' );

		const card = page.locator( '[data-yl-redeem]' );
		await expect( card.locator( '.yayloyalty-redeem__balance' ) ).toContainText( '500' );
		await card.getByRole( 'checkbox', { name: /Points discount/ } ).check();

		await expect( card.getByText( '500 points = $5 off' ) ).toBeVisible();
		await expect( page.locator( '.wc-block-components-totals-footer-item' ) ).toContainText( '$5.00' );

		await page.goto( '/checkout/' );
		await expect( page.locator( '.wc-block-components-totals-footer-item' ) ).toContainText( '$5.00' );
		await page.getByRole( 'button', { name: 'Place Order' } ).click();
		await expect( page ).toHaveURL( /order-received/ );

		const order = new URL( page.url() ).pathname.split( '/' ).filter( Boolean ).pop() as string;
		expect( wp( 'wc', 'shop_order', 'get', order, '--field=discount_total', '--user=admin' ) ).toBe( '5.00' );
		expect( balanceOf( customer ) ).toBe( 0 );
	} );

	test( 'a balance below the minimum cannot be ticked', async ( { customerPage: page } ) => {
		setBalance( customer, 50 );
		await page.goto( `/?add-to-cart=${ product }` );
		await page.goto( '/cart/' );

		await expect( page.locator( '[data-yl-redeem]' ).getByRole( 'checkbox', { name: /Points discount/ } ) ).toBeDisabled();
		await expect( page.locator( '.wc-block-components-totals-footer-item' ) ).toContainText( '$10.00' );
	} );
} );
