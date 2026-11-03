<?php
namespace YayLoyalty\Tests\Integration\Earning;

use YayLoyalty\Engine\Earning\OrderPointsState;
use YayLoyalty\Engine\Earning\PendingPoints;
use YayLoyalty\Repositories\CustomerRepository;
use YayLoyalty\Repositories\LedgerRepository;
use YayLoyalty\Tests\Integration\IntegrationTestCase;
use YayLoyalty\Tests\Integration\WooCommerceOrdersFixture;
use YayLoyalty\Utils\Dates;

/** An order whose award never completed gets that same award again from the order screen, once. */
final class OrderPointsRetryTest extends IntegrationTestCase {

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

    /** Signal A: the award threw, so the staged figure was never released. */
    public function test_retry_awards_a_failed_award(): void {
        $this->create_campaign();
        $user_id = $this->create_customer();
        $order   = $this->create_order( $user_id, [ 40 ] ); // pending: stages 40.
        $throw   = static function () {
            throw new \RuntimeException( 'test' );
        };
        add_filter( 'yayloyalty_award_points', $throw );
        $order = $this->transition( $order, 'completed' );
        remove_filter( 'yayloyalty_award_points', $throw );

        $state = $this->fresh_state( $order->get_id() );
        $this->assertSame( OrderPointsState::MISSING, $state->get_status() );
        $this->assertSame( 40, $state->get_figure() );

        $this->act_as_admin();
        $route    = '/yayloyalty/v1/orders/' . $order->get_id() . '/points';
        $response = $this->send( 'POST', $route );

        $this->assertSame( 200, $response->get_status() );
        $this->assertSame( 'earned', $response->get_data()['status'] );
        $rows = $this->ledger_rows( $user_id );
        $this->assertCount( 1, $rows );
        $this->assertSame( 40, (int) $rows[0]['points'] );
        $this->assertSame( 'earn:' . $user_id . ':order:' . $order->get_id() . ':purchase', $rows[0]['idempotency_key'] );
        $this->assertNotNull( $rows[0]['campaign_id'] );
        $this->assertSame( 0, (int) $this->customer_row( $user_id )['pending_points'] );
        $fresh = wc_get_order( $order->get_id() );
        $this->assertNotSame( '', (string) $fresh->get_meta( PendingPoints::META_RELEASED_AT ) );
        $this->assertSame( 40, (int) $fresh->get_meta( PendingPoints::META_EARNED ) );
        $this->assertSame( 'yayloyalty_order_has_points', $this->send( 'POST', $route )->as_error()->get_error_code() );
        $this->assertCount( 1, $this->ledger_rows( $user_id ) );
    }

    /** Signal C: the meta claims 40 earned and released, the ledger has nothing, and pending_points still holds 40. */
    public function test_retry_after_stale_earned_meta(): void {
        $user_id = $this->create_customer();
        $order   = $this->create_order( $user_id, [ 40 ], 'completed' ); // No campaign yet: nothing awarded.
        $this->create_campaign();
        $order->update_meta_data( PendingPoints::META_PENDING, 40 );
        $order->update_meta_data( PendingPoints::META_RELEASED_AT, Dates::now() );
        $order->update_meta_data( PendingPoints::META_EARNED, 40 );
        $order->save_meta_data();
        ( new CustomerRepository() )->ensure_row( $user_id );
        ( new CustomerRepository() )->apply_pending_delta( $user_id, 40 );

        $this->assertSame( [], $this->ledger_points( $user_id ) );
        $this->assertSame( OrderPointsState::MISSING, $this->fresh_state( $order->get_id() )->get_status() );

        $this->act_as_admin();
        $this->assertSame( 200, $this->send( 'POST', '/yayloyalty/v1/orders/' . $order->get_id() . '/points' )->get_status() );

        $this->assertSame( [ 40 ], $this->ledger_points( $user_id ) );
        $this->assertSame( 40, (int) wc_get_order( $order->get_id() )->get_meta( PendingPoints::META_EARNED ) );
        $this->assertSame( 0, (int) $this->customer_row( $user_id )['pending_points'] );
        $this->assertSame( 40, (int) $this->customer_row( $user_id )['balance'] );
    }

    /** Group B: a reversal is final, so an order cancelled and set back to completed is not missing. */
    public function test_reversed_order_is_not_missing(): void {
        $this->create_campaign();
        $user_id = $this->create_customer();
        $order   = $this->transition( $this->create_order( $user_id, [ 40 ] ), 'completed' );
        $order   = $this->transition( $order, 'cancelled' );
        $order   = $this->transition( $order, 'completed' );

        $this->assertSame( OrderPointsState::NO_POINTS, $this->fresh_state( $order->get_id() )->get_status() );

        $this->act_as_admin();
        $response = $this->send( 'POST', '/yayloyalty/v1/orders/' . $order->get_id() . '/points' );

        $this->assertSame( 'yayloyalty_not_eligible', $response->as_error()->get_error_code() );
        $this->assertSame( [ 40, -40 ], $this->ledger_points( $user_id ) );
    }

    /** The state as the order screen's box resolves it: the order read past its cached copy. */
    private function fresh_state( int $order_id ): OrderPointsState {
        ( new PendingPoints( new CustomerRepository() ) )->forget_cached( $order_id );

        return OrderPointsState::resolve( wc_get_order( $order_id ), new LedgerRepository() );
    }
}
