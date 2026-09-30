<?php
namespace YayLoyalty\Tests\Integration\Earning;

use YayLoyalty\Enums\CampaignTrigger;
use YayLoyalty\Enums\LedgerSource;
use YayLoyalty\Tests\Integration\IntegrationTestCase;
use YayLoyalty\Tests\Integration\WooCommerceOrdersFixture;

/** An approved product review earns once per product per customer (one_per_product). */
final class ReviewAwardTest extends IntegrationTestCase {

    use WooCommerceOrdersFixture;

    protected function setUp(): void {
        parent::setUp();

        $this->require_woocommerce();
        $this->create_campaign( CampaignTrigger::REVIEW, [ 'require_verified' => false ] );
    }

    protected function tearDown(): void {
        $this->cleanup_orders();

        parent::tearDown();
    }

    public function test_an_approved_review_earns(): void {
        $user_id = $this->create_customer();
        $this->approve_new_review( $user_id, $this->create_product() );

        $rows = $this->ledger_rows( $user_id );

        $this->assertCount( 1, $rows );
        $this->assertSame( LedgerSource::REVIEW, $rows[0]['source'] );
        $this->assertSame( 50, (int) $rows[0]['points'] );
    }

    public function test_a_second_review_of_the_same_product_earns_nothing_but_another_product_does(): void {
        $user_id = $this->create_customer();
        $product = $this->create_product();

        $first = $this->approve_new_review( $user_id, $product );
        $this->approve_new_review( $user_id, $product );
        $other = $this->approve_new_review( $user_id, $this->create_product() );
        $paid  = array_map( 'intval', array_column( $this->ledger_rows( $user_id ), 'reference_id' ) );

        $this->assertSame( [ $first, $other ], $paid );
    }
}
