<?php
namespace YayLoyalty\Pro\Services;

use YayLoyalty\Engine\Points\Context as EarnContext;
use YayLoyalty\Engine\Points\ContextFactory;
use YayLoyalty\Engine\Redemption\RewardGate;
use YayLoyalty\Engine\Rewards\Context;
use YayLoyalty\Models\Reward;

defined( 'ABSPATH' ) || exit;

/**
 * Pro's answer to RewardGate::CONDITIONS_FILTER: a reward's conditions, evaluated by the campaigns' evaluator on the
 * customer as of the claim. Reward condition types share the campaign types' names and value shapes, and are all
 * facts about the customer, so the customer-only Context ContextFactory::from_user() gives is enough; `tier` is
 * rejected there as not yet evaluable. A guest fails any condition. Checkout quotes and commits through this,
 * and RewardsPanel asks it to lock rewards in Ways to redeem.
 */
final class RewardConditionsMatch {

    /** @var ConditionsEvaluator */
    private $evaluator;

    /** @var array<string, EarnContext> The customer Context per user and time, so one page reads a member's facts once. */
    private $customers = [];

    public function __construct( ConditionsEvaluator $evaluator ) {
        $this->evaluator = $evaluator;
    }

    /** Called once by YayLoyaltyPro::boot(). */
    public function register(): void {
        add_filter( RewardGate::CONDITIONS_FILTER, [ $this, 'matches' ], 10, 3 ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- the constant holds the prefixed name.
    }

    /**
     * @param bool    $matches What an earlier subscriber decided; a false is kept.
     * @param Reward  $reward
     * @param Context $ctx     The claim.
     */
    public function matches( $matches, Reward $reward, Context $ctx ): bool {
        if ( ! $matches ) {
            return false;
        }

        $conditions = $reward->get_conditions();

        if ( [] === $conditions ) {
            return true;
        }

        $user_id = $ctx->get_user_id();

        if ( null === $user_id || $user_id < 1 ) {
            return false;
        }

        return $this->evaluator->evaluate( $conditions, $this->customer( $user_id, $ctx->get_at() ) );
    }

    private function customer( int $user_id, string $at ): EarnContext {
        $key = $user_id . '|' . $at;

        if ( ! isset( $this->customers[ $key ] ) ) {
            $this->customers[ $key ] = ContextFactory::from_user( $user_id, $at );
        }

        return $this->customers[ $key ];
    }
}
