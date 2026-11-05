<?php
namespace YayLoyalty\Engine\Rewards;

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

    /** @var float|null What a discount may take, in `currency`: the items net of every other coupon; null for a catalog claim with no cart. */
    private $discountable_amount;

    /** @var string Checkout currency, e.g. 'EUR'. */
    private $currency;

    /** @var string UTC MySQL datetime the claim is evaluated at. */
    private $at;

    /** @var RewardFacts|null Read once by ContextFactory for the conditions gate (tier, lifetime points); null for a guest. */
    private $facts;

    /** @var \WC_Cart|null The cart a coupon-describing handler dry-runs against; null at commit and for a catalog claim. */
    private $cart;

    /** @var float Units of `currency` per 1 base unit (CurrencyRate's direction); 1.0 in the base currency. */
    private $currency_rate;

    public function __construct(
        ?int $user_id,
        int $balance,
        ?int $points_requested,
        ?float $discountable_amount,
        string $currency,
        string $at,
        ?RewardFacts $facts = null,
        ?\WC_Cart $cart = null,
        float $currency_rate = 1.0
    ) {
        $this->user_id             = $user_id;
        $this->balance             = $balance;
        $this->points_requested    = $points_requested;
        $this->discountable_amount = $discountable_amount;
        $this->currency            = $currency;
        $this->at                  = $at;
        $this->facts               = $facts;
        $this->cart                = $cart;
        $this->currency_rate       = $currency_rate;
    }

    /** A copy at $balance: what a reward quotes against once earlier rewards in the selection are paid for. */
    public function with_balance( int $balance ): self {
        $copy          = clone $this;
        $copy->balance = $balance;

        return $copy;
    }

    /** A copy at $discountable_amount: what the points discount quotes against once the picked rewards take their share. */
    public function with_discountable_amount( float $discountable_amount ): self {
        $copy                      = clone $this;
        $copy->discountable_amount = $discountable_amount;

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

    public function get_discountable_amount(): ?float {
        return $this->discountable_amount;
    }

    public function get_currency(): string {
        return $this->currency;
    }

    public function get_at(): string {
        return $this->at;
    }

    public function get_facts(): ?RewardFacts {
        return $this->facts;
    }

    public function get_cart(): ?\WC_Cart {
        return $this->cart;
    }

    public function get_currency_rate(): float {
        return $this->currency_rate;
    }
}
