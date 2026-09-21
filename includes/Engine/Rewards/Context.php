<?php
namespace YayLoyalty\Engine\Rewards;

defined( 'ABSPATH' ) || exit;

/**
 * Everything the engine may know about a claim. Immutable, WordPress-free,
 * so the engine is testable without a bootstrap. The balance is a caller
 * input, not a lookup: the engine never reads the ledger. No actor_id: who
 * is logged in is a ledger argument, not a scoring input.
 */
final class Context {

    /** @var int|null Null for a guest previewing. */
    private $user_id;

    /** @var int Spendable points right now. */
    private $balance;

    /** @var int|null How many points the customer chose to spend; flexible_discount only, null for fixed-cost types. */
    private $points_requested;

    /** @var float|null Cart subtotal in `currency`; null for a catalog claim with no cart. */
    private $cart_subtotal;

    /** @var string Checkout currency, e.g. 'EUR'. */
    private $currency;

    /** @var string UTC MySQL datetime the claim is evaluated at. */
    private $at;

    public function __construct(
        ?int $user_id,
        int $balance,
        ?int $points_requested,
        ?float $cart_subtotal,
        string $currency,
        string $at
    ) {
        $this->user_id          = $user_id;
        $this->balance          = $balance;
        $this->points_requested = $points_requested;
        $this->cart_subtotal    = $cart_subtotal;
        $this->currency         = $currency;
        $this->at               = $at;
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
}
