<?php
namespace YayLoyalty\Engine\Expiry;

use YayLoyalty\Engine\Earning\Outcome;
use YayLoyalty\Repositories\CampaignRepository;
use YayLoyalty\Repositories\CustomerRepository;
use YayLoyalty\Repositories\LedgerRepository;
use YayLoyalty\Services\ExpiryRules;
use YayLoyalty\Services\LedgerService;
use YayLoyalty\Utils\Dates;
use YayLoyalty\Utils\Log;

defined( 'ABSPATH' ) || exit;

/**
 * The daily Action Scheduler job that expires due points. Each run expires up to PAGE due customers
 * through LedgerService::expire(), which re-checks every one under its lock, and queues one more run
 * when the page was full and made progress.
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

    public function __construct( LedgerService $ledger, CustomerRepository $customers ) {
        $this->ledger    = $ledger;
        $this->customers = $customers;
    }

    /** Composition root and a listed LedgerService construction site. */
    public static function build(): self {
        $customers = new CustomerRepository();

        return new self( new LedgerService( new LedgerRepository(), $customers, new CampaignRepository() ), $customers );
    }

    public function register(): void {
        // admin_init, not init: the scheduled-action check is a query, and a merchant opens the admin daily.
        add_action( 'admin_init', [ $this, 'schedule' ] );
        add_action( self::HOOK, [ $this, 'run' ] );
    }

    /** Schedules the daily run once. Action Scheduler ships with WooCommerce, which the plugin requires. */
    public function schedule(): void {
        if ( ! function_exists( 'as_has_scheduled_action' ) || as_has_scheduled_action( self::HOOK, [], self::GROUP ) ) {
            return;
        }

        as_schedule_recurring_action( time(), DAY_IN_SECONDS, self::HOOK, [], self::GROUP );
    }

    public function run(): void {
        if ( ! ExpiryRules::enabled() ) {
            return;
        }

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

        // Only after progress: a page that failed whole would otherwise requeue itself forever.
        if ( self::PAGE === count( $due ) && $expired > 0 && function_exists( 'as_enqueue_async_action' ) ) {
            as_enqueue_async_action( self::HOOK, [], self::GROUP );
        }
    }
}
