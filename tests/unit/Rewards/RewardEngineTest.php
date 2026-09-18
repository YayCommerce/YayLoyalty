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
 * quotes. Every gate and the flexible-discount math are stubs, so the Lite
 * build always denies `not_implemented` — the assertion a dev replacing the
 * stub must update.
 */
final class RewardEngineTest extends TestCase {

    private const AT = '2026-06-15 12:00:00';

    private function flexible_reward( string $type = RewardType::FLEXIBLE_DISCOUNT ): Reward {
        return new Reward( 1, 'Flex', 'flex', $type );
    }

    private function context( int $balance = 1000, ?int $points_requested = 100 ): Context {
        return new Context( 7, $balance, $points_requested, 50.0, 'USD', self::AT );
    }

    public function test_lite_build_denies_not_implemented(): void {
        $engine = new RewardEngine( Registry::with_lite_handlers() );
        $result = $engine->evaluate( $this->flexible_reward(), $this->context() );

        $this->assertFalse( $result->is_allowed() );
        $this->assertSame( Result::REASON_NOT_IMPLEMENTED, $result->get_reason() );
    }

    public function test_unregistered_type_throws(): void {
        $this->expectException( \InvalidArgumentException::class );

        ( new RewardEngine( Registry::with_lite_handlers() ) )->evaluate( $this->flexible_reward( 'nope' ), $this->context() );
    }

    public function test_returns_the_handlers_quote_unchanged(): void {
        $reward   = $this->flexible_reward();
        $expected = Result::allowed( $reward, 100, 5.0, 'USD', 20.0 );

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
