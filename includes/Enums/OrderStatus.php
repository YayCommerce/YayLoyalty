<?php
namespace YayLoyalty\Enums;

defined( 'ABSPATH' ) || exit;

/** Order statuses read outside OrderListener: the one that awards points and the ones that show none, as bare slugs. */
final class OrderStatus {

    /** The one status that awards points. It also marks a paid order for customer facts. */
    public const AWARD = 'completed';

    /** An order in the trash, which its own restore and reversal rules handle. */
    public const TRASH = 'trash';

    /**
     * Statuses that count as paid, in the shape wc_get_orders() takes.
     *
     * @return string[]
     */
    public static function paid(): array {
        return [ self::AWARD ];
    }

    /**
     * Statuses whose order shows no points to the shopper: never paid, or reversed.
     *
     * @return string[]
     */
    public static function no_points(): array {
        return [ 'failed', 'cancelled', 'refunded' ];
    }
}
