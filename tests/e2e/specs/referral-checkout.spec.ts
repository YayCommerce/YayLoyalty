import { type Browser, type Page } from '@playwright/test';

import { shortcodePage, userId } from '../fixtures/loyalty';
import { expect, test, wp } from '../fixtures/test';

const PASSWORD = 'password';

/** A new customer with a pending referral from the `customer` user: $10 off a $50 first order. Every test gets its own friend, so no earlier order counts. */
function referredFriend( advocate: number ): string {
	const login = `e2e-friend-${ Date.now() }-${ Math.floor( Math.random() * 1000 ) }`;
	const friend = wp( 'user', 'create', login, `${ login }@example.test`, '--role=customer', `--user_pass=${ PASSWORD }`, '--porcelain' );

	wp(
		'eval',
		`( new YayLoyalty\\Pro\\Repositories\\ReferralRepository() )->insert_pending( ${ advocate }, ${ friend }, 'E2EREF', [ 'discount_type' => 'fixed', 'discount_amount' => '10', 'min_order' => '50', 'advocate_points' => 1000, 'hold_days' => 14, 'exclude_other_coupons' => true ], gmdate( 'Y-m-d H:i:s', time() + 30 * DAY_IN_SECONDS ) );`
	);

	return login;
}

async function logIn( browser: Browser, baseURL: string | undefined, login: string ): Promise< Page > {
	const page = await ( await browser.newContext( { baseURL } ) ).newPage();

	await page.goto( '/wp-login.php' );
	await page.locator( '#user_login' ).fill( login );
	await page.locator( '#user_pass' ).fill( PASSWORD );
	await page.locator( '#wp-submit' ).click();
	await page.waitForURL( /wp-admin|my-account/ );

	return page;
}

test.describe( 'The referred friend’s discount at checkout', { tag: [ '@P-4', '@flow-referral-checkout' ] }, () => {
	let advocate: number;
	let product: string;

	test.beforeAll( () => {
		advocate = userId( 'customer' );
		product = wp( 'post', 'list', '--post_type=product', '--name=e2e-simple-product', '--field=ID' );
		shortcodePage( 'e2e-classic-cart', 'woocommerce_cart' );
		wp( 'option', 'update', 'yayloyalty_referral_settings', '{"enabled":true}', '--format=json' );
	} );

	test.afterAll( () => {
		wp( 'option', 'update', 'yayloyalty_referral_settings', '{"enabled":false}', '--format=json' );
	} );

	test( 'a $60 cart shows the discount in the classic and block totals', async ( { browser, baseURL } ) => {
		const page = await logIn( browser, baseURL, referredFriend( advocate ) );

		await page.goto( `/?add-to-cart=${ product }&quantity=6` );

		await page.goto( '/e2e-classic-cart/' );
		await expect( page.locator( '.cart_totals tr.cart-discount' ) ).toContainText( 'Referral discount' );
		await expect( page.locator( '.cart_totals tr.cart-discount' ) ).toContainText( '$10.00' );
		await expect( page.locator( '.cart_totals .order-total' ) ).toContainText( '$50.00' );

		await page.goto( '/checkout/' );
		await expect( page.locator( '.wc-block-components-totals-discount' ) ).toContainText( 'Referral discount' );
		await expect( page.locator( '.wc-block-components-totals-footer-item' ) ).toContainText( '$50.00' );
	} );

	test( 'a $40 cart gets no referral discount', async ( { browser, baseURL } ) => {
		const page = await logIn( browser, baseURL, referredFriend( advocate ) );

		await page.goto( `/?add-to-cart=${ product }&quantity=4` );

		await page.goto( '/e2e-classic-cart/' );
		await expect( page.locator( '.cart_totals' ) ).not.toContainText( 'Referral discount' );

		await page.goto( '/checkout/' );
		await expect( page.locator( '.wc-block-components-totals-footer-item' ) ).toContainText( '$40.00' );
		await expect( page.locator( '.wc-block-components-totals-item' ).filter( { hasText: 'Referral discount' } ) ).toHaveCount( 0 );
	} );
} );
