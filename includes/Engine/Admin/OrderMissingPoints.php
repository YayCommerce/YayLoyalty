<?php
namespace YayLoyalty\Engine\Admin;

use Automattic\WooCommerce\Utilities\OrderUtil;
use YayLoyalty\Access\Capabilities;
use YayLoyalty\Engine\Earning\EarningService;
use YayLoyalty\Engine\Earning\ProgramGate;
use YayLoyalty\Engine\Earning\Reversal;
use YayLoyalty\Engine\Points\Result;
use YayLoyalty\Engine\Storefront\Launcher;
use YayLoyalty\Enums\OrderStatus;
use YayLoyalty\Helpers\SettingsHelper;
use YayLoyalty\Repositories\LedgerRepository;
use YayLoyalty\Utils\Log;
use YayLoyalty\Utils\WooOrders;

defined( 'ABSPATH' ) || exit;

/**
 * Finds completed orders that never earned points and grants them from the order screen, for HPOS and legacy storage:
 * a "Loyalty points" column and a "Missing points" filter on the orders list, and a box on the order edit screen that
 * suggests today's figure and posts to `orders/{id}/grant-points` (assets/admin/order-missing-points.js and .css).
 */
final class OrderMissingPoints {

    public const HANDLE = 'yayloyalty-order-missing-points';

    private const COLUMN = 'yayloyalty_points';

    private const FILTER_PARAM = 'yayloyalty_points';

    private const FILTER_MISSING = 'missing';

    /** Query arg the HPOS list passes to wc_get_orders() while the filter is on. */
    private const QUERY_ARG = 'yayloyalty_missing_points';

    /** Read-only. */
    private LedgerRepository $ledger;

    /** @var array<int, array{earned: int, net: int}> Points per order id primed for the list's page; an absent id earned nothing. */
    private array $earned = [];

    /** @var array<int, true> Order ids already looked up. */
    private array $primed = [];

    public function __construct( LedgerRepository $ledger ) {
        $this->ledger = $ledger;
    }

    public function register(): void {
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue' ] );
        add_action( 'add_meta_boxes', [ $this, 'add_meta_box' ], 10, 2 );

        // HPOS list.
        add_filter( 'manage_woocommerce_page_wc-orders_columns', [ $this, 'add_column' ] );
        add_action( 'manage_woocommerce_page_wc-orders_custom_column', [ $this, 'render_hpos_column' ], 10, 2 );
        add_action( 'woocommerce_order_list_table_restrict_manage_orders', [ $this, 'render_hpos_filter' ], 10, 2 );
        add_filter( 'woocommerce_order_list_table_prepare_items_query_args', [ $this, 'hpos_query_args' ] );
        add_filter( 'woocommerce_orders_table_query_clauses', [ $this, 'hpos_query_clauses' ], 10, 3 );
        add_filter( 'woocommerce_order_query', [ $this, 'prime_from_results' ] );

        // Legacy list.
        add_filter( 'manage_edit-shop_order_columns', [ $this, 'add_column' ] );
        add_action( 'manage_shop_order_posts_custom_column', [ $this, 'render_legacy_column' ], 10, 2 );
        add_action( 'restrict_manage_posts', [ $this, 'render_legacy_filter' ], 10, 2 );
        add_filter( 'posts_clauses', [ $this, 'legacy_query_clauses' ], 10, 2 );
    }

    /**
     * @param array<string, string> $columns
     * @return array<string, string>
     */
    public function add_column( $columns ) {
        if ( ! is_array( $columns ) || ! current_user_can( Capabilities::MANAGE_POINTS ) ) {
            return $columns;
        }

        $out = [];

        foreach ( $columns as $key => $label ) {
            $out[ $key ] = $label;

            if ( 'order_status' === $key ) {
                $out[ self::COLUMN ] = __( 'Loyalty points', 'yayloyalty' );
            }
        }

        if ( ! isset( $out[ self::COLUMN ] ) ) {
            $out[ self::COLUMN ] = __( 'Loyalty points', 'yayloyalty' );
        }

        return $out;
    }

    /** @param \WC_Order|mixed $order */
    public function render_hpos_column( $column, $order ): void {
        if ( self::COLUMN === $column && $order instanceof \WC_Order ) {
            $this->render_cell( $order );
        }
    }

    public function render_legacy_column( $column, $post_id ): void {
        if ( self::COLUMN !== $column ) {
            return;
        }

        global $wp_query;

        // The page's ids, so the column runs one ledger query rather than one per row.
        if ( $wp_query instanceof \WP_Query && is_array( $wp_query->posts ) ) {
            $this->prime( wp_list_pluck( $wp_query->posts, 'ID' ) );
        }

        $order = wc_get_order( (int) $post_id );

        if ( $order instanceof \WC_Order ) {
            $this->render_cell( $order );
        }
    }

