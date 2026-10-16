# Redemption Implementation Plan

> **For agentic workers:** this plan is a record. Every task below is built and committed on `jason/feat/redemptions`. Read the spec first; it states the behaviour, and this plan states where each part lives, which commit built it and how to re-check it. New work on redemption starts from the spec's Open questions with a new plan.

**Status:** done 2026-10-16
**Goal:** Customers spend points at the cart and checkout through the points discount slider (Lite and Pro) or fixed and percentage rewards (Pro), are debited once at order placement, and get the points back by fixed rules on cancel and refund.
**Spec:** [docs/specs/2026-10-16-redemption-design.md](../specs/2026-10-16-redemption-design.md) (accepted 2026-10-16). It replaces the uncommitted 2026-10-07 and 2026-10-14 specs; this plan replaces their four plans (slider backend, reversals, checkout card, reward redemption).
**Architecture:** Every loyalty discount is a virtual coupon (`yayloyalty-redeem` for the slider, `yayloyalty-reward-{id}` per reward), so WooCommerce does the discount math, the tax split and one totals row per line. A pure quote path decides every number; `CommitWriter` re-quotes from the order's own coupon lines under the customer lock and writes everything or nothing; `Reversal` settles each row to a target and writes only the difference. Lite owns all of it; Pro adds two handlers and the Rewards tab through filters.
**Touches:**
- `includes/Engine/Redemption/` (all), `includes/Engine/Rewards/Clamp.php`, `ContextFactory.php`, `Context.php`, `Handlers/RewardHandler.php`, `Handlers/FlexibleDiscountHandler.php`
- `includes/Services/LedgerService.php` (shared: the single ledger writer; callers `EarningService`, `Earning\Reversal`, `CustomerRestController`, `CommitWriter`, `Reversal`)
- `includes/Engine/Earning/Listeners/OrderListener.php`, `OrderReversalListener.php`, `includes/Engine/Earning/Reversal.php`, `IdempotencyKey.php` (shared: every order status passes through them)
- `includes/Engine/Points/Context.php`, `ContextFactory.php` (shared: every earning handler)
- `includes/Database/Schema/RedemptionsTable.php`, `includes/Database/Installer.php`, `includes/Models/Redemption.php`, `includes/Repositories/RedemptionRepository.php`, `RewardRepository.php`
- `includes/Controllers/RedemptionsRestController.php`, `CustomerRestController.php`, `CustomerItemFormatter.php` (shared shape: mirrored by `apps/admin/src/lib/schema/redemption.schema.ts`)
- `includes/Engine/Storefront/RedeemCard.php`, `templates/redeem-card.php`, `LauncherPayload.php`, `LauncherCopy.php`, `assets/storefront/redeem.js`, `redeem-blocks.js`, `redeem.css`
- `includes/Pro/Services/DiscountRewardHandler.php`, `includes/Pro/Storefront/`, `includes/Pro/YayLoyaltyPro.php`
- `includes/YayLoyalty.php`, `includes/Engine/RestAPI.php`
- `docs/architecture.md`, `docs/decisions/2026-10-07-lite-owns-redemption-writes.md`, `docs/decisions/2026-10-14-loyalty-discounts-as-virtual-coupons.md`

**Tests:**
- `tests/unit/Engine/Rewards/ClampTest.php`, unit: the clamp order, the step, the minimum and the whole-unit rule.
- `tests/unit/Engine/Points/PointsEngineEvaluateTest.php`, unit: an order whose only coupons are loyalty codes still earns under `exclude_coupon_orders`.
- `tests/unit/Engine/Storefront/RedeemCardViewTest.php`, unit: the card's view states.
- `tests/integration/Services/LedgerRedeemTest.php`, `LedgerRefundRedeemTest.php`, integration: `redeem` refuses an overdraw unless allowed, `refund_redeem` raises the balance, one row per key.
- `tests/integration/Engine/Redemption/CommitWriterTest.php`, integration: slider and reward commits, re-fires, a short balance writes nothing.
- `tests/integration/Engine/Redemption/ReversalTest.php`, integration: cancel, thirds, half, `none`, `full`, the `refunded` status, deleted refund, un-cancel, short balance, the second cycle, repeats, two reward rows under one refund.
- `tests/integration/Engine/Earning/OrderReversalListenerTest.php`, `tests/integration/Engine/Redemption/ClassicCheckoutAdapterTest.php`, integration: routing and the classic commit.
- `apps/e2e/specs/redemptions.spec.ts`, `apps/e2e/specs/reward-redemptions.spec.ts`, Playwright: the spec's end-to-end walk-throughs.

