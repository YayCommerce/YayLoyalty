<?php
namespace YayLoyalty\Engine\Points;

use YayLoyalty\Engine\Points\Triggers\Registry;
use YayLoyalty\Models\Campaign;
use YayLoyalty\Repositories\CampaignRepository;

defined( 'ABSPATH' ) || exit;

/**
 * How many points an event earns and which campaign paid. One trigger per
 * call (triggers stack across calls, highest priority wins within one);
 * writes nothing. Pure: reads campaigns and the Context only, scored at
 * $at (the event time, never now()). No capability checks: runs from cron.
 */
final class PointsEngine {

    /** @var Registry */
    private $registry;

    /** @var CampaignRepository */
    private $campaigns;

    /** @var CapPolicy */
    private $caps;

    public function __construct( Registry $registry, CampaignRepository $campaigns, CapPolicy $caps ) {
        $this->registry  = $registry;
        $this->campaigns = $campaigns;
        $this->caps      = $caps;
    }

    /**
     * @param string  $trigger A CampaignTrigger constant.
     * @param Context $ctx     The event.
     * @param string  $at      UTC MySQL datetime to evaluate liveness at.
     * @throws \InvalidArgumentException When no handler owns the trigger.
     */
    public function evaluate( string $trigger, Context $ctx, string $at ): Result {
        $handler = $this->registry->get( $trigger );

        /**
         * Live campaigns for a trigger, highest priority first.
         *
         * @param Campaign[] $campaigns
         * @param string     $trigger
         * @param Context    $ctx
         */
        $candidates = apply_filters( 'yayloyalty_campaign_candidates', $this->campaigns->live_for_trigger( $trigger, $at ), $trigger, $ctx );

        if ( empty( $candidates ) ) {
            return Result::none( Result::REASON_NO_CAMPAIGNS );
        }

        foreach ( $candidates as $campaign ) {
            /**
             * Whether a campaign's conditions match this event.
             *
             * @param bool     $matches
             * @param Campaign $campaign
             * @param Context  $ctx
             */
            $matches = apply_filters( 'yayloyalty_campaign_matches', $campaign->conditions_match( (int) $ctx->get_user_id() ), $campaign, $ctx );

            if ( ! $matches || ! $handler->is_eligible( $campaign, $ctx ) ) {
                continue;
            }

            $breakdown = $handler->compute_points( $campaign, $ctx );
            $points    = $this->caps->apply( $breakdown->get_points(), $campaign, $ctx );

            if ( $points <= 0 ) {
                return Result::none( Result::REASON_ZERO_POINTS );
            }

            return Result::awarded( $points, $campaign, $breakdown, $handler->reference_type(), $handler->reference_id( $ctx ), $handler->source() );
        }

        return Result::none( Result::REASON_NOT_ELIGIBLE );
    }
}
