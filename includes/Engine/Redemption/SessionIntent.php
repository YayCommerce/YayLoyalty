<?php
namespace YayLoyalty\Engine\Redemption;

defined( 'ABSPATH' ) || exit;

/**
 * The only mutable state on the quote path: one integer in the WooCommerce
 * session, the points the customer asked to spend. Never the discount
 * value — that is derived from the current cart on every recalculation —
 * and never a Quote. Scaffold: stubs.
 */
final class SessionIntent {

    /** WC session key; the integer lives nowhere else until the order exists. */
    public const KEY = 'yayloyalty_redeem_points';

    /** Postcondition: the stored integer, 0 when nothing was asked or there is no session. Never negative. */
    public function get(): int {
        // TODO(redemption): read KEY from the WC session as an int, floored at 0; return 0 when there is no session.
        throw new \RuntimeException( 'Not implemented' );
    }

    /**
     * Precondition: $points ≥ 0, unclamped — the clamp runs on read, not on
     * write, so a later cart change can never leave a stale ceiling here.
     * Postcondition: get() returns $points; 0 removes the key.
     */
    public function set( int $points ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- scaffold stub.
        // TODO(redemption): floor the points at 0 and store them under KEY in the WC session; a zero delegates to clear.
        throw new \RuntimeException( 'Not implemented' );
    }

    /** Postcondition: get() returns 0. Called by the commit after the order carries the frozen value. */
    public function clear(): void {
        // TODO(redemption): WC()->session->__unset( self::KEY ) when a session exists.
        throw new \RuntimeException( 'Not implemented' );
    }
}