## Global constraints

- Product PHP runs on PHP 7.4: no enums, `match`, `readonly`, promoted constructor properties, `?->`, named arguments or union types.
- Only `LedgerService` writes ledger rows and balances. Its four construction sites are `Engine\RestAPI::controllers()`, `EarningService::build()`, `Earning\Reversal::build()`, `RedemptionService::build()`.
- Lite names Pro only in `includes/YayLoyalty.php`. Pro attaches only through hooks added in `YayLoyaltyPro::boot()`. Nothing under `includes/Engine/` imports `YayLoyalty\Pro\*`.
- Orders are read and written through WooCommerce CRUD only (HPOS). Classic checkout and Blocks are both supported.
- Money is `DECIMAL(19,4)` in storage and a string in PHP and REST. Points are integers. Datetimes are UTC.
- No `shop_coupon` post for any loyalty discount. No schema change after the table and no version bump: the plugin is unreleased at 1.0.0.
- The card keeps its own `assets/storefront/redeem.css` and reads nothing from the Design tab. Storefront scripts are plain JS: no build step, no JSX.
- Money shown to a shopper goes through `LauncherCopy::money()`, points through `SettingsHelper::points_text()`; no sentence hard-codes "points".
- Never hand-edit `vendor/`, `vendor-prefixed/`, `node_modules/`, `assets/dist/`, `release/`.
- Checks:
  - `composer lint -- <files>` on the changed PHP files reports no error (the whole-repo run fails on `dev/edd`, `docs/known-issues.md`).
  - `composer test` passes: 52 tests on 2026-10-16.
  - `composer test:integration` passes; exit 0 proves nothing, so read the test count.
  - `pnpm --filter @yayloyalty/e2e test redemptions reward-redemptions` passes against wp-env on :8889 (Pro build): 10 tests on 2026-10-16.

## Review focus

- **Debit equals discount.** The commit re-quotes from the order's coupon line, never the session, so the debit always matches what the order carries. Task 1, Task 6 (`CommitWriterTest`).
- **A failed commit leaves no discount.** On `skipped`, every loyalty line is removed from the order and the checkout fails, so it cannot be paid later with a discount and no debit. Task 1, Task 6.
- **Shared refund key.** Two reward rows on one order under one refund restore under their own `reward_{id}` keys. Task 5, Task 6 (`ReversalTest`).
- **Exclusivity.** No cart or order carries `yayloyalty-redeem` with a `yayloyalty-reward-*` code, whatever the request order. Task 6, Task 7 (e2e).
- **Raw codes never shown.** A dropped reward's notice names the reward, never `yayloyalty-reward-5`. Task 6, Task 8.
- **Downgrade.** A Lite build with reward ids in the session denies them as `not_live` instead of fatal, and past reward orders still reverse. Task 6.
- **A choice the card cannot show.** A paused program, disabled coupons, an opted-out or excluded shopper, or an expired `cart-discount` reward drops the saved choice instead of refusing every checkout. Task 8.

---

### Task 1: Slider backend: table, debit and the Store API discount

**Files:**
- Create: `includes/Database/Schema/RedemptionsTable.php`, `includes/Engine/Redemption/{RedemptionService,CommitWriter,CouponProvider,SessionIntent,ProgramGate,RewardGate}.php` (filled from stubs), `includes/Engine/Redemption/Adapters/StoreApiAdapter.php`, `includes/Engine/Rewards/{Clamp,ContextFactory}.php`, `includes/Engine/Rewards/Handlers/FlexibleDiscountHandler.php`
- Modify: `includes/Database/Installer.php`, `includes/Models/Redemption.php`, `includes/Repositories/{RedemptionRepository,LedgerRepository,AbstractRepository}.php`, `includes/Services/LedgerService.php` (`redeem()`), `includes/Controllers/{RedemptionsRestController,CustomerRestController,CustomerItemFormatter,RewardRestController}.php`, `includes/YayLoyalty.php`, `includes/Engine/RestAPI.php`, `apps/admin/src/lib/schema/{redemption,customer,reward}.schema.ts`, `apps/admin/src/pages/customers/drawer/customer-drawer.{redemption-rows,copy}.ts`
- Docs: `docs/decisions/2026-10-07-lite-owns-redemption-writes.md`, `docs/architecture.md`
- Test: `tests/unit/Engine/Rewards/ClampTest.php`, `tests/integration/Engine/Redemption/CommitWriterTest.php`, `tests/integration/Services/LedgerRedeemTest.php`

