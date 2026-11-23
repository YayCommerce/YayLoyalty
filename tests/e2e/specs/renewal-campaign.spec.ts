import { expect, test, wp } from '../fixtures/test';

const STUB = 'yay-e2e-wcs-renewal-stub.php';

/** WooCommerce Subscriptions isn't on the e2e site: an mu-plugin stands in for its renewal check, reading the meta it sets on a renewal order. */
function installSubscriptionsStub(): void {
	const php =
		'<?php if ( ! function_exists( "wcs_order_contains_renewal" ) ) { function wcs_order_contains_renewal( $order ) { ' +
		'$order = $order instanceof WC_Order ? $order : wc_get_order( $order ); ' +
		'return $order instanceof WC_Order && "" !== (string) $order->get_meta( "_subscription_renewal" ); } }';

	wp( 'eval', `wp_mkdir_p( WPMU_PLUGIN_DIR ); file_put_contents( WPMU_PLUGIN_DIR . '/${ STUB }', base64_decode( '${ Buffer.from( php ).toString( 'base64' ) }' ) );` );
}

function removeSubscriptionsStub(): void {
	wp( 'eval', `@unlink( WPMU_PLUGIN_DIR . '/${ STUB }' );` );
}

/** A completed $60 order for a new customer; a renewal one carries WooCommerce Subscriptions' renewal meta. */
function completedOrder( product: string, isRenewal: boolean ): number {
	const login = `e2e-renewal-${ Date.now() }-${ Math.floor( Math.random() * 1000 ) }`;
	const customer = wp( 'user', 'create', login, `${ login }@example.test`, '--role=customer', '--porcelain' );

	return Number(
		wp(
			'eval',
			`$o = wc_create_order( [ 'customer_id' => ${ customer } ] ); $o->add_product( wc_get_product( ${ product } ), 1 ); $o->calculate_totals();` +
				( isRenewal ? ` $o->update_meta_data( '_subscription_renewal', 1 );` : '' ) +
				` $o->save(); $o->update_status( 'completed' ); echo $o->get_id();`
		)
	);
}

/** Refunds $amount off the order's one line, as the order screen's Refund does. */
function refund( order: number, amount: number ): void {
	wp(
		'eval',
		`$o = wc_get_order( ${ order } ); $item = key( $o->get_items() );` +
			` wc_create_refund( [ 'order_id' => ${ order }, 'amount' => ${ amount }, 'line_items' => [ $item => [ 'qty' => 0, 'refund_total' => ${ amount } ] ] ] );`
	);
}

/** Ledger points for an order by source and type, e.g. { 'renewal/earn': 50, 'order/earn': 60 }. */
function ledger( order: number ): Record< string, number > {
	const rows = wp( 'db', 'query', `SELECT source, type, SUM(points) FROM wp_yayloyalty_ledger WHERE order_id = ${ order } GROUP BY source, type`, '--skip-column-names' );
	const sums: Record< string, number > = {};

	for ( const line of rows.split( '\n' ).filter( Boolean ) ) {
		const [ source, type, points ] = line.split( '\t' );
		sums[ `${ source }/${ type }` ] = Number( points );
	}

	return sums;
}

