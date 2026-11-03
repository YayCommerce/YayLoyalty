<?php
namespace YayLoyalty\Engine\Admin;

use YayLoyalty\Access\Capabilities;
use YayLoyalty\Engine\Earning\OrderPointsState;
use YayLoyalty\Repositories\LedgerRepository;
use YayLoyalty\Utils\Log;

defined( 'ABSPATH' ) || exit;

/**
 * The "Loyalty points" column on WooCommerce › Orders, for HPOS and legacy storage: the order's net
 * earned points, a Missing badge that leads the merchant to the order's box (OrderPointsMetabox), or "–".
 * One OrderPointsState::resolve() per row, a few indexed `order_id` queries. It reads the order as
 * the list loaded it, cached copy included: stale "earned" meta with no EARN row still shows Missing.
 */
final class OrderPointsColumn {

    public const KEY = 'yayloyalty_points';

    public static function register(): void {
        add_filter( 'manage_edit-shop_order_columns', [ self::class, 'add' ] );
        add_filter( 'manage_woocommerce_page_wc-orders_columns', [ self::class, 'add' ] );
        add_action( 'manage_shop_order_posts_custom_column', [ self::class, 'render' ], 10, 2 );
        add_action( 'manage_woocommerce_page_wc-orders_custom_column', [ self::class, 'render' ], 10, 2 );
    }

    /**
     * Adds the column after the status column, or last when there is none.
     *
     * @param array<string, string>|mixed $columns
     * @return array<string, string>|mixed
     */
    public static function add( $columns ) {
        if ( ! is_array( $columns ) || ! current_user_can( Capabilities::MANAGE_POINTS ) ) {
            return $columns;
        }

        $title = __( 'Loyalty points', 'yayloyalty' );
        $added = [];

        foreach ( $columns as $key => $label ) {
            $added[ $key ] = $label;

            if ( 'order_status' === $key ) {
                $added[ self::KEY ] = $title;
            }
        }

        if ( ! isset( $added[ self::KEY ] ) ) {
            $added[ self::KEY ] = $title;
        }

        return $added;
    }

    /**
     * @param string|mixed           $column
     * @param int|\WC_Order|mixed    $post_id_or_order The legacy screen passes the post id, the HPOS screen the order.
     */
    public static function render( $column, $post_id_or_order ): void {
        if ( self::KEY !== $column ) {
            return;
        }

        $order = $post_id_or_order instanceof \WC_Order ? $post_id_or_order : wc_get_order( (int) $post_id_or_order );

        if ( ! $order instanceof \WC_Order ) {
            echo '&ndash;';

            return;
        }

        try {
            $state = OrderPointsState::resolve( $order, new LedgerRepository() );
        } catch ( \Throwable $e ) {
            // The orders list outranks the cell.
            Log::warning( 'OrderPointsColumn', 'order #' . $order->get_id() . ': ' . $e->getMessage() );
            echo '&ndash;';

            return;
        }

        echo self::cell( $state ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- cell() escapes every part.
    }

    /** The cell's HTML: net points for a live earn, the Missing badge, or "–". */
    private static function cell( OrderPointsState $state ): string {
        $status = $state->get_status();

        if ( OrderPointsState::EARNED === $status ) {
            return esc_html( number_format_i18n( $state->get_net_earned() ) );
        }

        if ( OrderPointsState::MISSING === $status ) {
            return '<mark class="order-status status-on-hold"><span>' . esc_html__( 'Missing', 'yayloyalty' ) . '</span></mark>';
        }

        return '&ndash;';
    }
}
