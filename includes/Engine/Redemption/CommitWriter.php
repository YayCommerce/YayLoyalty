<?php
namespace YayLoyalty\Engine\Redemption;

use YayLoyalty\Database\Transaction;
use YayLoyalty\Engine\Earning\Outcome;
use YayLoyalty\Engine\Rewards\Clamp;
use YayLoyalty\Engine\Rewards\ContextFactory;
use YayLoyalty\Engine\Rewards\Result;
use YayLoyalty\Enums\LedgerType;
use YayLoyalty\Enums\ReferenceType;
use YayLoyalty\Models\LedgerEntry;
use YayLoyalty\Models\Redemption;
use YayLoyalty\Models\Reward;
use YayLoyalty\Repositories\CustomerRepository;
use YayLoyalty\Repositories\RedemptionRepository;
use YayLoyalty\Services\LedgerService;
use YayLoyalty\Utils\CurrencyRate;
use YayLoyalty\Utils\Dates;

defined( 'ABSPATH' ) || exit;

/**
 * ZONE 2's one transaction. Holds every collaborator that can write; the
 * Quoter holds none of them, so the quote path cannot reach a writer.
 * Locks the customer row, reads the points from the order's own coupon
 * line and re-quotes them with the locked balance through the same Quoter
 * the cart used, then inserts the redemptions row and the REDEEM ledger
 * row (through LedgerService — the one ledger writer) together and freezes
 * the value onto the order. The double-debit guard is the ledger key, one
 * per order and reward. A failed re-check writes nothing and the caller
 * fails the checkout. commit_rewards() does the same for an order's reward
 * coupon lines, all of them or none, and commit_all() for an order carrying
 * both kinds, the rewards first. Cancel and refund are not here: they are
 * Reversal's.
 */
final class CommitWriter {

    /** Order meta the value is frozen onto; projections, never read to compute a balance. */
    public const META_POINTS = '_yayloyalty_points_redeemed';
    public const META_VALUE  = '_yayloyalty_redeem_value';

    private LedgerService $ledger;

    private RedemptionRepository $redemptions;

    private CustomerRepository $customers;

    private ContextFactory $contexts;

    private Quoter $quoter;

    public function __construct(
        LedgerService $ledger,
        RedemptionRepository $redemptions,
        CustomerRepository $customers,
        ContextFactory $contexts,
        Quoter $quoter
    ) {
        $this->ledger      = $ledger;
        $this->redemptions = $redemptions;
        $this->customers   = $customers;
        $this->contexts    = $contexts;
        $this->quoter      = $quoter;
    }

    /**
     * Preconditions: $order is saved, its totals frozen, and it carries our
     * coupon line; $reward is the flexible reward. Any number of firings.
     *
     * One Transaction::run(), exactly one of:
     * - Outcome::redeemed: the redemptions row (status `applied`), the REDEEM
     *   row and the order meta are written, for exactly the points the
     *   order's coupon line costs.
     * - Outcome::duplicate: this order was already committed; nothing written.
     * - Outcome::skipped( reason ), nothing written: the order has no
     *   customer or no discount, a gate denies, the locked balance or a
     *   limit covers fewer points than the line costs, or the re-quoted
     *   value is not the line's amount. The caller fails the checkout.
     * Nothing slow runs inside.
     *
     * @throws \YayLoyalty\Database\QueryException When a write other than a duplicate fails (rolled back).
     */
    public function commit( \WC_Order $order, Reward $reward ): Outcome {
        return Transaction::run(
            function () use ( $order, $reward ): Outcome {
                $user_id  = (int) $order->get_customer_id();
                $order_id = (int) $order->get_id();

                if ( $user_id <= 0 ) {
                    return Outcome::skipped( ProgramGate::REASON_NO_CUSTOMER );
                }

                $customer = $this->customers->ensure_and_lock( $user_id );

                $existing = $this->redemptions->find_by_order( $order_id, (int) $reward->get_id() );

                if ( null !== $existing ) {
                    // The key is already in the ledger, so this answers duplicate with the existing row and writes nothing.
                    return $this->ledger->redeem( $this->draft_from( $user_id, $order_id, $existing->get_points(), (int) $existing->get_id() ) );
                }

                $quote = $this->requote_flexible_discount( $order, $reward, $customer->get_balance() );

                if ( $quote instanceof Outcome ) {
                    return $quote;
                }

                $redemption_id = $this->redemptions->insert( $this->redemption_from( $quote, $order ) );
                $debit         = $this->ledger->redeem( $this->draft_from( $user_id, $order_id, $quote->get_points(), $redemption_id ) );

                if ( Outcome::AWARDED !== $debit->get_status() ) {
                    $this->redemptions->delete( $redemption_id );

                    return $debit;
                }

                $this->freeze( $order, $quote );

                return Outcome::redeemed( $debit->get_entry(), $quote );
            }
        );
    }

