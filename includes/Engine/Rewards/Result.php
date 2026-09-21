<?php
namespace YayLoyalty\Engine\Rewards;

use YayLoyalty\Models\Reward;

defined( 'ABSPATH' ) || exit;

/**
 * Whether a claim may go ahead and, if so, what it costs and what it is
 * worth. `reason` is '' when allowed, else a REASON_* constant (handlers may
 * add their own strings). `rate_snapshot` is points per currency unit at
 * quote time, kept so a refund reverses at the quoted rate rather than
 * today's. The caller writes the ledger from this; the engine writes nothing.
 */
final class Result {

    public const REASON_NOT_LIVE             = 'not_live';
    public const REASON_INSUFFICIENT_BALANCE = 'insufficient_balance';
    public const REASON_CONDITIONS_NOT_MET   = 'conditions_not_met';
    public const REASON_NOT_IMPLEMENTED      = 'not_implemented';

    /** @var bool */
    private $allowed;

    /** @var Reward|null */
    private $reward;

    /** @var int Points the claim costs. */
    private $points;

    /** @var float Discount value in `currency`. */
    private $value;

    /** @var string|null */
    private $currency;

    /** @var float */
    private $rate_snapshot;

    /** @var string */
    private $reason;

    private function __construct(
        bool $allowed,
        ?Reward $reward,
        int $points,
        float $value,
        ?string $currency,
        float $rate_snapshot,
        string $reason
    ) {
        $this->allowed       = $allowed;
        $this->reward        = $reward;
        $this->points        = $points;
        $this->value         = $value;
        $this->currency      = $currency;
        $this->rate_snapshot = $rate_snapshot;
        $this->reason        = $reason;
    }

    public static function allowed( Reward $reward, int $points, float $value, string $currency, float $rate_snapshot ): self {
        return new self( true, $reward, $points, $value, $currency, $rate_snapshot, '' );
    }

    public static function denied( string $reason ): self {
        return new self( false, null, 0, 0.0, null, 0.0, $reason );
    }

    public function is_allowed(): bool {
        return $this->allowed;
    }

    public function get_reward(): ?Reward {
        return $this->reward;
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

    public function get_reason(): string {
        return $this->reason;
    }
}
