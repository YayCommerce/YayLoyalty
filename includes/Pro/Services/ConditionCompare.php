<?php
namespace YayLoyalty\Pro\Services;

use YayLoyalty\Enums\CampaignConditionType;

defined( 'ABSPATH' ) || exit;

/**
 * The value comparisons campaign and reward conditions share.
 */
final class ConditionCompare {

    /** Whether the actual values and the condition's values share one. */
    public static function intersects( array $actual, $value ): bool {
        $wanted = array_map(
            static function ( $item ) {
                return is_scalar( $item ) ? (string) $item : '';
            },
            is_array( $value ) ? $value : [ $value ]
        );

        return [] !== array_intersect( $wanted, array_map( 'strval', $actual ) );
    }

    /**
     * A number against one bound, or an inclusive [low, high] for `between`.
     *
     * @return bool|null Null when the value is not numeric or the operator is not numeric.
     */
    public static function numeric( float $actual, string $operator, $value ): ?bool {
        if ( CampaignConditionType::OP_BETWEEN === $operator ) {
            if ( ! is_array( $value ) || ! isset( $value[0], $value[1] ) || ! is_numeric( $value[0] ) || ! is_numeric( $value[1] ) ) {
                return null;
            }

            return $actual >= (float) $value[0] && $actual <= (float) $value[1];
        }

        if ( ! is_numeric( $value ) ) {
            return null;
        }

        $expected = (float) $value;

        switch ( $operator ) {
            case CampaignConditionType::OP_EQ:
                return abs( $actual - $expected ) < 0.00001;
            case CampaignConditionType::OP_GT:
                return $actual > $expected;
            case CampaignConditionType::OP_GTE:
                return $actual >= $expected;
            case CampaignConditionType::OP_LT:
                return $actual < $expected;
            case CampaignConditionType::OP_LTE:
                return $actual <= $expected;
        }

        return null;
    }
}
