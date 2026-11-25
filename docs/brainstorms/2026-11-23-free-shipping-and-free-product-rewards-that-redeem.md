---
id: BS-6
title: Free shipping and free product rewards that redeem
status: decided
from: R-7
decision: D. YayPricing's model: free cart line and zeroed rates, coupon as marker
created: 2026-11-23
---
# BS-6 Free shipping and free product rewards that redeem

## Problem
Pro merchants can create `free_shipping` and `free_product` rewards: the types and config schemas are in `includes/Pro/YayLoyaltyPro.php:98` and `:372`, and the editor is in `apps/admin/src/pro/rewards/editor/RewardEditorSections.tsx:138`. But only `fixed_coupon` and `percent_coupon` have a `RewardHandler` (`YayLoyaltyPro.php:319`). `RedemptionService::is_reward_offered()` (`includes/Engine/Redemption/RedemptionService.php:442`) needs a handler's `coupon_props()`, so shoppers never see these rewards, even though merchants could save them (known-issues.md). R-7 acceptance: a shopper redeems free shipping and shipping drops to zero, or redeems a free product and it is added at no cost, on classic and block checkout. A refund or cancel gives the points back.

## Constraints
- **Pro only.** The handlers attach through `yayloyalty_register_reward_handlers`. Lite keeps both types in `RewardType::all_known()` (`includes/Enums/RewardType.php:59`), so stored rows survive a downgrade (hard rules 1 and 6).
- **Every catalog reward is a virtual coupon** under its `coupon_code`, built by `RewardCoupons` and synced by `CouponProvider::sync_cart()` on `woocommerce_before_calculate_totals`. The commit, reversal, order screen and emails all key on that code.
- **The pipeline assumes the coupon line carries the value.**
  - `RedemptionService::applies()` (`:245`) needs `value > 0`.
  - `RewardCoupons::probe()` reads the value from `WC_Discounts::get_discounts_by_coupon()`.
  - `CommitWriter::requote_reward_lines()` (`includes/Engine/Redemption/CommitWriter.php:322`) fails the checkout when `line_discount_of()` is ≤ 0.
  - A WooCommerce coupon never discounts shipping, so free shipping can't fit this as it stands. Whichever option wins, Lite needs a small seam for where a reward's value comes from.
- **Reversal already works for any type.** `Reversal` (`includes/Engine/Redemption/Reversal.php:277`) restores points per redemption row, using `refund_policy` and the order total. It needs nothing new as long as a row is written.
- Classic checkout and block checkout (Store API) both run `woocommerce_before_calculate_totals` and `woocommerce_package_rates`. Multi-currency: `RewardCoupons::in_currency()` converts only `fixed_cart` amounts. A percent coupon needs no conversion.
- PHP 7.4. HPOS-safe order reads only.

## Options
### A. Native WooCommerce coupon flags
- **How:**
  - Free shipping: `coupon_props()` sets WooCommerce's own `free_shipping` flag on the reward's virtual coupon. `RewardCoupons::data()` hard-codes `free_shipping => false` today, so that changes.
  - Free product: a `percent` coupon at 100, limited to `product_ids` / `variation_id` and `limit_usage_to_x_items = qty`. The product is added to the cart when the reward is picked, or the coupon reuses a matching line that's already there.
- **Pros:** almost no new mechanics. WooCommerce does the maths, tax and display on both checkouts.
- **Cons:**
  - WooCommerce's free-shipping flag only works when the shipping zone has a *Free shipping* method set to "requires a valid free shipping coupon". Without that, the shopper spends points and nothing changes, which fails the acceptance on most stores unless setup is required and checked.
  - The coupon line is still worth 0, so `applies()` and the commit still need special-casing.
- **Risks:** support load from merchants whose zones aren't set up.
- **Editions:** Pro handlers; small Lite changes for the props and the zero-value line.
- **Size:** M

