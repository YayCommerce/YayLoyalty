<?php
namespace YayLoyalty\Pro\Services;

use YayLoyalty\Engine\Points\Context;
use YayLoyalty\Engine\Points\Multiplier;
use YayLoyalty\Engine\Points\PointsEngine;
use YayLoyalty\Models\Campaign;
use YayLoyalty\Repositories\CustomerRepository;
use YayLoyalty\Repositories\TierRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Answers PointsEngine::MULTIPLIER_FILTER with the customer's tier multiplier. It reads the tier the
 * customer holds now, not the one they held when the event happened: the award is authoritative, and
 * the order that lifts a customer into a tier earns at the old tier's multiplier, because the tier
 * moves only after that award commits. Guests, tierless customers, a deleted tier and tiers off all
 * leave the multiplier neutral.
 */
final class TierMultiplier {

    /** @var array<int, Multiplier|null> Per user id for this request; null means no tier multiplier. */
    private $by_user = [];

    public function register(): void {
        add_filter( PointsEngine::MULTIPLIER_FILTER, [ $this, 'for_context' ], 10, 3 );
    }

    /**
     * @param mixed    $multiplier The filtered value, Multiplier::none() unless another subscriber ran.
     * @param Campaign $campaign   The campaign that pays; unused, a tier multiplies every campaign.
     * @param Context  $ctx        The event.
     * @return mixed The tier's Multiplier, or $multiplier unchanged.
     */
    public function for_context( $multiplier, Campaign $campaign, Context $ctx ) {
        $user_id = $ctx->get_user_id();

        if ( null === $user_id || ! TierEvaluator::is_enabled() ) {
            return $multiplier;
        }

        if ( ! array_key_exists( $user_id, $this->by_user ) ) {
            $this->by_user[ $user_id ] = self::read( $user_id );
        }

        return $this->by_user[ $user_id ] ?? $multiplier;
    }

    /** The multiplier of the customer's live tier, or null when they have none. */
    private static function read( int $user_id ): ?Multiplier {
        $customer = ( new CustomerRepository() )->find_by_user_id( $user_id );
        $tier_id  = null !== $customer ? $customer->get_tier_id() : null;

        if ( null === $tier_id ) {
            return null;
        }

        $tier = ( new TierRepository() )->find_live( $tier_id );

        return null !== $tier ? new Multiplier( $tier->get_multiplier(), (int) $tier->get_id() ) : null;
    }
}
