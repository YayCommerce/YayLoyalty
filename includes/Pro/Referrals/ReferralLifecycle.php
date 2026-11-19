<?php
namespace YayLoyalty\Pro\Referrals;

use YayLoyalty\Engine\Earning\EarningService;
use YayLoyalty\Engine\Earning\Listeners\ListenerGuard;
use YayLoyalty\Enums\LedgerSource;
use YayLoyalty\Enums\LedgerType;
use YayLoyalty\Models\LedgerEntry;
use YayLoyalty\Pro\Enums\ReferralRejectReason;
use YayLoyalty\Pro\Enums\ReferralStatus;
use YayLoyalty\Pro\Models\Referral;
use YayLoyalty\Pro\Repositories\ReferralRepository;
use YayLoyalty\Repositories\LedgerRepository;
use YayLoyalty\Repositories\LotRepository;
use YayLoyalty\Utils\CurrencyRate;
use YayLoyalty\Utils\Dates;

defined( 'ABSPATH' ) || exit;

/** Moves a referral along as the friend's first order completes, is refunded during the hold, or is clawed back after payout. */
final class ReferralLifecycle {

    /** The statuses of an order that can still be the friend's first one. */
    private const LIVE_STATUSES = [ 'processing', 'on-hold', 'completed' ];

    private ReferralRepository $referrals;

    private ReferralPayout $payout;

    public function __construct( ReferralRepository $referrals, ReferralPayout $payout ) {
        $this->referrals = $referrals;
        $this->payout    = $payout;
    }

    /** Hooked on plugins_loaded. */
    public function register(): void {
        add_action( 'yayloyalty_order_awarded', [ $this, 'on_order_awarded' ] );
        add_action( 'yayloyalty_order_reversed', [ $this, 'on_order_reversed' ] );
        add_action( 'yayloyalty_ledger_recorded', [ $this, 'on_ledger_recorded' ] );
    }

    /** The friend's completed order either starts the hold or rejects the referral with the reason. */
    public function on_order_awarded( \WC_Order $order ): void {
        ListenerGuard::run(
            'ReferralLifecycle',
            'referral check failed for order #' . (int) $order->get_id(),
            function () use ( $order ): void {
                $this->qualify( $order );
            }
        );
    }

    /** A refund, cancel or trash of a held referral's first order rejects it; nothing was paid, so nothing is clawed back. */
    public function on_order_reversed( \WC_Order $order ): void {
        ListenerGuard::run(
            'ReferralLifecycle',
            'referral reversal failed for order #' . (int) $order->get_id(),
            function () use ( $order ): void {
                $referral = $this->referrals->find_by_first_order( (int) $order->get_id() );

                if ( null !== $referral && ReferralStatus::ON_HOLD === $referral->get_status() ) {
                    $this->referrals->reject( (int) $referral->get_id(), ReferralRejectReason::REFUNDED_DURING_HOLD, ReferralStatus::ON_HOLD );
                }
            }
        );
    }

    /**
     * A clawback that leaves nothing of a referral row (clawed back or expired) marks the referral reversed; the ledger stays the truth.
     *
     * @param mixed $entry Untyped: a third party firing the action with anything else must not fatal.
     */
    public function on_ledger_recorded( $entry ): void {
        if ( ! $entry instanceof LedgerEntry || LedgerType::REFUND_EARN !== $entry->get_type() || LedgerSource::REFERRAL !== $entry->get_source() || null === $entry->get_order_id() ) {
            return;
        }

        ListenerGuard::run(
            'ReferralLifecycle',
            'referral clawback check failed for ledger row #' . (int) $entry->get_id(),
            function () use ( $entry ): void {
                $ledger = new LedgerRepository();
                $lots   = new LotRepository();

                foreach ( $ledger->earn_rows_for_order( (int) $entry->get_order_id() ) as $earn ) {
                    if ( LedgerSource::REFERRAL === $earn->get_source() && $earn->get_user_id() === $entry->get_user_id() && $ledger->sum_refunded_for( $earn, false ) + $lots->expired_from( (int) $earn->get_id() ) >= $earn->get_points() ) {
                        $this->referrals->mark_reversed( (int) $earn->get_reference_id() );
                    }
                }
            }
        );
    }

