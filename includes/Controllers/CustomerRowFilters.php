<?php
namespace YayLoyalty\Controllers;

use YayLoyalty\Enums\CustomerFilters;
use YayLoyalty\Utils\Dates;

defined( 'ABSPATH' ) || exit;

/**
 * The Customers list's real search/filter rules — search, balance-range
 * buckets, expiring-soon window, tier — mirrored field-for-field with
 * `customers-page.filter-rows.ts`'s option values so a request returns the
 * same rows the admin list describes in its UI.
 *
 * SQL-FREE ON PURPOSE. `to_criteria()` turns raw wire params into a
 * normalised, logical criteria array — no `$wpdb`, no SQL fragments.
 * `CustomerRepository::where_fragments()` is the only place that turns a
 * criterion into a prepared `WHERE` clause (see docs/code-standards.md §6:
 * `$wpdb` belongs in `Repositories/`, not `Controllers/`). This class still
 * owns every threshold, bucket edge and day-window — the product knowledge
 * survives; only the per-row `array_filter` body it used to run in memory
 * is gone, replaced by `CustomerRepository::paginate_list()`.
 */
final class CustomerRowFilters {

    /** Every param's "not filtering" value — the shape `to_criteria()` and `candidates_for_no_match()` both work with. */
    private const DEFAULTS = [
        'search'        => '',
        'balance_range' => CustomerFilters::BALANCE_RANGES[0],
        'expiring_soon' => CustomerFilters::EXPIRY_RANGES[0],
        'tier'          => CustomerFilters::TIER_RANGES[0],
    ];

    /**
     * Raw wire params off the request, defaulted — the shape every other
     * method here expects.
     *
     * @return array<string, string>
     */
    public static function from_request( \WP_REST_Request $request ): array {
        return [
            'search'        => (string) $request->get_param( 'search' ),
            'balance_range' => (string) $request->get_param( 'balance_range' ),
            'expiring_soon' => (string) $request->get_param( 'expiring_soon' ),
            'tier'          => (string) $request->get_param( 'tier' ),
        ];
    }

    /**
     * Normalises raw filter params into logical criteria
     * `CustomerRepository::where_fragments()` turns into SQL. Same numbers,
     * same windows as the old row-matchers — just returned as bounds instead
     * of applied to one row at a time.
     *
     * @param array<string, string> $filters search / balance_range / expiring_soon / tier
     * @return array{
     *     search: string,
     *     balance_min: int|null,
     *     balance_max: int|null,
     *     expires_from: string|null,
     *     expires_to: string|null,
     *     expires_is_null: bool,
     *     tier: string|null,
     * }
     */
    public static function to_criteria( array $filters ): array {
        $tier = (string) ( $filters['tier'] ?? self::DEFAULTS['tier'] );

        [ $balance_min, $balance_max ]                   = self::balance_bounds( (string) ( $filters['balance_range'] ?? self::DEFAULTS['balance_range'] ) );
        [ $expires_from, $expires_to, $expires_is_null ] = self::expiry_window( (string) ( $filters['expiring_soon'] ?? self::DEFAULTS['expiring_soon'] ) );

        return [
            'search'          => strtolower( trim( (string) ( $filters['search'] ?? self::DEFAULTS['search'] ) ) ),
            'balance_min'     => $balance_min,
            'balance_max'     => $balance_max,
            'expires_from'    => $expires_from,
            'expires_to'      => $expires_to,
            'expires_is_null' => $expires_is_null,
            'tier'            => 'all' === $tier ? null : $tier,
        ];
    }

    /**
     * "Which filter should I drop?" candidate PARAM SETS — same active-filter
     * detection and "isolate the search" rule as before, but returns what to
     * count rather than a count: counting now costs a DB round trip
     * (`CustomerRepository::count_list()`), which only the REST controller
     * can afford to do per candidate.
     *
     * Only returned once at least 2 filters are active — with a single
     * filter, the one candidate is always "no filters at all", which isn't
     * worth suggesting over "Clear all".
     *
     * `key` comes back camelCase (`balanceRange`, not `balance_range`) to
     * match the admin app's `FilterKey` directly — the admin app already owns
     * the option labels (`customers-page.filter-rows.ts`) and builds display
     * text from `key` plus its own filter state, so nothing here translates.
     *
     * @param array<string, string> $filters
     * @return array<string, array<string, string>> filterKey => candidate params
     */
    public static function candidates_for_no_match( array $filters ): array {
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

        $candidates = [];

        foreach ( $active_keys as $key ) {
            $candidate = 'search' === $key
                ? array_merge( self::DEFAULTS, [ 'search' => $filters['search'] ?? '' ] )
                : array_merge( self::DEFAULTS, $filters, [ $key => self::DEFAULTS[ $key ] ] );

            $candidates[ self::param_to_filter_key( $key ) ] = $candidate;
        }

        return $candidates;
    }

    /** `balance_range` (wire/param name) → `balanceRange` (the admin app's `FilterKey`). */
    private static function param_to_filter_key( string $param ): string {
        return lcfirst( str_replace( '_', '', ucwords( $param, '_' ) ) );
    }

    /**
     * @return array{0: int|null, 1: int|null} [min, max] — null means unbounded.
     */
    private static function balance_bounds( string $range ): array {
        switch ( $range ) {
            case 'negative':
                return [ null, -1 ];
            case '0-1000':
                return [ 0, 1000 ];
            case '1000-10000':
                return [ 1001, 10000 ];
            case '10000-plus':
                return [ 10001, null ];
            case 'any':
            default:
                return [ null, null ];
        }
    }

    /**
     * Mirrors the old `matches_expiring_soon()`: a day-window option means
     * "expires between now and now+N days" (already-expired rows match
     * neither window, same as before).
     *
     * @return array{0: string|null, 1: string|null, 2: bool} [from, to, is_null].
     */
    private static function expiry_window( string $option ): array {
        if ( 'none' === $option ) {
            return [ null, null, true ];
        }

        if ( 'off' === $option ) {
            return [ null, null, false ];
        }

        return [ Dates::now(), Dates::offset( (int) $option * DAY_IN_SECONDS ), false ];
    }
}
