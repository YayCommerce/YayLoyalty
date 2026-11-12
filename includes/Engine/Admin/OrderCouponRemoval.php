<?php
namespace YayLoyalty\Engine\Admin;

use Automattic\WooCommerce\Utilities\OrderUtil;
use YayLoyalty\Access\Capabilities;
use YayLoyalty\Engine\Redemption\RemovedLineReconciler;
use YayLoyalty\Engine\Storefront\Launcher;
use YayLoyalty\Helpers\SettingsHelper;
use YayLoyalty\Models\Redemption;
use YayLoyalty\Repositories\RedemptionRepository;
use YayLoyalty\Repositories\RewardRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Lite's order-screen half of the removal restore, for HPOS and legacy storage. The server restores a loyalty line's
 * points however it leaves the order (Redemption\RemovedLineReconciler); this dialog only asks whether to waive that.
 * Removing a loyalty coupon line whose row still has points to return opens it (assets/admin/order-coupon-removal.js
 * and .css). "Don't restore" tags WooCommerce's own `woocommerce_remove_order_coupon` request with
 * `yayloyalty_waive` rather than sending one of ours, so WooCommerce keeps doing the removal (taxes, usage count,
 * totals, its note); "Restore" sends that request untagged.
 */
final class OrderCouponRemoval {

    public const HANDLE = 'yayloyalty-order-coupon-removal';

    private const NONCE_ACTION = 'yayloyalty-restore-coupon';

    private const NONCE_FIELD = 'yayloyalty_nonce';

    private RedemptionRepository $redemptions;

    /** Read-only: the reward's name for the dialog. */
    private RewardRepository $rewards;

    private RemovedLineReconciler $reconciler;

    public function __construct( RedemptionRepository $redemptions, RewardRepository $rewards, RemovedLineReconciler $reconciler ) {
        $this->redemptions = $redemptions;
        $this->rewards     = $rewards;
        $this->reconciler  = $reconciler;
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
     * Priority 1 on WooCommerce's remove-coupon request. It first settles the removal before WooCommerce renders the
     * notes it answers with: WC_AJAX::remove_order_coupon() runs calculate_totals() again after the line is deleted,
     * so the restore note shows without a reload. Without `yayloyalty_waive` it does nothing more. With it, a bad nonce
     * or a missing capability answers an error WooCommerce's script shows, and WooCommerce's handler never runs.
     */
    public function before_remove(): void {
        add_action( 'woocommerce_order_after_calculate_totals', [ $this->reconciler, 'settle' ] );

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- the flag only decides whether to check our nonce below.
        if ( empty( $_POST['yayloyalty_waive'] ) ) {
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

        $this->reconciler->waive( $order_id, $code );
    }

    /** The reward's name; "Reward" once the reward is deleted. */
    private function name_of( Redemption $row ): string {
        $reward = $this->rewards->find_reward( $row->get_reward_id() );

        return null === $reward ? __( 'Reward', 'yayloyalty' ) : $reward->get_name();
    }
}
