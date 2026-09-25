<?php
namespace YayLoyalty\Utils;

defined( 'ABSPATH' ) || exit;

/** Where WooCommerce keeps its orders, for the admin links that differ between the two storages. */
final class WooOrders {

    /** Whether High-Performance Order Storage is on, so order screens live under `admin.php?page=wc-orders`. */
    public static function is_hpos(): bool {
        return class_exists( \Automattic\WooCommerce\Utilities\OrderUtil::class )
            && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
    }
}
