<?php
namespace YayLoyalty\Services;

use YayLoyalty\Database\Transaction;
use YayLoyalty\Enums\LedgerSource;
use YayLoyalty\Enums\LedgerType;
use YayLoyalty\Enums\ReferenceType;
use YayLoyalty\Models\LedgerEntry;
use YayLoyalty\Repositories\CustomerRepository;
use YayLoyalty\Repositories\LedgerRepository;
use YayLoyalty\Utils\Dates;

defined( 'ABSPATH' ) || exit;

/**
 * The only writer of yayloyalty_ledger and the aggregates on
 * yayloyalty_customers.
 *
 * HOW THAT IS ENFORCED, HONESTLY: this is the only class constructed with both
 * repositories, and it is constructed in exactly one place. PHP has no
 * package-private, so that is a convention plus a single construction site plus
 * a review rule — NOT something the object graph or the language enforces. Do
 * not describe it as structural, and do not hand both repositories to anything
 * else.
 *
 * Every future movement type (earn, redeem, expire, refund_*) enters through
 * record(). award_for_order() is deliberately absent: it belongs with the
 * earning engine, which will decide its own boundary.
 */
final class LedgerService {

    /** @var LedgerRepository */
    private $ledger;

    /** @var CustomerRepository */
    private $customers;

    /** @var LedgerEntry[] Events collected in-transaction, dispatched after commit. */
    private $pending_events = [];

    public function __construct( LedgerRepository $ledger, CustomerRepository $customers ) {
        $this->ledger    = $ledger;
        $this->customers = $customers;
    }

    /**
     * Manual admin adjustment, positive or negative.
     *
     * DELIBERATELY NOT IDEMPOTENT. Adjust rows carry a NULL reference, and MySQL
     * treats NULLs as distinct in a unique index, so two identical calls create
     * two rows. That is intended — an admin may grant the same bonus twice on
     * purpose — and it is why the controller enforces a magnitude ceiling: with
     * no idempotency, a client that retries after a timeout double-grants.
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
     * The single write path.
     *
     * @throws \InvalidArgumentException When the draft's vocabulary is invalid.
     * @throws \YayLoyalty\Database\QueryException When a write fails.
     */
    private function record( LedgerEntry $draft ): LedgerEntry {
        // Assert at the SERVICE boundary. PHP 7.4 has no enums, so without this
        // an invalid value would only surface as a DB error naming a column
        // rather than the mistake.
        LedgerType::assert( $draft->get_type() );
        LedgerSource::assert( $draft->get_source() );
        ReferenceType::assert( $draft->get_reference_type() );

        $entry = Transaction::run(
            function () use ( $draft ) {
                $user_id = $draft->get_user_id();

                // 1. Materialise the row BEFORE locking it. See
                //    CustomerRepository::ensure_row(): locking a row that does
                //    not exist takes a gap lock, and two first-touch writers
                //    would both insert.
                $this->customers->ensure_row( $user_id );

                // 2. Lock it, then read. Everything below uses this snapshot.
                $customer = $this->customers->lock_and_get( $user_id );

                // 3. Idempotency, inside the lock. A plain SELECT, not
                //    INSERT IGNORE — IGNORE would swallow genuine constraint and
                //    type errors along with the duplicate.
                $existing = $this->ledger->find_by_reference(
                    $user_id,
                    $draft->get_source(),
                    $draft->get_reference_type(),
                    $draft->get_reference_id()
                );

                if ( null !== $existing ) {
                    return $existing;
                }

                // 4. Chain balance_after off the locked balance.
                $balance_after = $customer->get_balance() + $draft->get_points();

                $to_insert = $draft->with_persisted( 0, $balance_after );
                $insert_id = $this->ledger->insert( $to_insert );

                // 5. Aggregates, per the data model's rules (encoded once on
                //    LedgerType so they are not open-coded here).
                $this->apply_aggregates( $draft );

                $persisted = $to_insert->with_persisted( $insert_id, $balance_after );

                // 6. Collect for dispatch AFTER commit — see dispatch_events().
                $this->pending_events[] = $persisted;

                return $persisted;
            }
        );

        $this->dispatch_events();

        return $entry;
    }

    /**
     * Update the cached aggregates for one ledger row.
     */
    private function apply_aggregates( LedgerEntry $entry ): void {
        $type   = $entry->get_type();
        $points = $entry->get_points();

        $lifetime_earned_delta   = 0;
        $lifetime_redeemed_delta = 0;

        if ( LedgerType::affects_lifetime_earned( $type ) ) {
            // ADJUST counts toward lifetime_earned only when positive: a
            // negative adjustment must not inflate it, and must not reduce it
            // either, so it contributes nothing.
            if ( LedgerType::ADJUST !== $type || $points > 0 ) {
                $lifetime_earned_delta = $points;
            }
        }

        if ( LedgerType::affects_lifetime_redeemed( $type ) ) {
            // lifetime_redeemed = SUM(|redeem|) - SUM(refund_redeem), and it is
            // unsigned, so a redeem (negative points) increases it.
            $lifetime_redeemed_delta = -$points;
        }

        $activity_at = LedgerType::is_activity( $type ) ? $entry->get_created_at() : null;

        $this->customers->apply_delta(
            $entry->get_user_id(),
            $points,
            $lifetime_earned_delta,
            $lifetime_redeemed_delta,
            $activity_at,
            null // Expiry policy is not implemented yet; see the expiry job.
        );
    }

    /**
     * Fire collected events after the transaction has committed.
     *
     * NOT inside the transaction, and the original rationale for doing so was
     * backwards. A subscriber on a different connection — an object cache write,
     * an HTTP webhook, a queued job — cannot see uncommitted rows, so it would
     * observe the change as absent or stale. Cache and HTTP writes are also not
     * transactional, so a later rollback could not undo them. And firing inside
     * would hold the customer row lock for the duration of whatever the
     * subscriber does.
     *
     * The 2.0 events outbox is the durable version of this and replaces the
     * in-memory queue without changing the boundary.
     */
    private function dispatch_events(): void {
        $events               = $this->pending_events;
        $this->pending_events = [];

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
