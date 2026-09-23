<?php
namespace YayLoyalty\Tests\Unit\Redemption;

use PHPUnit\Framework\TestCase;
use YayLoyalty\Engine\Redemption\Gate;
use YayLoyalty\Engine\Redemption\Quoter;
use YayLoyalty\Engine\Rewards\Context;
use YayLoyalty\Engine\Rewards\Handlers\Registry;
use YayLoyalty\Engine\Rewards\Handlers\RewardHandler;
use YayLoyalty\Engine\Rewards\Result;
use YayLoyalty\Engine\Rewards\RewardEngine;
use YayLoyalty\Enums\RewardType;
use YayLoyalty\Models\Reward;

/** The pure half is real: gates in order, first reason wins, engine only when every gate passes. */
final class QuoterTest extends TestCase {

    /** @var string[] */
    private array $called = [];

    private function gate( string $name, ?string $reason ): Gate {
        $called = &$this->called;

        return new class( $name, $reason, $called ) implements Gate {
            private string $name;

            private ?string $reason;

            /** @var string[] */
            private array $called;

            /**
             * @param string[] $called
             */
            public function __construct( string $name, ?string $reason, array &$called ) {
                $this->name   = $name;
                $this->reason = $reason;
                $this->called = &$called;
            }

            public function check( Reward $reward, Context $ctx ): ?string {
                $this->called[] = $this->name;

                return $this->reason;
            }
        };
    }

    private function engine_answering( Result $answer ): RewardEngine {
        $registry = new Registry();
        $registry->register(
            new class( $answer ) implements RewardHandler {
                private Result $answer;

                public function __construct( Result $answer ) {
                    $this->answer = $answer;
                }

                public function type(): string {
                    return RewardType::FLEXIBLE_DISCOUNT;
                }

                public function quote( Reward $reward, Context $ctx ): Result {
                    return $this->answer;
                }
            }
        );

        return new RewardEngine( $registry );
    }

    private function reward(): Reward {
        return new Reward( 1, 'Flex', 'flex', RewardType::FLEXIBLE_DISCOUNT );
    }

    private function context(): Context {
        return new Context( 7, 1000, 100, 50.0, 'USD', '2026-06-15 12:00:00' );
    }

    public function test_every_gate_passing_returns_the_engine_quote_unchanged(): void {
        $answer = Result::allowed( $this->reward(), 100, 100, 1.0, 'USD', 100.0 );
        $quoter = new Quoter( $this->engine_answering( $answer ), [ $this->gate( 'a', null ), $this->gate( 'b', '' ) ] );

        $this->assertSame( $answer, $quoter->quote( $this->reward(), $this->context() ) );
        $this->assertSame( [ 'a', 'b' ], $this->called );
    }

    public function test_the_first_reason_wins_later_gates_and_the_engine_are_not_reached(): void {
        $answer = Result::allowed( $this->reward(), 100, 100, 1.0, 'USD', 100.0 );
        $quoter = new Quoter(
            $this->engine_answering( $answer ),
            [ $this->gate( 'a', null ), $this->gate( 'b', 'program_paused' ), $this->gate( 'c', 'not_live' ) ]
        );

        $result = $quoter->quote( $this->reward(), $this->context() );

        $this->assertFalse( $result->is_allowed() );
        $this->assertSame( 'program_paused', $result->get_reason() );
        $this->assertSame( [ 'a', 'b' ], $this->called );
    }

    public function test_no_gates_means_the_engine_answers(): void {
        $answer = Result::denied( 'below_min' );

        $this->assertSame( $answer, ( new Quoter( $this->engine_answering( $answer ), [] ) )->quote( $this->reward(), $this->context() ) );
    }
}
