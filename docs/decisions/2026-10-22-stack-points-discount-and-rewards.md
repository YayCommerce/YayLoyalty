# Loyalty discounts are virtual coupons, the points discount and rewards stack, and each reward has its own ledger key

**Purpose:** why fixed and percentage rewards reach the cart as one virtual coupon each, why they stack with the points slider on one cart and one order, and why every redemption ledger key carries a per-row qualifier.

**Update when:** never edit it. Supersede it with a new record if rewards are issued as stored coupons, the slider and rewards stop combining or combine in another order, or the ledger key changes shape.

## Status

Accepted, 2026-10-22, by PhungTruong1997, with the redeem card v2 design. It supersedes [loyalty discounts are virtual coupons](2026-10-14-loyalty-discounts-as-virtual-coupons.md) whole.

## Context

- The slider spends points as the virtual coupon `yayloyalty-redeem`, built from `woocommerce_get_shop_coupon_data`, with no `shop_coupon` post. Pro's `fixed_coupon` and `percent_coupon` rewards carry WooCommerce coupon rules: a percent or a fixed amount, a minimum spend, a sale-item exclusion and, for a percentage, a cap. Refunds need each reward's discount on its own line to return its points.
- [Lite owns every redemption write](2026-10-07-lite-owns-redemption-writes.md), and [Lite is Pro without `includes/Pro/`](2026-10-01-build-time-pro-lite-split.md), so the reward rows cannot live in Lite.
- The ledger's idempotency key used to end in the reward type, `flexible_discount`, one per order. Two rewards on one order, or two of their refunds under one refund id, would have collided.
- The previous record kept the slider and rewards apart: the session held one kind, and an order carrying both was refused at commit. Shoppers could not use a reward and spend the rest of their balance on the same order, which the redeem card v2 design asks for: one list where the Points discount row and any rewards are ticked together.
- `ContextFactory::from_order()` already nets every non-slider coupon line out of the slider's base, and `Reversal` already settles each redemption row under its own key, so an order with both kinds reverses without change.
- Options turned down:
  - **Issuing a `shop_coupon` per claim.** The shopper applies rewards on the cart directly; a stored coupon adds a claim step, an expiry and an `issued`/`used` lifecycle this version doesn't need.
  - **One combined virtual coupon for all lines.** It re-implements percent ordering, the minimum spend, the sale exclusion and the per-reward split that refunds need.
  - **Negative cart fees.** Their tax handling is odd, they ignore the sale and minimum rules, and WooCommerce discourages them.
  - **Committing a stacked order as two existing commits back to back.** A failure after the first transaction leaves the order half debited with no clean rollback.
  - **Quoting the slider first and the rewards after.** It needs new coupon weights and rewards re-quoted after the slider, and rewards would lose their priority.
  - **Keeping the two exclusive.** The design asked for both on one order.

## Decision

- **Virtual coupons.** Each applied reward is its own virtual coupon, `yayloyalty-reward-{id}`, described by its type's handler (`RewardHandler::coupon_props()`) and built by Lite's `RewardCoupons`. WooCommerce does the discount math, the minimum spend, the sale exclusion, the tax split and one totals row and order coupon line per reward. No `shop_coupon` post is created. A percentage reward whose discount would pass its cap becomes a fixed coupon at the cap.
- **Stacking.** `SessionIntent` holds slider points and reward ids together. Rewards are quoted against the full balance; the slider is quoted against what the allowed rewards leave, the balance minus their cost and the cart minus their value, inside `RedemptionService::quote()` and `::ceiling()`, so the card, the cart sync, the Store API data and checkout validation agree. The slider's coupon still applies after every other coupon. An order carrying both kinds is debited by `CommitWriter::commit_all()` in one transaction under one customer lock: every line is written, or none is. Merchant coupons stack with both.
- **Per-reward key.** A redemption ledger key ends in a qualifier per redemption row: `flexible_discount` for the slider and `reward_{id}` for a reward. The debit, every restore and every re-debit of a reward row use it.
- **Where the code lives.** Lite owns the session, the coupons, the commit and the reversal. Pro registers the two handlers and adds one row per reward to the card's list through `yayloyalty_redeem_card_rows`.

## Consequences

- Every reward gets WooCommerce's coupon behaviour without the plugin re-implementing it, and its discount sits on its own order line, which the commit and the reversal read.
- A shopper can tick rewards and spend the rest of the balance on the same order. The slider shrinks, or applies nothing with the balance note, when ticked rewards take what it had; checkout still passes while the rewards are covered.
- Every slider quote now dry-runs the picked rewards first. `quote_rewards()` is memoised per request so a totals run pays for it once.
- `commit_all()` adds a third commit path beside `commit()` and `commit_rewards()`; their per-line checks are shared helpers, not copies.
- Slider ledger keys are unchanged, so no migration is needed.
- A reward that gives less than its face value on a cart still costs its full points; the card says what it gives before it is ticked.
- On a downgrade to Lite, picked rewards drop from the session and the slider re-clamps up; orders placed under Pro still reverse.
