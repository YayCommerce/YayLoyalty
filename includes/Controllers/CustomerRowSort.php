<?php
namespace YayLoyalty\Controllers;

defined( 'ABSPATH' ) || exit;

/**
 * The Customers list's sort keys. SQL-free: it yields a logical descriptor
 * that CustomerRepository maps to ORDER BY. No-expiry rows always sort last
 * (nulls_last). An unknown key falls back to balance DESC rather than erroring.
 */
final class CustomerRowSort {

    public const KEYS = [ 'name', 'balance', 'pending', 'lifetime', 'last_activity', 'expires' ];

    public const DEFAULT_COLUMN     = 'balance';
    private const DEFAULT_DIRECTION = 'DESC';

    /** @return array{column: string, direction: string, nulls_last: bool} */
    public static function to_order( string $orderby, string $order ): array {
        $column = in_array( $orderby, self::KEYS, true ) ? $orderby : self::DEFAULT_COLUMN;

        return [
            'column'     => $column,
            'direction'  => 'ASC' === strtoupper( $order ) ? 'ASC' : self::DEFAULT_DIRECTION,
            'nulls_last' => 'expires' === $column,
        ];
    }
}
