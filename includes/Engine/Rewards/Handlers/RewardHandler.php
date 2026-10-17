<?php
namespace YayLoyalty\Engine\Rewards\Handlers;

use YayLoyalty\Engine\Rewards\Context;
use YayLoyalty\Engine\Rewards\Result;
use YayLoyalty\Models\Reward;

defined( 'ABSPATH' ) || exit;

/**
 * One reward type's rules: what a claim costs and what it is worth. Unlike TriggerHandler there is no
 * is_eligible(): the customer already chose the reward, so a failed config gate is a denied() Result.
 * Never throws.
 */
interface RewardHandler {

    /** A RewardType constant. */
    public function type(): string;

    public function quote( Reward $reward, Context $ctx ): Result;

    /**
     * The virtual coupon the reward is spent as, from its config; null for a type that is not spent as a coupon.
     * Lite builds and validates the coupon (Engine\Redemption\RewardCoupons) and WooCommerce does the discount
     * maths. `max_discount` is not a coupon property: it caps the line, turning it into `fixed_cart` at the cap.
     *
     * @return array{discount_type: string, amount: float, minimum_amount: ?float, exclude_sale_items: bool, max_discount: ?float}|null
     */
    public function coupon_props( Reward $reward ): ?array;
}
