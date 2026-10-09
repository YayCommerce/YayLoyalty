<?php
namespace YayLoyalty\Engine\Redemption\Adapters;

use YayLoyalty\Engine\Earning\Outcome;
use YayLoyalty\Engine\Redemption\RedemptionService;
use YayLoyalty\Engine\Storefront\RedeemCard;

defined( 'ABSPATH' ) || exit;

/**
 * Shortcode cart and checkout. Prints the points card in the totals
 * tables, rejects checkout when RedemptionService::checkout_error() says
 * so, and hands the created order to RedemptionService::commit(). The
 * chosen points never ride on the cart or checkout form: the card posts
 * them to its own endpoint (RedeemCard::on_apply()), the one write path.
 * No limit, eligibility rule or amount lives here — everything is the
 * service's answer.
 */
final class ClassicCheckoutAdapter {

    private RedemptionService $service;

    private RedeemCard $card;

    public function __construct( RedemptionService $service, RedeemCard $card ) {
        $this->service = $service;
        $this->card    = $card;
    }

    /**
     * Called once at boot; classic templates only. The card goes before the
     * order total on both pages: the hook before Shipping fires only for
     * carts that ship, so a cart of virtual products would get no card.
     */
    public function register(): void {
        add_action( 'woocommerce_cart_totals_before_order_total', [ $this, 'render_row' ] );
        add_action( 'woocommerce_review_order_before_order_total', [ $this, 'render_row' ] );
        add_action( 'woocommerce_after_checkout_validation', [ $this, 'validate' ], 10, 2 );
        add_action( 'woocommerce_checkout_order_processed', [ $this, 'on_order_processed' ], 10, 3 );
    }

    /** The card as a totals row, since both hooks fire inside a `<table>`; nothing when the card renders nothing. */
    public function render_row(): void {
        $markup = $this->card->markup();

        if ( '' === $markup ) {
            return;
        }

        echo '<tr class="yayloyalty-redeem-row"><td colspan="2">' . $markup . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- the card's template escapes every value.
    }

    /**
     * Precondition: checkout submitted, totals not yet frozen. Postcondition:
     * $errors gains one translated message when service->checkout_error()
     * returns a key, so WooCommerce aborts the submission; untouched otherwise
     * and without a cart.
     *
     * @param array $data The posted checkout fields.
     */
    public function validate( array $data, \WP_Error $errors ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- the hook's first argument; the cart and the session answer.
        $cart = RedemptionService::cart();

        if ( null === $cart ) {
            return;
        }

        $key = $this->service->checkout_error( $cart, RedemptionService::shopper_id() );

        if ( null !== $key ) {
            $errors->add( 'yayloyalty_redeem', RedemptionService::message_for( $key ) );
        }
    }

    /**
     * Precondition: the order exists with frozen totals; payment not yet
     * taken. Postcondition: service->commit( order ) ran once; a commit that
     * debited nothing for a reason other than "no points discount on this
     * order" stops the checkout, the same rule as StoreApiAdapter::on_order_processed().
     *
     * @param array $posted The posted checkout fields.
     * @throws \Exception When the points discount could not be debited: WC_Checkout::process_checkout() catches it, shows the sentence and takes no payment.
     */
    public function on_order_processed( int $order_id, array $posted, \WC_Order $order ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- the hook's first two arguments; the order is the third.
        $outcome = $this->service->commit( $order );

        if ( Outcome::SKIPPED === $outcome->get_status() && RedemptionService::REASON_NO_INTENT !== $outcome->get_reason() ) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- WooCommerce prints the message as an escaped notice.
            throw new \Exception( RedemptionService::message_for( $outcome->get_reason() ) );
        }
    }
}
