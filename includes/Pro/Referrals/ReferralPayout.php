<?php
namespace YayLoyalty\Pro\Referrals;

use YayLoyalty\Database\Transaction;
use YayLoyalty\Engine\Earning\EarningService;
use YayLoyalty\Engine\Earning\Listeners\PreviewLog;
use YayLoyalty\Engine\Earning\Outcome;
use YayLoyalty\Engine\Emails\Emails;
use YayLoyalty\Engine\Points\ContextFactory;
use YayLoyalty\Enums\CampaignTrigger;
use YayLoyalty\Enums\LedgerSource;
use YayLoyalty\Enums\ReferenceType;
use YayLoyalty\Pro\Enums\ReferralStatus;
use YayLoyalty\Pro\Models\Referral;
use YayLoyalty\Pro\Repositories\ReferralRepository;
use YayLoyalty\Utils\Dates;
use YayLoyalty\Utils\Log;

defined( 'ABSPATH' ) || exit;

/** Pays the advocate a held referral's points, once, whoever asks first: the daily job, a 0-day hold or the admin's Approve. */
final class ReferralPayout {

    private ReferralRepository $referrals;

    public function __construct( ReferralRepository $referrals ) {
        $this->referrals = $referrals;
    }

    /**
     * Claims the held referral as rewarded and pays the advocate in one transaction, so a referral another writer
     * already moved is never paid. A gate that blocks the advocate, or an advocate who no longer exists, leaves it on hold.
     *
     * @return string The referral's status afterwards.
     * @throws \YayLoyalty\Database\QueryException When a write fails (rolled back).
     */
    public function pay( Referral $referral ): string {
        $id = (int) $referral->get_id();

        if ( ! get_userdata( $referral->get_advocate_user_id() ) ) {
            Log::warning( 'ReferralPayout', sprintf( 'referral #%d stays on hold: advocate %d no longer exists', $id, $referral->get_advocate_user_id() ) );

            return $referral->get_status();
        }

        try {
            return Transaction::run(
                function () use ( $referral, $id ): string {
                    if ( ! $this->referrals->mark_rewarded( $id ) ) {
                        $current = $this->referrals->find( $id );

                        throw new ReferralPayoutAborted( $current instanceof Referral ? $current->get_status() : $referral->get_status() ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- carries a status back to pay(), never shown.
                    }

                    $outcome = EarningService::build()->award_flat(
                        CampaignTrigger::REFERRAL,
                        ContextFactory::from_user( $referral->get_advocate_user_id(), Dates::now(), [], $referral->get_first_order_id() ),
                        (int) ( $referral->get_terms()['advocate_points'] ?? 0 ),
                        LedgerSource::REFERRAL,
                        ReferenceType::REFERRAL,
                        $id
                    );

                    PreviewLog::write_outcome( 'ReferralPayout', 'referral #' . $id, $outcome );

                    if ( Outcome::SKIPPED === $outcome->get_status() ) {
                        throw new ReferralPayoutAborted( ReferralStatus::ON_HOLD ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- carries a status back to pay(), never shown.
                    }

                    if ( Outcome::AWARDED === $outcome->get_status() ) {
                        $entry = $outcome->get_entry();

                        Transaction::after_commit(
                            static function () use ( $entry ): void {
                                Emails::relay_grant( $entry );
                            }
                        );
                    }

                    return ReferralStatus::REWARDED;
                }
            );
        } catch ( ReferralPayoutAborted $aborted ) {
            return $aborted->get_status();
        }
    }
}
