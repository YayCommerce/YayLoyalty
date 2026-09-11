<?php
namespace YayLoyalty\Engine\Points;

defined( 'ABSPATH' ) || exit;

/**
 * One purchasable line of the order, cart or product being scored.
 *
 * Shipping is deliberately not a line item, which is how it stays out of the
 * earning base without a flag. Amounts are in the ORDER currency; the Context
 * carries the rate to base currency.
 */
final class ContextLineItem {

    /** @var int */
    private $product_id;

    /** @var int|null */
    private $variation_id;

    /** @var int[] Resolved from the parent product, not the variation. */
    private $category_ids;

    /** @var int */
    private $quantity;

    /** @var float Pre-discount line total. */
    private $subtotal;

    /** @var float Post-discount line total. */
    private $total;

    /** @var float */
    private $tax_total;

    /**
     * @param int[] $category_ids
     */
    public function __construct(
        int $product_id,
        ?int $variation_id,
        array $category_ids,
        int $quantity,
        float $subtotal,
        float $total,
        float $tax_total = 0.0
    ) {
        $this->product_id   = $product_id;
        $this->variation_id = $variation_id;
        $this->category_ids = array_values( array_map( 'intval', $category_ids ) );
        $this->quantity     = $quantity;
        $this->subtotal     = $subtotal;
        $this->total        = $total;
        $this->tax_total    = $tax_total;
    }

    public function get_product_id(): int {
        return $this->product_id;
    }

    public function get_variation_id(): ?int {
        return $this->variation_id;
    }

    /** @return int[] */
    public function get_category_ids(): array {
        return $this->category_ids;
    }

    public function get_quantity(): int {
        return $this->quantity;
    }

    public function get_subtotal(): float {
        return $this->subtotal;
    }

    public function get_total(): float {
        return $this->total;
    }

    public function get_tax_total(): float {
        return $this->tax_total;
    }
}