    /**
     * Collects the ids an orders query returned, so the HPOS column reads the ledger once per page.
     *
     * @param mixed $results
     * @return mixed Unchanged.
     */
    public function prime_from_results( $results ) {
        if ( ! self::is_orders_list() ) {
            return $results;
        }

        $orders = is_object( $results ) && isset( $results->orders ) ? $results->orders : $results;
        $ids    = [];

        foreach ( is_array( $orders ) ? $orders : [] as $order ) {
            $ids[] = $order instanceof \WC_Order ? (int) $order->get_id() : (int) $order;
        }

        $this->prime( $ids );

        return $results;
    }

    public function render_hpos_filter( $order_type, $which ): void {
        if ( 'shop_order' === $order_type && 'top' === $which ) {
            $this->render_filter();
        }
    }

    public function render_legacy_filter( $post_type, $which = 'top' ): void {
        if ( 'shop_order' === $post_type && 'top' === $which ) {
            $this->render_filter();
        }
    }

    /**
     * @param array<string, mixed> $args
     * @return array<string, mixed>
     */
    public function hpos_query_args( $args ) {
        if ( ! is_array( $args ) || ! self::is_filter_on() ) {
            return $args;
        }

        $args['status']          = [ 'wc-' . OrderStatus::AWARD ];
        $args[ self::QUERY_ARG ] = true;

        return $args;
    }

    /**
     * @param array<string, string> $clauses
     * @param object                $query   OrdersTableQuery.
     * @param array<string, mixed>  $args
     * @return array<string, string>
     */
    public function hpos_query_clauses( $clauses, $query, $args ) {
        if ( ! is_array( $clauses ) || empty( $args[ self::QUERY_ARG ] ) || ! method_exists( $query, 'get_table_name' ) ) {
            return $clauses;
        }

        $orders = esc_sql( $query->get_table_name( 'orders' ) );

        $clauses['where'] = ( $clauses['where'] ?? '1=1' )
            . " AND `{$orders}`.customer_id > 0 AND " . $this->ledger->no_earn_row_sql( "`{$orders}`.id" );

        return $clauses;
    }

    /**
     * @param array<string, string> $clauses
     * @param \WP_Query             $query
     * @return array<string, string>
     */
    public function legacy_query_clauses( $clauses, $query ) {
        if ( ! is_array( $clauses ) || ! $query instanceof \WP_Query || ! $query->is_main_query() || ! is_admin() || 'shop_order' !== $query->get( 'post_type' ) || ! self::is_filter_on() ) {
            return $clauses;
        }

        global $wpdb;

        $clauses['where'] .= $wpdb->prepare(
            " AND {$wpdb->posts}.post_status = %s AND EXISTS ( SELECT 1 FROM {$wpdb->postmeta} yl_cu WHERE yl_cu.post_id = {$wpdb->posts}.ID AND yl_cu.meta_key = '_customer_user' AND CAST( yl_cu.meta_value AS UNSIGNED ) > 0 )", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- core table names only.
            'wc-' . OrderStatus::AWARD
        ) . ' AND ' . $this->ledger->no_earn_row_sql( "{$wpdb->posts}.ID" );

        return $clauses;
    }

    /**
     * Adds the box to an order edit screen whose order is missing points.
     *
     * @param string $screen_id
     * @param mixed  $post_or_order WP_Post (legacy) or WC_Order (HPOS).
     */
    public function add_meta_box( $screen_id, $post_or_order = null ): void {
        if ( wc_get_page_screen_id( 'shop-order' ) !== $screen_id || ! current_user_can( Capabilities::MANAGE_POINTS ) ) {
            return;
        }

        $order = self::order_of( $post_or_order );

        if ( null === $order || ! EarningService::is_missing_points( $order, $this->ledger ) ) {
            return;
        }

        add_meta_box(
            'yayloyalty-missing-points',
            __( 'Loyalty points', 'yayloyalty' ),
            function () use ( $order ): void {
                $this->render_box( $order );
            },
            $screen_id,
            'side',
            'high'
        );
    }

