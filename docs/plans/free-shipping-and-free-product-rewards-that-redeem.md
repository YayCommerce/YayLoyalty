---
id: P-10
title: Free shipping and free product rewards that redeem
status: in-progress
from: BS-6
roadmap: R-7
design:
trello:
type: feat
branch: feat/p-10-free-shipping-and-free-product-rewards-that-r
pr:
editions: [pro, lite]
created: 2026-11-23
---
# P-10 Free shipping and free product rewards that redeem

## Goal
A Pro shopper can tick a `free_shipping` or `free_product` reward on the redeem card (classic and block cart/checkout), the same way as a coupon reward.
- **Free shipping:** every shipping rate drops to 0.
- **Free product:** a new line of the configured product and quantity is added at 0, locked, and labelled with the reward. A copy the shopper already has is never touched.

At checkout the reward's full `cost_points` is debited and a `redemptions` row stores what it was worth (the freed shipping, or the product's price × qty). Cancelling or refunding the order gives the points back under the reward's `refund_policy`.

Done means each part of R-7's acceptance passes in Verify, on both checkouts. On Lite, or a Lite downgrade, neither reward is offered and no free line stays in a cart.

## Non-goals
- WooCommerce's own "Free shipping requires a valid coupon" methods: the marker coupon keeps `free_shipping => false` (BS-6 option A).
- Discounting a copy of the product the shopper already has (decided in BS-6).
- A choice of product per claim: the config holds one `product_id` / `variation_id`.
- Changing what the reward editor saves: the schemas in `includes/Pro/YayLoyaltyPro.php:372` stay as they are.
- Hiding the marker coupon's 0 row on the totals: it stays visible, labelled with the reward name, and is the place to remove the reward.

## Findings
1. **The card and the launcher only list coupon types.** `RedemptionService::reward_options()` (`includes/Engine/Redemption/RedemptionService.php:387`) and `RewardsPanel::redeem_offers()` (`includes/Pro/Storefront/RewardsPanel.php:46`) both call `live_of_types( [ FIXED_COUPON, PERCENT_COUPON ] )`. A handler alone would never show the new rewards. → T1 lists every catalog type, and `is_reward_offered()` still filters out types with no handler.
2. **The launcher copy only knows the coupon types.** `LauncherCopy::coupon_reward_line()` (`includes/Engine/Storefront/LauncherCopy.php:79`) words anything other than percent as "$X off your order", which would read "$0 off" for both new types. → T1 adds their lines.
3. **The commit fails a line worth 0.** `CommitWriter::requote_reward_lines()` (`includes/Engine/Redemption/CommitWriter.php:322-326`) skips the whole commit with `REASON_REWARD_UNAVAILABLE` when `line_discount_of()` is ≤ 0, and the marker coupon's line is always 0. `RedemptionService::applies()` (`:245`) also needs `value > 0`, but that is the handler's quote value, which the new handlers set themselves. → T4 adds the freed value to the line's value at commit.
4. **The rate cache keeps what we write.** WooCommerce stores each package's rates in the session (`shipping_for_package_<n>`), and `woocommerce_shipping_packages` (`woocommerce/includes/class-wc-shipping.php:278`) runs on those cached objects too. A rate zeroed in one request can come back still zeroed in the next. YayPricing keeps track of the same thing with `modified_rules` (`YayPricing/includes/core/rule/checkout-fee/class-yaydp-shipping-fee.php:131`). → T3 keeps the original cost in rate meta, always restores from it, and zeroes again only while the marker is applied.
5. **Rate meta reaches the order on both checkouts.** `WC_Checkout::create_order_shipping_lines()` copies each rate's meta onto its shipping item (`woocommerce/includes/class-wc-checkout.php:696`), and the Store API calls the same method (`woocommerce/src/StoreApi/Utilities/OrderController.php:1271`), as it does `create_order_line_items()` (`:1262`). → T3 and T2 store the original cost and price as hidden meta, and T4 reads it from the order.
6. **A cart line's price comes back from the product on every request.** YayPricing sets the price both on `woocommerce_add_to_cart` and on every pricing pass (`YayPricing/includes/core/manager/class-yaydp-product-pricing-manager.php:53`, `class-yaydp-cart.php:151`). → T2 sets the price to 0 on every reconcile pass and when the cart loads from the session.
7. **YayPricing would count our line as a cart item, and must not delete it.** Its rules read `WC()->cart->get_cart()` through `yaydp_init_cart_items` (`YayPricing/includes/core/class-yaydp-cart.php:56`). Marking the line `is_extra` would make YayPricing delete it (`class-yaydp-product-pricing-manager.php:115`). → T6 filters our line out of YayPricing's cart.
8. **Order on the totals hook.** `CouponProvider::sync_cart()` decides the loyalty codes at `woocommerce_before_calculate_totals` `PHP_INT_MAX`. A Pro or Lite listener at the same priority can't rely on running after it. → T2 adds a Lite action at the end of `sync_cart()` and reconciles the free lines on it, so the marker coupon is the only source of truth.
9. **Block checkout hides the quantity selector through `woocommerce_store_api_product_quantity_editable`** (`woocommerce/src/StoreApi/Utilities/QuantityLimits.php:39`), but the block's remove link isn't filterable. → T2 hides the classic remove link and also treats a removed free line (`woocommerce_cart_item_removed`) as the shopper removing the reward.
10. **A reward the cart can't use drops with `no_value`** (`CouponProvider::DROPPING_REASONS`, `includes/Engine/Redemption/CouponProvider.php:31`, message "Nothing in your cart qualifies"). → Both handlers deny `RewardCoupons::REASON_NO_VALUE` when there's nothing to free: no shipping rates yet, a cart that needs no shipping, or a product that is missing, unpurchasable or out of stock for `qty`. No new reason or copy is needed.
- differs: `docs/knowledge/known-issues.md:25` says these types can't redeem; `docs/knowledge/architecture.md` "Redemption" names only the coupon handlers. → T7 updates both, plus the YayPricing row in `integrations.md`.

