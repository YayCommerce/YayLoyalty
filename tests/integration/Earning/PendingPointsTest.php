<?php
namespace YayLoyalty\Tests\Integration\Earning;

use YayLoyalty\Engine\Earning\EarningService;
use YayLoyalty\Engine\Earning\Outcome;
use YayLoyalty\Engine\Earning\PendingPoints;
use YayLoyalty\Engine\Points\ContextFactory;
use YayLoyalty\Enums\CampaignTrigger;
use YayLoyalty\Repositories\CustomerRepository;
use YayLoyalty\Tests\Integration\IntegrationTestCase;
use YayLoyalty\Tests\Integration\WooCommerceOrdersFixture;

/**
 * Staging and release on real order meta: each moves customers.pending_points
 * once, whichever hook or path calls it. Orders are created with no campaign
 * active, so the listener stages nothing and each test stages by hand.
 */
final class PendingPointsTest extends IntegrationTestCase {

    use WooCommerceOrdersFixture;

    private PendingPoints $pending;

    private int $user_id = 0;

    protected function setUp(): void {
        parent::setUp();

        $this->require_woocommerce();
        $this->pending = new PendingPoints( new CustomerRepository() );
        $this->user_id = $this->create_customer();
    }

    protected function tearDown(): void {
        $this->cleanup_orders();

        parent::tearDown();
    }

    public function test_stage_is_idempotent_keyed_on_the_pending_meta_being_present(): void {
        $order_id = $this->order_id();

        $this->assertTrue( $this->pending->stage( $order_id, $this->user_id, 100 ) );
        $this->assertFalse( $this->pending->stage( $order_id, $this->user_id, 70 ) );

        $this->assertSame( 100, (int) wc_get_order( $order_id )->get_meta( PendingPoints::META_PENDING ) );
        $this->assertSame( 100, $this->pending_points() );
    }

    /** A new-order hook and a status hook may both reach staging for one order. */
    public function test_stage_is_safe_to_call_from_several_hooks(): void {
        $this->create_campaign();
        $order = $this->create_order( $this->user_id, [ 100 ] );

        do_action( 'woocommerce_new_order', $order->get_id(), $order ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- re-firing WooCommerce's own hook.
        $this->transition( $order, 'on-hold' );

        $this->assertSame( 100, $this->pending_points() );
    }

    public function test_release_happens_exactly_once_guarded_by_the_release_flag(): void {
        $order_id = $this->order_id();
        $this->pending->stage( $order_id, $this->user_id, 100 );

        $this->assertSame( 100, $this->pending->release( $order_id ) );
        $this->assertSame( 0, $this->pending->release( $order_id ) );
        $this->assertSame( 0, $this->pending_points() );
        $this->assertNotEmpty( wc_get_order( $order_id )->get_meta( PendingPoints::META_RELEASED ) );
    }

    public function test_release_without_prior_stage_is_a_no_op(): void {
        $order_id = $this->order_id();

        $this->assertSame( 0, $this->pending->release( $order_id ) );
        $this->assertSame( '', wc_get_order( $order_id )->get_meta( PendingPoints::META_RELEASED ) );
    }

    /** A second order keeps pending above zero, so a double release would show instead of being floored away. */
    public function test_release_on_cancel_then_on_award_moves_pending_once(): void {
        $order_id = $this->order_id();
        $this->pending->stage( $order_id, $this->user_id, 100 );
        $this->pending->stage( $this->order_id(), $this->user_id, 50 );

        $this->pending->release( $order_id );
        $this->create_campaign();
        $outcome = EarningService::build()->award( CampaignTrigger::PURCHASE, ContextFactory::from_order( wc_get_order( $order_id ) ) );

        $this->assertSame( Outcome::AWARDED, $outcome->get_status() );
        $this->assertSame( 50, $this->pending_points() );
    }

    private function order_id(): int {
        return (int) $this->create_order( $this->user_id, [ 100 ] )->get_id();
    }

    private function pending_points(): int {
        return (int) $this->customer_row( $this->user_id )['pending_points'];
    }
}
