---
id: B-3
title: Cart discount drops to 0 points when a free product reward is applied
status: fixed
severity: medium
editions: [pro]
found_in: 1.1.0-dev
fixed_in:
branch: fix/b-3-cart-discount-drops-to-0-points-when-a-free-pr
pr:
created: 2026-11-25
---
# B-3 Cart discount drops to 0 points when a free product reward is applied

## Symptoms
On the cart (classic and block), once a `free_product` reward is ticked the built-in flexible "Cart discount" row offers fewer points than the cart allows, often "0 Points" and disabled. Unticking the free reward puts it back (e.g. 900 Points). A `free_shipping` reward lowers it too, by the freed shipping. Seen during the P-10 manual and edge-case runs (cases 3, 11, and the edge-case table's case 0).

## Environment
YayLoyalty Pro on branch `feat/p-10-free-shipping-and-free-product-rewards-that-r` (unreleased, 1.1.0 dev), branchpress site cloned from the brandy snapshot: WooCommerce 11.2.1, VND (0 decimals), flexible reward at 300 points per 1 ₫, HPOS on, classic and block cart both affected.

## Repro steps
1. Pro with the flexible Cart discount active (300 points per ₫), a `free_product` reward for Beanie (price 18, 200 points) and a `free_shipping` reward (100 points), a flat rate of 5.
2. Customer with 1,007 points: Polo (20) in the cart, tick the free Beanie reward.
3. The Cart discount row reads **600 Points**. Expected **800**: the balance left after the reward (807) rounded to the 100 increment, since 20 ₫ is still payable (20 × 300 = 6,000).
4. Customer with 10,007 points, Polo only, tick only the free shipping reward: Cart discount reads **4,500 Points**. With no reward it reads **6,000**. Shipping isn't part of the item subtotal, so it should stay 6,000.
5. Own Beanie (18) + the free Beanie: Cart discount reads **0 Points**. Expected: up to 18 × 300 = 5,400, capped by the balance.

## Root cause
`RedemptionService::quote_for()` prices the flexible discount against "the cart less the picked rewards' value": it sums `$row['quote']->get_value()` over every applied picked reward and subtracts it from the context's discountable amount (`includes/Engine/Redemption/RedemptionService.php:197-206`). That is right for the coupon rewards it was written for (#144, `13aa7c6`): a fixed or percent coupon's value is a discount on the items, and `ContextFactory::discountable_amount()` skips loyalty codes, so their discount isn't in the base yet (`includes/Engine/Rewards/ContextFactory.php:177-181`).

P-10's two new types give a `value` that is not a discount on the item subtotal:
- **free_product:** the value is the product's price × qty (`includes/Pro/Services/FreeProductRewardHandler.php:38`). But the free line is already priced 0 in the cart (`includes/Engine/Redemption/RewardCartLines.php:103-105`), so `discountable_amount()`, which sums `get_price() × quantity` per line (`ContextFactory.php:169-171`), already leaves it out. Subtracting the value again removes it twice: Polo 20 + free Beanie → 20 − 18 = 2 ₫ → 600 points.
- **free_shipping:** the value is the freed shipping cost (`includes/Pro/Services/FreeShippingRewardHandler.php:47`, `RewardShipping::cart_value()`), which was never in the item subtotal. Subtracting it shrinks the item base by the shipping: 20 − 5 = 15 ₫ → 4,500 points.

Introduced by P-10 (the new handlers feed `quote_for()`'s subtraction); the subtraction itself predates it and is correct for coupon rewards. Confidence: **high**: both numeric predictions (600 and 4,500) matched the cart exactly on the test site, and both "expected" values follow from the same formula without the subtraction.

## Fix options
1. **(Recommended)** In `RedemptionService::quote_for()`, add a picked reward's value to `$amount` only when that value is a discount on the items, i.e. skip `RewardType::FREE_PRODUCT` and `RewardType::FREE_SHIPPING` (their cost still comes off the balance). One file, Lite code naming Lite-known enum values (`RewardType::all_known()`), no Pro name. Affects pro (only Pro offers these types); Lite behaviour unchanged.
2. Let each handler say how much of its value comes off the item subtotal (a new `Result` field, 0 for the two free types), and subtract that. Cleaner if more non-item reward types come, but touches `Result`, every handler and its tests; YAGNI for two types.
3. Compute the base after the free line and shipping changes instead (quote from the cart's real discounted totals). Bigger: changes when quotes can run (rates must exist), and risks the coupon-reward path that works today.

## Regression test proposal
[integration] `test_free_rewards_do_not_shrink_the_flexible_discount_base` in `tests/php/integration/Engine/Redemption/FreeRewardCommitTest.php` (or a new `FlexibleQuoteWithFreeRewardsTest.php` beside it): a cart with one 20 item and an applied `free_product` marker for an 18 product, then with an applied `free_shipping` marker on a 5 rate; `RedemptionService::ceiling()` quotes the same points as with no reward except for the reward's cost taken off the balance. Integration, not unit: it needs `WC_Cart`, the free line's session price and the rate cache. Guards the double subtraction for both types.

## Fix
`RedemptionService::quote_for()` still takes every picked reward's points off the balance, but takes its value off the points-discount base only when the reward discounts the items. A reward whose coupon props carry `free_item` (a 0-priced line) or `free_shipping` is skipped: the same props `RewardCartLines` and `RewardShipping` read to add the line and free the rates, so any future type that does either is covered too. The commit side needed no change: `CommitWriter::requote_flexible_discount()` builds its base from the order (`ContextFactory::from_order()`), where the free line and the free-shipping coupon line are already 0, so cart and checkout now agree.

Verified:
- `[integration] FlexibleQuoteWithFreeRewardsTest` (`@group B-3`): fails without the fix (`200` vs `2000` for a free product, `1300` vs `2000` for free shipping) and passes with it.
- `yayh test run integration` → OK (115 tests); `yayh test run unit` → OK (158 tests); phpcs on the changed file → exit 0; `yayh code unused` → exit 0; `yayh debug markers` → nothing.
- Review: `yay:bug-reviewer` raised two risks, both fixed: match on the coupon props instead of the reward type, and remove the test's shipping method in `tear_down()`.
