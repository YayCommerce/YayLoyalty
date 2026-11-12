<?php
namespace YayLoyalty\Tests\Integration\Engine\Earning;

use YayLoyalty\Engine\Earning\Listeners\OrderReversalListener;
use YayLoyalty\Engine\Earning\PendingPoints;
use YayLoyalty\Engine\Earning\Reversal as EarnReversal;
use YayLoyalty\Engine\Redemption\Reversal as RedemptionReversal;
use YayLoyalty\Enums\LedgerType;
use YayLoyalty\Models\Redemption;
use YayLoyalty\Repositories\CampaignRepository;
use YayLoyalty\Repositories\CustomerRepository;
use YayLoyalty\Repositories\LedgerRepository;
use YayLoyalty\Repositories\RedemptionRepository;
use YayLoyalty\Repositories\RewardRepository;
use YayLoyalty\Tests\Integration\IntegrationTestCase;

/** OrderReversalListener on an order that both earned and redeemed: the restore lands before the clawback, and the two commit or roll back together. */
final class OrderReversalListenerTest extends IntegrationTestCase {

    /** An order that earned 1000 points for user 7 and then spent 500 of them on itself. */
    private function order_that_earned_and_redeemed(): \WC_Order {
        $reward = $this->seeded_reward();
        $order  = $this->order_with_points_discount( 7, 20.0, 5.0, $reward->get_coupon_code() );
        $order->set_total( 15.0 );
        $order->save();

        $this->ledger_service()->earn( $this->earn_draft( 7, $order->get_id(), 1000 ) );
        $this->commit_writer()->commit( $order, $reward );

        return $order;
    }

    public function test_a_cancel_writes_the_restore_before_the_clawback(): void {
        $order = $this->order_that_earned_and_redeemed();

        $order->update_status( 'cancelled' );

        $this->assertSame( [ 1000, -500, 500, -1000 ], $this->ledger_points( 7 ) );
        $this->assertSame(
            [ LedgerType::EARN, LedgerType::REDEEM, LedgerType::REFUND_REDEEM, LedgerType::REFUND_EARN ],
            array_column( $this->ledger_rows( 7 ), 'type' )
        );
        $this->assertSame( 0, (int) $this->customer_row( 7 )['balance'] );
        $this->assertSame( Redemption::STATUS_CANCELLED, $this->redemption_rows( 7 )[0]['status'] );
    }

    public function test_a_failing_clawback_rolls_the_restore_back(): void {
        $order     = $this->order_that_earned_and_redeemed();
        $customers = new CustomerRepository();
        $failing   = new class() extends LedgerRepository {
            public function earn_rows_for_order( int $order_id ): array {
                throw new \RuntimeException( 'clawback failed' );
            }
        };
        $listener  = new OrderReversalListener(
            new PendingPoints( $customers ),
            new EarnReversal( $this->ledger_service(), $failing, new CampaignRepository() ),
            new RedemptionReversal( $this->ledger_service(), new RedemptionRepository(), $customers, new RewardRepository(), new LedgerRepository() )
        );

        try {
            $listener->on_cancelled( $order );
            $this->fail( 'The clawback should have thrown.' );
        } catch ( \RuntimeException $e ) {
            $this->assertSame( 'clawback failed', $e->getMessage() );
        }

        $this->assertSame( [ 1000, -500 ], $this->ledger_points( 7 ) );
        $this->assertSame( 500, (int) $this->customer_row( 7 )['balance'] );
        $this->assertSame( Redemption::STATUS_APPLIED, $this->redemption_rows( 7 )[0]['status'] );
        $this->assertSame( 0, (int) $this->redemption_rows( 7 )[0]['restored_points'] );
    }
}
