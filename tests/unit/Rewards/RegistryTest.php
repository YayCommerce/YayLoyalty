<?php
namespace YayLoyalty\Tests\Unit\Rewards;

use PHPUnit\Framework\TestCase;
use YayLoyalty\Engine\Rewards\Context;
use YayLoyalty\Engine\Rewards\Handlers\Registry;
use YayLoyalty\Engine\Rewards\Handlers\RewardHandler;
use YayLoyalty\Engine\Rewards\Result;
use YayLoyalty\Enums\RewardType;
use YayLoyalty\Models\Reward;

/** Type → handler lookup and the seam Pro attaches through. */
final class RegistryTest extends TestCase {

    protected function tearDown(): void {
        $GLOBALS['yayloyalty_test_hooks'] = [];
    }

    private function handler( string $type ): RewardHandler {
        return new class( $type ) implements RewardHandler {
            /** @var string */
            private $type;

            public function __construct( string $type ) {
                $this->type = $type;
            }

            public function type(): string {
                return $this->type;
            }

            public function quote( Reward $reward, Context $ctx ): Result {
                return Result::denied( 'test' );
            }
        };
    }

    public function test_register_then_get_by_type(): void {
        $registry = new Registry();
        $handler  = $this->handler( 'custom' );

        $registry->register( $handler );

        $this->assertTrue( $registry->has( 'custom' ) );
        $this->assertSame( $handler, $registry->get( 'custom' ) );
    }

    public function test_get_unknown_type_throws_naming_it(): void {
        $this->expectException( \InvalidArgumentException::class );
        $this->expectExceptionMessage( 'nope' );

        ( new Registry() )->get( 'nope' );
    }

    public function test_lite_registry_owns_flexible_discount(): void {
        $this->assertTrue( Registry::with_lite_handlers()->has( RewardType::FLEXIBLE_DISCOUNT ) );
    }

    public function test_lite_registry_fires_the_pro_seam(): void {
        add_action(
            'yayloyalty_register_reward_handlers',
            function ( Registry $registry ): void {
                $registry->register( $this->handler( 'pro_type' ) );
            }
        );

        $this->assertTrue( Registry::with_lite_handlers()->has( 'pro_type' ) );
    }
}