## Approach
Model on: `RewardHandler::coupon_props()` → `RewardCoupons` (`includes/Engine/Rewards/Handlers/RewardHandler.php:27`, `includes/Engine/Redemption/RewardCoupons.php:28`). The handler *describes* the reward and Lite *applies* it, so the mechanics stay in Lite and work, or switch off, on any build.

- **Props.** `coupon_props()` gains two optional keys: `free_shipping` (bool) and `free_item` (`{product_id, variation_id, qty}`). Both new handlers return a `fixed_cart` coupon at amount 0. That is the **marker**, and it is the only sign a reward is in use. `RewardCoupons::coupon_fields()` ignores the new keys.
- **Free item lines (Lite, `RewardCartLines`).**
  - On the new action `yayloyalty_reward_coupons_synced` (fired at the end of `CouponProvider::sync_cart()`), every applied marker whose props have `free_item` gets exactly one cart line (cart item data `yayloyalty_reward_id`) at price 0 and the configured qty.
  - Every line whose reward code isn't applied is removed. This also covers Lite, a downgrade and an unlicensed storefront.
  - The line is locked: no classic remove link, a fixed quantity (`woocommerce_cart_item_quantity`, `woocommerce_store_api_product_quantity_editable`). It is labelled through `woocommerce_get_item_data` as "Reward: <reward name>". If it is removed anyway, the reward leaves the session (`SessionIntent::remove_reward()`).
  - `woocommerce_checkout_create_order_line_item` copies the reward id and the original unit price onto the order item as hidden meta (`_yayloyalty_reward_id`, `_yayloyalty_original_price`).
- **Free shipping (Lite, `RewardShipping`).**
  - `woocommerce_shipping_packages` (as YayPricing hooks it) keeps each rate's original cost and taxes in hidden rate meta (`_yayloyalty_original_cost`, `_yayloyalty_original_taxes`). It restores them, then sets cost and taxes to 0 while an applied marker has `free_shipping`, on every package.
  - It also notes the chosen rates' original total in the WC session (`yayloyalty_shipping_before_reward`), which the free-shipping quote reads.
- **Value at commit.** `CommitWriter::requote_reward_lines()` takes the line's value as `line_discount_of()` plus what the code freed on the order: for its free-item lines, original price × qty; for its free shipping, the shipping items' original cost minus their total (plus the taxes when prices include tax, the same basis as `line_discount_of()`). Reversal is unchanged, since it works from the `redemptions` row.
- **Handlers (Pro, `includes/Pro/Services/`).**
  - `FreeShippingRewardHandler::quote()`: allowed at the full cost, valued at the session's shipping-before-reward total. Denied `no_value` when the cart doesn't need shipping or there are no rates yet.
  - `FreeProductRewardHandler::quote()`: denied `no_value` unless the product (or variation) exists, is purchasable and has stock for `qty`. Valued at the product's price × qty in the charged currency.
  - Both quote cost-only when there is no cart, like `DiscountRewardHandler`. Both are registered in `YayLoyaltyPro::register_reward_handlers()`.
- **UI** (existing places, no design file):
  - The card row is the existing one from `templates/rewards-panel.php` (name and cost).
  - The launcher and My Account "Ways to redeem" lines read "Free shipping on your order" and "Free <product name>" (with "× <qty>" when qty > 1).
  - The cart line shows the item data "Reward: <reward name>" and a 0 price.
