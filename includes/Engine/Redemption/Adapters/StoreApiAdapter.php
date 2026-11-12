<?php
namespace YayLoyalty\Engine\Redemption\Adapters;

use Automattic\WooCommerce\StoreApi\Exceptions\RouteException;
use Automattic\WooCommerce\StoreApi\Schemas\V1\CartSchema;
use YayLoyalty\Engine\Redemption\PayForOrderRedebit;
use YayLoyalty\Engine\Redemption\RedemptionService;
use YayLoyalty\Engine\Redemption\SessionIntent;
use YayLoyalty\Engine\Storefront\RedeemCard;

defined( 'ABSPATH' ) || exit;

/**
 * Cart and Checkout blocks. Exposes the quote and the points card's HTML
 * on the Store API cart response, receives the intent through a Store API
 * update callback, rejects checkout through the cart-errors hook and hands
 * the created order to RedemptionService::commit(). The same service as
 * the classic adapter; nothing about limits, eligibility or amounts lives
 * here. The block UI is a slot fill (assets/storefront/redeem-blocks.js)
 * that shows `extensions.yayloyalty.card`, the same server-rendered card
 * the classic pages print, and posts the update callback.
 */
final class StoreApiAdapter {

    /** Store API namespace: `extensions.yayloyalty` on the cart response. */
    public const EXT_NAMESPACE = 'yayloyalty';

    /** Update-callback key the block posts with `{ points: int }` or `{ rewards: int[] }`. */
    public const CALLBACK = 'yayloyalty-redeem';

    private RedemptionService $service;

    private SessionIntent $intent;

    private RedeemCard $card;

    /** The block pay-for-order fires the same order-processed hook: an order whose payment failed is debited again first. */
    private PayForOrderRedebit $redebit;

    public function __construct( RedemptionService $service, SessionIntent $intent, RedeemCard $card, PayForOrderRedebit $redebit ) {
        $this->service = $service;
        $this->intent  = $intent;
        $this->card    = $card;
        $this->redebit = $redebit;
    }

    /** Attaches to the Store API once its blocks package has loaded. Called once at boot; a no-op without the package. */
    public function register(): void {
        if ( did_action( 'woocommerce_blocks_loaded' ) ) {
            $this->attach();

            return;
        }

        add_action( 'woocommerce_blocks_loaded', [ $this, 'attach' ] );
    }

    /** The cart extension data, the update callback, checkout validation, the commit hook and the blocks' scripts. Public for the hook only. */
    public function attach(): void {
        if ( ! function_exists( 'woocommerce_store_api_register_endpoint_data' ) ) {
            return;
        }

        woocommerce_store_api_register_endpoint_data(
            [
                'endpoint'        => CartSchema::IDENTIFIER,
                'namespace'       => self::EXT_NAMESPACE,
                'data_callback'   => [ $this, 'cart_data' ],
                'schema_callback' => [ $this, 'cart_schema' ],
                'schema_type'     => ARRAY_A,
            ]
        );

        woocommerce_store_api_register_update_callback(
            [
                'namespace' => self::CALLBACK,
                'callback'  => [ $this, 'on_update' ],
            ]
        );

        add_action( 'woocommerce_store_api_cart_errors', [ $this, 'validate' ], 10, 2 );
        add_action( 'woocommerce_store_api_checkout_order_processed', [ $this, 'on_order_processed' ] );
        add_action( 'woocommerce_blocks_enqueue_cart_block_scripts_after', [ $this, 'enqueue' ] );
        add_action( 'woocommerce_blocks_enqueue_checkout_block_scripts_after', [ $this, 'enqueue' ] );
    }

    /** On a page drawing the Cart or Checkout block: the card's assets and the slot fill that shows it. */
    public function enqueue(): void {
        RedeemCard::enqueue_block_assets( $this->service->live_flexible_reward() );
    }

    /**
     * Postcondition: the quote as numbers only — requested, applied, value,
     * currency, bound_by, reason — never the Reward (merchant strategy stays
     * server-side), `rewards`, the reward coupons on the cart, and `card`, the
     * points card's HTML for this cart ('' when nothing renders). Zero and
     * empty values without a cart.
     *
     * @return array{requested: int, applied: int, value: string, currency: string, bound_by: string, reason: string, rewards: array, card: string}
     */
    public function cart_data(): array {
        $cart     = RedemptionService::cart();
        $decimals = wc_get_price_decimals();

        if ( null === $cart ) {
            return [
                'requested' => 0,
                'applied'   => 0,
                'value'     => wc_format_decimal( 0, $decimals ),
                'currency'  => get_woocommerce_currency(),
                'bound_by'  => '',
                'reason'    => '',
                'rewards'   => [],
                'card'      => '',
            ];
        }

        $quote = $this->service->quote( $cart, RedemptionService::shopper_id() );

        return [
            // A denied quote carries no request, so the intent itself answers.
            'requested' => $this->intent->get(),
            'applied'   => $quote->get_points(),
            'value'     => wc_format_decimal( $quote->get_value(), $decimals ),
            'currency'  => null !== $quote->get_currency() ? $quote->get_currency() : get_woocommerce_currency(),
            'bound_by'  => $quote->get_bound_by(),
            'reason'    => $quote->get_reason(),
            'rewards'   => $this->applied_rewards( $cart, $decimals ),
            'card'      => $this->card->markup(),
        ];
    }