    /**
     * Preconditions: $order is saved, its totals frozen, and it carries reward
     * coupon lines and no flexible discount line; $rewards maps each of its reward codes
     * to the reward, or to null when the service found it missing or not
     * offered. Any number of firings.
     *
     * One Transaction::run() under the customer lock, exactly one of:
     * - Outcome::redeemed (the last row's debit): per code a redemptions row
     *   (status `applied`, points the full cost, value the line's discount)
     *   and its REDEEM row keyed `reward_{id}`, then the summed meta.
     * - Outcome::duplicate: every code already has its row; nothing written.
     * - Outcome::skipped( reason ), nothing written: no customer; a null
     *   reward, a denied re-quote or a line worth nothing
     *   (REASON_REWARD_UNAVAILABLE); or a summed cost above the locked
     *   balance (REASON_REWARDS_BALANCE).
     * - A debit that is not AWARDED: the whole commit is rolled back and that
     *   debit's Outcome answered.
     *
     * @param array<string, Reward|null> $rewards Keyed by coupon code.
     * @throws \YayLoyalty\Database\QueryException When a write fails (rolled back).
     */
    public function commit_rewards( \WC_Order $order, array $rewards ): Outcome {
        $failed = null;

        try {
            return Transaction::run(
                function () use ( $order, $rewards, &$failed ): Outcome {
                    $user_id  = (int) $order->get_customer_id();
                    $order_id = (int) $order->get_id();

                    if ( $user_id <= 0 ) {
                        return Outcome::skipped( ProgramGate::REASON_NO_CUSTOMER );
                    }

                    $customer = $this->customers->ensure_and_lock( $user_id );
                    $existing = $this->existing_rows( $order_id, array_keys( $rewards ) );

                    if ( [] !== $rewards && count( $existing ) === count( $rewards ) ) {
                        $last = end( $existing );

                        // Every key is already in the ledger, so this answers duplicate with the last row and writes nothing.
                        return $this->ledger->redeem( $this->reward_draft( $user_id, $order_id, $last->get_reward_id(), $last->get_points(), (int) $last->get_id() ) );
                    }

                    $lines   = [];
                    $cost    = 0;
                    $skipped = $this->requote_reward_lines( $order, $rewards, $customer->get_balance(), $lines, $cost );

                    if ( null !== $skipped ) {
                        return $skipped;
                    }

                    list( $debit, $quote, $total ) = $this->write_reward_lines( $order, $lines, $failed );

                    $order->update_meta_data( self::META_POINTS, $cost );
                    $order->update_meta_data( self::META_VALUE, $this->money( $total ) );
                    $order->save();

                    return Outcome::redeemed( $debit->get_entry(), $quote );
                }
            );
        } catch ( \RuntimeException $e ) {
            if ( null !== $failed ) {
                return $failed;
            }

            throw $e;
        }
    }

