<?php
namespace YayLoyalty\Engine\Redemption;

use YayLoyalty\Database\Transaction;
use YayLoyalty\Engine\Earning\ClawbackMath;
use YayLoyalty\Engine\Earning\Outcome;
use YayLoyalty\Engine\Earning\Reversal as EarnReversal;
use YayLoyalty\Enums\LedgerType;
use YayLoyalty\Enums\ReferenceType;
use YayLoyalty\Models\LedgerEntry;
use YayLoyalty\Models\Redemption;
use YayLoyalty\Models\Reward;
use YayLoyalty\Repositories\CustomerRepository;
use YayLoyalty\Repositories\RedemptionRepository;
use YayLoyalty\Repositories\RewardRepository;
use YayLoyalty\Services\LedgerService;
use YayLoyalty\Utils\WooOrders;

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
 * An admin removing a line with Restore on the order screen, or a checkout that
 * reuses the order without that line, returns what is left of that one row under
 * `[ redemption, id ]` and makes it `removed`, a status no other event lists, so
 * nothing moves it again.
 * Never writes an ADJUST row: LedgerRepository::sum_refunded_for() reads
 * one that references a refund as an earn give-back.
 */
final class Reversal {

    /** Outcome::skipped reason: the row is already where this event would put it, or the event does not act on its status. */
    public const REASON_NOTHING_TO_MOVE = 'nothing_to_move';

    /** Outcome::skipped reason: every key this event may write under is already in the ledger. */
    public const REASON_KEY_SPENT = 'key_spent';

    /** Order meta: the codes whose line an admin removed with "Don't restore", so their rows stay `applied` on purpose. */
    public const META_KEPT_CODES = '_yayloyalty_kept_codes';

    private LedgerService $ledger;

    private RedemptionRepository $redemptions;

    private CustomerRepository $customers;

    /** Read-only use: the reward's `refund_policy` as it is when the event runs, and its type for the key. */
    private RewardRepository $rewards;

    /** @var array<int, Reward|null> Rewards read by id this event; null for an id with no row. */
    private array $reward_cache = [];

    public function __construct( LedgerService $ledger, RedemptionRepository $redemptions, CustomerRepository $customers, RewardRepository $rewards ) {
        $this->ledger      = $ledger;
        $this->redemptions = $redemptions;
        $this->customers   = $customers;
        $this->rewards     = $rewards;
    }

    /**
     * Cancel or trash: restores whatever is not restored yet; the row becomes `cancelled`.
     *
     * @return Outcome[] One per redemption row of the order.
     */
    public function cancelled( \WC_Order $order ): array {
        $order_id = (int) $order->get_id();

        return $this->settle_each(
            $order_id,
            [ Redemption::STATUS_APPLIED ],
            function ( Redemption $row ) use ( $order_id ): Outcome {
                return $this->restore( $row, $row->get_points(), Redemption::STATUS_CANCELLED, self::order_then_redemption( $row, $order_id ) );
            }
        );
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
     * An admin removed the coupon line $code with Restore: returns whatever of its row is not restored yet; the row becomes
     * `removed`. Acts only on an `applied` row, under the same locks as settle_each().
     *
     * @return Outcome|null Null when the order has no row for $code.
     */
    public function coupon_removed( \WC_Order $order, string $code ): ?Outcome {
        $stale = $this->redemptions->find_by_order_and_coupon_code( (int) $order->get_id(), $code );

        if ( null === $stale ) {
            return null;
        }

        return Transaction::run(
            function () use ( $stale ): Outcome {
                $this->customers->ensure_and_lock( $stale->get_user_id() );

                $row = $this->redemptions->lock( (int) $stale->get_id() );

                if ( null === $row || Redemption::STATUS_APPLIED !== $row->get_status() ) {
                    return Outcome::skipped( self::REASON_NOTHING_TO_MOVE );
                }

                if ( $row->get_restored_points() >= $row->get_points() ) {
                    $this->redemptions->record_restore( (int) $row->get_id(), $row->get_points(), Redemption::STATUS_REMOVED );

                    return Outcome::skipped( self::REASON_NOTHING_TO_MOVE );
                }

                return $this->restore( $row, $row->get_points(), Redemption::STATUS_REMOVED, [ [ ReferenceType::REDEMPTION, (int) $row->get_id() ] ], 'Coupon removed from order' );
            }
        );
    }

    /**
     * Checkout reused the order, which now carries only $codes: returns what is left of each `applied` row whose line
     * is gone; the row becomes `removed`. A row with no stored code is left alone, as no line can be matched to it,
     * and so is one an admin chose to keep debited.
     *
     * @param string[] $codes The loyalty codes on the order.
     * @return Outcome[] One per redemption row of the order.
     */
    public function lines_gone( \WC_Order $order, array $codes ): array {
        $on_order = array_merge( array_map( 'wc_format_coupon_code', $codes ), self::kept_codes( $order ) );

        return $this->settle_each(
            (int) $order->get_id(),
            [ Redemption::STATUS_APPLIED ],
            function ( Redemption $row ) use ( $on_order ): Outcome {
                $code = $row->get_coupon_code();

                if ( null === $code || in_array( wc_format_coupon_code( $code ), $on_order, true ) ) {
                    return Outcome::skipped( self::REASON_NOTHING_TO_MOVE );
                }

                return $this->restore( $row, $row->get_points(), Redemption::STATUS_REMOVED, [ [ ReferenceType::REDEMPTION, (int) $row->get_id() ] ], 'Coupon left the order at checkout' );
            }
        );
    }

    /** An admin removed the line $code with "Don't restore": remembers it, so nothing reads the row as a line that went missing. Moves no points. */
    public static function keep_debited( \WC_Order $order, string $code ): void {
        $order->update_meta_data( self::META_KEPT_CODES, array_values( array_unique( array_merge( self::kept_codes( $order ), [ wc_format_coupon_code( $code ) ] ) ) ) );
        WooOrders::save_meta_only( $order );
    }

    /**
     * The codes keep_debited() remembered on the order.
     *
     * @return string[]
     */
    public static function kept_codes( \WC_Order $order ): array {
        $codes = $order->get_meta( self::META_KEPT_CODES );

        return is_array( $codes ) ? array_map( 'strval', $codes ) : [];
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
