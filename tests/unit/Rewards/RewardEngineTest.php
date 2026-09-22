<?php
namespace YayLoyalty\Tests\Unit\Rewards;

use PHPUnit\Framework\TestCase;
use YayLoyalty\Engine\Rewards\Context;
use YayLoyalty\Engine\Rewards\Handlers\Registry;
use YayLoyalty\Engine\Rewards\Handlers\RewardHandler;
use YayLoyalty\Engine\Rewards\Result;
use YayLoyalty\Engine\Rewards\RewardEngine;
use YayLoyalty\Enums\RewardType;
use YayLoyalty\Models\Reward;

/**
 * Wiring only: the engine resolves the handler by type and returns what it
 * quotes. Gates live in Engine\Redemption; the maths in Clamp. The Lite
 * handler is a stub until F08.
 */
final class RewardEngineTest extends TestCase {

    private const AT = '2026-06-15 12:00:00';

    private function flexible_reward( string $type = RewardType::FLEXIBLE_DISCOUNT ): Reward {
        return new Reward( 1, 'Flex', 'flex', $type );
    }

    private function context( int $balance = 1000, ?int $points_requested = 100 ): Context {
        return new Context( 7, $balance, $points_requested, 50.0, 'USD', self::AT );
    }

    public function test_lite_handler_quotes_through_clamp(): void {
        $this->markTestIncomplete( 'FlexibleDiscountHandler::quote() returns exactly Clamp::apply() for the Context; replace this when the stub body lands.' );
    }

    public function test_unregistered_type_throws(): void {
        $this->expectException( \InvalidArgumentException::class );

        ( new RewardEngine( Registry::with_lite_handlers() ) )->evaluate( $this->flexible_reward( 'nope' ), $this->context() );
    }

    public function test_returns_the_handlers_quote_unchanged(): void {
        $reward   = $this->flexible_reward();
        $expected = Result::allowed( $reward, 100, 100, 5.0, 'USD', 20.0 );

        $registry = new Registry();
        $registry->register(
            new class( $expected ) implements RewardHandler {
                /** @var Result */
                private $result;

                public function __construct( Result $result ) {
                    $this->result = $result;
                }

                public function type(): string {
                    return RewardType::FLEXIBLE_DISCOUNT;
                }

                public function quote( Reward $reward, Context $ctx ): Result {
                    return $this->result;
                }
            }
        );

        $this->assertSame( $expected, ( new RewardEngine( $registry ) )->evaluate( $reward, $this->context() ) );
    }
}
