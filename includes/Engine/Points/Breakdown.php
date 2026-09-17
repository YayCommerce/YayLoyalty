<?php
namespace YayLoyalty\Engine\Points;

use YayLoyalty\Utils\PointsMath;

defined( 'ABSPATH' ) || exit;

/**
 * How a handler arrived at its points: the admin's "why did this order earn?".
 *
 * INTERNAL MERCHANT STRATEGY. Carries the rate and the raw float; the admin
 * preview may render all of it, the storefront must expose the integer only.
 */
final class Breakdown {

    /** @var int */
    private $points;

    /** @var float Base-currency amount earned on; 0.0 for flat triggers. */
    private $base_amount;

    /** @var float Points per base-currency unit; 0.0 for flat triggers. */
    private $rate;

    /** @var float Before rounding. */
    private $raw_points;

    /** @var string 'down' | 'nearest' | 'up' | 'none'. */
    private $rounding;

    /** @var string[] */
    private $notes;

    /**
     * @param string[] $notes
     */
    public function __construct( int $points, float $base_amount, float $rate, float $raw_points, string $rounding, array $notes = [] ) {
        $this->points      = $points;
        $this->base_amount = $base_amount;
        $this->rate        = $rate;
        $this->raw_points  = $raw_points;
        $this->rounding    = $rounding;
        $this->notes       = $notes;
    }

    /** A flat award: no base, no rate, nothing rounded. */
    public static function flat( int $points, array $notes = [] ): self {
        return new self( $points, 0.0, 0.0, (float) $points, PointsMath::ROUND_NONE, $notes );
    }

    public function get_points(): int {
        return $this->points;
    }

    public function get_base_amount(): float {
        return $this->base_amount;
    }

    public function get_rate(): float {
        return $this->rate;
    }

    public function get_raw_points(): float {
        return $this->raw_points;
    }

    public function get_rounding(): string {
        return $this->rounding;
    }

    /** @return string[] */
    public function get_notes(): array {
        return $this->notes;
    }

    /** @return array<string, mixed> */
    public function to_array(): array {
        return [
            'points'      => $this->points,
            'base_amount' => $this->base_amount,
            'rate'        => $this->rate,
            'raw_points'  => $this->raw_points,
            'rounding'    => $this->rounding,
            'notes'       => $this->notes,
        ];
    }
}