**Interfaces:** spec, "Data and storage", "Quote path" (gates, the points discount), "Checkout and commit" (slider). `RedemptionService::quote( \WC_Cart, ?int ): Result`, `ceiling()`, `checkout_error()`, `commit( \WC_Order ): Outcome`, `message_for( string ): string`; `LedgerService::redeem( LedgerEntry, bool $allow_negative = false ): Outcome`; route `GET /yayloyalty/v1/customers/{id}/redemptions`.

- [x] **Verify:** `composer test -- --filter ClampTest` passes; `composer test:integration -- --filter 'CommitWriterTest|LedgerRedeemTest'` passes with a non-zero count.
- [x] **Committed:** `9173be9` feat(redemption): spend points as a cart discount through the Store API; `129569a` fix(redemption): repair the customer route and apply excluded roles to redemption; `c4d5aad` fix(redemption): pass the campaign repository to LedgerService.

### Task 2: Reversals

**Files:**
- Create: `includes/Engine/Redemption/Reversal.php`, `includes/Engine/Redemption/RedemptionDraft.php`
- Modify: `includes/Services/LedgerService.php` (`refund_redeem()`, overdraw on re-debit), `includes/Engine/Earning/Reversal.php` (`refunds()`, `refund_state()` shared), `includes/Engine/Earning/Listeners/{OrderListener,OrderReversalListener}.php` (restore first, one transaction; un-cancel routing), `includes/Engine/Earning/ProgramGate.php`, `includes/Repositories/{RedemptionRepository,CustomerRepository}.php`, `includes/Models/Reward.php` (refund policy constants), `includes/YayLoyalty.php`
- Test: `tests/integration/Services/LedgerRefundRedeemTest.php`, `tests/integration/Engine/Redemption/ReversalTest.php`, `tests/integration/Engine/Earning/OrderReversalListenerTest.php`, `tests/integration/IntegrationTestCase.php`

**Interfaces:** spec, "Reversals". `Reversal::cancelled( \WC_Order ): Outcome[]`, `refunded( \WC_Order, int $refund_id )`, `refunded_status( \WC_Order )`, `refund_deleted( int $refund_id, int $order_id )`, `uncancelled( \WC_Order )`; reasons `nothing_to_move`, `key_spent`; `RedemptionService::reversal(): Reversal`.

- [x] **Verify:** `composer test:integration -- --filter 'ReversalTest|LedgerRefundRedeemTest|OrderReversalListenerTest'` passes with a non-zero count.
- [x] **Committed:** `e05054b` feat(ledger): write refund_redeem rows and let a re-debit overdraw; `42f12f4` feat(redemption): settle a redemption on cancel, refund and their undo; `b9630a8` feat(redemption): return points when an order is cancelled or refunded; `1b5145a` refactor(redemption): give shared redemption facts one owner.

### Task 3: The points card on classic and block pages

**Files:**
- Create: `includes/Engine/Storefront/RedeemCard.php`, `includes/Engine/Storefront/templates/redeem-card.php`, `includes/Engine/Redemption/Adapters/ClassicCheckoutAdapter.php` (filled), `assets/storefront/{redeem.js,redeem-blocks.js,redeem.css}`
- Modify: `includes/Engine/Redemption/{CouponProvider,RedemptionService}.php`, `Adapters/StoreApiAdapter.php`, `includes/Engine/Register/ScriptName.php`, `includes/Engine/Storefront/{Launcher,LauncherPayload}.php`, `includes/Engine/Rewards/Clamp.php`, `includes/Repositories/RewardRepository.php`, `includes/Engine/Emails/PointsEarnedEmail.php` and its templates, `includes/Integrations/YayMail/PointsEarnedShortcodes.php`, `includes/YayLoyalty.php`
- Test: `tests/unit/Engine/Storefront/RedeemCardViewTest.php`, `tests/integration/Engine/Redemption/ClassicCheckoutAdapterTest.php`, `apps/e2e/specs/redemptions.spec.ts`, `apps/e2e/support/api.ts`, `apps/e2e/bin/setup.php`

