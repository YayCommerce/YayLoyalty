---
id: P-11
title: Free rewards: admin creation and edge cases
status: done
from: BL-28
roadmap: R-7
design:
trello:
type: feat
branch: feat/p-11-free-rewards-admin-creation-and-edge-cases
pr:
editions: [pro, lite]
created: 2026-11-25
---
# P-11 Free rewards: admin creation and edge cases

## Goal
A merchant can add free shipping and free product rewards from the Rewards screen, and the redeem card stays honest about them. The shopper sees why any reward row is disabled. A free reward that stops applying is unticked on the same totals run with a notice. A free product is never offered past its stock or a sold-individually limit. A partial refund gives back a free reward's points only for the gift it refunds. Done when each line of Verify passes on Pro, and Lite behaves as before.

## Non-goals
- A variation picker in the reward editor: a variable product is refused with a message instead (BL-29).
- Changing coupon rewards' refunds: fixed and percent rewards keep their `refund_policy`.
- Failed orders: they still give nothing back (BL-34).
- Multi-package and YayPricing/YayCurrency cases: still untested (no license or plugin on the test site).

## Key decisions
- Stored data: none new. Reward rows, configs and order item meta stay as P-10 writes them; `refund_policy` is not read for the two free types.
- Hooks and public shapes: one new action callback on `woocommerce_after_calculate_totals` (CouponProvider), no new hook of ours; two new reason keys `out_of_stock` and `sold_individually` in `RewardCoupons` with `message_for()` text; the rewards-panel template gains a reason line (theme overrides of that template would not show it).
- User-facing flow: Rewards → Add a reward shows four cards (Fixed discount, Percentage off, Free shipping, Free product). On the cart, a disabled row reads its reason under the name; a picked free reward that stops applying shows "X was removed: <reason>" and its row is unticked on the same request.

