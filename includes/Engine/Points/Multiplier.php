<?php
namespace YayLoyalty\Engine\Points;

use YayLoyalty\Utils\Log;

defined( 'ABSPATH' ) || exit;

/**
 * The factor PointsEngine::evaluate() multiplies a campaign's points by, and the tier that set it.
 * Immutable. The factor is a decimal string with two places ("1.25"), as the ledger stores it, and
 * the math runs in integer hundredths: as a float, 100 × 1.15 floors to 114. Internal seam, not a
 * public API.
 */
final class Multiplier {

    private const NEUTRAL = '1.00';

    /** The factor must have one or two integer digits and exactly two decimals. */
    private const FORMAT = '/^\d{1,2}\.\d{2}$/';

    private const MIN_HUNDREDTHS = 100;
    private const MAX_HUNDREDTHS = 1000;

    /** @var string */
    private $factor;

    /** @var int|null */
    private $tier_id;

    public function __construct( string $factor, ?int $tier_id ) {
        $this->factor  = $factor;
        $this->tier_id = $tier_id;
    }

    /** The factor that leaves points unchanged, set by no tier. */
    public static function none(): self {
        return new self( self::NEUTRAL, null );
    }

    /**
     * The filter's answer when it is a Multiplier from 1.00 to 10.00, else none(). Logs every
     * rejected answer, so a broken subscriber is visible without changing what anyone earns.
     *
     * @param mixed $value What yayloyalty_points_multiplier returned.
     */
    public static function from_filter( $value ): self {
        if ( $value instanceof self && $value->is_valid() ) {
            return $value;
        }

        Log::warning(
            'PointsEngine',
            sprintf(
                'Ignored a points multiplier that is not a Multiplier from 1.00 to 10.00: %s.',
                $value instanceof self ? $value->get_factor() : gettype( $value )
            )
        );

        return self::none();
    }

    public function get_factor(): string {
        return $this->factor;
    }

    public function get_tier_id(): ?int {
        return $this->tier_id;
    }

    public function is_neutral(): bool {
        return self::NEUTRAL === $this->factor;
    }

    /** The points times the factor, floored. */
    public function apply( int $points ): int {
        return intdiv( $points * $this->hundredths(), 100 );
    }

    private function is_valid(): bool {
        if ( 1 !== preg_match( self::FORMAT, $this->factor ) ) {
            return false;
        }

        $hundredths = $this->hundredths();

        return $hundredths >= self::MIN_HUNDREDTHS && $hundredths <= self::MAX_HUNDREDTHS;
    }

    /** "1.25" → 125. Only meaningful once the factor matches FORMAT. */
    private function hundredths(): int {
        return (int) str_replace( '.', '', $this->factor );
    }
}
