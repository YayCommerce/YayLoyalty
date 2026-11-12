<?php
namespace YayLoyalty\Engine\Redemption;

use YayLoyalty\Database\Transaction;
use YayLoyalty\Engine\Earning\ClawbackMath;
use YayLoyalty\Engine\Earning\Outcome;
use YayLoyalty\Engine\Earning\Reversal as EarnReversal;
use YayLoyalty\Engine\Rewards\Result as RedeemResult;
use YayLoyalty\Enums\LedgerType;
use YayLoyalty\Enums\ReferenceType;
use YayLoyalty\Models\LedgerEntry;
use YayLoyalty\Models\Redemption;
use YayLoyalty\Models\Reward;
use YayLoyalty\Repositories\CustomerRepository;
use YayLoyalty\Repositories\LedgerRepository;
use YayLoyalty\Repositories\RedemptionRepository;
use YayLoyalty\Repositories\RewardRepository;
use YayLoyalty\Services\LedgerService;
use YayLoyalty\Utils\Log;

defined( 'ABSPATH' ) || exit;

/**
 * Returns redeemed points when an order is cancelled or refunded, and takes
 * them again when that is undone. Each event settles the redemption row to
 * what should be restored now and writes the difference from its
 * `restored_points` under the customer lock, so a repeated event finds
 * nothing to move and part refunds add up without summing the ledger.
 * Cancel restores twice and un-cancel debits once, because the key has no
 * cycle part: a second un-cancel answers REASON_KEY_SPENT. Catalog reward
 * rows follow the same rules as the flexible discount, keyed per reward.
 * A loyalty line leaving the order by any route returns what is left of its
 * row under `[ redemption, id ]`, unless the admin waived it, and makes it
 * `removed`, a status no other event lists, so nothing moves it again.
 * A failed payment returns what is left of each `applied` row and makes it `unpaid`; paying again debits
 * it back and makes it `applied`. That cycle repeats without limit: each move is keyed on the ledger row
 * it undoes (`[ ledger, id ]`), so every cycle has fresh keys while a re-fired event composes the same one.
 * Never writes an ADJUST row: LedgerRepository::sum_refunded_for() reads
 * one that references a refund as an earn give-back.
 */
final class Reversal {

    /** Outcome::skipped reason: the row is already where this event would put it, or the event does not act on its status. */
    public const REASON_NOTHING_TO_MOVE = 'nothing_to_move';

    /** Outcome::skipped reason: every key this event may write under is already in the ledger. */
    public const REASON_KEY_SPENT = 'key_spent';

    private LedgerService $ledger;

    private RedemptionRepository $redemptions;

    private CustomerRepository $customers;

    /** Read-only use: the reward's `refund_policy` as it is when the event runs, and its type for the key. */
    private RewardRepository $rewards;

    /** Read-only: the ledger row a failed-payment restore or a pay-again debit undoes, for its key. */
    private LedgerRepository $ledger_rows;

    /** @var array<int, Reward|null> Rewards read by id this event; null for an id with no row. */
    private array $reward_cache = [];

    public function __construct( LedgerService $ledger, RedemptionRepository $redemptions, CustomerRepository $customers, RewardRepository $rewards, LedgerRepository $ledger_rows ) {
        $this->ledger      = $ledger;
        $this->redemptions = $redemptions;
        $this->customers   = $customers;
        $this->rewards     = $rewards;
        $this->ledger_rows = $ledger_rows;
    }

    /**
     * Cancel or trash: restores whatever is not restored yet; the row becomes `cancelled`. An `unpaid` row has
     * nothing left to restore, so it only becomes `cancelled`.
     *
     * @return Outcome[] One per redemption row of the order.
     */
    public function cancelled( \WC_Order $order ): array {
        $order_id = (int) $order->get_id();

        return $this->settle_each(
            $order_id,
            Redemption::ON_ORDER_STATUSES,
            function ( Redemption $row ) use ( $order_id ): Outcome {
                if ( Redemption::STATUS_UNPAID === $row->get_status() ) {
                    return $this->relabel( $row, $row->get_restored_points(), Redemption::STATUS_CANCELLED );
                }

                return $this->restore( $row, $row->get_points(), Redemption::STATUS_CANCELLED, self::order_then_redemption( $row, $order_id ) );
            }
        );
    }

