<?php
namespace YayLoyalty\Pro\Services;

use YayLoyalty\Engine\Expiry\ExpiryRunner;
use YayLoyalty\Enums\TierMode;
use YayLoyalty\Repositories\CustomerRepository;
use YayLoyalty\Utils\Dates;
use YayLoyalty\Utils\Log;

defined( 'ABSPATH' ) || exit;

/**
 * The daily Action Scheduler job for tiers. Each run, while tiers are on: ends the manual floors that are
 * due, then in Rolling mode ends the holds that are due, then evaluates the next batch of the pending
 * sweep (TierSweep). It queues an async continuation while any step filled its batch, as BirthdayRunner
 * does. A floor that ended but has not been cleared yet already counts for nothing, since TierEvaluator
 * ignores an ended floor; an ended hold that has not been processed already counts as inactive.
 */
final class TierRunner {

    public const RUN_ACTION = 'yayloyalty_tier_run';

    public const BATCH_SIZE = 100;

    private const SOURCE = 'TierRunner';

    public function register(): void {
        ExpiryRunner::register_daily( self::RUN_ACTION, [ $this, 'schedule' ], [ $this, 'run' ] );
    }

    /** Keeps the daily action scheduled. Hooked on init. */
    public function schedule(): void {
        ExpiryRunner::keep_daily( self::RUN_ACTION );
    }

    /** Queues a run now: a sweep was requested. */
    public static function queue_now(): void {
        if ( function_exists( 'as_enqueue_async_action' ) ) {
            as_enqueue_async_action( self::RUN_ACTION, [], ExpiryRunner::GROUP );
        }
    }

    public function run(): void {
        if ( ! TierEvaluator::is_enabled() ) {
            return;
        }

        $customers = new CustomerRepository();
        $evaluator = TierEvaluator::build();

        $ended    = $customers->floor_ended_user_ids( Dates::now(), self::BATCH_SIZE );
        $has_more = count( $ended ) === self::BATCH_SIZE;

        foreach ( $ended as $user_id ) {
            $this->guarded(
                $user_id,
                static function () use ( $evaluator, $user_id ): void {
                    $evaluator->clear_floor( $user_id, null );
                }
            );
        }

        if ( TierMode::ROLLING === TierEvaluator::mode() ) {
            $held = $customers->hold_ended_user_ids( Dates::now(), self::BATCH_SIZE );

            if ( count( $held ) === self::BATCH_SIZE ) {
                $has_more = true;
            }

            foreach ( $held as $user_id ) {
                $this->guarded(
                    $user_id,
                    static function () use ( $evaluator, $user_id ): void {
                        $evaluator->end_hold( $user_id );
                    }
                );
            }
        }

        $sweep = TierSweep::current();

        if ( null !== $sweep ) {
            $batch = $customers->user_ids_after( $sweep['after_user_id'], self::BATCH_SIZE );

            foreach ( $batch as $user_id ) {
                $this->guarded(
                    $user_id,
                    static function () use ( $evaluator, $user_id, $sweep ): void {
                        $evaluator->sweep_one( $user_id, $sweep['mode'] );
                    }
                );
            }

            if ( count( $batch ) < self::BATCH_SIZE ) {
                TierSweep::finish( $sweep );
            } else {
                TierSweep::advance( $sweep, (int) end( $batch ) );
                $has_more = true;
            }
        }

        if ( $has_more ) {
            self::queue_now();
        }
    }

    /** One customer's evaluation; a failure is logged and the batch goes on. */
    private function guarded( int $user_id, callable $evaluate ): void {
        try {
            $evaluate();
        } catch ( \Throwable $e ) {
            Log::error( self::SOURCE, sprintf( 'tier evaluation for user %d failed: %s', $user_id, $e->getMessage() ) );
        }
    }
}