    /** On the orders list, the stylesheet; on an order edit screen, the stylesheet and the grant script. */
    public function enqueue(): void {
        $screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

        // The legacy list is `edit-shop_order`; HPOS shares one screen id between the list and the edit screen.
        $screens = [ wc_get_page_screen_id( 'shop-order' ), 'edit-shop_order' ];

        if ( null === $screen || ! in_array( $screen->id, $screens, true ) || ! current_user_can( Capabilities::MANAGE_POINTS ) ) {
            return;
        }

        // The file's mtime in the version, so an edited script or stylesheet reaches the browser without a version bump.
        $css = 'assets/admin/order-missing-points.css';
        wp_enqueue_style( self::HANDLE, YAYLOYALTY_PLUGIN_URL . $css, [], Launcher::asset_version( $css ) );

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- reads which order the screen shows; changes nothing.
        $order = OrderUtil::is_order_edit_screen() ? wc_get_order( absint( wp_unslash( $_GET['id'] ?? $_GET['post'] ?? 0 ) ) ) : null;

        if ( ! $order instanceof \WC_Order || ! EarningService::is_missing_points( $order, $this->ledger ) ) {
            return;
        }

        $js = 'assets/admin/order-missing-points.js';
        wp_enqueue_script( self::HANDLE, YAYLOYALTY_PLUGIN_URL . $js, [ 'wp-api-fetch' ], Launcher::asset_version( $js ), true );
        wp_add_inline_script(
            self::HANDLE,
            'window.yayloyaltyMissingPoints = ' . wp_json_encode(
                [
                    'i18n' => [
                        'invalid' => __( 'Enter a whole number of points above 0.', 'yayloyalty' ),
                        /* translators: %s: points in the store's format, as "1.250 Points". */
                        'granted' => __( '%s granted. Reloading…', 'yayloyalty' ),
                        'failed'  => __( 'The points could not be granted.', 'yayloyalty' ),
                    ],
                ]
            ) . ';',
            'before'
        );
    }

    private function render_cell( \WC_Order $order ): void {
        $order_id = (int) $order->get_id();
        $this->prime( [ $order_id ] );

        if ( isset( $this->earned[ $order_id ] ) ) {
            $points = $this->earned[ $order_id ];

            echo esc_html( SettingsHelper::points_text( $points['net'] ) );

            if ( $points['net'] !== $points['earned'] ) {
                /* translators: %s: points in the store's format, as "1.250 Points". */
                echo '<br /><span class="description">' . esc_html( sprintf( __( 'of %s earned', 'yayloyalty' ), SettingsHelper::points_text( $points['earned'] ) ) ) . '</span>';
            }
            return;
        }

        if ( ! EarningService::is_awardable_order( $order ) ) {
            echo '<span aria-hidden="true">&ndash;</span>';
            return;
        }

        $reason = EarningService::grant_blocked_reason( $order );

        if ( null !== $reason ) {
            echo '<mark class="yayloyalty-missing-badge is-blocked" title="' . esc_attr( self::reason_label( $reason ) ) . '">' . esc_html__( 'Not eligible', 'yayloyalty' ) . '</mark>';
            return;
        }

        echo '<mark class="yayloyalty-missing-badge">' . esc_html__( 'Missing', 'yayloyalty' ) . '</mark>';
    }

    private function render_filter(): void {
        if ( ! current_user_can( Capabilities::MANAGE_POINTS ) ) {
            return;
        }

        $selected = self::is_filter_on() ? self::FILTER_MISSING : '';
        ?>
        <label class="screen-reader-text" for="yayloyalty-points-filter"><?php esc_html_e( 'Filter by loyalty points', 'yayloyalty' ); ?></label>
        <select name="<?php echo esc_attr( self::FILTER_PARAM ); ?>" id="yayloyalty-points-filter">
            <option value=""><?php esc_html_e( 'All loyalty points', 'yayloyalty' ); ?></option>
            <option value="<?php echo esc_attr( self::FILTER_MISSING ); ?>" <?php selected( $selected, self::FILTER_MISSING ); ?>><?php esc_html_e( 'Missing points', 'yayloyalty' ); ?></option>
        </select>
        <?php
    }

