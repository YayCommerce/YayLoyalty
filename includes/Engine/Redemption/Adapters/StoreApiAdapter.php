<?php
namespace YayLoyalty\Engine\Redemption\Adapters;

use Automattic\WooCommerce\StoreApi\Exceptions\RouteException;
use Automattic\WooCommerce\StoreApi\Schemas\V1\CartSchema;
use YayLoyalty\Engine\Earning\Outcome;
use YayLoyalty\Engine\Redemption\CouponProvider;
use YayLoyalty\Engine\Redemption\RedemptionService;
use YayLoyalty\Engine\Redemption\SessionIntent;
use YayLoyalty\Engine\Register\ScriptName;
use YayLoyalty\Engine\Storefront\Launcher;
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

    /** Update-callback key the block posts with `{ points: int }`. */
    public const CALLBACK = 'yayloyalty-redeem';

    private RedemptionService $service;

    private SessionIntent $intent;

    private RedeemCard $card;

    public function __construct( RedemptionService $service, SessionIntent $intent, RedeemCard $card ) {
        $this->service = $service;
        $this->intent  = $intent;
        $this->card    = $card;
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

    /**
     * On a page drawing the Cart or Checkout block: the card's assets and the slot fill that shows it, which is
     * handed the coupon's code and the label its totals row reads on the blocks.
     */
    public function enqueue(): void {
        RedeemCard::enqueue_assets();

        wp_enqueue_script(
            ScriptName::STOREFRONT_REDEEM_BLOCKS_SCRIPT,
            YAYLOYALTY_PLUGIN_URL . 'assets/storefront/redeem-blocks.js',
            [ 'wp-element', 'wp-plugins', 'wc-blocks-checkout' ],
            Launcher::asset_version( 'assets/storefront/redeem-blocks.js' ),
            [ 'in_footer' => true ]
        );
        wp_add_inline_script(
            ScriptName::STOREFRONT_REDEEM_BLOCKS_SCRIPT,
            'window.yayloyaltyRedeemBlocks = ' . wp_json_encode(
                [
                    'code'  => CouponProvider::CODE,
                    'label' => CouponProvider::display_label(),
                ]
            ) . ';',
            'before'
        );
    }

    /**
     * Postcondition: the quote as numbers only — requested, applied, value,
     * currency, bound_by, reason — never the Reward (merchant strategy stays
     * server-side), and `card`, the points card's HTML for this cart ('' when
     * nothing renders). Zero and empty values without a cart.
     *
     * @return array{requested: int, applied: int, value: string, currency: string, bound_by: string, reason: string, card: string}
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
            'card'      => $this->card->markup(),
        ];
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
            'card'      => $property( 'string', __( 'The points card\'s HTML for this cart.', 'yayloyalty' ) ),
        ];
    }

    /**
     * Precondition: the block posted `{ points }` through the update
     * callback. Postcondition: SessionIntent::set( absint( points ) ),
     * unclamped; the Store API then recalculates and cart_data() carries the
     * clamped answer.
     *
     * @param array<string, mixed> $data
     */
    public function on_update( $data ): void {
        $this->intent->set( absint( is_array( $data ) ? ( $data['points'] ?? 0 ) : 0 ) );
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
     *
     * @throws RouteException When the points discount could not be debited.
     */
    public function on_order_processed( \WC_Order $order ): void {
        $outcome = $this->service->commit( $order );

        if ( Outcome::SKIPPED === $outcome->get_status() && RedemptionService::REASON_NO_INTENT !== $outcome->get_reason() ) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- the Store API returns the message as JSON; it is never echoed.
            throw new RouteException( 'yayloyalty_redeem_failed', RedemptionService::message_for( $outcome->get_reason() ), 409 );
        }
    }
}
