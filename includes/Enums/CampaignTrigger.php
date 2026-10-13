<?php
namespace YayLoyalty\Enums;

defined( 'ABSPATH' ) || exit;

/**
 * The event that fires a campaign, and the key to the per-trigger config whitelist.
 * Pro triggers stay constants so a downgraded site's rows are not rejected as corrupt.
 * all() returns only what this build can fire.
 */
final class CampaignTrigger {

    use StringEnumMethods;

    public const PURCHASE = 'purchase';
    public const SIGNUP   = 'signup';
    public const REVIEW   = 'review';

    // Pro: recognised when stored, offered only by Pro.

    public const BIRTHDAY        = 'birthday';
    public const FIRST_ORDER     = 'first_order';
    public const ORDER_COUNT     = 'order_count';
    public const SPEND_MILESTONE = 'spend_milestone';

    // Recognised when stored, offered by no build yet.

    public const REFERRAL = 'referral';
    public const SOCIAL   = 'social';
    public const RENEWAL  = 'renewal';

    /**
     * Triggers this build can fire. Filtered, unlike all_known(): downgrade safety needs the recognised set to stay complete.
     *
     * @return string[]
     */
    public static function all(): array {
        /**
         * Campaign triggers this build can fire. Internal Pro seam, not a public API.
         *
         * @param string[] $triggers
         */
        return apply_filters( 'yayloyalty_campaign_triggers', self::lite_triggers() );
    }

    /**
     * Lite's own triggers, unfiltered, so all_known() never depends on the build.
     *
     * @return string[]
     */
    private static function lite_triggers(): array {
        return [
            self::PURCHASE,
            self::SIGNUP,
            self::REVIEW,
        ];
    }

    /**
     * Every trigger the schema recognises, Pro included. Unfiltered: for validating stored rows, never for offering choices.
     *
     * @return string[]
     */
    public static function all_known(): array {
        return array_merge(
            self::lite_triggers(),
            [
                self::BIRTHDAY,
                self::FIRST_ORDER,
                self::ORDER_COUNT,
                self::SPEND_MILESTONE,
                self::REFERRAL,
                self::SOCIAL,
                self::RENEWAL,
            ]
        );
    }

    public static function is_known( $value ): bool {
        return is_string( $value ) && in_array( $value, self::all_known(), true );
    }

    protected static function noun(): string {
        return 'campaign trigger';
    }
}
