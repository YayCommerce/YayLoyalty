<?php
namespace YayLoyalty\Engine\Redemption\Adapters;

use YayLoyalty\Engine\Redemption\RedemptionService;
use YayLoyalty\Engine\Redemption\SessionIntent;

defined( 'ABSPATH' ) || exit;

/**
 * Shortcode cart and checkout. Renders the control from a quote, turns a
 * form post into SessionIntent::set(), rejects checkout when
 * RedemptionService::checkout_error() says so, and hands the created order
 * to RedemptionService::commit(). No limit, eligibility rule or amount
 * lives here — everything is the service's answer. Scaffold: stubs.
 */
final class ClassicCheckoutAdapter {

    /** Form field carrying the requested points. */
    public const FIELD = 'yayloyalty_redeem_points';

    private RedemptionService $service;

    private SessionIntent $intent;

    public function __construct( RedemptionService $service, SessionIntent $intent ) {
        $this->service = $service;
        $this->intent  = $intent;
    }

    /**
     * Hooks: `woocommerce_cart_totals_before_order_total` and
     * `woocommerce_review_order_before_order_total` → render(),
     * `woocommerce_update_cart_action_cart_updated` and
     * `woocommerce_checkout_update_order_review` → read_intent(),
     * `woocommerce_after_checkout_validation` → validate(),
     * `woocommerce_checkout_order_processed` → on_order_processed().
     * Called once at boot; classic templates only.
     */
    public function register(): void {
        // TODO(redemption): add_action / add_filter for the six hooks named in the docblock.
        throw new \RuntimeException( 'Not implemented' );
    }

    /** Renders the slider/input row from service->quote(): requested, applied, value, and the bound_by explanation; nothing when the quote is denied. Escaped output only. */
    public function render(): void {
        // TODO(redemption): quote = service->quote( WC()->cart, current user or null ); denied → return; echo the escaped row template with data-* for requested/applied/bound_by.
        throw new \RuntimeException( 'Not implemented' );
    }

    /**
     * Precondition: a cart-update or order-review post carrying self::FIELD
     * (nonce-checked by WooCommerce's own form handling). Postcondition:
     * SessionIntent::set( (int) field ) — unclamped; the clamp runs on the
     * next quote. Absent field → no change. Returns its first argument
     * untouched because `woocommerce_update_cart_action_cart_updated` is a
     * filter (the order-review hook is an action and ignores the return).
     *
     * @param mixed $passthrough The cart-updated flag, or the order-review post string.
     * @return mixed $passthrough, unchanged.
     */
    public function read_intent( $passthrough = null ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- scaffold stub.
        // TODO(redemption): read the field from the posted form (parse_str for the order-review string, the request for cart update), absint it, store it through SessionIntent::set, and return the argument unchanged.
        throw new \RuntimeException( 'Not implemented' );
    }

    /**
     * Precondition: checkout submitted, totals not yet frozen. Postcondition:
     * $errors gains one translated message when service->checkout_error()
     * returns a key, so WooCommerce aborts the submission; untouched otherwise.
     *
     * @param array $data The posted checkout fields.
     */
    public function validate( array $data, \WP_Error $errors ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- scaffold stub.
        // TODO(redemption): key = service->checkout_error( WC()->cart, user ); key → errors->add( 'yayloyalty_redeem', message_for( key ) ).
        throw new \RuntimeException( 'Not implemented' );
    }

    /**
     * Precondition: the order exists with frozen totals; payment not yet
     * taken. Postcondition: service->commit( order ) ran once; its Outcome
     * is not acted on here (the service already noted a skip on the order).
     *
     * @param array $posted The posted checkout fields.
     */
    public function on_order_processed( int $order_id, array $posted, \WC_Order $order ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- scaffold stub.
        // TODO(redemption): service->commit( order ).
        throw new \RuntimeException( 'Not implemented' );
    }
}
