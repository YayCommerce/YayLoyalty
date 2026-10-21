<?php
namespace YayLoyalty\Engine\Storefront;

use YayLoyalty\Repositories\LedgerRepository;
use YayLoyalty\Utils\Dates;

defined( 'ABSPATH' ) || exit;

/**
 * The launcher panel's "You earned 40 new points.": points issued since the shopper last opened the panel.
 * The one owner of the per-user stamp. The panel's first open each page posts to POST /me/activity/seen,
 * which calls mark_seen(); the notice is then gone from the next page on, on every device.
 */
final class NewPointsNotice {

    /** User meta: when the shopper last opened the launcher panel, as a UTC MySQL datetime. */
    public const META_SEEN_AT = '_yayloyalty_panel_seen_at';

    /**
     * Points issued since the last open; 0 for none. A member with no stamp yet starts one now, so the first
     * panel after this ships never sums a whole history into "new".
     */
    public static function points_since_seen( int $user_id ): int {
        $seen_at = (string) get_user_meta( $user_id, self::META_SEEN_AT, true );

        if ( ! Dates::is_valid( $seen_at ) ) {
            self::mark_seen( $user_id );

            return 0;
        }

        return ( new LedgerRepository() )->issued_since( $user_id, $seen_at );
    }

    public static function mark_seen( int $user_id ): void {
        update_user_meta( $user_id, self::META_SEEN_AT, Dates::now() );
    }
}
