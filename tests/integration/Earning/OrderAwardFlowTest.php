<?php
namespace YayLoyalty\Tests\Integration\Earning;

use YayLoyalty\Engine\Earning\PendingPoints;
use YayLoyalty\Enums\CampaignTrigger;
use YayLoyalty\Enums\LedgerSource;
use YayLoyalty\Enums\LedgerType;
use YayLoyalty\Enums\Status;
use YayLoyalty\Models\LedgerEntry;
use YayLoyalty\Repositories\CampaignRepository;
use YayLoyalty\Repositories\LedgerRepository;
use YayLoyalty\Tests\Integration\IntegrationTestCase;
use YayLoyalty\Tests\Integration\WooCommerceOrdersFixture;
use YayLoyalty\Utils\Dates;

/** Orders through WooCommerce's own hooks: staged at pending, awarded once at completed, never at processing. */
final class OrderAwardFlowTest extends IntegrationTestCase {

    use WooCommerceOrdersFixture;

    private int $campaign_id = 0;

    protected function setUp(): void {
        parent::setUp();

        $this->require_woocommerce();
        $this->campaign_id = $this->create_campaign();
    }

    protected function tearDown(): void {
        $this->cleanup_orders();

        parent::tearDown();
    }

    public function test_pending_stages_and_completed_awards_once(): void {
        $user_id = $this->create_customer();
        $order   = $this->create_order( $user_id, [ 60, 40 ] );

        $this->assertSame( 100, (int) $order->get_meta( PendingPoints::META_PENDING ) );
        $this->assertSame( 100, (int) $this->customer_row( $user_id )['pending_points'] );

        $order = $this->transition( $order, 'completed' );

        $rows     = $this->ledger_rows( $user_id );
        $customer = $this->customer_row( $user_id );

        $this->assertCount( 1, $rows );
        $this->assertSame( LedgerType::EARN, $rows[0]['type'] );
        $this->assertSame( 100, (int) $rows[0]['points'] );
        $this->assertSame( 100, (int) $customer['balance'] );
        $this->assertSame( 100, (int) $customer['lifetime_earned'] );
        $this->assertSame( 0, (int) $customer['pending_points'] );
        $this->assertNotEmpty( $customer['last_activity_at'] );
        $this->assertSame( 100, (int) $order->get_meta( PendingPoints::META_EARNED ) );
        $this->assertSame( (int) $rows[0]['campaign_id'], (int) $order->get_meta( PendingPoints::META_CAMPAIGN ) );
        $this->assertNotEmpty( $order->get_meta( PendingPoints::META_RELEASED ) );
        $this->assertStringContainsString( 'Earned 100 points', $this->order_notes( $order )[0] );
    }

    public function test_completed_fired_twice_is_a_duplicate_with_no_change_and_no_note(): void {
        $user_id = $this->create_customer();
        $order   = $this->transition( $this->create_order( $user_id, [ 100 ] ), 'completed' );
        $before  = $this->customer_row( $user_id );
        $notes   = count( $this->order_notes( $order ) );

        do_action( 'woocommerce_order_status_changed', $order->get_id(), 'processing', 'completed', $order ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- re-firing WooCommerce's own hook.

        $this->assertCount( 1, $this->ledger_rows( $user_id ) );
        $this->assertSame( $before['balance'], $this->customer_row( $user_id )['balance'] );
        $this->assertCount( $notes, $this->order_notes( $order ) );
    }

    /** WooCommerce announces every new order as pending first, so it stages; processing then neither awards nor releases. */
    public function test_processing_awards_nothing_and_keeps_pending(): void {
        $user_id = $this->create_customer();
        $order   = $this->create_order( $user_id, [ 100 ], 'processing' );

        $this->assertSame( 100, (int) $this->customer_row( $user_id )['pending_points'] );
        $this->assertSame( '', $order->get_meta( PendingPoints::META_RELEASED ) );
        $this->assertCount( 0, $this->ledger_rows( $user_id ) );
    }

    /** Completed ends staging even when nothing is awarded, so pending never outlives the order. */
    public function test_a_skipped_award_still_releases_pending(): void {
        $user_id = $this->create_customer();
        $order   = $this->create_order( $user_id, [ 100 ] );

        ( new CampaignRepository() )->update( $this->campaign_id, [ 'status' => Status::PAUSED ] );
        $order = $this->transition( $order, 'completed' );

        $this->assertCount( 0, $this->ledger_rows( $user_id ) );
        $this->assertSame( 0, (int) $this->customer_row( $user_id )['pending_points'] );
        $this->assertNotEmpty( $order->get_meta( PendingPoints::META_RELEASED ) );
    }

    /** Guests are blocked by ProgramGate at both moments: nothing staged, no row, no note. */
    public function test_a_guest_order_stages_and_awards_nothing(): void {
        $order = $this->transition( $this->create_order( null, [ 100 ] ), 'completed' );

        $this->assertSame( '', $order->get_meta( PendingPoints::META_PENDING ) );
        $this->assertSame( '', $order->get_meta( PendingPoints::META_EARNED ) );
        $this->assertSame( [], preg_grep( '/points/i', $this->order_notes( $order ) ) );
    }

    /** The cap counts today's order earnings only: a review earned the same day does not use it up. */
    public function test_daily_cap_spans_orders_and_ignores_other_sources(): void {
        ( new CampaignRepository() )->update( $this->campaign_id, [ 'status' => Status::PAUSED ] );
        $this->create_campaign( CampaignTrigger::PURCHASE, [ 'daily_cap' => 150 ] );
        $user_id = $this->create_customer();
        ( new LedgerRepository() )->insert( new LedgerEntry( null, $user_id, 500, 0, LedgerType::EARN, LedgerSource::REVIEW, null, null, null, null, null, null, null, Dates::now() ) );

        $this->transition( $this->create_order( $user_id, [ 100 ] ), 'completed' );
        $this->transition( $this->create_order( $user_id, [ 100 ] ), 'completed' );

        $orders = array_values( array_filter( $this->ledger_rows( $user_id ), static fn( array $row ): bool => LedgerSource::ORDER === $row['source'] ) );

        $this->assertSame( [ 100, 50 ], array_map( static fn( array $row ): int => (int) $row['points'], $orders ) );
    }
}