    /**
     * The order's payment failed: restores whatever is not restored yet, keyed on the row's latest `redeem` ledger row;
     * the row becomes `unpaid`. A row with nothing left to restore becomes `unpaid` with no ledger row.
     *
     * @return Outcome[] One per redemption row of the order.
     */
    public function payment_failed( \WC_Order $order ): array {
        return $this->settle_each(
            (int) $order->get_id(),
            [ Redemption::STATUS_APPLIED ],
            function ( Redemption $row ): Outcome {
                $debit = $this->ledger_rows->latest_for_redemption( (int) $row->get_id(), LedgerType::REDEEM );

                if ( null === $debit ) {
                    Log::warning( 'Reversal', sprintf( 'redemption #%d has no redeem ledger row; its failed payment restores nothing', (int) $row->get_id() ) );

                    return Outcome::skipped( self::REASON_NOTHING_TO_MOVE );
                }

                if ( $row->get_restored_points() >= $row->get_points() ) {
                    return $this->relabel( $row, $row->get_points(), Redemption::STATUS_UNPAID );
                }

                return $this->restore( $row, $row->get_points(), Redemption::STATUS_UNPAID, [ [ ReferenceType::LEDGER, (int) $debit->get_id() ] ], 'Payment failed' );
            }
        );
    }

    /**
     * The order is being paid again: debits each `unpaid` row's restored points beyond what its refunds justify, keyed on the
     * row's latest `refund_redeem` ledger row; the row becomes `applied`. With $allow_negative false it is all or nothing:
     * when the balance cannot cover every row, nothing is written and every outcome is skipped
     * Rewards\Result::REASON_INSUFFICIENT_BALANCE.
     *
     * @param bool $allow_negative True when the payment already arrived (a late gateway webhook), so the debit must stand.
     * @return Outcome[] One per redemption row of the order.
     */
    public function payment_retried( \WC_Order $order, bool $allow_negative ): array {
        $order_id = (int) $order->get_id();
        $settle   = function () use ( $order, $order_id, $allow_negative ): array {
            return $this->settle_each(
                $order_id,
                [ Redemption::STATUS_UNPAID ],
                function ( Redemption $row ) use ( $order, $allow_negative ): Outcome {
                    $restore = $this->ledger_rows->latest_for_redemption( (int) $row->get_id(), LedgerType::REFUND_REDEEM );

                    if ( null === $restore ) {
                        return Outcome::skipped( self::REASON_NOTHING_TO_MOVE );
                    }

                    return $this->debit( $row, $this->refund_target( $order, $row ), ReferenceType::LEDGER, (int) $restore->get_id(), $allow_negative, 'Paid again' );
                }
            );
        };

        if ( $allow_negative ) {
            return $settle();
        }

        try {
            return Transaction::run(
                static function () use ( $settle ): array {
                    $outcomes = $settle();

                    foreach ( $outcomes as $outcome ) {
                        if ( RedeemResult::REASON_INSUFFICIENT_BALANCE === $outcome->get_reason() ) {
                            // Rolls back the rows that were covered, so the order is debited whole or not at all.
                            throw new \DomainException( 'insufficient_balance' );
                        }
                    }

                    return $outcomes;
                }
            );
        } catch ( \DomainException $e ) {
            return array_map(
                static fn(): Outcome => Outcome::skipped( RedeemResult::REASON_INSUFFICIENT_BALANCE ),
                $this->redemptions->for_order( $order_id )
            );
        }
    }

