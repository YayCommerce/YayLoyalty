<?php
namespace YayLoyalty\Engine\Storefront;

use YayLoyalty\Engine\Earning\PendingPoints;
use YayLoyalty\Engine\Register\ScriptName;
use YayLoyalty\Enums\OrderStatus;
use YayLoyalty\Helpers\DesignHelper;
use YayLoyalty\Helpers\SettingsHelper;
use YayLoyalty\Repositories\CustomerRepository;
use YayLoyalty\Utils\Log;

defined( 'ABSPATH' ) || exit;

/**
 * The "Rewards" card on the order-received page: the balance and "+270 Points from this order", pending until the order releases them, then earned.
 * Reads the order meta PendingPoints wrote and the customer row; computes and writes nothing.
 * Markup in templates/thankyou-points.php, styles in assets/storefront/thankyou-points.css.
 */
final class ThankYouPoints {

    public const CSS_CLASS = 'yayloyalty-thankyou-points';

    private const STYLE_PATH = 'assets/storefront/thankyou-points.css';

    /**
     * WooCommerce fires this in the classic template and in the Order Confirmation block; 20 puts the card
     * after the order details and the addresses (WooCommerce prints those at 10).
     */
    public static function register(): void {
        add_action( 'woocommerce_thankyou', [ self::class, 'render' ], 20 );
        add_action( 'wp_enqueue_scripts', [ self::class, 'enqueue' ] );
    }

    /** On the order-received page only; the file is small, so it loads whether or not the card shows. */
    public static function enqueue(): void {
        if ( function_exists( 'is_order_received_page' ) && is_order_received_page() ) {
            wp_enqueue_style( ScriptName::THANKYOU_STYLE, YAYLOYALTY_PLUGIN_URL . self::STYLE_PATH, [], Launcher::asset_version( self::STYLE_PATH ) );
        }
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
            $points    = $is_earned ? $earned : $pending;
            $hint      = $is_earned
                /* translators: keep every {…} placeholder as it is; each is filled in when the line is shown. */
                ? _n( '{points} {points_label} was added to your balance.', '{points} {points_label} were added to your balance.', $points, 'yayloyalty' )
                /* translators: keep every {…} placeholder as it is; each is filled in when the line is shown. */
                : __( '{points} {points_label} will be added when your order is completed.', 'yayloyalty' );

            $yayloyalty_thankyou = [
                'state'   => $is_earned ? 'earned' : 'pending',
                /* translators: keep every {…} placeholder as it is; each is filled in when the line is shown. */
                'line'    => LauncherCopy::points_sentence_html( __( '+{points} {points_label} from this order', 'yayloyalty' ), $points ),
                'hint'    => strtr(
                    $hint,
                    [
                        '{points}'       => LauncherCopy::points( $points ),
                        '{points_label}' => SettingsHelper::points_label( $points ),
                    ]
                ),
                'balance' => $customer->get_balance(),
            ];

            return (string) OutputCapture::capture(
                static function () use ( $yayloyalty_thankyou ): void {
                    include __DIR__ . '/templates/thankyou-points.php';
                },
                'ThankYouPoints'
            );
        } catch ( \Throwable $e ) {
            // A broken block must never take the order page down with it.
            Log::warning( 'ThankYouPoints', 'order #' . $order->get_id() . ': ' . $e->getMessage() );

            return '';
        }
    }
}
