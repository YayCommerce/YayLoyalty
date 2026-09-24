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
 * writer of the two-moment split (docs/archive/points-engine-scout.md). Enforced by
 * convention: constructed in exactly four places, Engine\RestAPI::controllers(),
 * Database\InitYayLoyaltyCustomer::maybe_create() and (once their bodies
 * land) Engine\Earning\EarningService::build() and
 * Engine\Redemption\RedemptionService::build(). Name a fifth here before
 * adding it. Manual rows enter through adjust() and record(); system rows
 * through earn() and redeem(), which add the idempotency-key duplicate branch.
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
     * @param bool $adjust_lifetime True moves lifetime_earned by the same
     *             signed amount as the balance, so a deduction can undo a
     *             mistaken grant's tier progress. False is a balance-only
     *             correction: the ledger row is still written, but
     *             lifetime_earned (and so tier progress) does not move.
     *
     * TODO: the balance-only choice is not stored on the ledger row, so
     * lifetime_earned can no longer be rebuilt from the ledger and the
     * dashboard's points-issued total (which counts every positive adjust)
     * drifts from it. Record it as its own ledger type and drop the flag
     * from record() and apply_aggregates(). Scheduled after the earning
     * process; task in docs/project-roadmap.md, Backbone TODOs.
     *
     * @throws \InvalidArgumentException When points is zero or the type is bad.
     */
    public function adjust( int $user_id, int $points, string $note, int $actor_id, bool $adjust_lifetime = true ): LedgerEntry {
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

        return $this->record( $draft, $adjust_lifetime );
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
    public function earn( LedgerEntry $draft ): Outcome {
        $key = $draft->get_idempotency_key();

        if ( LedgerType::EARN !== $draft->get_type() ) {
            throw new \InvalidArgumentException( 'YayLoyalty: earn() writes EARN rows only.' );
        }

        if ( null === $key || '' === $key ) {
            throw new \InvalidArgumentException( 'YayLoyalty: an earn row needs an idempotency key.' );
        }

        if ( $draft->get_points() < 1 ) {
            throw new \InvalidArgumentException( 'YayLoyalty: an earn row awards a positive number of points.' );
        }

        LedgerSource::assert( $draft->get_source() );
        ReferenceType::assert( $draft->get_reference_type() );

        $outcome = Transaction::run(
            function () use ( $draft, $key ) {
                $user_id = $draft->get_user_id();

                // Materialise before locking: a missing row gap-locks and two first-touch writers both insert.
                $this->customers->ensure_row( $user_id );

                $customer = $this->customers->lock_and_get( $user_id );

                // Under the lock a concurrent award for this user has finished, so this sees its row.
                $existing = $this->ledger->find_by_idempotency_key( $key );

                if ( null !== $existing ) {
                    return Outcome::duplicate( $existing );
                }

                $balance_after = $customer->get_balance() + $draft->get_points();

                $to_insert = $draft->with_persisted( 0, $balance_after );
                $insert_id = $this->ledger->insert_unless_duplicate( $to_insert );

                if ( null === $insert_id ) {
                    return Outcome::duplicate( $this->existing_for( $key ) );
                }

                $this->apply_aggregates( $draft );

                $persisted = $to_insert->with_persisted( $insert_id, $balance_after );

                $this->queued_events[] = $persisted;

                return Outcome::awarded( $persisted );
            }
        );

        $this->dispatch_events();

        return $outcome;
    }

    /**
     * The row the UNIQUE index rejected this write for. Separate from the
     * pre-check because here the row must exist: the ledger is append-only
     * and the insert just failed against it, so a miss means the index and
     * the table disagree and no outcome would be truthful.
     *
     * @throws \RuntimeException When the key that collided matches no row.
     */
    private function existing_for( string $key ): LedgerEntry {
        $existing = $this->ledger->find_by_idempotency_key( $key );

        if ( null === $existing ) {
            throw new \RuntimeException(
                sprintf( 'YayLoyalty: idempotency key "%s" collided but matches no ledger row.', $key )
            );
        }

        return $existing;
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
        // TODO(F08): mirror earn() — assert REDEEM, points < 0, key; Transaction::run: ensure_row, lock_and_get, if balance + points < 0 → Outcome::skipped( insufficient_balance ), pre-check, insert_unless_duplicate → Outcome::duplicate, apply_aggregates, queue event; then dispatch_events; return Outcome::redeemed.
        throw new \RuntimeException( 'Not implemented' );
    }

    /**
     * The manual write path (no idempotency key, so no duplicate branch).
     *
     * @throws \InvalidArgumentException When the draft's vocabulary is invalid.
     * @throws \YayLoyalty\Database\QueryException When a write fails.
     */
    private function record( LedgerEntry $draft, bool $adjust_lifetime = true ): LedgerEntry {
        // No enums in PHP 7.4: without this an invalid type surfaces as a DB error naming a column.
        LedgerType::assert( $draft->get_type() );
        LedgerSource::assert( $draft->get_source() );
        ReferenceType::assert( $draft->get_reference_type() );

        $entry = Transaction::run(
            function () use ( $draft, $adjust_lifetime ) {
                $user_id = $draft->get_user_id();

                // Materialise before locking: a missing row gap-locks and two first-touch writers both insert.
                $this->customers->ensure_row( $user_id );

                $customer = $this->customers->lock_and_get( $user_id );

                $balance_after = $customer->get_balance() + $draft->get_points();

                $to_insert = $draft->with_persisted( 0, $balance_after );
                $insert_id = $this->ledger->insert( $to_insert );

                $this->apply_aggregates( $draft, $adjust_lifetime );

                $persisted = $to_insert->with_persisted( $insert_id, $balance_after );

                $this->queued_events[] = $persisted;

                return $persisted;
            }
        );

        $this->dispatch_events();

        return $entry;
    }

    /**
     * Updates the cached aggregates for one ledger row.
     *
     * @param bool $adjust_lifetime The manual adjust dialog's "balance only"
     *             escape hatch: false leaves lifetime_earned untouched.
     *             Always true for earned/redeemed system rows — only
     *             LedgerService::adjust() ever passes false.
     */
    private function apply_aggregates( LedgerEntry $entry, bool $adjust_lifetime = true ): void {
        $type   = $entry->get_type();
        $points = $entry->get_points();

        $lifetime_earned_delta   = 0;
        $lifetime_redeemed_delta = 0;

        if ( $adjust_lifetime && LedgerType::affects_lifetime_earned( $type ) ) {
            $lifetime_earned_delta = $points;
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
            null // Expiry policy is a later Pro task (data-model/future-tables.md `yayloyalty_lots`).
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
