<?php
namespace YayLoyalty\Tests\Integration\Earning;

use YayLoyalty\Engine\Earning\Outcome;
use YayLoyalty\Enums\LedgerSource;
use YayLoyalty\Enums\LedgerType;
use YayLoyalty\Enums\ReferenceType;
use YayLoyalty\Services\LedgerService;
use YayLoyalty\Tests\Integration\IntegrationTestCase;
use YayLoyalty\Tests\Integration\WooCommerceOrdersFixture;

/** An order's missing points given back once, as that order's earning, and clawed back like one. */
final class OrderPointsRecoveryTest extends IntegrationTestCase {

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

    public function test_recovery_writes_one_keyed_earn_row(): void {
        $user_id = $this->create_customer();
        $order   = $this->create_order( $user_id, [ 40 ], 'processing' );

        $outcome = $this->ledger_service()->recover_order( $user_id, (int) $order->get_id(), 120, 'Server error', 1 );

        $this->assertSame( Outcome::AWARDED, $outcome->get_status() );
        $row = $this->ledger_rows( $user_id )[0];
        $this->assertSame( [ 120 ], $this->ledger_points( $user_id ) );
        $this->assertSame( LedgerType::EARN, $row['type'] );
        $this->assertSame( LedgerSource::ORDER, $row['source'] );
        $this->assertSame( ReferenceType::ORDER, $row['reference_type'] );
        $this->assertSame( (int) $order->get_id(), (int) $row['order_id'] );
        $this->assertNull( $row['campaign_id'] );
        $this->assertSame( 1, (int) $row['actor_id'] );
        $this->assertSame( 'Server error', $row['note'] );
        $this->assertSame( $this->recovery_key( $user_id, (int) $order->get_id() ), $row['idempotency_key'] );
        $this->assertSame( 120, (int) $this->customer_row( $user_id )['balance'] );
        $this->assertSame( 120, (int) $this->customer_row( $user_id )['lifetime_earned'] );
    }

    public function test_second_recovery_is_a_duplicate(): void {
        $user_id = $this->create_customer();
        $order   = $this->create_order( $user_id, [ 40 ], 'processing' );
        $this->ledger_service()->recover_order( $user_id, (int) $order->get_id(), 120, 'First', 1 );

        $outcome = $this->ledger_service()->recover_order( $user_id, (int) $order->get_id(), 120, 'Second', 1 );

        $this->assertSame( Outcome::DUPLICATE, $outcome->get_status() );
        $this->assertSame( [ 120 ], $this->ledger_points( $user_id ) );
        $this->assertSame( 120, (int) $this->customer_row( $user_id )['balance'] );
    }

    public function test_recovery_is_refused_when_the_order_has_live_points(): void {
        $this->create_campaign();
        $user_id = $this->create_customer();
        $order   = $this->transition( $this->create_order( $user_id, [ 40 ] ), 'completed' );

        $outcome = $this->ledger_service()->recover_order( $user_id, (int) $order->get_id(), 40, 'Again', 1 );

        $this->assertSame( Outcome::SKIPPED, $outcome->get_status() );
        $this->assertSame( LedgerService::REASON_ORDER_HAS_POINTS, $outcome->get_reason() );
        $this->assertSame( [ 40 ], $this->ledger_points( $user_id ) );
    }

    public function test_later_completion_is_skipped_after_recovery(): void {
        $this->create_campaign();
        $user_id = $this->create_customer();
        $order   = $this->create_order( $user_id, [ 40 ], 'processing' );
        $this->ledger_service()->recover_order( $user_id, (int) $order->get_id(), 40, 'Stuck', 1 );

        $this->transition( $order, 'completed' );

        $this->assertSame( [ 40 ], $this->ledger_points( $user_id ) );
        $this->assertSame( 40, (int) $this->customer_row( $user_id )['balance'] );
    }

    public function test_cancel_claws_back_the_recovery(): void {
        $user_id = $this->create_customer();
        $order   = $this->create_order( $user_id, [ 40 ], 'processing' );
        $this->ledger_service()->recover_order( $user_id, (int) $order->get_id(), 40, 'Stuck', 1 );

        $this->transition( $order, 'cancelled' );

        $rows = $this->ledger_rows( $user_id );
        $this->assertSame( [ 40, -40 ], $this->ledger_points( $user_id ) );
        $this->assertSame( LedgerType::REFUND_EARN, $rows[1]['type'] );
        $this->assertSame( 'refund_earn:' . $user_id . ':order:' . $order->get_id() . ':recovery', $rows[1]['idempotency_key'] );
        $this->assertSame( 0, (int) $this->customer_row( $user_id )['balance'] );
    }

    private function recovery_key( int $user_id, int $order_id ): string {
        return "earn:{$user_id}:order:{$order_id}:recovery";
    }
}
