import { expect, test, wp } from '../fixtures/test';

const SETTINGS = '{"enabled":true,"discount_type":"fixed","discount_amount":"10","min_order":"50","advocate_points":1000,"hold_days":14}';

test.describe( 'My Account → Referrals', { tag: [ '@P-6', '@flow-referral-account' ] }, () => {
	let tabUrl: string;

	test.beforeAll( () => {
		wp( 'option', 'update', 'yayloyalty_referral_settings', SETTINGS, '--format=json' );
		tabUrl = wp( 'eval', 'echo wc_get_endpoint_url( "referrals", "", wc_get_page_permalink( "myaccount" ) );' );
	} );

	test.afterAll( () => {
		wp( 'option', 'update', 'yayloyalty_referral_settings', '{"enabled":false}', '--format=json' );
	} );

	test( 'the customer sees the offer and copies their link', async ( { customerPage: page } ) => {
		await page.context().grantPermissions( [ 'clipboard-read', 'clipboard-write' ] );
		await page.goto( tabUrl );

		await expect( page.getByRole( 'heading', { name: /Give \$10, get 1,000/ } ) ).toBeVisible();
		const link = page.getByLabel( 'Your link' );
		await expect( link ).toHaveValue( /\?yl_ref=[A-Z2-9]{8}$/ );

		await page.getByRole( 'button', { name: 'Copy link' } ).click();
		await expect( page.getByRole( 'button', { name: 'Copied' } ) ).toBeVisible();
		expect( await page.evaluate( () => navigator.clipboard.readText() ) ).toBe( await link.inputValue() );
	} );

	test( 'at 375px the tab has no horizontal scroll', async ( { customerPage: page } ) => {
		await page.setViewportSize( { width: 375, height: 800 } );
		await page.goto( tabUrl );

		await expect( page.getByRole( 'button', { name: 'Copy link' } ) ).toBeVisible();
		expect( await page.evaluate( () => document.documentElement.scrollWidth <= document.documentElement.clientWidth ) ).toBe( true );
	} );
} );
