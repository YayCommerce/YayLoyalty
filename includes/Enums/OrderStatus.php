<?php
namespace YayLoyalty\Enums;

defined( 'ABSPATH' ) || exit;

/** The order status that awards points, as woocommerce_order_status_changed reports it (bare slug). */
final class OrderStatus {

    /** The one status that awards points. It also marks a paid order for customer facts. */
    public const AWARD = 'completed';

    /**
     * Statuses that count as paid, in the shape wc_get_orders() takes.
     *
     * @return string[]
     */
    public static function paid(): array {
        return [ self::AWARD ];
    }
}
