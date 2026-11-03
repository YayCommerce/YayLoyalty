<?php
namespace YayLoyalty\Enums;

defined( 'ABSPATH' ) || exit;

/** How customers qualify for a tier: on every point earned, or on the points earned in a rolling window. */
final class TierMode {

    use StringEnumMethods;

    /** Every point earned counts, forever. */
    public const LIFETIME = 'lifetime';

    /** Only the points earned in the last N months count, with a hold on the tier reached. */
    public const ROLLING = 'rolling';

    /** The window lengths, in months, a merchant may pick for rolling mode. */
    public const ROLLING_MONTHS = [ 3, 6, 9, 12, 18, 24 ];

    public const DEFAULT_ROLLING_MONTHS = 6;

    /** @return string[] */
    public static function all(): array {
        return [ self::LIFETIME, self::ROLLING ];
    }

    /** Whether $value is one of ROLLING_MONTHS, as an int or a whole-number string. */
    public static function is_rolling_months( $value ): bool {
        return is_numeric( $value ) && (string) (int) $value === (string) $value && in_array( (int) $value, self::ROLLING_MONTHS, true );
    }

    protected static function noun(): string {
        return 'tier mode';
    }
}