### B. The coupon marks the reward; YayLoyalty frees the shipping itself
- **How:**
  - Free product: as in A. A 100% `percent` coupon limited to the product and qty, so the value is a real coupon discount, and probe, commit and the proportional refund work unchanged.
  - Free shipping: the reward's virtual coupon stays on the cart at 0, only as a marker. A Pro filter on `woocommerce_package_rates` sets every rate (and its taxes) to 0 while the marker is applied, and keeps the original cost in rate meta. A `woocommerce_cart_shipping_packages` key busts WooCommerce's cached rates when the marker comes or goes.
  - The value is the original cost of the chosen rates. On the order it is read back from the shipping items' meta. A Lite seam on the handler (e.g. the value the order line is worth) lets `applies()` and `requote_reward_lines()` take that value instead of the coupon line's.
- **Pros:**
  - Works on every shipping method with no merchant setup.
  - The shopper sees shipping drop to 0 and the reward line named on cart, checkout, order and emails.
  - The free product reuses the existing coupon pipeline completely.
- **Cons:**
  - A new value seam in Lite's `RewardHandler`.
  - Free shipping has no value until rates are calculated (a cart before an address is entered).
  - The rate filter must coexist with other shipping plugins.
- **Risks:**
  - Rate caching, if the package key is missed.
  - Third-party rate plugins that run after us.
  - Local pickup or a virtual-only cart (worth 0, so denied with `no_value`, and the card needs copy for it).
- **Editions:** handlers and the rate filter in `includes/Pro/`; the value seam in Lite's `RewardHandler` / `RedemptionService` / `CommitWriter`.
- **Size:** M

### C. Cart mechanics without coupons
- **How:**
  - The free product is added as its own cart line with price 0 (cart item data `yayloyalty_reward_id`, priced in `woocommerce_before_calculate_totals`).
  - Free shipping is a rate filter as in B.
  - The redemption is tracked through the session and that line, not a coupon code.
- **Pros:** the free item shows as "Free" on its own line. No coupon row for shoppers to remove by mistake.
- **Cons:**
  - Everything that keys on loyalty codes needs a second path for non-coupon rewards: `sync_cart`, `commit` / `CommitWriter`, `StoreApiAdapter::applied_rewards`, the removal handling, the order screen and emails.
  - Proportional refunds lose the line value.
  - Easy to diverge between classic and block checkout.
- **Risks:** a duplicate redemption path next to the one that's tested. Rule 3 (ledger writes) gets more callers.
- **Editions:** Pro plus large Lite changes.
- **Size:** L

### D. YayPricing's model: a free cart line and zeroed rates, with the coupon as the marker
Modelled on YayPricing (`../YayPricing`), at the user's request.
- **Free product, as a YayPricing gift.** YayPricing rebuilds its gift lines on every pricing pass. It removes its `is_extra` items, adds each again with `WC()->cart->add_to_cart( …, [ 'is_extra' => true, … ] )` and `set_price( 0 )` (`includes/core/class-yaydp-cart.php:107-155`, cleanup in `includes/core/manager/class-yaydp-product-pricing-manager.php:115`). It also hides the line's remove link, locks the quantity box and shows the subtotal as 0 (`class-yaydp-product-pricing-manager.php:31-36`, `:272`).
  - Here, while the reward is picked, `CouponProvider::sync_cart()`'s pass adds **always a new line**: `product_id` / `variation_id`, `qty`, cart item data `yayloyalty_reward_id`, price 0. It never discounts a copy the shopper already has.
  - When the reward leaves the session the line is removed. Its quantity and remove link are locked, and the reward is removed from the redeem card, as in YayPricing.
  - The value is the product's price × `qty`, taken before it is set to 0.
