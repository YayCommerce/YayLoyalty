<?php
namespace YayLoyalty\Engine\Points;

defined( 'ABSPATH' ) || exit;

/**
 * What a campaign condition may know about the customer, read once by
 * ContextFactory before evaluation so the evaluator compares values and
 * queries nothing. Immutable. Absent (null on the Context) for a guest.
 * Roles are on the Context itself; the order's own facts (currency,
 * coupons, lines) are too.
 */
final class CustomerFacts {

    private int $user_id;

    /** lifetime_earned, not balance: spending must not drop a customer below a threshold. */
    private int $lifetime_points;

    private int $order_count;

    /** @var array<string, float> Keyed by currency code; no conversion. */
    private array $spend_by_currency;

    /** @var int[] Parents, never variations. */
    private array $purchased_product_ids;

    /** @var int[] */
    private array $purchased_category_ids;

    private ?int $tier_id;

    /**
     * @param array<string, float> $spend_by_currency
     * @param int[]                $purchased_product_ids
     * @param int[]                $purchased_category_ids
     */
    public function __construct(
        int $user_id,
        int $lifetime_points,
        int $order_count,
        array $spend_by_currency,
        array $purchased_product_ids,
        array $purchased_category_ids,
        ?int $tier_id = null
    ) {
        $this->user_id                = $user_id;
        $this->lifetime_points        = $lifetime_points;
        $this->order_count            = $order_count;
        $this->spend_by_currency      = $spend_by_currency;
        $this->purchased_product_ids  = array_values( array_map( 'intval', $purchased_product_ids ) );
        $this->purchased_category_ids = array_values( array_map( 'intval', $purchased_category_ids ) );
        $this->tier_id                = $tier_id;
    }

    public function get_user_id(): int {
        return $this->user_id;
    }

    public function get_lifetime_points(): int {
        return $this->lifetime_points;
    }

    public function get_order_count(): int {
        return $this->order_count;
    }

    /** 0.0 for a currency the customer never paid in. */
    public function get_spend_in( string $currency ): float {
        return (float) ( $this->spend_by_currency[ $currency ] ?? 0.0 );
    }

    /** @return int[] */
    public function get_purchased_product_ids(): array {
        return $this->purchased_product_ids;
    }

    /** @return int[] */
    public function get_purchased_category_ids(): array {
        return $this->purchased_category_ids;
    }

    public function get_tier_id(): ?int {
        return $this->tier_id;
    }
}
