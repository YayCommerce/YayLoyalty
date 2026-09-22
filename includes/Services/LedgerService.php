<?php
namespace YayLoyalty\Services;

use YayLoyalty\Database\Transaction;
use YayLoyalty\Engine\Earning\Outcome;
use YayLoyalty\Enums\LedgerSource;
use YayLoyalty\Enums\LedgerType;
use YayLoyalty\Enums\ReferenceType;
use YayLoyalty\Models\LedgerEntry;
use YayLoyalty\Repositories\CustomerRepository;
use YayLoyalty\Repositories\LedgerRepository;
use YayLoyalty\Utils\Dates;

defined( 'ABSPATH' ) || exit;

/**
 * The only writer of the ledger and the customer aggregates — the award
 * writer of the two-moment split (docs/points-engine-scout.md). Enforced by
 * convention: constructed in exactly four places, Engine\RestAPI::controllers(),
 * Database\InitYayLoyaltyCustomer::maybe_create() and (once their bodies
 * land) Engine\Earning\EarningService::build() and
 * Engine\Redemption\RedemptionService::build(). Name a fifth here before
 * adding it. Manual rows enter through adjust(); system rows through earn()
 * and redeem(), which share record() so there is one write path.
 */
final class LedgerService {

    /** @var LedgerRepository */
    private $ledger;

    /** @var CustomerRepository */
    private $customers;

    /** @var LedgerEntry[] Events collected in-transaction, dispatched after commit. */
    private $queued_events = [];

    public function __construct( LedgerRepository $ledger, CustomerRepository $customers ) {
        $this->ledger    = $ledger;
        $this->customers = $customers;
    }

    /**
     * Manual admin adjustment, positive or negative. Deliberately not
     * idempotent (NULL reference): an admin may grant the same bonus twice,
     * which is why the controller enforces a magnitude ceiling.
     *
     * @throws \InvalidArgumentException When points is zero or the type is bad.
     */
    public function adjust( int $user_id, int $points, string $note, int $actor_id ): LedgerEntry {
        if ( 0 === $points ) {
            throw new \InvalidArgumentException( 'YayLoyalty: an adjustment of zero points is meaningless.' );
        }

        $draft = new LedgerEntry(
            null,
            $user_id,
            $points,
            0, // balance_after is computed inside the lock.
            LedgerType::ADJUST,
            LedgerSource::MANUAL,
            null,
            null,
            null,
            null,
            null,
            $note,
            $actor_id > 0 ? $actor_id : null,
            Dates::now()
        );

        return $this->record( $draft );
    }

    /**
     * Commits one evaluated award: ledger row, customer aggregates, in one
     * transaction under the customer's row lock.
     *
     * Preconditions: $draft is an EARN row with a non-null idempotency_key
     * (Engine\Earning\IdempotencyKey::compose()), points > 0, created_at
     * set by the caller. Nothing slow or externally fallible may run inside.
     *
     * Postconditions: exactly one of — Outcome::awarded( persisted row ) with
     * balance_after = locked balance + points and aggregates moved; or
     * Outcome::duplicate( existing row ) when the UNIQUE index rejected the
     * insert (QueryException::is_duplicate_key()), which is a normal outcome,
     * not an error, and leaves every aggregate untouched. Any other failure
     * rethrows and Transaction::run() has rolled back. Events are queued and
     * fire after commit through dispatch_events().
     *
     * @throws \InvalidArgumentException When the draft is not an earn row or lacks a key.
     * @throws \YayLoyalty\Database\QueryException When a write other than a duplicate fails.
     */
    public function earn( LedgerEntry $draft ): Outcome { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- scaffold stub.
        // TODO(F01-4): assert EARN + key; Transaction::run: ensure_row, lock_and_get, optional find_by_idempotency_key pre-check, try insert catch is_duplicate_key → Outcome::duplicate, apply_aggregates, queue event; then dispatch_events; return Outcome::awarded.
        throw new \RuntimeException( 'Not implemented' );
    }

