<?php
namespace YayTests\Unit\Engine\Redemption;

use Brain\Monkey\Filters;
use YayLoyalty\Engine\Redemption\RewardGate;
use YayLoyalty\Engine\Rewards\Context;
use YayLoyalty\Engine\Rewards\Result;
use YayLoyalty\Models\Reward;
use YayTests\Unit\TestCase;

/**
 * @group P-1
 */
final class RewardGateTest extends TestCase {

    public function test_failed_conditions_win_over_an_unaffordable_cost() {
        Filters\expectApplied( 'yayloyalty_reward_conditions_match' )->andReturn( false );

        $this->assertSame( Result::REASON_CONDITIONS_NOT_MET, ( new RewardGate() )->check( $this->reward( 500 ), $this->ctx( 100 ) ) );
    }

    public function test_met_conditions_still_need_the_balance() {
        Filters\expectApplied( 'yayloyalty_reward_conditions_match' )->andReturn( true );

        $this->assertSame( Result::REASON_INSUFFICIENT_BALANCE, ( new RewardGate() )->check( $this->reward( 500 ), $this->ctx( 100 ) ) );
    }

    private function ctx( int $balance ): Context {
        return new Context( 7, $balance, null, null, 'USD', '2026-11-13 00:00:00' );
    }

    private function reward( int $cost ): Reward {
        return new Reward( 1, 'Wholesale $5', 'wholesale-5', 'fixed_coupon', 'active', 0, $cost, [], [] );
    }
}
