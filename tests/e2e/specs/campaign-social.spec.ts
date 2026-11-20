import { expect, test, wp } from '../fixtures/test';

function storedSocialConfig(): string {
	return wp( 'db', 'query', "SELECT config FROM wp_yayloyalty_campaigns WHERE trigger_type = 'social'", '--skip-column-names' );
}

test.describe( 'Social campaign', { tag: [ '@P-7', '@flow-campaign-social' ] }, () => {
	test.afterEach( () => {
		wp( 'db', 'query', "DELETE FROM wp_yayloyalty_campaigns WHERE trigger_type = 'social'" );
	} );

	test( 'network, action, link and points survive a reload', async ( { page } ) => {
		await page.goto( '/wp-admin/admin.php?page=yayloyalty#/campaigns/new/social' );

		await page.getByLabel( 'Link' ).fill( 'https://facebook,com' );
		await page.getByRole( 'button', { name: 'Save as draft' } ).click();
		await expect( page.getByText( 'Please fix the highlighted link before saving.' ) ).toBeVisible();
		await expect( page.getByText( 'Enter a full link that starts with https://' ) ).toBeVisible();

		await page.getByRole( 'combobox', { name: 'Network' } ).click();
		await page.getByRole( 'option', { name: 'Facebook' } ).click();
		await page.getByRole( 'radio', { name: 'Share' } ).click();
		await page.getByRole( 'button', { name: 'Use a share link for my store' } ).click();
		await expect( page.getByLabel( 'Link' ) ).toHaveValue( /^https:\/\/www\.facebook\.com\/sharer\/sharer\.php\?u=/ );
		await page.getByLabel( 'Award' ).fill( '25' );

		await page.getByRole( 'button', { name: 'Save as draft' } ).click();
		await expect( page.getByText( 'Campaign created.' ) ).toBeVisible();
		await expect( page ).toHaveURL( /#\/campaigns\/\d+\/edit/ );

		await page.reload();

		await expect( page.getByRole( 'combobox', { name: 'Network' } ) ).toHaveText( 'Facebook' );
		await expect( page.getByRole( 'radio', { name: 'Share' } ) ).toHaveAttribute( 'aria-checked', 'true' );
		await expect( page.getByLabel( 'Link' ) ).toHaveValue( /facebook\.com\/sharer/ );
		await expect( page.getByLabel( 'Award' ) ).toHaveValue( /^25/ );
		expect( storedSocialConfig() ).toContain( '"network":"facebook"' );
		expect( storedSocialConfig() ).toContain( '"action":"share"' );
		expect( storedSocialConfig() ).toContain( '"points":25' );
	} );
} );
