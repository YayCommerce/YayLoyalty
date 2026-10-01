<?php
namespace YayLoyalty\Engine\Earning;

use YayLoyalty\Utils\PointsMath;

defined( 'ABSPATH' ) || exit;

/** How many of an earn row's points are owed back after refunds. Pure. */
final class ClawbackMath {

    /**
     * Total points owed back for an earn, recomputed from the cumulative
     * refunded base each time (never summed per refund, which strands
     * rounding): floored so a partial refund never over-claws, exact once
     * the order is fully refunded or its whole base is.
     *
     * @param float $refunded_base Refunded amount of the lines the earn's campaign counted, across every refund.
     * @param float $original_base Those lines' total when the order was placed.
     */
    public static function owed( int $earned, float $refunded_base, float $original_base, bool $is_fully_refunded ): int {
        if ( $is_fully_refunded || ( $original_base > 0 && $refunded_base >= $original_base ) ) {
            return $earned;
        }

        if ( $original_base <= 0 ) {
            return 0;
        }

        return PointsMath::round_points( $earned * $refunded_base / $original_base, PointsMath::ROUND_DOWN );
    }
}
