<?php
namespace YayLoyalty\Engine\Rewards;

use YayLoyalty\Engine\Points\CustomerFacts;

defined( 'ABSPATH' ) || exit;

/**
 * Everything the engine and the gates may know about a claim. Immutable and WordPress-free.
 * The balance is a caller input, because the engine never reads the ledger.
 */
final class Context {

    /** @var int|null Null for a guest previewing. */
    private $user_id;

    /** @var int Spendable points right now. */
    private $balance;

    /** @var int|null How many points the customer chose to spend; flexible_discount only, null for fixed-cost types. */
    private $points_requested;

    /** @var float|null Cart subtotal in `currency`, net of every other coupon; null for a catalog claim with no cart. */
    private $cart_subtotal;

    /** @var string Checkout currency, e.g. 'EUR'. */
    private $currency;

    /** @var string UTC MySQL datetime the claim is evaluated at. */
    private $at;

    /** @var CustomerFacts|null Read once by ContextFactory for the conditions gate (tier, lifetime points); null for a guest. */
    private $facts;

    public function __construct(
        ?int $user_id,
        int $balance,
        ?int $points_requested,
        ?float $cart_subtotal,
        string $currency,
        string $at,
        ?CustomerFacts $facts = null
    ) {
        $this->user_id          = $user_id;
        $this->balance          = $balance;
        $this->points_requested = $points_requested;
        $this->cart_subtotal    = $cart_subtotal;
        $this->currency         = $currency;
        $this->at               = $at;
        $this->facts            = $facts;
    }

    public function get_user_id(): ?int {
        return $this->user_id;
    }

    public function get_balance(): int {
        return $this->balance;
    }

    public function get_points_requested(): ?int {
        return $this->points_requested;
    }

    public function get_cart_subtotal(): ?float {
        return $this->cart_subtotal;
    }

    public function get_currency(): string {
        return $this->currency;
    }

    public function get_at(): string {
        return $this->at;
    }

    public function get_facts(): ?CustomerFacts {
        return $this->facts;
    }
}
