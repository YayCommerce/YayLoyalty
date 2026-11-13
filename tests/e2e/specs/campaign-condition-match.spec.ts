import { expect, test, wp } from '../fixtures/test';

/** Creates a draft purchase campaign holding one condition, country US (billing), and returns its id. */
function createCountryCampaign(): number {
	return Number(
		wp(
			'eval',
			'--user=admin',
			'$q = new WP_REST_Request( "POST", "/yayloyalty/v1/campaigns" );' +
				'$q->set_body_params( [ "name" => "P-1 match", "trigger" => "purchase", "status" => "draft", "config" => [ "points_per_unit" => 1 ],' +
				' "conditions" => [ [ "type" => "country", "operator" => "in", "value" => [ "countries" => [ "US" ], "source" => "billing" ] ] ] ] );' +
				'echo rest_do_request( $q )->get_data()["id"];'
		)
	);
}

function storedConditions( id: number ): string {
	return wp( 'db', 'query', `SELECT conditions FROM wp_yayloyalty_campaigns WHERE id = ${ id }`, '--skip-column-names' );
}

test.describe( 'Campaign condition match', { tag: [ '@flow-campaign-condition-match', '@P-1' ] }, () => {
	let id = 0;

	test.beforeEach( () => {
		id = createCountryCampaign();
	} );

	test.afterEach( () => {
		wp( 'db', 'query', `DELETE FROM wp_yayloyalty_campaigns WHERE id = ${ id }` );
	} );

	test( 'a second condition matched by any comes back as saved', async ( { page } ) => {
		await page.goto( `/wp-admin/admin.php?page=yayloyalty#/campaigns/${ id }/edit` );

		const match = page.getByRole( 'combobox', { name: 'Match conditions' } );
		await expect( match ).toHaveText( 'all' );
		await expect( page.getByRole( 'button', { name: 'Add group' } ) ).toHaveCount( 0 );

		await page.getByRole( 'button', { name: 'Add condition' } ).click();
		await page.getByRole( 'menuitem', { name: 'Order count' } ).click();
		await page.getByRole( 'textbox' ).last().fill( '5' );

		await match.click();
		await page.getByRole( 'option', { name: 'any' } ).click();

		await page.getByRole( 'button', { name: 'Save campaign' } ).click();
		await expect( page.getByText( 'Campaign saved.' ) ).toBeVisible();

		await page.reload();

		await expect( page.getByRole( 'combobox', { name: 'Match conditions' } ) ).toHaveText( 'any' );
		await expect( page.getByText( 'Country' ).first() ).toBeVisible();
		await expect( page.getByText( 'Order count' ).first() ).toBeVisible();
		expect( storedConditions( id ) ).toContain( '"match":"any"' );
		expect( storedConditions( id ) ).toContain( '"schema_version":2' );
	} );
} );
