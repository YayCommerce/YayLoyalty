<?php
namespace YayLoyalty\Tests\Integration\Engine;

use YayLoyalty\Engine\Points\CustomerFactsReader;
use YayLoyalty\Tests\Integration\IntegrationTestCase;
use YayLoyalty\Utils\Dates;

/**
 * Facts against real WooCommerce orders: only paid orders created before
 * the evaluated moment count, and the evaluated order never counts itself.
 *
 * Runs on the site's table prefix, not the scratch one: WooCommerce's HPOS
 * tables are named from $wpdb->prefix at query time, so under `ylit_` no
 * order could be stored. Every order and the user are force-deleted in
 * tearDown(); the only plugin-table access is read()'s ledger sum for this
 * fresh user, which finds no row. The order hooks are detached while it
 * runs, so the earning listener cannot write the site's loyalty tables.
 */
final class CustomerFactsReaderTest extends IntegrationTestCase {

    private const AS_OF = '2026-06-15 12:00:00';

    private int $user_id = 0;

    /** @var \WC_Order[] */
    private array $orders = [];

    private string $scratch_prefix = '';

    /** Order hooks whose callbacks (the earning listener among them) must not run here. */
    private const DETACHED_HOOKS = [ 'woocommerce_new_order', 'woocommerce_order_status_changed' ];

    /** @var array<string, \WP_Hook> */
    private array $detached = [];

    protected function setUp(): void {
        global $wpdb, $wp_filter;

        parent::setUp();

        foreach ( self::DETACHED_HOOKS as $hook ) {
            if ( isset( $wp_filter[ $hook ] ) ) {
                $this->detached[ $hook ] = $wp_filter[ $hook ];
                unset( $wp_filter[ $hook ] );
            }
        }

        $this->scratch_prefix = $wpdb->prefix;
        $wpdb->prefix         = $wpdb->base_prefix;

        $this->user_id = (int) wp_insert_user(
            [
                'user_login' => 'facts_reader_' . wp_generate_password( 6, false ),
                'user_pass'  => wp_generate_password(),
                'role'       => 'customer',
            ]
        );
    }

    protected function tearDown(): void {
        global $wpdb, $wp_filter;

        foreach ( $this->orders as $order ) {
            $order->delete( true );
        }

        require_once ABSPATH . 'wp-admin/includes/user.php';
        wp_delete_user( $this->user_id );

        $wpdb->prefix = $this->scratch_prefix;

        foreach ( $this->detached as $hook => $callbacks ) {
            $wp_filter[ $hook ] = $callbacks; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- restoring what setUp() detached.
        }

        parent::tearDown();
    }

    public function test_counts_paid_orders_created_before_as_of_excluding_the_evaluated_order(): void {
        $this->order( 'completed', '2026-06-01 10:00:00', 100, 'USD' );
        $this->order( 'completed', '2026-06-02 10:00:00', 40, 'EUR' );
        $this->order( 'processing', '2026-06-03 10:00:00', 999, 'USD' );
        $this->order( 'pending', '2026-06-04 10:00:00', 999, 'USD' );
        $this->order( 'completed', '2026-07-01 10:00:00', 999, 'USD' );
        $evaluated = $this->order( 'completed', '2026-06-10 10:00:00', 999, 'USD' );

        $facts = CustomerFactsReader::read( $this->user_id, self::AS_OF, $evaluated->get_id() );

        $this->assertSame( 2, $facts->get_order_count() );
        $this->assertSame( 100.0, $facts->get_spend_in( 'USD' ) );
        $this->assertSame( 40.0, $facts->get_spend_in( 'EUR' ) );
        $this->assertSame( 0, $facts->get_lifetime_points() );

        // No order to exclude (the signup / preview path): the evaluated order is just history.
        $this->assertSame( 3, CustomerFactsReader::read( $this->user_id, self::AS_OF, null )->get_order_count() );
    }

    public function test_the_evaluated_order_counts_nothing_whatever_its_status(): void {
        $evaluated = $this->order( 'pending', '2026-06-10 10:00:00', 50, 'USD' );
        $before    = CustomerFactsReader::read( $this->user_id, self::AS_OF, $evaluated->get_id() );

        $evaluated->set_status( 'completed' );
        $evaluated->save();

        $this->assertEquals( $before, CustomerFactsReader::read( $this->user_id, self::AS_OF, $evaluated->get_id() ) );
        $this->assertSame( 0, $before->get_order_count() );
    }

    private function order( string $status, string $created_utc, float $total, string $currency ): \WC_Order {
        $order = wc_create_order( [ 'customer_id' => $this->user_id ] );
        $order->set_date_created( Dates::to_timestamp( $created_utc ) );
        $order->set_currency( $currency );
        $order->set_total( $total );
        $order->set_status( $status );
        $order->save();

        $this->orders[] = $order;

        return $order;
    }
}