    /**
     * Preconditions: $order is saved, its totals frozen, and it carries reward
     * coupon lines and our flexible discount's line; $rewards as commit_rewards() takes
     * it; $flexible is the flexible reward. Any number of firings.
     *
     * One Transaction::run() under the customer lock, exactly one of:
     * - Outcome::redeemed (the flexible discount's debit): commit_rewards()'s rows for
     *   the reward lines, then commit()'s for the flexible discount's, quoted against
     *   the locked balance less the rewards' cost, then the summed meta.
     * - Outcome::duplicate: every line already has its row; nothing written.
     * - Outcome::skipped( reason ), nothing written: what commit_rewards()
     *   skips for, then what commit() skips the flexible discount's line for.
     * - A debit that is not AWARDED: the whole commit is rolled back and that
     *   debit's Outcome answered.
     *
     * @param array<string, Reward|null> $rewards Keyed by coupon code.
     * @throws \YayLoyalty\Database\QueryException When a write fails (rolled back).
     */
    public function commit_all( \WC_Order $order, array $rewards, Reward $flexible ): Outcome {
        $failed = null;

        try {
            return Transaction::run(
                function () use ( $order, $rewards, $flexible, &$failed ): Outcome {
                    $user_id  = (int) $order->get_customer_id();
                    $order_id = (int) $order->get_id();

                    if ( $user_id <= 0 ) {
                        return Outcome::skipped( ProgramGate::REASON_NO_CUSTOMER );
                    }

                    $customer     = $this->customers->ensure_and_lock( $user_id );
                    $flexible_row = $this->redemptions->find_by_order( $order_id, (int) $flexible->get_id() );

                    if ( null !== $flexible_row && count( $this->existing_rows( $order_id, array_keys( $rewards ) ) ) === count( $rewards ) ) {
                        // Every key is already in the ledger, so this answers duplicate with the flexible discount's row and writes nothing.
                        return $this->ledger->redeem( $this->draft_from( $user_id, $order_id, $flexible_row->get_points(), (int) $flexible_row->get_id() ) );
                    }

                    $lines   = [];
                    $cost    = 0;
                    $skipped = $this->requote_reward_lines( $order, $rewards, $customer->get_balance(), $lines, $cost );

                    if ( null !== $skipped ) {
                        return $skipped;
                    }

                    $quote = $this->requote_flexible_discount( $order, $flexible, $customer->get_balance() - $cost );

                    if ( $quote instanceof Outcome ) {
                        return $quote;
                    }

                    list( , , $total ) = $this->write_reward_lines( $order, $lines, $failed );

                    $redemption_id = $this->redemptions->insert( $this->redemption_from( $quote, $order ) );
                    $debit         = $this->ledger->redeem( $this->draft_from( $user_id, $order_id, $quote->get_points(), $redemption_id ) );

                    if ( Outcome::AWARDED !== $debit->get_status() ) {
                        $failed = $debit;

                        // Thrown so Transaction::run() rolls back the reward rows written above too.
                        throw new \RuntimeException( 'YayLoyalty: a points discount debit was not written.' );
                    }

                    $order->update_meta_data( self::META_POINTS, $cost + $quote->get_points() );
                    $order->update_meta_data( self::META_VALUE, $this->money( $total + (float) $this->decimal( $quote ) ) );
                    $order->save();

                    return Outcome::redeemed( $debit->get_entry(), $quote );
                }
            );
        } catch ( \RuntimeException $e ) {
            if ( null !== $failed ) {
                return $failed;
            }

            throw $e;
        }
    }

