<?php
namespace YayLoyalty\Tests\Integration\Engine\Redemption;

use YayLoyalty\Engine\Redemption\RedemptionService;
use YayLoyalty\Engine\Redemption\SessionIntent;
use YayLoyalty\Engine\Rewards\Clamp;
use YayLoyalty\Enums\RewardType;
use YayLoyalty\Models\Reward;
use YayLoyalty\Tests\Integration\IntegrationTestCase;

/** The points discount beside picked rewards: it quotes against the balance and the cart the rewards leave. */
final class StackedQuoteTest extends IntegrationTestCase {

    private SessionIntent $intent;

    private RedemptionService $service;

    /** $5 off, 500 points. */
    private Reward $a;

    /** 15% off capped at 10, 1,500 points. */
    private Reward $b;

    protected function setUp(): void {
        parent::setUp();

        // The site's own currency may have no decimals, which would round every amount below to whole units.
        add_filter( 'wc_get_price_decimals', [ $this, 'two_decimals' ] );

        // 100 points per currency unit, minimum 100, no percent cap; a step of 50 keeps the balances below whole.
        $this->seeded_reward( [ 'increment' => 50 ] );
        $this->a       = $this->pro_reward( RewardType::FIXED_COUPON, 500, [ 'amount' => 5 ] );
        $this->b       = $this->pro_reward(
            RewardType::PERCENT_COUPON,
            1500,
            [
                'percent'      => 15,
                'max_discount' => 10,
            ]
        );
        $this->intent  = new SessionIntent();
        $this->service = RedemptionService::build( $this->intent );
    }

    public function two_decimals(): int {
        return 2;
    }

    protected function tearDown(): void {
        remove_filter( 'wc_get_price_decimals', [ $this, 'two_decimals' ] );
        $this->intent->clear();
        WC()->cart->set_cart_contents( [] );

        parent::tearDown();
    }

    public function test_the_flexible_discount_alone_is_unchanged(): void {
        $this->ledger_service()->earn( $this->earn_draft( 7, 100, 9850 ) );
        $cart = $this->cart_for( 100.0 );
        $this->intent->set( 500 );

        $this->assertSame( 9850, $this->service->ceiling( $cart, 7 )->get_points() );
        $this->assertSame( 500, $this->service->quote( $cart, 7 )->get_points() );
    }

    public function test_the_flexible_discount_quotes_what_the_rewards_leave(): void {
        $this->ledger_service()->earn( $this->earn_draft( 7, 100, 9850 ) );
        $cart = $this->cart_for( 100.0 );
        $this->intent->set_rewards( [ $this->a->get_id(), $this->b->get_id() ] );
        $this->intent->set( 99999 );

        $ceiling = $this->service->ceiling( $cart, 7 );
        // 9,850 − 2,000; the cart left ($85) would allow 8,500.
        $this->assertSame( 7850, $ceiling->get_points() );
        $this->assertSame( 78.5, $ceiling->get_value() );
        $this->assertSame( Clamp::BOUND_BALANCE, $this->service->quote( $cart, 7 )->get_bound_by() );

        $this->intent->set( 500 );
        $this->assertSame( 500, $this->service->quote( $cart, 7 )->get_points() );
        $this->assertSame( 5.0, $this->service->quote( $cart, 7 )->get_value() );
    }

    public function test_the_cart_left_binds_when_the_balance_does_not(): void {
        $this->ledger_service()->earn( $this->earn_draft( 7, 100, 50000 ) );
        $cart = $this->cart_for( 100.0 );
        $this->intent->set_rewards( [ $this->a->get_id(), $this->b->get_id() ] );

        // $100 − $15.
        $this->assertSame( 8500, $this->service->ceiling( $cart, 7 )->get_points() );
    }

    public function test_checkout_passes_when_the_rewards_use_the_whole_balance(): void {
        $this->ledger_service()->earn( $this->earn_draft( 7, 100, 2000 ) );
        $cart = $this->cart_for( 100.0 );
        $this->intent->set_rewards( [ $this->a->get_id(), $this->b->get_id() ] );
        $this->intent->set( 500 );

        $this->assertSame( 0, $this->service->quote( $cart, 7 )->get_points() );
        $this->assertNull( $this->service->checkout_error( $cart, 7 ) );
    }

    public function test_checkout_stops_when_the_rewards_are_not_covered(): void {
        $this->ledger_service()->earn( $this->earn_draft( 7, 100, 1900 ) );
        $cart = $this->cart_for( 100.0 );
        $this->intent->set_rewards( [ $this->a->get_id(), $this->b->get_id() ] );
        $this->intent->set( 500 );

        $this->assertSame( RedemptionService::REASON_REWARDS_BALANCE, $this->service->checkout_error( $cart, 7 ) );
    }
}