- **Editions:** the mechanics are Lite (no Pro names, per hard rule 1); the handlers are Pro. With no handler, `is_reward_offered()` is false, the marker never applies, and `RewardCartLines` removes any leftover line.
- **Multi-currency:** rates and product prices are already in the charged currency, and `CommitWriter::base_value()` converts the stored value as it does for coupons.
- **No storage change:** no table, option or migration. The new meta is on order items and session only.

## Tasks
- [ ] T1 Offer the two types on the card, the launcher and My Account, with their own launcher line: `includes/Engine/Redemption/RedemptionService.php`, `includes/Pro/Storefront/RewardsPanel.php`, `includes/Engine/Storefront/LauncherCopy.php`, `includes/Engine/Rewards/Handlers/RewardHandler.php` [risk: 1]
  - Reuse: `RewardRepository::live_of_types()` in `includes/Repositories/RewardRepository.php:70`; `RedemptionService::is_reward_offered()`; `LauncherCopy::coupon_reward_line()` and `money()`; `RewardType` constants in `includes/Enums/RewardType.php`
  - Callers: none new. The `RewardHandler::coupon_props()` docblock documents the `free_shipping` / `free_item` keys that T5 returns.
- [ ] T2 Free item lines: add, price, lock, label and remove them from the marker; record them on the order: `includes/Engine/Redemption/RewardCartLines.php` (new), `includes/Engine/Redemption/CouponProvider.php`, `includes/YayLoyalty.php` [risk: 3]
  - Reuse: `RedemptionService::applied_rewards()` and `coupon_data_for()`/`coupon_props_of()` for the props; `SessionIntent::remove_reward()`; `RedemptionService::cart()`
  - Callers: action `yayloyalty_reward_coupons_synced` → fired by `CouponProvider::sync_cart()`; `RewardCartLines::reconcile()` → that action; `set_free_prices()` → `woocommerce_get_cart_item_from_session`; `lock_remove_link()` → `woocommerce_cart_item_remove_link`; `lock_quantity()` → `woocommerce_cart_item_quantity`; `lock_block_quantity()` → `woocommerce_store_api_product_quantity_editable`; `item_data()` → `woocommerce_get_item_data`; `on_line_removed()` → `woocommerce_cart_item_removed`; `copy_to_order_item()` → `woocommerce_checkout_create_order_line_item`; `RewardCartLines::order_value()` → `CommitWriter` (T4); `RewardCartLines::register()` → `includes/YayLoyalty.php`, next to `CouponProvider`
- [ ] T3 Free shipping on the rates: keep the original cost, zero every rate while a `free_shipping` marker is applied, and note the original total for the quote: `includes/Engine/Redemption/RewardShipping.php` (new), `includes/YayLoyalty.php` [risk: 3]
  - Reuse: `RedemptionService::applied_rewards()` and the props read from T2; `WC_Shipping_Rate::add_meta_data()` / `set_cost()` / `set_taxes()`
  - Callers: `RewardShipping::adjust_packages()` → filter `woocommerce_shipping_packages`; `RewardShipping::cart_value()` → `FreeShippingRewardHandler::quote()` (T5); `RewardShipping::order_value()` → `CommitWriter` (T4); `register()` → `includes/YayLoyalty.php`
- [ ] T4 Commit a free reward at what it freed: `includes/Engine/Redemption/CommitWriter.php` [risk: 3]
  - Reuse: `ContextFactory::line_discount_of()`; `RewardCartLines::order_value()` (T2); `RewardShipping::order_value()` (T3); `CommitWriter::money()` / `base_value()`
- [ ] T5 Pro handlers for `free_shipping` and `free_product`, registered with Lite: `includes/Pro/Services/FreeShippingRewardHandler.php` (new), `includes/Pro/Services/FreeProductRewardHandler.php` (new), `includes/Pro/YayLoyaltyPro.php` [risk: 3]
  - Reuse: `DiscountRewardHandler` in `includes/Pro/Services/DiscountRewardHandler.php` (the no-cart cost-only quote, `Result::allowed()` / `denied()`); `RewardCoupons::REASON_NO_VALUE`; `RewardShipping::cart_value()` (T3); `wc_get_product()` for the free product's price and stock
  - Callers: `FreeShippingRewardHandler`, `FreeProductRewardHandler` → `YayLoyaltyPro::register_reward_handlers()` on `yayloyalty_register_reward_handlers`
- [ ] T6 Keep YayPricing's rules off the free line: `includes/Integrations/YayPricing/YayPricingIntegration.php`
  - Reuse: `RewardCartLines` cart item key constant (T2)
  - Callers: `YayPricingIntegration::skip_reward_lines()` → filter `yaydp_init_cart_items`
- [ ] T7 Knowledge: drop the known issue; add the free lines, the rate filter and the commit value to Redemption; update the YayPricing row and the testing coverage table: `docs/knowledge/known-issues.md`, `docs/knowledge/architecture.md`, `docs/knowledge/integrations.md`, `docs/knowledge/testing.md` [risk: 1] [tier: veryfast]
  - Reuse: n/a