    /**
     * The flexible discount's line re-quoted against $balance: the quote when it buys exactly the line, otherwise why not.
     * Skipped when the line costs nothing (REASON_NO_INTENT), a gate denies, the balance or a limit covers fewer
     * points than the line costs (the bound that bit), or the value is not the line's amount (BOUND_CART).
     *
     * @return Result|Outcome
     */
    private function requote_flexible_discount( \WC_Order $order, Reward $reward, int $balance ) {
        $ctx    = $this->contexts->from_order( $order, $reward, $balance );
        $frozen = (int) $ctx->get_points_requested();

        if ( $frozen <= 0 ) {
            return Outcome::skipped( RedemptionService::REASON_NO_INTENT );
        }

        $quote = $this->quoter->quote( $reward, $ctx );

        if ( ! $quote->is_allowed() ) {
            return Outcome::skipped( $quote->get_reason(), $quote );
        }

        if ( $quote->get_points() < $frozen ) {
            return Outcome::skipped( '' !== $quote->get_bound_by() ? $quote->get_bound_by() : Result::REASON_INSUFFICIENT_BALANCE, $quote );
        }

        // The order carries a discount these points do not buy: debit nothing.
        if ( abs( $quote->get_value() - $this->contexts->points_discount_of( $order, $reward ) ) >= 0.5 / pow( 10, wc_get_price_decimals() ) ) {
            return Outcome::skipped( Clamp::BOUND_CART, $quote );
        }

        return $quote;
    }

    /**
     * Re-quotes each reward line against $balance into $lines (`[ code, reward, quote, value ]`) and sums their
     * cost into $cost. Null when every line holds; else skipped REASON_REWARD_UNAVAILABLE for a null reward, a
     * denied re-quote or a line worth nothing, or REASON_REWARDS_BALANCE when the summed cost exceeds $balance.
     *
     * @param array<string, Reward|null> $rewards
     */
    private function requote_reward_lines( \WC_Order $order, array $rewards, int $balance, array &$lines, int &$cost ): ?Outcome {
        foreach ( $rewards as $code => $reward ) {
            if ( null === $reward ) {
                return Outcome::skipped( RedemptionService::REASON_REWARD_UNAVAILABLE );
            }

            $quote = $this->quoter->quote( $reward, $this->contexts->from_order_line( $order, $reward, $balance ) );
            $value = $this->contexts->line_discount_of( $order, (string) $code );

            if ( ! $quote->is_allowed() || $value <= 0 ) {
                return Outcome::skipped( RedemptionService::REASON_REWARD_UNAVAILABLE, $quote );
            }

            $lines[] = [ (string) $code, $reward, $quote, $value ];
            $cost   += $quote->get_points();
        }

        return $cost > $balance ? Outcome::skipped( RedemptionService::REASON_REWARDS_BALANCE ) : null;
    }

    /**
     * Writes each line's redemptions row and its REDEEM row. A debit that is not AWARDED is put in $failed and a
     * RuntimeException thrown, so the caller's Transaction::run() rolls back every row written.
     *
     * @param array<int, array{0: string, 1: Reward, 2: Result, 3: float}> $lines requote_reward_lines()'s.
     * @return array{0: Outcome, 1: Result, 2: float} The last debit, the last quote, and the lines' summed value.
     */
    private function write_reward_lines( \WC_Order $order, array $lines, ?Outcome &$failed ): array {
        $user_id  = (int) $order->get_customer_id();
        $order_id = (int) $order->get_id();
        $debit    = null;
        $quote    = null;
        $total    = 0.0;

        foreach ( $lines as list( $code, $reward, $quote, $value ) ) {
            $redemption_id = $this->redemptions->insert( $this->reward_row( $order, $reward, $quote->get_points(), $value, $code ) );
            $debit         = $this->ledger->redeem( $this->reward_draft( $user_id, $order_id, (int) $reward->get_id(), $quote->get_points(), $redemption_id ) );

            if ( Outcome::AWARDED !== $debit->get_status() ) {
                $failed = $debit;

                // Thrown so Transaction::run() rolls back every row this commit wrote.
                throw new \RuntimeException( 'YayLoyalty: a reward debit was not written.' );
            }

            $total += (float) $this->money( $value );
        }

        return [ $debit, $quote, $total ];
    }

