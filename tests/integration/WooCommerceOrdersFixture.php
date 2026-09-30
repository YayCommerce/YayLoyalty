<?php
namespace YayLoyalty\Tests\Integration;

use YayLoyalty\Enums\CampaignTrigger;
use YayLoyalty\Enums\Status;
use YayLoyalty\Models\Campaign;
use YayLoyalty\Repositories\CampaignRepository;

/**
 * Real orders, customers and campaigns for tests that drive the earning
 * listeners through WooCommerce's own hooks. Orders live in the scratch
 * copies of WooCommerce's tables (bootstrap.php), but their HPOS placeholder
 * posts, notes and customers are site rows, so cleanup_orders() deletes
 * everything it made; call it from tearDown(). Mail is short-circuited in
 * between, so order emails never leave the site.
 */
trait WooCommerceOrdersFixture {

    /** @var \WC_Order[] */
    private $fixture_orders = [];

    /** @var int[] */
    private $fixture_users = [];

    /** Skips without WooCommerce; otherwise stops mail until cleanup_orders(). Call from setUp(). */
    private function require_woocommerce(): void {
        if ( ! function_exists( 'wc_create_order' ) ) {
            $this->markTestSkipped( 'WooCommerce is not active on this site.' );
        }

        add_filter( 'pre_wp_mail', '__return_false' );
    }

    /** A customer-role user, registered while an administrator is current so SignupListener does not award it. */
    private function create_customer(): int {
        $admins = get_users(
            [
                'role'   => 'administrator',
                'number' => 1,
                'fields' => 'ID',
            ]
        );

        if ( empty( $admins ) ) {
            $this->markTestSkipped( 'The site has no administrator to register customers as.' );
        }

        $previous = get_current_user_id();
        wp_set_current_user( (int) $admins[0] );

        $user_id = (int) wp_insert_user(
            [
                'user_login' => 'yl_it_' . wp_generate_password( 8, false ),
                'user_pass'  => wp_generate_password(),
                'role'       => 'customer',
            ]
        );

        wp_set_current_user( $previous );
        $this->fixture_users[] = $user_id;

        return $user_id;
    }

    /**
     * An order saved once at $status with one line per total, so
     * woocommerce_new_order reaches the listener with its lines in place.
     *
     * @param float[] $line_totals
     */
    private function create_order( ?int $user_id, array $line_totals, string $status = 'pending' ): \WC_Order {
        $order = new \WC_Order();
        $order->set_customer_id( (int) $user_id );
        $order->set_currency( 'USD' );

        foreach ( $line_totals as $index => $total ) {
            $item = new \WC_Order_Item_Product();
            $item->set_name( 'Line ' . ( $index + 1 ) );
            $item->set_quantity( 1 );
            $item->set_subtotal( $total );
            $item->set_total( $total );
            $order->add_item( $item );
        }

        $order->calculate_totals( false );
        $order->set_status( $status );
        $order->save();

        $this->fixture_orders[] = $order;

        // A fresh read: the listener wrote its meta through its own instance.
        return wc_get_order( $order->get_id() );
    }

    /** Moves an order to $status through save(), which fires woocommerce_order_status_changed. */
    private function transition( \WC_Order $order, string $status ): \WC_Order {
        $order->set_status( $status );
        $order->save();

        return wc_get_order( $order->get_id() );
    }

    /**
     * An active campaign for $trigger with its default config, overridden by $config.
     *
     * @param array<string, mixed> $config
     */
    private function create_campaign( string $trigger = CampaignTrigger::PURCHASE, array $config = [] ): int {
        static $sequence = 0;
        ++$sequence;

        return ( new CampaignRepository() )->insert(
            new Campaign(
                null,
                'Earning test ' . $sequence,
                'earning-test-' . $sequence,
                $trigger,
                Status::ACTIVE,
                0,
                array_replace( Campaign::default_config( $trigger ), $config )
            )
        );
    }

    /**
     * Order notes the listener added, newest first.
     *
     * @return string[]
     */
    private function order_notes( \WC_Order $order ): array {
        return array_map(
            static function ( $note ): string {
                return (string) $note->content;
            },
            wc_get_order_notes( [ 'order_id' => $order->get_id() ] )
        );
    }

    private function cleanup_orders(): void {
        foreach ( $this->fixture_orders as $order ) {
            foreach ( wc_get_order_notes( [ 'order_id' => $order->get_id() ] ) as $note ) {
                wp_delete_comment( (int) $note->id, true );
            }

            $order->delete( true );
        }

        require_once ABSPATH . 'wp-admin/includes/user.php';

        foreach ( $this->fixture_users as $user_id ) {
            wp_delete_user( $user_id );
        }

        $this->fixture_orders = [];
        $this->fixture_users  = [];

        remove_filter( 'pre_wp_mail', '__return_false' );
    }
}
