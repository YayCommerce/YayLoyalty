<?php
namespace YayLoyalty\Engine\Admin;

use Automattic\WooCommerce\Utilities\OrderUtil;
use YayLoyalty\Access\Capabilities;
use YayLoyalty\Engine\Earning\EarningService;
use YayLoyalty\Engine\Earning\OrderAward;
use YayLoyalty\Engine\Earning\OrderPointsState;
use YayLoyalty\Engine\Earning\PendingPoints;
use YayLoyalty\Repositories\CustomerRepository;
use YayLoyalty\Repositories\LedgerRepository;
use YayLoyalty\Utils\Text;

defined( 'ABSPATH' ) || exit;

/**
 * The "Loyalty points" box on the order edit screen, for HPOS and legacy storage: where the order's
 * points stand and, when the order's award never completed, a button that runs it again through
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

        $order = PendingPoints::fresh_order( $order_id );

        if ( null === $order ) {
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
            case OrderPointsState::MISSING:
                self::render_confirm( $order, $state );
                return;
            case OrderPointsState::EARNED:
                /* translators: %d: points */
                self::paragraph( sprintf( __( 'Earned %d points.', 'yayloyalty' ), $state->get_net_earned() ) );
                break;
        }

        // Earned, or no points to retry: a manual grant belongs in Adjust points.
        self::adjust_points_link( (int) $order->get_customer_id() );
    }

    /**
     * The button offers what the award gives now (OrderAward::preview(): campaigns live at the order's
     * date, as configured today), which is what the retry writes. Checkout's figure is shown when it differs, and when the
     * award gives nothing the box links to Adjust points prefilled with it.
     */
    private static function render_confirm( \WC_Order $order, OrderPointsState $state ): void {
        $customer_id = (int) $order->get_customer_id();
        $name        = Text::user_display_name( $customer_id );
        $expected    = $state->get_figure();
        $pending     = new PendingPoints( new CustomerRepository() );
        $now         = ( new OrderAward( EarningService::build(), $pending, new LedgerRepository() ) )->preview( $order );

        if ( $now < 1 ) {
            /* translators: %d: points */
            self::paragraph( sprintf( __( 'No campaign gives this order points any more. At checkout it was expected to earn %d points.', 'yayloyalty' ), $expected ) );
            self::adjust_points_link( $customer_id, $expected );

            return;
        }

        /* translators: 1: points, 2: customer display name */
        self::paragraph( sprintf( __( 'This order should have earned %1$d points but they are not in %2$s\'s balance.', 'yayloyalty' ), $now, $name ) );

        if ( $expected !== $now ) {
            /* translators: %d: points */
            self::paragraph( sprintf( __( 'At checkout it was expected to earn %d points.', 'yayloyalty' ), $expected ) );
        }
        ?>
        <p>
            <?php /* translators: %d: points */ ?>
            <button type="button" class="button button-primary"><?php echo esc_html( sprintf( __( 'Give %d points', 'yayloyalty' ), $now ) ); ?></button>
        </p>
        <?php
        self::error_line();
    }

    /**
     * The customer's drawer in the SPA with the Adjust points dialog open: `?adjust=1`, plus `&points=N`
     * to prefill the amount when $points is above 0. The owner of both parameters; CustomerDrawerActions
     * (apps/admin) reads them.
     */
    private static function adjust_points_link( int $customer_id, int $points = 0 ): void {
        $url = admin_url( 'admin.php?page=yayloyalty#/customers/' . $customer_id . '?adjust=1' . ( $points > 0 ? '&points=' . $points : '' ) );

        echo '<p><a href="' . esc_url( $url ) . '">' . esc_html__( 'Adjust points →', 'yayloyalty' ) . '</a></p>';
    }

    private static function paragraph( string $text ): void {
        echo '<p>' . esc_html( $text ) . '</p>';
    }

    private static function error_line(): void {
        // `inline` keeps WordPress's common.js from moving the notice to the top of the page.
        echo '<div class="yayloyalty-order-points__error notice notice-error inline" role="alert" hidden><p></p></div>';
    }

    /** On the order edit screen only, for both storages (OrderUtil::is_order_edit_screen()). */
    public static function enqueue(): void {
        if ( ! OrderUtil::is_order_edit_screen() || ! current_user_can( Capabilities::MANAGE_POINTS ) ) {
            return;
        }

        wp_enqueue_script( self::ID, YAYLOYALTY_PLUGIN_URL . 'assets/admin/order-points.js', [ 'wp-api-fetch' ], YAYLOYALTY_VERSION, true );
    }
}