- **Free shipping, as YayPricing's shipping rule.** YayPricing's `adjust_shipping()` on `woocommerce_shipping_packages` sets each rate's cost (and `WC_Tax::calc_shipping_tax` taxes), and records the amount because no fee line carries it (`includes/core/rule/checkout-fee/class-yaydp-shipping-fee.php:125-148`, hooked in `includes/core/single-adjustment/class-yaydp-checkout-fee-adjustment.php:117`).
  - Here a Pro filter does the same while the reward is picked. Every rate in every package goes to 0, and the original cost is kept in rate meta, so it reaches the order's shipping items.
- **The reward's virtual coupon stays as the marker** at 0, labelled with the reward name (`CouponProvider::label()`). So `commit()`, `CommitWriter`, `Reversal`, the order screen and emails keep keying on the code, and a redemption row is written as for any reward.
  - The value comes from a new Lite `RewardHandler` seam: the free line's original price, or the shipping items' original cost, instead of the coupon line's. `applies()` and `requote_reward_lines()` take it from there.
- **Pros:**
  - Shoppers see what YayPricing shoppers already see: a "Free" product line, and shipping at 0 on any shipping method, with no merchant setup.
  - One mental model across YayCommerce plugins.
  - The ledger, commit and reversal path is unchanged.
- **Cons:**
  - Two value sources (product line, shipping meta) through the new seam.
  - Adding and removing the free line inside the totals pass must not recurse. YayPricing guards this with `yaydp_prevent_recalculate_cart`, and we need the same guard.
  - A 0-value marker coupon row shows on cart and checkout totals.
- **Risks:**
  - Stacking with YayPricing on the same cart. Our free line must be skipped by YayPricing's rules and theirs by ours; check `includes/Integrations/` YayPricing.
  - Rate caching: hook after rates are calculated, as YayPricing does on `woocommerce_shipping_packages`.
  - Out-of-stock or deleted products: denied with a reason, and the card says why.
- **Editions:** handlers, the free-line sync and the shipping filter in `includes/Pro/`; the value seam in Lite.
- **Size:** M (upper end)

## Recommendation
**Decided: D** (user, 2026-11-23: "free shipping and free product like logic YayPricing"; a free product is always a new line item). It follows the free-item and free-shipping behaviour YayCommerce merchants already know from YayPricing, and keeps the reward's coupon code as the marker, so the redemption, ledger and reversal path stays the tested one. The extra cost over B is the free product line's lifecycle (add, lock, remove), which YayPricing has already proven on both checkouts.

**Earlier recommendation (B), kept for the record.** It meets the acceptance on stores with any shipping setup, and keeps both rewards on the one redemption path that the commit, reversal, order screen and emails already trust.
- The free product becomes an ordinary coupon reward (a 100%, product-limited `percent` coupon), so nearly all the risk sits in the free-shipping rate filter. That filter is about 1 file of Pro code plus one value seam in Lite.
- A trusts WooCommerce's free-shipping coupon flag, which silently does nothing on most stores.
- C rebuilds a redemption path the plugin already has.

**What would change my mind:**
- If the team accepts "requires a Free shipping method that takes coupons" as a documented setup step with an admin warning, A is smaller and nearly as good.
- If shopper testing shows people want the free product as its own "Free" line rather than a discount row, C's display might be worth its cost, but only for the product half.

## Open questions
- **Free product already in the cart:** decided. A new free line is always added; the shopper's own copy is never touched.
- **The 0-value marker coupon row.** Can it be hidden on cart and checkout totals, so only the free line or the 0 shipping shows? Proposal: keep it visible, labelled with the reward name, so the shopper can remove the reward there as with any other reward.
- **Free shipping before an address is entered.** Can the shopper pick it, with the value shown as "Free shipping" and no amount until rates exist? Proposal: allow it when the cart needs shipping; the card says "Free shipping" without an amount; the commit uses the chosen rates' original cost.
- **Several packages, or a rate plugin that already discounts.** Does the reward free every package, or only the first? Proposal: every package.
- **The shopper removes the free product line.** Proposal, as in YayPricing: the line has no remove link and a locked quantity, and the shopper removes the reward from the redeem card or its coupon row.
