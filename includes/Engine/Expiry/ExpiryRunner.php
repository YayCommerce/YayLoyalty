<?php
namespace YayLoyalty\Engine\Expiry;

use YayLoyalty\Engine\Earning\Outcome;
use YayLoyalty\Engine\Emails\Emails;
use YayLoyalty\Enums\ExpiryMode;
use YayLoyalty\Repositories\CampaignRepository;
use YayLoyalty\Repositories\CustomerRepository;
use YayLoyalty\Repositories\LedgerRepository;
use YayLoyalty\Repositories\LotRepository;
use YayLoyalty\Services\LedgerService;
use YayLoyalty\Utils\Dates;
use YayLoyalty\Utils\Log;

defined( 'ABSPATH' ) || exit;

/**
 * The daily Action Scheduler job. Each run, in order: backfills lots for balances that predate them,
 * re-applies changed expiry settings to every customer's date, expires due customers through
 * LedgerService::expire(), then sends due expiry reminders (ExpiryReminders). Each step handles one batch, and the run queues an async continuation while
 * a step made progress on a full batch. Does nothing without Action Scheduler (WooCommerce inactive).
 */
final class ExpiryRunner {

    public const RUN_ACTION = 'yayloyalty_expiry_run';

    public const GROUP = 'yayloyalty';

    public const BATCH_SIZE = 100;

    /** ExpiryPolicy::signature() the stored dates were last computed from. */
    public const OPTION_APPLIED = 'yayloyalty_expiry_applied';

    public function register(): void {
        self::register_daily( self::RUN_ACTION, [ $this, 'schedule' ], [ $this, 'run' ] );
    }

    /** Keeps the daily action scheduled. Hooked on init. */
    public function schedule(): void {
        self::keep_daily( self::RUN_ACTION );
    }

    /**
     * Wires a daily job in GROUP: $run on $action, and $schedule on init to keep it scheduled. Does nothing
     * without Action Scheduler (WooCommerce inactive).
     */
    public static function register_daily( string $action, callable $schedule, callable $run ): void {
        if ( ! function_exists( 'as_has_scheduled_action' ) ) {
            return;
        }

        // Only admin page loads and cron check, so storefront views and admin-ajax (Heartbeat) pay no Action Scheduler query.
        if ( ( is_admin() && ! wp_doing_ajax() ) || wp_doing_cron() ) {
            add_action( 'init', $schedule );
        }

        add_action( $action, $run );
    }

    /** Schedules $action to recur daily in GROUP unless it already is. */
    public static function keep_daily( string $action ): void {
        if ( ! as_has_scheduled_action( $action ) ) {
            as_schedule_recurring_action( time(), DAY_IN_SECONDS, $action, [], self::GROUP );
        }
    }

    public function run(): void {
        $now       = Dates::now();
        $policy    = ExpiryPolicy::current();
        $customers = new CustomerRepository();
        $lots      = new LotRepository();
        $ledger    = new LedgerService( new LedgerRepository(), $customers, new CampaignRepository() );

        $has_more = $this->backfill( $ledger, $lots );

        try {
            $this->apply_settings( $policy, $customers, $lots, $has_more, $now );
        } catch ( \Throwable $e ) {
            // Unapplied, so the next run retries; expiry waits, since is_due() wants applied dates.
            Log::error( 'ExpiryRunner', 'applying expiry settings failed: ' . $e->getMessage() );
        }

        if ( $policy->is_enabled() ) {
            $due_ids = $policy->is_batch() ? $lots->due_user_ids( $now, self::BATCH_SIZE ) : $customers->due_user_ids( $now, self::BATCH_SIZE );

            if ( $this->expire_due( $ledger, $due_ids, $now ) ) {
                $has_more = true;
            }
        }

        if ( ( new ExpiryReminders( $customers, $lots ) )->run( $policy, $now ) ) {
            $has_more = true;
        }

        if ( $has_more ) {
            self::queue_now();
        }
    }

    /** Queues a run now, after a settings save changed expiry. */
    public static function queue_now(): void {
        if ( function_exists( 'as_enqueue_async_action' ) ) {
            as_enqueue_async_action( self::RUN_ACTION, [], self::GROUP );
        }
    }

    /**
     * Recomputes customers.expires_at, and in batch mode lot dates, when the settings changed since the
     * last run. Kept dates never move sooner than the grace floor or the stored date; applied ones move to
     * the new window, and only a date set for the first time gets the floor. Batch dates live on lots, so
     * they wait until every balance has its lots: until then the settings stay unapplied and nothing expires.
     *
     * @param bool $is_backfill_pending Whether this run's backfill left balances without lots.
     */
    private function apply_settings( ExpiryPolicy $policy, CustomerRepository $customers, LotRepository $lots, bool $is_backfill_pending, string $now ): void {
        $signature = $policy->signature();

        if ( get_option( self::OPTION_APPLIED ) === $signature ) {
            return;
        }

        $floor = ExpiryPolicy::grace_floor( $now );

        if ( ! $policy->is_enabled() ) {
            $customers->clear_expiry();
        } elseif ( ExpiryMode::INACTIVITY === $policy->mode() ) {
            $customers->restamp_inactivity_expiry( $policy->inactivity_days(), $floor, $policy->keeps_dates() );
        } elseif ( $is_backfill_pending ) {
            return;
        } else {
            $lots->restamp_open_expiry( $policy->batch_months(), $floor, $policy->keeps_dates() );
            $customers->restamp_expiry_from_lots();
        }

        update_option( self::OPTION_APPLIED, $signature, false );
    }

    /** @return bool Whether a full batch made progress, so more may remain. */
    private function backfill( LedgerService $ledger, LotRepository $lots ): bool {
        $user_ids = $lots->user_ids_needing_backfill( self::BATCH_SIZE );
        $opened   = 0;

        foreach ( $user_ids as $user_id ) {
            try {
                $opened += $ledger->backfill_lots( $user_id );
            } catch ( \Throwable $e ) {
                Log::error( 'ExpiryRunner', sprintf( 'backfill for user %d failed: %s', $user_id, $e->getMessage() ) );
            }
        }

        // A batch that opened nothing would come back unchanged: balances with no ledger history to rebuild from.
        return count( $user_ids ) === self::BATCH_SIZE && $opened > 0;
    }

    /**
     * @param int[] $user_ids Customers due at $now, at most BATCH_SIZE.
     * @return bool Whether a full batch made progress, so more may remain.
     */
    private function expire_due( LedgerService $ledger, array $user_ids, string $now ): bool {
        $expired = 0;

        foreach ( $user_ids as $user_id ) {
            try {
                $outcome = $ledger->expire( $user_id, $now );

                if ( Outcome::AWARDED === $outcome->get_status() ) {
                    Emails::relay_expiry( $outcome->get_entry() );
                    ++$expired;
                }
            } catch ( \Throwable $e ) {
                Log::error( 'ExpiryRunner', sprintf( 'expiry for user %d failed: %s', $user_id, $e->getMessage() ) );
            }
        }

        return count( $user_ids ) === self::BATCH_SIZE && $expired > 0;
    }

    /** Removes every pending action in GROUP, this job's and any other the build schedules there. Called on deactivation. */
    public static function unschedule_group(): void {
        if ( function_exists( 'as_unschedule_all_actions' ) ) {
            as_unschedule_all_actions( '', [], self::GROUP );
        }
    }
}
