<?php
namespace YayLoyalty\Tests\Integration\Repositories;

use YayLoyalty\Engine\Redemption\CommitWriter;
use YayLoyalty\Repositories\DashboardRepository;
use YayLoyalty\Tests\Integration\IntegrationTestCase;
use YayLoyalty\Tests\Integration\WooCommerceOrdersFixture;

/** The redemption rate's two counts cover the same orders, so the rate can't pass 100%. */
final class DashboardRepositoryTest extends IntegrationTestCase {

    use WooCommerceOrdersFixture;

    /** A day no site order falls on, so the counts see only this test's orders. */
    private const WINDOW_START = 978307200; // 2001-01-01 00:00:00 UTC.

    protected function setUp(): void {
        parent::setUp();

        $this->require_woocommerce();
    }

    protected function tearDown(): void {
        $this->cleanup_orders();

        parent::tearDown();
    }

    public function test_redeeming_orders_count_only_among_completed_ones(): void {
        $this->order_in_window( 'completed', true );
        $this->order_in_window( 'completed', false );
        $this->order_in_window( 'processing', true );

        $repository = new DashboardRepository();
        $to         = self::WINDOW_START + DAY_IN_SECONDS;

        $this->assertSame( 2, $repository->count_orders( self::WINDOW_START, $to ) );
        $this->assertSame( 1, $repository->orders_with_redemption( self::WINDOW_START, $to ) );
    }

    /** A guest order dated inside the window, carrying the commit's redeemed-points meta when $redeemed. */
    private function order_in_window( string $status, bool $redeemed ): void {
        $order = $this->create_order( null, [ 20.0 ], $status );
        $order->set_date_created( self::WINDOW_START + HOUR_IN_SECONDS );

        if ( $redeemed ) {
            $order->update_meta_data( CommitWriter::META_POINTS, 500 );
        }

        $order->save();
    }
}
