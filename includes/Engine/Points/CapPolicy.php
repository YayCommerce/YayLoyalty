<?php
namespace YayLoyalty\Engine\Points;

use YayLoyalty\Models\Campaign;

defined( 'ABSPATH' ) || exit;

/**
 * Clamps points to the campaign's per-order and daily caps. A preview has 0 earned today,
 * so it shows the best case, which is the honest number to promise on a product page.
 */
final class CapPolicy {

    public function apply( int $points, Campaign $campaign, Context $ctx ): int {
        $config = $campaign->get_config();

        // null or missing is no cap; Campaign stores any cap below 1 as null.
        $per_order = $config['max_points_per_order'] ?? null;

        if ( null !== $per_order ) {
            $points = min( $points, (int) $per_order );
        }

        $daily = $config['daily_cap'] ?? null;

        if ( null !== $daily ) {
            $points = min( $points, max( 0, (int) $daily - $ctx->get_points_earned_today() ) );
        }

        return max( 0, $points );
    }
}
