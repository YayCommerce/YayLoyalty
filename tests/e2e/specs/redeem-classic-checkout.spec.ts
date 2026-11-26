import { balanceOf, emptyCart, setBalance, shortcodePage, userId } from '../fixtures/loyalty';
import { expect, test, wp } from '../fixtures/test';

test.describe( 'Redeem points at the classic checkout', { tag: '@flow-redeem-classic-checkout' }, () => {
	let customer: number;
	let product: string;

	test.beforeAll( () => {
		customer = userId( 'customer' );
		product = wp( 'post', 'list', '--post_type=product', '--name=e2e-simple-product', '--field=ID' );
		shortcodePage( 'e2e-classic-cart', 'woocommerce_cart' );
		shortcodePage( 'e2e-classic-checkout', 'woocommerce_checkout' );
	} );

	test.beforeEach( () => {
		setBalance( customer, 500 );
		emptyCart( customer );
	} );

	test.afterAll( () => {
		setBalance( customer, 0 );
	} );

	test( 'the points discount is drawn in the classic totals and charged at checkout', async ( { customerPage: page } ) => {
		await page.goto( `/?add-to-cart=${ product }` );
		await page.goto( '/e2e-classic-cart/' );

		const card = page.locator( '.yayloyalty-redeem-row' );
		await card.getByRole( 'checkbox', { name: /Points discount/ } ).check();
		await card.getByRole( 'button', { name: 'Add rewards' } ).click();
		await expect( page.locator( '.cart_totals tr.cart-discount' ) ).toContainText( 'Points discount' );
		await expect( page.locator( '.cart_totals .order-total' ) ).toContainText( '$5.00' );

		await page.goto( '/e2e-classic-checkout/' );
		await expect( page.locator( '#order_review tr.cart-discount' ) ).toContainText( 'Points discount' );
		await expect( page.locator( '#order_review .order-total' ) ).toContainText( '$5.00' );
		await page.getByRole( 'button', { name: 'Place order' } ).click();
		await expect( page ).toHaveURL( /order-received/ );

		const order = new URL( page.url() ).pathname.split( '/' ).filter( Boolean ).pop() as string;
		expect( wp( 'wc', 'shop_order', 'get', order, '--field=discount_total', '--user=admin' ) ).toBe( '5.00' );
		expect( balanceOf( customer ) ).toBe( 0 );
	} );
} );