    /** Whether any of the order's rows is `unpaid`: points the order spent are back with the customer until it is paid. */
    public function has_unpaid( \WC_Order $order ): bool {
        foreach ( $this->redemptions->for_order( (int) $order->get_id() ) as $row ) {
            if ( Redemption::STATUS_UNPAID === $row->get_status() ) {
                return true;
            }
        }

        return false;
    }

    /**
     * A refund was created: restores up to what the order's refunds justify so far under the reward's policy, keyed on this refund.
     *
     * @return Outcome[] One per redemption row of the order.
     */
    public function refunded( \WC_Order $order, int $refund_id ): array {
        return $this->settle_each(
            (int) $order->get_id(),
            [ Redemption::STATUS_APPLIED ],
            function ( Redemption $row ) use ( $order, $refund_id ): Outcome {
                $target = $this->refund_target( $order, $row );

                return $this->restore( $row, $target, self::status_at( $row, $target ), [ [ ReferenceType::REFUND, $refund_id ] ] );
            }
        );
    }

    /**
     * The `refunded` status. Acts only when no refund object exists: then the order was marked refunded
     * outside WooCommerce's refunds, and the policy decides between everything and nothing. Otherwise the refunds drive it.
     *
     * @return Outcome[] One per redemption row of the order; none when refund objects exist.
     */
    public function refunded_status( \WC_Order $order ): array {
        $order_id = (int) $order->get_id();

        if ( [] !== EarnReversal::refunds( $order_id, 1 ) ) {
            return [];
        }

        return $this->settle_each(
            $order_id,
            [ Redemption::STATUS_APPLIED ],
            function ( Redemption $row ) use ( $order_id ): Outcome {
                $target = Reward::REFUND_POLICY_NONE === $this->policy_of( $row ) ? 0 : $row->get_points();

                return $this->restore( $row, $target, self::status_at( $row, $target ), self::order_then_redemption( $row, $order_id ) );
            }
        );
    }

    /**
     * A refund was deleted: debits the difference between what is restored and what the remaining refunds
     * still justify. The balance may go negative. Nothing once the row is `cancelled`.
     *
     * @return Outcome[] One per redemption row of the order; none when the order is gone.
     */
    public function refund_deleted( int $refund_id, int $order_id ): array {
        $order = wc_get_order( $order_id );

        if ( ! $order instanceof \WC_Order ) {
            return [];
        }

        return $this->settle_each(
            $order_id,
            [ Redemption::STATUS_APPLIED, Redemption::STATUS_REFUNDED ],
            function ( Redemption $row ) use ( $order, $refund_id ): Outcome {
                return $this->debit( $row, $this->refund_target( $order, $row ), ReferenceType::REFUND, $refund_id, true, sprintf( 'Refund #%d deleted', $refund_id ) );
            }
        );
    }

    /**
     * A cancelled or trashed order is live again: debits what the cancel restored beyond what the order's
     * refunds still justify, all or nothing. A short balance answers Rewards\Result::REASON_INSUFFICIENT_BALANCE
     * and leaves the row `cancelled`.
     *
     * @return Outcome[] One per redemption row of the order.
     */
    public function uncancelled( \WC_Order $order ): array {
        return $this->settle_each(
            (int) $order->get_id(),
            [ Redemption::STATUS_CANCELLED ],
            function ( Redemption $row ) use ( $order ): Outcome {
                return $this->debit( $row, $this->refund_target( $order, $row ), ReferenceType::REDEMPTION, (int) $row->get_id(), false, 'Order restored' );
            }
        );
    }

