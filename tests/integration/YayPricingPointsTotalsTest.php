<?php
namespace YayLoyalty\Tests\Integration;

use YayLoyalty\Enums\ReferenceType;
use YayLoyalty\Integrations\YayPricing\CustomerPointsTotals;
use YayLoyalty\Utils\Dates;

/** The figures YayPricing's points conditions compare: net, lifetime and from the site-timezone month start. */
final class YayPricingPointsTotalsTest extends IntegrationTestCase {

    protected function setUp(): void {
        parent::setUp();

        add_filter( 'pre_option_timezone_string', [ $this, 'pinned_timezone' ] );
    }

    protected function tearDown(): void {
        remove_filter( 'pre_option_timezone_string', [ $this, 'pinned_timezone' ] );

        parent::tearDown();
    }

    public function pinned_timezone(): string {
        return 'Asia/Ho_Chi_Minh';
    }

    public function test_month_totals_count_net_rows_from_the_site_month_start(): void {
        $user_id = 616161;
        $start   = Dates::to_utc( Dates::site_month_start( Dates::now() ) );
        $before  = Dates::offset( -1, $start );
        $ledger  = $this->ledger_service();

        $ledger->earn( $this->earn_draft( $user_id, 1, 999, $before ) );          // last month: excluded
        $ledger->redeem( $this->redeem_draft( $user_id, 2, 999, $before ) );      // last month: excluded
        $earn = $ledger->earn( $this->earn_draft( $user_id, 3, 100, $start ) );   // at the start: included
        $ledger->adjust( $user_id, 20, 'goodwill', 1 );
        $ledger->refund_earn( $earn->get_entry(), 30, ReferenceType::REFUND, 1 );
        $ledger->redeem( $this->redeem_draft( $user_id, 4, 50 ) );
        $ledger->refund_redeem( $this->refund_redeem_draft( $user_id, 4, 10 ) );

        $totals = new CustomerPointsTotals();

        $this->assertSame( 90, $totals->get( $user_id, CustomerPointsTotals::EARNED_MONTH ) );
        $this->assertSame( 40, $totals->get( $user_id, CustomerPointsTotals::SPENT_MONTH ) );
        $this->assertSame( 1089, $totals->get( $user_id, CustomerPointsTotals::EARNED ) );
        $this->assertSame( 1039, $totals->get( $user_id, CustomerPointsTotals::SPENT ) );
    }

    public function test_user_without_a_customer_row_has_zero_totals(): void {
        $totals = new CustomerPointsTotals();

        $this->assertSame( 0, $totals->get( 727272, CustomerPointsTotals::EARNED ) );
        $this->assertSame( 0, $totals->get( 727272, CustomerPointsTotals::SPENT_MONTH ) );
    }
}
