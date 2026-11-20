<?php
namespace YayLoyalty\Pro\Renewals;

use YayLoyalty\Engine\Points\ContextFactory;
use YayLoyalty\Engine\Points\PointsEngine;
use YayLoyalty\Enums\CampaignTrigger;
use YayLoyalty\Repositories\CampaignRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Adds the renewal trigger to a completed renewal order's award triggers, and drops its order points
 * when the renewal campaign that pays it is set to replace them.
 */
final class RenewalTriggers {

    public function register(): void {
        add_filter( 'yayloyalty_order_award_triggers', [ $this, 'filter' ], 10, 2 );
    }

    /**
     * @param string[]  $triggers CampaignTrigger values.
     * @param \WC_Order $order
     * @return string[]
     */
    public function filter( array $triggers, \WC_Order $order ): array {
        if ( ! function_exists( 'wcs_order_contains_renewal' ) || ! wcs_order_contains_renewal( $order ) ) {
            return $triggers;
        }

        $triggers[] = CampaignTrigger::RENEWAL;

        // Decided by the campaign that would pay this order, so one whose conditions don't match keeps order points.
        $result = PointsEngine::build( new CampaignRepository() )->evaluate( CampaignTrigger::RENEWAL, ContextFactory::from_order( $order ) );
        $paying = $result->is_awarded() ? $result->get_campaign() : null;

        if ( null !== $paying && ! empty( $paying->get_config()['replace_purchase'] ) ) {
            $triggers = array_diff( $triggers, [ CampaignTrigger::PURCHASE ] );
        }

        return array_values( array_unique( $triggers ) );
    }
}