    /**
     * One entry per reward coupon on the cart: the reward's id, its code, its name as the totals row's label,
     * its cost, and the line's discount on the basis prices were entered.
     *
     * @return array<int, array{id: int, code: string, label: string, points: int, value: string}>
     */
    private function applied_rewards( \WC_Cart $cart, int $decimals ): array {
        $rewards = [];

        foreach ( $this->service->applied_rewards( $cart ) as $code => $reward ) {
            $rewards[] = [
                'id'     => (int) $reward->get_id(),
                'code'   => $code,
                'label'  => $reward->get_name(),
                'points' => (int) $reward->get_cost_points(),
                'value'  => wc_format_decimal( $cart->get_coupon_discount_amount( $code, ! wc_prices_include_tax() ), $decimals ),
            ];
        }

        return $rewards;
    }

    /**
     * The JSON schema for cart_data(), so the block can rely on the shape.
     *
     * @return array<string, array>
     */
    public function cart_schema(): array {
        $property = static function ( string $type, string $description ): array {
            return [
                'description' => $description,
                'type'        => $type,
                'context'     => [ 'view', 'edit' ],
                'readonly'    => true,
            ];
        };

        return [
            'requested' => $property( 'integer', __( 'Points the customer asked to spend.', 'yayloyalty' ) ),
            'applied'   => $property( 'integer', __( 'Points the cart takes after every limit.', 'yayloyalty' ) ),
            'value'     => $property( 'string', __( 'Discount the applied points buy, as a decimal string.', 'yayloyalty' ) ),
            'currency'  => $property( 'string', __( 'Currency code of the discount.', 'yayloyalty' ) ),
            'bound_by'  => $property( 'string', __( 'The limit that reduced the request, or empty.', 'yayloyalty' ) ),
            'reason'    => $property( 'string', __( 'Why no points can be spent, or empty.', 'yayloyalty' ) ),
            'rewards'   => $property( 'array', __( 'The rewards applied to the cart, one discount line each.', 'yayloyalty' ) ) + [
                'items' => [
                    'type'       => 'object',
                    'properties' => [
                        'id'     => [ 'type' => 'integer' ],
                        'code'   => [ 'type' => 'string' ],
                        'label'  => [ 'type' => 'string' ],
                        'points' => [ 'type' => 'integer' ],
                        'value'  => [ 'type' => 'string' ],
                    ],
                ],
            ],
            'card'      => $property( 'string', __( 'The points card\'s HTML for this cart.', 'yayloyalty' ) ),
        ];
    }

    /**
     * Precondition: the block posted `{ points }`, `{ rewards }` or both
     * through the update callback. Postcondition: SessionIntent::apply() with
     * whichever key is present; the Store API then recalculates and
     * cart_data() carries the answer.
     *
     * @param array<string, mixed> $data
     */
    public function on_update( $data ): void {
        $data = is_array( $data ) ? $data : [];

        $this->intent->apply(
            array_key_exists( 'rewards', $data ) ? array_map( 'absint', (array) $data['rewards'] ) : null,
            array_key_exists( 'points', $data ) ? absint( $data['points'] ) : null
        );
    }

    /**
     * Precondition: Store API cart validation, totals not yet frozen.
     * Postcondition: $errors gains one translated message when
     * service->checkout_error() returns a key; untouched otherwise.
     */
    public function validate( \WP_Error $errors, \WC_Cart $cart ): void {
        $key = $this->service->checkout_error( $cart, RedemptionService::shopper_id() );

        if ( null !== $key ) {
            $errors->add( 'yayloyalty_redeem_' . $key, RedemptionService::message_for( $key ) );
        }
    }

    /**
     * Precondition: the order exists with frozen totals. Postcondition: service->commit( order ) ran once;
     * a commit that debited nothing for a reason other than "no points discount on this order" stops the checkout.
     * On the block pay-for-order, an order whose payment failed is first debited its redeemed points again,
     * and a balance that no longer covers them stops the payment.
     *
     * @throws RouteException When the points discount could not be debited.
     */
    public function on_order_processed( \WC_Order $order ): void {
        $message = $this->redebit->redebit_or_message( $order );

        if ( null !== $message ) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- the Store API returns the message as JSON; it is never echoed.
            throw new RouteException( 'yayloyalty_redeem_failed', $message, 409 );
        }

        $key = RedemptionService::commit_error( $this->service->commit( $order ) );

        if ( null !== $key ) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- the Store API returns the message as JSON; it is never echoed.
            throw new RouteException( 'yayloyalty_redeem_failed', RedemptionService::message_for( $key ), 409 );
        }
    }
}
