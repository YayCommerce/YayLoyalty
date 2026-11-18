<?php
namespace YayLoyalty\Pro\Referrals;

use YayLoyalty\Engine\Earning\Listeners\ListenerGuard;
use YayLoyalty\Repositories\CustomerRepository;

defined( 'ABSPATH' ) || exit;

/** Gives every new customer their referral code as soon as their row is created. */
final class ReferralCodeAssigner {

    private const SOURCE = 'ReferralCodeAssigner';

    /** @var CustomerRepository */
    private $customers;

    public function __construct( CustomerRepository $customers ) {
        $this->customers = $customers;
    }

    public function register(): void {
        add_action( 'yayloyalty_customer_created', [ $this, 'on_customer_created' ] );
    }

    /** The row may be created inside a ledger write, so a failure is logged and the code is made later, on first use. */
    public function on_customer_created( int $user_id ): void {
        ListenerGuard::run(
            self::SOURCE,
            'referral code failed for user #' . $user_id,
            function () use ( $user_id ): void {
                $this->customers->assign_referral_code( $user_id );
            }
        );
    }
}
