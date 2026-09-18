<?php
namespace YayLoyalty\Enums;

defined( 'ABSPATH' ) || exit;

/**
 * Namespace of a ledger row's reference_id, part of the idempotency key.
 * NULL (manual adjustments) never collides, so those are not idempotent on purpose.
 */
final class ReferenceType {

    /** WooCommerce order id. */
    public const ORDER = 'order';

    /** WooCommerce refund id — one row per partial refund. */
    public const REFUND = 'refund';

    /** wp_comments id, for review rewards. */
    public const COMMENT = 'comment';

    /** yayloyalty_redemptions id (table not implemented yet; accepted so stored rows round-trip). */
    public const REDEMPTION = 'redemption';

    /** yayloyalty_referrals id (table not implemented yet; accepted so stored rows round-trip). */
    public const REFERRAL = 'referral';

    /** wp_users id. */
    public const USER = 'user';

    /** Calendar year, for the annual birthday award. */
    public const BIRTHDAY = 'birthday';

    /** Import batch id. */
    public const IMPORT = 'import';

    /**
     * @return string[]
     */
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
        ];
    }

    /** Null is valid: "no reference", as on a manual adjustment. */
    public static function is_valid( $value ): bool {
        if ( null === $value ) {
            return true;
        }

        return is_string( $value ) && in_array( $value, self::all(), true );
    }

    /**
     * @throws \InvalidArgumentException When $value is neither null nor known.
     */
    public static function assert( $value ): ?string {
        if ( ! self::is_valid( $value ) ) {
            throw new \InvalidArgumentException(
                sprintf(
                    'YayLoyalty: unknown reference type "%s". Expected null or one of: %s.',
                    is_scalar( $value ) ? (string) $value : gettype( $value ),
                    implode( ', ', self::all() )
                )
            );
        }

        return $value;
    }
}
