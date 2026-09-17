<?php
namespace YayLoyalty\Engine\Points\Triggers;

use YayLoyalty\Engine\Points\BaseCalculator;
use YayLoyalty\Engine\Points\Breakdown;
use YayLoyalty\Engine\Points\Context;
use YayLoyalty\Enums\CampaignTrigger;
use YayLoyalty\Enums\LedgerSource;
use YayLoyalty\Enums\ReferenceType;
use YayLoyalty\Enums\Status;
use YayLoyalty\Models\Campaign;
use YayLoyalty\Repositories\CampaignRepository;
use YayLoyalty\Utils\PointsMath;

defined( 'ABSPATH' ) || exit;

/**
 * Points for an order.
 *
 * Config gates are eligibility, not calculation: "Trail Shoes boost, 3 pts/$,
 * minimum $50" on a $30 order must fall through to the default campaign and
 * earn 1 pt/$ — not earn zero. A program-wide floor is a gate on the default
 * campaign itself.
 *
 * Holds the repository for one reason: a multiplier campaign multiplies the
 * default campaign's rate, and "Black Friday 2×" with the default paused is
 * ineligible — so the customer still earns from the next campaign — rather
 * than an error that aborts the whole evaluation.
 */
final class PurchaseHandler implements TriggerHandler {

    /** @var CampaignRepository */
    private $campaigns;

    /** @var BaseCalculator */
    private $base_calculator;

    public function __construct( CampaignRepository $campaigns, BaseCalculator $base_calculator ) {
        $this->campaigns       = $campaigns;
        $this->base_calculator = $base_calculator;
    }

    public function trigger(): string {
        return CampaignTrigger::PURCHASE;
    }

    public function is_eligible( Campaign $campaign, Context $ctx ): bool {
        $config = $campaign->get_config();

        $min = (float) ( $config['min_subtotal'] ?? 0 );

        if ( $min > 0 && $ctx->get_order_subtotal() * $ctx->get_base_rate() < $min ) {
            return false;
        }

        if ( ! empty( $config['exclude_coupon_orders'] ) && ! empty( $ctx->get_real_coupon_codes() ) ) {
            return false;
        }

        if ( $this->is_multiplier( $config ) ) {
            $default = $this->campaigns->find_default_purchase();

            if ( null === $default || Status::ACTIVE !== $default->get_status() ) {
                return false;
            }
        }

        return true;
    }

    public function compute_points( Campaign $campaign, Context $ctx ): Breakdown {
        $config   = $campaign->get_config();
        $base     = $this->base_calculator->compute( $campaign, $ctx );
        $rounding = (string) ( $config['rounding'] ?? PointsMath::ROUND_DOWN );

        if ( $this->is_multiplier( $config ) ) {
            // is_eligible() already guaranteed the default exists and is active.
            $default = $this->campaigns->find_default_purchase();
            $rate    = (float) ( $default->get_config()['points_per_unit'] ?? 0 ) * (float) ( $config['multiplier'] ?? 1 );
        } else {
            $rate = (float) ( $config['points_per_unit'] ?? 0 );
        }

        $raw    = $base * $rate;
        $points = PointsMath::round_points( $raw, $rounding );

        /**
         * Points an order earns from a campaign, after rounding and before caps.
         *
         * @param int      $points
         * @param Campaign $campaign
         * @param Context  $ctx
         * @param float    $base
         * @param float    $rate
         */
        $points = (int) apply_filters( 'yayloyalty_points_for_order', $points, $campaign, $ctx, $base, $rate );

        return new Breakdown( $points, $base, $rate, $raw, $rounding );
    }

    public function reference_type(): string {
        return ReferenceType::ORDER;
    }

    public function reference_id( Context $ctx ): ?int {
        return $ctx->get_order_id();
    }

    public function source(): string {
        return LedgerSource::ORDER;
    }

    /**
     * @param array<string, mixed> $config
     */
    private function is_multiplier( array $config ): bool {
        return 'multiplier' === ( $config['mode'] ?? 'rate' );
    }
}
