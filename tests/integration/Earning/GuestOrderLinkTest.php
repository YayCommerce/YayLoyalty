<?php
namespace YayLoyalty\Tests\Integration\Earning;

use Automattic\WooCommerce\Internal\CustomerEmailVerification\EmailVerificationService;
use YayLoyalty\Enums\LedgerType;
use YayLoyalty\Tests\Integration\IntegrationTestCase;
use YayLoyalty\Tests\Integration\WooCommerceOrdersFixture;

/** A guest order linked to an account, on verifying or on a verified customer's login, earns as if it had just completed: once, net of earlier refunds, and only when completed. */
final class GuestOrderLinkTest extends IntegrationTestCase {

    use WooCommerceOrdersFixture;

    private const EMAIL = 'yl-guest-link@example.com';

    protected function setUp(): void {
        parent::setUp();

        $this->require_woocommerce();
        $this->pin_settings();
        $this->create_campaign();
    }

    protected function tearDown(): void {
        $this->cleanup_orders();

        parent::tearDown();
    }

    public function test_a_linked_completed_order_earns_once(): void {
        $this->transition( $this->guest_order( [ 40 ] ), 'completed' );

        $user_id = $this->create_customer( self::EMAIL );
        $this->assertSame( [], $this->ledger_points( $user_id ) );

        $this->assertSame( 1, wc_update_new_customer_past_orders( $user_id ) );

        $this->assertSame( [ 40 ], $this->ledger_points( $user_id ) );
        $this->assertSame( 40, (int) $this->customer_row( $user_id )['balance'] );

        // A second link finds no guest order; the hook re-firing finds an earn row.
        wc_update_new_customer_past_orders( $user_id );
        do_action( 'woocommerce_update_new_customer_past_order', (int) $this->ledger_rows( $user_id )[0]['order_id'], get_user_by( 'id', $user_id ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- firing WooCommerce's own hook.

        $this->assertSame( [ 40 ], $this->ledger_points( $user_id ) );
        $this->assertSame( 40, (int) $this->customer_row( $user_id )['balance'] );
    }

    public function test_a_refund_before_the_link_is_clawed_back(): void {
        $order = $this->transition( $this->guest_order( [ 30, 10 ] ), 'completed' );
        $this->refund_lines( $order, [ 0 ] );

        $user_id = $this->create_customer( self::EMAIL );
        wc_update_new_customer_past_orders( $user_id );

        $this->assertSame( [ 40, -30 ], $this->ledger_points( $user_id ) );
        $this->assertSame( LedgerType::REFUND_EARN, $this->ledger_rows( $user_id )[1]['type'] );
        $this->assertSame( 10, (int) $this->customer_row( $user_id )['balance'] );
    }

    public function test_an_order_not_completed_earns_only_on_completion(): void {
        $order = $this->guest_order( [ 40 ] );

        $user_id = $this->create_customer( self::EMAIL );
        wc_update_new_customer_past_orders( $user_id );

        $this->assertSame( [], $this->ledger_points( $user_id ) );

        $this->transition( wc_get_order( $order->get_id() ), 'completed' );

        $this->assertSame( [ 40 ], $this->ledger_points( $user_id ) );
    }

    public function test_an_open_order_shows_as_pending_once_linked_and_releases_on_completion(): void {
        $order = $this->transition( $this->guest_order( [ 40 ] ), 'on-hold' );

        $user_id = $this->create_customer( self::EMAIL );
        wc_update_new_customer_past_orders( $user_id );

        $this->assertSame( 40, (int) $this->customer_row( $user_id )['pending_points'] );

        // The hook re-firing must not stage twice.
        do_action( 'woocommerce_update_new_customer_past_order', (int) $order->get_id(), get_user_by( 'id', $user_id ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- firing WooCommerce's own hook.

        $this->assertSame( 40, (int) $this->customer_row( $user_id )['pending_points'] );

        $this->transition( wc_get_order( $order->get_id() ), 'completed' );

        $this->assertSame( 0, (int) $this->customer_row( $user_id )['pending_points'] );
        $this->assertSame( [ 40 ], $this->ledger_points( $user_id ) );
    }

    public function test_a_verified_customers_login_links_a_later_guest_order_and_earns_once(): void {
        if ( ! class_exists( EmailVerificationService::class ) ) {
            $this->markTestSkipped( 'WooCommerce has no email verification before 11.0.' );
        }

        $user_id = $this->create_customer( self::EMAIL );
        wc_get_container()->get( EmailVerificationService::class )->mark_verified( $user_id );

        $order = $this->transition( $this->guest_order( [ 40 ] ), 'completed' );
        $user  = get_user_by( 'id', $user_id );

        do_action( 'wp_login', $user->user_login, $user ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- firing WordPress's own hook.

        $this->assertSame( $user_id, (int) wc_get_order( $order->get_id() )->get_customer_id() );
        $this->assertSame( [ 40 ], $this->ledger_points( $user_id ) );

        do_action( 'wp_login', $user->user_login, $user ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- firing WordPress's own hook.

        $this->assertSame( [ 40 ], $this->ledger_points( $user_id ) );
        $this->assertSame( 40, (int) $this->customer_row( $user_id )['balance'] );
    }

    /** @param float[] $line_totals */
    private function guest_order( array $line_totals ): \WC_Order {
        $order = $this->create_order( null, $line_totals );

        $order->set_billing_email( self::EMAIL );
        $order->save();

        return $order;
    }
}
