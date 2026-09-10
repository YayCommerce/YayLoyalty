<?php
namespace YayLoyalty\Enums;

defined( 'ABSPATH' ) || exit;

/**
 * Kinds of balance movement.
 *
 * PHP 7.4 has no enums, so closed vocabularies are a final class of constants
 * plus a validator. assert() is called at the SERVICE boundary, never in a
 * repository: with no compile-time check, an invalid value would otherwise
 * surface only as a DB error, far from the caller and naming a column rather
 * than the mistake.
 *
 * The affects_* helpers encode the data model's aggregate rules in one place so
 * LedgerService never open-codes them.
 */
final class LedgerType {

    /** Points granted. */
    public const EARN = 'earn';

    /** Points spent on a reward. */
    public const REDEEM = 'redeem';

    /** Clawback of earned points after a refund. */
    public const REFUND_EARN = 'refund_earn';

    /** Return of redeemed points after a refund or cancellation. */
    public const REFUND_REDEEM = 'refund_redeem';

    /** Points removed by the expiry job. */
    public const EXPIRE = 'expire';

    /** Manual admin adjustment, positive or negative. */
    public const ADJUST = 'adjust';

    /**
     * @return string[]
     */
    public static function all(): array {
        return [
            self::EARN,
            self::REDEEM,
            self::REFUND_EARN,
            self::REFUND_REDEEM,
            self::EXPIRE,
            self::ADJUST,
        ];
    }

    public static function is_valid( $value ): bool {
        return is_string( $value ) && in_array( $value, self::all(), true );
    }

    /**
     * @throws \InvalidArgumentException When $value is not a known type.
     */
    public static function assert( $value ): string {
        if ( ! self::is_valid( $value ) ) {
            throw new \InvalidArgumentException(
                sprintf(
                    'YayLoyalty: unknown ledger type "%s". Expected one of: %s.',
                    is_scalar( $value ) ? (string) $value : gettype( $value ),
                    implode( ', ', self::all() )
                )
            );
        }

        return $value;
    }

    /**
     * Does a row of this type move customers.lifetime_earned?
     *
     * Data model: lifetime_earned = SUM(earn) + SUM(adjust > 0) - SUM(|refund_earn|).
     * redeem and expire never touch it, so spending points never demotes a tier.
     *
     * ADJUST is included because a positive adjustment counts as earning; the
     * caller applies the sign, and a negative adjust must not increase it.
     */
    public static function affects_lifetime_earned( string $type ): bool {
        return in_array( $type, [ self::EARN, self::REFUND_EARN, self::ADJUST ], true );
    }

    /**
     * Does a row of this type move customers.lifetime_redeemed?
     *
     * Data model: lifetime_redeemed = SUM(|redeem|) - SUM(refund_redeem).
     */
    public static function affects_lifetime_redeemed( string $type ): bool {
        return in_array( $type, [ self::REDEEM, self::REFUND_REDEEM ], true );
    }

    /**
     * Does a row of this type count as customer activity for expiry purposes?
     *
     * Data model: last_activity_at tracks the last earn or redeem.
     */
    public static function is_activity( string $type ): bool {
        return in_array( $type, [ self::EARN, self::REDEEM ], true );
    }
}
