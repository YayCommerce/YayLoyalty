<?php
namespace YayLoyalty\Engine\Admin;

use Automattic\WooCommerce\Utilities\OrderUtil;
use YayLoyalty\Access\Capabilities;
use YayLoyalty\Engine\Earning\OrderPointsState;
use YayLoyalty\Engine\Earning\PendingPoints;
use YayLoyalty\Repositories\CustomerRepository;
use YayLoyalty\Repositories\LedgerRepository;

defined( 'ABSPATH' ) || exit;

/**
 * The "Loyalty points" box on the order edit screen, for HPOS and legacy storage: where the order's
 * points stand and, when a known figure is missing, a button that gives it back once through
 * `POST yayloyalty/v1/orders/{id}/points` (assets/admin/order-points.js). Otherwise, on a paid order
 * with a customer, a link to Customers › Adjust points for any manual grant. Rendered in PHP from
 * OrderPointsState, after dropping the order's cached copy.
 */
final class OrderPointsMetabox {

    public const ID = 'yayloyalty-order-points';

    public static function register(): void {
        add_action( 'add_meta_boxes', [ self::class, 'add' ] );
        add_action( 'admin_enqueue_scripts', [ self::class, 'enqueue' ] );
    }

    public static function add(): void {
        if ( ! current_user_can( Capabilities::MANAGE_POINTS ) ) {
            return;
        }

        add_meta_box( self::ID, __( 'Loyalty points', 'yayloyalty' ), [ self::class, 'render' ], wc_get_page_screen_id( 'shop-order' ), 'side', 'default' );
    }

    /** @param \WP_Post|\WC_Order|mixed $post_or_order The legacy screen passes the post, the HPOS screen the order. */
    public static function render( $post_or_order ): void {
        $order_id = $post_or_order instanceof \WP_Post ? (int) $post_or_order->ID : ( $post_or_order instanceof \WC_Order ? (int) $post_or_order->get_id() : 0 );

        ( new PendingPoints( new CustomerRepository() ) )->forget_cached( $order_id );

        $order = wc_get_order( $order_id );

        if ( ! $order instanceof \WC_Order ) {
            return;
        }

        $state = OrderPointsState::resolve( $order, new LedgerRepository() );

        echo '<div class="yayloyalty-order-points" data-order-id="' . esc_attr( (string) $order_id ) . '">';
        self::render_state( $order, $state );
        echo '</div>';
    }

    private static function render_state( \WC_Order $order, OrderPointsState $state ): void {
        switch ( $state->get_status() ) {
            case OrderPointsState::NOT_PAID:
                self::paragraph( __( 'Points are added when the order is completed.', 'yayloyalty' ) );
                return;
            case OrderPointsState::AWAITING_COMPLETION:
                self::paragraph(
                    $state->get_figure() > 0
                        /* translators: %d: points */
                        ? sprintf( __( '%d points will be added when the order is completed.', 'yayloyalty' ), $state->get_figure() )
                        : __( 'Points are added when the order is completed.', 'yayloyalty' )
                );
                return;
            case OrderPointsState::GUEST:
                self::paragraph( __( 'Assign a customer in the Customer field and update the order to add points.', 'yayloyalty' ) );
                return;
            case OrderPointsState::MISSING_KNOWN:
                self::render_confirm( $order, $state );
                return;
            case OrderPointsState::EARNED:
                /* translators: %d: points */
                self::paragraph( sprintf( __( 'Earned %d points.', 'yayloyalty' ), $state->get_net_earned() ) );
                break;
            case OrderPointsState::RECOVERED:
                self::render_recovered( $state );
                break;
        }

        // Earned, recovered, or nothing says how many points are missing: a manual grant belongs in Adjust points.
        self::adjust_points_link( (int) $order->get_customer_id() );
    }

    private static function render_recovered( OrderPointsState $state ): void {
        // resolve() gives RECOVERED only with its row, so the recovery is never null here.
        $recovery = $state->to_array()['recovery'];

        if ( $recovery['reversed'] ) {
            /* translators: 1: points, 2: admin display name, 3: date */
            self::paragraph( sprintf( __( '%1$d points added manually by %2$s on %3$s were reversed.', 'yayloyalty' ), $recovery['points'], $recovery['actor_name'], $recovery['created_at'] ) );

            return;
        }

        /* translators: 1: points, 2: admin display name, 3: date, 4: the recovery's note */
        self::paragraph( sprintf( __( '%1$d points added manually by %2$s on %3$s: %4$s.', 'yayloyalty' ), $recovery['points'], $recovery['actor_name'], $recovery['created_at'], $recovery['note'] ) );
    }

    /** One click gives the known figure back; the route takes no points or note, so the box sends none. */
    private static function render_confirm( \WC_Order $order, OrderPointsState $state ): void {
        $customer = get_userdata( (int) $order->get_customer_id() );
        $name     = $customer instanceof \WP_User ? $customer->display_name : '';
        $figure   = $state->get_figure();

        /* translators: 1: points, 2: customer display name */
        self::paragraph( sprintf( __( 'This order should have earned %1$d points but they are not in %2$s\'s balance.', 'yayloyalty' ), $figure, $name ) );
        ?>
        <p>
            <?php /* translators: %d: points */ ?>
            <button type="button" class="button button-primary"><?php echo esc_html( sprintf( __( 'Give %d points', 'yayloyalty' ), $figure ) ); ?></button>
        </p>
        <?php
        self::error_line();
    }

    /** The customer's drawer in the SPA with the Adjust points dialog open (CustomerDrawerActions reads `adjust=1`). */
    private static function adjust_points_link( int $customer_id ): void {
        $url = admin_url( 'admin.php?page=yayloyalty#/customers/' . $customer_id . '?adjust=1' );

        echo '<p><a href="' . esc_url( $url ) . '">' . esc_html__( 'Adjust points →', 'yayloyalty' ) . '</a></p>';
    }

    private static function paragraph( string $text ): void {
        echo '<p>' . esc_html( $text ) . '</p>';
    }

    private static function error_line(): void {
        // `inline` keeps WordPress's common.js from moving the notice to the top of the page.
        echo '<div class="yayloyalty-order-points__error notice notice-error inline" role="alert" hidden><p></p></div>';
    }

    /** On the order edit screen only: HPOS gives the orders list the same screen id, so the id alone is not enough. */
    public static function enqueue( string $hook ): void {
        unset( $hook );

        $screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

        if ( null === $screen || wc_get_page_screen_id( 'shop-order' ) !== $screen->id || ! OrderUtil::is_order_edit_screen() || ! current_user_can( Capabilities::MANAGE_POINTS ) ) {
            return;
        }

        wp_enqueue_script( self::ID, YAYLOYALTY_PLUGIN_URL . 'assets/admin/order-points.js', [ 'wp-api-fetch' ], YAYLOYALTY_VERSION, true );
    }
}
