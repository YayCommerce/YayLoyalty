<?php
namespace YayLoyalty\Tests\Integration\Earning;

use YayLoyalty\Engine\Earning\Outcome;
use YayLoyalty\Engine\Earning\PendingPoints;
use YayLoyalty\Enums\LedgerSource;
use YayLoyalty\Enums\LedgerType;
use YayLoyalty\Enums\ReferenceType;
use YayLoyalty\Repositories\CustomerRepository;
use YayLoyalty\Services\LedgerService;
use YayLoyalty\Tests\Integration\IntegrationTestCase;
use YayLoyalty\Tests\Integration\WooCommerceOrdersFixture;
use YayLoyalty\Utils\Dates;

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
        wp_set_current_user( 0 );

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

    public function test_rest_recovery_of_a_failed_award(): void {
        $this->create_campaign();
        $user_id = $this->create_customer();
        $order   = $this->create_order( $user_id, [ 40 ] ); // pending: stages 40.
        $throw   = static function () {
            throw new \RuntimeException( 'test' );
        };
        add_filter( 'yayloyalty_award_points', $throw );
        $order = $this->transition( $order, 'completed' );
        remove_filter( 'yayloyalty_award_points', $throw );
        $this->assertSame( [], $this->ledger_points( $user_id ) );

        $this->act_as_admin();
        $route = '/yayloyalty/v1/orders/' . $order->get_id() . '/points';
        $this->assertSame( 'missing_known', $this->send( 'GET', $route )->get_data()['status'] );
        $this->assertSame( 40, $this->send( 'GET', $route )->get_data()['figure'] );

        $response = $this->send( 'POST', $route );

        $this->assertSame( 200, $response->get_status() );
        $this->assertSame( 'recovered', $response->get_data()['status'] );
        $this->assertSame( [ 40 ], $this->ledger_points( $user_id ) );
        $this->assertSame( 'Recovered missing order points', $this->ledger_rows( $user_id )[0]['note'] );
        $this->assertSame( 0, (int) $this->customer_row( $user_id )['pending_points'] );
        $fresh = wc_get_order( $order->get_id() );
        $this->assertNotSame( '', (string) $fresh->get_meta( PendingPoints::META_RELEASED_AT ) );
        $this->assertSame( 40, (int) $fresh->get_meta( PendingPoints::META_EARNED ) );
        $this->assertSame( 'yayloyalty_already_recovered', $this->send( 'POST', $route )->as_error()->get_error_code() );
    }

    /** Case 4: the order meta claims 40 earned and released, the ledger has nothing, and pending_points still holds 40. */
    public function test_recovery_after_stale_earned_meta(): void {
        $user_id = $this->create_customer();
        $order   = $this->create_order( $user_id, [ 40 ], 'completed' );
        $order->update_meta_data( PendingPoints::META_PENDING, 40 );
        $order->update_meta_data( PendingPoints::META_RELEASED_AT, Dates::now() );
        $order->update_meta_data( PendingPoints::META_EARNED, 40 );
        $order->save_meta_data();
        ( new CustomerRepository() )->ensure_row( $user_id );
        ( new CustomerRepository() )->apply_pending_delta( $user_id, 40 );

        $this->act_as_admin();
        $route = '/yayloyalty/v1/orders/' . $order->get_id() . '/points';
        $state = $this->send( 'GET', $route )->get_data();
        $this->assertSame( 'missing_known', $state['status'] );
        $this->assertSame( 40, $state['figure'] );

        $this->assertSame( 200, $this->send( 'POST', $route )->get_status() );

        $this->assertSame( [ 40 ], $this->ledger_points( $user_id ) );
        $this->assertSame( 40, (int) wc_get_order( $order->get_id() )->get_meta( PendingPoints::META_EARNED ) );
        $this->assertSame( 0, (int) $this->customer_row( $user_id )['pending_points'] );
        $this->assertSame( 40, (int) $this->customer_row( $user_id )['balance'] );
    }

    private function recovery_key( int $user_id, int $order_id ): string {
        return "earn:{$user_id}:order:{$order_id}:recovery";
    }
}
