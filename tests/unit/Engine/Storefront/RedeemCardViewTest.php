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

    /** The seeded slider reward: 100 points per currency unit, minimum 100, step 100. */
    private function reward( array $config = [] ): Reward {
        return new Reward(
            9,
            'Cart discount',
            'cart-discount',
            RewardType::FLEXIBLE_DISCOUNT,
            Status::ACTIVE,
            0,
            null,
            array_merge( Reward::default_config( RewardType::FLEXIBLE_DISCOUNT ), $config ),
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

        $view = RedeemCard::view( 9850, $quote, $ceiling );

        $this->assertSame( 'form', $view['state'] );
        $this->assertSame( 9850, $view['balance'] );
        $this->assertArrayNotHasKey( 'worth', $view );
        $this->assertSame( 100, $view['min'] );
        $this->assertSame( 100, $view['step'] );
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

        $view = RedeemCard::view( 50, $quote, $ceiling );

        $this->assertSame( 'form', $view['state'] );
        $this->assertSame( 0, $view['max'] );
        $this->assertTrue( $view['is_disabled'] );
    }

    public function test_the_slider_minimum_is_the_first_step_at_or_above_the_reward_minimum(): void {
        $reward  = $this->reward( [ 'min_points' => 150 ] );
        $ceiling = Clamp::apply( $reward, PHP_INT_MAX, 9850, 300.0, 'USD', 2 );
        $quote   = Clamp::apply( $reward, 0, 9850, 300.0, 'USD', 2 );

        $this->assertSame( 200, RedeemCard::view( 9850, $quote, $ceiling )['min'] );
    }

    public function test_applied_points_leave_the_rest_of_the_balance(): void {
        $reward  = $this->reward();
        $ceiling = Clamp::apply( $reward, PHP_INT_MAX, 9850, 300.0, 'USD', 2 );
        $quote   = Clamp::apply( $reward, 5000, 9850, 300.0, 'USD', 2 );

        $view = RedeemCard::view( 9850, $quote, $ceiling );

        $this->assertSame( 'applied', $view['state'] );
        $this->assertSame( 5000, $view['points'] );
        $this->assertSame( 50.0, $view['value'] );
        $this->assertSame( 4850, $view['remaining'] );
        $this->assertSame( '', $view['bound_by'] );
    }

    public function test_a_lowered_request_names_the_limit_that_lowered_it(): void {
        $reward  = $this->reward();
        $ceiling = Clamp::apply( $reward, PHP_INT_MAX, 9850, 20.0, 'USD', 2 );
        $quote   = Clamp::apply( $reward, 5000, 9850, 20.0, 'USD', 2 );

        $view = RedeemCard::view( 9850, $quote, $ceiling );

        $this->assertSame( 2000, $view['points'] );
        $this->assertSame( Clamp::BOUND_CART, $view['bound_by'] );
    }

    public function test_a_guest_gets_the_sign_in_line_and_a_refused_customer_nothing(): void {
        $denied = Result::denied( Result::REASON_NOT_LIVE );

        $this->assertSame( [ 'state' => 'guest' ], RedeemCard::view( null, $denied, $denied ) );
        $this->assertSame( [ 'state' => 'hidden' ], RedeemCard::view( 500, $denied, $denied ) );
    }
}
