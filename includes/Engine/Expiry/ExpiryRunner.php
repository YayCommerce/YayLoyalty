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
 * The daily Action Scheduler job. Each run, in order: re-applies changed expiry settings to every
 * customer's date, backfills lots for balances that predate them, then expires due customers through
 * LedgerService::expire(). Each step handles one batch, and the run queues an async continuation while
 * a step made progress on a full batch. Does nothing without Action Scheduler (WooCommerce inactive).
 */
final class ExpiryRunner {

    public const RUN_ACTION = 'yayloyalty_expiry_run';

    public const GROUP = 'yayloyalty';

    public const BATCH_SIZE = 100;

    /** ExpiryPolicy::signature() the stored dates were last computed from. */
    public const OPTION_APPLIED = 'yayloyalty_expiry_applied';

    public function register(): void {
        if ( ! function_exists( 'as_has_scheduled_action' ) ) {
            return;
        }

        // Only admin and cron requests check, so a storefront page view pays no Action Scheduler query.
        if ( is_admin() || wp_doing_cron() ) {
            add_action( 'init', [ $this, 'schedule' ] );
        }

        add_action( self::RUN_ACTION, [ $this, 'run' ] );
    }

    /** Keeps the daily action scheduled. Hooked on init. */
    public function schedule(): void {
        if ( ! as_has_scheduled_action( self::RUN_ACTION ) ) {
            as_schedule_recurring_action( time(), DAY_IN_SECONDS, self::RUN_ACTION, [], self::GROUP );
        }
    }

    public function run(): void {
        $now       = Dates::now();
        $policy    = ExpiryPolicy::current();
        $customers = new CustomerRepository();
        $ledger    = new LedgerService( new LedgerRepository(), $customers, new CampaignRepository() );

        $this->apply_settings( $policy, $customers, $now );

        $has_more = $this->backfill( $ledger );

        if ( $policy->is_enabled() && $this->expire_due( $ledger, $customers, $now ) ) {
            $has_more = true;
        }

        if ( $has_more ) {
            as_enqueue_async_action( self::RUN_ACTION, [], self::GROUP );
        }
    }

    /** Queues a run now, after a settings save changed expiry. */
    public static function queue_now(): void {
        if ( function_exists( 'as_enqueue_async_action' ) ) {
            as_enqueue_async_action( self::RUN_ACTION, [], self::GROUP );
        }
    }

    /** Recomputes customers.expires_at when the settings changed since the last run, never sooner than the grace floor. */
    private function apply_settings( ExpiryPolicy $policy, CustomerRepository $customers, string $now ): void {
        $signature = $policy->signature();

        if ( get_option( self::OPTION_APPLIED ) === $signature ) {
            return;
        }

        if ( ! $policy->is_enabled() ) {
            $customers->clear_expiry();
        } elseif ( ExpiryMode::INACTIVITY === $policy->mode() ) {
            $customers->restamp_inactivity_expiry( $policy->inactivity_days(), ExpiryDates::grace_floor( $now ) );
        }

        update_option( self::OPTION_APPLIED, $signature, false );
    }

    /** @return bool Whether a full batch made progress, so more may remain. */
    private function backfill( LedgerService $ledger ): bool {
        $user_ids = ( new LotRepository() )->user_ids_needing_backfill( self::BATCH_SIZE );
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

    /** @return bool Whether a full batch made progress, so more may remain. */
    private function expire_due( LedgerService $ledger, CustomerRepository $customers, string $now ): bool {
        $user_ids = $customers->due_user_ids( $now, self::BATCH_SIZE );
        $expired  = 0;

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

    /** Removes every pending run. Called on deactivation. */
    public static function unschedule(): void {
        if ( function_exists( 'as_unschedule_all_actions' ) ) {
            as_unschedule_all_actions( self::RUN_ACTION );
        }
    }
}
