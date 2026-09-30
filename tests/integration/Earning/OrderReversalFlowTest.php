<?php
namespace YayLoyalty\Tests\Integration\Earning;

use YayLoyalty\Engine\Earning\PendingPoints;
use YayLoyalty\Enums\CampaignTrigger;
use YayLoyalty\Enums\LedgerType;
use YayLoyalty\Tests\Integration\IntegrationTestCase;
use YayLoyalty\Tests\Integration\WooCommerceOrdersFixture;

/** Points come back out through WooCommerce's own refund, cancel and trash hooks, never more than was earned. */
final class OrderReversalFlowTest extends IntegrationTestCase {

    use WooCommerceOrdersFixture;

    private int $user_id = 0;

    protected function setUp(): void {
        parent::setUp();

        $this->require_woocommerce();
        $this->user_id = $this->create_customer();
    }

    protected function tearDown(): void {
        $this->cleanup_orders();

        parent::tearDown();
    }

    public function test_line_refunds_claw_back_cumulatively_and_land_exactly_on_earned(): void {
        $this->create_campaign();
        $order = $this->completed_order( [ 30, 30, 40 ] );

        $this->refund_lines( $order, [ 0 ] );
        $this->refund_lines( wc_get_order( $order->get_id() ), [ 1 ] );
        $this->refund_lines( wc_get_order( $order->get_id() ), [ 2 ] );

        $this->assertSame( [ 100, -30, -30, -40 ], $this->ledger_points( $this->user_id ) );
        $this->assertSame( 0, $this->balance() );
        $this->assertSame( 0, (int) $this->customer_row( $this->user_id )['lifetime_earned'] );
    }

    /** Per-refund flooring would claw 33 + 33 + 33 = 99 and strand a point; recomputing the cumulative total lands on 100. */
    public function test_three_third_refunds_claw_back_33_then_66_then_exactly_100(): void {
        $this->create_campaign( CampaignTrigger::PURCHASE, [ 'points_per_unit' => 3.34 ] );
        $order = $this->completed_order( [ 10, 10, 10 ] );
        $this->assertSame( 100, $this->balance(), 'floor(30 × 3.34) earns 100.' );

        $clawed = [];
        foreach ( [ 0, 1, 2 ] as $line ) {
            $this->refund_lines( wc_get_order( $order->get_id() ), [ $line ] );
            $clawed[] = 100 - $this->balance();
        }

        $this->assertSame( [ 33, 66, 100 ], $clawed );
        $this->assertSame( [ 100, -33, -33, -34 ], $this->ledger_points( $this->user_id ), 'The last refund carries the stranded point.' );
    }

    /** An amount-only refund that completes the refund takes the shortcut: everything left is owed. */
    public function test_an_amount_only_refund_that_completes_the_order_claws_back_the_rest(): void {
        $this->create_campaign();
        $order = $this->completed_order( [ 60, 40 ] );

        $this->refund_lines( $order, [ 0 ] );
        $this->refund( wc_get_order( $order->get_id() ), 40 );

        $this->assertSame( [ 100, -60, -40 ], $this->ledger_points( $this->user_id ) );
    }

    /** Marking an order refunded reverses it once, whether WooCommerce creates a refund object for it or not. */
    public function test_the_refunded_status_reverses_once(): void {
        $this->create_campaign();

        $this->transition( $this->completed_order( [ 100 ] ), 'refunded' );

        $this->assertSame( [ 100, -100 ], $this->ledger_points( $this->user_id ) );
    }

    /** A deleted refund must not leave a cached refund total that stops the refunded status from reversing. */
    public function test_marking_refunded_after_a_deleted_refund_still_reverses_everything(): void {
        $this->create_campaign();
        $order = $this->completed_order( [ 50, 50 ] );
        $this->delete_refund( $this->refund_lines( $order, [ 0 ] ), $order );

        $this->transition( wc_get_order( $order->get_id() ), 'refunded' );

        $this->assertSame( 0, $this->balance() );
    }

    public function test_refunding_only_an_excluded_product_claws_back_nothing(): void {
        $excluded = $this->create_product();
        $this->create_campaign( CampaignTrigger::PURCHASE, [ 'excluded_product_ids' => [ $excluded ] ] );
        $order = $this->transition( $this->create_order( $this->user_id, [ 60, 40 ], 'pending', [ $excluded ] ), 'completed' );

        $this->refund_lines( $order, [ 0 ] );

        $this->assertSame( [ 40 ], $this->ledger_points( $this->user_id ) );
    }

    public function test_an_amount_only_refund_claws_back_nothing(): void {
        $this->create_campaign();

        $this->refund( $this->completed_order( [ 100 ] ), 5 );

        $this->assertSame( [ 100 ], $this->ledger_points( $this->user_id ) );
    }

