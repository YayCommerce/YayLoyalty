<?php
namespace YayLoyalty\Engine\Emails;

use YayLoyalty\Helpers\SettingsHelper;

defined( 'ABSPATH' ) || exit;

/**
 * The merchant's weekly per-customer cap on loyalty emails (`emails.throttle_per_week`).
 * Counts sends over a rolling 7 days, kept as Unix timestamps in user meta. Not atomic: two sends
 * racing in one moment can both pass at the cap, which costs one extra email, never a lost award.
 */
final class EmailThrottle {

    public const META_SENDS = '_yayloyalty_email_sends';

    /** Whether $user_id may get another loyalty email now. */
    public static function allows( int $user_id ): bool {
        $cap = self::cap();

        return null === $cap || count( self::recent_sends( $user_id ) ) < $cap;
    }

    /** Counts one sent loyalty email against $user_id's week. Nothing is counted while there is no cap. */
    public static function record( int $user_id ): void {
        if ( null === self::cap() ) {
            return;
        }

        $sends   = self::recent_sends( $user_id );
        $sends[] = time();

        update_user_meta( $user_id, self::META_SENDS, $sends );
    }

    /** null means no cap. */
    private static function cap(): ?int {
        return SettingsHelper::get_settings()['emails']['throttle_per_week'];
    }

    /** @return int[] Send times inside the window. Older ones are dropped. */
    private static function recent_sends( int $user_id ): array {
        $sends = get_user_meta( $user_id, self::META_SENDS, true );
        $since = time() - WEEK_IN_SECONDS;

        return array_values(
            array_filter(
                array_map( 'intval', is_array( $sends ) ? $sends : [] ),
                static function ( int $sent_at ) use ( $since ): bool {
                    return $sent_at > $since;
                }
            )
        );
    }
}
