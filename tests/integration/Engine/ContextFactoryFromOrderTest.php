<?php
namespace YayLoyalty\Tests\Integration\Engine;

use YayLoyalty\Engine\Points\ContextFactory;
use YayLoyalty\Tests\Integration\IntegrationTestCase;
use YayLoyalty\Tests\Integration\WooCommerceOrdersFixture;
use YayLoyalty\Utils\Dates;

/** The award-path Context carries the order's own time, never the wall clock. */
final class ContextFactoryFromOrderTest extends IntegrationTestCase {

    use WooCommerceOrdersFixture;

    protected function setUp(): void {
        parent::setUp();

        $this->require_woocommerce();
    }

    protected function tearDown(): void {
        $this->cleanup_orders();

        parent::tearDown();
    }

    public function test_from_order_uses_the_order_creation_date_as_occurred_at(): void {
        $order = $this->create_order( $this->create_customer(), [ 100 ] );
        $order->set_date_created( Dates::to_timestamp( '2026-06-01 10:00:00' ) );

        $this->assertSame( '2026-06-01 10:00:00', ContextFactory::from_order( $order )->get_occurred_at() );
    }

    public function test_from_order_throws_when_the_order_has_no_creation_date(): void {
        $this->expectException( \InvalidArgumentException::class );

        ContextFactory::from_order( new \WC_Order() );
    }

    public function test_from_order_builds_the_same_context_at_creation_and_at_award(): void {
        $order = $this->create_order( $this->create_customer(), [ 60, 40 ] );

        $this->assertEquals( ContextFactory::from_order( $order, 5 ), ContextFactory::from_order( wc_get_order( $order->get_id() ), 5 ) );
    }
}
