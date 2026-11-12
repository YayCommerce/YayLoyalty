<?php
namespace YayLoyalty\Tests\Integration\Earning;

use YayLoyalty\Enums\CampaignTrigger;
use YayLoyalty\Enums\LedgerType;
use YayLoyalty\Tests\Integration\IntegrationTestCase;
use YayLoyalty\Tests\Integration\WooCommerceOrdersFixture;

/** Refunds, cancellations and refund deletes taking back, or giving back, what an order earned. */
final class ReversalFlowTest extends IntegrationTestCase {

    use WooCommerceOrdersFixture;

    protected function setUp(): void {
        parent::setUp();

        $this->require_woocommerce();
        $this->pin_settings();
    }

    protected function tearDown(): void {
        $this->cleanup_orders();

        parent::tearDown();
    }

    /** 3 points per unit on 29.99 earns 89; refunding the 10.00 line owes floor( 89 * 10 / 29.99 ) = 29. */
    public function test_partial_refund_claws_back_proportionally(): void {
        list( $user_id ) = $this->completed_order();

        $this->assertSame( [ 89, -29 ], $this->ledger_points( $user_id ) );
        $this->assertSame( LedgerType::REFUND_EARN, $this->ledger_rows( $user_id )[1]['type'] );
        $this->assertSame( 60, (int) $this->customer_row( $user_id )['balance'] );
    }

    public function test_deleting_refund_restores_points(): void {
        list( $user_id, , $refund ) = $this->completed_order();

        $this->delete_refund( $refund );

        $this->assertSame( [ 89, -29, 29 ], $this->ledger_points( $user_id ) );
        $this->assertSame( 89, (int) $this->customer_row( $user_id )['balance'] );
    }

    public function test_cancel_after_partial_refund_reverses_the_rest_only(): void {
        list( $user_id, $order ) = $this->completed_order();

        $this->transition( $order, 'cancelled' );

        $this->assertSame( [ 89, -29, -60 ], $this->ledger_points( $user_id ) );
        $this->assertSame( 0, (int) $this->customer_row( $user_id )['balance'] );
    }

    /** WooCommerce answers the `refunded` status with a refund for the remainder; the points come back once, not per path. */
    public function test_refunded_status_reverses_fully_once(): void {
        $this->create_campaign();
        $user_id = $this->create_customer();
        $order   = $this->transition( $this->create_order( $user_id, [ 40 ] ), 'completed' );

        $this->transition( $order, 'refunded' );

        $this->assertSame( [ 40, -40 ], $this->ledger_points( $user_id ) );
        $this->assertSame( 0, (int) $this->customer_row( $user_id )['balance'] );
    }

    public function test_cancelled_on_hold_order_releases_pending(): void {
        $this->create_campaign();
        $user_id = $this->create_customer();
        $order   = $this->create_order( $user_id, [ 40 ], 'on-hold' );

        $this->assertSame( 40, (int) $this->customer_row( $user_id )['pending_points'] );

        $this->transition( $order, 'cancelled' );

        $this->assertSame( 0, (int) $this->customer_row( $user_id )['pending_points'] );
        $this->assertSame( [], $this->ledger_points( $user_id ) );
    }

    public function test_reversed_order_is_not_re_awarded(): void {
        $this->create_campaign();
        $user_id = $this->create_customer();
        $order   = $this->transition( $this->create_order( $user_id, [ 40 ] ), 'completed' );
        $order   = $this->transition( $order, 'cancelled' );

        $this->transition( $order, 'completed' );

        $this->assertSame( [ 40, -40 ], $this->ledger_points( $user_id ) );
        $this->assertSame( 0, (int) $this->customer_row( $user_id )['balance'] );
    }

    /** 90 × ( 50 × 90 ÷ 100 ) ÷ 90 = 45. */
    public function test_an_amount_only_refund_claws_back_its_share(): void {
        list( $user_id, $order ) = $this->order_with_shipping();

        $this->refund( $order, 50 );

        $this->assertSame( [ 90, -45 ], $this->ledger_points( $user_id ) );
    }

