<?php
namespace YayLoyalty\Enums;

defined( 'ABSPATH' ) || exit;

/**
 * Kinds of balance movement. assert() runs at the service boundary; the
 * affects_* helpers hold the data model's aggregate rules in one place.
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
     * lifetime_earned = SUM(earn) + SUM(adjust > 0) - SUM(|refund_earn|).
     * Spending never demotes a tier; the caller applies ADJUST's sign.
     */
    public static function affects_lifetime_earned( string $type ): bool {
        return in_array( $type, [ self::EARN, self::REFUND_EARN, self::ADJUST ], true );
    }

    /** lifetime_redeemed = SUM(|redeem|) - SUM(refund_redeem). */
    public static function affects_lifetime_redeemed( string $type ): bool {
        return in_array( $type, [ self::REDEEM, self::REFUND_REDEEM ], true );
    }

    /** Whether the row touches last_activity_at: the last earn or redeem. */
    public static function is_activity( string $type ): bool {
        return in_array( $type, [ self::EARN, self::REDEEM ], true );
    }
}
