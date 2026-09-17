<?php
namespace YayLoyalty\Controllers\Fixtures\CustomerDrawer;

use YayLoyalty\Enums\LedgerSource;
use YayLoyalty\Enums\LedgerType;

defined( 'ABSPATH' ) || exit;

/**
 * Per-row synthesis for `DrawerLedger` — split out to keep that file near
 * the 200-line cap. Owns "which type does row N get" and "what points/
 * source does a row of this type get"; `DrawerLedger` owns the envelope,
 * the chain, and filtering.
 */
final class DrawerLedgerGenerator {

    /**
     * How many of each base type to generate. `large` hardcodes the exact
     * split artboard 3c quotes (4,190 + 561 + 38 + 23 = 4,812); everything
     * else is a plausible 75/15/7/3 split with `earn` absorbing the
     * rounding remainder so the four always sum to $count exactly.
     *
     * @return array{0:int,1:int,2:int,3:int} earn, redeem, adjust, expire
     */
    private static function bucket_counts( string $scenario, int $count ): array {
        if ( 'large' === $scenario ) {
            return [ 4190, 561, 38, 23 ];
        }

        if ( 0 === $count ) {
            return [ 0, 0, 0, 0 ];
        }

        $redeem = (int) round( $count * 0.15 );
        $adjust = (int) round( $count * 0.07 );
        $expire = (int) round( $count * 0.03 );

        return [ $count - $redeem - $adjust - $expire, $redeem, $adjust, $expire ];
    }

    /**
     * Which type each of the $count rows gets — a deterministic Fisher-Yates
     * (same LCG recurrence as `DashboardFixture::series()`) assigns exact
     * bucket counts to random-looking positions, so no two buckets fight
     * over the same index.
     *
     * @return string[] Keyed 0..$count-1, index 0 is the newest row.
     */
    public static function type_sequence( string $scenario, int $count ): array {
        [ , $redeem, $adjust, $expire ] = self::bucket_counts( $scenario, $count );

        $types  = array_fill( 0, $count, LedgerType::EARN );
        $order  = self::shuffled_indexes( $count, 17 );
        $cursor = 0;

        foreach ( [ [ LedgerType::REDEEM, $redeem ], [ LedgerType::ADJUST, $adjust ], [ LedgerType::EXPIRE, $expire ] ] as [ $type, $n ] ) {
            for ( $i = 0; $i < $n; $i++ ) {
                $types[ $order[ $cursor ] ] = $type;
                ++$cursor;
            }
        }

        return $types;
    }

    /** @return int[] */
    private static function shuffled_indexes( int $count, int $seed ): array {
        $indexes = range( 0, $count - 1 );

        for ( $i = $count - 1; $i > 0; $i-- ) {
            $seed = ( $seed * 1103515245 + 12345 ) % 2147483648;
            $j    = $seed % ( $i + 1 );

            [ $indexes[ $i ], $indexes[ $j ] ] = [ $indexes[ $j ], $indexes[ $i ] ];
        }

        return $indexes;
    }

    /** Deterministic pseudo-random magnitude per row — a Knuth multiplicative hash of the index, not rand(). */
    public static function points_for( string $type, int $index ): int {
        $seed = ( ( $index + 1 ) * 2654435761 ) % 2000;

        switch ( $type ) {
            case LedgerType::REDEEM:
                return -( 100 + ( $seed % 1900 ) );
            case LedgerType::ADJUST:
                return ( 0 === $index % 2 ? 1 : -1 ) * ( 100 + ( $seed % 900 ) );
            case LedgerType::EXPIRE:
                return -( 200 + ( $seed % 1800 ) );
            case LedgerType::EARN:
            default:
                return 40 + ( $seed % 460 );
        }
    }

    public static function source_for( string $type ): string {
        switch ( $type ) {
            case LedgerType::ADJUST:
                return LedgerSource::MANUAL;
            case LedgerType::EXPIRE:
                return LedgerSource::EXPIRY;
            case LedgerType::EARN:
            case LedgerType::REDEEM:
            default:
                return LedgerSource::ORDER;
        }
    }
}