    public function test_a_second_amount_refund_that_completes_the_order_claws_back_the_rest(): void {
        list( $user_id, $order ) = $this->order_with_shipping();

        $this->refund( $order, 50 );
        $this->refund( wc_get_order( $order->get_id() ), 50 );

        $this->assertSame( [ 90, -45, -45 ], $this->ledger_points( $user_id ) );
    }

    /** The line share 10.00 plus the amount share 10 × 29.99 ÷ 29.99 = 10.00: floor( 89 × 20 ÷ 29.99 ) = 59 owed. */
    public function test_a_line_refund_then_an_amount_refund_add_up(): void {
        list( $user_id, $order ) = $this->completed_order();

        $this->refund( $order, 10 );

        $this->assertSame( [ 89, -29, -30 ], $this->ledger_points( $user_id ) );
    }

    public function test_a_line_refund_with_extra_amount_claws_back_the_line_share_only(): void {
        $this->create_campaign( CampaignTrigger::PURCHASE, [ 'points_per_unit' => 3 ] );
        $user_id = $this->create_customer();
        $order   = $this->transition( $this->create_order( $user_id, [ 19.99, 10 ] ), 'completed' );
        $line    = array_values( $order->get_items() )[1];

        $this->refund(
            $order,
            15,
            [
                $line->get_id() => [
                    'qty'          => 1,
                    'refund_total' => 10,
                ],
            ]
        );

        $this->assertSame( [ 89, -29 ], $this->ledger_points( $user_id ) );
    }

    /** A 40.00 line and a −40.00 fee: the order earns 40 and totals 0.00, so its only possible amount refund is 0.00. */
    public function test_an_amount_refund_on_a_zero_total_order_claws_nothing(): void {
        $this->create_campaign();
        $user_id = $this->create_customer();
        $order   = $this->create_order( $user_id, [ 40 ] );

        $fee = new \WC_Order_Item_Fee();
        $fee->set_name( 'Gift' );
        $fee->set_total( -40 );
        $order->add_item( $fee );
        $order->calculate_totals( false );
        $order->save();
        $order = $this->transition( wc_get_order( $order->get_id() ), 'completed' );

        $this->refund( $order, 0 );

        $this->assertSame( [ 40 ], $this->ledger_points( $user_id ) );
    }

    /**
     * A completed order of a 90.00 line at 1 point per unit, which earns 90, and 10.00 shipping: 100.00 in all.
     *
     * @return array{0: int, 1: \WC_Order}
     */
    private function order_with_shipping(): array {
        $this->create_campaign( CampaignTrigger::PURCHASE, [ 'points_per_unit' => 1 ] );
        $user_id = $this->create_customer();
        $order   = $this->create_order( $user_id, [ 90 ] );

        $shipping = new \WC_Order_Item_Shipping();
        $shipping->set_method_title( 'Flat rate' );
        $shipping->set_total( 10 );
        $order->add_item( $shipping );
        $order->calculate_totals( false );
        $order->save();
        $this->assertSame( 100.0, (float) $order->get_total() );

        return [ $user_id, $this->transition( wc_get_order( $order->get_id() ), 'completed' ) ];
    }

    /**
     * A completed order of 19.99 + 10.00 at 3 points per unit, with its 10.00 line refunded.
     *
     * @return array{0: int, 1: \WC_Order, 2: \WC_Order_Refund}
     */
    private function completed_order(): array {
        $this->create_campaign( CampaignTrigger::PURCHASE, [ 'points_per_unit' => 3 ] );
        $user_id = $this->create_customer();
        $order   = $this->transition( $this->create_order( $user_id, [ 19.99, 10 ] ), 'completed' );
        $refund  = $this->refund_lines( $order, [ 1 ] );

        return [ $user_id, wc_get_order( $order->get_id() ), $refund ];
    }
}
