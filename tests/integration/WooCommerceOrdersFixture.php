<?php
namespace YayLoyalty\Tests\Integration;

use YayLoyalty\Enums\CampaignTrigger;
use YayLoyalty\Enums\Status;
use YayLoyalty\Models\Campaign;
use YayLoyalty\Repositories\CampaignRepository;

/**
 * Real orders, customers, products, reviews and campaigns for tests that
 * drive the earning listeners through WooCommerce's own hooks. Orders live
 * in the scratch copies of WooCommerce's tables (bootstrap.php), but their
 * HPOS placeholder posts, notes, products, reviews and customers are site
 * rows, so cleanup_orders() deletes everything it made; call it from
 * tearDown(). Mail is short-circuited in between, so order emails never
 * leave the site.
 */
trait WooCommerceOrdersFixture {

    /** @var \WC_Order[] */
    private $fixture_orders = [];

    /** @var int[] */
    private $fixture_users = [];

    /** @var \WC_Product[] */
    private $fixture_products = [];

    /** @var int[] */
    private $fixture_comments = [];

    /**
     * Skips without WooCommerce; otherwise stops mail until cleanup_orders(). Call from setUp().
     *
     * Every WooCommerce email is switched off, not just its delivery: rendering
     * (CSS inlining) runs before wp_mail, and once other tests have loaded the
     * mailer it costs seconds per status change.
     */
    private function require_woocommerce(): void {
        if ( ! function_exists( 'wc_create_order' ) ) {
            $this->markTestSkipped( 'WooCommerce is not active on this site.' );
        }

        add_filter( 'pre_wp_mail', '__return_false' );

        foreach ( $this->woocommerce_email_ids() as $email_id ) {
            add_filter( 'woocommerce_email_enabled_' . $email_id, '__return_false' );
        }
    }

    /** @return string[] */
    private function woocommerce_email_ids(): array {
        return array_values( wp_list_pluck( WC()->mailer()->get_emails(), 'id' ) );
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

        return $this->track_user( $user_id );
    }

    /**
     * An order saved once at $status with one line per total, so
     * woocommerce_new_order reaches the listener with its lines in place.
     *
     * @param float[] $line_totals
     * @param int[]   $product_ids Product per line index, for lines a campaign must be able to exclude.
     */
    private function create_order( ?int $user_id, array $line_totals, string $status = 'pending', array $product_ids = [] ): \WC_Order {
        $order = new \WC_Order();
        $order->set_customer_id( (int) $user_id );
        $order->set_currency( 'USD' );

        foreach ( $line_totals as $index => $total ) {
            $item = new \WC_Order_Item_Product();
            $item->set_name( 'Line ' . ( $index + 1 ) );
            $item->set_product_id( $product_ids[ $index ] ?? 0 );
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
     * Refunds whole lines by index through wc_create_refund(), which fires woocommerce_order_refunded.
     *
     * @param int[] $line_indexes
     */
    private function refund_lines( \WC_Order $order, array $line_indexes ): \WC_Order_Refund {
        $items  = array_values( $order->get_items() );
        $lines  = [];
        $amount = 0.0;

        foreach ( $line_indexes as $index ) {
            $item                     = $items[ $index ];
            $lines[ $item->get_id() ] = [
                'qty'          => $item->get_quantity(),
                'refund_total' => $item->get_total(),
            ];
            $amount                  += (float) $item->get_total();
        }

        return $this->refund( $order, $amount, $lines );
    }

    /**
     * A refund of $amount; without line items it is amount-only, which is what refunding shipping looks like.
     *
     * @param array<int, array<string, mixed>> $line_items
     */
    private function refund( \WC_Order $order, float $amount, array $line_items = [] ): \WC_Order_Refund {
        $refund = wc_create_refund(
            [
                'order_id'   => $order->get_id(),
                'amount'     => $amount,
                'line_items' => $line_items,
            ]
        );

        if ( ! $refund instanceof \WC_Order_Refund ) {
            $this->fail( 'Refund not created: ' . ( is_wp_error( $refund ) ? $refund->get_error_message() : 'unknown' ) );
        }

        return $refund;
    }

    /** Tracks a user a test registered itself, so cleanup_orders() deletes it. */
    private function track_user( int $user_id ): int {
        $this->fixture_users[] = $user_id;

        return $user_id;
    }

    private function create_product(): int {
        $product = new \WC_Product_Simple();
        $product->set_name( 'YayLoyalty test product' );
        $product->set_regular_price( '10' );
        $product->save();

        $this->fixture_products[] = $product;

        return $product->get_id();
    }

    /** A top-level review inserted unapproved, then approved, so transition_comment_status reaches the listener. */
    private function approve_new_review( int $user_id, int $product_id ): int {
        $comment_id = (int) wp_insert_comment(
            [
                'comment_post_ID'  => $product_id,
                'user_id'          => $user_id,
                'comment_type'     => 'review',
                'comment_content'  => 'Works as described.',
                'comment_approved' => 0,
            ]
        );

        $this->fixture_comments[] = $comment_id;
        wp_set_comment_status( $comment_id, 'approve' );

        return $comment_id;
    }

    /**
     * An active campaign for $trigger with its default config, overridden by $config.
     *
     * @param array<string, mixed> $config
     */
    private function create_campaign( string $trigger = CampaignTrigger::PURCHASE, array $config = [], ?string $ends_at = null ): int {
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
                array_replace( Campaign::default_config( $trigger ), $config ),
                [],
                null,
                $ends_at
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

        foreach ( $this->fixture_comments as $comment_id ) {
            wp_delete_comment( $comment_id, true );
        }

        foreach ( $this->fixture_products as $product ) {
            $product->delete( true );
        }

        require_once ABSPATH . 'wp-admin/includes/user.php';

        foreach ( $this->fixture_users as $user_id ) {
            wp_delete_user( $user_id );
        }

        $this->fixture_orders   = [];
        $this->fixture_users    = [];
        $this->fixture_products = [];
        $this->fixture_comments = [];

        remove_filter( 'pre_wp_mail', '__return_false' );

        foreach ( $this->woocommerce_email_ids() as $email_id ) {
            remove_filter( 'woocommerce_email_enabled_' . $email_id, '__return_false' );
        }
    }
}
