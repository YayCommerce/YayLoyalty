<?php
namespace YayLoyalty\Enums;

defined( 'ABSPATH' ) || exit;

/**
 * What a reward does when redeemed.
 *
 * The type determines which keys `config` may contain (see
 * Reward::config_schema()) and, per the HARD RULE enforced by
 * Reward::cost_points_is_valid(), whether `cost_points` must be null or set:
 * `flexible_discount` is the slider — the customer picks the amount, so
 * `cost_points` stays null; every other type is a fixed-cost catalog item.
 *
 * Same Free/Pro split as CampaignTrigger: Pro types exist as constants because
 * a downgraded site's stored rows must not be treated as corrupt, but all()
 * returns only what this build actually offers.
 */
final class RewardType {

    /* --- 1.0 --- */

    public const FLEXIBLE_DISCOUNT = 'flexible_discount';

    /* --- Pro. Recognised when stored, not offered in Free. --- */

    public const FIXED_COUPON   = 'fixed_coupon';
    public const PERCENT_COUPON = 'percent_coupon';
    public const FREE_SHIPPING  = 'free_shipping';
    public const FREE_PRODUCT   = 'free_product';

    /**
     * Types this build offers.
     *
     * FILTERED; all_known() is NOT — same asymmetry as CampaignTrigger, for the
     * same reason: a site that downgrades from Pro to Lite must still recognise
     * its stored Pro reward rows, even though Lite cannot create new ones.
     *
     * @return string[]
     */
    public static function all(): array {
        /**
         * Reward types this build can offer.
         *
         * Pro's attach point for its own types. Internal seam for first-party
         * Pro code, not a documented third-party API.
         *
         * @param string[] $types
         */
        return apply_filters( 'yayloyalty_reward_types', self::lite_types() );
    }

    /**
     * The types Lite itself ships, before any filtering.
     *
     * Exists so all_known() has an unfiltered base to build on — it must NOT
     * call all(), or a Pro subscriber would leak into the "recognised"
     * vocabulary and make it depend on the build.
     *
     * @return string[]
     */
    private static function lite_types(): array {
        return [
            self::FLEXIBLE_DISCOUNT,
        ];
    }

    /**
     * Every type the schema recognises, Pro types included.
     *
     * DELIBERATELY UNFILTERED. Used for validating stored rows, never for
     * offering choices.
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

    public static function is_valid( $value ): bool {
        return is_string( $value ) && in_array( $value, self::all(), true );
    }

    public static function is_known( $value ): bool {
        return is_string( $value ) && in_array( $value, self::all_known(), true );
    }

    /**
     * @throws \InvalidArgumentException When $value is not offerable in this build.
     */
    public static function assert( $value ): string {
        if ( ! self::is_valid( $value ) ) {
            throw new \InvalidArgumentException(
                sprintf(
                    'YayLoyalty: unknown reward type "%s". Expected one of: %s.',
                    is_scalar( $value ) ? (string) $value : gettype( $value ),
                    implode( ', ', self::all() )
                )
            );
        }

        return $value;
    }
}
