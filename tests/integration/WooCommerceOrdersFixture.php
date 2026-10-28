<?php
namespace YayLoyalty\Tests\Integration;

use YayLoyalty\Engine\Emails\PointsEarnedEmail;
use YayLoyalty\Helpers\SettingsHelper;
use YayLoyalty\Enums\CampaignTrigger;
use YayLoyalty\Integrations\YayMail\PointsEarnedYayMailEmail;
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

    /** @var array<string, mixed>|null Settings pin_settings() serves in place of the site's own. */
    private $fixture_settings = null;

    /** @var callable|null The pre_wp_mail capture send_emails_for_real() installed. */
    private $fixture_capture = null;

    /** @var string[] Ids of the emails send_emails_for_real() switched on. */
    private $fixture_real_emails = [];

    /** @var string[] Ids of the emails send_emails_for_real() added to the mailer, because the mailer lacked them. */
    private $fixture_added_emails = [];

    /** @var array<int, array{0: string, 1: callable, 2: int, 3: int}> Hook, callback, priority and arguments that detach_yaymail() removed. */
    private $detached_yaymail = [];

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

        $this->detach_yaymail();
    }

    /**
     * Keeps YayMail out of loyalty sends. It looks its templates up through
     * $wpdb->prefix, which points at the scratch tables, so on every send it
     * misses and inserts a fresh template into the site's own posts table.
     */
    private function detach_yaymail(): void {
        global $wp_filter;

        $subject_hook = 'woocommerce_email_subject_' . PointsEarnedEmail::ID;

        // YayMail's subject filter is a closure.
        if ( isset( $wp_filter[ $subject_hook ] ) ) {
            foreach ( $wp_filter[ $subject_hook ]->callbacks as $priority => $callbacks ) {
                foreach ( $callbacks as $callback ) {
                    if ( $this->is_yaymail_closure( $callback['function'] ) ) {
                        $this->detach( $subject_hook, $callback['function'], (int) $priority, (int) $callback['accepted_args'] );
                    }
                }
            }
        }

        if ( ! class_exists( PointsEarnedYayMailEmail::class, false ) ) {
            return;
        }

        $callback = [ PointsEarnedYayMailEmail::get_instance(), 'get_template_file' ];
        $priority = has_filter( 'wc_get_template', $callback );

        if ( false !== $priority ) {
            $this->detach( 'wc_get_template', $callback, (int) $priority, 3 );
        }
    }

    /** @param mixed $callback */
    private function is_yaymail_closure( $callback ): bool {
        if ( ! $callback instanceof \Closure ) {
            return false;
        }

        $scope = ( new \ReflectionFunction( $callback ) )->getClosureScopeClass();

        return null !== $scope && 0 === strpos( $scope->getName(), 'YayMail\\' );
    }

    private function detach( string $hook, callable $callback, int $priority, int $accepted_args ): void {
        remove_filter( $hook, $callback, $priority );
        $this->detached_yaymail[] = [ $hook, $callback, $priority, $accepted_args ];
    }

    /** Puts back what detach_yaymail() removed. */
    private function reattach_yaymail(): void {
        foreach ( $this->detached_yaymail as [ $hook, $callback, $priority, $accepted_args ] ) {
            add_filter( $hook, $callback, $priority, $accepted_args );
        }

        $this->detached_yaymail = [];
    }

    /**
     * Lets these emails send through WooCommerce's mailer until cleanup_orders(), with $capture on pre_wp_mail
     * in place of the fixture's failing short-circuit. Adds any email the mailer lacks: the mailer is built once
     * per process, so it misses the expiry emails when it was built while the site had expiry off.
     *
     * @param callable $capture       A pre_wp_mail filter taking ( $result, $atts ).
     * @param string   ...$email_classes LoyaltyEmail subclasses, each with an ID constant.
     */
    private function send_emails_for_real( callable $capture, string ...$email_classes ): void {
        remove_filter( 'pre_wp_mail', '__return_false' );
        add_filter( 'pre_wp_mail', $capture, 10, 2 );
        $this->fixture_capture = $capture;

        $mailer = WC()->mailer();

        foreach ( $email_classes as $email_class ) {
            $email_id = $email_class::ID;

            remove_filter( 'woocommerce_email_enabled_' . $email_id, '__return_false' );
            add_filter( 'woocommerce_email_enabled_' . $email_id, '__return_true' );
            $this->fixture_real_emails[] = $email_id;

            if ( ! isset( $mailer->emails[ $email_id ] ) ) {
                $mailer->emails[ $email_id ]  = new $email_class();
                $this->fixture_added_emails[] = $email_id;
            }
        }
    }

    /** @return string[] */
    private function woocommerce_email_ids(): array {
        return array_values( wp_list_pluck( WC()->mailer()->get_emails(), 'id' ) );
    }

    /**
     * Serves defaults plus $overrides as the settings until cleanup_orders(), so the site's own settings never change a result.
     *
     * @param array<string, mixed> $overrides
     */
    private function pin_settings( array $overrides = [] ): void {
        $this->fixture_settings = [ 'schema_version' => SettingsHelper::SCHEMA_VERSION ] + $overrides;

        add_filter( 'pre_option_' . SettingsHelper::OPTION_SETTINGS, [ $this, 'fixture_settings' ] );
    }

    /** @return array<string, mixed> */
    public function fixture_settings(): array {
        return (array) $this->fixture_settings;
    }

    /** A customer with a unique address, so the Points earned email has somewhere to go. */
    private function customer_with_email(): int {
        return $this->create_customer( 'yl_it_' . wp_generate_password( 8, false ) . '@example.com' );
    }

    /** A customer-role user, registered while an administrator is current so SignupListener does not award it. */
    private function create_customer( string $email = '' ): int {
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
                'user_email' => $email,
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
     * @param int[]   $product_ids    Product per line index, for lines a campaign must be able to exclude.
     * @param float[] $line_subtotals Pre-discount amount per line index; the line's total when absent.
     */
    private function create_order( ?int $user_id, array $line_totals, string $status = 'pending', array $product_ids = [], array $line_subtotals = [] ): \WC_Order {
        $order = new \WC_Order();
        $order->set_customer_id( (int) $user_id );
        $order->set_currency( 'USD' );

        foreach ( $line_totals as $index => $total ) {
            $item = new \WC_Order_Item_Product();
            $item->set_name( 'Line ' . ( $index + 1 ) );
            $item->set_product_id( $product_ids[ $index ] ?? 0 );
            $item->set_quantity( 1 );
            $item->set_subtotal( $line_subtotals[ $index ] ?? $total );
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

    /** Deletes a refund as the order screen does, which fires woocommerce_refund_deleted. */
    private function delete_refund( \WC_Order_Refund $refund ): void {
        $refund_id = $refund->get_id();
        $order_id  = $refund->get_parent_id();

        $refund->delete( true );
        do_action( 'woocommerce_refund_deleted', $refund_id, $order_id ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- firing WooCommerce's own hook, as its AJAX handler does.
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

    /**
     * A top-level review inserted unapproved, then approved, so transition_comment_status reaches the listener.
     * $verified writes the meta WooCommerce sets on comment_post, which wp_insert_comment() never fires.
     */
    private function approve_new_review( int $user_id, int $product_id, bool $verified = false ): int {
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

        if ( $verified ) {
            update_comment_meta( $comment_id, 'verified', 1 );
        }

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
        remove_filter( 'pre_option_' . SettingsHelper::OPTION_SETTINGS, [ $this, 'fixture_settings' ] );
        $this->fixture_settings = null;

        if ( $this->fixture_capture ) {
            remove_filter( 'pre_wp_mail', $this->fixture_capture, 10 );
            $this->fixture_capture = null;
        }

        foreach ( $this->fixture_real_emails as $email_id ) {
            remove_filter( 'woocommerce_email_enabled_' . $email_id, '__return_true' );
        }

        foreach ( $this->fixture_added_emails as $email_id ) {
            unset( WC()->mailer()->emails[ $email_id ] );
        }

        $this->fixture_real_emails  = [];
        $this->fixture_added_emails = [];

        foreach ( $this->woocommerce_email_ids() as $email_id ) {
            remove_filter( 'woocommerce_email_enabled_' . $email_id, '__return_false' );
        }

        $this->reattach_yaymail();
    }
}
