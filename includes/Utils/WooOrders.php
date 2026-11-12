<?php
namespace YayLoyalty\Utils;

defined( 'ABSPATH' ) || exit;

/** Where WooCommerce keeps its orders, for what differs between the two storages. */
final class WooOrders {

    /** Whether High-Performance Order Storage is on, so order screens live under `admin.php?page=wc-orders`. */
    public static function is_hpos(): bool {
        return class_exists( \Automattic\WooCommerce\Utilities\OrderUtil::class )
            && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
    }

    /**
     * Saves the order's meta only, never the order, for a caller inside a transaction or an order save, where
     * woocommerce_update_order must not fire. HPOS turns save_meta_data() into save() once date_modified is in the
     * past; the filter is WooCommerce's own switch for that.
     */
    public static function save_meta_only( \WC_Order $order ): void {
        add_filter( 'woocommerce_orders_table_datastore_should_save_after_meta_change', '__return_false' );

        try {
            $order->save_meta_data();
        } finally {
            remove_filter( 'woocommerce_orders_table_datastore_should_save_after_meta_change', '__return_false' );
        }
    }
}
