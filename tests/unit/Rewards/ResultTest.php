<?php
namespace YayLoyalty\Tests\Unit\Rewards;

use PHPUnit\Framework\TestCase;
use YayLoyalty\Engine\Rewards\Clamp;
use YayLoyalty\Engine\Rewards\Result;
use YayLoyalty\Enums\RewardType;
use YayLoyalty\Models\Reward;

/** The two shapes a quote can take and what each exposes: the request, what stood, what it buys, and which limit bit. */
final class ResultTest extends TestCase {

    public function test_allowed_exposes_the_quote(): void {
        $reward = new Reward( 1, 'Flex', 'flex', RewardType::FLEXIBLE_DISCOUNT );
        $result = Result::allowed( $reward, 150, 100, 5.0, 'USD', 20.0, Clamp::BOUND_BALANCE );

        $this->assertTrue( $result->is_allowed() );
        $this->assertSame( $reward, $result->get_reward() );
        $this->assertSame( 150, $result->get_points_requested() );
        $this->assertSame( 100, $result->get_points() );
        $this->assertSame( Clamp::BOUND_BALANCE, $result->get_bound_by() );
        $this->assertSame( 5.0, $result->get_value() );
        $this->assertSame( 'USD', $result->get_currency() );
        $this->assertSame( 20.0, $result->get_rate_snapshot() );
        $this->assertSame( '', $result->get_reason() );
    }

    public function test_denied_carries_only_the_reason(): void {
        $result = Result::denied( 'x' );

        $this->assertFalse( $result->is_allowed() );
        $this->assertSame( 'x', $result->get_reason() );
        $this->assertNull( $result->get_reward() );
        $this->assertSame( 0, $result->get_points() );
        $this->assertSame( 0.0, $result->get_value() );
        $this->assertSame( 0.0, $result->get_rate_snapshot() );
        $this->assertNull( $result->get_currency() );
        $this->assertSame( Clamp::BOUND_NONE, $result->get_bound_by() );
    }

    public function test_allowed_with_zero_points_is_a_quote_not_a_denial(): void {
        $reward = new Reward( 1, 'Flex', 'flex', RewardType::FLEXIBLE_DISCOUNT );
        $result = Result::allowed( $reward, 50, 0, 0.0, 'USD', 100.0, Clamp::BOUND_REWARD_MIN );

        $this->assertTrue( $result->is_allowed() );
        $this->assertSame( 0, $result->get_points() );
        $this->assertSame( Clamp::BOUND_REWARD_MIN, $result->get_bound_by() );
    }
}
