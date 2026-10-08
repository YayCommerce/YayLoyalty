<?php
namespace YayLoyalty\Engine\Points;

use YayLoyalty\Engine\Points\Triggers\Registry;
use YayLoyalty\Models\Campaign;
use YayLoyalty\Repositories\CampaignRepository;

defined( 'ABSPATH' ) || exit;

/**
 * How many points an event earns and which campaign paid. One trigger per call: triggers stack
 * across calls, and the highest priority wins within one. Writes nothing. Reads only campaigns and
 * the Context, and scores at its occurred_at, never now(). No capability checks: it runs from cron.
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
        return new self( Registry::with_lite_handlers(), $campaigns, new CapPolicy() );
    }

    /**
     * @param string  $trigger A CampaignTrigger constant.
     * @param Context $ctx     The event; liveness is evaluated at its occurred_at.
     * @throws \InvalidArgumentException When no handler owns the trigger.
     */
    public function evaluate( string $trigger, Context $ctx ): Result {
        $handler    = $this->registry->get( $trigger );
        $candidates = $this->candidates( $trigger, $ctx );

        if ( empty( $candidates ) ) {
            return Result::none( Result::REASON_NO_CAMPAIGNS );
        }

        $picked = $this->pick( $handler, $candidates, $ctx );

        if ( null === $picked ) {
            return Result::none( Result::REASON_NOT_ELIGIBLE );
        }

        $campaign = $picked['campaign'];

        $breakdown = $handler->compute_points( $campaign, $ctx );
        $points    = $this->caps->apply( $breakdown->get_points(), $campaign, $ctx );

        if ( $points <= 0 ) {
            return Result::none( Result::REASON_ZERO_POINTS );
        }

        return Result::awarded( $points, $campaign, $breakdown, $handler->reference_type(), $handler->reference_id( $ctx ), $handler->source() );
    }

    /**
     * The campaign the storefront may promise for a trigger, through evaluate()'s own loop and stop rule:
     * null when the campaign the engine would reach pays nothing. Without an event it cannot apply the
     * handlers' is_eligible() gates (min_subtotal, exclude_coupon_orders, require_verified, one_per_product),
     * CapPolicy's caps, nor campaign conditions, so a campaign with conditions is skipped rather than
     * promised to shoppers it may not pay.
     *
     * @param string  $trigger A CampaignTrigger constant.
     * @param Context $ctx     Context::advert(); liveness is evaluated at its occurred_at.
     * @return array{campaign: Campaign, value: float}|null Value is points per currency unit or flat points.
     * @throws \InvalidArgumentException When $ctx is an event, or no handler owns the trigger.
     */
    public function advertised( string $trigger, Context $ctx ): ?array {
        if ( ! $ctx->is_advert() ) {
            throw new \InvalidArgumentException( 'YayLoyalty: advertised() takes a Context::advert().' );
        }

        $picked = $this->pick( $this->registry->get( $trigger ), $this->candidates( $trigger, $ctx ), $ctx );

        return null === $picked || $picked['value'] <= 0 ? null : $picked;
    }

    /** @return Campaign[] */
    private function candidates( string $trigger, Context $ctx ): array {
        /**
         * Live campaigns for a trigger, highest priority first. Also fires on storefront pages with an
         * advertising Context (Context::is_advert()), which stands for any shopper and no event.
         *
         * @param Campaign[] $campaigns
         * @param string     $trigger
         * @param Context    $ctx
         */
        return (array) apply_filters( 'yayloyalty_campaign_candidates', $this->campaigns->live_for_trigger( $trigger, $ctx->get_occurred_at() ), $trigger, $ctx );
    }

    /**
     * The first candidate that matches and is eligible: for an advertising Context, eligible means
     * the handler has a value to advertise, since is_eligible() needs an event.
     *
     * @param Campaign[] $candidates
     * @return array{campaign: Campaign, value: ?float}|null Value is the advertised value, null for an event.
     */
    private function pick( Triggers\TriggerHandler $handler, array $candidates, Context $ctx ): ?array {
        foreach ( $candidates as $campaign ) {
            /**
             * Whether a campaign's conditions match this event. Also fires on storefront pages with an
             * advertising Context (Context::is_advert()), which stands for any shopper and no event.
             *
             * @param bool     $matches
             * @param Campaign $campaign
             * @param Context  $ctx
             */
            $matches = apply_filters( 'yayloyalty_campaign_matches', $this->conditions_match( $campaign, $ctx ), $campaign, $ctx );

            if ( ! $matches ) {
                continue;
            }

            $value    = $ctx->is_advert() ? $handler->advertised_value( $campaign ) : null;
            $eligible = $ctx->is_advert() ? null !== $value : $handler->is_eligible( $campaign, $ctx );

            if ( $eligible ) {
                return [
                    'campaign' => $campaign,
                    'value'    => $value,
                ];
            }
        }

        return null;
    }

    /**
     * The evaluator gets the Context, so it compares and never queries. With no evaluator
     * attached (Lite) this fails open: failing closed would silently disable
     * every conditional campaign the moment a site downgraded from Pro.
     * An advertising Context has no event to compare, so only a campaign for everyone matches it.
     */
    private function conditions_match( Campaign $campaign, Context $ctx ): bool {
        if ( self::applies_to_everyone( $campaign ) ) {
            return true;
        }

        if ( $ctx->is_advert() ) {
            return false;
        }

        return (bool) self::conditions_evaluator()->evaluate( $campaign->get_conditions(), $ctx );
    }

    /**
     * Whether a campaign's conditions let every shopper through: it has none, or none are enforced (Lite).
     * Only such a campaign is advertised, so the storefront never promises a rate some shoppers miss.
     */
    private static function applies_to_everyone( Campaign $campaign ): bool {
        return [] === $campaign->get_conditions() || null === self::conditions_evaluator();
    }

    /** The attached conditions evaluator, or null when conditions are not enforced. */
    private static function conditions_evaluator(): ?object {
        /**
         * Evaluator for campaign `conditions`; null in Lite. Internal Pro seam, not a public API.
         *
         * @param object|null $evaluator Exposes evaluate( array $conditions, Context $ctx ): bool.
         */
        $evaluator = apply_filters( self::CONDITIONS_EVALUATOR_FILTER, null ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- the constant holds the prefixed name.

        return is_object( $evaluator ) && method_exists( $evaluator, 'evaluate' ) ? $evaluator : null;
    }
}