    /**
     * Commits one quoted redemption: the REDEEM row and the customer
     * aggregates, in one transaction under the customer's row lock. The
     * redemption commit (Engine\Redemption\CommitWriter) wraps this in its
     * own Transaction::run(), so the row here and the redemptions row commit
     * or roll back together.
     *
     * Preconditions: $draft is a REDEEM row with points < 0, a non-null
     * idempotency_key (IdempotencyKey::compose( REDEEM, user, REDEMPTION,
     * redemption id, reward type )), redemption_id and order_id set,
     * created_at set by the caller. Nothing slow or externally fallible may
     * run inside.
     *
     * Postconditions: exactly one of — Outcome::redeemed( persisted row ) with
     * balance_after = locked balance + points (≥ 0) and lifetime_redeemed
     * moved; Outcome::skipped( Rewards\Result::REASON_INSUFFICIENT_BALANCE )
     * and nothing written when the locked balance + points would go below
     * zero — a redemption hard-refuses, unlike a clawback; or
     * Outcome::duplicate( existing row ) when the UNIQUE index rejected the
     * insert. Any other failure rethrows and the transaction has rolled back.
     * Events fire after commit through dispatch_events().
     *
     * @throws \InvalidArgumentException When the draft is not a negative redeem row or lacks a key.
     * @throws \YayLoyalty\Database\QueryException When a write other than a duplicate fails.
     */
    public function redeem( LedgerEntry $draft ): Outcome { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- scaffold stub.
        // TODO(F08): assert REDEEM, points < 0, key; Transaction::run: ensure_row, lock_and_get, if balance + points < 0 → Outcome::skipped( insufficient_balance ), try insert catch is_duplicate_key → Outcome::duplicate, apply_aggregates, queue event; then dispatch_events; return Outcome::redeemed.
        throw new \RuntimeException( 'Not implemented' );
    }

    /**
     * The manual write path (no idempotency key, so no duplicate branch).
     *
     * @throws \InvalidArgumentException When the draft's vocabulary is invalid.
     * @throws \YayLoyalty\Database\QueryException When a write fails.
     */
    private function record( LedgerEntry $draft ): LedgerEntry {
        // No enums in PHP 7.4: without this an invalid type surfaces as a DB error naming a column.
        LedgerType::assert( $draft->get_type() );
        LedgerSource::assert( $draft->get_source() );
        ReferenceType::assert( $draft->get_reference_type() );

        $entry = Transaction::run(
            function () use ( $draft ) {
                $user_id = $draft->get_user_id();

                // Materialise before locking: a missing row gap-locks and two first-touch writers both insert.
                $this->customers->ensure_row( $user_id );

                $customer = $this->customers->lock_and_get( $user_id );

                $balance_after = $customer->get_balance() + $draft->get_points();

                $to_insert = $draft->with_persisted( 0, $balance_after );
                $insert_id = $this->ledger->insert( $to_insert );

                $this->apply_aggregates( $draft );

                $persisted = $to_insert->with_persisted( $insert_id, $balance_after );

                $this->queued_events[] = $persisted;

                return $persisted;
            }
        );

        $this->dispatch_events();

        return $entry;
    }

    /** Updates the cached aggregates for one ledger row. */
    private function apply_aggregates( LedgerEntry $entry ): void {
        $type   = $entry->get_type();
        $points = $entry->get_points();

        $lifetime_earned_delta   = 0;
        $lifetime_redeemed_delta = 0;

        if ( LedgerType::affects_lifetime_earned( $type ) ) {
            // A negative ADJUST neither inflates nor reduces lifetime_earned.
            if ( LedgerType::ADJUST !== $type || $points > 0 ) {
                $lifetime_earned_delta = $points;
            }
        }

        if ( LedgerType::affects_lifetime_redeemed( $type ) ) {
            // lifetime_redeemed is unsigned, so a redeem (negative points) increases it.
            $lifetime_redeemed_delta = -$points;
        }

        $activity_at = LedgerType::is_activity( $type ) ? $entry->get_created_at() : null;

        $this->customers->apply_delta(
            $entry->get_user_id(),
            $points,
            $lifetime_earned_delta,
            $lifetime_redeemed_delta,
            $activity_at,
            null // Expiry policy is a later Pro task (data-model.md `yayloyalty_lots`).
        );
    }

    /**
     * Fires collected events after commit, never inside the transaction: a
     * subscriber on another connection cannot see uncommitted rows, its
     * cache/HTTP writes cannot be rolled back, and it would hold the row lock.
     * Known gap: when nested (exec_write, EarningService::award) this runs
     * after the savepoint release, before the outer commit — gate on
     * Transaction::depth() === 0 when the award path lands.
     */
    private function dispatch_events(): void {
        $events              = $this->queued_events;
        $this->queued_events = [];

        foreach ( $events as $entry ) {
            /**
             * Fires after a ledger row is committed.
             *
             * @param LedgerEntry $entry
             */
            do_action( 'yayloyalty_ledger_recorded', $entry );
        }
    }
}
