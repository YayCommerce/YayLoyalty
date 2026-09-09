<?php
namespace YayLoyalty\Enums;

defined( 'ABSPATH' ) || exit;

/**
 * Namespace of a ledger row's reference_id.
 *
 * (type, reference_type, reference_id) carries the ledger's UNIQUE key, which is
 * the idempotency guarantee against duplicate hooks, order status bouncing and
 * webhook retries.
 *
 * IMPORTANT: MySQL treats NULLs as distinct in a unique index. Rows with a NULL
 * reference — manual adjustments — therefore never collide, which is why manual
 * adjustments are deliberately NOT idempotent: an admin can grant the same
 * points twice on purpose. Intended behaviour, not a defect.
 */
final class ReferenceType {

    /** WooCommerce order id. */
    public const ORDER = 'order';

    /** WooCommerce refund id — one row per partial refund. */
    public const REFUND = 'refund';

    /** wp_comments id, for review rewards. */
    public const COMMENT = 'comment';

    /** yayloyalty_redemptions id. */
    public const REDEMPTION = 'redemption';

    /** yayloyalty_referrals id. */
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

    /**
     * Null is valid: it means "no reference", as on a manual adjustment.
     */
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
