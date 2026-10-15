<?php
namespace YayLoyalty\Engine\Expiry;

use YayLoyalty\Engine\Emails\Emails;
use YayLoyalty\Engine\Emails\PointsExpiringSoonEmail;
use YayLoyalty\Helpers\EmailsHelper;
use YayLoyalty\Repositories\CustomerRepository;
use YayLoyalty\Repositories\LotRepository;
use YayLoyalty\Utils\Log;

defined( 'ABSPATH' ) || exit;

/**
 * The expiry job's reminder step: claims each due Points expiring soon reminder, then relays it to the
 * email. Claim-then-send makes a reminder at-most-once: a failed send is dropped, never doubled. Nothing
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
        // Batch reminders are per lot, which this step does not handle yet: customers.expires_at is only
        // the soonest lot there, and the balance is not what expires.
        if ( ! $policy->is_enabled() || ! $policy->is_applied() || $policy->is_batch() ) {
            return false;
        }

        $days     = $policy->reminder_days();
        $user_ids = $this->customers->reminder_due_user_ids( $now, $days, ExpiryRunner::BATCH_SIZE );

        // Checked only when someone is due: it builds WooCommerce's mailer, which constructs every email.
        if ( empty( $user_ids ) || ! self::is_email_enabled() ) {
            return false;
        }

        $sent = 0;

        foreach ( $user_ids as $user_id ) {
            try {
                $claim = $this->customers->claim_reminder( $user_id, $now, $days );

                if ( null !== $claim ) {
                    Emails::relay_expiring_soon( $user_id, $claim['points'], $claim['expires_at'], true );
                    ++$sent;
                }
            } catch ( \Throwable $e ) {
                Log::error( 'ExpiryReminders', sprintf( 'reminder for user %d failed: %s', $user_id, $e->getMessage() ) );
            }
        }

        return count( $user_ids ) === ExpiryRunner::BATCH_SIZE && $sent > 0;
    }

    /** Whether the merchant has the Points expiring soon email switched on in WooCommerce. */
    private static function is_email_enabled(): bool {
        $email = EmailsHelper::registered_emails()[ PointsExpiringSoonEmail::ID ] ?? null;

        return null !== $email && $email->is_enabled();
    }
}
