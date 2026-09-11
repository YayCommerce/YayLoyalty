<?php
namespace YayLoyalty\Engine\Points;

use YayLoyalty\Models\Campaign;

defined( 'ABSPATH' ) || exit;

/**
 * Clamp computed points to the campaign's per-order and daily caps.
 *
 * The daily figure comes from the Context — the caller reads the ledger, the
 * engine does not — so in a preview (points_earned_today = 0) the cap shows
 * the best case, which is the honest number to promise on a product page.
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