test.describe( 'Subscription renewal campaign', { tag: [ '@P-7', '@flow-renewal-campaign' ] }, () => {
	test.describe.configure( { mode: 'serial' } );

	let product: string;

	test.beforeAll( () => {
		installSubscriptionsStub();
		wp( 'db', 'query', "DELETE FROM wp_yayloyalty_campaigns WHERE trigger_type = 'renewal'" );
		wp( 'eval', 'YayLoyalty\\Helpers\\SettingsHelper::update_settings( [ "max_points_per_day" => null ] );' );
		product = wp(
			'eval',
			'$p = new WC_Product_Simple(); $p->set_name( "E2E monthly box" ); $p->set_regular_price( "60" ); $p->set_status( "publish" ); $p->save(); echo $p->get_id();'
		);
	} );

	test.afterAll( () => {
		removeSubscriptionsStub();
		wp( 'db', 'query', "DELETE FROM wp_yayloyalty_campaigns WHERE trigger_type = 'renewal'" );
		wp( 'post', 'delete', product, '--force' );
	} );

	test( 'the merchant creates a renewal campaign that pays on top of order points', async ( { page } ) => {
		await page.goto( '/wp-admin/admin.php?page=yayloyalty#/campaigns/new' );
		await page.getByRole( 'button', { name: /Subscription renewal/ } ).click();

		await page.getByLabel( 'Award' ).fill( '50' );
		await expect( page.getByLabel( 'Instead of order points' ) ).not.toBeChecked();
		await page.getByRole( 'button', { name: 'Save campaign' } ).click();
		await expect( page.getByText( 'Campaign created.' ) ).toBeVisible();
		await expect( page ).toHaveURL( /#\/campaigns\/\d+\/edit/ );

		await page.reload();
		await expect( page.getByLabel( 'Award' ) ).toHaveValue( /^50/ );
		await expect( page.getByLabel( 'Instead of order points' ) ).not.toBeChecked();

		const order = completedOrder( product, true );
		expect( ledger( order ) ).toMatchObject( { 'renewal/earn': 50 } );
		expect( ledger( order )[ 'order/earn' ] ).toBeGreaterThan( 0 );

		// A re-fired completed status pays nothing more.
		wp( 'eval', `$o = wc_get_order( ${ order } ); $o->update_status( 'processing' ); $o->update_status( 'completed' );` );
		expect( ledger( order )[ 'renewal/earn' ] ).toBe( 50 );
	} );

	test( 'a full refund takes the renewal points back, a partial one pro rata', () => {
		const full = completedOrder( product, true );
		refund( full, 60 );
		expect( ledger( full )[ 'renewal/refund_earn' ] ).toBe( -50 );

		const partial = completedOrder( product, true );
		refund( partial, 30 );
		expect( ledger( partial )[ 'renewal/refund_earn' ] ).toBe( -25 );
	} );

	test( 'an order that is not a renewal earns order points only', () => {
		const order = completedOrder( product, false );

		expect( ledger( order )[ 'renewal/earn' ] ).toBeUndefined();
		expect( ledger( order )[ 'order/earn' ] ).toBeGreaterThan( 0 );
	} );

	test( 'switched to instead of order points, a renewal earns only the renewal points', async ( { page } ) => {
		const id = wp( 'db', 'query', "SELECT id FROM wp_yayloyalty_campaigns WHERE trigger_type = 'renewal' LIMIT 1", '--skip-column-names' );

		await page.goto( `/wp-admin/admin.php?page=yayloyalty#/campaigns/${ id }/edit` );
		await page.getByLabel( 'Instead of order points' ).click();
		await page.getByRole( 'button', { name: 'Save campaign' } ).click();
		await expect( page.getByText( 'Campaign saved.' ) ).toBeVisible();

		const order = completedOrder( product, true );
		expect( ledger( order ) ).toEqual( { 'renewal/earn': 50 } );
	} );

	test( 'without WooCommerce Subscriptions the card is gone and the stored campaign still opens', async ( { page } ) => {
		removeSubscriptionsStub();
		test.skip(
			wp( 'eval', 'echo function_exists( "wcs_order_contains_renewal" ) ? "yes" : "no";' ) === 'yes',
			'WooCommerce Subscriptions, or another stand-in for it, is active on this site.'
		);
		const id = wp( 'db', 'query', "SELECT id FROM wp_yayloyalty_campaigns WHERE trigger_type = 'renewal' LIMIT 1", '--skip-column-names' );

		await page.goto( '/wp-admin/admin.php?page=yayloyalty#/campaigns/new' );
		await expect( page.getByRole( 'button', { name: /Social follow or share/ } ) ).toBeVisible();
		await expect( page.getByRole( 'button', { name: /Subscription renewal/ } ) ).toHaveCount( 0 );

		await page.goto( `/wp-admin/admin.php?page=yayloyalty#/campaigns/${ id }/edit` );
		await expect( page.getByLabel( 'Award' ) ).toHaveValue( /^50/ );

		const order = completedOrder( product, true );
		expect( ledger( order )[ 'renewal/earn' ] ).toBeUndefined();
	} );
} );
