<?php
namespace YayLoyalty\Controllers;

use YayLoyalty\Enums\CustomerFilters;
use YayLoyalty\Utils\Dates;

defined( 'ABSPATH' ) || exit;

/**
 * The Customers list's filter rules: search, balance buckets and the
 * expiring-soon window. SQL-free: it owns every threshold and edge, and
 * CustomerRepository turns them into WHERE.
 */
final class CustomerRowFilters {

    /** Every param's "not filtering" value. */
    private const DEFAULTS = [
        'search'        => '',
        'balance_range' => CustomerFilters::BALANCE_RANGES[0],
        'expiring_soon' => CustomerFilters::EXPIRY_RANGES[0],
    ];

    /**
     * Wire params off the request, defaulted.
     *
     * @return array<string, string>
     */
    public static function from_request( \WP_REST_Request $request ): array {
        return [
            'search'        => (string) $request->get_param( 'search' ),
            'balance_range' => (string) $request->get_param( 'balance_range' ),
            'expiring_soon' => (string) $request->get_param( 'expiring_soon' ),
        ];
    }

    /**
     * Normalises filter params into the logical criteria CustomerRepository turns into SQL.
     *
     * @param array<string, string> $filters search / balance_range / expiring_soon
     * @return array{
     *     search: string,
     *     balance_min: int|null,
     *     balance_max: int|null,
     *     expires_from: string|null,
     *     expires_to: string|null,
     *     expires_is_null: bool,
     * }
     */
    public static function to_criteria( array $filters ): array {
        [ $balance_min, $balance_max ]                   = self::balance_bounds( (string) ( $filters['balance_range'] ?? self::DEFAULTS['balance_range'] ) );
        [ $expires_from, $expires_to, $expires_is_null ] = self::expiry_window( (string) ( $filters['expiring_soon'] ?? self::DEFAULTS['expiring_soon'] ) );

        return [
            'search'          => strtolower( trim( (string) ( $filters['search'] ?? self::DEFAULTS['search'] ) ) ),
            'balance_min'     => $balance_min,
            'balance_max'     => $balance_max,
            'expires_from'    => $expires_from,
            'expires_to'      => $expires_to,
            'expires_is_null' => $expires_is_null,
        ];
    }

    /**
     * "Which filter should I drop?" candidate param sets, for the controller
     * to count. Empty below two active filters: the one candidate would be
     * "no filters", not worth suggesting over "Clear all".
     *
     * @param array<string, string> $filters
     * @return array<string, array<string, string>> param name => candidate params
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

            $candidates[ $key ] = $candidate;
        }

        return $candidates;
    }

    /**
     * @return array{0: int|null, 1: int|null} [min, max], where null means unbounded.
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
     * A day-window option means now to now + N days. Already-expired rows match neither.
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