**Tests:** [integration] test_an_applied_free_product_marker_adds_one_locked_line_at_zero_and_leaves_the_shoppers_copy (tests/php/integration/Engine/Redemption/RewardCartLinesTest.php) — the free line exists once, at 0, only while the marker is applied, and is removed with it (Lite downgrade case: no handler, so no line); [integration] test_removing_the_free_line_drops_the_reward_from_the_session (tests/php/integration/Engine/Redemption/RewardCartLinesTest.php) — removal can't leave a reward debited with no item; [integration] test_free_shipping_zeroes_every_rate_and_restores_it_from_the_cached_original (tests/php/integration/Engine/Redemption/RewardShippingTest.php) — the rate cache (Finding 4) never leaves shipping free after the reward is removed; [integration] test_a_free_reward_commit_stores_the_freed_value_and_a_cancel_restores_the_points (tests/php/integration/Engine/Redemption/FreeRewardCommitTest.php) — the debit, the row's value for both types, and the cancel give-back; [unit] test_free_product_quote_denies_no_value_for_a_missing_or_out_of_stock_product (tests/php/unit/Pro/Services/FreeProductRewardHandlerTest.php) — the handler's gates

**E2E:** Redeem points at block cart and checkout: ticking a free-shipping reward shows shipping at 0, and ticking a free-product reward adds a 0 line labelled with the reward, then the order is placed and the points are debited

## Verify
- `yayh test run unit` → exit 0
- `yayh test run integration` → exit 0 (integration: the four tests above)
- `yayh e2e run --grep @P-10` → exit 0 (e2e)
- `composer lint` → no new errors beyond the `dev` baseline in known-issues
- `pnpm --filter @yayloyalty/admin-page exec tsc --noEmit` → exit 0
- `yayh code unused` → no line for a symbol this plan added
- `grep -rn "YayLoyaltyPro\|Pro\\\\" includes/Engine/Redemption/RewardCartLines.php includes/Engine/Redemption/RewardShipping.php` → exit 1 (Lite names no Pro)
- Classic checkout (Pro, a store with a flat-rate method, customer with enough points): tick the free-shipping reward on the cart's redeem card → shipping reads 0 and a row named after the reward shows $0. Place the order → `wp db query "SELECT points, value FROM wp_yayloyalty_redemptions ORDER BY id DESC LIMIT 1"` shows the reward's cost and the flat rate's cost.
- Classic checkout: tick the free-product reward with that product already in the cart → a second line of the product at $0 with "Reward: <name>", no remove link, fixed qty; the shopper's own line is unchanged. Untick → the free line is gone.
- Block checkout: the same two flows (e2e), plus removing the free line from the block cart → the reward is unticked on the card.
- Cancel one of those orders in wp-admin → the customer's balance goes back up by the reward's cost (`wp eval 'echo (new \YayLoyalty\Repositories\CustomerRepository())->find_by_user_id( <id> )->get_balance();'`).
- Refund a free-product order fully → points restored under the reward's `refund_policy` (default proportional = all on a full refund).
- With YayPricing active and a "buy 2 get 10% off" rule on the product: the free line doesn't count toward the 2, and YayPricing doesn't remove it.
- Lite build (`./scripts/run.sh build lite`, Lite zip installed over Pro with a free line in a cart) → the reward isn't on the card and the free line is gone on the next cart load.
- A virtual-only cart → the free-shipping row is disabled ("Nothing in your cart qualifies"), and no marker is applied.

## Risks
- **A free line that stays free without a debit, or a debit without the line.** The marker coupon is the single source of truth: `RewardCartLines` adds lines only for applied markers and removes all others on every totals pass, and the commit re-quotes from the order. Rollback: remove the two handler registrations in T5. The mechanics then do nothing, since no marker can apply.
- **Shipping left free after the reward is removed (rate cache).** T3 always restores from the stored original before deciding, and the T3 test covers it.
- **Other shipping or pricing plugins acting after us** on `woocommerce_shipping_packages` can change our 0. This is accepted, since YayPricing has the same exposure. The commit stores what the order shows as freed, never more than the original.
- **Recursion:** `add_to_cart()` inside the totals pass can trigger another pass, and the reconcile is idempotent (one line per applied code, no line for others). `LoyaltyCouponRerun` already caps YayPricing re-runs at one.
- **Free shipping before rates exist** (no address yet) is denied `no_value`, so the row is disabled until shipping is calculated. This is a change from BS-6's proposal, made because there is nothing to free yet.
- **Unlicensed Pro:** `ProgramGate` already refuses redemption while the storefront is hidden, so the marker drops and the line goes with it.
