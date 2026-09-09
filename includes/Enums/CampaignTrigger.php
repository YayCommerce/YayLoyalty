<?php
namespace YayLoyalty\Enums;

defined( 'ABSPATH' ) || exit;

/**
 * The event that fires a campaign.
 *
 * The trigger determines which keys `config` may contain, so this enum is also
 * the key to the per-trigger config whitelist in Campaign::validate_config().
 *
 * Pro triggers exist as constants because stored rows may reference them — a
 * site that downgrades from Pro must not have its campaigns rejected as
 * corrupt — but all() returns only what this build can actually fire.
 */
final class CampaignTrigger {

    /* --- 1.0 --- */

    public const PURCHASE = 'purchase';
    public const SIGNUP   = 'signup';
    public const REVIEW   = 'review';

    /* --- Pro. Recognised when stored, not offered in Free. --- */

    public const BIRTHDAY        = 'birthday';
    public const FIRST_ORDER     = 'first_order';
    public const ORDER_COUNT     = 'order_count';
    public const SPEND_MILESTONE = 'spend_milestone';
    public const REFERRAL        = 'referral';
    public const SOCIAL          = 'social';
    public const RENEWAL         = 'renewal';

    /**
     * Triggers this build can fire.
     *
     * @return string[]
     */
    public static function all(): array {
        return [
            self::PURCHASE,
            self::SIGNUP,
            self::REVIEW,
        ];
    }

    /**
     * Every trigger the schema recognises, including Pro.
     *
     * Used for validating stored rows, never for offering choices.
     *
     * @return string[]
     */
    public static function all_known(): array {
        return array_merge(
            self::all(),
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

    public static function is_valid( $value ): bool {
        return is_string( $value ) && in_array( $value, self::all(), true );
    }

    public static function is_known( $value ): bool {
        return is_string( $value ) && in_array( $value, self::all_known(), true );
    }

    /**
     * @throws \InvalidArgumentException When $value is not fireable in this build.
     */
    public static function assert( $value ): string {
        if ( ! self::is_valid( $value ) ) {
            throw new \InvalidArgumentException(
                sprintf(
                    'YayLoyalty: unknown campaign trigger "%s". Expected one of: %s.',
                    is_scalar( $value ) ? (string) $value : gettype( $value ),
                    implode( ', ', self::all() )
                )
            );
        }

        return $value;
    }
}