    /**
     * The order's redemption rows for these reward codes, one per code that has one.
     *
     * @param string[] $codes
     * @return Redemption[]
     */
    private function existing_rows( int $order_id, array $codes ): array {
        $by_code = [];

        // Oldest first, so the first row per code wins, as one row per code is all a commit writes.
        foreach ( $this->redemptions->for_order( $order_id ) as $row ) {
            $code = (string) $row->get_coupon_code();

            if ( ! isset( $by_code[ $code ] ) ) {
                $by_code[ $code ] = $row;
            }
        }

        $rows = [];

        foreach ( $codes as $code ) {
            if ( isset( $by_code[ (string) $code ] ) ) {
                $rows[] = $by_code[ (string) $code ];
            }
        }

        return $rows;
    }

    /** A reward line's redemptions row: the full cost, the line's discount as value, no rate. */
    private function reward_row( \WC_Order $order, Reward $reward, int $points, float $value, string $code ): Redemption {
        $value    = $this->money( $value );
        $currency = (string) $order->get_currency();
        $now      = Dates::now();

        return new Redemption(
            null,
            (int) $order->get_customer_id(),
            (int) $reward->get_id(),
            (int) $order->get_id(),
            $points,
            $value,
            $currency,
            $this->base_value( $value, $currency, $order ),
            '0',
            $code,
            Redemption::STATUS_APPLIED,
            0,
            $now,
            $now
        );
    }

    /** A reward line's REDEEM row, keyed `redeem:{user}:order:{order_id}:reward_{id}`. */
    private function reward_draft( int $user_id, int $order_id, int $reward_id, int $points, int $redemption_id ): LedgerEntry {
        return RedemptionDraft::of( $user_id, $order_id, $redemption_id, LedgerType::REDEEM, -$points, ReferenceType::ORDER, $order_id, null, RedemptionDraft::reward_qualifier( $reward_id ) );
    }

    /** An amount as the decimal string the rows and the order meta store. */
    private function money( float $amount ): string {
        return (string) wc_format_decimal( $amount, wc_get_price_decimals() );
    }

    /** $value in the base currency, at the order's saved rate when it has one, rounded to the base currency's decimals. */
    private function base_value( string $value, string $currency, \WC_Order $order ): string {
        return (string) wc_format_decimal( CurrencyRate::to_base( (float) $value, $currency, $order ), CurrencyRate::base_decimals() );
    }

    /** The redemptions row a quote becomes. Precondition: $quote is allowed with points > 0. */
    private function redemption_from( Result $quote, \WC_Order $order ): Redemption {
        $value    = $this->decimal( $quote );
        $currency = (string) $quote->get_currency();
        $now      = Dates::now();

        return new Redemption(
            null,
            (int) $order->get_customer_id(),
            (int) $quote->get_reward()->get_id(),
            (int) $order->get_id(),
            $quote->get_points(),
            $value,
            $currency,
            $this->base_value( $value, $currency, $order ),
            (string) wc_format_decimal( $quote->get_rate_snapshot(), 10 ),
            $quote->get_reward()->get_coupon_code(),
            Redemption::STATUS_APPLIED,
            0,
            $now,
            $now
        );
    }

    /**
     * The REDEEM row for one order: $points negated, source and reference the order, keyed
     * `redeem:{user}:order:{order_id}:flexible_discount` (RedemptionDraft::of()).
     */
    private function draft_from( int $user_id, int $order_id, int $points, int $redemption_id ): LedgerEntry {
        return RedemptionDraft::of( $user_id, $order_id, $redemption_id, LedgerType::REDEEM, -$points, ReferenceType::ORDER, $order_id );
    }

    /** Freezes points and value onto the order (HPOS-safe update_meta_data + save) so the order screen and emails never re-derive them. */
    private function freeze( \WC_Order $order, Result $quote ): void {
        $order->update_meta_data( self::META_POINTS, $quote->get_points() );
        $order->update_meta_data( self::META_VALUE, $this->decimal( $quote ) );
        $order->save();
    }

    /** The quote's value as the decimal string the row and the order meta both store. */
    private function decimal( Result $quote ): string {
        return $this->money( $quote->get_value() );
    }
}
