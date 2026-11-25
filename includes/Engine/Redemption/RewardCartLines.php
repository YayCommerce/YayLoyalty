<?php
namespace YayLoyalty\Engine\Redemption;

use YayLoyalty\Models\Reward;

defined( 'ABSPATH' ) || exit;

/**
 * The cart line of a reward whose coupon props carry `free_item`: added at 0 while the reward's coupon is applied,
 * removed as soon as it is not, so the coupon stays the only sign the reward is in use. The line keeps its quantity
 * and has no remove link; removing it anyway takes the reward off. On the order, the line records its reward and
 * the unit price it was worth, which order_value() reads at commit.
 */
final class RewardCartLines {

    /** Cart item data key: the reward id the line was added for. */
    public const ITEM_KEY = 'yayloyalty_reward_id';

    /** Order item meta: the reward id, and the unit price on the basis prices were entered. */
    public const META_REWARD = '_yayloyalty_reward_id';
    public const META_PRICE  = '_yayloyalty_original_price';

    private RedemptionService $service;

    private SessionIntent $intent;

    /** True while reconcile() edits the cart, so its own add_to_cart() never re-enters it. */
    private bool $is_reconciling = false;

    public function __construct( RedemptionService $service, SessionIntent $intent ) {
        $this->service = $service;
        $this->intent  = $intent;
    }

    public function register(): void {
        add_action( CouponProvider::SYNCED_ACTION, [ $this, 'reconcile' ] );
        add_filter( 'woocommerce_get_cart_item_from_session', [ $this, 'set_free_price' ] );
        add_filter( 'woocommerce_cart_item_remove_link', [ $this, 'lock_remove_link' ], 10, 2 );
        add_filter( 'woocommerce_cart_item_quantity', [ $this, 'lock_quantity' ], 10, 3 );
        add_filter( 'woocommerce_store_api_product_quantity_editable', [ $this, 'lock_block_quantity' ], 10, 3 );
        add_filter( 'woocommerce_get_item_data', [ $this, 'item_data' ], 10, 2 );
        add_action( 'woocommerce_cart_item_removed', [ $this, 'on_line_removed' ], 10, 2 );
        add_action( 'woocommerce_checkout_create_order_line_item', [ $this, 'copy_to_order_item' ], 10, 3 );
        add_filter( 'woocommerce_hidden_order_itemmeta', [ $this, 'hide_meta' ] );
    }

    /**
     * Keeps the free line's meta off the order screen.
     *
     * @param string[] $keys
     * @return string[]
     */
    public function hide_meta( $keys ): array {
        return array_merge( (array) $keys, [ self::META_REWARD, self::META_PRICE ] );
    }

    /**
     * One line per applied reward with `free_item`, at 0 and at the reward's quantity; every other reward line is
     * dropped. A line that can't be added (out of stock since the quote) takes the reward's coupon off this pass and
     * the reward out of the session, so the order never pays for an item it doesn't carry.
     */
    public function reconcile( \WC_Cart $cart ): void {
        if ( $this->is_reconciling ) {
            return;
        }

        $this->is_reconciling = true;

        try {
            $wanted = $this->wanted_items( $cart );
            $kept   = [];
            $lines  = $cart->get_cart_contents();

            foreach ( $lines as $key => $line ) {
                $reward_id = (int) ( $line[ self::ITEM_KEY ] ?? 0 );

                if ( $reward_id <= 0 ) {
                    continue;
                }

                if ( ! isset( $wanted[ $reward_id ] ) || isset( $kept[ $reward_id ] ) || ! self::is_line_of( $line, $wanted[ $reward_id ]['item'] ) ) {
                    unset( $lines[ $key ] );
                    continue;
                }

                $kept[ $reward_id ]        = true;
                $lines[ $key ]['quantity'] = self::quantity_of( $line['data'], $wanted[ $reward_id ]['item']['qty'] );
                $lines[ $key ]             = $this->set_free_price( $lines[ $key ] );
            }

            if ( $lines !== $cart->get_cart_contents() ) {
                $cart->set_cart_contents( $lines );
            }

            foreach ( array_diff_key( $wanted, $kept ) as $reward_id => $entry ) {
                if ( ! $this->add_line( $cart, (int) $reward_id, $entry['item'] ) ) {
                    $this->intent->remove_reward( (int) $reward_id );
                    CouponProvider::notice_dropped( $entry['name'] );
                    $cart->set_applied_coupons( array_values( array_diff( $cart->get_applied_coupons(), [ $entry['code'] ] ) ) );
                }
            }
        } finally {
            $this->is_reconciling = false;
        }
    }

