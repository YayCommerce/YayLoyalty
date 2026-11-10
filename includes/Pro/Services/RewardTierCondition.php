<?php
namespace YayLoyalty\Pro\Services;

use YayLoyalty\Engine\Rewards\Context;
use YayLoyalty\Engine\Rewards\RewardFacts;
use YayLoyalty\Enums\RewardConditionType;
use YayLoyalty\Models\Reward;
use YayLoyalty\Models\RewardConditionSet;
use YayLoyalty\Models\Tier;
use YayLoyalty\Repositories\TierRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Enforces a reward's TIER condition. "Minimum tier" (`gte`) needs the customer's live tier to rank at
 * or above the required one; "Only this tier" (`eq`) needs it to be that tier. It answers the claim
 * gate through `yayloyalty_reward_conditions_match`, and its allows() serves the listings that have no
 * quote (the launcher and My Account).
 *
 * It fails closed: tiers off, a guest, a customer without a live tier, a required tier that is no
 * longer live and an unknown operator all refuse the reward. A reward with no TIER entry is always
 * allowed; other condition types are not enforced here. `gte` compares ranks, not ids, so a threshold
 * edit that reranks the tiers keeps the comparison right; `eq` compares ids, which a rerank leaves alone.
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
        $entry = self::tier_entry( $reward );

        if ( null === $entry ) {
            return true;
        }

        if ( null === $facts || 0 === $facts->get_tier_rank() || ! TierEvaluator::is_enabled() ) {
            return false;
        }

        $required = $this->live_tier( $entry['id'] );

        if ( null === $required ) {
            return false;
        }

        if ( RewardConditionType::OP_GTE === $entry['operator'] ) {
            return $facts->get_tier_rank() >= $required->get_tier_rank();
        }

        if ( RewardConditionType::OP_EQ === $entry['operator'] ) {
            return $facts->get_tier_id() === $required->get_id();
        }

        return false;
    }

    /**
     * The reward's TIER entry as its operator and required tier id, or null without one.
     *
     * @return array{operator: string, id: int}|null
     */
    private static function tier_entry( Reward $reward ): ?array {
        $condition = RewardConditionSet::from_array( $reward->get_conditions() )->find( RewardConditionType::TIER );

        if ( null === $condition ) {
            return null;
        }

        $value = $condition->get_value();

        // A malformed stored value still names a requirement, so it refuses rather than passes.
        return [
            'operator' => $condition->get_operator(),
            'id'       => is_numeric( $value ) ? (int) $value : 0,
        ];
    }

    private function live_tier( int $id ): ?Tier {
        if ( ! array_key_exists( $id, $this->tiers ) ) {
            $this->tiers[ $id ] = $id > 0 ? ( new TierRepository() )->find_live( $id ) : null;
        }

        return $this->tiers[ $id ];
    }
}
