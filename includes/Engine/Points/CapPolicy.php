<?php
namespace YayLoyalty\Engine\Points;

use YayLoyalty\Models\Campaign;

defined( 'ABSPATH' ) || exit;

/** Clamps points to the campaign's per-order cap. */
final class CapPolicy {

    public function apply( int $points, Campaign $campaign ): int {
        // null or missing is no cap; Campaign::config_is_valid() refuses a cap below 1.
        $per_order = $campaign->get_config()['max_points_per_order'] ?? null;

        if ( null !== $per_order ) {
            $points = min( $points, (int) $per_order );
        }

        return max( 0, $points );
    }
}
