<?php
namespace YayLoyalty\Integrations\YayPricing;

use YAYDP\Condition\YAYDP_Condition_Context;
use YAYDP\Condition\YAYDP_Condition_Registry;
use YayLoyalty\Engine\Points\Context as EarnContext;

defined( 'ABSPATH' ) || exit;

/**
 * A YayPricing condition on whether the cart holds a loyalty discount: the points slider's coupon or any
 * reward's. It extends YayPricing's base class, so only YayPricingIntegration::register_conditions() names it.
 */
final class RewardAppliedCondition extends \YAYDP\Abstracts\YAYDP_Condition_Type {

    /** @var LoyaltyCouponRerun Told on every check, so a run that asked re-runs once the coupons settle. */
    private $rerun;

    public function __construct( LoyaltyCouponRerun $rerun ) {
        $this->rerun = $rerun;
    }

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

    public function check( array $condition, YAYDP_Condition_Context $ctx ) {
        $this->rerun->mark_asked();

        $has   = [] !== EarnContext::loyalty_codes( $ctx->applied_coupons() );
        $wants = (bool) ( $condition['comparation'] ?? true );

        return $wants === $has;
    }
}
