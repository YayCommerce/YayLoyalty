<?php
namespace YayLoyalty\Engine\Storefront;

use YayLoyalty\Engine\Earning\PendingPoints;
use YayLoyalty\Enums\OrderStatus;
use YayLoyalty\Helpers\DesignHelper;
use YayLoyalty\Helpers\SettingsHelper;
use YayLoyalty\Repositories\CustomerRepository;
use YayLoyalty\Utils\Log;

defined( 'ABSPATH' ) || exit;

/**
 * "You'll earn 270 Points when your order completes" on the order-received page, with the balance.
 * Reads the order meta PendingPoints wrote and the customer row; computes and writes nothing.
 */
final class ThankYouPoints {

    public const CSS_CLASS = 'yayloyalty-thankyou-points';

    /** WooCommerce fires this in the classic template and in the Order Confirmation block; 5 puts it above the order details. */
    public static function register(): void {
        add_action( 'woocommerce_thankyou', [ self::class, 'render' ], 5 );
    }

    /** @param int|string $order_id */
    public static function render( $order_id ): void {
        $order = wc_get_order( (int) $order_id );

        if ( $order instanceof \WC_Order ) {
            echo self::markup( $order ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- markup() escapes every part.
        }
    }

    /** The block for the signed-in owner of an order that earns, or ''. */
    private static function markup( \WC_Order $order ): string {
        try {
            $user_id = get_current_user_id();

            // The order-received URL opens for anyone holding the order key, so the balance shows to its owner only.
            if ( 0 === $user_id || (int) $order->get_customer_id() !== $user_id || $order->has_status( OrderStatus::no_points() ) ) {
                return '';
            }

            $earned  = (int) $order->get_meta( PendingPoints::META_EARNED );
            $pending = PendingPoints::unreleased( $order );

            if ( $earned < 1 && $pending < 1 ) {
                return '';
            }

            $design = DesignHelper::get_design();

            if ( ! DesignHelper::surface_enabled( $design, 'thankyou_message' ) ) {
                return '';
            }

            $customer = ( new CustomerRepository() )->find_by_user_id( $user_id );

            if ( null === $customer ) {
                return '';
            }

            $is_earned = $earned > 0;
            // The raw design value: storefront_text() would fill {points_label} with the plural before the number is known.
            $sentence = LauncherCopy::points_sentence( $design['storefront_text'][ $is_earned ? 'thankyou_earned_line' : 'thankyou_pending_line' ], $is_earned ? $earned : $pending );
            /* translators: %s: points with their label, as "1,240 Points". */
            $balance = sprintf( $is_earned ? __( 'New balance: %s', 'yayloyalty' ) : __( 'Balance: %s', 'yayloyalty' ), SettingsHelper::points_text( $customer->get_balance() ) );

            if ( $customer->get_pending_points() > 0 ) {
                /* translators: %s: points with their label, as "270 Points". */
                $balance .= ' · ' . sprintf( __( '%s pending until your orders complete', 'yayloyalty' ), SettingsHelper::points_text( $customer->get_pending_points() ) );
            }

            return '<section class="' . esc_attr( self::CSS_CLASS ) . '" style="margin:1.5em 0">'
                . '<p class="' . esc_attr( self::CSS_CLASS ) . '__line" style="margin:0"><strong>' . esc_html( $sentence ) . '</strong></p>'
                . '<p class="' . esc_attr( self::CSS_CLASS ) . '__balance" style="margin:0">' . esc_html( $balance ) . '</p>'
                . '</section>';
        } catch ( \Throwable $e ) {
            // A broken block must never take the order page down with it.
            Log::warning( 'ThankYouPoints', 'order #' . $order->get_id() . ': ' . $e->getMessage() );

            return '';
        }
    }
}
