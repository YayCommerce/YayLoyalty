<?php
namespace YayLoyalty\Controllers;

defined( 'ABSPATH' ) || exit;

/**
 * Same 8 sort keys as `customers-page.sort-rows.ts`'s `SortKey` union, so a
 * sorted request returns rows in the exact order the admin list's sort
 * carets describe. The tie-break rules below (a row with no expiry date
 * always sorts last regardless of direction; "last activity" sorts by
 * recency, not raw elapsed minutes) have no TS counterpart to mirror
 * anymore — the client-side comparator they were ported from was deleted
 * once sorting moved entirely server-side; this class is now the only place
 * they're implemented.
 *
 * Needed once sort has to reach beyond whatever page already loaded: sorting
 * only the 10 rows on screen and calling it "sorted" is wrong the moment
 * there's a second page. NOT demo-only, same reasoning as
 * `CustomerRowFilters` — real sort semantics, just run in-memory here until
 * `CustomerRepository` can do it in SQL.
 */
final class CustomerRowSort {

    private const KEYS = [ 'name', 'tier', 'balance', 'pending', 'lifetime', 'last_activity', 'expires', 'referrals' ];

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    public static function apply( array $rows, string $orderby, string $order ): array {
        if ( ! in_array( $orderby, self::KEYS, true ) ) {
            return $rows;
        }

        $factor = 'asc' === strtolower( $order ) ? 1 : -1;

        usort(
            $rows,
            function ( array $a, array $b ) use ( $orderby, $factor ) {
                return 'expires' === $orderby
                    ? self::compare_expires( $a, $b, $factor )
                    : $factor * self::compare_by_key( $a, $b, $orderby );
            }
        );

        return $rows;
    }

    /** No-expiry rows sort last regardless of direction — see BalanceCell.tsx. */
    private static function compare_expires( array $a, array $b, int $factor ): int {
        $a_expires = $a['expires_at'];
        $b_expires = $b['expires_at'];

        if ( $a_expires === $b_expires ) {
            return 0;
        }
        if ( null === $a_expires ) {
            return 1;
        }
        if ( null === $b_expires ) {
            return -1;
        }

        return $factor * strcmp( (string) $a_expires, (string) $b_expires );
    }

    private static function compare_by_key( array $a, array $b, string $key ): int {
        switch ( $key ) {
            case 'name':
                return strcasecmp( (string) $a['name'], (string) $b['name'] );
            case 'tier':
                return strcasecmp( (string) $a['tier'], (string) $b['tier'] );
            case 'balance':
                return $a['balance'] <=> $b['balance'];
            case 'pending':
                return $a['pending_points'] <=> $b['pending_points'];
            case 'lifetime':
                return $a['lifetime_earned'] <=> $b['lifetime_earned'];
            case 'last_activity':
                // Swapped, not `$a <=> $b`: `last_activity_minutes_ago` is
                // "time elapsed" (smaller = more recent), so swapping a/b
                // makes desc sort by recency (most-recent first) instead of
                // by the raw elapsed value (which would put the OLDEST
                // activity first under desc).
                return $b['last_activity_minutes_ago'] <=> $a['last_activity_minutes_ago'];
            case 'referrals':
                return $a['referrals'] <=> $b['referrals'];
            default:
                return 0;
        }
    }
}