**Interfaces:** spec, "Storefront" (placements, slider states, wire format). Shortcode `[yayloyalty_redeem]`, action `yayloyalty_redeem_card`, `wc-ajax=yayloyalty_redeem`, Store API callback `yayloyalty-redeem`, `extensions.yayloyalty`.

- [x] **Verify:** `pnpm --filter @yayloyalty/e2e test redemptions` passes; no console errors on `/cart/`, `/checkout/`, `/e2e-classic-cart/`, `/e2e-classic-checkout/`.
- [x] **Committed:** `41b9d49` feat(redemption): add the points slider card to cart and checkout; `0936bbb` refactor(redemption): give the points card's shared facts one owner; `2a9bc8c` test(e2e): cover the redemptions flow.

### Task 4: Ways to redeem in the launcher

**Files:**
- Modify: `includes/Engine/Storefront/{LauncherPayload,LauncherCopy}.php`, `includes/Engine/Storefront/templates/panel-member.php`

**Interfaces:** spec, "Storefront", Launcher. `LauncherPayload::redeem_rule( ?Reward ): ?array`.

- [x] **Verify:** signed in with points and `cart-discount` live, the launcher's member panel lists the points discount under "Ways to redeem"; with the reward paused, the line is gone.
- [x] **Committed:** `981d2c6` feat(launcher): show ways to redeem in the member panel.

### Task 5: Groundwork for rewards: per-reward keys, loyalty codes in earning, the card header

**Files:**
- Modify: `includes/Engine/Redemption/{RedemptionDraft,Reversal,CouponProvider}.php`, `includes/Engine/Points/{Context,ContextFactory}.php`, `includes/Engine/Rewards/ContextFactory.php`, `includes/Engine/Storefront/RedeemCard.php`, `templates/redeem-card.php`, `assets/storefront/redeem.css`
- Test: `tests/unit/Engine/Points/PointsEngineEvaluateTest.php`, `tests/unit/Engine/Storefront/RedeemCardViewTest.php`, `apps/e2e/specs/redemptions.spec.ts`

**Interfaces:** `RedemptionDraft::qualifier_for( Redemption ): string`, `reward_qualifier( int ): string`; `Points\Context::REWARD_COUPON_PREFIX = 'yayloyalty-reward-'`, `Points\Context::is_loyalty_code( string ): bool`; `CouponProvider::REWARD_PREFIX`, `reward_code( int ): string`, `reward_id_of( string ): ?int`; `RedeemCard::heading( ?int $balance ): string`.

- [x] **Verify:** `composer test` passes; the card reads "Your rewards" and the balance on both builds.
- [x] **Committed:** `2fdcd09` refactor(redemption): key redemption ledger rows per reward; `a9a1603` feat(earning): treat reward coupons as loyalty discounts; `4e7d787` feat(storefront): title the points card "Your rewards" with the balance.

### Task 6: Rewards on the cart and the order

**Files:**
- Create: `includes/Engine/Redemption/RewardCoupons.php`, `includes/Pro/Services/DiscountRewardHandler.php`
- Modify: `includes/Engine/Rewards/{Context,ContextFactory}.php`, `Handlers/{RewardHandler,FlexibleDiscountHandler}.php` (`coupon_props()`), `includes/Pro/YayLoyaltyPro.php`, `includes/Engine/Redemption/{SessionIntent,RedemptionService,CouponProvider,CommitWriter}.php`, `Adapters/StoreApiAdapter.php`, `includes/Engine/Storefront/RedeemCard.php`, `includes/Repositories/RewardRepository.php` (`live_of_types()`), `includes/Engine/Earning/IdempotencyKey.php`
- Test: `tests/integration/Engine/Redemption/CommitWriterTest.php`, `ReversalTest.php`, `tests/integration/IntegrationTestCase.php`

