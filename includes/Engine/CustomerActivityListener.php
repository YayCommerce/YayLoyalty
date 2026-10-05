<?php
namespace YayLoyalty\Engine;

use YayLoyalty\Models\LedgerEntry;
use YayLoyalty\Repositories\CustomerMetaRepository;
use YayLoyalty\Repositories\CustomerRepository;
use YayLoyalty\Repositories\LedgerRepository;
use YayLoyalty\Services\CustomerActivityService;
use YayLoyalty\Utils\Log;

defined( 'ABSPATH' ) || exit;

/**
 * Records member activity from the user hooks and `yayloyalty_ledger_recorded`, registered in `yayloyalty.php`.
 * Every callback catches `\Throwable`: a failed capture must never block the WordPress action it observes.
 */
final class CustomerActivityListener {

    /**
     * Creates the customer's row at balance 0. Staff are skipped: an admin or shop manager
     * is not a member just for having an account. The staff test is WooCommerce's
     * `wc_prevent_admin_access()` rule.
     *
     * @param int $user_id
     */
    public static function on_user_register( $user_id ): void {
        if ( user_can( (int) $user_id, 'edit_posts' ) || user_can( (int) $user_id, 'manage_woocommerce' ) ) {
            return;
        }

        try {
            ( new CustomerRepository() )->ensure_row( (int) $user_id );
        } catch ( \Throwable $e ) {
            Log::error( 'CustomerActivityListener', $e->getMessage() );
        }
    }

    /**
     * Single-site deletion. On multisite, `delete_user` also fires when a user is only
     * removed from one blog, so there only `on_wpmu_delete_user()` counts.
     * `$reassign` is ignored: WordPress reassigns posts, never plugin tables.
     *
     * @param int           $user_id
     * @param int|null      $reassign
     * @param \WP_User|null $user
     */
    public static function on_delete_user( $user_id, $reassign = null, $user = null ): void {
        if ( is_multisite() ) {
            return;
        }

        self::capture_deletion( (int) $user_id, $user );
    }

    /**
     * Multisite deletion. This hook fires instead of `delete_user` when a user is deleted network-wide.
     *
     * @param int           $user_id
     * @param \WP_User|null $user
     */
    public static function on_wpmu_delete_user( $user_id, $user = null ): void {
        self::capture_deletion( (int) $user_id, $user );
    }

    /**
     * Checks the type, because a third party could fire `yayloyalty_ledger_recorded` with anything.
     *
     * @param mixed $entry Expected to be a LedgerEntry; anything else is ignored.
     */
    public static function on_ledger_recorded( $entry ): void {
        if ( ! $entry instanceof LedgerEntry ) {
            return;
        }

        try {
            $service = self::service();
            $service->record_account_created( $entry );
            $service->record_points_adjusted( $entry );
        } catch ( \Throwable $e ) {
            Log::error( 'CustomerActivityListener', $e->getMessage() );
        }
    }

    /**
     * Reads the email and name from the hook's `WP_User`: on `delete_user` this is the last moment
     * they can be read. `get_userdata()` covers a third party that fires the hook without one.
     *
     * @param \WP_User|null $user
     */
    private static function capture_deletion( int $user_id, $user ): void {
        try {
            if ( $user instanceof \WP_User ) {
                $email        = $user->user_email;
                $display_name = $user->display_name;
            } else {
                $fallback     = get_userdata( $user_id );
                $email        = $fallback instanceof \WP_User ? $fallback->user_email : '';
                $display_name = $fallback instanceof \WP_User ? $fallback->display_name : '';
            }

            self::service()->record_account_deleted( $user_id, $email, $display_name );
        } catch ( \Throwable $e ) {
            Log::error( 'CustomerActivityListener', $e->getMessage() );
        }
    }

    private static function service(): CustomerActivityService {
        return new CustomerActivityService( new CustomerMetaRepository(), new CustomerRepository(), new LedgerRepository() );
    }
}
