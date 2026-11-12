<?php
namespace YayLoyalty\Engine\Redemption;

use YayLoyalty\Engine\Earning\Outcome;
use YayLoyalty\Engine\Rewards\Result;
use YayLoyalty\Utils\Log;

defined( 'ABSPATH' ) || exit;

/**
 * Pay-for-order on an order whose payment failed: the points it redeemed went back to the customer
 * (Reversal::payment_failed()), so they are debited again before the gateway runs, and a balance that no
 * longer covers them refuses the payment. Classic pay-for-order is hooked here; the block one goes through
 * StoreApiAdapter::on_order_processed(), which calls redebit_or_message(). Both debit before WooCommerce checks the
 * terms and runs the gateway, so an attempt that leaves the order still needing payment gets its points back at
 * the end of the request (settle()).
 */
final class PayForOrderRedebit {

    private Reversal $reversal;

    /** @var array<int, true> Order id => true, for each order this request debited again. */
    private array $debited = [];

    public function __construct( Reversal $reversal ) {
        $this->reversal = $reversal;
    }

    /** Called once at boot, not behind is_admin(): pay-for-order is a storefront request. */
    public function register(): void {
        add_action( 'woocommerce_before_pay_action', [ $this, 'before_pay' ] );
        add_action( 'shutdown', [ $this, 'settle' ] );
    }

    /**
     * Debits the order's `unpaid` rows again, all or nothing.
     *
     * @return string|null The message to refuse the payment with, or null when the debit landed or there was nothing to debit.
     */
    public function redebit_or_message( \WC_Order $order ): ?string {
        if ( ! $this->reversal->has_unpaid( $order ) ) {
            return null;
        }

        foreach ( $this->reversal->payment_retried( $order, false ) as $outcome ) {
            if ( Result::REASON_INSUFFICIENT_BALANCE === $outcome->get_reason() ) {
                return __( 'You no longer have enough points for the points discount on this order.', 'yayloyalty' );
            }

            if ( Outcome::AWARDED === $outcome->get_status() ) {
                $this->debited[ (int) $order->get_id() ] = true;
            }
        }

        return null;
    }

    /**
     * `shutdown`: each order this request debited again that still needs payment (the terms were not accepted, the
     * gateway refused, or it went offsite and left the order pending) gets its points back and its rows `unpaid`, so
     * a later paid status debits them leniently. Empties the list first, so a second call does nothing. Never throws.
     */
    public function settle(): void {
        $debited       = $this->debited;
        $this->debited = [];

        foreach ( array_keys( $debited ) as $order_id ) {
            $order = wc_get_order( $order_id );

            if ( ! $order instanceof \WC_Order || ! $order->needs_payment() ) {
                continue;
            }

            try {
                $this->reversal->payment_failed( $order );
            } catch ( \Throwable $e ) {
                Log::error( self::class, sprintf( 'Returning order #%d points after an unfinished payment failed: %s', $order_id, $e->getMessage() ) );
            }
        }
    }

    /**
     * `woocommerce_before_pay_action` adapter. WC_Form_Handler::pay_action() goes on to the gateway whatever notices
     * this hook adds, so a refusal redirects back to the payment page and stops the request.
     */
    public function before_pay( \WC_Order $order ): void {
        $message = $this->redebit_or_message( $order );

        if ( null === $message ) {
            return;
        }

        wc_add_notice( $message, 'error' );
        wp_safe_redirect( $order->get_checkout_payment_url() );
        exit;
    }
}
