<?php
namespace YayLoyalty\Tests\Integration\Earning;

use YayLoyalty\Enums\CampaignTrigger;
use YayLoyalty\Enums\LedgerType;
use YayLoyalty\Tests\Integration\IntegrationTestCase;
use YayLoyalty\Tests\Integration\WooCommerceOrdersFixture;

/** A purchase from checkout to completion: staged as pending, then awarded once. */
final class PurchaseLifecycleTest extends IntegrationTestCase {

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

    public function test_on_hold_stages_pending_then_completed_awards_and_releases(): void {
        $this->create_campaign();
        $user_id = $this->create_customer();

        $order    = $this->create_order( $user_id, [ 25 ], 'on-hold' );
        $customer = $this->customer_row( $user_id );

        $this->assertSame( 25, (int) $customer['pending_points'] );
        $this->assertSame( 0, (int) $customer['balance'] );
        $this->assertSame( [], $this->ledger_points( $user_id ) );

        $this->transition( $order, 'completed' );
        $customer = $this->customer_row( $user_id );

        $this->assertSame( 0, (int) $customer['pending_points'] );
        $this->assertSame( 25, (int) $customer['balance'] );
        $this->assertSame( 25, (int) $customer['lifetime_earned'] );
        $this->assertSame( [ 25 ], $this->ledger_points( $user_id ) );
        $this->assertSame( LedgerType::EARN, $this->ledger_rows( $user_id )[0]['type'] );
    }

    public function test_completing_twice_awards_once(): void {
        $this->create_campaign();
        $user_id = $this->create_customer();

        $order = $this->transition( $this->create_order( $user_id, [ 25 ] ), 'completed' );
        $order = $this->transition( $order, 'processing' );
        $this->transition( $order, 'completed' );

        $this->assertSame( [ 25 ], $this->ledger_points( $user_id ) );
        $this->assertSame( 25, (int) $this->customer_row( $user_id )['balance'] );
    }

    /** 30.00 before a discount, 24.50 after: the default campaign scores 24.50 and rounds down. */
    public function test_points_use_after_discount_base_and_round_down(): void {
        $this->create_campaign();
        $user_id = $this->create_customer();

        $this->transition( $this->create_order( $user_id, [ 24.5 ], 'pending', [], [ 30 ] ), 'completed' );

        $this->assertSame( [ 24 ], $this->ledger_points( $user_id ) );
    }

    public function test_excluded_product_lines_earn_nothing(): void {
        $excluded = $this->create_product();
        $counted  = $this->create_product();
        $this->create_campaign( CampaignTrigger::PURCHASE, [ 'excluded_product_ids' => [ $excluded ] ] );
        $user_id = $this->create_customer();

        $this->transition( $this->create_order( $user_id, [ 20, 10 ], 'pending', [ $excluded, $counted ] ), 'completed' );

        $this->assertSame( [ 10 ], $this->ledger_points( $user_id ) );
    }
}
