<?php
namespace YayLoyalty\Pro\Services;

use YayLoyalty\Engine\Redemption\RewardCoupons;
use YayLoyalty\Engine\Rewards\Clamp;
use YayLoyalty\Engine\Rewards\Context;
use YayLoyalty\Engine\Rewards\Handlers\RewardHandler;
use YayLoyalty\Engine\Rewards\Result;
use YayLoyalty\Enums\RewardType;
use YayLoyalty\Models\Reward;

defined( 'ABSPATH' ) || exit;

/**
 * The handler for one of Pro's discount reward types, `fixed_coupon` or `percent_coupon`, both spent as a
 * virtual coupon on the cart. Registered once per type. The quote is WooCommerce's own answer for that coupon
 * on the cart (RewardCoupons::probe()); the cost is always the reward's full `cost_points`, even when the cart
 * leaves less than the face value to take off. `coupon_expiry_days` and `currency` are not read.
 */
final class DiscountRewardHandler implements RewardHandler {

    /** RewardType::FIXED_COUPON or RewardType::PERCENT_COUPON. */
    private string $type;

    public function __construct( string $type ) {
        $this->type = $type;
    }

    public function type(): string {
        return $this->type;
    }

    /**
     * Without a cart (the commit, which checks the order's own line) the cost alone, at value 0. With one, denied
     * with a RewardCoupons::REASON_* when the coupon cannot go on it, else what the coupon takes off, at most
     * `max_discount`.
     */
    public function quote( Reward $reward, Context $ctx ): Result {
        $cost = (int) $reward->get_cost_points();
        $cart = $ctx->get_cart();

        if ( null === $cart ) {
            return Result::allowed( $reward, $cost, $cost, 0.0, $ctx->get_currency(), 0.0 );
        }

        // The config is in the base currency; the cart is in the one it is charged in.
        $props = RewardCoupons::in_currency( $this->coupon_props( $reward ), $ctx->get_currency_rate() );
        $probe = RewardCoupons::probe( $reward->get_coupon_code(), $props, $cart );

        if ( '' !== $probe['reason'] ) {
            return Result::denied( $probe['reason'] );
        }

        // The cap lowers the dry run's value, and says so, so the card can tell the shopper.
        $is_capped = null !== $props['max_discount'] && $probe['value'] > $props['max_discount'];
        $value     = $is_capped ? (float) $props['max_discount'] : $probe['value'];

        return Result::allowed( $reward, $cost, $cost, $value, $ctx->get_currency(), 0.0, $is_capped ? Clamp::BOUND_REWARD_MAX : Clamp::BOUND_NONE );
    }

    /**
     * `fixed_coupon`: `config.amount` off the cart, as `fixed_cart`. `percent_coupon`: `config.percent` off, as
     * `percent`, capped at `config.max_discount` when set. Both take `min_order` and `exclude_sale_items`.
     */
    public function coupon_props( Reward $reward ): array {
        $config     = $reward->get_config();
        $is_percent = RewardType::PERCENT_COUPON === $this->type;

        return [
            'discount_type'      => $is_percent ? 'percent' : 'fixed_cart',
            'amount'             => (float) ( $is_percent ? ( $config['percent'] ?? 0 ) : ( $config['amount'] ?? 0 ) ),
            'minimum_amount'     => isset( $config['min_order'] ) ? (float) $config['min_order'] : null,
            'exclude_sale_items' => ! empty( $config['exclude_sale_items'] ),
            'max_discount'       => $is_percent && isset( $config['max_discount'] ) ? (float) $config['max_discount'] : null,
        ];
    }
}
