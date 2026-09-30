<?php
namespace YayLoyalty\Tests\Integration\Database;

use YayLoyalty\Database\Transaction;
use YayLoyalty\Tests\Integration\IntegrationTestCase;

/** after_commit() fires once, after the outermost commit, and never for a write that was rolled back. */
final class TransactionAfterCommitTest extends IntegrationTestCase {

    /** @var string[] */
    private $fired = [];

    public function test_outside_a_transaction_the_callback_runs_now(): void {
        Transaction::after_commit( $this->record( 'now' ) );

        $this->assertSame( [ 'now' ], $this->fired );
    }

    public function test_a_nested_callback_waits_for_the_outer_commit(): void {
        Transaction::run(
            function (): void {
                Transaction::run(
                    function (): void {
                        Transaction::after_commit( $this->record( 'inner' ) );
                    }
                );

                $this->assertSame( [], $this->fired, 'Fired at the savepoint release.' );
            }
        );

        $this->assertSame( [ 'inner' ], $this->fired );
    }

    public function test_an_outer_rollback_fires_nothing(): void {
        try {
            Transaction::run(
                function (): void {
                    Transaction::run(
                        function (): void {
                            Transaction::after_commit( $this->record( 'inner' ) );
                        }
                    );

                    throw new \RuntimeException( 'undo' );
                }
            );
        } catch ( \RuntimeException $e ) {
            $this->assertSame( 'undo', $e->getMessage() );
        }

        $this->assertSame( [], $this->fired );
    }

    public function test_a_rolled_back_savepoint_drops_only_its_own_callbacks(): void {
        Transaction::run(
            function (): void {
                Transaction::after_commit( $this->record( 'outer' ) );

                try {
                    Transaction::run(
                        function (): void {
                            Transaction::after_commit( $this->record( 'undone' ) );
                            throw new \RuntimeException( 'undo inner' );
                        }
                    );
                } catch ( \RuntimeException $e ) {
                    $this->assertSame( 'undo inner', $e->getMessage() );
                }

                Transaction::run(
                    function (): void {
                        Transaction::after_commit( $this->record( 'sibling' ) );
                    }
                );
            }
        );

        $this->assertSame( [ 'outer', 'sibling' ], $this->fired );
    }

    /** A callback may open its own transaction: the queue is drained before callbacks run. */
    public function test_a_callback_can_open_a_new_transaction(): void {
        Transaction::run(
            function (): void {
                Transaction::after_commit(
                    function (): void {
                        Transaction::run(
                            function (): void {
                                Transaction::after_commit( $this->record( 'from callback' ) );
                            }
                        );
                    }
                );
            }
        );

        $this->assertSame( [ 'from callback' ], $this->fired );
        $this->assertSame( 0, Transaction::depth() );
    }

    public function test_a_throwing_callback_does_not_stop_the_others_or_reach_the_caller(): void {
        Transaction::run(
            function (): void {
                Transaction::after_commit(
                    static function (): void {
                        throw new \RuntimeException( 'subscriber failed' );
                    }
                );
                Transaction::after_commit( $this->record( 'after' ) );
            }
        );

        $this->assertSame( [ 'after' ], $this->fired );
    }

    public function test_ledger_recorded_fires_after_the_outermost_commit(): void {
        $listener = function (): void {
            $this->fired[] = 'recorded';
        };

        add_action( 'yayloyalty_ledger_recorded', $listener );

        try {
            Transaction::run(
                function (): void {
                    $this->ledger_service()->earn( $this->earn_draft( 4343, 55, 10 ) );

                    $this->assertSame( [], $this->fired, 'Fired before the outer commit.' );
                }
            );
        } finally {
            remove_action( 'yayloyalty_ledger_recorded', $listener );
        }

        $this->assertSame( [ 'recorded' ], $this->fired );
    }

    private function record( string $label ): callable {
        return function () use ( $label ): void {
            $this->fired[] = $label;
        };
    }
}
