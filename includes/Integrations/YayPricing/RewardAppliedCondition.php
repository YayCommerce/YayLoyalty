<?php
namespace YayLoyalty\Integrations\YayPricing;

use YAYDP\Condition\YAYDP_Condition_Context;
use YAYDP\Condition\YAYDP_Condition_Registry;
use YayLoyalty\Engine\Points\Context as EarnContext;

defined( 'ABSPATH' ) || exit;

/**
 * A YayPricing condition on whether the cart holds any loyalty reward at all: the points discount or any
 * catalog reward. AppliedRewardsCondition asks which ones. It extends YayPricing's base class, so only
 * YayPricingIntegration::register_conditions() names it.
 */
final class RewardAppliedCondition extends \YAYDP\Abstracts\YAYDP_Condition_Type {

    public function slug() {
        return 'yayloyalty_reward_applied';
    }

    public function label() {
        return __( 'Loyalty reward applied', 'yayloyalty' );
    }

    public function group() {
        return YAYDP_Condition_Registry::GROUP_CART;
    }

    public function comparators() {
        return [
            [
                'value' => true,
                'label' => __( 'Applied', 'yayloyalty' ),
            ],
            [
                'value' => false,
                'label' => __( 'Not applied', 'yayloyalty' ),
            ],
        ];
    }

    public function default_value() {
        return true;
    }

    /** A comparator that is not a boolean never matches. */
    public function check( array $condition, YAYDP_Condition_Context $ctx ) {
        $wants = $condition['comparation'] ?? null;

        if ( ! is_bool( $wants ) ) {
            return false;
        }

        $has = [] !== EarnContext::loyalty_codes( $ctx->applied_coupons() );

        return $wants === $has;
    }
}
