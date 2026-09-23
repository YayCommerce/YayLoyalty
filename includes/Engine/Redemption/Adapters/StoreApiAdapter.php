<?php
namespace YayLoyalty\Engine\Redemption\Adapters;

use YayLoyalty\Engine\Redemption\RedemptionService;
use YayLoyalty\Engine\Redemption\SessionIntent;

defined( 'ABSPATH' ) || exit;

/**
 * Cart and Checkout blocks. Exposes the quote on the Store API cart
 * response, receives the intent through a Store API update callback,
 * rejects checkout through the cart-errors hook and hands the created
 * order to RedemptionService::commit(). The same service as the classic
 * adapter; nothing about limits, eligibility or amounts lives here. The
 * block UI itself (a checkout inner block reading `extensions.yayloyalty`
 * and posting the update callback) is a separate storefront package and
 * is not built here. Scaffold: stubs.
 */
final class StoreApiAdapter {

    /** Store API namespace: `extensions.yayloyalty` on the cart response and the update-callback name. */
    public const EXT_NAMESPACE = 'yayloyalty';

    /** Update-callback key the block posts with `{ points: int }`. */
    public const CALLBACK = 'yayloyalty-redeem';

    private RedemptionService $service;

    private SessionIntent $intent;

    public function __construct( RedemptionService $service, SessionIntent $intent ) {
        $this->service = $service;
        $this->intent  = $intent;
    }

    /**
     * Hooks, all inside `woocommerce_blocks_loaded`:
     * StoreApi::container()->get( ExtendSchema::class )->register_endpoint_data()
     * for CartSchema::IDENTIFIER with cart_data() / cart_schema(),
     * woocommerce_store_api_register_update_callback( self::CALLBACK → on_update() ),
     * `woocommerce_store_api_cart_errors` → validate(),
     * `woocommerce_store_api_checkout_order_processed` → on_order_processed().
     * Called once at boot; no-op when the blocks package is absent.
     */
    public function register(): void {
        // TODO(F08): guard on class_exists( Automattic\WooCommerce\StoreApi\StoreApi ); register the four attachments named in the docblock.
        throw new \RuntimeException( 'Not implemented' );
    }

    /**
     * Postcondition: the quote as numbers only — requested, applied, value,
     * currency, bound_by, reason — never the Reward or the rate snapshot
     * (merchant strategy stays server-side).
     *
     * @return array<string, int|float|string|null>
     */
    public function cart_data(): array {
        // TODO(F08): quote = service->quote( WC()->cart, user ); map to the six keys; denied → applied 0, value 0, reason set.
        throw new \RuntimeException( 'Not implemented' );
    }

    /**
     * The JSON schema for cart_data(), so the block can rely on the shape.
     *
     * @return array<string, array>
     */
    public function cart_schema(): array {
        // TODO(F08): one readonly property per cart_data() key with type and description.
        throw new \RuntimeException( 'Not implemented' );
    }

    /**
     * Precondition: the block posted `{ points }` through the update
     * callback. Postcondition: SessionIntent::set( absint( points ) ),
     * unclamped; the Store API then recalculates and cart_data() carries the
     * clamped answer.
     *
     * @param array<string, mixed> $data
     */
    public function on_update( array $data ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- scaffold stub.
        // TODO(F08): read the points key from the payload, absint it, and store it through SessionIntent::set.
        throw new \RuntimeException( 'Not implemented' );
    }

    /**
     * Precondition: Store API checkout validation, totals not yet frozen.
     * Postcondition: $errors gains one translated message when
     * service->checkout_error() returns a key; untouched otherwise.
     */
    public function validate( \WP_Error $errors, \WC_Cart $cart ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- scaffold stub.
        // TODO(F08): key = service->checkout_error( cart, user ); key → errors->add( 'yayloyalty_redeem', message_for( key ) ).
        throw new \RuntimeException( 'Not implemented' );
    }

    /** Precondition: the order exists with frozen totals. Postcondition: service->commit( order ) ran once. */
    public function on_order_processed( \WC_Order $order ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- scaffold stub.
        // TODO(F08): service->commit( order ).
        throw new \RuntimeException( 'Not implemented' );
    }
}
