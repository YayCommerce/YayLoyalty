import { type Browser, type Page } from '@playwright/test';

import { balanceOf, shortcodePage } from '../fixtures/loyalty';
import { expect, test, wp } from '../fixtures/test';

const PASSWORD = 'password';
const NETWORK_URL = 'https://instagram.com/yay-e2e';

/** Runs a REST request as the admin and returns the response data as JSON. */
function rest( method: string, route: string, body: Record< string, unknown > = {} ): string {
	return wp(
		'eval',
		'--user=admin',
		`$q = new WP_REST_Request( '${ method }', '${ route }' ); $q->set_body_params( json_decode( '${ JSON.stringify( body ) }', true ) ); echo wp_json_encode( rest_do_request( $q )->get_data() );`
	);
}

function createSocialCampaign(): number {
	const created = JSON.parse(
		rest( 'POST', '/yayloyalty/v1/campaigns', {
			name: 'E2E follow',
			trigger: 'social',
			status: 'active',
			config: { network: 'instagram', action: 'follow', url: NETWORK_URL, points: 20 },
		} )
	);

	return Number( created.id );
}

function socialRows( user: number ): number {
	return Number( wp( 'db', 'query', `SELECT COUNT(*) FROM wp_yayloyalty_ledger WHERE user_id = ${ user } AND source = 'social'`, '--skip-column-names' ) );
}

/** A new customer, so no earlier claim counts. */
function freshCustomer(): { login: string; id: number } {
	const login = `e2e-social-${ Date.now() }-${ Math.floor( Math.random() * 1000 ) }`;
	const id = Number( wp( 'user', 'create', login, `${ login }@example.test`, '--role=customer', `--user_pass=${ PASSWORD }`, '--porcelain' ) );

	return { login, id };
}

/** Opens a link as the signed-in page would, without following the redirect off the site: the status and where it sends the customer. */
async function follow( page: Page, href: string ): Promise< { status: number; location: string } > {
	const response = await page.request.get( href, { maxRedirects: 0 } );

	return { status: response.status(), location: response.headers().location ?? '' };
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

test.describe( 'Social campaign claim', { tag: [ '@P-7', '@flow-social-claim' ] }, () => {
	test.describe.configure( { mode: 'serial' } );

	let campaign: number;
	let myPoints: string;

	test.beforeAll( () => {
		wp( 'db', 'query', "DELETE FROM wp_yayloyalty_campaigns WHERE trigger_type = 'social'" );
		wp( 'eval', 'YayLoyalty\\Helpers\\SettingsHelper::update_settings( [ "max_points_per_day" => null ] );' );
		campaign = createSocialCampaign();
		myPoints = wp( 'eval', 'echo wc_get_endpoint_url( "my-points", "", wc_get_page_permalink( "myaccount" ) );' );
		shortcodePage( 'e2e-ways-to-earn', 'yayloyalty_ways_to_earn' );
	} );

	test.afterAll( () => {
		wp( 'db', 'query', "DELETE FROM wp_yayloyalty_campaigns WHERE trigger_type = 'social'" );
	} );

	test( 'a click pays once, sends the customer to the network and then shows Earned', async ( { browser, baseURL } ) => {
		const customer = freshCustomer();
		const page = await logIn( browser, baseURL, customer.login );
		const before = balanceOf( customer.id );

		await page.goto( myPoints );
		const content = page.locator( '.woocommerce-MyAccount-content' );
		const row = content.getByRole( 'link', { name: /Follow us on Instagram/ } );
		await expect( row ).toHaveAttribute( 'href', new RegExp( `yl_social=${ campaign }` ) );
		await expect( content.getByText( 'Earn once, the first time you click.' ) ).toBeVisible();
		await expect( page.locator( '#yayloyalty-launcher-panel a', { hasText: 'Follow us on Instagram' } ) ).toHaveCount( 1 );

		const claim = ( await row.getAttribute( 'href' ) ) as string;
		expect( await follow( page, claim ) ).toEqual( { status: 302, location: NETWORK_URL } );
		expect( balanceOf( customer.id ) ).toBe( before + 20 );

		await page.goto( myPoints );
		await expect( content.locator( 'li', { hasText: 'Follow us on Instagram' } ).getByText( 'Earned', { exact: true } ) ).toBeVisible();
		const earned = content.getByRole( 'link', { name: /Follow us on Instagram/ } );
		await expect( earned ).toHaveAttribute( 'href', NETWORK_URL );

		// The claim link again, as a stale tab or a double click would send it: still no second payment.
		expect( await follow( page, claim ) ).toEqual( { status: 302, location: NETWORK_URL } );
		expect( balanceOf( customer.id ) ).toBe( before + 20 );
		expect( socialRows( customer.id ) ).toBe( 1 );
	} );

	test( 'a claim link with a bad nonce still redirects but pays nothing', async ( { browser, baseURL } ) => {
		const customer = freshCustomer();
		const page = await logIn( browser, baseURL, customer.login );
		const before = balanceOf( customer.id );

		expect( await follow( page, `/?yl_social=${ campaign }&_wpnonce=badnonce1` ) ).toEqual( { status: 302, location: NETWORK_URL } );
		expect( balanceOf( customer.id ) ).toBe( before );
		expect( socialRows( customer.id ) ).toBe( 0 );
	} );

	test( 'a guest gets a plain link to the network and is told to sign in', async ( { browser, baseURL } ) => {
		const context = await browser.newContext( { baseURL, storageState: { cookies: [], origins: [] } } );
		const page = await context.newPage();

		await page.goto( '/e2e-ways-to-earn/' );
		await expect( page.locator( '#wpadminbar' ) ).toHaveCount( 0 );

		await expect( page.getByRole( 'link', { name: /Follow us on Instagram/ } ).first() ).toHaveAttribute( 'href', NETWORK_URL );
		await expect( page.getByText( 'Sign in to earn.' ).first() ).toBeVisible();
		await expect( page.locator( 'a[href*="yl_social="]' ) ).toHaveCount( 0 );
		await context.close();
	} );

	test( 'a customer the conditions leave out sees no row', async ( { browser, baseURL } ) => {
		rest( 'PATCH', `/yayloyalty/v1/campaigns/${ campaign }`, {
			conditions: [ { type: 'user_role', operator: 'in', value: [ 'wholesale' ] } ],
		} );

		try {
			const page = await logIn( browser, baseURL, freshCustomer().login );
			await page.goto( myPoints );

			await expect( page.locator( '.woocommerce-MyAccount-content' ).getByRole( 'heading', { name: 'Ways to earn' } ) ).toBeVisible();
			await expect( page.getByRole( 'link', { name: /Follow us on Instagram/ } ) ).toHaveCount( 0 );
		} finally {
			rest( 'PATCH', `/yayloyalty/v1/campaigns/${ campaign }`, { conditions: [] } );
		}
	} );

	test( 'a paused campaign disappears from Ways to earn', async ( { browser, baseURL } ) => {
		rest( 'PATCH', `/yayloyalty/v1/campaigns/${ campaign }`, { status: 'paused' } );

		try {
			const page = await logIn( browser, baseURL, freshCustomer().login );
			await page.goto( myPoints );

			await expect( page.locator( '.woocommerce-MyAccount-content' ).getByRole( 'heading', { name: 'Ways to earn' } ) ).toBeVisible();
			await expect( page.getByRole( 'link', { name: /Follow us on Instagram/ } ) ).toHaveCount( 0 );
		} finally {
			rest( 'PATCH', `/yayloyalty/v1/campaigns/${ campaign }`, { status: 'active' } );
		}
	} );
} );