## Findings
1. The "Add a reward" gate is deliberate: `CREATABLE_REWARD_TYPES` (`apps/admin/src/pro/rewards/reward-labels.ts:104`, PR #137) leaves the free types out "of this release" because they could not be redeemed. P-10 makes them redeemable, so T1 adds them. The editor already has their sections (`RewardEditorSections.tsx:138`, `reward-editor.form.ts:78-116`). The Rewards list was never hiding them; the earlier "3 rewards" was the SPA's cached query.
2. `Reward::is_rewardable_product()` (`includes/Models/Reward.php:350`) refuses variations but accepts a variable, grouped or external parent, none of which `RewardCartLines::add_line()` can put in a cart without a variation (`RewardCartLines.php:258`). T2 refuses them.
3. `FreeProductRewardHandler::quote()` (`includes/Pro/Services/FreeProductRewardHandler.php:29-44`) checks stock for `qty` alone, not counting the shopper's own copies, and ignores sold-individually. WooCommerce then refuses the line in `add_line()` and the tick silently reverts (edge cases 7, 10). T3 denies upfront with a reason.
4. `CouponProvider::sync_cart()` runs on `woocommerce_before_calculate_totals` (`CouponProvider.php:69`), before WooCommerce calculates shipping; `RewardShipping::cart_value()` reads the rates of the previous run (`RewardShipping.php:91,99`). A free-shipping reward whose chosen rate became 0 is only dropped on the next request (edge cases 6, 17). T4 re-checks picked rewards after the totals.
5. The rewards panel marks a denied row with a bare `data-denied` and no text (`includes/Pro/Storefront/templates/rewards-panel.php:26`), though `RedemptionService::message_for()` (`RedemptionService.php:516`) already words every reason. Pre-existing, coupon rewards too. T5 prints it.
6. `Reversal::refund_target()` (`includes/Engine/Redemption/Reversal.php:277-292`) restores `proportional` by refunded amount ÷ order total for every reward, so refunding a paid item returns a free reward's points (edge cases 2-3). T6 gives the free types their own rule.

## Approach
Model on: `includes/Engine/Redemption/CouponProvider.php:345-371` (resolve_rewards: drop a denied picked reward with `notice_removed()`).
- T1 admin: add the two types to `CREATABLE_REWARD_TYPES`; nothing else in the SPA changes.
- T2 storage guard: `Reward::config_conflict()` refuses a `grouped` or `external` product, and a `variable` one without `variation_id`, with one readable message the editor shows as its save error.
- T3 handler: count the shopper's own copies (non-reward lines) of the product; deny `out_of_stock` when those plus `qty` exceed managed stock, `sold_individually` when the product is sold individually and the cart already has it.
- T4 after totals: `CouponProvider::drop_stale_rewards()` on `woocommerce_after_calculate_totals` quotes the picked rewards again; one denied for a dropping reason has its coupon removed (which clears it from the intent through `on_removed()`) and is told through the card's dropped line (`SessionIntent::add_dropped()`). The removed coupon is worth 0, so the totals just computed stay right.
- T5 card: `RewardsPanel::row_views()` adds `reason` (`message_for()` of a denied quote's reason; empty for a balance shortfall, which re-enables itself); the template prints it under the name.
- T6 refunds: in `refund_target()`, a `free_product` row restores points × refunded qty of its free line (meta `_yayloyalty_reward_id`) ÷ that line's qty, all of them once the order is fully refunded; a `free_shipping` row restores only once the order is fully refunded. Cancel is unchanged.
- Editions: T1, T3 are Pro files; T2, T4, T5 (template is Pro), T6 are Lite code that names only Lite-known `RewardType` and `RewardCoupons` values.

## Tasks
- [x] T1 Offer free shipping and free product on "Add a reward" — `apps/admin/src/pro/rewards/reward-labels.ts`
  - Reuse: the existing editor sections and schema for both types
- [x] T2 Refuse a variable, grouped or external product for a free product reward — `includes/Models/Reward.php`
  - Reuse: `Reward::is_rewardable_product()`
- [x] T3 Deny a free product past its stock or sold-individually limit, with reasons — `includes/Pro/Services/FreeProductRewardHandler.php`, `includes/Engine/Redemption/RewardCoupons.php`, `includes/Engine/Redemption/RedemptionService.php` (message_for), `includes/Engine/Redemption/CouponProvider.php` (DROPPING_REASONS)
  - Reuse: `RewardCartLines::is_reward_line()`, `RewardCartLines::quantity_of()`
- [x] T4 Drop a picked reward that stopped applying on the same totals run — `includes/Engine/Redemption/CouponProvider.php`
  - Reuse: `RedemptionService::quote_rewards()`, `CouponProvider::on_removed()`, `SessionIntent::add_dropped()` (the card's dropped line)
  - Callers: `drop_stale_rewards()` → `woocommerce_after_calculate_totals` in `CouponProvider::register()`
- [x] T5 Say why a reward row is disabled — `includes/Pro/Storefront/RewardsPanel.php`, `includes/Pro/Storefront/templates/rewards-panel.php`, `assets/storefront/` CSS if needed
  - Reuse: `RedemptionService::message_for()`, `RewardCoupons::displayed_subtotal()`, `LauncherCopy::money()`
- [x] T6 Give back a free reward's points only for the gift a refund covers — `includes/Engine/Redemption/Reversal.php`
  - Reuse: `EarnReversal::refund_state()`, `RewardCartLines::META_REWARD`

**Tests:** [integration] test_a_variable_product_is_refused_for_a_free_product_reward, test_a_grouped_or_external_product_is_refused (tests/php/integration/Models/RewardFreeProductValidationTest.php) — a reward that can never be added; [integration] test_free_product_is_denied_past_stock_counting_the_shoppers_copies and test_free_product_is_denied_for_a_sold_individually_product_in_the_cart (tests/php/integration/Pro/Services/FreeProductRewardHandlerTest.php) — overselling and the merchant's limit; [integration] test_free_shipping_is_dropped_on_the_run_its_rate_becomes_free (tests/php/integration/Engine/Redemption/RewardShippingTest.php) — a reward left ticked after it stopped applying; [integration] test_a_partial_refund_gives_back_free_reward_points_only_for_the_refunded_gift (tests/php/integration/Engine/Redemption/FreeRewardRefundTest.php) — points given back for gifts the shopper keeps

**E2E:** Rewards → Add a reward → Free product: pick a product, save, reload, and the reward is in the list with its product

## Verify
- `yayh test run unit` → exit 0
- `yayh test run integration` → exit 0
- `yayh e2e run --grep @P-11` → exit 0
- `pnpm --filter @yayloyalty/admin-page exec tsc --noEmit` → exit 0
- `vendor/bin/phpcs -q includes/Models/Reward.php includes/Pro/Services/FreeProductRewardHandler.php includes/Engine/Redemption/RewardCoupons.php includes/Engine/Redemption/RedemptionService.php includes/Engine/Redemption/CouponProvider.php includes/Pro/Storefront/RewardsPanel.php includes/Engine/Redemption/Reversal.php` → exit 0
- `yayh code unused feat/p-10-free-shipping-and-free-product-rewards-that-r` → exit 0
- Admin (Pro build): Rewards → Add a reward shows Free shipping and Free product; a free product reward saved with a simple product appears in the list after a reload; with a variable product the save shows the product field's error.
- Cart: a disabled row (e.g. a coupon under its minimum) reads its reason under the name.
- Cart with the shopper's own copy of a product whose stock is 1: the free product reward is disabled and reads "Not enough in stock"; same for a sold-individually product.
- Checkout with a free-shipping reward applied: switch to a 0-cost method → on that same refresh the reward is unticked and "… was removed: …" shows; no points are debited.
- wp-admin refund of only the paid item of an order with a free product and free shipping → no points come back; refund including the free line (qty 1) → the free product's points come back; full refund → all.

## Risks
- T4 runs on every totals run with a picked reward: one extra quote of the picked rewards per run. Mitigated by quoting only when the intent has rewards.
- T6 changes what refunds give back for the free types only; a store that already refunded P-10 test orders keeps its ledger rows (no migration, nothing re-read).
- T5's template change is invisible to a theme that overrides rewards-panel.php.
- Rollback: revert the branch; no stored data changes.

## Decided
- T2: the guard lives in `config_conflict()`, not `is_rewardable_product()`: a per-key failure answers "unknown key or wrong type", while the conflict gives the merchant one readable message; grouped and external are refused too.
- T3+T4 share one commit: both edit `RedemptionService.php` and `CouponProvider.php`.
- T4: `RedemptionService::cart_key()` now includes `RewardShipping::cart_value()`, or the per-request quote memo would hand the after-totals check the stale quote. The drop is told through the card's dropped line instead of `notice_removed()`: the block checkout shows WooCommerce's `notice` type only on the next page load (seen on the test site), the card is redrawn at once.
- T5: the minimum-spend reason fills in the missing amount (`message_for()` left it to the caller, and no caller did).
- T6: the free reward is found by the meta its gift left on the order, so a reward deleted after checkout still refunds by its gift.
- Test helpers: `order()` and `add_marker()` moved from FreeRewardCommitTest to FreeRewardTestCase so the refund test shares them.

