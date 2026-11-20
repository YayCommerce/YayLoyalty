<?php
namespace YayLoyalty\Pro\Renewals;

use YayLoyalty\Engine\Points\Breakdown;
use YayLoyalty\Engine\Points\Context;
use YayLoyalty\Engine\Points\Triggers\TriggerHandler;
use YayLoyalty\Enums\CampaignTrigger;
use YayLoyalty\Enums\LedgerSource;
use YayLoyalty\Enums\ReferenceType;
use YayLoyalty\Models\Campaign;

defined( 'ABSPATH' ) || exit;

/**
 * Flat points for a WooCommerce Subscriptions renewal order. The reference is the order, so each renewal
 * pays once and a refund of it claws the row back. Registered only while WooCommerce Subscriptions is active.
 */
final class RenewalHandler implements TriggerHandler {

    public function trigger(): string {
        return CampaignTrigger::RENEWAL;
    }

    public function is_eligible( Campaign $campaign, Context $ctx ): bool {
        $order_id = $ctx->get_order_id();

        return null !== $order_id && function_exists( 'wcs_order_contains_renewal' ) && wcs_order_contains_renewal( $order_id );
    }

    public function compute_points( Campaign $campaign, Context $ctx ): Breakdown {
        return Breakdown::flat( $campaign->flat_points() );
    }

    public function advertised_points( Campaign $campaign ): float {
        return (float) $campaign->flat_points();
    }

    public function reference_type(): string {
        return ReferenceType::ORDER;
    }

    public function reference_id( Context $ctx ): ?int {
        return $ctx->get_order_id();
    }

    public function source(): string {
        return LedgerSource::RENEWAL;
    }
}
