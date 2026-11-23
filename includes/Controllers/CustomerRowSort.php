<?php
namespace YayLoyalty\Controllers;

defined( 'ABSPATH' ) || exit;

/**
 * The Customers list's sort keys. SQL-free: it yields a logical descriptor
 * that CustomerRepository maps to ORDER BY. No-expiry rows always sort last
 * (nulls_last), and so do tierless rows. An unknown key falls back to balance DESC rather than erroring.
 */
final class CustomerRowSort {

    public const KEYS = [ 'name', 'balance', 'pending', 'lifetime', 'last_activity', 'expires', 'tier' ];

    public const DEFAULT_COLUMN     = 'balance';
    private const DEFAULT_DIRECTION = 'DESC';

    /**
     * @param int[] $tier_ids_by_rank Live tier ids, lowest rank first; the `tier` key sorts by position
     *                                in this list, so DESC puts the top tier first.
     * @return array{column: string, direction: string, nulls_last: bool, tier_ids?: int[]}
     */
    public static function to_order( string $orderby, string $order, array $tier_ids_by_rank = [] ): array {
        $column = in_array( $orderby, self::KEYS, true ) ? $orderby : self::DEFAULT_COLUMN;

        $sort = [
            'column'     => $column,
            'direction'  => 'ASC' === strtoupper( $order ) ? 'ASC' : self::DEFAULT_DIRECTION,
            'nulls_last' => in_array( $column, [ 'expires', 'tier' ], true ),
        ];

        if ( 'tier' === $column ) {
            $sort['tier_ids'] = array_values( array_map( 'intval', $tier_ids_by_rank ) );
        }

        return $sort;
    }
}
