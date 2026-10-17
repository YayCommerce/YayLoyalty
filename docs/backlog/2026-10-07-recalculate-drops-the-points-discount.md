# Recalculating an order in wp-admin drops its points discount

**Purpose:** a follow-up left open by a review or a change, kept until every box under Done when is ticked.

**Update when:** a Done when condition is met (tick its box), the code it describes changes, or the suggested fix changes. Once every box is ticked, the item is done: remove the file.

**Source:** planning of the slider redemption backend on 2026-10-07 (`docs/plans/2026-10-07-redemptions-slider-backend.md`). Jason chose to track it here instead of widening that plan.

## Today

The points discount is a virtual coupon, `yayloyalty-redeem`. `Engine\Redemption\CouponProvider::virtual_coupon_data()` answers WooCommerce's `woocommerce_get_shop_coupon_data` filter with an amount quoted from the shopper's cart. When a merchant presses Recalculate on an order, `WC_Abstract_Order::recalculate_coupons()` builds a `WC_Coupon` for each coupon line. No cart exists in wp-admin, so the provider returns nothing and WooCommerce finds no such coupon.

## Why it matters

The order loses its discount while the points stay debited: the redemption row and the `redeem` ledger row are untouched. The customer pays for the discount twice. Nothing can reach this until checkout redemption is switched on for shoppers (`RedemptionService::is_available()`, spec task 3).

## Suggested fix

During an order recalculation, serve the coupon's amount from the value frozen on the order, `_yayloyalty_redeem_value` (`CommitWriter::META_VALUE`), instead of from a cart quote. The filter is not told which order is being recalculated, so the provider needs that from a hook that brackets the recalculation, for example `woocommerce_order_before_calculate_totals`.

## Done when

- [ ] Pressing Recalculate on an order with a points discount keeps the `yayloyalty-redeem` line at the amount in `_yayloyalty_redeem_value`.
- [ ] The order's redemption row and ledger rows are unchanged by the recalculation.
- [ ] Adding or removing another coupon on that order in wp-admin leaves the points discount at the same amount.
