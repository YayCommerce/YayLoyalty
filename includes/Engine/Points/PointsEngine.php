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

    // A bonus's status for one viewer (offers()): every condition holds; one fails; only the order is left to decide.
    public const OFFER_MATCHED     = 'matched';
    public const OFFER_LOCKED      = 'locked';
    public const OFFER_AT_CHECKOUT = 'at_checkout';

    /** The evaluator's reason for a condition only the order can answer. */
    private const REASON_NEEDS_ORDER = 'needs_order';

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
     * @throws \InvalidArgumentException When $ctx is an advert, or no handler owns the trigger.
     */
    public function evaluate( string $trigger, Context $ctx ): Result {
        if ( $ctx->is_advert() ) {
            throw new \InvalidArgumentException( 'YayLoyalty: evaluate() takes an event Context, not Context::advert().' );
        }

        $handler    = $this->registry->get( $trigger );
        $candidates = $this->candidates( $trigger, $ctx );

        if ( empty( $candidates ) ) {
            return Result::none( Result::REASON_NO_CAMPAIGNS );
        }

        $campaign = $this->pick( $handler, $candidates, $ctx );

        if ( null === $campaign ) {
            return Result::none( Result::REASON_NOT_ELIGIBLE );
        }

        $breakdown = $handler->compute_points( $campaign, $ctx );
        $points    = $this->caps->apply( $breakdown->get_points(), $campaign );

        if ( $points <= 0 ) {
            return Result::none( Result::REASON_ZERO_POINTS );
        }

        return Result::awarded( $points, $campaign, $breakdown, $handler->reference_type(), $handler->reference_id( $ctx ), $handler->source() );
    }

    /**
     * The value the storefront may promise for a trigger: never more than any shopper gets. Walks evaluate()'s
     * candidates down to the first campaign for everyone; a conditional campaign above it may catch some
     * shoppers, so its value lowers the promise. Null when that lowest value is 0, or no campaign is for
     * everyone. The handlers' event gates (min_subtotal, require_verified, one_per_product) are the row's
     * terms, not uncertainty: a gated campaign stops the walk like evaluate() stops at it. Not applied without an event: caps, and the purchase filters and rounding.
     *
     * @param string  $trigger A CampaignTrigger constant.
     * @param Context $ctx     Context::advert(); liveness is evaluated at its occurred_at.
     * @return array{campaign: Campaign, value: float}|null The campaign that set the value: points per currency
     *                                                       unit or flat points.
     * @throws \InvalidArgumentException When $ctx is an event, or no handler owns the trigger.
     */
    public function advertised( string $trigger, Context $ctx ): ?array {
        if ( ! $ctx->is_advert() ) {
            throw new \InvalidArgumentException( 'YayLoyalty: advertised() takes a Context::advert().' );
        }

        $handler = $this->registry->get( $trigger );
        $lowest  = null;

        foreach ( $this->candidates( $trigger, $ctx ) as $campaign ) {
            // No shopper to compare conditions with, so a conditional campaign is neither matched nor skipped.
            $for_everyone = self::applies_to_everyone( $campaign );

            if ( $for_everyone && ! $this->matches( $campaign, $ctx ) ) {
                continue;
            }

            $value = $handler->advertised_points( $campaign );

            if ( null === $lowest || $value < $lowest['value'] ) {
                $lowest = [
                    'campaign' => $campaign,
                    'value'    => $value,
                ];
            }

            if ( $for_everyone ) {
                return $lowest['value'] > 0 ? $lowest : null;
            }
        }

        return null;
    }

    /**
     * What advertised() promises, plus the bonuses a viewer could earn instead: the conditional campaigns that come
     * before the first campaign for everyone and pay more than the promise (anything, with no promise). Below
     * that campaign nothing pays, so nothing there is a bonus. Each bonus carries the viewer's status, folded
     * from the evaluator's explain(): OFFER_MATCHED, OFFER_LOCKED when a condition fails, OFFER_AT_CHECKOUT when
     * only conditions the order answers are left. No bonuses without an evaluator that explains (Lite).
     *
     * @param string       $trigger A CampaignTrigger constant.
     * @param Context      $advert  Context::advert(); liveness is evaluated at its occurred_at.
     * @param Context|null $viewer  The member the statuses are for; null for a guest, explained on $advert.
     * @return array{base: array{campaign: Campaign, value: float}|null, bonuses: array<int, array{campaign: Campaign, value: float, status: string}>}
     * @throws \InvalidArgumentException When $advert is an event, or no handler owns the trigger.
     */
    public function offers( string $trigger, Context $advert, ?Context $viewer ): array {
        $base      = $this->advertised( $trigger, $advert );
        $evaluator = self::conditions_evaluator();
        $offers    = [
            'base'    => $base,
            'bonuses' => [],
        ];

        if ( null === $evaluator || ! method_exists( $evaluator, 'explain' ) ) {
            return $offers;
        }

        $handler = $this->registry->get( $trigger );
        $ctx     = $viewer ?? $advert;

        foreach ( $this->candidates( $trigger, $advert ) as $campaign ) {
            if ( self::applies_to_everyone( $campaign ) ) {
                break;
            }

            $value = $handler->advertised_points( $campaign );

            if ( $value <= ( null === $base ? 0.0 : $base['value'] ) ) {
                continue;
            }

            $offers['bonuses'][] = [
                'campaign' => $campaign,
                'value'    => $value,
                'status'   => $this->offer_status( $evaluator->explain( $campaign->get_conditions(), $ctx ), $campaign, $ctx ),
            ];
        }

        return $offers;
    }

    /**
     * @param array<int, array{matched: bool, reason: string}> $results The evaluator's explain().
     */
    private function offer_status( array $results, Campaign $campaign, Context $ctx ): string {
        $status = self::OFFER_MATCHED;

        foreach ( $results as $result ) {
            if ( ! empty( $result['matched'] ) ) {
                continue;
            }

            if ( self::REASON_NEEDS_ORDER !== ( $result['reason'] ?? '' ) ) {
                return self::OFFER_LOCKED;
            }

            $status = self::OFFER_AT_CHECKOUT;
        }

        // A matched campaign still goes through the same filter evaluate() asks.
        if ( self::OFFER_MATCHED === $status && ! $this->matches( $campaign, $ctx ) ) {
            return self::OFFER_LOCKED;
        }

        return $status;
    }

    /** @return iterable<Campaign> */
    private function candidates( string $trigger, Context $ctx ): iterable {
        /**
         * Live campaigns for a trigger, highest priority first. Also fires on storefront pages with an
         * advertising Context (Context::is_advert()), which stands for any shopper and no event.
         *
         * @param Campaign[] $campaigns
         * @param string     $trigger
         * @param Context    $ctx
         */
        return apply_filters( 'yayloyalty_campaign_candidates', $this->campaigns->live_for_trigger( $trigger, $ctx->get_occurred_at() ), $trigger, $ctx );
    }

    /**
     * The first candidate that matches the event and passes the handler's is_eligible().
     *
     * @param iterable<Campaign> $candidates
     */
    private function pick( Triggers\TriggerHandler $handler, iterable $candidates, Context $ctx ): ?Campaign {
        foreach ( $candidates as $campaign ) {
            if ( $this->matches( $campaign, $ctx ) && $handler->is_eligible( $campaign, $ctx ) ) {
                return $campaign;
            }
        }

        return null;
    }

    private function matches( Campaign $campaign, Context $ctx ): bool {
        /**
         * Whether a campaign's conditions match this event. Also fires on storefront pages with an
         * advertising Context (Context::is_advert()), which stands for any shopper and no event; there it
         * fires for a campaign for everyone, with true, and for a bonus whose conditions offers() found met.
         *
         * @param bool     $matches
         * @param Campaign $campaign
         * @param Context  $ctx
         */
        return (bool) apply_filters( 'yayloyalty_campaign_matches', $this->conditions_match( $campaign, $ctx ), $campaign, $ctx );
    }

    /**
     * The evaluator gets the Context, so it compares and never queries. With no evaluator
     * attached (Lite) this fails open: failing closed would silently disable
     * every conditional campaign the moment a site downgraded from Pro.
     */
    private function conditions_match( Campaign $campaign, Context $ctx ): bool {
        if ( self::applies_to_everyone( $campaign ) ) {
            return true;
        }

        return (bool) self::conditions_evaluator()->evaluate( $campaign->get_conditions(), $ctx );
    }

    /** Whether a campaign's conditions let every shopper through: it has none, or none are enforced (Lite). */
    private static function applies_to_everyone( Campaign $campaign ): bool {
        return [] === $campaign->get_conditions() || null === self::conditions_evaluator();
    }

    /** The attached conditions evaluator, or null when conditions are not enforced. */
    private static function conditions_evaluator(): ?object {
        /**
         * Evaluator for campaign `conditions`; null in Lite. Internal Pro seam, not a public API.
         *
         * @param object|null $evaluator Exposes evaluate( array $conditions, Context $ctx ): bool, and for
         *                              offers() explain( array $conditions, Context $ctx ): array, one
         *                              {matched: bool, reason: string} per condition.
         */
        $evaluator = apply_filters( self::CONDITIONS_EVALUATOR_FILTER, null ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- the constant holds the prefixed name.

        return is_object( $evaluator ) && method_exists( $evaluator, 'evaluate' ) ? $evaluator : null;
    }
}
