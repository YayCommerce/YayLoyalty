<?php
namespace YayLoyalty\Engine\Redemption;

defined( 'ABSPATH' ) || exit;

/**
 * The only mutable state on the quote path: one integer in the WooCommerce
 * session, the points the customer asked to spend. Never the discount
 * value — that is derived from the current cart on every recalculation —
 * and never a Quote. Every method returns quietly when WooCommerce has no session.
 */
final class SessionIntent {

    /** WC session key; the integer lives nowhere else until the order exists. */
    public const KEY = 'yayloyalty_redeem_points';

    /** Postcondition: the stored integer, 0 when nothing was asked or there is no session. Never negative. */
    public function get(): int {
        $session = $this->session();

        return null === $session ? 0 : max( 0, (int) $session->get( self::KEY, 0 ) );
    }

    /**
     * Precondition: $points ≥ 0, unclamped — the clamp runs on read, not on
     * write, so a later cart change can never leave a stale ceiling here.
     * Postcondition: get() returns $points; 0 removes the key.
     */
    public function set( int $points ): void {
        if ( $points <= 0 ) {
            $this->clear();

            return;
        }

        $session = $this->session();

        if ( null !== $session ) {
            $session->set( self::KEY, $points );
        }
    }

    /** Postcondition: get() returns 0. Called by the commit, and when the shopper removes the coupon. */
    public function clear(): void {
        $session = $this->session();

        if ( null !== $session ) {
            $session->__unset( self::KEY );
        }
    }

    private function session(): ?\WC_Session {
        if ( ! function_exists( 'WC' ) ) {
            return null;
        }

        $session = WC()->session;

        return $session instanceof \WC_Session ? $session : null;
    }
}
