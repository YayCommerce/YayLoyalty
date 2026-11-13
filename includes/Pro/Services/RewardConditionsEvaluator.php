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

                return self::in_set( $roles, $operator, $value );

            case RewardConditionType::CUSTOMER_IDS:
                return self::in_set( [ $user_id ], $operator, $value );

            case RewardConditionType::LIFETIME_POINTS:
                $facts = $ctx->get_facts();

                return null !== $facts && true === ConditionCompare::numeric( (float) $facts->get_lifetime_earned(), $operator, $value );
        }

        return false;
    }

    /** `in` holds when the values share one; `not_in` when they share none. */
    private static function in_set( array $actual, string $operator, $value ): bool {
        if ( RewardConditionType::OP_IN === $operator ) {
            return ConditionCompare::intersects( $actual, $value );
        }

        return RewardConditionType::OP_NOT_IN === $operator && ! ConditionCompare::intersects( $actual, $value );
    }
}
