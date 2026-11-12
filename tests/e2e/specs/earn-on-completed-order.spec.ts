import { pendingOf, setBalance, userId } from '../fixtures/loyalty';
import { expect, test, wp } from '../fixtures/test';

test.describe( 'Earn points on a completed order', { tag: '@flow-earn-on-completed-order' }, () => {
	let customer: number;
	let order: string;
	let pendingBefore: number;

	test.beforeAll( () => {
		customer = userId( 'customer' );
		setBalance( customer, 0 );

		pendingBefore = pendingOf( customer );
		const product = wp( 'post', 'list', '--post_type=product', '--name=e2e-simple-product', '--field=ID' );
		order = wp(
			'wc', 'shop_order', 'create', '--user=admin', '--porcelain', '--status=on-hold', `--customer_id=${ customer }`,
			`--line_items=[{"product_id":${ product },"quantity":1}]`
		);
	} );

	test.afterAll( () => {
		wp( 'wc', 'shop_order', 'delete', order, '--force=true', '--user=admin' );
		setBalance( customer, 0 );
	} );

	test( 'an on-hold order shows as pending, and completing it adds the points', async ( { customerPage: page } ) => {
		const content = page.locator( '.woocommerce-MyAccount-content' );

		await page.goto( '/my-account/my-points/' );
		await expect( content.locator( '.yayloyalty-account__pending' ) ).toContainText( `+${ pendingBefore + 10 } points pending` );
		await expect( content.locator( '.yayloyalty-account__balance' ) ).toHaveText( '0' );

		wp( 'wc', 'shop_order', 'update', order, '--status=completed', '--user=admin' );
		await page.reload();

		await expect( content.locator( '.yayloyalty-account__balance' ) ).toHaveText( '10' );
		expect( pendingOf( customer ) ).toBe( pendingBefore );
		await expect( content.locator( '.yayloyalty-account__table' ) ).toContainText( /points earned from an order/i );
		await expect( content.getByRole( 'link', { name: `Order #${ order }` } ) ).toBeVisible();
	} );
} );
