<?php
namespace YayLoyalty\Enums;

defined( 'ABSPATH' ) || exit;

/**
 * Kinds of balance movement. assert() runs at the service boundary.
 * The affects_* helpers hold the aggregate rules in one place.
 */
final class LedgerType {

    use StringEnumMethods;

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

    /** Manual admin adjustment, positive or negative, that also moves lifetime_earned. */
    public const ADJUST = 'adjust';

    /** Manual adjustment written with "adjust lifetime totals" off. It moves the balance only. */
    public const CORRECTION = 'correction';

    /** @return string[] */
    public static function all(): array {
        return [
            self::EARN,
            self::REDEEM,
            self::REFUND_EARN,
            self::REFUND_REDEEM,
            self::EXPIRE,
            self::ADJUST,
            self::CORRECTION,
        ];
    }

    /**
     * lifetime_earned = SUM(earn) + SUM(adjust) - SUM(|refund_earn|). Spending (redeem, expire)
     * and `correction` never move it, so they never demote a tier. An admin's `adjust` deduction can.
     */
    public static function affects_lifetime_earned( string $type ): bool {
        return in_array( $type, [ self::EARN, self::REFUND_EARN, self::ADJUST ], true );
    }

    /** @return string[] Types that feed customers.lifetime_earned. */
    public static function lifetime_earned_types(): array {
        return array_values( array_filter( self::all(), [ self::class, 'affects_lifetime_earned' ] ) );
    }

    /** Whether the row is an admin's manual adjustment, with or without lifetime totals. */
    public static function is_manual_adjustment( string $type ): bool {
        return in_array( $type, [ self::ADJUST, self::CORRECTION ], true );
    }

    /** lifetime_redeemed = SUM(|redeem|) - SUM(refund_redeem). */
    public static function affects_lifetime_redeemed( string $type ): bool {
        return in_array( $type, [ self::REDEEM, self::REFUND_REDEEM ], true );
    }

    /** @return string[] Types that feed customers.lifetime_redeemed. */
    public static function lifetime_redeemed_types(): array {
        return array_values( array_filter( self::all(), [ self::class, 'affects_lifetime_redeemed' ] ) );
    }

    /** Whether the row touches last_activity_at: the last earn or redeem. */
    public static function is_activity( string $type ): bool {
        return in_array( $type, [ self::EARN, self::REDEEM ], true );
    }

    protected static function noun(): string {
        return 'ledger type';
    }
}
