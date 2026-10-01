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
 * Points for an order. Config gates are eligibility, not calculation: a
 * failed minimum falls through to the next campaign rather than earning
 * zero. A multiplier campaign with the default paused is likewise ineligible.
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

        $min = self::min_subtotal( $config );

        if ( $min > 0 && $ctx->get_order_subtotal() * $ctx->get_base_rate() < $min ) {
            return false;
        }

        if ( ! empty( $config['exclude_coupon_orders'] ) && ! empty( $ctx->get_real_coupon_codes() ) ) {
            return false;
        }

        if ( $this->is_multiplier( $config ) && null === self::multiplier_default( $this->campaigns->find_default_purchase() ) ) {
            return false;
        }

        return true;
    }

    public function compute_points( Campaign $campaign, Context $ctx ): Breakdown {
        $config   = $campaign->get_config();
        $base     = $this->base_calculator->compute( $campaign, $ctx );
        $rounding = (string) ( $config['rounding'] ?? PointsMath::ROUND_DOWN );
        $rate     = self::rate_of( $config, $this->campaigns->find_default_purchase() );

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
     * Whether a purchase config multiplies the default rate rather than naming its own; a missing mode means rate.
     * The one decider (code-standards § 16): the performance page's liability split asks here too.
     *
     * @param array<string, mixed> $config
     */
    public static function is_multiplier( array $config ): bool {
        return 'multiplier' === ( $config['mode'] ?? 'rate' );
    }

    /**
     * The factor a multiplier config applies to the default rate; a missing value is ×1.
     *
     * @param array<string, mixed> $config
     */
    public static function multiplier( array $config ): float {
        return (float) ( $config['multiplier'] ?? 1 );
    }

    /**
     * The order subtotal (base currency) below which this config does not pay; 0 for none.
     *
     * @param array<string, mixed> $config
     */
    public static function min_subtotal( array $config ): float {
        return max( 0.0, (float) ( $config['min_subtotal'] ?? 0 ) );
    }

    /**
     * The default purchase campaign a multiplier may scale, or null when it cannot (archived or not active).
     * The storefront launcher asks here too, so it never advertises a multiplier the engine would skip.
     */
    public static function multiplier_default( ?Campaign $default_purchase ): ?Campaign {
        return null !== $default_purchase && Status::ACTIVE === $default_purchase->get_status() ? $default_purchase : null;
    }

    /**
     * Points per currency unit a purchase config pays: its own rate, or the default's rate times its multiplier.
     *
     * @param array<string, mixed> $config
     * @param Campaign|null        $default_purchase The default purchase campaign; read only for a multiplier.
     */
    public static function rate_of( array $config, ?Campaign $default_purchase ): float {
        if ( ! self::is_multiplier( $config ) ) {
            return (float) ( $config['points_per_unit'] ?? 0 );
        }

        return null === $default_purchase ? 0.0 : (float) ( $default_purchase->get_config()['points_per_unit'] ?? 0 ) * self::multiplier( $config );
    }
}
