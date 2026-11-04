<?php
namespace YayLoyalty\Pro\Services;

use YayLoyalty\Database\Transaction;
use YayLoyalty\Repositories\CustomerRepository;
use YayLoyalty\Utils\Dates;

defined( 'ABSPATH' ) || exit;

/**
 * The pending tier sweep: every customer evaluated again, in batches by ascending user id, as TierRunner
 * drains it. One sweep at a time, kept in one option as a cursor. A new request while one runs restarts
 * the cursor, so every customer is evaluated against the final catalogue, and placement wins over
 * re-evaluation because it is the stronger pass. Each request gets a fresh token: a batch that started
 * under an older request may not move the new one's cursor or end it.
 */
final class TierSweep {

    public const OPTION = 'yayloyalty_tier_sweep';

    /** Tiers were turned on: every customer gets exactly the tier they qualify for, with no entry bonus. */
    public const MODE_PLACEMENT = 'placement';

    /** The catalogue changed: customers only move up, except those whose tier or floor tier was deleted. */
    public const MODE_REEVALUATE = 'reevaluate';

    /**
     * Asks for a sweep. Everything happens after the outermost commit, because a rolled-back write must
     * neither leave a sweep behind nor queue a run, and a rollback doesn't undo the options cache.
     *
     * @param string $mode MODE_PLACEMENT or MODE_REEVALUATE.
     */
    public static function request( string $mode ): void {
        Transaction::after_commit(
            static function () use ( $mode ): void {
                $current = self::current();

                if ( null !== $current && self::MODE_PLACEMENT === $current['mode'] ) {
                    $mode = self::MODE_PLACEMENT;
                }

                self::write(
                    [
                        'mode'          => $mode,
                        'after_user_id' => 0,
                        'total'         => ( new CustomerRepository() )->count_all(),
                        'started_at'    => Dates::now(),
                        'token'         => wp_generate_uuid4(),
                    ]
                );

                TierRunner::queue_now();
            }
        );
    }

    /** Drops the pending sweep. A run already queued then finds nothing to do. */
    public static function cancel(): void {
        delete_option( self::OPTION );
    }

    /** @return array{mode: string, after_user_id: int, total: int, started_at: string, token: string}|null Null when none is pending. */
    public static function current(): ?array {
        $stored = get_option( self::OPTION, null );

        if ( ! is_array( $stored ) || ! in_array( $stored['mode'] ?? null, [ self::MODE_PLACEMENT, self::MODE_REEVALUATE ], true ) ) {
            return null;
        }

        return [
            'mode'          => (string) $stored['mode'],
            'after_user_id' => max( 0, (int) ( $stored['after_user_id'] ?? 0 ) ),
            'total'         => max( 0, (int) ( $stored['total'] ?? 0 ) ),
            'started_at'    => (string) ( $stored['started_at'] ?? '' ),
            'token'         => (string) ( $stored['token'] ?? '' ),
        ];
    }

    /**
     * Moves the cursor past the last customer a batch evaluated, unless a newer request replaced $from.
     *
     * @param array{token: string} $from current() as the batch read it.
     */
    public static function advance( array $from, int $after_user_id ): void {
        $current = self::current();

        if ( null === $current || $current['token'] !== $from['token'] ) {
            return;
        }

        $current['after_user_id'] = $after_user_id;

        self::write( $current );
    }

    /**
     * The sweep reached the last customer, unless a newer request replaced $from.
     *
     * @param array{token: string} $from current() as the batch read it.
     */
    public static function finish( array $from ): void {
        $current = self::current();

        if ( null !== $current && $current['token'] === $from['token'] ) {
            delete_option( self::OPTION );
        }
    }

    /** @return array{mode: string, done: int, total: int}|null Null when no sweep is pending. */
    public static function progress(): ?array {
        $current = self::current();

        if ( null === $current ) {
            return null;
        }

        $done = 0 === $current['after_user_id'] ? 0 : ( new CustomerRepository() )->count_up_to( $current['after_user_id'] );

        return [
            'mode'  => $current['mode'],
            'done'  => min( $current['total'], $done ),
            'total' => $current['total'],
        ];
    }

    /** @param array{mode: string, after_user_id: int, total: int, started_at: string, token: string} $sweep */
    private static function write( array $sweep ): void {
        // Not autoloaded: read only by the tier run and the Tiers page.
        update_option( self::OPTION, $sweep, false );
    }
}
