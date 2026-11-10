<?php
namespace YayLoyalty\Tests\Integration\Earning;

use YayLoyalty\Engine\Earning\EarningService;
use YayLoyalty\Engine\Earning\IdempotencyKey;
use YayLoyalty\Engine\Earning\Outcome;
use YayLoyalty\Engine\Earning\ProgramGate;
use YayLoyalty\Engine\Points\ContextFactory;
use YayLoyalty\Enums\CampaignTrigger;
use YayLoyalty\Enums\LedgerType;
use YayLoyalty\Enums\ReferenceType;
use YayLoyalty\Repositories\LedgerRepository;
use YayLoyalty\Tests\Integration\IntegrationTestCase;
use YayLoyalty\Tests\Integration\WooCommerceOrdersFixture;
use YayLoyalty\Utils\Dates;

/** An admin grants the points a completed order missed: one earn row under the order's own key, never twice. */
final class OrderPointsGrantTest extends IntegrationTestCase {

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

    public function test_grant_writes_one_order_earn_row_and_blocks_a_second_grant_and_a_later_award(): void {
        $user_id  = $this->create_customer();
        $admin_id = $this->admin_id();

        // Completed with no live campaign: the automatic award writes nothing, so the order is missing points.
        $order = $this->transition( $this->create_order( $user_id, [ 25 ] ), 'completed' );
        $this->assertTrue( EarningService::is_missing_points( $order, new LedgerRepository() ) );

        $this->create_campaign();
        $service = EarningService::build();

        $outcome = $service->grant_for_order( $order, 40, $admin_id, 'Placed before the plugin' );

        $this->assertSame( Outcome::AWARDED, $outcome->get_status() );
        $rows = $this->ledger_rows( $user_id );
        $this->assertCount( 1, $rows );
        $this->assertSame( LedgerType::EARN, $rows[0]['type'] );
        $this->assertSame( 40, (int) $rows[0]['points'] );
        $this->assertSame( (int) $order->get_id(), (int) $rows[0]['order_id'] );
        $this->assertSame( $admin_id, (int) $rows[0]['actor_id'] );
        $this->assertSame(
            IdempotencyKey::for_earn( $user_id, ReferenceType::ORDER, (int) $order->get_id(), CampaignTrigger::PURCHASE ),
            $rows[0]['idempotency_key']
        );
        $this->assertSame( 40, (int) $this->customer_row( $user_id )['balance'] );
        $this->assertFalse( EarningService::is_missing_points( $order, new LedgerRepository() ) );

        $again = $service->grant_for_order( $order, 40, $admin_id, 'Second click' );
        $this->assertSame( Outcome::DUPLICATE, $again->get_status() );

        $award = $service->award( CampaignTrigger::PURCHASE, ContextFactory::from_order( wc_get_order( $order->get_id() ) ) );
        $this->assertSame( Outcome::DUPLICATE, $award->get_status() );

        $this->assertSame( [ 40 ], $this->ledger_points( $user_id ) );
        $this->assertSame( 40, (int) $this->customer_row( $user_id )['balance'] );
    }

    public function test_guest_and_not_completed_orders_are_refused(): void {
        $service = EarningService::build();
        $guest   = $this->transition( $this->create_order( null, [ 25 ] ), 'completed' );
        $pending = $this->create_order( $this->create_customer(), [ 25 ] );

        foreach ( [ $guest, $pending ] as $order ) {
            try {
                $service->grant_for_order( $order, 10, $this->admin_id(), 'Refused' );
                $this->fail( 'A grant for order ' . $order->get_id() . ' should throw.' );
            } catch ( \InvalidArgumentException $e ) {
                $this->assertStringContainsString( 'cannot be granted', $e->getMessage() );
            }
        }
    }

    public function test_a_grant_is_not_counted_toward_todays_earning(): void {
        $user_id = $this->create_customer();
        $order   = $this->transition( $this->create_order( $user_id, [ 25 ] ), 'completed' );

        EarningService::build()->grant_for_order( $order, 500, $this->admin_id(), 'Backfill' );

        $this->assertSame( [ 500 ], $this->ledger_points( $user_id ) );
        $this->assertSame( 0, ( new LedgerRepository() )->points_earned_on_site_day( $user_id, Dates::now(), null ) );
    }

    /** 60 + 40 with the 40 line refunded before the grant: the grant's 1000 keeps 600. */
    public function test_a_refund_made_before_the_grant_is_clawed_back_by_the_route(): void {
        $user_id = $this->create_customer();
        $order   = $this->transition( $this->create_order( $user_id, [ 60, 40 ] ), 'completed' );
        $this->refund_lines( $order, [ 1 ] );

        $this->act_as_admin();
        $response = $this->send( 'POST', '/yayloyalty/v1/orders/' . $order->get_id() . '/grant-points', [ 'points' => 1000 ] );

        $this->assertSame( 200, $response->get_status() );
        $this->assertSame( 400, $response->get_data()['clawed_back'] );
        $this->assertSame( [ 1000, -400 ], $this->ledger_points( $user_id ) );
        $this->assertSame( LedgerType::REFUND_EARN, $this->ledger_rows( $user_id )[1]['type'] );
        $this->assertSame( 600, (int) $this->customer_row( $user_id )['balance'] );
    }

    public function test_an_opted_out_customer_is_refused_and_nothing_is_written(): void {
        $user_id = $this->create_customer();
        update_user_meta( $user_id, ProgramGate::META_OPTED_OUT, 1 );
        $order = $this->transition( $this->create_order( $user_id, [ 25 ] ), 'completed' );

        $outcome = EarningService::build()->grant_for_order( $order, 40, $this->admin_id(), 'Refused' );

        $this->assertSame( Outcome::SKIPPED, $outcome->get_status() );
        $this->assertSame( ProgramGate::REASON_OPTED_OUT, $outcome->get_reason() );
        $this->assertSame( [], $this->ledger_points( $user_id ) );
    }

    private function admin_id(): int {
        $this->act_as_admin();

        return get_current_user_id();
    }
}
