<?php
namespace YayLoyalty\Pro\Services;

use YayLoyalty\Engine\Rewards\Context;
use YayLoyalty\Engine\Rewards\RewardFacts;
use YayLoyalty\Enums\RewardConditionType;
use YayLoyalty\Models\Reward;
use YayLoyalty\Models\RewardCondition;
use YayLoyalty\Models\Tier;
use YayLoyalty\Repositories\TierRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Enforces a reward's TIER condition ("Minimum tier"): the customer's live tier must rank at or above
 * the required one. It answers the claim gate through `yayloyalty_reward_conditions_match`, and its
 * allows() serves the listings that have no quote (the launcher and My Account).
 *
 * It fails closed: tiers off, a guest, a customer without a live tier and a required tier that is no
 * longer live all refuse the reward. A reward with no TIER entry is always allowed; other condition
 * types are not enforced here. Ranks are compared, not ids, so a threshold edit that reranks the
 * tiers keeps the comparison right.
 */
final class RewardTierCondition {

    /** @var array<int, Tier|null> Required tiers by id for this request; null when not live. */
    private $tiers = [];

    public function register(): void {
        add_filter( 'yayloyalty_reward_conditions_match', [ $this, 'matches' ], 10, 3 );
    }

    /**
     * @param mixed   $matches Whether earlier subscribers let the reward through.
     * @param Reward  $reward  The reward being claimed.
     * @param Context $ctx     The claim.
     */
    public function matches( $matches, Reward $reward, Context $ctx ): bool {
        return (bool) $matches && $this->allows( $reward, $ctx->get_facts() );
    }

    /** Whether $facts meet $reward's TIER entry; true when the reward has none. */
    public function allows( Reward $reward, ?RewardFacts $facts ): bool {
        $required_id = self::required_tier_id( $reward );

        if ( null === $required_id ) {
            return true;
        }

        if ( null === $facts || 0 === $facts->get_tier_rank() || ! TierEvaluator::is_enabled() ) {
            return false;
        }

        $required = $this->live_tier( $required_id );

        return null !== $required && $facts->get_tier_rank() >= $required->get_tier_rank();
    }

    /** The id the reward's TIER entry requires, or null without one. */
    private static function required_tier_id( Reward $reward ): ?int {
        foreach ( $reward->get_conditions() as $entry ) {
            if ( ! is_array( $entry ) ) {
                continue;
            }

            $condition = RewardCondition::from_array( $entry );

            if ( RewardConditionType::TIER === $condition->get_type() ) {
                $value = $condition->get_value();

                // A malformed stored value still names a requirement, so it refuses rather than passes.
                return is_numeric( $value ) ? (int) $value : 0;
            }
        }

        return null;
    }

    private function live_tier( int $id ): ?Tier {
        if ( ! array_key_exists( $id, $this->tiers ) ) {
            $this->tiers[ $id ] = $id > 0 ? ( new TierRepository() )->find_live( $id ) : null;
        }

        return $this->tiers[ $id ];
    }
}