    /**
     * Prices a reward line at 0; any other line unchanged. The product object is rebuilt from the session on every
     * request, so the price is set again each time.
     *
     * @param array $line A cart item.
     * @return array
     */
    public function set_free_price( $line ) {
        if ( self::is_reward_line( $line ) && isset( $line['data'] ) && $line['data'] instanceof \WC_Product ) {
            $line['data']->set_price( 0 );
        }

        return $line;
    }

    /**
     * No remove link on a reward line: the shopper takes the reward off on the card or its coupon row.
     *
     * @param string $link
     * @param string $key
     */
    public function lock_remove_link( $link, $key ) {
        return self::is_reward_line( WC()->cart->get_cart_item( (string) $key ) ) ? '' : $link;
    }

    /**
     * A reward line's quantity as plain text, posted back unchanged, as WooCommerce prints a sold-individually item.
     *
     * @param string $html
     * @param string $key
     * @param array  $line
     */
    public function lock_quantity( $html, $key, $line ) {
        if ( ! self::is_reward_line( $line ) ) {
            return $html;
        }

        return sprintf( '%1$d <input type="hidden" name="cart[%2$s][qty]" value="%1$d" />', (int) $line['quantity'], esc_attr( (string) $key ) );
    }

    /**
     * No quantity selector for a reward line in the cart and checkout blocks.
     *
     * @param mixed       $editable
     * @param \WC_Product $product
     * @param array|null  $line
     */
    public function lock_block_quantity( $editable, $product, $line = null ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- the hook's second argument; the cart item answers.
        return self::is_reward_line( $line ) ? false : $editable;
    }

    /**
     * "Reward: <reward name>" under a reward line, on the classic and block cart and checkout.
     *
     * @param array $data
     * @param array $line
     * @return array
     */
    public function item_data( $data, $line ) {
        if ( ! self::is_reward_line( $line ) ) {
            return $data;
        }

        $reward = $this->applied_reward( (int) $line[ self::ITEM_KEY ] );

        if ( null !== $reward ) {
            $data[] = [
                'key'   => __( 'Reward', 'yayloyalty' ),
                'value' => $reward->get_name(),
            ];
        }

        return $data;
    }

    /**
     * The shopper removed a reward line anyway (the block cart's remove link): the reward leaves the session, so the
     * next totals run takes its coupon off too.
     *
     * @param string   $key
     * @param \WC_Cart $cart
     */
    public function on_line_removed( $key, $cart ): void {
        $line = $cart instanceof \WC_Cart ? ( $cart->removed_cart_contents[ $key ] ?? null ) : null;

        if ( ! $this->is_reconciling && self::is_reward_line( $line ) ) {
            $this->intent->remove_reward( (int) $line[ self::ITEM_KEY ] );
        }
    }

    /**
     * Records the reward and the unit price the line was worth on the order item, read from the product as the shop
     * sells it now, since the cart's copy is at 0.
     *
     * @param \WC_Order_Item_Product $item
     * @param string                 $key
     * @param array                  $line
     */
    public function copy_to_order_item( $item, $key, $line ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- the hook's second argument; the cart item answers.
        if ( ! self::is_reward_line( $line ) || ! $item instanceof \WC_Order_Item_Product ) {
            return;
        }

        $product = wc_get_product( $line['data']->get_id() );

        $item->add_meta_data( self::META_REWARD, (int) $line[ self::ITEM_KEY ], true );
        $item->add_meta_data( self::META_PRICE, $product instanceof \WC_Product ? (string) $product->get_price() : '0', true );
    }

