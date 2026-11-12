import { wp } from './test';

/** Runs PHP in the site as the admin and returns what it echoes. */
function phpAsAdmin( code: string ): string {
	return wp( 'eval', '--user=admin', code );
}

export function userId( login: string ): number {
	return Number( wp( 'user', 'get', login, '--field=ID' ) );
}

/** A field of the customer's row, as the admin REST route reports it. */
function customerField( user: number, field: 'balance' | 'pending_points' ): number {
	return Number(
		phpAsAdmin( `echo rest_do_request( new WP_REST_Request( 'GET', '/yayloyalty/v1/customers/${ user }' ) )->get_data()['${ field }'];` )
	);
}

export function balanceOf( user: number ): number {
	return customerField( user, 'balance' );
}

/** Points staged on the customer's open orders. */
export function pendingOf( user: number ): number {
	return customerField( user, 'pending_points' );
}

/** Moves a balance through the admin adjust route, so LedgerService writes the row as it does for a real admin. */
export function adjustPoints( user: number, points: number, adjustLifetime = true ): void {
	const status = phpAsAdmin(
		`$r = new WP_REST_Request( 'POST', '/yayloyalty/v1/customers/${ user }/adjust' );` +
			`$r->set_body_params( [ 'points' => ${ points }, 'note' => 'e2e', 'adjust_lifetime' => ${ adjustLifetime ? 'true' : 'false' } ] );` +
			'echo rest_do_request( $r )->get_status();'
	);

	if ( status !== '200' && status !== '201' ) {
		throw new Error( `Adjusting user ${ user } by ${ points } points returned HTTP ${ status }.` );
	}
}

/** Sets a balance exactly, with a balance-only correction for the difference. */
export function setBalance( user: number, points: number ): void {
	const difference = points - balanceOf( user );

	if ( difference !== 0 ) {
		adjustPoints( user, difference, false );
	}
}

/** Drops the customer's cart and WooCommerce session, so a points intent from an earlier test does not carry over. */
export function emptyCart( user: number ): void {
	wp( 'user', 'meta', 'delete', String( user ), '_woocommerce_persistent_cart_1' );
	wp( 'db', 'query', `DELETE FROM wp_woocommerce_sessions WHERE session_key = '${ user }'` );
}
