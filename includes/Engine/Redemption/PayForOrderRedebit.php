<?php
namespace YayLoyalty\Engine\Redemption;

use YayLoyalty\Engine\Rewards\Result;

defined( 'ABSPATH' ) || exit;

/**
 * Pay-for-order on an order whose payment failed: the points it redeemed went back to the customer
 * (Reversal::payment_failed()), so they are debited again before the gateway runs, and a balance that no
 * longer covers them refuses the payment. Classic pay-for-order is hooked here; the block one goes through
 * StoreApiAdapter::on_order_processed(), which calls redebit_or_message().
 */
final class PayForOrderRedebit {

    private Reversal $reversal;

    public function __construct( Reversal $reversal ) {
        $this->reversal = $reversal;
    }

    /** Called once at boot, not behind is_admin(): pay-for-order is a storefront request. */
    public function register(): void {
        add_action( 'woocommerce_before_pay_action', [ $this, 'before_pay' ] );
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
        }

        return null;
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