    /** What the reward's lines on $order were worth: each one's recorded unit price × its quantity. 0 without a line. */
    public static function order_value( \WC_Order $order, int $reward_id ): float {
        $value = 0.0;

        foreach ( $order->get_items() as $item ) {
            if ( $item instanceof \WC_Order_Item_Product && $reward_id === (int) $item->get_meta( self::META_REWARD ) ) {
                $value += (float) $item->get_meta( self::META_PRICE ) * (float) $item->get_quantity();
            }
        }

        return $value;
    }

    /**
     * Whether a cart item is a line a reward added.
     *
     * @param mixed $line
     */
    public static function is_reward_line( $line ): bool {
        return is_array( $line ) && ! empty( $line[ self::ITEM_KEY ] );
    }

    /**
     * Each applied reward whose props carry `free_item`, keyed by reward id, with its code.
     *
     * @return array<int, array{code: string, name: string, item: array{product_id: int, variation_id: ?int, qty: int}}>
     */
    private function wanted_items( \WC_Cart $cart ): array {
        $wanted = [];

        foreach ( $this->service->applied_rewards( $cart ) as $code => $reward ) {
            $props = $this->service->coupon_props_of( $reward );

            if ( isset( $props['free_item'] ) ) {
                $wanted[ (int) $reward->get_id() ] = [
                    'code' => (string) $code,
                    'name' => $reward->get_name(),
                    'item' => $props['free_item'],
                ];
            }
        }

        return $wanted;
    }

    /**
     * Adds the line at 0. WooCommerce's own error notice for a line it refuses is taken back: the shopper never asked
     * for the item, and the caller says the reward was removed instead.
     *
     * @param array{product_id: int, variation_id: ?int, qty: int} $item
     */
    private function add_line( \WC_Cart $cart, int $reward_id, array $item ): bool {
        $variation_id = (int) ( $item['variation_id'] ?? 0 );
        $notices      = wc_get_notices();
        // add_to_cart() recalculates the totals by default; this runs inside a totals run, which goes on with the line.
        $recalculates = false !== has_action( 'woocommerce_add_to_cart', [ $cart, 'calculate_totals' ] );
        remove_action( 'woocommerce_add_to_cart', [ $cart, 'calculate_totals' ], 20 );

        $key = $cart->add_to_cart(
            (int) $item['product_id'],
            (int) $item['qty'],
            $variation_id,
            $variation_id > 0 ? wc_get_product_variation_attributes( $variation_id ) : [],
            [ self::ITEM_KEY => $reward_id ]
        );

        if ( $recalculates ) {
            add_action( 'woocommerce_add_to_cart', [ $cart, 'calculate_totals' ], 20, 0 );
        }

        if ( ! is_string( $key ) || '' === $key ) {
            wc_set_notices( $notices );

            return false;
        }

        $this->set_free_price( $cart->get_cart_item( $key ) );

        return true;
    }

    /**
     * Whether a reward line still holds the product the reward gives now; a merchant may have changed it.
     *
     * @param array                                                $line
     * @param array{product_id: int, variation_id: ?int, qty: int} $item
     */
    private static function is_line_of( array $line, array $item ): bool {
        return (int) ( $line['product_id'] ?? 0 ) === (int) $item['product_id'] && (int) ( $line['variation_id'] ?? 0 ) === (int) ( $item['variation_id'] ?? 0 );
    }

    /** The reward's quantity, or 1 for a product sold individually, as add_to_cart() leaves it. */
    public static function quantity_of( $product, int $qty ): int {
        return $product instanceof \WC_Product && $product->is_sold_individually() ? 1 : $qty;
    }

    private function applied_reward( int $reward_id ): ?Reward {
        $cart = RedemptionService::cart();

        foreach ( null === $cart ? [] : $this->service->applied_rewards( $cart ) as $reward ) {
            if ( $reward_id === (int) $reward->get_id() ) {
                return $reward;
            }
        }

        return null;
    }
}
