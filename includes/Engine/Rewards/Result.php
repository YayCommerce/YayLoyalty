<?php
namespace YayLoyalty\Engine\Rewards;

use YayLoyalty\Models\Reward;

defined( 'ABSPATH' ) || exit;

/**
 * The quote: whether a claim may go ahead, what the customer asked for and gets, its worth and which
 * limit bound. `reason` is '' when allowed, else a REASON_* constant or a gate's or handler's own string.
 * `rate_snapshot` is the quoted rate, which CommitWriter stores on the redemption row as a record. Nothing
 * reads it back: Reversal works from the row's points.
 */
final class Result {

    public const REASON_NOT_LIVE             = 'not_live';
    public const REASON_INSUFFICIENT_BALANCE = 'insufficient_balance';
    public const REASON_CONDITIONS_NOT_MET   = 'conditions_not_met';
    public const REASON_NO_RATE              = 'no_rate';

    private bool $allowed;

    private ?Reward $reward;

    /** Points the customer asked to spend; equals `points` for fixed-cost types. */
    private int $points_requested;

    /** Points the claim costs after every limit. */
    private int $points;

    /** Discount value in `currency`. */
    private float $value;

    private ?string $currency;

    private float $rate_snapshot;

    /** A Clamp::BOUND_* constant, '' when the request stood or for fixed-cost types. */
    private string $bound_by;

    private string $reason;

    private function __construct(
        bool $allowed,
        ?Reward $reward,
        int $points_requested,
        int $points,
        float $value,
        ?string $currency,
        float $rate_snapshot,
        string $bound_by,
        string $reason
    ) {
        $this->allowed          = $allowed;
        $this->reward           = $reward;
        $this->points_requested = $points_requested;
        $this->points           = $points;
        $this->value            = $value;
        $this->currency         = $currency;
        $this->rate_snapshot    = $rate_snapshot;
        $this->bound_by         = $bound_by;
        $this->reason           = $reason;
    }

    /** Allowed with `points` 0 is a valid quote (nothing to apply yet); a coupon needs `points` > 0. */
    public static function allowed(
        Reward $reward,
        int $points_requested,
        int $points,
        float $value,
        string $currency,
        float $rate_snapshot,
        string $bound_by = Clamp::BOUND_NONE
    ): self {
        return new self( true, $reward, $points_requested, $points, $value, $currency, $rate_snapshot, $bound_by, '' );
    }

    public static function denied( string $reason ): self {
        return new self( false, null, 0, 0, 0.0, null, 0.0, Clamp::BOUND_NONE, $reason );
    }

    public function is_allowed(): bool {
        return $this->allowed;
    }

    public function get_reward(): ?Reward {
        return $this->reward;
    }

    public function get_points_requested(): int {
        return $this->points_requested;
    }

    public function get_points(): int {
        return $this->points;
    }

    public function get_value(): float {
        return $this->value;
    }

    public function get_currency(): ?string {
        return $this->currency;
    }

    public function get_rate_snapshot(): float {
        return $this->rate_snapshot;
    }

    public function get_bound_by(): string {
        return $this->bound_by;
    }

    public function get_reason(): string {
        return $this->reason;
    }
}
