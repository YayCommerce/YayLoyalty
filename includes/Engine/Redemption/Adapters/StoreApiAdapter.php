<?php
namespace YayLoyalty\Engine\Redemption\Adapters;

use Automattic\WooCommerce\StoreApi\Exceptions\RouteException;
use Automattic\WooCommerce\StoreApi\Schemas\V1\CartSchema;
use YayLoyalty\Engine\Earning\Outcome;
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
 * is not built here.
 */
final class StoreApiAdapter {

    /** Store API namespace: `extensions.yayloyalty` on the cart response. */
    public const EXT_NAMESPACE = 'yayloyalty';

    /** Update-callback key the block posts with `{ points: int }`. */
    public const CALLBACK = 'yayloyalty-redeem';

    private RedemptionService $service;

    private SessionIntent $intent;

    public function __construct( RedemptionService $service, SessionIntent $intent ) {
        $this->service = $service;
        $this->intent  = $intent;
    }

    /** Attaches to the Store API once its blocks package has loaded. Called once at boot; a no-op without the package. */
    public function register(): void {
        if ( did_action( 'woocommerce_blocks_loaded' ) ) {
            $this->attach();

            return;
        }

        add_action( 'woocommerce_blocks_loaded', [ $this, 'attach' ] );
    }

    /** The cart extension data, the update callback, checkout validation and the commit hook. Public for the hook only. */
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
    }

    /**
     * Postcondition: the quote as numbers only — requested, applied, value,
     * currency, bound_by, reason — never the Reward or the rate snapshot
     * (merchant strategy stays server-side). Zero and empty values without a cart.
     *
     * @return array{requested: int, applied: int, value: string, currency: string, bound_by: string, reason: string}
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
