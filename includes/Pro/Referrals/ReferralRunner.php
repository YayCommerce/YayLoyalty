<?php
namespace YayLoyalty\Pro\Referrals;

use YayLoyalty\Engine\Expiry\ExpiryRunner;
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

    private ReferralRepository $referrals;

    private ReferralPayout $payout;

    public function __construct( ReferralRepository $referrals, ReferralPayout $payout ) {
        $this->referrals = $referrals;
        $this->payout    = $payout;
    }

    public function register(): void {
        ExpiryRunner::register_daily( self::RUN_ACTION, [ $this, 'schedule' ], [ $this, 'run' ], 2 );
    }

    /** Keeps the daily action scheduled. Hooked on init. */
    public function schedule(): void {
        ExpiryRunner::keep_daily( self::RUN_ACTION );
    }

    /**
     * Runs one batch of a phase; a full batch continues asynchronously, and the release phase hands over to expiry.
     *
     * @param string     $phase    Where a continuation resumes; Action Scheduler passes its stored arguments.
     * @param int|string $after_id
     */
    public function run( $phase = self::PHASE_RELEASE, $after_id = 0 ): void {
        $release = self::PHASE_EXPIRE !== $phase;
        $due     = $release
            ? $this->referrals->due( ReferralStatus::ON_HOLD, 'hold_until', (int) $after_id, self::BATCH_SIZE )
            : $this->referrals->due( ReferralStatus::PENDING, 'coupon_expires_at', (int) $after_id, self::BATCH_SIZE );

        foreach ( $due as $referral ) {
            try {
                $release ? $this->payout->pay( $referral ) : $this->expire( $referral );
            } catch ( \Throwable $e ) {
                Log::error( 'ReferralRunner', sprintf( 'referral #%d %s failed: %s', (int) $referral->get_id(), $release ? 'release' : 'expiry', $e->getMessage() ) );
            }
        }

        if ( count( $due ) === self::BATCH_SIZE ) {
            as_enqueue_async_action( self::RUN_ACTION, [ $release ? self::PHASE_RELEASE : self::PHASE_EXPIRE, (int) end( $due )->get_id() ], ExpiryRunner::GROUP );
        } elseif ( $release ) {
            $this->run( self::PHASE_EXPIRE, 0 );
        }
    }

    /** Expires a pending referral, unless the friend placed an order inside the window that hasn't completed yet. */
    private function expire( Referral $referral ): void {
        $expires = (string) $referral->get_coupon_expires_at();
        $orders  = wc_get_orders(
            [
                'customer_id'  => $referral->get_friend_user_id(),
                'status'       => [ 'processing', 'on-hold' ],
                'date_created' => '<=' . Dates::to_timestamp( $expires ),
                'limit'        => 1,
                'return'       => 'ids',
            ]
        );

        if ( [] === $orders ) {
            $this->referrals->expire( (int) $referral->get_id() );
        }
    }
}
