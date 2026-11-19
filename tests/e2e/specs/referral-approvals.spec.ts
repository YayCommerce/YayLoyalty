import { type Page } from '@playwright/test';

import { userId } from '../fixtures/loyalty';
import { expect, test, wp } from '../fixtures/test';

/**
 * A held referral from the `customer` user: a fresh friend with a completed $60 first order, held for 5 days.
 *
 * @return The friend's email.
 */
function heldReferral( advocate: number ): string {
	const login = `e2e-held-${ Date.now() }-${ Math.floor( Math.random() * 1000 ) }`;
	const friend = wp( 'user', 'create', login, `${ login }@example.test`, '--role=customer', '--porcelain' );

	wp(
		'eval',
		`$r = new YayLoyalty\\Pro\\Repositories\\ReferralRepository();
		$id = $r->insert_pending( ${ advocate }, ${ friend }, 'E2EREF', [ 'advocate_points' => 1000 ], gmdate( 'Y-m-d H:i:s', time() + 30 * DAY_IN_SECONDS ) );
		$o = wc_create_order( [ 'customer_id' => ${ friend } ] ); $o->set_total( '60' ); $o->set_status( 'completed' ); $o->save();
		$r->hold( $id, $o->get_id(), gmdate( 'Y-m-d H:i:s', time() + 5 * DAY_IN_SECONDS ) );`
	);

	return `${ login }@example.test`;
}

/** The Approvals row of the friend whose masked email starts with `e`. */
function rowOf( page: Page, email: string ) {
	return page.getByRole( 'row' ).filter( { hasText: `${ email[ 0 ] }•••@example.test` } );
}

test.describe( 'Referral approvals', { tag: [ '@P-5', '@flow-referral-approvals' ] }, () => {
	let advocate: number;
	let advocateName: string;

	test.beforeAll( () => {
		advocate = userId( 'customer' );
		advocateName = wp( 'user', 'get', String( advocate ), '--field=display_name' );
		wp( 'eval', "global $wpdb; $wpdb->query( 'DELETE FROM ' . $wpdb->prefix . 'yayloyalty_referrals' );" );
	} );

	test( 'approve pays the advocate and the referral shows Rewarded', async ( { page } ) => {
		heldReferral( advocate );
		await page.goto( '/wp-admin/admin.php?page=yayloyalty#/referral/approvals' );

		const row = page.getByRole( 'row' ).filter( { hasText: 'Hold ending' } ).first();
		await row.getByRole( 'button', { name: 'Approve' } ).click();

		await expect( page.getByText( `Paid 1,000 points to ${ advocateName }` ) ).toBeVisible();
		await expect( page.getByText( 'Nothing waiting. Held referrals appear here until their hold ends.' ) ).toBeVisible();

		await page.getByRole( 'link', { name: 'Referrals', exact: true } ).click();
		await expect( page.getByRole( 'row' ).filter( { hasText: 'Rewarded' } ) ).toHaveCount( 1 );
	} );

	test( 'reject with a reason shows Rejected with that reason and pays nothing', async ( { page } ) => {
		const email = heldReferral( advocate );
		const ledgerRows = () => wp( 'eval', "global $wpdb; echo $wpdb->get_var( \"SELECT COUNT(*) FROM {$wpdb->prefix}yayloyalty_ledger WHERE source = 'referral'\" );" );
		const before = ledgerRows();
		await page.goto( '/wp-admin/admin.php?page=yayloyalty#/referral/approvals' );

		await rowOf( page, email ).getByRole( 'button', { name: 'Reject' } ).click();
		await page.getByRole( 'combobox', { name: 'Reason' } ).click();
		await page.getByRole( 'option', { name: 'Not a genuine referral' } ).click();
		await page.getByRole( 'dialog' ).getByRole( 'button', { name: 'Reject' } ).click();

		await expect( page.getByText( 'Referral rejected' ) ).toBeVisible();

		await page.getByRole( 'link', { name: 'Referrals', exact: true } ).click();
		await expect( page.getByRole( 'row' ).filter( { hasText: 'Not a genuine referral' } ) ).toHaveCount( 1 );
		expect( ledgerRows() ).toBe( before );
	} );

	test( 'the advocate drawer lists their referrals and their link', async ( { page } ) => {
		await page.goto( `/wp-admin/admin.php?page=yayloyalty#/referral/referrals/${ advocate }` );

		await page.getByRole( 'tab', { name: /Referrals/ } ).click();

		await expect( page.getByRole( 'textbox', { name: 'Referral link' } ) ).toHaveValue( /\?yl_ref=[A-Z2-9]{8}$/ );
		await expect( page.getByText( 'Revenue referred' ) ).toBeVisible();
	} );
} );
