<?php
namespace YayLoyalty\Pro\Services;

use YayLoyalty\Engine\Points\ContextFactory as EarnContextFactory;
use YayLoyalty\Engine\Rewards\Context;
use YayLoyalty\Enums\RewardConditionType;
use YayLoyalty\Models\Reward;
use YayLoyalty\Models\RewardCondition;
use YayLoyalty\Models\RewardConditionSet;
use YayLoyalty\Utils\ConditionGroups;

defined( 'ABSPATH' ) || exit;

/**
 * Decides whether a shopper may redeem a reward under its `conditions`. Attached to
 * `yayloyalty_reward_conditions_match`, which RewardGate applies on every quote and commit.
 *
 * Lite has no subscriber and lets every shopper through. Here a condition fails when its input is
 * missing (a guest, no facts) or its type cannot be evaluated (tier, a typo). Customer facts are read
 * through the Context's loader only when a lifetime_points condition runs.
 */
final class RewardConditionsEvaluator {

    /**
     * @param bool    $matches What earlier subscribers decided; a false stays false.
     * @param Reward  $reward
     * @param Context $ctx
     */
    public function matches( $matches, Reward $reward, Context $ctx ): bool {
        if ( ! $matches ) {
            return false;
        }

        if ( ! $reward->has_conditions() ) {
            return true;
        }

        $set      = RewardConditionSet::from_array( $reward->get_conditions() );
        $roles    = null;
        $verdicts = [];

        foreach ( $set->groups() as $group ) {
            if ( [] === $group['conditions'] ) {
                continue;
            }

            $results = [];

            foreach ( $group['conditions'] as $condition ) {
                $results[] = $this->evaluate_one( $condition, $ctx, $roles );
            }

            $verdicts[] = ConditionGroups::fold( $group['match'], $results );
        }

        return ConditionGroups::fold( $set->match(), $verdicts );
    }

    /**
     * @param string[]|null $roles The customer's roles, read on the first user_role condition.
     */
    private function evaluate_one( RewardCondition $condition, Context $ctx, ?array &$roles ): bool {
        $user_id  = $ctx->get_user_id();
        $operator = $condition->get_operator();
        $value    = $condition->get_value();

        if ( null === $user_id ) {
            return false;
        }

        switch ( $condition->get_type() ) {
            case RewardConditionType::USER_ROLE:
                if ( null === $roles ) {
                    $roles = EarnContextFactory::user_roles( $user_id );
                }

                return $this->in_set( $roles, $operator, $value );

            case RewardConditionType::CUSTOMER_IDS:
                return $this->in_set( [ $user_id ], $operator, $value );

            case RewardConditionType::LIFETIME_POINTS:
                $facts = $ctx->get_facts();

                return null !== $facts && $this->in_range( (float) $facts->get_lifetime_earned(), $operator, $value );
        }

        return false;
    }

    /** `in` holds when the customer's values and the condition's share one; `not_in` when they share none. */
    private function in_set( array $actual, string $operator, $value ): bool {
        $wanted     = array_map( 'strval', array_filter( is_array( $value ) ? $value : [ $value ], 'is_scalar' ) );
        $intersects = [] !== array_intersect( $wanted, array_map( 'strval', $actual ) );

        switch ( $operator ) {
            case RewardConditionType::OP_IN:
                return $intersects;
            case RewardConditionType::OP_NOT_IN:
                return ! $intersects;
        }

        return false;
    }

    /** A number against one bound, or an inclusive [low, high] for `between`. */
    private function in_range( float $actual, string $operator, $value ): bool {
        if ( RewardConditionType::OP_BETWEEN === $operator ) {
            return is_array( $value ) && isset( $value[0], $value[1] ) && is_numeric( $value[0] ) && is_numeric( $value[1] )
                && $actual >= (float) $value[0] && $actual <= (float) $value[1];
        }

        if ( ! is_numeric( $value ) ) {
            return false;
        }

        $expected = (float) $value;

        switch ( $operator ) {
            case RewardConditionType::OP_EQ:
                return abs( $actual - $expected ) < 0.00001;
            case RewardConditionType::OP_GT:
                return $actual > $expected;
            case RewardConditionType::OP_GTE:
                return $actual >= $expected;
            case RewardConditionType::OP_LT:
                return $actual < $expected;
            case RewardConditionType::OP_LTE:
                return $actual <= $expected;
        }

        return false;
    }
}
