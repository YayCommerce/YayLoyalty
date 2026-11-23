<?php
namespace YayLoyalty\Engine\Points;

use YayLoyalty\Models\Campaign;

defined( 'ABSPATH' ) || exit;

/**
 * What an event earns and which campaign paid. `reason` is '' when awarded,
 * else a REASON_* constant: the admin must tell "no campaign" from "none
 * applied" from "rounded to 0". The caller writes the ledger from it.
 */
final class Result {

    public const REASON_NO_CAMPAIGNS = 'no_campaigns';
    public const REASON_NOT_ELIGIBLE = 'not_eligible';
    public const REASON_ZERO_POINTS  = 'zero_points';

    /** @var bool */
    private $awarded;

    /** @var int */
    private $points;

    /** @var Campaign|null */
    private $campaign;

    /** @var Breakdown|null */
    private $breakdown;

    /** @var string|null */
    private $reference_type;

    /** @var int|null */
    private $reference_id;

    /** @var string|null */
    private $source;

    /** @var string */
    private $reason;

    /** @var Multiplier|null Set only by awarded(). */
    private $multiplier;

    private function __construct(
        bool $awarded,
        int $points,
        ?Campaign $campaign,
        ?Breakdown $breakdown,
        ?string $reference_type,
        ?int $reference_id,
        ?string $source,
        string $reason
    ) {
        $this->awarded        = $awarded;
        $this->points         = $points;
        $this->campaign       = $campaign;
        $this->breakdown      = $breakdown;
        $this->reference_type = $reference_type;
        $this->reference_id   = $reference_id;
        $this->source         = $source;
        $this->reason         = $reason;
    }

    public static function awarded(
        int $points,
        Campaign $campaign,
        Breakdown $breakdown,
        string $reference_type,
        ?int $reference_id,
        string $source,
        ?Multiplier $multiplier = null
    ): self {
        $result             = new self( true, $points, $campaign, $breakdown, $reference_type, $reference_id, $source, '' );
        $result->multiplier = $multiplier;

        return $result;
    }

    public static function none( string $reason ): self {
        return new self( false, 0, null, null, null, null, null, $reason );
    }

    public function is_awarded(): bool {
        return $this->awarded;
    }

    public function get_points(): int {
        return $this->points;
    }

    public function get_campaign(): ?Campaign {
        return $this->campaign;
    }

    public function get_breakdown(): ?Breakdown {
        return $this->breakdown;
    }

    public function get_reference_type(): ?string {
        return $this->reference_type;
    }

    public function get_reference_id(): ?int {
        return $this->reference_id;
    }

    public function get_source(): ?string {
        return $this->source;
    }

    public function get_reason(): string {
        return $this->reason;
    }

    /** The multiplier the points include; none() when nothing multiplied them. */
    public function get_multiplier(): Multiplier {
        return $this->multiplier ?? Multiplier::none();
    }
}
