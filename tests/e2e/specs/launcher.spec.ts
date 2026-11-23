import { setBalance, userId } from '../fixtures/loyalty';
import { expect, test, wp } from '../fixtures/test';

test.describe( 'Storefront launcher', { tag: '@flow-launcher' }, () => {
	let customer: number;

	test.beforeAll( () => {
		customer = userId( 'customer' );
		setBalance( customer, 500 );
	} );

	test.afterEach( () => {
		wp( 'option', 'delete', 'yayloyalty_settings' );
	} );

	test.afterAll( () => {
		setBalance( customer, 0 );
	} );

	test( 'a member opens the panel from the balance button and closes it', async ( { customerPage: page } ) => {
		await page.goto( '/shop/' );

		const button = page.getByRole( 'button', { name: /^Rewards/ } );
		await expect( button ).toContainText( '500' );
		await button.click();

		const panel = page.getByRole( 'dialog', { name: 'Rewards' } );
		await expect( panel ).toBeVisible();
		// The open panel is aria-modal, so the trigger leaves the accessibility tree; read it by its hook.
		await expect( page.locator( '[data-yl-open]' ) ).toHaveAttribute( 'aria-expanded', 'true' );
		await expect( panel.getByRole( 'link', { name: 'Use points at checkout' } ) ).toBeVisible();
		await expect( panel.getByRole( 'heading', { name: 'Ways to earn' } ) ).toBeVisible();

		await panel.getByRole( 'button', { name: 'Close' } ).click();
		await expect( panel ).toBeHidden();
	} );

	test( 'a role excluded from earning never sees it', async ( { customerPage: page } ) => {
		wp( 'eval', 'YayLoyalty\\Helpers\\SettingsHelper::update_settings( [ "excluded_roles" => [ "customer" ] ] );' );

		await page.goto( '/shop/' );

		await expect( page.locator( '[data-yl-open]' ) ).toHaveCount( 0 );
	} );
} );
