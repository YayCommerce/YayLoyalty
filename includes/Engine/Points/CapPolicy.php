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

        $per_order = (int) ( $config['max_points_per_order'] ?? 0 );

        if ( $per_order > 0 ) {
            $points = min( $points, $per_order );
        }

        $daily = (int) ( $config['daily_cap'] ?? 0 );

        if ( $daily > 0 ) {
            $points = min( $points, max( 0, $daily - $ctx->get_points_earned_today() ) );
        }

        return max( 0, $points );
    }
}
