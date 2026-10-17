<?php
namespace YayLoyalty\Tests\Integration\Services;

use YayLoyalty\Engine\Earning\Outcome;
use YayLoyalty\Engine\Rewards\Result;
use YayLoyalty\Tests\Integration\IntegrationTestCase;

/** LedgerService::redeem(): the debit, its refusal of a negative balance, and its idempotency key. */
final class LedgerRedeemTest extends IntegrationTestCase {

    public function test_a_redeem_lowers_the_balance_and_raises_lifetime_redeemed(): void {
        $this->ledger_service()->earn( $this->earn_draft( 7, 100, 1000 ) );

        $outcome = $this->ledger_service()->redeem( $this->redeem_draft( 7, 200, 500 ) );

        $this->assertSame( Outcome::AWARDED, $outcome->get_status() );
        $this->assertSame( -500, $outcome->get_entry()->get_points() );
        $this->assertSame( 500, $outcome->get_entry()->get_balance_after() );

        $customer = $this->customer_row( 7 );
        $this->assertSame( 500, (int) $customer['balance'] );
        $this->assertSame( 500, (int) $customer['lifetime_redeemed'] );
        $this->assertSame( 1000, (int) $customer['lifetime_earned'] );
    }

    public function test_a_redeem_larger_than_the_balance_writes_nothing(): void {
        $this->ledger_service()->earn( $this->earn_draft( 7, 100, 300 ) );

        $outcome = $this->ledger_service()->redeem( $this->redeem_draft( 7, 200, 500 ) );

        $this->assertSame( Outcome::SKIPPED, $outcome->get_status() );
        $this->assertSame( Result::REASON_INSUFFICIENT_BALANCE, $outcome->get_reason() );
        $this->assertSame( [ 300 ], $this->ledger_points( 7 ) );
        $this->assertSame( 300, (int) $this->customer_row( 7 )['balance'] );
    }

    public function test_the_same_key_twice_writes_one_row(): void {
        $this->ledger_service()->earn( $this->earn_draft( 7, 100, 1000 ) );

        $this->ledger_service()->redeem( $this->redeem_draft( 7, 200, 500 ) );
        $second = $this->ledger_service()->redeem( $this->redeem_draft( 7, 200, 500 ) );

        $this->assertSame( Outcome::DUPLICATE, $second->get_status() );
        $this->assertSame( [ 1000, -500 ], $this->ledger_points( 7 ) );
        $this->assertSame( 500, (int) $this->customer_row( 7 )['balance'] );
    }
}
