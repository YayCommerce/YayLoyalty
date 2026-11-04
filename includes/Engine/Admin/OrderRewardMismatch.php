<?php
namespace YayLoyalty\Engine\Admin;

use Automattic\WooCommerce\Utilities\OrderUtil;
use YayLoyalty\Access\Capabilities;
use YayLoyalty\Engine\Redemption\RewardMismatch;
use YayLoyalty\Engine\Storefront\Launcher;
use YayLoyalty\Helpers\SettingsHelper;

defined( 'ABSPATH' ) || exit;

/**
 * The order screen's "Loyalty rewards" box, for HPOS and legacy storage: lists what RewardMismatch finds and repairs
 * one at a time through `orders/{id}/reconcile-reward` (assets/admin/order-reward-mismatch.js and .css).
 * Shows only on an order that has a mismatch.
 */
final class OrderRewardMismatch {

    public const HANDLE = 'yayloyalty-order-reward-mismatch';

    /** Read-only. */
    private RewardMismatch $mismatches;

    public function __construct( RewardMismatch $mismatches ) {
        $this->mismatches = $mismatches;
    }

    public function register(): void {
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue' ] );
        add_action( 'add_meta_boxes', [ $this, 'add_meta_box' ], 10, 2 );
    }

    /**
     * @param string $screen_id
     * @param mixed  $post_or_order WP_Post (legacy) or WC_Order (HPOS).
     */
    public function add_meta_box( $screen_id, $post_or_order = null ): void {
        if ( wc_get_page_screen_id( 'shop-order' ) !== $screen_id || ! current_user_can( Capabilities::MANAGE_POINTS ) ) {
            return;
        }

        $order = OrderMissingPoints::order_of( $post_or_order );
        $found = null === $order ? [] : $this->mismatches->for_order( $order );

        if ( [] === $found ) {
            return;
        }

        add_meta_box(
            'yayloyalty-reward-mismatch',
            __( 'Loyalty rewards', 'yayloyalty' ),
            function () use ( $order, $found ): void {
                $this->render_box( $order, $found );
            },
            $screen_id,
            'side',
            'high'
        );
    }

    public function enqueue(): void {
        $screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

        if ( null === $screen || wc_get_page_screen_id( 'shop-order' ) !== $screen->id || ! OrderUtil::is_order_edit_screen() || ! current_user_can( Capabilities::MANAGE_POINTS ) ) {
            return;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- reads which order the screen shows; changes nothing.
        $order = wc_get_order( absint( wp_unslash( $_GET['id'] ?? $_GET['post'] ?? 0 ) ) );

        if ( ! $order instanceof \WC_Order || [] === $this->mismatches->for_order( $order ) ) {
            return;
        }

        // The file's mtime in the version, so an edited script or stylesheet reaches the browser without a version bump.
        $css = 'assets/admin/order-reward-mismatch.css';
        $js  = 'assets/admin/order-reward-mismatch.js';
        wp_enqueue_style( self::HANDLE, YAYLOYALTY_PLUGIN_URL . $css, [], Launcher::asset_version( $css ) );
        wp_enqueue_script( self::HANDLE, YAYLOYALTY_PLUGIN_URL . $js, [ 'wp-api-fetch' ], Launcher::asset_version( $js ), true );
        wp_add_inline_script(
            self::HANDLE,
            'window.yayloyaltyRewardMismatch = ' . wp_json_encode(
                [
                    'i18n' => [
                        'failed' => __( 'The reward could not be repaired.', 'yayloyalty' ),
                    ],
                ]
            ) . ';',
            'before'
        );
    }

    /** @param array<int, array{kind: string, code: string, points: int, reward: ?\YayLoyalty\Models\Reward}> $found */
    private function render_box( \WC_Order $order, array $found ): void {
        ?>
        <div class="yayloyalty-reward-mismatch" data-order-id="<?php echo esc_attr( (string) $order->get_id() ); ?>">
            <?php foreach ( $found as $mismatch ) : ?>
                <?php $this->render_item( $mismatch ); ?>
            <?php endforeach; ?>
            <p class="yayloyalty-reward-mismatch__error" role="alert" hidden></p>
        </div>
        <?php
    }

    /** @param array{kind: string, code: string, points: int, reward: ?\YayLoyalty\Models\Reward} $mismatch */
    private function render_item( array $mismatch ): void {
        $name     = null === $mismatch['reward'] ? $mismatch['code'] : $mismatch['reward']->get_name();
        $points   = SettingsHelper::points_text( $mismatch['points'] );
        $is_stale = RewardMismatch::KIND_STALE_ROW === $mismatch['kind'];
        ?>
        <div class="yayloyalty-reward-mismatch__item">
            <?php if ( $is_stale ) : ?>
                <?php /* translators: 1: points in the store's format, as "500 Points", 2: the reward's name */ ?>
                <p><?php echo esc_html( sprintf( __( 'The customer paid %1$s for %2$s, but this order no longer has its discount.', 'yayloyalty' ), $points, $name ) ); ?></p>
                <p class="description"><?php esc_html_e( 'If an admin removed this discount and chose not to restore the points, leave it as it is.', 'yayloyalty' ); ?></p>
                <?php /* translators: %s: points in the store's format, as "500 Points". */ ?>
                <?php self::render_button( $mismatch['code'], sprintf( __( 'Restore %s', 'yayloyalty' ), $points ) ); ?>
            <?php elseif ( null === $mismatch['reward'] || $mismatch['points'] < 1 ) : ?>
                <?php /* translators: %s: the reward's name, or its coupon code once the reward is deleted. */ ?>
                <p><?php echo esc_html( sprintf( __( 'This order has the discount of %s, but no points were debited for it, and the reward no longer exists. Adjust the customer\'s points by hand if needed.', 'yayloyalty' ), $name ) ); ?></p>
            <?php else : ?>
                <?php /* translators: 1: the reward's name, 2: points in the store's format, as "500 Points". */ ?>
                <p><?php echo esc_html( sprintf( __( 'This order has the discount of %1$s, but its %2$s were not debited.', 'yayloyalty' ), $name, $points ) ); ?></p>
                <p class="description"><?php esc_html_e( 'Debiting takes the points from the customer\'s balance now. To let them keep the discount, do nothing.', 'yayloyalty' ); ?></p>
                <?php /* translators: %s: points in the store's format, as "500 Points". */ ?>
                <?php self::render_button( $mismatch['code'], sprintf( __( 'Debit %s', 'yayloyalty' ), $points ) ); ?>
            <?php endif; ?>
        </div>
        <?php
    }

    private static function render_button( string $code, string $label ): void {
        ?>
        <p><button type="button" class="button yayloyalty-reward-mismatch__repair" data-code="<?php echo esc_attr( $code ); ?>"><?php echo esc_html( $label ); ?></button></p>
        <?php
    }
}
