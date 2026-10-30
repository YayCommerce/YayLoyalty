<?php
namespace YayLoyalty\Engine\Admin;

use Automattic\WooCommerce\Utilities\OrderUtil;
use YayLoyalty\Access\Capabilities;
use YayLoyalty\Engine\Earning\OrderPointsState;
use YayLoyalty\Engine\Earning\PendingPoints;
use YayLoyalty\Repositories\CustomerRepository;
use YayLoyalty\Repositories\LedgerRepository;
use YayLoyalty\Utils\Dates;

defined( 'ABSPATH' ) || exit;

/**
 * The "Loyalty points" box on the order edit screen, for HPOS and legacy storage: where the order's
 * points stand and, when they are missing, a button that gives them back once through
 * `POST yayloyalty/v1/orders/{id}/points` (assets/admin/order-points.js). Rendered in PHP from
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
                self::paragraph( __( 'Points can be added once the order is paid (Processing or Completed).', 'yayloyalty' ) );
                return;
            case OrderPointsState::GUEST:
                self::paragraph( __( 'Assign a customer in the Customer field and update the order to add points.', 'yayloyalty' ) );
                return;
            case OrderPointsState::EARNED:
                /* translators: %d: points */
                self::paragraph( sprintf( __( 'Earned %d points.', 'yayloyalty' ), $state->get_net_earned() ) );
                return;
            case OrderPointsState::RECOVERED:
                self::render_recovered( $state );
                return;
            case OrderPointsState::MISSING_KNOWN:
                self::render_confirm( $order, $state );
                return;
            default:
                self::render_manual();
        }
    }

    private static function render_recovered( OrderPointsState $state ): void {
        $recovery = $state->to_array()['recovery'];

        if ( null === $recovery ) {
            return;
        }

        if ( $state->is_recovery_reversed() ) {
            /* translators: 1: points, 2: admin display name, 3: date */
            self::paragraph( sprintf( __( '%1$d points added manually by %2$s on %3$s were reversed; use YayLoyalty › Customers › Adjust points.', 'yayloyalty' ), $recovery['points'], $recovery['actor_name'], $recovery['created_at'] ) );

            return;
        }

        /* translators: 1: points, 2: admin display name, 3: date, 4: the admin's reason */
        self::paragraph( sprintf( __( '%1$d points added manually by %2$s on %3$s: %4$s.', 'yayloyalty' ), $recovery['points'], $recovery['actor_name'], $recovery['created_at'], $recovery['note'] ) );
    }

    private static function render_confirm( \WC_Order $order, OrderPointsState $state ): void {
        $customer = get_userdata( (int) $order->get_customer_id() );
        $name     = $customer instanceof \WP_User ? $customer->display_name : '';
        $figure   = $state->get_figure();

        /* translators: 1: points, 2: customer display name */
        self::paragraph( sprintf( __( 'This order should have earned %1$d points but they are not in %2$s\'s balance.', 'yayloyalty' ), $figure, $name ) );
        ?>
        <p>
            <input type="text" class="widefat" name="yayloyalty_note" maxlength="500" placeholder="<?php echo esc_attr__( 'Recovered missing order points', 'yayloyalty' ); ?>" aria-label="<?php echo esc_attr__( 'Reason', 'yayloyalty' ); ?>" />
        </p>
        <p>
            <?php /* translators: %d: points */ ?>
            <button type="button" class="button button-primary"><?php echo esc_html( sprintf( __( 'Give %d points', 'yayloyalty' ), $figure ) ); ?></button>
        </p>
        <?php
        self::error_line();
    }

    private static function render_manual(): void {
        ?>
        <p>
            <label for="yayloyalty_points"><?php esc_html_e( 'Points', 'yayloyalty' ); ?></label>
            <input type="number" class="widefat" id="yayloyalty_points" name="yayloyalty_points" min="1" max="1000000" step="1" />
        </p>
        <p>
            <label for="yayloyalty_note"><?php esc_html_e( 'Reason', 'yayloyalty' ); ?></label>
            <input type="text" class="widefat" id="yayloyalty_note" name="yayloyalty_note" maxlength="500" required />
        </p>
        <p>
            <button type="button" class="button button-primary"><?php esc_html_e( 'Add points', 'yayloyalty' ); ?></button>
        </p>
        <?php
        self::error_line();
    }

    private static function paragraph( string $text ): void {
        echo '<p>' . esc_html( $text ) . '</p>';
    }

    private static function error_line(): void {
        echo '<p class="yayloyalty-order-points__error" role="alert" hidden></p>';
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
