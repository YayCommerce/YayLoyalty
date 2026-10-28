<?php
namespace YayLoyalty\Engine\Expiry;

use YayLoyalty\Engine\Emails\Emails;
use YayLoyalty\Engine\Emails\PointsExpiringSoonEmail;
use YayLoyalty\Helpers\EmailsHelper;
use YayLoyalty\Repositories\CustomerRepository;
use YayLoyalty\Repositories\LotRepository;
use YayLoyalty\Utils\Dates;
use YayLoyalty\Utils\Log;

defined( 'ABSPATH' ) || exit;

/**
 * The expiry job's reminder step: claims each due Points expiring soon reminder, then relays it to the
 * email. Inactivity mode announces each customer's date once (customers.reminder_for), batch mode each
 * lot once (lots.reminded_at), several lots due together in one email. Claim-then-send makes a reminder at-most-once per claim: a failed send is retried by QueuedEmails, never doubled, and the send re-reads what the claim still announces (still_due()). Nothing
 * is claimed while expiry is off, the stored dates are stale or the email is switched off, so turning
 * the email on later still reaches customers inside their window.
 */
final class ExpiryReminders {

    /** @var CustomerRepository */
    private $customers;

    /** @var LotRepository */
    private $lots;

    public function __construct( CustomerRepository $customers, LotRepository $lots ) {
        $this->customers = $customers;
        $this->lots      = $lots;
    }

    /** @return bool Whether a full batch made progress, so more may remain. */
    public function run( ExpiryPolicy $policy, string $now ): bool {
        if ( ! $policy->is_enabled() || ! $policy->is_applied() ) {
            return false;
        }

        $horizon  = Dates::offset( $policy->reminder_days() * DAY_IN_SECONDS, $now );
        $is_batch = $policy->is_batch();
        $user_ids = $is_batch
            ? $this->lots->reminder_due_user_ids( $now, $horizon, ExpiryRunner::BATCH_SIZE )
            : $this->customers->reminder_due_user_ids( $now, $horizon, ExpiryRunner::BATCH_SIZE );

        // Checked only when someone is due: it builds WooCommerce's mailer, which constructs every email.
        if ( empty( $user_ids ) || ! self::is_email_enabled() ) {
            return false;
        }

        $sent = 0;

        foreach ( $user_ids as $user_id ) {
            try {
                $claim = $is_batch ? $this->claim_lots( $user_id, $now, $horizon ) : $this->customers->claim_reminder( $user_id, $now, $horizon );

                if ( null !== $claim ) {
                    Emails::relay_expiring_soon( $user_id, $claim['expires_at'], $claim['lot_ids'] ?? [] );
                    ++$sent;
                }
            } catch ( \Throwable $e ) {
                Log::error( 'ExpiryReminders', sprintf( 'reminder for user %d failed: %s', $user_id, $e->getMessage() ) );
            }
        }

        return count( $user_ids ) === ExpiryRunner::BATCH_SIZE && $sent > 0;
    }

    /**
     * What a claimed reminder still announces when its queued email comes due, read fresh: the customer
     * may have earned or spent since the claim. Inactivity mode keeps it only while the customer's date
     * is still the claimed one (earning or redeeming moves it) and points remain; batch mode keeps the
     * claimed lots that still hold points (LotRepository::reminded_lots()).
     *
     * @param string $expires_at The date the claim announced, UTC.
     * @param int[]  $lot_ids    The lots a batch claim stamped; [] in inactivity mode.
     * @return array{points: int, expires_at: string, is_single_date: bool}|null Null when nothing is left to announce.
     */
    public function still_due( int $user_id, string $expires_at, array $lot_ids, string $now ): ?array {
        if ( strcmp( $expires_at, $now ) <= 0 ) {
            return null;
        }

        if ( ExpiryPolicy::current()->is_batch() ) {
            return $this->lots->reminded_lots( $user_id, $lot_ids, $now );
        }

        $customer = $this->customers->find_by_user_id( $user_id );

        if ( null === $customer || $customer->get_expires_at() !== $expires_at || $customer->get_balance() <= 0 ) {
            return null;
        }

        return [
            'points'         => $customer->get_balance(),
            'expires_at'     => $expires_at,
            'is_single_date' => true,
        ];
    }

    /**
     * Batch: each lot not yet announced that expires within the window, once per lot.
     *
     * @return array{points: int, expires_at: string, is_single_date: bool, lot_ids: int[]}|null
     */
    private function claim_lots( int $user_id, string $now, string $horizon ): ?array {
        $claim = $this->lots->claim_reminder_lots( $user_id, $now, $horizon );

        if ( null !== $claim ) {
            $this->customers->mark_reminded( $user_id, $now );
        }

        return $claim;
    }

    /** Whether the merchant has the Points expiring soon email switched on in WooCommerce. */
    private static function is_email_enabled(): bool {
        $email = EmailsHelper::registered_emails()[ PointsExpiringSoonEmail::ID ] ?? null;

        return null !== $email && $email->is_enabled();
    }
}
