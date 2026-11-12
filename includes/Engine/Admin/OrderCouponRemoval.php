<?php
namespace YayLoyalty\Engine\Admin;

use Automattic\WooCommerce\Utilities\OrderUtil;
use YayLoyalty\Access\Capabilities;
use YayLoyalty\Engine\Earning\Outcome;
use YayLoyalty\Engine\Redemption\Reversal;
use YayLoyalty\Engine\Storefront\Launcher;
use YayLoyalty\Helpers\SettingsHelper;
use YayLoyalty\Models\Redemption;
use YayLoyalty\Repositories\RedemptionRepository;
use YayLoyalty\Repositories\RewardRepository;
use YayLoyalty\Utils\Log;

defined( 'ABSPATH' ) || exit;

/**
 * Lite's order-screen half of the admin-removal restore, for HPOS and legacy storage. Removing a loyalty
 * coupon line whose row still has points to return opens a dialog (assets/admin/order-coupon-removal.js and .css).
 * "Restore" tags WooCommerce's own `woocommerce_remove_order_coupon` request rather than sending one of
 * ours, so WooCommerce keeps doing the removal (taxes, usage count, totals, its note). Inside that request
 * a one-shot listener restores through Reversal::coupon_removed() when WooCommerce's save deletes the line.
 * "Don't restore" tags the same request, and the listener only remembers the choice (Reversal::keep_debited()).
 * Every other way a line leaves an order moves no points.
 */
final class OrderCouponRemoval {

    public const HANDLE = 'yayloyalty-order-coupon-removal';

    private const NONCE_ACTION = 'yayloyalty-restore-coupon';

    private const NONCE_FIELD = 'yayloyalty_nonce';

    private Reversal $reversal;

    private RedemptionRepository $redemptions;

    /** Read-only: the reward's name for the dialog and the notes. */
    private RewardRepository $rewards;

    public function __construct( Reversal $reversal, RedemptionRepository $redemptions, RewardRepository $rewards ) {
        $this->reversal    = $reversal;
        $this->redemptions = $redemptions;
        $this->rewards     = $rewards;
    }

    public function register(): void {
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue' ] );
        // Before WC_AJAX::remove_order_coupon() at priority 10, so a refusal stops it.
        add_action( 'wp_ajax_woocommerce_remove_order_coupon', [ $this, 'before_remove' ], 1 );
    }

