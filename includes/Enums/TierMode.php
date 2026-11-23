<?php
namespace YayLoyalty\Enums;

defined( 'ABSPATH' ) || exit;

/** How customers qualify for a tier: on every point earned, the points in a rolling window, or the points in fixed calendar periods. */
final class TierMode {

    use StringEnumMethods;

    /** Every point earned counts, forever. */
    public const LIFETIME = 'lifetime';

    /** Only the points earned in the last N months count, with a hold on the tier reached. */
    public const ROLLING = 'rolling';

    /** The window lengths, in months, a merchant may pick for rolling mode. */
    public const ROLLING_MONTHS = [ 3, 6, 9, 12, 18, 24 ];

    public const DEFAULT_ROLLING_MONTHS = 6;

    /** Only the points earned in a fixed calendar period count, with the tier kept through the next period. */
    public const CALENDAR = 'calendar';

    public const CALENDAR_MONTH = 'month';

    public const CALENDAR_QUARTER = 'quarter';

    public const CALENDAR_YEAR = 'year';

    /** The periods a merchant may pick for calendar mode. */
    public const CALENDAR_PERIODS = [ self::CALENDAR_MONTH, self::CALENDAR_QUARTER, self::CALENDAR_YEAR ];

    public const DEFAULT_CALENDAR_PERIOD = self::CALENDAR_YEAR;

    /** @return string[] */
    public static function all(): array {
        return [ self::LIFETIME, self::ROLLING, self::CALENDAR ];
    }

    /** Whether $value is one of ROLLING_MONTHS, as an int or a whole-number string. */
    public static function is_rolling_months( $value ): bool {
        return is_numeric( $value ) && (string) (int) $value === (string) $value && in_array( (int) $value, self::ROLLING_MONTHS, true );
    }

    /** Whether $value is one of CALENDAR_PERIODS, as a string. */
    public static function is_calendar_period( $value ): bool {
        return is_string( $value ) && in_array( $value, self::CALENDAR_PERIODS, true );
    }

    /** The months in a calendar period: 1, 3 or 12, and 12 for an unknown value. */
    public static function calendar_period_months( string $period ): int {
        if ( self::CALENDAR_MONTH === $period ) {
            return 1;
        }

        return self::CALENDAR_QUARTER === $period ? 3 : 12;
    }

    protected static function noun(): string {
        return 'tier mode';
    }
}
