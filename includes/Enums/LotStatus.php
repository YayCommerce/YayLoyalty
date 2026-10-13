<?php
namespace YayLoyalty\Enums;

defined( 'ABSPATH' ) || exit;

/** A lot's state. Only an open lot counts toward the balance; an emptied one records what emptied it. */
final class LotStatus {

    use StringEnumMethods;

    /** Has points left. */
    public const OPEN = 'open';

    /** Emptied by spending: a redemption, deduction or clawback. */
    public const CONSUMED = 'consumed';

    /** Emptied by an EXPIRE row. */
    public const EXPIRED = 'expired';

    /** @return string[] */
    public static function all(): array {
        return [ self::OPEN, self::CONSUMED, self::EXPIRED ];
    }

    protected static function noun(): string {
        return 'lot status';
    }
}
