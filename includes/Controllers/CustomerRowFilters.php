<?php
namespace YayLoyalty\Controllers;

defined( 'ABSPATH' ) || exit;

/**
 * The Customers list's real search/filter rules — search, balance-range
 * buckets, expiring-soon window, tier — mirrored field-for-field with
 * `customers-page.filter-rows.ts`'s option values so a request returns the
 * same rows the admin list describes in its UI.
 *
 * NOT demo-only, unlike `CustomerDemoRows`: these are the actual filter
 * semantics the product exposes, only running in-memory over the fixed demo
 * rows for now because there's no real row source yet. `apply()`'s current
 * array-filter body gets replaced with real SQL `WHERE` clauses once
 * `CustomerRepository` gains a paginate/filter method (see
 * `CustomerRestController::get_items()`) — this class or its logic doesn't
 * go away the way `CustomerDemoRows` does.
 */
final class CustomerRowFilters {

    /**
     * @param array<int, array<string, mixed>> $rows
     * @param array<string, string>            $params search / balance_range / expiring_soon / tier
     * @return array<int, array<string, mixed>>
     */
    public static function apply( array $rows, array $params ): array {
        $search        = strtolower( trim( (string) ( $params['search'] ?? '' ) ) );
        $balance_range = (string) ( $params['balance_range'] ?? 'any' );
        $expiring_soon = (string) ( $params['expiring_soon'] ?? 'off' );
        $tier          = (string) ( $params['tier'] ?? 'all' );

        return array_values(
            array_filter(
                $rows,
                function ( array $row ) use ( $search, $balance_range, $expiring_soon, $tier ) {
                    return self::matches_search( $row, $search )
                        && self::matches_balance_range( (int) $row['balance'], $balance_range )
                        && self::matches_expiring_soon( $row['expires_at'], $expiring_soon )
                        && self::matches_tier( (string) $row['tier'], $tier );
                }
            )
        );
    }

    private static function matches_search( array $row, string $search ): bool {
        return '' === $search || false !== strpos( strtolower( (string) $row['name'] ), $search );
    }

    private static function matches_balance_range( int $balance, string $range ): bool {
        switch ( $range ) {
            case 'negative':
                return $balance < 0;
            case '0-1000':
                return $balance >= 0 && $balance <= 1000;
            case '1000-10000':
                return $balance > 1000 && $balance <= 10000;
            case '10000-plus':
                return $balance > 10000;
            case 'any':
            default:
                return true;
        }
    }

    /** Days until `expires_at`, evaluated against now — mirrors the TS version. */
    private static function matches_expiring_soon( $expires_at, string $option ): bool {
        if ( 'off' === $option ) {
            return true;
        }

        if ( 'none' === $option ) {
            return null === $expires_at;
        }

        if ( null === $expires_at ) {
            return false;
        }

        $days_until    = ( strtotime( (string) $expires_at ) - time() ) / DAY_IN_SECONDS;
        $within_days   = (float) $option;

        return $days_until >= 0 && $days_until <= $within_days;
    }

    private static function matches_tier( string $row_tier, string $filter_tier ): bool {
        return 'all' === $filter_tier || $row_tier === $filter_tier;
    }
}
