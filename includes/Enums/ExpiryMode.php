<?php
namespace YayLoyalty\Enums;

defined( 'ABSPATH' ) || exit;

/** How points expire. all() is what this build runs; all_known() is what settings keep, so Pro data survives Lite. */
final class ExpiryMode {

    use StringEnumMethods;

    /** The whole balance expires after a period with no earn or redeem. */
    public const INACTIVITY = 'inactivity';

    /** Each lot expires a set number of months after it was earned. */
    public const BATCH = 'batch';

    /** @return string[] */
    public static function all(): array {
        /**
         * Expiry modes this build can run. Pro adds batch.
         *
         * @param string[] $modes
         */
        return apply_filters( 'yayloyalty_expiry_modes', [ self::INACTIVITY ] );
    }

    /** @return string[] */
    public static function all_known(): array {
        return [ self::INACTIVITY, self::BATCH ];
    }

    protected static function noun(): string {
        return 'expiry mode';
    }
}
