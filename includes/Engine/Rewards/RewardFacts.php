<?php
namespace YayLoyalty\Engine\Rewards;

defined( 'ABSPATH' ) || exit;

/**
 * What the reward conditions gate knows about the customer, read once per claim Context by
 * ContextFactory::facts_for() from the customer row and the tiers table. Immutable.
 * Internal seam, not a public API.
 *
 * Lighter than Engine\Points\CustomerFacts on purpose: that one loads every paid order,
 * and this is read on every cart quote.
 */
final class RewardFacts {

    /** @var int The customer row's lifetime_earned; 0 without a row. */
    private $lifetime_earned;

    /** @var int|null The stored tier id, live or not; null when tierless or without a row. */
    private $tier_id;

    /** @var int The live tier's rank, or 0 when the customer has no live tier. */
    private $tier_rank;

    public function __construct( int $lifetime_earned, ?int $tier_id, int $tier_rank ) {
        $this->lifetime_earned = $lifetime_earned;
        $this->tier_id         = $tier_id;
        $this->tier_rank       = $tier_rank;
    }

    public function get_lifetime_earned(): int {
        return $this->lifetime_earned;
    }

    public function get_tier_id(): ?int {
        return $this->tier_id;
    }

    public function get_tier_rank(): int {
        return $this->tier_rank;
    }
}
