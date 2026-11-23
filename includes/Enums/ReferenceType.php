<?php
namespace YayLoyalty\Enums;

defined( 'ABSPATH' ) || exit;

/**
 * Namespace of a ledger row's reference_id, part of the idempotency key.
 * NULL (manual adjustments) never collides, so those are not idempotent on purpose.
 */
final class ReferenceType {

    use StringEnumMethods {
        is_valid as private is_listed;
    }

    /** WooCommerce order id. */
    public const ORDER = 'order';

    /** WooCommerce refund id, one row per partial refund. */
    public const REFUND = 'refund';

    /** wp_comments id, for review rewards. */
    public const COMMENT = 'comment';

    /** yayloyalty_redemptions id. Accepted before the table ships, so stored rows still validate. */
    public const REDEMPTION = 'redemption';

    /** yayloyalty_referrals id. Accepted before the table ships, so stored rows still validate. */
    public const REFERRAL = 'referral';

    /** wp_users id. */
    public const USER = 'user';

    /** Calendar year, for the annual birthday award. */
    public const BIRTHDAY = 'birthday';

    /** Import batch id. */
    public const IMPORT = 'import';

    /** yayloyalty_tiers id, for a tier's entry bonus. */
    public const TIER = 'tier';

    /** @return string[] */
    public static function all(): array {
        return [
            self::ORDER,
            self::REFUND,
            self::COMMENT,
            self::REDEMPTION,
            self::REFERRAL,
            self::USER,
            self::BIRTHDAY,
            self::IMPORT,
            self::TIER,
        ];
    }

    /** Null is valid: "no reference", as on a manual adjustment. */
    public static function is_valid( $value ): bool {
        return null === $value || self::is_listed( $value );
    }

    /** @throws \InvalidArgumentException When $value is neither null nor known. */
    public static function assert( $value ): ?string {
        if ( ! self::is_valid( $value ) ) {
            throw self::unknown_value( $value, 'null or one of' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- unknown_value() escapes the message it builds.
        }

        return $value;
    }

    protected static function noun(): string {
        return 'reference type';
    }
}