**Interfaces:** spec, "Quote path" (Rewards), "Cart sync", "Checkout and commit" (rewards). `RewardHandler::coupon_props( Reward ): ?array`; `RewardCoupons::probe()`, `capped()`, `data()`, `displayed_subtotal()`, reasons `min_order`, `sale_items`, `no_value`; `SessionIntent::REWARDS_KEY`, `rewards()`, `set_rewards()`, `remove_reward()`; `RedemptionService::quote_rewards()`, `reward_options()`, `reward_for_code()`, `coupon_data_for()`, `is_reward_offered()`, reasons `rewards_balance`, `reward_unavailable`; `CommitWriter::commit_rewards( \WC_Order, array<string, ?Reward> ): Outcome`.

- [x] **Verify:** on the block cart with $100 of items and 9,850 Points, `wc.blocksCheckout.extensionCartUpdate({ namespace: 'yayloyalty-redeem', data: { rewards: [A, B] } })` makes the total $85.00; placing the order writes two rows and ledger −500 / −1,500; cancelling restores 9,850. `composer test:integration -- --filter 'CommitWriterTest|ReversalTest'` passes.
- [x] **Committed:** `8e2bcd7` feat(rewards): describe fixed and percent rewards as virtual coupons; `4e4d2c4` feat(redemption): keep chosen rewards in the session and quote them; `ad43ca4` feat(redemption): apply reward coupons on classic and block carts; `f795573` feat(redemption): debit each reward at checkout and reverse it.

### Task 7: The Rewards tab

**Files:**
- Create: `includes/Pro/Storefront/RewardsPanel.php`, `includes/Pro/Storefront/templates/rewards-panel.php`, `apps/e2e/specs/reward-redemptions.spec.ts`, `apps/e2e/support/storefront.ts`
- Modify: `includes/Engine/Storefront/RedeemCard.php` (`PANELS_FILTER`, `SLIDER_PANEL`), `templates/redeem-card.php`, `includes/Engine/Redemption/{CouponProvider,ProgramGate,RewardCoupons}.php`, `Adapters/StoreApiAdapter.php`, `includes/Pro/Services/DiscountRewardHandler.php`, `includes/Pro/YayLoyaltyPro.php`, `assets/storefront/{redeem.js,redeem-blocks.js,redeem.css}`, `apps/e2e/specs/{redemptions,purchase}.spec.ts`, `apps/e2e/support/api.ts`
- Docs: `docs/decisions/2026-10-14-loyalty-discounts-as-virtual-coupons.md`, `docs/architecture.md`

**Interfaces:** spec, "Storefront" (Rewards tab), "Integration points". Filter `yayloyalty_redeem_card_panels`; `ProgramGate::REASONS`; `window.yayloyaltyRedeemTransport( { points } | { rewards } )`.

- [x] **Verify:** `pnpm --filter @yayloyalty/e2e test redemptions reward-redemptions` passes (10 tests); a Lite build (`./scripts/run.sh build lite`) shows the slider with no tabs.
- [x] **Committed:** `193d310` feat(redemption): draw the card's panels as tabs beside the slider; `ca3cbfa` feat(rewards): list rewards to tick in the card's Rewards tab; `08218f1` feat(redemption): tick, apply and label rewards on classic and block carts; `c93edce` test(redemption): cover reward redemptions end to end and record the design.

### Task 8: Drop a saved choice the card can no longer show

**Files:**
- Modify: `includes/Engine/Redemption/CouponProvider.php` (`DROPPING_REASONS` gains `ProgramGate::REASONS`; `wanted_slider()` clears the intent when `ceiling()` is refused; `notice_dropped()`)

**Interfaces:** spec, "Cart sync", "Dropping a choice the card can no longer show". No signature changes.

- [x] **Verify:** on wp-env, with 500 Points applied (or one reward picked) and the program then paused, the next totals run leaves no loyalty coupon and an empty intent, adds the notice "Points discount was removed: this order no longer qualifies." (or "{name} was removed: …"), and `checkout_error()` returns null on two attempts in a row. Before the fix it returned `program_paused` (or `reward_unavailable`) on every attempt. `pnpm --filter @yayloyalty/e2e test redemptions reward-redemptions` still passes 10 of 10, including "checkout stops when the balance no longer covers the applied points".
- [x] **Committed:** `8e24efa` fix(redemption): drop a saved discount the card can no longer show.

## Not in this plan

The spec's Open questions 1–7 (order reuse after a failed payment, un-cancel after a full-policy refund, a stop the shopper cannot clear, earning after a refund, `refunded` → live, the percent cap on an edited order, and the stale Recalculate backlog item). Each needs a product decision; plan them separately once decided.
