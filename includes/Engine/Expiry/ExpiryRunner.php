<?php
namespace YayLoyalty\Engine\Expiry;

use YayLoyalty\Repositories\CampaignRepository;
use YayLoyalty\Repositories\CustomerRepository;
use YayLoyalty\Repositories\LedgerRepository;
use YayLoyalty\Repositories\LotRepository;
use YayLoyalty\Services\LedgerService;
use YayLoyalty\Utils\Log;

defined( 'ABSPATH' ) || exit;

/**
 * The daily Action Scheduler job: backfills lots for balances that predate them. Each run handles one
 * batch and queues an async continuation while batches come back full. Does nothing without Action
 * Scheduler (WooCommerce inactive).
 */
final class ExpiryRunner {

    public const RUN_ACTION = 'yayloyalty_expiry_run';

    public const GROUP = 'yayloyalty';

    public const BATCH_SIZE = 100;

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
        $ledger   = new LedgerService( new LedgerRepository(), new CustomerRepository(), new CampaignRepository() );
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
        if ( count( $user_ids ) === self::BATCH_SIZE && $opened > 0 ) {
            as_enqueue_async_action( self::RUN_ACTION, [], self::GROUP );
        }
    }

    /** Removes every pending run. Called on deactivation. */
    public static function unschedule(): void {
        if ( function_exists( 'as_unschedule_all_actions' ) ) {
            as_unschedule_all_actions( self::RUN_ACTION );
        }
    }
}
