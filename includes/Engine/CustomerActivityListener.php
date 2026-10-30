<?php
namespace YayLoyalty\Engine;

use YayLoyalty\Engine\Earning\Listeners\ListenerGuard;
use YayLoyalty\Engine\Earning\Outcome;
use YayLoyalty\Engine\Points\Context;
use YayLoyalty\Models\LedgerEntry;
use YayLoyalty\Repositories\CustomerMetaRepository;
use YayLoyalty\Repositories\CustomerRepository;
use YayLoyalty\Repositories\LedgerRepository;
use YayLoyalty\Services\CustomerActivityService;
use YayLoyalty\Utils\Dates;

defined( 'ABSPATH' ) || exit;

/**
 * Records member activity from the user hooks, `yayloyalty_ledger_recorded`, the earning-limit hook and the
 * expiry reminder's sent hook, registered in `yayloyalty.php`.
 * Every callback runs through ListenerGuard: a failed capture must never block the WordPress action it observes.
 */
final class CustomerActivityListener {

    private const SOURCE = 'CustomerActivityListener';

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

        ListenerGuard::run(
            self::SOURCE,
            'member row failed for user #' . (int) $user_id,
            static function () use ( $user_id ) {
                ( new CustomerRepository() )->ensure_row( (int) $user_id );
            }
        );
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

        ListenerGuard::run(
            self::SOURCE,
            'activity capture failed for ledger entry #' . (int) $entry->get_id(),
            static function () use ( $entry ) {
                $service = self::service();
                $service->record_membership_started( $entry );
                $service->record_points_adjusted( $entry );
            }
        );
    }

    /**
     * An award an earning limit cut, in part or whole
     * (EarningService::LIMITED_ACTION): one `earning_limited` row. Guards its
     * arguments like on_ledger_recorded().
     *
     * @param mixed $outcome
     * @param mixed $trigger
     * @param mixed $ctx
     */
    public static function on_award_limited( $outcome, $trigger, $ctx ): void {
        if ( ! $outcome instanceof Outcome || ! $ctx instanceof Context || null === $ctx->get_user_id() || $outcome->held_back() < 1 ) {
            return;
        }

        ListenerGuard::run(
            self::SOURCE,
            'earning_limited row failed for user #' . (int) $ctx->get_user_id(),
            static function () use ( $outcome, $trigger, $ctx ) {
                $entry = $outcome->get_entry();

                self::service()->record_earning_limited(
                    (int) $ctx->get_user_id(),
                    (string) $trigger,
                    $outcome->get_evaluation()->get_points(),
                    $outcome->held_back(),
                    $ctx->get_order_id(),
                    $entry ? $entry->get_created_at() : Dates::now()
                );
            }
        );
    }

    /**
     * One `expiring_soon_email_sent` row per expiry reminder the transport accepted, from
     * `yayloyalty_points_expiring_soon_email_sent` (Emails::POINTS_EXPIRING_SOON_SENT_ACTION).
     *
     * @param mixed $user_id
     * @param mixed $points
     * @param mixed $expires_at
     * @param mixed $reminder_days
     */
    public static function on_expiring_soon_email_sent( $user_id, $points, $expires_at, $reminder_days ): void {
        ListenerGuard::run(
            self::SOURCE,
            'expiring_soon_email_sent row failed for user #' . (int) $user_id,
            static function () use ( $user_id, $points, $expires_at, $reminder_days ) {
                self::service()->record_expiring_soon_email_sent( (int) $user_id, (int) $points, (string) $expires_at, (int) $reminder_days );
            }
        );
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
        ListenerGuard::run(
            self::SOURCE,
            'deletion capture failed for user #' . $user_id,
            static function () use ( $user_id, $user ) {
                if ( $user instanceof \WP_User ) {
                    $email        = $user->user_email;
                    $display_name = $user->display_name;
                } else {
                    $fallback     = get_userdata( $user_id );
                    $email        = $fallback instanceof \WP_User ? $fallback->user_email : '';
                    $display_name = $fallback instanceof \WP_User ? $fallback->display_name : '';
                }

                self::service()->record_account_deleted( $user_id, $email, $display_name );
            }
        );
    }

    private static function service(): CustomerActivityService {
        return new CustomerActivityService( new CustomerMetaRepository(), new CustomerRepository(), new LedgerRepository() );
    }
}
