# Loyalty discounts are virtual coupons, rewards exclude the slider, and each reward has its own ledger key

**Purpose:** why fixed and percentage rewards reach the cart as one virtual coupon each, why they never meet the points slider on one order, and why every redemption ledger key carries a per-row qualifier.

**Update when:** never edit it. Supersede it with a new record if rewards are issued as stored coupons, the slider and rewards may combine, or the ledger key changes shape.

## Status

Accepted, 2026-10-14, by Jason, with the reward redemption design.

## Context

- Pro's `fixed_coupon` and `percent_coupon` rewards had a config and an editor but no way to be spent. The slider already spends points as the virtual coupon `yayloyalty-redeem`, built from `woocommerce_get_shop_coupon_data`, with no `shop_coupon` post.
- A reward carries WooCommerce coupon rules: a percent or a fixed amount, a minimum spend, a sale-item exclusion and, for a percentage, a cap. Refunds need each reward's discount on its own line to return its points.
- [Lite owns every redemption write](2026-10-07-lite-owns-redemption-writes.md), and [Lite is Pro without `includes/Pro/`](2026-10-01-build-time-pro-lite-split.md), so the Rewards tab cannot live in Lite.
- The ledger's idempotency key ended in the reward type, `flexible_discount`, one per order. Two rewards on one order, or two of their refunds under one refund id, would have collided.
- Options turned down:
  - **Issuing a `shop_coupon` per claim.** The shopper wanted to apply rewards on the cart directly; a stored coupon adds a claim step, an expiry and an `issued`/`used` lifecycle this version doesn't need.
  - **One combined virtual coupon for all rewards.** It re-implements percent ordering, the minimum spend, the sale exclusion and the per-reward split that refunds need.
  - **Negative cart fees.** Their tax handling is odd, they ignore the sale and minimum rules, and WooCommerce discourages them.

## Decision

- **Virtual coupons.** Each applied reward is its own virtual coupon, `yayloyalty-reward-{id}`, described by its type's handler (`RewardHandler::coupon_props()`) and built by Lite's `RewardCoupons`. WooCommerce does the discount math, the minimum spend, the sale exclusion, the tax split and one totals row and order coupon line per reward. No `shop_coupon` post is created. A percentage reward whose discount would pass its cap becomes a fixed coupon at the cap.
- **Exclusivity.** The session holds slider points or reward ids, never both. Asking for one takes the other off, and an order that carries both is refused at commit. Merchant coupons still stack with either.
- **Per-reward key.** A redemption ledger key ends in a qualifier per redemption row: `flexible_discount` for the slider, unchanged, and `reward_{id}` for a reward. The debit, every restore and every re-debit of a reward row use it.
- **Where the code lives.** Lite owns the session, the coupons, the commit and the reversal. Pro registers the two handlers and adds the Rewards tab through `yayloyalty_redeem_card_panels`.

## Consequences

- Every reward gets WooCommerce's coupon behaviour without the plugin re-implementing it, and its discount sits on its own order line, which the commit and the reversal read.
- Existing slider ledger keys are unchanged, so no migration is needed.
- A reward that gives less than its face value on a cart still costs its full points; the card says what it gives before Apply.
- On a downgrade to Lite, picked rewards drop from the session, and orders placed under Pro still reverse.
- The slider and rewards can't be combined on one order, even where a store might want it.