    /**
     * Decides a pending referral from the friend's first live order in its window once that order completed (also one that completed while the program was off).
     *
     * @return bool Whether the friend has a live order in the window, so the referral must not expire.
     */
    public function settle( Referral $referral ): bool {
        $expires = $referral->get_coupon_expires_at();
        $orders  = null === $expires ? [] : wc_get_orders(
            [
                'customer_id'  => $referral->get_friend_user_id(),
                'status'       => self::LIVE_STATUSES,
                'date_created' => '<=' . Dates::to_timestamp( $expires ),
                'orderby'      => 'date',
                'order'        => 'ASC',
                'limit'        => 1,
            ]
        );
        $first   = $orders[0] ?? null;

        if ( ! $first instanceof \WC_Order ) {
            return false;
        }

        // The query already made it the first order in the window.
        if ( EarningService::is_awardable_order( $first ) ) {
            $this->decide( $first, $referral );
        }

        return true;
    }

    private function qualify( \WC_Order $order ): void {
        $friend   = (int) $order->get_customer_id();
        $referral = $friend > 0 ? $this->referrals->find_pending_for_friend( $friend ) : null;

        if ( null === $referral || ! self::placed_in_window( $order, $referral->get_coupon_expires_at() ) || self::has_earlier_order( $order ) ) {
            return;
        }

        $this->decide( $order, $referral );
    }

    /** Holds the referral on its first order, or rejects it with the reason the order doesn't qualify. */
    private function decide( \WC_Order $order, Referral $referral ): void {
        if ( ! ReferralSettings::get()['enabled'] ) {
            return;
        }

        $terms    = $referral->get_terms();
        $advocate = get_userdata( $referral->get_advocate_user_id() );
        $reason   = ReferralQualifier::check(
            $terms,
            CurrencyRate::to_base( ReferralQualifier::minimum_subtotal( $order ), (string) $order->get_currency(), $order ),
            (array) $order->get_coupon_codes(),
            ReferralCoupon::code_for( (int) $referral->get_id() ),
            (string) $order->get_billing_email(),
            $advocate ? (string) $advocate->user_email : ''
        );

        if ( null !== $reason ) {
            $this->referrals->reject( (int) $referral->get_id(), $reason );

            return;
        }

        $hold_days = (int) ( $terms['hold_days'] ?? 0 );
        $held      = $this->referrals->hold( (int) $referral->get_id(), (int) $order->get_id(), Dates::offset( $hold_days * DAY_IN_SECONDS ) );
        $referral  = $held && 0 === $hold_days ? $this->referrals->find( (int) $referral->get_id() ) : null;

        if ( $referral instanceof Referral ) {
            $this->payout->pay( $referral );
        }
    }

    /**
     * Whether the friend placed a live order before this one: that order is the first one, and it decides the referral
     * whenever it completes, so a later order that happens to complete first changes nothing.
     */
    private static function has_earlier_order( \WC_Order $order ): bool {
        $created = $order->get_date_created();
        $earlier = wc_get_orders(
            [
                'customer_id'  => (int) $order->get_customer_id(),
                'status'       => self::LIVE_STATUSES,
                'date_created' => '<' . ( null === $created ? time() : $created->getTimestamp() ),
                'exclude'      => [ (int) $order->get_id() ], // phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.PostNotIn_exclude -- one order id, the one being checked.
                'limit'        => 1,
                'return'       => 'ids',
            ]
        );

        return [] !== $earlier;
    }

    /** Whether the order was placed before the coupon window closed; a later one leaves the referral to expire. */
    private static function placed_in_window( \WC_Order $order, ?string $coupon_expires_at ): bool {
        $created = $order->get_date_created();

        return null !== $coupon_expires_at && null !== $created && $created->getTimestamp() <= Dates::to_timestamp( $coupon_expires_at );
    }
}
