<?php
namespace YayLoyalty\Enums;

defined( 'ABSPATH' ) || exit;

/**
 * What a reward does when redeemed. It also keys the per-type config whitelist and the cost_points rule:
 * flexible_discount is the built-in points discount, with a null cost, and every other type has a fixed cost.
 */
final class RewardType {

    use StringEnumMethods;

    public const FLEXIBLE_DISCOUNT = 'flexible_discount';

    // Pro: recognised when stored, not offered in Free.

    public const FIXED_COUPON   = 'fixed_coupon';
    public const PERCENT_COUPON = 'percent_coupon';
    public const FREE_SHIPPING  = 'free_shipping';
    public const FREE_PRODUCT   = 'free_product';

    /**
     * Types this build offers. Filtered, unlike all_known(): downgrade safety needs the recognised set to stay complete.
     *
     * @return string[]
     */
    public static function all(): array {
        /**
         * Reward types this build can offer. Internal Pro seam, not a public API.
         *
         * @param string[] $types
         */
        return apply_filters( 'yayloyalty_reward_types', self::lite_types() );
    }

    /**
     * Lite's own types, unfiltered, so all_known() never depends on the build.
     *
     * @return string[]
     */
    private static function lite_types(): array {
        return [
            self::FLEXIBLE_DISCOUNT,
        ];
    }

    /**
     * Every type the schema recognises, Pro included. Unfiltered: for validating stored rows, never for offering choices.
     *
     * @return string[]
     */
    public static function all_known(): array {
        return array_merge(
            self::lite_types(),
            [
                self::FIXED_COUPON,
                self::PERCENT_COUPON,
                self::FREE_SHIPPING,
                self::FREE_PRODUCT,
            ]
        );
    }

    public static function is_known( $value ): bool {
        return is_string( $value ) && in_array( $value, self::all_known(), true );
    }

    protected static function noun(): string {
        return 'reward type';
    }
}
