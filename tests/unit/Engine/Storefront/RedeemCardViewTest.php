<?php
namespace YayLoyalty\Tests\Unit\Engine\Storefront;

use PHPUnit\Framework\TestCase;
use YayLoyalty\Engine\Rewards\Clamp;
use YayLoyalty\Engine\Rewards\Result;
use YayLoyalty\Engine\Storefront\RedeemCard;
use YayLoyalty\Enums\RewardType;
use YayLoyalty\Enums\Status;
use YayLoyalty\Models\Reward;

/** RedeemCard::view(): the card's numbers are Clamp's answers, and the state follows who is asking. */
final class RedeemCardViewTest extends TestCase {

    /** The seeded flexible discount reward in free input: 100 points per currency unit, minimum 100, step 100. */
    private function reward( array $config = [] ): Reward {
        return new Reward(
            9,
            'Cart discount',
            'cart-discount',
            RewardType::FLEXIBLE_DISCOUNT,
            Status::ACTIVE,
            0,
            null,
            array_merge( Reward::default_config( RewardType::FLEXIBLE_DISCOUNT ), [ 'redemption_mode' => Reward::REDEMPTION_MODE_FREE_INPUT ], $config ),
            [],
            null,
            null,
            true,
            null,
            null,
            '2026-06-15 12:00:00',
            '2026-06-15 12:00:00'
        );
    }

    /** The wireframe's cart: 9,850 points, a 300.00 subtotal, nothing asked yet. */
    public function test_the_form_offers_what_clamp_grants_for_the_whole_balance(): void {
        $reward  = $this->reward();
        $ceiling = Clamp::apply( $reward, PHP_INT_MAX, 9850, 300.0, 'USD', 2 );
        $quote   = Clamp::apply( $reward, 0, 9850, 300.0, 'USD', 2 );

        $view = RedeemCard::view( 9850, $quote, $ceiling, 0, 2 );

        $this->assertSame( 'flexible', $view['state'] );
        $this->assertSame( 0, $view['requested'] );
        $this->assertSame( 0, $view['points'] );
        $this->assertSame( 0.0, $view['value'] );
        $this->assertSame( 9850, $view['balance'] );
        $this->assertArrayNotHasKey( 'worth', $view );
        $this->assertSame( 100, $view['min'] );
        $this->assertSame( 100, $view['effective_step'] );
        $this->assertSame( 9800, $view['max'] );
        $this->assertSame( 98.0, $view['max_value'] );
        $this->assertSame( 100.0, $view['rate'] );
        $this->assertFalse( $view['is_disabled'] );
        $this->assertSame( '', $view['bound_by'] );
    }

    public function test_a_balance_below_the_minimum_disables_the_form(): void {
        $reward  = $this->reward();
        $ceiling = Clamp::apply( $reward, PHP_INT_MAX, 50, 300.0, 'USD', 2 );
        $quote   = Clamp::apply( $reward, 0, 50, 300.0, 'USD', 2 );

        $view = RedeemCard::view( 50, $quote, $ceiling, 0, 2 );

        $this->assertSame( 'flexible', $view['state'] );
        $this->assertSame( 0, $view['max'] );
        $this->assertTrue( $view['is_disabled'] );
    }

    public function test_the_flexible_discount_minimum_is_the_first_step_at_or_above_the_reward_minimum(): void {
        $reward  = $this->reward( [ 'min_points' => 150 ] );
        $ceiling = Clamp::apply( $reward, PHP_INT_MAX, 9850, 300.0, 'USD', 2 );
        $quote   = Clamp::apply( $reward, 0, 9850, 300.0, 'USD', 2 );

        $this->assertSame( 200, RedeemCard::view( 9850, $quote, $ceiling, 0, 2 )['min'] );
    }

    public function test_applied_points_are_the_quotes(): void {
        $reward  = $this->reward();
        $ceiling = Clamp::apply( $reward, PHP_INT_MAX, 9850, 300.0, 'USD', 2 );
        $quote   = Clamp::apply( $reward, 5000, 9850, 300.0, 'USD', 2 );

        $view = RedeemCard::view( 9850, $quote, $ceiling, 5000, 2 );

        $this->assertSame( 'flexible', $view['state'] );
        $this->assertSame( 5000, $view['requested'] );
        $this->assertSame( 5000, $view['points'] );
        $this->assertSame( 50.0, $view['value'] );
        $this->assertSame( 9800, $view['max'] );
        $this->assertArrayNotHasKey( 'remaining', $view );
        $this->assertSame( '', $view['bound_by'] );
    }

    public function test_a_lowered_request_names_the_limit_that_lowered_it(): void {
        $reward  = $this->reward();
        $ceiling = Clamp::apply( $reward, PHP_INT_MAX, 9850, 20.0, 'USD', 2 );
        $quote   = Clamp::apply( $reward, 5000, 9850, 20.0, 'USD', 2 );

        $view = RedeemCard::view( 9850, $quote, $ceiling, 5000, 2 );

        $this->assertSame( 2000, $view['points'] );
        $this->assertSame( Clamp::BOUND_CART, $view['bound_by'] );
    }

    public function test_a_guest_gets_the_sign_in_line_and_a_refused_customer_nothing(): void {
        $denied = Result::denied( Result::REASON_NOT_LIVE );

        $this->assertSame( [ 'state' => 'guest' ], RedeemCard::view( null, $denied, $denied, 0, 2 ) );
        $this->assertSame( [ 'state' => 'hidden' ], RedeemCard::view( 500, $denied, $denied, 0, 2 ) );
    }

    /** Rewards took the whole balance: the ticked row stays ticked, applies nothing and says why. */
    public function test_a_ticked_flexible_discount_the_rewards_leave_nothing_for_stays_ticked_with_the_balance_note(): void {
        $reward  = $this->reward();
        $ceiling = Clamp::apply( $reward, PHP_INT_MAX, 0, 85.0, 'USD', 2 );
        $quote   = Clamp::apply( $reward, 500, 0, 85.0, 'USD', 2 );

        $view = RedeemCard::view( 2000, $quote, $ceiling, 500, 2 );

        $this->assertSame( 'flexible', $view['state'] );
        $this->assertSame( 500, $view['requested'] );
        $this->assertSame( 0, $view['points'] );
        $this->assertSame( 0.0, $view['value'] );
        $this->assertSame( 0, $view['max'] );
        $this->assertFalse( $view['is_disabled'] );
        $this->assertSame( Clamp::BOUND_BALANCE, $view['bound_by'] );
    }
}
