import { expect, test, wp } from '../fixtures/test';

test.describe( 'Reward editor', { tag: [ '@P-11', '@flow-reward-editor' ] }, () => {
	// A claimed reward can only be archived, so each run names its own.
	const run = Date.now();
	const rewardName = `E2E free gift ${ run }`;
	let product: string;

	test.beforeAll( () => {
		product = wp( 'wc', 'product', 'create', `--name=E2E P11 gift ${ run }`, '--regular_price=9', '--user=admin', '--porcelain' );
	} );

	test.afterAll( () => {
		const id = rewardId();

		if ( id ) {
			wp( 'eval', '--user=admin', `rest_do_request( new WP_REST_Request( 'DELETE', '/yayloyalty/v1/rewards/${ id }' ) );` );
		}

		wp( 'wc', 'product', 'delete', product, '--force=true', '--user=admin' );
	} );

	test( 'a free product reward is added from the catalog and kept after a reload', async ( { page } ) => {
		await page.goto( '/wp-admin/admin.php?page=yayloyalty#/rewards/new' );

		await page.getByRole( 'button', { name: /Free product/ } ).click();
		await page.getByLabel( 'Name' ).fill( rewardName );
		await page.getByText( 'Search products' ).click();
		await page.getByPlaceholder( 'Type to search…' ).fill( `E2E P11 gift ${ run }` );
		await page.getByRole( 'option', { name: new RegExp( `E2E P11 gift ${ run }` ) } ).click();
		await page.getByRole( 'button', { name: 'Save reward' } ).click();
		await expect( page.getByText( 'Reward created.' ) ).toBeVisible();

		await page.goto( '/wp-admin/admin.php?page=yayloyalty#/rewards' );
		await page.reload();

		await expect( page.getByRole( 'row' ).filter( { hasText: rewardName } ).filter( { hasText: 'Free product' } ) ).toHaveCount( 1 );
		expect( rewardId() ).not.toBe( '' );
	} );

	/** The saved reward's id when it is a free product of this run's product, else ''. */
	function rewardId(): string {
		return wp(
			'eval',
			'--user=admin',
			`foreach ( rest_do_request( new WP_REST_Request( 'GET', '/yayloyalty/v1/rewards' ) )->get_data()['data'] as $r ) { if ( '${ rewardName }' === $r['name'] && 'free_product' === $r['type'] && ${ product } === (int) ( $r['config']['product_id'] ?? 0 ) ) { echo $r['id']; } }`
		);
	}
} );
