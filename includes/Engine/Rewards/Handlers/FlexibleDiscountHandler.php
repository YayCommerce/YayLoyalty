<?php
namespace YayLoyalty\Engine\Rewards\Handlers;

use YayLoyalty\Engine\Rewards\Context;
use YayLoyalty\Engine\Rewards\Result;
use YayLoyalty\Enums\RewardType;
use YayLoyalty\Models\Reward;

defined( 'ABSPATH' ) || exit;

/**
 * Points → cart discount at the customer's chosen amount. quote() must, in
 * order: reject a null points_requested; check config `min_points`,
 * `increment`, `max_points_per_order`, `max_percent_of_subtotal` (each a
 * denied() with its own reason); then value = points / points_per_currency_unit
 * rounded through Utils\PointsMath, rate_snapshot = points_per_currency_unit.
 *
 * Scaffold: denies `not_implemented`.
 */
final class FlexibleDiscountHandler implements RewardHandler {

    public function type(): string {
        return RewardType::FLEXIBLE_DISCOUNT;
    }

    public function quote( Reward $reward, Context $ctx ): Result {
        return Result::denied( Result::REASON_NOT_IMPLEMENTED );
    }
}
