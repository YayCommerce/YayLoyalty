<?php
namespace YayLoyalty\Engine\Redemption;

use YayLoyalty\Database\Transaction;
use YayLoyalty\Engine\Earning\IdempotencyKey;
use YayLoyalty\Engine\Earning\Outcome;
use YayLoyalty\Engine\Points\Context as EarnContext;
use YayLoyalty\Engine\Rewards\Clamp;
use YayLoyalty\Engine\Rewards\ContextFactory;
use YayLoyalty\Engine\Rewards\Result;
use YayLoyalty\Enums\LedgerSource;
use YayLoyalty\Enums\LedgerType;
use YayLoyalty\Enums\ReferenceType;
use YayLoyalty\Enums\RewardType;
use YayLoyalty\Models\LedgerEntry;
use YayLoyalty\Models\Redemption;
use YayLoyalty\Models\Reward;
use YayLoyalty\Repositories\CustomerRepository;
use YayLoyalty\Repositories\RedemptionRepository;
use YayLoyalty\Services\LedgerService;
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
 * per order. A failed re-check writes nothing and the caller fails the
 * checkout. Cancel and refund are not here: reversal is spec task 2.
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

                // Materialise before locking: a missing row gap-locks and two first-touch writers both insert.
                $this->customers->ensure_row( $user_id );
                $customer = $this->customers->lock_and_get( $user_id );

                $existing = $this->redemptions->find_by_order( $order_id, (int) $reward->get_id() );

                if ( null !== $existing ) {
                    // The key is already in the ledger, so this answers duplicate with the existing row and writes nothing.
                    return $this->ledger->redeem( $this->draft_from( $user_id, $order_id, $existing->get_points(), (int) $existing->get_id() ) );
                }

                $ctx    = $this->contexts->from_order( $order, $reward, $customer->get_balance() );
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
                if ( abs( $quote->get_value() - $this->contexts->points_discount_of( $order ) ) >= 0.5 / pow( 10, wc_get_price_decimals() ) ) {
                    return Outcome::skipped( Clamp::BOUND_CART, $quote );
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

    /** The redemptions row a quote becomes. Precondition: $quote is allowed with points > 0. `base_value` equals `value`: there is no currency conversion. */
    private function redemption_from( Result $quote, \WC_Order $order ): Redemption {
        $value = $this->decimal( $quote );
        $now   = Dates::now();

        return new Redemption(
            null,
            (int) $order->get_customer_id(),
            (int) $quote->get_reward()->get_id(),
            (int) $order->get_id(),
            $quote->get_points(),
            $value,
            (string) $quote->get_currency(),
            $value,
            (string) $quote->get_rate_snapshot(),
            EarnContext::REDEEM_COUPON_CODE,
            null,
            Redemption::STATUS_APPLIED,
            0,
            null,
            $now,
            $now
        );
    }

    /**
     * The REDEEM row for one order: $points negated, source and reference the order, keyed
     * `redeem:{user}:order:{order_id}:flexible_discount`. balance_after is computed under the lock.
     */
    private function draft_from( int $user_id, int $order_id, int $points, int $redemption_id ): LedgerEntry {
        return new LedgerEntry(
            null,
            $user_id,
            -$points,
            0,
            LedgerType::REDEEM,
            LedgerSource::ORDER,
            ReferenceType::ORDER,
            $order_id,
            null,
            $redemption_id,
            $order_id,
            null,
            null,
            Dates::now(),
            IdempotencyKey::compose( LedgerType::REDEEM, $user_id, ReferenceType::ORDER, $order_id, RewardType::FLEXIBLE_DISCOUNT )
        );
    }

    /** Freezes points and value onto the order (HPOS-safe update_meta_data + save) so the order screen and emails never re-derive them. */
    private function freeze( \WC_Order $order, Result $quote ): void {
        $order->update_meta_data( self::META_POINTS, $quote->get_points() );
        $order->update_meta_data( self::META_VALUE, $this->decimal( $quote ) );
        $order->save();
    }

    /** The quote's value as the decimal string the row and the order meta both store. */
    private function decimal( Result $quote ): string {
        return (string) wc_format_decimal( $quote->get_value(), wc_get_price_decimals() );
    }
}
