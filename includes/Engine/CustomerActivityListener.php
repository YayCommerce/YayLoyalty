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
 * First WP-core business-logic listener in this codebase — no dedicated
 * Listeners/Hooks base class exists, so this mirrors the closest
 * precedent (`Engine\ActDeact::on_new_site()`: plain static, guards then
 * works) and is registered directly in `yayloyalty.php`. Three capture
 * hooks share one service factory: `delete_user`/`wpmu_delete_user`
 * (account deletion, phase 02) and `yayloyalty_ledger_recorded`
 * (`account_created` + `points_adjusted`, phase 03). Every callback
 * try/catches `\Throwable` — a failed capture must never block or fatal
 * the WP action it observes.
 */
final class CustomerActivityListener {

    /**
     * Single-site path. `$reassign` is accepted and ignored: WP only
     * reassigns posts/links, never plugin tables, so loyalty rows stay
     * keyed to the old user_id forever.
     *
     * On multisite, `wp_delete_user()` still unconditionally fires
     * `delete_user` even for a "removed from this blog" action that is
     * NOT a real deletion (it falls through to `remove_user_from_blog()`
     * instead of deleting the row) — only `on_wpmu_delete_user()` is
     * trusted as "really gone" there.
     *
     * @param int        $user_id
     * @param int|null   $reassign
     * @param \WP_User|null $user
     */
    public static function on_delete_user( $user_id, $reassign = null, $user = null ): void {
        if ( is_multisite() ) {
            return;
        }

        self::capture_deletion( (int) $user_id, $user );
    }

    /**
     * Multisite path: fires INSTEAD of `delete_user` for a real
     * network-wide deletion (no `$reassign` param on this hook). This is
     * the only hook trusted as "really gone" on a multisite install.
     *
     * @param int           $user_id
     * @param \WP_User|null $user
     */
    public static function on_wpmu_delete_user( $user_id, $user = null ): void {
        self::capture_deletion( (int) $user_id, $user );
    }

    /**
     * Guards `$entry`'s type: `yayloyalty_ledger_recorded` is a public
     * seam a third party could fire with anything.
     *
     * @param mixed $entry
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
     * Identity comes from the `WP_User` object when the hook supplied one
     * (the only moment `user_email`/`display_name` are still readable for
     * `delete_user`); `get_userdata()` is a defensive fallback for a
     * third-party caller that fires the hook without it.
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
