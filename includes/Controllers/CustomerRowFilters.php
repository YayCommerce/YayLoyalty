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

    /** Every param's "not filtering" value — the same shape `apply()` and `suggestions_for_no_match()` both work with. */
    private const DEFAULTS = [
        'search'        => '',
        'balance_range' => 'any',
        'expiring_soon' => 'off',
        'tier'          => 'all',
    ];

    /**
     * @param array<int, array<string, mixed>> $rows
     * @param array<string, string>            $params search / balance_range / expiring_soon / tier
     * @return array<int, array<string, mixed>>
     */
    public static function apply( array $rows, array $params ): array {
        $search        = strtolower( trim( (string) ( $params['search'] ?? self::DEFAULTS['search'] ) ) );
        $balance_range = (string) ( $params['balance_range'] ?? self::DEFAULTS['balance_range'] );
        $expiring_soon = (string) ( $params['expiring_soon'] ?? self::DEFAULTS['expiring_soon'] );
        $tier          = (string) ( $params['tier'] ?? self::DEFAULTS['tier'] );

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

    /**
     * "Which filter should I drop?" — for `CustomerRestController::get_items()`
     * to embed in the same response once the current filters match zero rows,
     * instead of the admin app firing one extra request per active filter to
     * ask. Mirrors `customers-page.filter-suggestions.ts`'s candidate-building
     * exactly: `search` is isolated (every other filter reverted, search kept
     * — "search on its own"); every other key is just reverted to its default
     * one at a time ("without this one filter").
     *
     * Only returned once at least 2 filters are active — with a single
     * filter, the one candidate is always "no filters at all", which isn't
     * worth suggesting over "Clear all".
     *
     * Raw `{key, count}` pairs, not labels/copy: the admin app already owns
     * the option labels (`customers-page.filter-rows.ts`) and builds display
     * text from `key` plus its own filter state, so nothing here needs
     * translating twice. `key` comes back camelCase (`balanceRange`, not
     * `balance_range`) to match the admin app's `FilterKey` directly.
     *
     * @param array<int, array<string, mixed>> $rows    the FULL (unfiltered) row set
     * @param array<string, string>            $filters the currently-applied params — same shape as apply()
     * @return array<int, array{key: string, count: int}>
     */
    public static function suggestions_for_no_match( array $rows, array $filters ): array {
        $active_keys = array_keys(
            array_filter(
                self::DEFAULTS,
                fn( $default, $key ) => ( $filters[ $key ] ?? $default ) !== $default,
                ARRAY_FILTER_USE_BOTH
            )
        );

        if ( count( $active_keys ) < 2 ) {
            return [];
        }

        $suggestions = [];

        foreach ( $active_keys as $key ) {
            $candidate = 'search' === $key
                ? array_merge( self::DEFAULTS, [ 'search' => $filters['search'] ?? '' ] )
                : array_merge( self::DEFAULTS, $filters, [ $key => self::DEFAULTS[ $key ] ] );

            $count = count( self::apply( $rows, $candidate ) );

            if ( $count > 0 ) {
                $suggestions[] = [
                    'key'   => self::param_to_filter_key( $key ),
                    'count' => $count,
                ];
            }
        }

        usort( $suggestions, fn( $a, $b ) => $b['count'] <=> $a['count'] );

        return $suggestions;
    }

    /** `balance_range` (wire/param name) → `balanceRange` (the admin app's `FilterKey`). */
    private static function param_to_filter_key( string $param ): string {
        return lcfirst( str_replace( '_', '', ucwords( $param, '_' ) ) );
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