    private function render_box( \WC_Order $order ): void {
        $customer = get_userdata( (int) $order->get_customer_id() );
        $blocked  = EarningService::grant_blocked_reason( $order );

        try {
            $result = EarningService::build()->suggest_for_order( $order );
        } catch ( \Throwable $e ) {
            Log::error( 'OrderMissingPoints', sprintf( 'Suggestion for order %d failed: %s', (int) $order->get_id(), $e->getMessage() ) );
            $result = Result::none( Result::REASON_ZERO_POINTS );
        }

        $suggested = $result->is_awarded() ? $result->get_points() : 0;
        ?>
        <div class="yayloyalty-missing-points" data-order-id="<?php echo esc_attr( (string) $order->get_id() ); ?>">
            <p><?php esc_html_e( 'This order earned no points.', 'yayloyalty' ); ?></p>
            <?php if ( $customer ) : ?>
                <p>
                    <?php
                    /* translators: %s: the customer's display name. */
                    echo esc_html( sprintf( __( 'Customer: %s', 'yayloyalty' ), $customer->display_name ) );
                    ?>
                </p>
            <?php endif; ?>
            <?php if ( null !== $blocked ) : ?>
                <p class="yayloyalty-missing-points__error"><?php echo esc_html( self::reason_label( $blocked ) ); ?></p>
            <?php else : ?>
                <p class="yayloyalty-missing-points__suggestion">
                    <?php
                    /* translators: %s: points, as "50 points". */
                    echo esc_html( sprintf( __( 'Suggested under today\'s campaigns: %s', 'yayloyalty' ), SettingsHelper::points_text( $suggested ) ) );
                    ?>
                    <?php if ( ! $result->is_awarded() ) : ?>
                        <br /><span class="description"><?php echo esc_html( self::reason_label( $result->get_reason() ) ); ?></span>
                    <?php endif; ?>
                </p>
                <?php if ( [] !== Reversal::refunds( (int) $order->get_id(), 1 ) ) : ?>
                    <p class="description"><?php esc_html_e( 'This order has refunds. Their share of the points is taken back right after the grant, as for any refund.', 'yayloyalty' ); ?></p>
                <?php endif; ?>
                <p>
                    <label for="yayloyalty-grant-points"><?php esc_html_e( 'Points to grant', 'yayloyalty' ); ?></label>
                    <input type="number" id="yayloyalty-grant-points" class="widefat" min="1" step="1" value="<?php echo esc_attr( $suggested > 0 ? (string) $suggested : '' ); ?>" />
                </p>
                <p>
                    <label for="yayloyalty-grant-note"><?php esc_html_e( 'Note (optional)', 'yayloyalty' ); ?></label>
                    <input type="text" id="yayloyalty-grant-note" class="widefat" maxlength="200" placeholder="<?php esc_attr_e( 'Missing order points', 'yayloyalty' ); ?>" />
                </p>
                <p>
                    <label class="yayloyalty-missing-points__check"><input type="checkbox" id="yayloyalty-grant-notify" /> <?php esc_html_e( 'Email the customer', 'yayloyalty' ); ?></label>
                </p>
                <p class="yayloyalty-missing-points__error" role="alert" hidden></p>
                <p><button type="button" class="button button-primary yayloyalty-missing-points__grant"><?php esc_html_e( 'Grant points', 'yayloyalty' ); ?></button></p>
            <?php endif; ?>
        </div>
        <?php
    }

    /** Why an order earns nothing or cannot be granted, for the merchant. */
    public static function reason_label( string $reason ): string {
        $labels = [
            ProgramGate::REASON_PROGRAM_PAUSED => __( 'The loyalty program is paused.', 'yayloyalty' ),
            ProgramGate::REASON_OPTED_OUT      => __( 'The customer opted out of the program.', 'yayloyalty' ),
            ProgramGate::REASON_EXCLUDED_ROLE  => __( 'The customer\'s role is excluded from earning.', 'yayloyalty' ),
            Result::REASON_NO_CAMPAIGNS        => __( 'No purchase campaign is live today.', 'yayloyalty' ),
            Result::REASON_NOT_ELIGIBLE        => __( 'The order does not meet any live campaign\'s conditions.', 'yayloyalty' ),
            Result::REASON_ZERO_POINTS         => __( 'Today\'s campaigns give this order 0 points.', 'yayloyalty' ),
        ];

        return $labels[ $reason ] ?? $reason;
    }

    /** @param int[] $ids */
    private function prime( array $ids ): void {
        $todo = [];

        foreach ( $ids as $id ) {
            $id = (int) $id;

            if ( $id > 0 && ! isset( $this->primed[ $id ] ) ) {
                $todo[]              = $id;
                $this->primed[ $id ] = true;
            }
        }

        if ( [] !== $todo ) {
            $this->earned = $this->ledger->points_by_orders( $todo ) + $this->earned;
        }
    }

    /** @param mixed $post_or_order */
    private static function order_of( $post_or_order ): ?\WC_Order {
        if ( $post_or_order instanceof \WC_Order ) {
            return $post_or_order;
        }

        $order = $post_or_order instanceof \WP_Post ? wc_get_order( $post_or_order->ID ) : null;

        return $order instanceof \WC_Order ? $order : null;
    }

    private static function is_filter_on(): bool {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a list filter; changes nothing.
        return self::FILTER_MISSING === sanitize_key( wp_unslash( $_GET[ self::FILTER_PARAM ] ?? '' ) );
    }

    private static function is_orders_list(): bool {
        $screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

        return null !== $screen && wc_get_page_screen_id( 'shop-order' ) === $screen->id && ! OrderUtil::is_order_edit_screen() && WooOrders::is_hpos();
    }
}
