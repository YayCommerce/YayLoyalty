<?php
namespace YayLoyalty\Pro\Referrals;

defined( 'ABSPATH' ) || exit;

/** Thrown inside the payout transaction to undo the claim, carrying the status the referral keeps. */
final class ReferralPayoutAborted extends \RuntimeException {

    private string $status;

    public function __construct( string $status ) {
        parent::__construct( 'Referral payout aborted.' );
        $this->status = $status;
    }

    public function get_status(): string {
        return $this->status;
    }
}
