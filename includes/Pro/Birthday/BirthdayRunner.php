<?php
namespace YayLoyalty\Pro\Birthday;

use YayLoyalty\Engine\Earning\EarningService;
use YayLoyalty\Engine\Earning\IdempotencyKey;
use YayLoyalty\Engine\Earning\Listeners\PreviewLog;
use YayLoyalty\Engine\Expiry\ExpiryRunner;
use YayLoyalty\Engine\Points\ContextFactory;
use YayLoyalty\Enums\CampaignTrigger;
use YayLoyalty\Enums\LedgerType;
use YayLoyalty\Enums\ReferenceType;
use YayLoyalty\Models\Customer;
use YayLoyalty\Repositories\CampaignRepository;
use YayLoyalty\Repositories\CustomerRepository;
use YayLoyalty\Repositories\LedgerRepository;
use YayLoyalty\Utils\Dates;
use YayLoyalty\Utils\Log;

defined( 'ABSPATH' ) || exit;

/**
 * The daily Action Scheduler job that pays birthday campaigns. It awards every customer whose birthday
 * falls within the widest live campaign's early window, not only on the day itself, so a late or missed run
 * still pays; the ledger's idempotency key keeps that to once per birthday. Batches resume by user id.
 */
final class BirthdayRunner {

    public const RUN_ACTION = 'yayloyalty_birthday_run';

    public const BATCH_SIZE = 100;

    /** @var \DateTimeImmutable|null */
    private $site_today;

    /** @param \DateTimeImmutable|null $site_today The site-calendar day to run for; null for today. */
    public function __construct( ?\DateTimeImmutable $site_today = null ) {
        $this->site_today = $site_today;
    }

    public function register(): void {
        if ( ! function_exists( 'as_has_scheduled_action' ) ) {
            return;
        }

        // Only admin page loads and cron check, so storefront views and admin-ajax (Heartbeat) pay no Action Scheduler query.
        if ( ( is_admin() && ! wp_doing_ajax() ) || wp_doing_cron() ) {
            add_action( 'init', [ $this, 'schedule' ] );
        }

        add_action( self::RUN_ACTION, [ $this, 'run' ] );
    }

    /** Keeps the daily action scheduled. Hooked on init. */
    public function schedule(): void {
        if ( ! as_has_scheduled_action( self::RUN_ACTION ) ) {
            as_schedule_recurring_action( time(), DAY_IN_SECONDS, self::RUN_ACTION, [], ExpiryRunner::GROUP );
        }
    }

    /** @param int|string $after_user_id Where a continuation resumes; Action Scheduler passes its stored argument. */
    public function run( $after_user_id = 0 ): void {
        $campaigns = ( new CampaignRepository() )->live_for_trigger( CampaignTrigger::BIRTHDAY, Dates::now() );

        if ( [] === $campaigns ) {
            return;
        }

        $days_before = 0;

        foreach ( $campaigns as $campaign ) {
            $days_before = max( $days_before, (int) ( $campaign->get_config()['days_before'] ?? 0 ) );
        }

        $site_today = null !== $this->site_today ? $this->site_today : new \DateTimeImmutable( 'today', wp_timezone() );
        $customers  = new CustomerRepository();
        $user_ids   = $customers->birthday_user_ids( BirthdayWindow::due_days( $site_today, $days_before ), (int) $after_user_id, self::BATCH_SIZE );

        if ( [] === $user_ids ) {
            return;
        }

        $service = EarningService::build();
        $ledger  = new LedgerRepository();

        foreach ( $user_ids as $user_id ) {
            try {
                $this->award( $service, $ledger, $customers->find_by_user_id( $user_id ), $site_today );
            } catch ( \Throwable $e ) {
                Log::error( 'BirthdayRunner', sprintf( 'birthday award for user %d failed: %s', $user_id, $e->getMessage() ) );
            }
        }

        if ( count( $user_ids ) === self::BATCH_SIZE ) {
            as_enqueue_async_action( self::RUN_ACTION, [ end( $user_ids ) ], ExpiryRunner::GROUP );
        }
    }

    private function award( EarningService $service, LedgerRepository $ledger, ?Customer $customer, \DateTimeImmutable $site_today ): void {
        if ( null === $customer || null === $customer->get_birthday_month() || null === $customer->get_birthday_day() || ! get_userdata( $customer->get_user_id() ) ) {
            return;
        }

        $user_id = $customer->get_user_id();
        $next    = BirthdayWindow::next( $customer->get_birthday_month(), $customer->get_birthday_day(), $site_today );

        // Already paid for this birthday: skip the engine and the transaction a duplicate would cost.
        if ( null !== $ledger->find_by_idempotency_key( IdempotencyKey::compose( LedgerType::EARN, $user_id, ReferenceType::BIRTHDAY, $next['year'], CampaignTrigger::BIRTHDAY ) ) ) {
            return;
        }

        $outcome = $service->award(
            CampaignTrigger::BIRTHDAY,
            ContextFactory::from_user(
                $user_id,
                Dates::now(),
                [
                    BirthdayHandler::EXTRA_YEAR       => $next['year'],
                    BirthdayHandler::EXTRA_DAYS_UNTIL => $next['days_until'],
                ]
            )
        );

        PreviewLog::write_outcome( 'BirthdayRunner', 'user #' . $user_id . ' birthday ' . $next['year'], $outcome );
    }
}
