import { emptyCart, setBalance, userId } from '../fixtures/loyalty';
import { expect, test, wp } from '../fixtures/test';

const WIDGETS = [ 'points-balance', 'ways-to-earn', 'ways-to-redeem', 'redeem-card' ];
const TITLES = [ 'Points balance', 'Ways to earn', 'Ways to redeem', 'Redeem card' ];

function createPage( title: string, content = '' ): number {
	return Number( wp( 'post', 'create', '--post_type=page', '--post_status=publish', `--post_title=${ title }`, `--post_content=${ content }`, '--porcelain' ) );
}

function elementorData(): string {
	const widgets = WIDGETS.map( ( widget, i ) => ( {
		id: `e2e000${ i }`,
		elType: 'widget',
		widgetType: `yayloyalty-${ widget }`,
		settings: {},
		elements: [],
	} ) );

	return JSON.stringify( [ { id: 'e2ec0n1', elType: 'container', settings: {}, elements: widgets } ] );
}

test.describe( 'Elementor widgets', { tag: [ '@flow-page-builder-elementor', '@P-2' ] }, () => {
	let customer: number;
	let builderPage: number;
	let shortcodePage: number;

	test.beforeAll( () => {
		wp( 'plugin', 'activate', 'elementor' );
		// Activation sends the next admin page to Elementor's onboarding wizard instead of the editor.
		wp( 'option', 'update', 'elementor_onboarded', '1' );
		wp( 'transient', 'delete', 'elementor_activation_redirect' );
		customer = userId( 'customer' );
		setBalance( customer, 500 );
		emptyCart( customer );

		builderPage = createPage( 'E2E Elementor placements' );
		wp( 'post', 'meta', 'update', String( builderPage ), '_elementor_edit_mode', 'builder' );
		wp( 'post', 'meta', 'update', String( builderPage ), '_elementor_template_type', 'wp-page' );
		wp( 'post', 'meta', 'update', String( builderPage ), '_elementor_data', elementorData() );

		shortcodePage = createPage( 'E2E Shortcode placements', '[yayloyalty_balance][yayloyalty_ways_to_earn][yayloyalty_ways_to_redeem][yayloyalty_redeem]' );
	} );

	test.afterAll( () => {
		wp( 'post', 'delete', String( builderPage ), String( shortcodePage ), '--force' );
		setBalance( customer, 0 );
		wp( 'plugin', 'deactivate', 'elementor' );
	} );

	test( 'a customer sees on the Elementor page exactly what the shortcodes print', async ( { customerPage: page } ) => {
		await page.goto( `/?page_id=${ shortcodePage }` );
		const expected = await page.locator( '.yayloyalty-placement' ).allInnerTexts();
		expect( expected.length ).toBeGreaterThan( 0 );

		await page.goto( `/?page_id=${ builderPage }` );
		const placements = page.locator( '.elementor-widget .yayloyalty-placement' );

		await expect( placements.first() ).toContainText( '500' );
		expect( await placements.allInnerTexts() ).toEqual( expected );
		await expect( page.locator( '.yayloyalty-account__state' ) ).toHaveCount( 0 );
	} );

	test( 'the editor lists the four widgets and previews each one', async ( { page } ) => {
		await page.goto( `/wp-admin/post.php?post=${ builderPage }&action=elementor` );

		const preview = page.frameLocator( '#elementor-preview-iframe' );

		// The admin is in the program, so the balance is real; an empty cart leaves the redeem card a placeholder.
		await expect( preview.locator( '.elementor-widget-yayloyalty-points-balance .yayloyalty-placement' ) ).toBeVisible( { timeout: 30_000 } );
		const redeemCard = preview.locator( '.elementor-widget-yayloyalty-redeem-card .yayloyalty-account__state' );
		await expect( redeemCard.getByRole( 'heading', { name: 'Redeem card' } ) ).toBeVisible();
		await expect( redeemCard ).toContainText( 'Shows when the cart has items.' );

		const search = page.locator( '#elementor-panel-elements-search-input' );
		await search.fill( 'loyalty' );

		const panel = page.locator( '#elementor-panel-elements' );
		for ( const title of TITLES ) {
			await expect( panel.getByText( title, { exact: true } ) ).toBeVisible();
		}
	} );
} );
