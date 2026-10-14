<?php
namespace YayLoyalty\Services;

use YayLoyalty\Engine\Expiry\ExpiryPolicy;
use YayLoyalty\Enums\LotStatus;
use YayLoyalty\Models\LedgerEntry;
use YayLoyalty\Repositories\LedgerRepository;
use YayLoyalty\Repositories\LotRepository;
use YayLoyalty\Utils\Dates;
use YayLoyalty\Utils\Log;

defined( 'ABSPATH' ) || exit;

/**
 * Keeps lots in step with the ledger, so that for every user SUM(open remaining) = max(0, balance).
 * Constructed only by LedgerService, and called only inside its transaction under the customer's
 * row lock: lots are a second view of the balance and must commit or roll back with it.
 */
final class LotService {

    /** @var LotRepository */
    private $lots;

    /** @var LedgerRepository */
    private $ledger;

    public function __construct( LotRepository $lots, LedgerRepository $ledger ) {
        $this->lots   = $lots;
        $this->ledger = $ledger;
    }

    /**
     * Applies one persisted ledger row. A positive row opens a lot for the part of it above zero, dated
     * in batch expiry. A negative row takes the part of it the balance covered, from $source_ledger_id's
     * lot first (a clawback reverses its own earning), then in the spend order (oldest first unless
     * batch expiry says newest).
     *
     * @param int $balance_before The locked balance before the row.
     */
    public function record( LedgerEntry $row, int $balance_before, ?int $source_ledger_id ): void {
        $points  = $row->get_points();
        $user_id = $row->get_user_id();
        $policy  = ExpiryPolicy::current();

        if ( $points > 0 ) {
            $size = min( $points, max( 0, $balance_before + $points ) );

            if ( $size > 0 ) {
                $this->lots->open( (int) $row->get_id(), $user_id, $size, $size, $row->get_created_at(), $policy->lot_expires_at( $row->get_created_at() ) );
            }

            return;
        }

        $source = [];
        $rest   = [];
        $order  = ExpiryPolicy::SPEND_NEWEST === $policy->spend_order() ? 'DESC' : 'ASC';

        foreach ( $this->lots->open_lots( $user_id, $order ) as $lot ) {
            if ( $lot['ledger_id'] === $source_ledger_id ) {
                $source[] = $lot;
            } else {
                $rest[] = $lot;
            }
        }

        $this->take( $row, min( -$points, max( 0, $balance_before ) ), array_merge( $source, $rest ), LotStatus::CONSUMED );
    }

    /** Empties every open lot for an EXPIRE row, oldest first, each becoming `expired`. */
    public function take_all( LedgerEntry $expire_row ): void {
        $this->take( $expire_row, -$expire_row->get_points(), $this->lots->open_lots( $expire_row->get_user_id(), 'ASC' ), LotStatus::EXPIRED );
    }

    /**
     * Takes an EXPIRE row's points from the given due lots, oldest first, each emptied lot becoming `expired`.
     *
     * @param array<int, array{id: int, remaining: int}> $due_lots From due_lots().
     */
    public function take_due( LedgerEntry $expire_row, array $due_lots ): void {
        $this->take( $expire_row, -$expire_row->get_points(), $due_lots, LotStatus::EXPIRED );
    }

    /**
     * A user's open lots past their expiry at $now, oldest first.
     *
     * @return array<int, array{id: int, ledger_id: int, remaining: int, expires_at: ?string}>
     */
    public function due_lots( int $user_id, string $now ): array {
        return $this->lots->due_lots( $user_id, $now );
    }

    public function earliest_open_expiry( int $user_id ): ?string {
        return $this->lots->earliest_open_expiry( $user_id );
    }

    /**
     * Opens lots for a balance that predates them, rebuilt from the user's ledger history, unless the
     * user already has lots or nothing to cover. Runs before the next row is inserted, or from the job.
     *
     * @return int Lots opened.
     */
    public function backfill( int $user_id, int $balance ): int {
        if ( $balance <= 0 || $this->lots->has_any( $user_id ) ) {
            return 0;
        }

        return $this->rebuild( $user_id, $balance );
    }

    /**
     * History short of the balance means the balance and the ledger disagree. The oldest lot then
     * absorbs the gap, so the invariant holds and the gap expires first. In batch expiry a rebuilt lot
     * never expires sooner than the grace floor: the customer never saw its date.
     *
     * @return int Lots opened.
     */
    public function rebuild( int $user_id, int $balance ): int {
        $lots      = self::from_ledger( $this->ledger->positive_rows_newest_first( $user_id, $balance ), $balance );
        $shortfall = $balance - array_sum( array_column( $lots, 'remaining' ) );
        $oldest    = count( $lots ) - 1;

        if ( $shortfall > 0 ) {
            Log::warning( 'LotService', sprintf( 'user %d: ledger history is %d short of a %d balance.', $user_id, $shortfall, $balance ) );

            if ( $oldest >= 0 ) {
                $lots[ $oldest ]['remaining'] += $shortfall;
                $lots[ $oldest ]['points']     = max( $lots[ $oldest ]['points'], $lots[ $oldest ]['remaining'] );
            }
        }

        $policy = ExpiryPolicy::current();
        $floor  = ExpiryPolicy::grace_floor( Dates::now() );

        foreach ( $lots as $lot ) {
            $expires_at = $policy->lot_expires_at( $lot['created_at'] );
            $expires_at = null === $expires_at ? null : ExpiryPolicy::floor_at( $expires_at, $floor );

            $this->lots->open( $lot['ledger_id'], $user_id, $lot['points'], $lot['remaining'], $lot['created_at'], $expires_at );
        }

        return count( $lots );
    }

    /**
     * What a balance is still made of, FIFO: positive rows newest first until they cover it, the
     * oldest covering row only in part. When the history sums to less than the balance, the lots end at the oldest row and cover less
     * than the balance; the caller reports the shortfall.
     *
     * @param array<int, array{id: int, points: int, created_at: string}> $rows_newest_first Positive ledger rows.
     * @return array<int, array{ledger_id: int, points: int, remaining: int, created_at: string}> Newest first.
     */
    private static function from_ledger( array $rows_newest_first, int $balance ): array {
        $lots  = [];
        $cover = $balance;

        foreach ( $rows_newest_first as $row ) {
            if ( $cover <= 0 ) {
                break;
            }

            $remaining = min( (int) $row['points'], $cover );
            $cover    -= $remaining;

            $lots[] = [
                'ledger_id'  => (int) $row['id'],
                'points'     => (int) $row['points'],
                'remaining'  => $remaining,
                'created_at' => (string) $row['created_at'],
            ];
        }

        return $lots;
    }

    /**
     * Takes $need points from $open in order, each lot that empties getting $empty_status. Never
     * throws on a shortfall: a historical drift must not block a refund, so it is logged instead.
     *
     * @param array<int, array{id: int, remaining: int}> $open
     */
    private function take( LedgerEntry $row, int $need, array $open, string $empty_status ): void {
        foreach ( $open as $lot ) {
            if ( $need <= 0 ) {
                return;
            }

            $slice = min( $need, $lot['remaining'] );
            $this->lots->take( $lot['id'], $slice, (int) $row->get_id(), $empty_status, $row->get_created_at() );
            $need -= $slice;
        }

        if ( $need > 0 ) {
            Log::warning( 'LotService', sprintf( 'user %d: ledger row %d left %d points with no open lot to take from.', $row->get_user_id(), (int) $row->get_id(), $need ) );
        }
    }
}
