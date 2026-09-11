<?php
namespace YayLoyalty\Engine\Points;

defined( 'ABSPATH' ) || exit;

/**
 * Raw points to a whole number, ONCE, at order level.
 *
 * Never per line item: rounding each line down would lose up to a point per
 * line and make the total depend on how the cart was split.
 */
final class Rounding {

    public const DOWN    = 'down';
    public const NEAREST = 'nearest';
    public const UP      = 'up';

    /** Breakdown marker for flat awards, which never round. Not a config value. */
    public const NONE = 'none';

    public static function apply( float $raw, string $mode ): int {
        switch ( $mode ) {
            case self::UP:
                return (int) ceil( $raw );

            case self::NEAREST:
                return (int) round( $raw, 0, PHP_ROUND_HALF_UP );

            case self::DOWN:
            default:
                return (int) floor( $raw );
        }
    }
}
