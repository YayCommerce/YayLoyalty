<?php
namespace YayLoyalty\Engine\Points;

use YayLoyalty\Engine\Points\Triggers\Registry;
use YayLoyalty\Models\Campaign;
use YayLoyalty\Repositories\CampaignRepository;

defined( 'ABSPATH' ) || exit;

/**
 * How many points does an event earn, and which campaign paid?
 *
 * One trigger per call. An order that is also a first order is two calls —
 * `purchase` and `first_order` — which is how triggers stack while
 * highest-priority-only holds within one. The caller runs that loop and
 * writes the ledger; this class writes nothing.
 *
 * PURE. Reads campaigns through CampaignRepository and nothing else: no
 * $wpdb, no WooCommerce globals, no current user, no time(). Everything
 * about the event is on the Context, and the instant to score it at is `$at`
 * — the order's creation time, never now(), so a late-processed order is
 * scored under the rules that were live when it happened.
 *
 * No capability checks, ever. This runs from cron, WP-CLI and order hooks
 * where there is no current user; permissions live at the HTTP boundary.
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

        /** Live campaigns for a trigger, highest priority first. @param Campaign[] $campaigns @param string $trigger @param Context $ctx */
        $candidates = apply_filters( 'yayloyalty_campaign_candidates', $this->campaigns->live_for_trigger( $trigger, $at ), $trigger, $ctx );

        if ( empty( $candidates ) ) {
            return Result::none( Result::REASON_NO_CAMPAIGNS );
        }

        foreach ( $candidates as $campaign ) {
            /** Does a campaign's conditions set match this event? @param bool $matches @param Campaign $campaign @param Context $ctx */
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
