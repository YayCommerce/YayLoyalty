import { expect, test, wp } from '../fixtures/test';

/** Creates a draft purchase campaign holding one condition, country US (billing), and returns its id. */
function createCountryCampaign(): number {
	return Number(
		wp(
			'eval',
			'--user=admin',
			'$q = new WP_REST_Request( "POST", "/yayloyalty/v1/campaigns" );' +
				'$q->set_body_params( [ "name" => "P-1 groups", "trigger" => "purchase", "status" => "draft", "config" => [ "points_per_unit" => 1 ],' +
				' "conditions" => [ [ "type" => "country", "operator" => "in", "value" => [ "countries" => [ "US" ], "source" => "billing" ] ] ] ] );' +
				'echo rest_do_request( $q )->get_data()["id"];'
		)
	);
}

function storedConditions( id: number ): string {
	return wp( 'db', 'query', `SELECT conditions FROM wp_yayloyalty_campaigns WHERE id = ${ id }`, '--skip-column-names' );
}

test.describe( 'Campaign condition groups', { tag: [ '@flow-campaign-condition-groups', '@P-1' ] }, () => {
	let id = 0;

	test.beforeEach( () => {
		id = createCountryCampaign();
	} );

	test.afterEach( () => {
		wp( 'db', 'query', `DELETE FROM wp_yayloyalty_campaigns WHERE id = ${ id }` );
	} );

	test( 'two groups joined by AND, the second ORed, come back as saved', async ( { page } ) => {
		await page.goto( `/wp-admin/admin.php?page=yayloyalty#/campaigns/${ id }/edit` );

		// One group: the 1.0.0 layout plus a match select.
		await expect( page.getByRole( 'combobox', { name: 'Match conditions' } ) ).toBeVisible();
		await expect( page.getByRole( 'group', { name: 'Group 1' } ) ).toHaveCount( 0 );

		await page.getByRole( 'button', { name: 'Add group' } ).click();

		const second = page.getByRole( 'group', { name: 'Group 2' } );
		await expect( page.getByRole( 'group', { name: 'Group 1' } ).getByText( 'Country' ) ).toBeVisible();

		await second.getByRole( 'button', { name: 'Add condition' } ).click();
		await page.getByRole( 'menuitem', { name: 'Lifetime spend' } ).click();
		await second.getByRole( 'textbox' ).first().fill( '500' );

		await second.getByRole( 'button', { name: 'Add condition' } ).click();
		await page.getByRole( 'menuitem', { name: 'Order count' } ).click();
		await second.getByRole( 'textbox' ).last().fill( '5' );

		await second.getByRole( 'combobox', { name: 'Group 2 match' } ).click();
		await page.getByRole( 'option', { name: 'any' } ).click();

		await page.getByRole( 'button', { name: 'Save campaign' } ).click();
		await expect( page.getByText( 'Campaign saved.' ) ).toBeVisible();

		await page.reload();

		await expect( page.getByRole( 'combobox', { name: 'Match groups' } ) ).toHaveText( 'all' );
		await expect( page.getByRole( 'combobox', { name: 'Group 1 match' } ) ).toHaveText( 'all' );
		await expect( page.getByRole( 'combobox', { name: 'Group 2 match' } ) ).toHaveText( 'any' );
		await expect( page.getByRole( 'group', { name: 'Group 2' } ).getByText( 'Lifetime spend' ) ).toBeVisible();
		await expect( page.getByRole( 'group', { name: 'Group 2' } ).getByText( 'Order count' ) ).toBeVisible();
		expect( storedConditions( id ) ).toContain( '"schema_version":2' );
	} );
} );
