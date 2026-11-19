<?php
namespace YayLoyalty\Pro\Referrals;

use YayLoyalty\Engine\Expiry\ExpiryRunner;
use YayLoyalty\Pro\Enums\ReferralRejectReason;
use YayLoyalty\Pro\Enums\ReferralStatus;
use YayLoyalty\Pro\Models\Referral;
use YayLoyalty\Pro\Repositories\ReferralRepository;
use YayLoyalty\Utils\Dates;
use YayLoyalty\Utils\Log;

defined( 'ABSPATH' ) || exit;

/**
 * The daily Action Scheduler job: pays held referrals whose hold has ended, then expires pending ones whose
 * coupon window closed with no order. It runs whether or not the program is live, so held referrals still release.
 */
final class ReferralRunner {

    public const RUN_ACTION = 'yayloyalty_referral_run';

    public const BATCH_SIZE = 100;

    private const PHASE_RELEASE = 'release';

    private const PHASE_EXPIRE = 'expire';

    /** Settles every pending referral once, queued when the program is switched back on. */
    private const PHASE_RECHECK = 'recheck';

    private ReferralRepository $referrals;

    private ReferralPayout $payout;

    private ReferralLifecycle $lifecycle;

    public function __construct( ReferralRepository $referrals, ReferralPayout $payout, ReferralLifecycle $lifecycle ) {
        $this->referrals = $referrals;
        $this->payout    = $payout;
        $this->lifecycle = $lifecycle;
    }

    public function register(): void {
        ExpiryRunner::register_daily( self::RUN_ACTION, [ $this, 'schedule' ], [ $this, 'run' ], 2 );
        add_action( 'update_option_' . ReferralSettings::OPTION, [ $this, 'on_settings_updated' ], 10, 2 );
    }

    /** Keeps the daily action scheduled. Hooked on init. */
    public function schedule(): void {
        ExpiryRunner::keep_daily( self::RUN_ACTION );
    }

    /**
     * Switching the program back on queues a recheck, so a first order that completed while it was off is decided.
     *
     * @param mixed $old_value
     * @param mixed $value
     */
    public function on_settings_updated( $old_value, $value ): void {
        $was_on = is_array( $old_value ) && ! empty( $old_value['enabled'] );
        $is_on  = is_array( $value ) && ! empty( $value['enabled'] );

        if ( ! $was_on && $is_on && function_exists( 'as_enqueue_async_action' ) && ! as_has_scheduled_action( self::RUN_ACTION, [ self::PHASE_RECHECK, 0 ] ) ) {
            as_enqueue_async_action( self::RUN_ACTION, [ self::PHASE_RECHECK, 0 ], ExpiryRunner::GROUP );
        }
    }

    /**
     * Runs one batch of a phase; a full batch continues asynchronously, and the release phase hands over to expiry.
     *
     * @param string     $phase    Where a continuation resumes; Action Scheduler passes its stored arguments.
     * @param int|string $after_id
     */
    public function run( $phase = self::PHASE_RELEASE, $after_id = 0 ): void {
        if ( self::PHASE_RECHECK === $phase ) {
            $due = $this->referrals->due( ReferralStatus::PENDING, null, (int) $after_id, self::BATCH_SIZE );
            $act = [ $this->lifecycle, 'settle' ];
        } elseif ( self::PHASE_EXPIRE === $phase ) {
            $due = $this->referrals->due( ReferralStatus::PENDING, 'coupon_expires_at', (int) $after_id, self::BATCH_SIZE );
            $act = [ $this, 'expire' ];
        } else {
            $phase = self::PHASE_RELEASE;
            $due   = $this->referrals->due( ReferralStatus::ON_HOLD, 'hold_until', (int) $after_id, self::BATCH_SIZE );
            $act   = [ $this, 'release' ];
        }

        foreach ( $due as $referral ) {
            try {
                $act( $referral );
            } catch ( \Throwable $e ) {
                Log::error( 'ReferralRunner', sprintf( 'referral #%d %s failed: %s', (int) $referral->get_id(), $phase, $e->getMessage() ) );
            }
        }

        if ( count( $due ) === self::BATCH_SIZE ) {
            as_enqueue_async_action( self::RUN_ACTION, [ $phase, (int) end( $due )->get_id() ], ExpiryRunner::GROUP );
        } elseif ( self::PHASE_RELEASE === $phase ) {
            $this->run( self::PHASE_EXPIRE, 0 );
        }
    }

    /** Pays a held referral, or, for an advocate who can't be paid, rejects it once ReferralPayout::rejects_at() has passed. */
    private function release( Referral $referral ): void {
        $at = ReferralPayout::rejects_at( $referral->get_advocate_user_id(), (string) $referral->get_hold_until() );

        if ( null === $at ) {
            $this->payout->pay( $referral );
        } elseif ( $at <= Dates::now() ) {
            $this->referrals->reject( (int) $referral->get_id(), ReferralRejectReason::ADVOCATE_CANNOT_EARN, ReferralStatus::ON_HOLD );
        }
    }

    /** Expires a pending referral, unless the friend placed an order inside the window; a completed one decides it instead. */
    private function expire( Referral $referral ): void {
        if ( ! $this->lifecycle->settle( $referral ) ) {
            $this->referrals->expire( (int) $referral->get_id() );
        }
    }
}
