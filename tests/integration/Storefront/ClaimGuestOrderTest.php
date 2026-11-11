<?php
namespace YayLoyalty\Tests\Integration\Storefront;

use YayLoyalty\Engine\Earning\EarningService;
use YayLoyalty\Engine\Earning\Listeners\OrderListener;
use YayLoyalty\Engine\Earning\Listeners\OrderReversalListener;
use YayLoyalty\Engine\Earning\PendingPoints;
use YayLoyalty\Engine\Earning\Reversal;
use YayLoyalty\Engine\Redemption\RedemptionService;
use YayLoyalty\Engine\Redemption\SessionIntent;
use YayLoyalty\Engine\Storefront\AccountClaimOrder;
use YayLoyalty\Enums\LedgerType;
use YayLoyalty\Repositories\CustomerRepository;
use YayLoyalty\Repositories\LedgerRepository;
use YayLoyalty\Tests\Integration\IntegrationTestCase;
use YayLoyalty\Tests\Integration\WooCommerceOrdersFixture;

/** A shopper claims a guest order from My Points: only with their own email, only once, and it earns as a normal completion. */
final class ClaimGuestOrderTest extends IntegrationTestCase {

    use WooCommerceOrdersFixture;

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

    public function test_a_completed_guest_order_is_assigned_and_earns_once(): void {
        $email   = 'yl_it_' . wp_generate_password( 8, false ) . '@example.com';
        $user_id = $this->create_customer( $email );
        $order   = $this->transition( $this->guest_order( strtoupper( $email ), [ 25 ] ), 'completed' );

        $result = $this->claimer()->claim( $user_id, '#' . $order->get_order_number() );

        $this->assertSame( 'success', $result['type'] );
        $this->assertSame( $user_id, wc_get_order( $order->get_id() )->get_customer_id() );
        $this->assertSame( [ 25 ], $this->ledger_points( $user_id ) );
        $this->assertSame( 25, (int) $this->customer_row( $user_id )['balance'] );

        $again = $this->claimer()->claim( $user_id, (string) $order->get_id() );

        $this->assertSame( 'error', $again['type'] );
        $this->assertSame( [ 25 ], $this->ledger_points( $user_id ) );
    }

    public function test_an_email_mismatch_assigns_nothing_and_writes_nothing(): void {
        $user_id = $this->customer_with_email();
        $order   = $this->transition( $this->guest_order( 'someone-else@example.com', [ 25 ] ), 'completed' );

        $result = $this->claimer()->claim( $user_id, (string) $order->get_id() );

        $this->assertSame( 'error', $result['type'] );
        $this->assertSame( 0, wc_get_order( $order->get_id() )->get_customer_id() );
        $this->assertSame( [], $this->ledger_points( $user_id ) );
    }

    public function test_another_customers_order_is_refused_and_stays_theirs(): void {
        $email    = 'yl_it_' . wp_generate_password( 8, false ) . '@example.com';
        $owner_id = $this->create_customer();
        $user_id  = $this->create_customer( $email );
        $order    = $this->create_order( $owner_id, [ 25 ] );
        $order->set_billing_email( $email );
        $order->save();

        $result = $this->claimer()->claim( $user_id, (string) $order->get_id() );

        $this->assertSame( 'error', $result['type'] );
        $this->assertSame( $owner_id, wc_get_order( $order->get_id() )->get_customer_id() );
        $this->assertSame( [], $this->ledger_points( $user_id ) );
    }

    /** 60 + 40 with the 40 line refunded while it was a guest order: the claim earns 100, then claws back 40. */
    public function test_a_refund_made_before_the_claim_is_clawed_back(): void {
        $email   = 'yl_it_' . wp_generate_password( 8, false ) . '@example.com';
        $user_id = $this->create_customer( $email );
        $order   = $this->transition( $this->guest_order( $email, [ 60, 40 ] ), 'completed' );
        $this->refund_lines( $order, [ 1 ] );

        $result = $this->claimer()->claim( $user_id, (string) $order->get_id() );

        $this->assertSame( 'success', $result['type'] );
        $this->assertStringContainsString( '60', $result['message'] );
        $this->assertSame( [ 100, -40 ], $this->ledger_points( $user_id ) );
        $this->assertSame( LedgerType::REFUND_EARN, $this->ledger_rows( $user_id )[1]['type'] );
        $this->assertSame( 60, (int) $this->customer_row( $user_id )['balance'] );
    }

    public function test_an_open_guest_order_is_staged_on_claim_and_earns_on_completion(): void {
        $email   = 'yl_it_' . wp_generate_password( 8, false ) . '@example.com';
        $user_id = $this->create_customer( $email );
        $order   = $this->transition( $this->guest_order( $email, [ 25 ] ), 'on-hold' );

        $result = $this->claimer()->claim( $user_id, (string) $order->get_id() );

        $this->assertSame( 'success', $result['type'] );
        $this->assertSame( [], $this->ledger_points( $user_id ) );
        $this->assertSame( 25, (int) $this->customer_row( $user_id )['pending_points'] );

        $this->transition( wc_get_order( $order->get_id() ), 'completed' );

        $this->assertSame( [ 25 ], $this->ledger_points( $user_id ) );
        $this->assertSame( 25, (int) $this->customer_row( $user_id )['balance'] );
        $this->assertSame( 0, (int) $this->customer_row( $user_id )['pending_points'] );
    }

    /**
     * A pending guest order billed to $email, not yet at a status that earns.
     *
     * @param float[] $line_totals
     */
    private function guest_order( string $email, array $line_totals ): \WC_Order {
        $order = $this->create_order( null, $line_totals );
        $order->set_billing_email( $email );
        $order->save();

        return wc_get_order( $order->get_id() );
    }

    /** Built as YayLoyalty::register_earning_listeners() builds it. */
    private function claimer(): AccountClaimOrder {
        $ledger    = new LedgerRepository();
        $pending   = new PendingPoints( new CustomerRepository() );
        $reversal  = Reversal::build();
        $reversals = new OrderReversalListener( $pending, $reversal, RedemptionService::build( new SessionIntent() )->reversal() );

        return new AccountClaimOrder( new OrderListener( EarningService::build(), $pending, $ledger, $reversals ), $reversal, $ledger );
    }
}
