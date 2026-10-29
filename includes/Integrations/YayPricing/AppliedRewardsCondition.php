<?php
namespace YayLoyalty\Integrations\YayPricing;

use YAYDP\Condition\YAYDP_Comparators;
use YAYDP\Condition\YAYDP_Condition_Context;
use YAYDP\Condition\YAYDP_Condition_Registry;
use YayLoyalty\Engine\Points\Context as EarnContext;
use YayLoyalty\Enums\Status;
use YayLoyalty\Repositories\RewardRepository;

defined( 'ABSPATH' ) || exit;

/**
 * A YayPricing condition on which loyalty rewards the cart holds: the merchant picks rewards (the points
 * discount among them), and the rule matches when the cart uses any of them, or none of them.
 * RewardAppliedCondition asks only whether the cart holds any. A reward is
 * on the cart as a coupon under its stored code. The value is always a list: YayPricing's select editor
 * fails to render any other value. It extends YayPricing's base class, so only
 * YayPricingIntegration::register_conditions() names it.
 */
final class AppliedRewardsCondition extends \YAYDP\Abstracts\YAYDP_Condition_Type {

    /** @var RewardRepository */
    private $rewards;

    /** @var array<string, int|null> Reward id per applied code, read once per request. */
    private $ids_by_code = [];

    public function __construct( ?RewardRepository $rewards = null ) {
        $this->rewards = $rewards ?? new RewardRepository();
    }

    public function slug() {
        return 'yayloyalty_applied_rewards';
    }

    public function label() {
        return __( 'Applied loyalty rewards', 'yayloyalty' );
    }

    public function group() {
        return YAYDP_Condition_Registry::GROUP_CART;
    }

    public function comparators() {
        return YAYDP_Comparators::pick(
            [
                YAYDP_Comparators::CONTAIN     => __( 'Any of', 'yayloyalty' ),
                YAYDP_Comparators::NOT_CONTAIN => __( 'None of', 'yayloyalty' ),
            ]
        );
    }

    /** Every reward but the archived ones, in the order the Rewards page lists them. */
    public function editor() {
        $options = [];

        foreach ( $this->rewards->paginate_filtered( [ 'per_page' => 100 ] )['items'] as $reward ) {
            if ( Status::ARCHIVED === $reward->get_status() ) {
                continue;
            }

            $options[] = [
                'value' => (int) $reward->get_id(),
                'label' => $reward->get_name(),
            ];
        }

        return [
            'kind'    => 'select',
            'options' => $options,
        ];
    }

    public function default_value() {
        return [];
    }

    /**
     * An unknown comparator, or a value that is not a list, never matches. The comparator must be a string:
     * matches_contain() switches loosely, so `true` would pass as contain_all.
     */
    public function check( array $condition, YAYDP_Condition_Context $ctx ) {
        if ( ! is_string( $condition['comparation'] ?? null ) || ! is_array( $condition['value'] ?? null ) ) {
            return false;
        }

        $codes   = EarnContext::loyalty_codes( $ctx->applied_coupons() );
        $picked  = array_map( 'intval', \YAYDP\Helper\YAYDP_Helper::map_filter_value( $condition ) );
        $applied = array_intersect( $picked, array_map( [ $this, 'reward_id_of' ], $codes ) );

        return YAYDP_Comparators::matches_contain( array_values( $applied ), count( $picked ), $condition );
    }

    /** The reward holding $code, or 0 when none does. */
    private function reward_id_of( string $code ): int {
        if ( ! array_key_exists( $code, $this->ids_by_code ) ) {
            $reward = $this->rewards->find_by_coupon_code( $code );

            $this->ids_by_code[ $code ] = null === $reward ? 0 : (int) $reward->get_id();
        }

        return $this->ids_by_code[ $code ];
    }
}
