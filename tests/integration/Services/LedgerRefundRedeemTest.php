<?php
namespace YayLoyalty\Tests\Integration\Services;

use YayLoyalty\Engine\Earning\IdempotencyKey;
use YayLoyalty\Engine\Earning\Outcome;
use YayLoyalty\Engine\Rewards\Result;
use YayLoyalty\Enums\LedgerSource;
use YayLoyalty\Enums\LedgerType;
use YayLoyalty\Enums\ReferenceType;
use YayLoyalty\Enums\RewardType;
use YayLoyalty\Models\LedgerEntry;
use YayLoyalty\Tests\Integration\IntegrationTestCase;
use YayLoyalty\Utils\Dates;

/** LedgerService::refund_redeem(): the restore and its idempotency key, and a redeem() that is allowed to overdraw. */
final class LedgerRefundRedeemTest extends IntegrationTestCase {

    public function test_a_refund_redeem_raises_the_balance_and_lowers_lifetime_redeemed(): void {
        $this->ledger_service()->earn( $this->earn_draft( 7, 100, 1000 ) );
        $this->ledger_service()->redeem( $this->redeem_draft( 7, 200, 500 ) );

        $outcome = $this->ledger_service()->refund_redeem( $this->refund_redeem_draft( 7, 200, 200 ) );

        $this->assertSame( Outcome::AWARDED, $outcome->get_status() );
        $this->assertSame( 200, $outcome->get_entry()->get_points() );
        $this->assertSame( 700, $outcome->get_entry()->get_balance_after() );

        $customer = $this->customer_row( 7 );
        $this->assertSame( 700, (int) $customer['balance'] );
        $this->assertSame( 300, (int) $customer['lifetime_redeemed'] );
        $this->assertSame( 1000, (int) $customer['lifetime_earned'] );
    }

    public function test_the_same_refund_key_twice_writes_one_row(): void {
        $this->ledger_service()->earn( $this->earn_draft( 7, 100, 1000 ) );
        $this->ledger_service()->redeem( $this->redeem_draft( 7, 200, 500 ) );

        $this->ledger_service()->refund_redeem( $this->refund_redeem_draft( 7, 200, 200 ) );
        $second = $this->ledger_service()->refund_redeem( $this->refund_redeem_draft( 7, 200, 200 ) );

        $this->assertSame( Outcome::DUPLICATE, $second->get_status() );
        $this->assertSame( [ 1000, -500, 200 ], $this->ledger_points( 7 ) );
        $this->assertSame( 700, (int) $this->customer_row( 7 )['balance'] );
    }

    public function test_a_redeem_that_may_go_negative_overdraws_and_a_normal_one_still_refuses(): void {
        $this->ledger_service()->earn( $this->earn_draft( 7, 100, 300 ) );

        $refused = $this->ledger_service()->redeem( $this->redeem_draft( 7, 200, 500 ) );
        $this->assertSame( Outcome::SKIPPED, $refused->get_status() );
        $this->assertSame( Result::REASON_INSUFFICIENT_BALANCE, $refused->get_reason() );

        $overdrawn = $this->ledger_service()->redeem( $this->redeem_draft( 7, 200, 500 ), true );
        $this->assertSame( Outcome::AWARDED, $overdrawn->get_status() );
        $this->assertSame( -200, $overdrawn->get_entry()->get_balance_after() );
        $this->assertSame( -200, (int) $this->customer_row( 7 )['balance'] );
        $this->assertSame( 500, (int) $this->customer_row( 7 )['lifetime_redeemed'] );
    }

    /** A REFUND_REDEEM draft returning $points of the redemption on $order_id, keyed on the order. */
    private function refund_redeem_draft( int $user_id, int $order_id, int $points ): LedgerEntry {
        return new LedgerEntry(
            null,
            $user_id,
            $points,
            0,
            LedgerType::REFUND_REDEEM,
            LedgerSource::ORDER,
            ReferenceType::ORDER,
            $order_id,
            null,
            null,
            $order_id,
            null,
            null,
            Dates::now(),
            IdempotencyKey::compose( LedgerType::REFUND_REDEEM, $user_id, ReferenceType::ORDER, $order_id, RewardType::FLEXIBLE_DISCOUNT )
        );
    }
}