    /**
     * The coupon line $code left the order, by whatever route (RemovedLineReconciler settles each one): returns whatever of its
     * row is not restored yet; the row becomes `removed`. With $waive, the admin's "Don't restore", and for an `unpaid` row,
     * whose points are back already, it writes no ledger row. Acts only on an `applied` or `unpaid` row, under the same locks
     * as settle_each().
     *
     * @return Outcome|null Null when the order has no row for $code.
     */
    public function coupon_removed( \WC_Order $order, string $code, bool $waive = false ): ?Outcome {
        $stale = $this->redemptions->find_by_order_and_coupon_code( (int) $order->get_id(), $code );

        if ( null === $stale ) {
            return null;
        }

        return Transaction::run(
            function () use ( $stale, $waive ): Outcome {
                $this->customers->ensure_and_lock( $stale->get_user_id() );

                $row = $this->redemptions->lock( (int) $stale->get_id() );

                if ( null === $row || ! in_array( $row->get_status(), Redemption::ON_ORDER_STATUSES, true ) ) {
                    return Outcome::skipped( self::REASON_NOTHING_TO_MOVE );
                }

                if ( $waive || Redemption::STATUS_UNPAID === $row->get_status() ) {
                    return $this->relabel( $row, $row->get_restored_points(), Redemption::STATUS_REMOVED );
                }

                if ( $row->get_restored_points() >= $row->get_points() ) {
                    return $this->relabel( $row, $row->get_points(), Redemption::STATUS_REMOVED );
                }

                return $this->restore( $row, $row->get_points(), Redemption::STATUS_REMOVED, [ [ ReferenceType::REDEMPTION, (int) $row->get_id() ] ], 'Coupon removed from order' );
            }
        );
    }

    /**
     * Runs $event once per redemption row of the order, each in its own transaction holding the
     * customer lock and then the row lock. The lock is on the row's user, not the order's customer:
     * an admin may have reassigned the order. A row that is gone, or whose locked status the event does not
     * act on, answers REASON_NOTHING_TO_MOVE.
     *
     * @param string[]                      $statuses The Redemption statuses this event acts on.
     * @param callable(Redemption): Outcome $event    Receives the locked row.
     * @return Outcome[]
     */
    private function settle_each( int $order_id, array $statuses, callable $event ): array {
        $outcomes = [];

        foreach ( $this->redemptions->for_order( $order_id ) as $stale ) {
            $outcomes[] = Transaction::run(
                function () use ( $stale, $statuses, $event ): Outcome {
                    $this->customers->ensure_and_lock( $stale->get_user_id() );

                    $row = $this->redemptions->lock( (int) $stale->get_id() );

                    if ( null === $row || ! in_array( $row->get_status(), $statuses, true ) ) {
                        return Outcome::skipped( self::REASON_NOTHING_TO_MOVE );
                    }

                    return $event( $row );
                }
            );
        }

        return $outcomes;
    }

    /**
     * Raises the row's restored points to $target with one REFUND_REDEEM row, under the first key not in the ledger yet.
     *
     * @param array<int, array{0: string, 1: int}> $keys [ reference type, reference id ] pairs, in the order to try.
     * @param string|null                          $note The ledger row's note.
     */
    private function restore( Redemption $row, int $target, string $status, array $keys, ?string $note = null ): Outcome {
        $points = $target - $row->get_restored_points();

        if ( $points <= 0 ) {
            return Outcome::skipped( self::REASON_NOTHING_TO_MOVE );
        }

        foreach ( $keys as list( $reference_type, $reference_id ) ) {
            $outcome = $this->ledger->refund_redeem( $this->draft( $row, LedgerType::REFUND_REDEEM, $points, $reference_type, $reference_id, $note ) );

            if ( Outcome::AWARDED === $outcome->get_status() ) {
                $this->redemptions->record_restore( (int) $row->get_id(), $target, $status );

                return $outcome;
            }
        }

        return Outcome::skipped( self::REASON_KEY_SPENT );
    }

    /** Moves the row to $status with $restored points and no ledger row, for an event that finds nothing left to move. */
    private function relabel( Redemption $row, int $restored, string $status ): Outcome {
        $this->redemptions->record_restore( (int) $row->get_id(), $restored, $status );

        return Outcome::skipped( self::REASON_NOTHING_TO_MOVE );
    }