    /** The balance may go negative: points already spent are still owed. */
    public function test_cancel_claws_back_everything_even_below_zero(): void {
        $this->create_campaign();
        $order = $this->completed_order( [ 100 ] );
        $this->ledger_service()->adjust( $this->user_id, -30, 'Spent', 1 );

        $order = $this->transition( $order, 'cancelled' );

        $this->assertSame( -30, $this->balance() );
        $this->assertStringContainsString( 'Reversed 100 points', $this->order_notes( $order )[0] );
    }

    public function test_cancel_after_a_partial_refund_claws_back_only_the_remainder(): void {
        $this->create_campaign();
        $order = $this->completed_order( [ 30, 70 ] );

        $this->refund_lines( $order, [ 0 ] );
        $this->transition( wc_get_order( $order->get_id() ), 'cancelled' );

        $this->assertSame( [ 100, -30, -70 ], $this->ledger_points( $this->user_id ) );
    }

    public function test_completing_a_cancelled_order_again_awards_nothing_and_says_so(): void {
        $this->create_campaign();
        $order = $this->transition( $this->completed_order( [ 100 ] ), 'cancelled' );

        $order = $this->transition( $order, 'completed' );

        $this->assertSame( [ 100, -100 ], $this->ledger_points( $this->user_id ) );
        $this->assertStringContainsString( 'reversed earlier', $this->order_notes( $order )[0] );
    }

    public function test_a_deleted_refund_is_restored_and_a_new_refund_claws_again(): void {
        $this->create_campaign();
        $order  = $this->completed_order( [ 50, 50 ] );
        $refund = $this->refund_lines( $order, [ 0 ] );

        $this->delete_refund( $refund, $order );
        $this->refund_lines( wc_get_order( $order->get_id() ), [ 0 ] );

        $this->assertSame( [ 100, -50, 50, -50 ], $this->ledger_points( $this->user_id ) );
        $this->assertSame( 50, $this->balance() );
    }

    /** A cancel is final: deleting a refund afterwards must not hand points back on an order that no longer earns. */
    public function test_a_refund_deleted_after_cancel_restores_nothing(): void {
        $this->create_campaign();
        $order  = $this->completed_order( [ 60, 40 ] );
        $refund = $this->refund_lines( $order, [ 0 ] );
        $order  = $this->transition( wc_get_order( $order->get_id() ), 'cancelled' );

        $this->delete_refund( $refund, $order );

        $this->assertSame( [ 100, -60, -40 ], $this->ledger_points( $this->user_id ) );
        $this->assertSame( 0, $this->balance() );
    }

    public function test_a_refired_refund_hook_is_a_duplicate(): void {
        $this->create_campaign();
        $order  = $this->completed_order( [ 50, 50 ] );
        $refund = $this->refund_lines( $order, [ 0 ] );

        do_action( 'woocommerce_order_refunded', $order->get_id(), $refund->get_id() ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- re-firing WooCommerce's own hook.

        $this->assertSame( [ 100, -50 ], $this->ledger_points( $this->user_id ) );
    }

    /** A second staged order keeps pending above zero, so a double release would show. */
    public function test_a_pending_order_cancelled_before_completion_releases_once_and_can_still_award(): void {
        $this->create_campaign();
        $order = $this->create_order( $this->user_id, [ 100 ] );
        $this->create_order( $this->user_id, [ 50 ] );

        $order = $this->transition( $order, 'cancelled' );
        $this->assertSame( 50, $this->pending() );
        $this->assertSame( [], $this->ledger_points( $this->user_id ) );

        $this->transition( $order, 'completed' );

        $this->assertSame( 50, $this->pending() );
        $this->assertSame( [ 100 ], $this->ledger_points( $this->user_id ) );
    }

    /** HPOS trash writes the status directly, without woocommerce_order_status_changed; woocommerce_trash_order carries it. */
    public function test_trash_claws_back_like_cancel(): void {
        $this->create_campaign();
        $order = $this->completed_order( [ 100 ] );

        $order->delete( false );

        $this->assertSame( [ 100, -100 ], $this->ledger_points( $this->user_id ) );
    }

    /**
     * @param float[] $line_totals
     */
    private function completed_order( array $line_totals ): \WC_Order {
        return $this->transition( $this->create_order( $this->user_id, $line_totals ), 'completed' );
    }

    /** What WooCommerce's refund screen does: delete the refund, then announce it. */
    private function delete_refund( \WC_Order_Refund $refund, \WC_Order $order ): void {
        $refund_id = $refund->get_id();
        $refund->delete( true );

        do_action( 'woocommerce_refund_deleted', $refund_id, $order->get_id() ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce's hook, fired as its AJAX handler does.
    }

    private function balance(): int {
        return (int) $this->customer_row( $this->user_id )['balance'];
    }

    private function pending(): int {
        return (int) $this->customer_row( $this->user_id )['pending_points'];
    }
}
