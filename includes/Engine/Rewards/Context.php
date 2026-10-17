<?php
namespace YayLoyalty\Engine\Rewards;

use YayLoyalty\Engine\Points\CustomerFacts;

defined( 'ABSPATH' ) || exit;

/**
 * Everything the engine and the gates may know about a claim. Immutable and free of WordPress calls; it may
 * carry the cart it was built from. The balance is a caller input, because the engine never reads the ledger.
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

    /** @var \WC_Cart|null The cart a coupon-describing handler dry-runs against; null at commit and for a catalog claim. */
    private $cart;

    public function __construct(
        ?int $user_id,
        int $balance,
        ?int $points_requested,
        ?float $cart_subtotal,
        string $currency,
        string $at,
        ?CustomerFacts $facts = null,
        ?\WC_Cart $cart = null
    ) {
        $this->user_id          = $user_id;
        $this->balance          = $balance;
        $this->points_requested = $points_requested;
        $this->cart_subtotal    = $cart_subtotal;
        $this->currency         = $currency;
        $this->at               = $at;
        $this->facts            = $facts;
        $this->cart             = $cart;
    }

    /** A copy at $balance: what a reward quotes against once earlier rewards in the selection are paid for. */
    public function with_balance( int $balance ): self {
        $copy          = clone $this;
        $copy->balance = $balance;

        return $copy;
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

    public function get_cart(): ?\WC_Cart {
        return $this->cart;
    }
}