    /** Lowers the row's restored points to $target with one negative REDEEM row; the row is `applied` again. */
    private function debit( Redemption $row, int $target, string $reference_type, int $reference_id, bool $allow_negative, string $note ): Outcome {
        $points = $row->get_restored_points() - $target;

        if ( $points <= 0 ) {
            return Outcome::skipped( self::REASON_NOTHING_TO_MOVE );
        }

        $outcome = $this->ledger->redeem( $this->draft( $row, LedgerType::REDEEM, -$points, $reference_type, $reference_id, $note ), $allow_negative );

        if ( Outcome::DUPLICATE === $outcome->get_status() ) {
            return Outcome::skipped( self::REASON_KEY_SPENT );
        }

        if ( Outcome::AWARDED === $outcome->get_status() ) {
            $this->redemptions->record_restore( (int) $row->get_id(), $target, Redemption::STATUS_APPLIED );
        }

        return $outcome;
    }

    /**
     * How many of the row's points the order's refunds justify restoring now. 0 with no refund: an order
     * with total 0 and no refunds would otherwise read as fully refunded. `proportional` is refunded
     * amount ÷ order total, rounded down; the refund that completes the order returns the rest.
     */
    private function refund_target( \WC_Order $order, Redemption $row ): int {
        list( $refunds, $refunded_total, $is_fully_refunded ) = EarnReversal::refund_state( $order );

        if ( [] === $refunds ) {
            return 0;
        }

        switch ( $this->policy_of( $row ) ) {
            case Reward::REFUND_POLICY_NONE:
                return 0;
            case Reward::REFUND_POLICY_FULL:
                return $row->get_points();
            default:
                return ClawbackMath::owed( $row->get_points(), $refunded_total, (float) $order->get_total(), $is_fully_refunded );
        }
    }

    /** The reward's `refund_policy` as it is now; `proportional` when the reward is gone or its config lacks the key. */
    private function policy_of( Redemption $row ): string {
        $reward = $this->reward_of( $row );
        $policy = null === $reward ? null : ( $reward->get_config()['refund_policy'] ?? null );

        return in_array( $policy, [ Reward::REFUND_POLICY_FULL, Reward::REFUND_POLICY_NONE ], true ) ? $policy : Reward::REFUND_POLICY_PROPORTIONAL;
    }

    /** The row's reward, read once per reward id. */
    private function reward_of( Redemption $row ): ?Reward {
        $reward_id = $row->get_reward_id();

        if ( ! array_key_exists( $reward_id, $this->reward_cache ) ) {
            $this->reward_cache[ $reward_id ] = $this->rewards->find_reward( $reward_id );
        }

        return $this->reward_cache[ $reward_id ];
    }

    /** `refunded` only when every point is restored. */
    private static function status_at( Redemption $row, int $target ): string {
        return $target === $row->get_points() ? Redemption::STATUS_REFUNDED : Redemption::STATUS_APPLIED;
    }

    /**
     * The keys of a full restore: the order first, then the redemption for the second cancel.
     *
     * @return array<int, array{0: string, 1: int}>
     */
    private static function order_then_redemption( Redemption $row, int $order_id ): array {
        return [ [ ReferenceType::ORDER, $order_id ], [ ReferenceType::REDEMPTION, (int) $row->get_id() ] ];
    }

    /** RedemptionDraft::of() for the row's customer, order and id, keyed by its reward's qualifier. */
    private function draft( Redemption $row, string $type, int $points, string $reference_type, int $reference_id, ?string $note ): LedgerEntry {
        $qualifier = RedemptionDraft::qualifier_for( $row, $this->reward_of( $row ) );

        return RedemptionDraft::of( $row->get_user_id(), $row->get_order_id(), (int) $row->get_id(), $type, $points, $reference_type, $reference_id, $note, $qualifier );
    }
}
