<?php
namespace YayLoyalty\Engine\Expiry;

use YayLoyalty\Engine\Earning\Outcome;
use YayLoyalty\Engine\Emails\Emails;
use YayLoyalty\Models\Customer;
use YayLoyalty\Repositories\CampaignRepository;
use YayLoyalty\Repositories\CustomerMetaRepository;
use YayLoyalty\Repositories\CustomerRepository;
use YayLoyalty\Repositories\LedgerRepository;
use YayLoyalty\Services\CustomerActivityService;
use YayLoyalty\Services\ExpiryRules;
use YayLoyalty\Services\LedgerService;
use YayLoyalty\Utils\Dates;
use YayLoyalty\Utils\Log;

defined( 'ABSPATH' ) || exit;

/**
 * The daily Action Scheduler job that expires due points, then sends the reminders. Each run expires up
 * to PAGE due customers through LedgerService::expire(), which re-checks every one under its lock, then
 * handles up to PAGE reminders, and queues one more run when either page was full and made progress.
 */
final class ExpiryJob {

    /** The Action Scheduler hook: once a day, and again at once while due customers remain. */
    public const HOOK = 'yayloyalty_expire_points';

    public const GROUP = 'yayloyalty';

    public const PAGE = 200;

    /** @var LedgerService */
    private $ledger;

    /** @var CustomerRepository */
    private $customers;

    /** @var CustomerActivityService */
    private $activity;

    public function __construct( LedgerService $ledger, CustomerRepository $customers, CustomerActivityService $activity ) {
        $this->ledger    = $ledger;
        $this->customers = $customers;
        $this->activity  = $activity;
    }

    /** Composition root and a listed LedgerService construction site. */
    public static function build(): self {
        $customers = new CustomerRepository();
        $ledger    = new LedgerRepository();

        return new self(
            new LedgerService( $ledger, $customers, new CampaignRepository() ),
            $customers,
            new CustomerActivityService( new CustomerMetaRepository(), $customers, $ledger )
        );
    }

    public function register(): void {
        // admin_init, not init: the scheduled-action check is a query, and a merchant opens the admin daily.
        add_action( 'admin_init', [ $this, 'schedule' ] );
        add_action( self::HOOK, [ $this, 'run' ] );
    }

    /** Schedules the daily run once. Action Scheduler ships with WooCommerce, which the plugin requires. */
    public function schedule(): void {
        // admin_init also fires for admin-ajax.php, the heartbeat included: no query there.
        if ( wp_doing_ajax() || ! function_exists( 'as_has_scheduled_action' ) || as_has_scheduled_action( self::HOOK, [], self::GROUP ) ) {
            return;
        }

        as_schedule_recurring_action( time(), DAY_IN_SECONDS, self::HOOK, [], self::GROUP );
    }

    public function run(): void {
        if ( ! ExpiryRules::enabled() ) {
            return;
        }

        $more_expiries  = $this->expire_due();
        $more_reminders = $this->remind_due();

        // Only after progress: a page that failed whole would otherwise requeue itself forever.
        if ( ( $more_expiries || $more_reminders ) && function_exists( 'as_enqueue_async_action' ) ) {
            as_enqueue_async_action( self::HOOK, [], self::GROUP );
        }
    }

    /** Expires one page of due customers. True when the page was full and made progress. */
    private function expire_due(): bool {
        $due     = $this->customers->due_for_expiry( Dates::now(), self::PAGE );
        $expired = 0;

        foreach ( $due as $customer ) {
            try {
                if ( Outcome::SKIPPED !== $this->ledger->expire( $customer['user_id'], $customer['expires_at'] )->get_status() ) {
                    ++$expired;
                }
            } catch ( \Throwable $e ) {
                // One customer's failure must not stop the rest; they stay due and the next run retries.
                Log::error( 'ExpiryJob', sprintf( 'could not expire points for user %d: %s', $customer['user_id'], $e->getMessage() ) );
            }
        }

        return self::PAGE === count( $due ) && $expired > 0;
    }

    /** Handles one page of due reminders. True when the page was full and made progress. */
    private function remind_due(): bool {
        $stages = ExpiryRules::reminder_stages();
        $now    = Dates::now();
        $due    = $this->customers->due_for_reminder( $now, array_values( $stages ), self::PAGE );
        $done   = 0;

        foreach ( $due as $customer ) {
            try {
                $this->remind( $customer, $stages, $now );
                ++$done;
            } catch ( \Throwable $e ) {
                Log::error( 'ExpiryJob', sprintf( 'could not remind user %d: %s', $customer->get_user_id(), $e->getMessage() ) );
            }
        }

        return self::PAGE === count( $due ) && $done > 0;
    }

    /**
     * Sends the narrowest reminder stage due for $customer, unless the reminder filter skips it, then stamps
     * the customer as handled either way, so a skip leaves the due set until the next, narrower window.
     *
     * @param array<string, int> $stages Narrowest first.
     */
    private function remind( Customer $customer, array $stages, string $now ): void {
        $expires_at = (string) $customer->get_expires_at();
        $sent_at    = $customer->get_reminder_sent_at();
        $days_left  = (int) ceil( ( Dates::to_timestamp( $expires_at ) - Dates::to_timestamp( $now ) ) / DAY_IN_SECONDS );
        $stage      = '';

        foreach ( $stages as $name => $days ) {
            if ( $days_left <= $days && ( null === $sent_at || $sent_at < Dates::offset( -$days * DAY_IN_SECONDS, $expires_at ) ) ) {
                $stage = $name;
                break;
            }
        }

        if ( '' !== $stage ) {
            /**
             * The reminder stage one customer gets; '' skips them, such as below a reminder floor.
             *
             * @param string   $stage           The narrowest due stage.
             * @param Customer $customer
             * @param int      $days_left       Whole days until expires_at, rounded up.
             * @param int      $expiring_points What expires at expires_at.
             */
            $stage = (string) apply_filters( ExpiryRules::REMINDER_FILTER, $stage, $customer, $days_left, ExpiryRules::expiring_points( $customer, $expires_at ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- the constant holds the prefixed name; the sniff cannot read it.
        }

        if ( '' !== $stage ) {
            /**
             * Fires once per reminder sent. WooCommerce relays it to {action}_notification.
             *
             * @param int $user_id
             */
            do_action( Emails::reminder_action( $stage ), $customer->get_user_id() ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- reminder_action() builds a prefixed name; the sniff cannot read it.
            $this->activity->record_expiring_soon_email_sent( $customer->get_user_id(), $days_left, $now );
        }

        $this->customers->mark_reminded( $customer->get_user_id(), $now );
    }
}
