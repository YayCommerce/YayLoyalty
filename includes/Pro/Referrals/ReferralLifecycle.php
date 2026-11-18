<?php
namespace YayLoyalty\Pro\Referrals;

use YayLoyalty\Engine\Earning\Listeners\ListenerGuard;
use YayLoyalty\Engine\Points\ContextFactory;
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

    /** A clawback that leaves nothing of a referral row (clawed back or expired) marks the referral reversed; the ledger stays the truth. */
    public function on_ledger_recorded( LedgerEntry $entry ): void {
        if ( LedgerType::REFUND_EARN !== $entry->get_type() || LedgerSource::REFERRAL !== $entry->get_source() || null === $entry->get_order_id() ) {
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

    private function qualify( \WC_Order $order ): void {
        $order_id = (int) $order->get_id();
        $friend   = (int) $order->get_customer_id();
        $referral = $friend > 0 ? $this->referrals->find_pending_for_friend( $friend ) : null;

        if ( null === $referral || ! ReferralSettings::get()['enabled'] || ! self::placed_in_window( $order, $referral->get_coupon_expires_at() ) ) {
            return;
        }

        $terms    = $referral->get_terms();
        $facts    = ContextFactory::customer_facts( $friend, Dates::now(), $order_id );
        $advocate = get_userdata( $referral->get_advocate_user_id() );
        $reason   = ReferralQualifier::check(
            $terms,
            null === $facts ? 0 : $facts->get_order_count(),
            CurrencyRate::to_base( self::minimum_subtotal( $order ), (string) $order->get_currency(), $order ),
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
        $held      = $this->referrals->hold( (int) $referral->get_id(), $order_id, Dates::offset( $hold_days * DAY_IN_SECONDS ) );
        $referral  = $held && 0 === $hold_days ? $this->referrals->find( (int) $referral->get_id() ) : null;

        if ( $referral instanceof Referral ) {
            $this->payout->pay( $referral );
        }
    }

    /** The order's subtotal as the cart showed it when the minimum was checked: with line tax when the cart displays prices with tax. */
    private static function minimum_subtotal( \WC_Order $order ): float {
        $subtotal = (float) $order->get_subtotal();

        if ( 'incl' !== get_option( 'woocommerce_tax_display_cart' ) ) {
            return $subtotal;
        }

        foreach ( $order->get_items( 'line_item' ) as $item ) {
            $subtotal += (float) $item->get_subtotal_tax();
        }

        return $subtotal;
    }

    /** Whether the order was placed before the coupon window closed; a later one leaves the referral to expire. */
    private static function placed_in_window( \WC_Order $order, ?string $coupon_expires_at ): bool {
        $created = $order->get_date_created();

        return null !== $coupon_expires_at && null !== $created && $created->getTimestamp() <= Dates::to_timestamp( $coupon_expires_at );
    }
}