    /** On the order edit screen, for MANAGE_POINTS, when at least one of the order's rows has points to return. */
    public function enqueue(): void {
        $screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

        if ( null === $screen || wc_get_page_screen_id( 'shop-order' ) !== $screen->id || ! OrderUtil::is_order_edit_screen() || ! current_user_can( Capabilities::MANAGE_POINTS ) ) {
            return;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- reads which order the screen shows; changes nothing.
        $order_id = absint( wp_unslash( $_GET['id'] ?? $_GET['post'] ?? 0 ) );
        $coupons  = [];

        foreach ( $this->redemptions->for_order( $order_id ) as $row ) {
            $remaining = $row->get_points() - $row->get_restored_points();

            if ( Redemption::STATUS_APPLIED === $row->get_status() && null !== $row->get_coupon_code() && $remaining > 0 ) {
                $coupons[ wc_format_coupon_code( $row->get_coupon_code() ) ] = [
                    'name'      => $this->name_of( $row ),
                    'points'    => SettingsHelper::points_text( $row->get_points() ),
                    'remaining' => SettingsHelper::points_text( $remaining ),
                ];
            }
        }

        if ( [] === $coupons ) {
            return;
        }

        // The file's mtime in the version, so an edited script or stylesheet reaches the browser without a version bump.
        $css = 'assets/admin/order-coupon-removal.css';
        $js  = 'assets/admin/order-coupon-removal.js';
        wp_enqueue_style( self::HANDLE, YAYLOYALTY_PLUGIN_URL . $css, [], Launcher::asset_version( $css ) );
        wp_enqueue_script( self::HANDLE, YAYLOYALTY_PLUGIN_URL . $js, [ 'jquery', 'wc-admin-order-meta-boxes' ], Launcher::asset_version( $js ), true );
        wp_add_inline_script(
            self::HANDLE,
            'window.yayloyaltyCouponRemoval = ' . wp_json_encode(
                [
                    'nonce'   => wp_create_nonce( self::NONCE_ACTION ),
                    'coupons' => $coupons,
                    'i18n'    => [
                        /* translators: %s: the reward's name, as "$5 off". */
                        'title'      => __( 'Remove %s?', 'yayloyalty' ),
                        /* translators: %s: points, as "500 points". */
                        'spent'      => __( 'The customer spent %s on this discount.', 'yayloyalty' ),
                        /* translators: %s: points, as "300 points". */
                        'unreturned' => __( '%s of them have not been returned yet.', 'yayloyalty' ),
                        /* translators: %s: points, as "500 points". */
                        'restore'    => __( 'Restore %s', 'yayloyalty' ),
                        /* translators: %s: the store's points label, as "points". */
                        'keep'       => sprintf( __( 'Don\'t restore %s', 'yayloyalty' ), SettingsHelper::points_label_plural() ),
                        'cancel'     => __( 'Cancel', 'yayloyalty' ),
                    ],
                ]
            ) . ';',
            'before'
        );
    }

    /**
     * Priority 1 on WooCommerce's remove-coupon request. Without `yayloyalty_restore` or `yayloyalty_keep` it does nothing.
     * With one, a bad nonce or a missing capability answers an error WooCommerce's script shows, and WooCommerce's handler never runs.
     */
    public function before_remove(): void {
        // phpcs:disable WordPress.Security.NonceVerification.Missing -- the flags only decide whether to check our nonce below.
        $is_restore = ! empty( $_POST['yayloyalty_restore'] );
        $is_keep    = ! empty( $_POST['yayloyalty_keep'] );
        // phpcs:enable WordPress.Security.NonceVerification.Missing

        if ( ! $is_restore && ! $is_keep ) {
            return;
        }

        if ( false === check_ajax_referer( self::NONCE_ACTION, self::NONCE_FIELD, false ) ) {
            wp_send_json_error( [ 'error' => __( 'This page has expired. Reload it and try again.', 'yayloyalty' ) ] );
        }

        if ( ! current_user_can( Capabilities::MANAGE_POINTS ) ) {
            wp_send_json_error( [ 'error' => __( 'You are not allowed to restore points.', 'yayloyalty' ) ] );
        }

        $order_id = absint( wp_unslash( $_POST['order_id'] ?? 0 ) );
        $code     = wc_format_coupon_code( sanitize_text_field( wp_unslash( $_POST['coupon'] ?? '' ) ) );

        if ( 0 === $order_id || '' === $code ) {
            return;
        }

        $listener = null;
        $listener = function ( $item_id ) use ( $order_id, $code, $is_restore, &$listener ): void {
            $item = \WC_Order_Factory::get_order_item( absint( $item_id ) );

            if ( ! $item instanceof \WC_Order_Item_Coupon || (int) $item->get_order_id() !== $order_id || wc_format_coupon_code( $item->get_code() ) !== $code ) {
                return;
            }

            remove_action( 'woocommerce_before_delete_order_item', $listener );

            if ( $is_restore ) {
                $this->restore( $order_id, $code );
            } else {
                $this->keep( $order_id, $code );
            }
        };

        add_action( 'woocommerce_before_delete_order_item', $listener );
    }

    /** Restores the line's row and notes the result on the order. Never throws, so the line still leaves the order. */
    private function restore( int $order_id, string $code ): void {
        $order = wc_get_order( $order_id );

        if ( ! $order instanceof \WC_Order ) {
            return;
        }

        $row  = $this->redemptions->find_by_order_and_coupon_code( $order_id, $code );
        $name = null === $row ? $code : $this->name_of( $row );

        try {
            $outcome = $this->reversal->coupon_removed( $order, $code );
        } catch ( \Throwable $e ) {
            Log::error( self::class, sprintf( 'Restoring order #%d coupon %s failed: %s', $order_id, $code, $e->getMessage() ) );
            self::note_failure( $order, $name );

            return;
        }

        if ( null === $outcome ) {
            return;
        }

        if ( Outcome::AWARDED === $outcome->get_status() ) {
            $points = null === $outcome->get_entry() ? 0 : abs( $outcome->get_entry()->get_points() );
            /* translators: 1: the reward's name, as "$5 off", 2: points, as "500 points". */
            $order->add_order_note( sprintf( __( '%1$s removed — %2$s restored.', 'yayloyalty' ), $name, SettingsHelper::points_text( $points ) ), 0, true );

            return;
        }

        if ( Reversal::REASON_KEY_SPENT === $outcome->get_reason() ) {
            Log::error( self::class, sprintf( 'Restoring order #%d coupon %s found its ledger key spent.', $order_id, $code ) );
            self::note_failure( $order, $name );
        }
    }

    /** Remembers "Don't restore" and notes it on the order. Never throws, so the line still leaves the order. */
    private function keep( int $order_id, string $code ): void {
        $order = wc_get_order( $order_id );

        if ( ! $order instanceof \WC_Order ) {
            return;
        }

        $row  = $this->redemptions->find_by_order_and_coupon_code( $order_id, $code );
        $name = null === $row ? $code : $this->name_of( $row );

        try {
            Reversal::keep_debited( $order, $code );
        } catch ( \Throwable $e ) {
            Log::error( self::class, sprintf( 'Keeping order #%d coupon %s debited failed: %s', $order_id, $code, $e->getMessage() ) );

            return;
        }

        /* translators: %s: the reward's name, as "$5 off". */
        $order->add_order_note( sprintf( __( '%s removed — its points were not restored.', 'yayloyalty' ), $name ), 0, true );
    }

    private static function note_failure( \WC_Order $order, string $name ): void {
        /* translators: %s: the reward's name, as "$5 off". */
        $order->add_order_note( sprintf( __( '%s removed, but its points could not be restored. Adjust the customer\'s points by hand.', 'yayloyalty' ), $name ), 0, true );
    }

    /** The reward's name; "Reward" once the reward is deleted. */
    private function name_of( Redemption $row ): string {
        $reward = $this->rewards->find_reward( $row->get_reward_id() );

        return null === $reward ? __( 'Reward', 'yayloyalty' ) : $reward->get_name();
    }
}
