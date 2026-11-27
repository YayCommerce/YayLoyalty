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
		await card.getByRole( 'button', { name: 'Add rewards' } ).click();
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

/** Runs PHP in the site as the admin and returns what it echoes. */
function asAdmin( code: string ): string {
	return wp( 'eval', '--user=admin', code );
}

/** A Pro reward created through the admin REST route, as the reward editor saves it; its id. */
function createReward( name: string, type: string, cost: number, config: Record< string, unknown > ): string {
	const id = asAdmin(
		`$r = new WP_REST_Request( 'POST', '/yayloyalty/v1/rewards' );` +
			`$r->set_header( 'Content-Type', 'application/json' );` +
			`$r->set_body( ${ JSON.stringify( JSON.stringify( { name, type, cost_points: cost, config, status: 'active' } ) ) } );` +
			`$res = rest_do_request( $r ); echo $res->get_status() < 300 ? $res->get_data()['id'] : '';`
	);

	if ( ! id ) {
		throw new Error( `Creating the ${ type } reward failed.` );
	}

	return id;
}

test.describe( 'Free shipping and free product rewards at the block checkout', { tag: [ '@P-10', '@flow-redeem-block-checkout' ] }, () => {
	let customer: number;
	let shirt: string;
	let method: string;
	const rewards: string[] = [];
	// A claimed reward can only be archived, never deleted, so each run names its own.
	const run = Date.now();
	const shippingName = `E2E free shipping ${ run }`;
	const shirtName = `E2E free shirt ${ run }`;

	test.beforeAll( () => {
		customer = userId( 'customer' );
		shirt = wp( 'wc', 'product', 'create', '--name=E2E P10 shirt', '--regular_price=15', '--user=admin', '--porcelain' );
		method = asAdmin(
			`$id = WC_Shipping_Zones::get_zone( 0 )->add_shipping_method( 'flat_rate' );` +
				`update_option( 'woocommerce_flat_rate_' . $id . '_settings', [ 'title' => 'Flat rate', 'tax_status' => 'none', 'cost' => '7' ] );` +
				'WC_Cache_Helper::get_transient_version( "shipping", true ); echo $id;'
		);
		wp( 'wc', 'customer', 'update', String( customer ), '--user=admin', '--shipping={"first_name":"Jane","last_name":"Smith","address_1":"1 Test Street","city":"San Francisco","state":"CA","postcode":"94110","country":"US"}' );
		rewards.push( createReward( shippingName, 'free_shipping', 100, { currency: 'USD' } ) );
		rewards.push( createReward( shirtName, 'free_product', 200, { product_id: Number( shirt ), qty: 1 } ) );
	} );

	test.beforeEach( () => {
		setBalance( customer, 500 );
		emptyCart( customer );
	} );

	test.afterAll( () => {
		for ( const id of rewards ) {
			asAdmin(
				`if ( rest_do_request( new WP_REST_Request( 'DELETE', '/yayloyalty/v1/rewards/${ id }' ) )->is_error() ) {` +
					`$r = new WP_REST_Request( 'PATCH', '/yayloyalty/v1/rewards/${ id }' ); $r->set_body_params( [ 'status' => 'archived' ] ); rest_do_request( $r ); }`
			);
		}

		asAdmin( `WC_Shipping_Zones::get_zone( 0 )->delete_shipping_method( ${ method } );` );
		wp( 'wc', 'product', 'delete', shirt, '--force=true', '--user=admin' );
		setBalance( customer, 0 );
	} );

	test( 'shipping drops to 0, the free shirt is added at 0, and both rewards are paid for in points', async ( { customerPage: page } ) => {
		await page.goto( `/?add-to-cart=${ shirt }` );
		await page.goto( '/cart/' );
		const card = page.locator( '[data-yl-redeem]' );
		const total = page.locator( '.wc-block-components-totals-footer-item' );
		await expect( total ).toContainText( '$22.00' );

		await card.getByRole( 'checkbox', { name: new RegExp( shippingName ) } ).check();
		await card.getByRole( 'button', { name: 'Add rewards' } ).click();
		await expect( total ).toContainText( '$15.00' );

		// Applied, the card collapses to its summary; the chevron opens it again.
		await card.getByRole( 'button', { name: 'Change' } ).click();
		await card.getByRole( 'checkbox', { name: new RegExp( shirtName ) } ).check();
		await card.getByRole( 'button', { name: 'Update rewards' } ).click();
		await expect( page.locator( '.wc-block-cart-items__row' ) ).toHaveCount( 2 );
		await expect( page.getByText( shirtName, { exact: true } ).first() ).toBeVisible();
		await expect( total ).toContainText( '$15.00' );

		await page.goto( '/checkout/' );
		await expect( page.locator( '.wc-block-components-totals-footer-item' ) ).toContainText( '$15.00' );
		await page.getByRole( 'button', { name: 'Place Order' } ).click();
		await expect( page ).toHaveURL( /order-received/ );

		const order = new URL( page.url() ).pathname.split( '/' ).filter( Boolean ).pop() as string;
		expect( wp( 'wc', 'shop_order', 'get', order, '--field=shipping_total', '--user=admin' ) ).toBe( '0.00' );
		expect( balanceOf( customer ) ).toBe( 200 );
	} );

	test( 'removing the free line from the block cart unticks its reward', async ( { customerPage: page } ) => {
		await page.goto( `/?add-to-cart=${ shirt }` );
		await page.goto( '/cart/' );
		const card = page.locator( '[data-yl-redeem]' );
		const reward = card.getByRole( 'checkbox', { name: new RegExp( shirtName ) } );
		const rows = page.locator( '.wc-block-cart-items__row' );

		await reward.check();
		await card.getByRole( 'button', { name: 'Add rewards' } ).click();
		await expect( rows ).toHaveCount( 2 );

		await rows.filter( { hasText: `Reward: ${ shirtName }` } ).getByRole( 'button', { name: /Remove .* from cart/ } ).click();

		await expect( rows ).toHaveCount( 1 );
		await expect( reward ).not.toBeChecked();
		await expect( page.getByRole( 'button', { name: `Remove coupon "${ shirtName }"` } ) ).toHaveCount( 0 );
		expect( balanceOf( customer ) ).toBe( 500 );
	} );
} );
