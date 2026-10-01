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
 * the Context's occurred_at (the event time, never now()) — there is no
 * separate timestamp argument; the Context is the only way a time gets in.
 * No capability checks: runs from cron.
 */
final class PointsEngine {

    /** Filter that attaches a campaign-conditions evaluator (Pro); nothing subscribes in Lite. Internal seam. */
    public const CONDITIONS_EVALUATOR_FILTER = 'yayloyalty_campaign_conditions_evaluator';

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

    /** The engine with this build's trigger handlers; Pro adds its own through Registry::with_lite_handlers(). */
    public static function build( CampaignRepository $campaigns ): self {
        return new self( Registry::with_lite_handlers( $campaigns ), $campaigns, new CapPolicy() );
    }

    /**
     * @param string  $trigger A CampaignTrigger constant.
     * @param Context $ctx     The event; liveness is evaluated at its occurred_at.
     * @throws \InvalidArgumentException When no handler owns the trigger.
     */
    public function evaluate( string $trigger, Context $ctx ): Result {
        $handler = $this->registry->get( $trigger );
        $at      = $ctx->get_occurred_at();

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
            $matches = apply_filters( 'yayloyalty_campaign_matches', $this->conditions_match( $campaign, $ctx ), $campaign, $ctx );

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

    /**
     * Whether the campaign's `conditions` match the event. The evaluator
     * receives the Context — customer facts (loaded on first use through
     * ContextFactory::customer_facts()) plus the order's own currency,
     * coupons and lines — so it compares and never queries itself. With no evaluator
     * attached (Lite) this fails open: failing closed would silently disable
     * every conditional campaign the moment a site downgraded from Pro.
     */
    private function conditions_match( Campaign $campaign, Context $ctx ): bool {
        $conditions = $campaign->get_conditions();

        if ( empty( $conditions ) ) {
            return true;
        }

        /**
         * Evaluator for campaign `conditions`; null in Lite. Internal Pro seam, not a public API.
         *
         * @param object|null $evaluator Exposes evaluate( array $conditions, Context $ctx ): bool.
         */
        $evaluator = apply_filters( self::CONDITIONS_EVALUATOR_FILTER, null ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- the constant holds the prefixed name.

        if ( null === $evaluator || ! method_exists( $evaluator, 'evaluate' ) ) {
            return true;
        }

        return (bool) $evaluator->evaluate( $conditions, $ctx );
    }
}
