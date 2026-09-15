<?php
namespace YayLoyalty\Controllers;

defined( 'ABSPATH' ) || exit;

/**
 * The Customers list's real sort keys, mirrored field-for-field with
 * `customers-page.sort-rows.ts`'s `SortKey` union, so a sorted request
 * returns rows in the exact order the admin list's sort carets describe.
 *
 * SQL-FREE ON PURPOSE. `to_order()` turns a raw `orderby`/`order` pair into a
 * logical `{column, direction, nulls_last}` descriptor — no SQL. Only
 * `CustomerRepository::order_by_sql()` maps that logical column onto an
 * actual (join-qualified) SQL expression and emits `ORDER BY` (see
 * docs/code-standards.md §6). This class still owns the one real tie-break
 * rule that isn't expressible as a plain column: no-expiry rows always sort
 * last, regardless of direction — `nulls_last` carries that decision.
 *
 * `tier`/`referrals` are dropped from `KEYS`: no tiers table and no referral
 * count column exist yet, so there's nothing to sort by. An unknown/dropped
 * key falls back to the default order (`balance` `DESC`) rather than
 * erroring — same forgiving behaviour as `AbstractRepository::sanitize_orderby()`.
 */
final class CustomerRowSort {

    private const KEYS = [ 'name', 'balance', 'pending', 'lifetime', 'last_activity', 'expires' ];

    private const DEFAULT_COLUMN    = 'balance';
    private const DEFAULT_DIRECTION = 'DESC';

    /**
     * @return array{column: string, direction: string, nulls_last: bool}
     */
    public static function to_order( string $orderby, string $order ): array {
        $column = in_array( $orderby, self::KEYS, true ) ? $orderby : self::DEFAULT_COLUMN;

        return [
            'column'     => $column,
            'direction'  => 'ASC' === strtoupper( $order ) ? 'ASC' : self::DEFAULT_DIRECTION,
            // No-expiry rows sort last regardless of direction — see BalanceCell.tsx.
            'nulls_last' => 'expires' === $column,
        ];
    }
}
