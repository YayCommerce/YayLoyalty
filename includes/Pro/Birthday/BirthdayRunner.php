<?php
namespace YayLoyalty\Pro\Birthday;

use YayLoyalty\Engine\Earning\EarningService;
use YayLoyalty\Engine\Earning\IdempotencyKey;
use YayLoyalty\Engine\Earning\Listeners\PreviewLog;
use YayLoyalty\Engine\Expiry\ExpiryRunner;
use YayLoyalty\Engine\Points\ContextFactory;
use YayLoyalty\Enums\CampaignTrigger;
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
        ExpiryRunner::register_daily( self::RUN_ACTION, [ $this, 'schedule' ], [ $this, 'run' ] );
    }

    /** Keeps the daily action scheduled. Hooked on init. */
    public function schedule(): void {
        ExpiryRunner::keep_daily( self::RUN_ACTION );
    }

    /** @param int|string $after_user_id Where a continuation resumes; Action Scheduler passes its stored argument. */
    public function run( $after_user_id = 0 ): void {
        $campaigns = ( new CampaignRepository() )->live_for_trigger( CampaignTrigger::BIRTHDAY, Dates::now() );

        if ( [] === $campaigns ) {
            return;
        }

        $days_before = 0;

        foreach ( $campaigns as $campaign ) {
            $days_before = max( $days_before, BirthdayHandler::days_before( $campaign ) );
        }

        $site_today = $this->site_today ?? Dates::site_day_start( Dates::now() );
        $customers  = new CustomerRepository();
        $birthdays  = $customers->birthdays_on( BirthdayWindow::due_days( $site_today, $days_before ), (int) $after_user_id, self::BATCH_SIZE );

        if ( [] === $birthdays ) {
            return;
        }

        $service = EarningService::build();
        $ledger  = new LedgerRepository();

        foreach ( $birthdays as $birthday ) {
            try {
                $this->award( $service, $ledger, $birthday['user_id'], $birthday['month'], $birthday['day'], $site_today );
            } catch ( \Throwable $e ) {
                Log::error( 'BirthdayRunner', sprintf( 'birthday award for user %d failed: %s', $birthday['user_id'], $e->getMessage() ) );
            }
        }

        if ( count( $birthdays ) === self::BATCH_SIZE ) {
            as_enqueue_async_action( self::RUN_ACTION, [ end( $birthdays )['user_id'] ], ExpiryRunner::GROUP );
        }
    }

    private function award( EarningService $service, LedgerRepository $ledger, int $user_id, int $month, int $day, \DateTimeImmutable $site_today ): void {
        if ( ! get_userdata( $user_id ) ) {
            return;
        }

        $next = BirthdayWindow::next( $month, $day, $site_today );

        // Already paid for this birthday: skip the engine and the transaction a duplicate would cost.
        if ( null !== $ledger->find_by_idempotency_key( IdempotencyKey::for_earn( $user_id, ( new BirthdayHandler() )->reference_type(), $next['year'], CampaignTrigger::BIRTHDAY ) ) ) {
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
