<?php
namespace YayLoyalty\Tests\Integration\Engine\Redemption;

use YayLoyalty\Engine\Redemption\Adapters\ClassicCheckoutAdapter;
use YayLoyalty\Engine\Redemption\RedemptionService;
use YayLoyalty\Engine\Redemption\SessionIntent;
use YayLoyalty\Engine\Storefront\RedeemCard;
use YayLoyalty\Models\Redemption;
use YayLoyalty\Repositories\CustomerRepository;
use YayLoyalty\Tests\Integration\IntegrationTestCase;

/** ClassicCheckoutAdapter: the order hook debits once, and a debit that cannot happen stops the checkout and strips the discount. */
final class ClassicCheckoutAdapterTest extends IntegrationTestCase {

    protected function setUp(): void {
        parent::setUp();

        // The site's own settings (program paused, excluded roles) must not decide the test.
        add_filter( 'yayloyalty_redemption_gates', '__return_empty_array' );
    }

    protected function tearDown(): void {
        remove_filter( 'yayloyalty_redemption_gates', '__return_empty_array' );

        parent::tearDown();
    }

    private function adapter(): ClassicCheckoutAdapter {
        $intent  = new SessionIntent();
        $service = RedemptionService::build( $intent );

        return new ClassicCheckoutAdapter( $service, new RedeemCard( $service, $intent, new CustomerRepository() ) );
    }

    public function test_the_order_hook_writes_one_redemption_and_a_repeat_writes_nothing(): void {
        $code = $this->seeded_reward()->get_coupon_code();
        $this->ledger_service()->earn( $this->earn_draft( 7, 100, 1000 ) );
        $order = $this->order_with_points_discount( 7, 20.0, 5.0, $code );

        $this->adapter()->on_order_processed( $order->get_id(), [], $order );
        $this->adapter()->on_order_processed( $order->get_id(), [], $order );

        $this->assertSame( [ 1000, -500 ], $this->ledger_points( 7 ) );
        $this->assertSame( 500, (int) $this->customer_row( 7 )['balance'] );
        $this->assertCount( 1, $this->redemption_rows( 7 ) );
        $this->assertSame( Redemption::STATUS_APPLIED, $this->redemption_rows( 7 )[0]['status'] );
    }

    public function test_a_balance_that_dropped_stops_the_checkout_and_debits_nothing(): void {
        $code = $this->seeded_reward()->get_coupon_code();
        $this->ledger_service()->earn( $this->earn_draft( 7, 100, 300 ) );
        $order = $this->order_with_points_discount( 7, 20.0, 5.0, $code );

        try {
            $this->adapter()->on_order_processed( $order->get_id(), [], $order );
            $this->fail( 'The order hook should have thrown.' );
        } catch ( \Exception $e ) {
            $this->assertNotSame( '', $e->getMessage() );
        }

        $this->assertSame( [ 300 ], $this->ledger_points( 7 ) );
        $this->assertSame( [], $this->redemption_rows( 7 ) );
        $this->assertNotContains( $code, wc_get_order( $order->get_id() )->get_coupon_codes() );
    }

    public function test_an_order_without_the_points_coupon_passes_untouched(): void {
        $this->seeded_reward();
        $order = wc_create_order( [ 'customer_id' => 7 ] );

        $this->adapter()->on_order_processed( $order->get_id(), [], $order );

        $this->assertSame( [], $this->ledger_points( 7 ) );
    }
}
