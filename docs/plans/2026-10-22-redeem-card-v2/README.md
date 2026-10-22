# Redeem Card v2 Implementation Plan

> **For agentic workers:** execute phases in order. Read this file, then the current phase file. Do not start a phase until the previous phase's exit criterion has been verified and `/yayplancheck` has passed on it.

**Status:** active
**Goal:** The points discount and Pro rewards stack on one cart and one order, and the card becomes one checkbox list: instant on the cart, collapsed summary plus a draft with Update on the checkout.
**Spec:** [docs/specs/2026-10-22-redeem-card-v2-design.md](../../specs/2026-10-22-redeem-card-v2-design.md)
**Architecture:** Rewards keep their quote against the full balance. `RedemptionService::quote_for()` then quotes the slider against what the allowed rewards leave, so the card, the cart sync, the Store API data and checkout validation agree from one place. A new `CommitWriter::commit_all()` debits a stacked order in one transaction under one lock. The alternative, two existing commits back to back, could leave an order half debited.
**Touches:**
- `includes/Engine/Redemption/SessionIntent.php` (shared: `CouponProvider`, `RedemptionService`, `RedeemCard`, `StoreApiAdapter`)
- `includes/Engine/Redemption/RedemptionService.php` (shared: both adapters, `CouponProvider`, `RedeemCard`, Pro `RewardsPanel`)
- `includes/Engine/Redemption/CouponProvider.php`, `CommitWriter.php`, `Adapters/StoreApiAdapter.php`
- `includes/Engine/Rewards/Context.php` (shared: every reward handler and `Clamp`; the new wither is additive)
- `includes/Engine/Storefront/RedeemCard.php`, `templates/redeem-card.php`, `LauncherPayload.php` (worth helper made public)
- `includes/Pro/Storefront/RewardsPanel.php`, `templates/rewards-panel.php`
- `assets/storefront/redeem.js`, `redeem.css`
- `tests/integration/IntegrationTestCase.php`, `tests/integration/Engine/Redemption/`, `tests/unit/Engine/Storefront/RedeemCardViewTest.php`
- `apps/e2e/specs/redemptions.spec.ts`, `reward-redemptions.spec.ts`
- `docs/decisions/`, `docs/specs/2026-10-16-redemption-design.md`, `docs/architecture.md`

**Tests** (accepted in the spec):
- `tests/integration/Engine/Redemption/CommitWriterTest.php` (integration): `commit_all()` writes reward and slider rows, their `redeem` rows and summed meta in one transaction; a re-fire answers `duplicate`; a summed cost above the locked balance writes nothing.
- `tests/integration/Engine/Redemption/StackedQuoteTest.php` (integration, new): with rewards picked, the slider's `quote()` and `ceiling()` use balance − Σ cost and cart − Σ value; `checkout_error()` is null when the slider applies 0 beside an applied reward, and `rewards_balance` when the rewards are not covered.
- `tests/unit/Engine/Storefront/RedeemCardViewTest.php` (unit): updated for the `slider` state, plus `test_a_ticked_slider_the_rewards_leave_nothing_for_stays_ticked_with_the_balance_note` (spec AC 5; accepted 2026-10-22 while expanding phase 2).
- `apps/e2e/specs/reward-redemptions.spec.ts` and `redemptions.spec.ts` (Playwright): rewritten for the list UI and the spec's AC 17.

## Global constraints

- Product PHP runs on PHP 7.4: no enums, `match`, `readonly`, promoted constructor properties, `?->`, named arguments, union/`mixed`/`static`/`never` types, catch without a variable, `throw` expressions, trailing comma in declarations.
- Only `LedgerService` writes ledger rows and balances, from its four construction sites; `CommitWriter` stays the only inserter of redemption rows.
- Lite names Pro only in `includes/YayLoyalty.php`; nothing under `includes/Engine/` imports `YayLoyalty\Pro\*`; Pro attaches through `yayloyalty_redeem_card_rows`.
- The storefront scripts and styles are plain JS/CSS with no build step and no JSX.
- No sentence hard-codes "points": counts through `SettingsHelper::points_text()`, the label through `points_label_plural()`; money through `LauncherCopy::money()`.
- The card's inputs have no `name`. The card reads nothing from the Design tab.
- Every new string uses the `yayloyalty` text domain, with a translators comment where it has placeholders.
- Classic checkout and Blocks are both supported; orders through WooCommerce CRUD only (HPOS).
- Every hook the plugin fires has a docblock.
- Commits: Conventional Commits `type(scope): summary`, ending with `Co-Authored-By: Claude <model> <noreply@anthropic.com>`. Stage files by name.
- Branch `paul/improve/redeem-card`; its PR goes into `dev/edd`.
- Checks that fail on the base today ([docs/known-issues.md](../../known-issues.md)): `composer lint` over the whole repo, and `pnpm --filter @yayloyalty/admin-page lint`. Lint only changed files with `composer lint -- <files>`; expected: no new errors or warnings in them. `composer test:integration` exit 0 does not prove it ran: check the reported test count.

## Review focus

- **Slider re-clamp on every totals run.** Each slider quote now calls `quote_rewards()`, which dry-runs a `WC_Discounts` per picked reward. Without the memo a cart page runs it once per coupon load. Owner: phase 1 task 2, verification step (count calls).
- **Slider "[Remove]" keeping the rewards.** `on_removed()` used `clear()`, which drops both; it must become `set( 0 )`. Owner: phase 1 task 1, verification step.
- **Checkout with the slider at 0 beside rewards.** It must pass, not stop with `reward_min`/`balance`. Owner: phase 1 task 2, test `test_checkout_passes_when_the_rewards_use_the_whole_balance`.
- **Commit after the balance dropped below rewards + slider.** The slider's re-quote uses the locked balance minus the rewards' cost; the whole order fails with nothing written. Owner: phase 1 task 3, test `test_a_stacked_order_above_the_balance_writes_nothing`.
- **Blocks card shared between Cart and Checkout.** The Store API card HTML is identical on both; checkout mode must come from the client (`form.checkout`, `.wp-block-woocommerce-checkout`). Owner: phase 3 task 1, verification step on the block cart and block checkout.
- **A checkout totals refresh while a draft is open** (address change) replaces the card and loses the draft; it must reopen from server state with no console error. Owner: phase 3 task 1, verification step.

## Phases

| Phase | Delivers (user-visible) | Exit criterion (exact check) | Status |
|---|---|---|---|
| 1 | [Stacking engine](phase-1-stacking-engine.md): rewards and the points discount on one cart and one order, through today's card with its exclusivity notices removed | `composer test:integration -- --filter 'StackedQuoteTest\|CommitWriterTest'` passes with the new tests counted; spec AC 9 by hand on the classic checkout | done (yayplancheck PASS 2026-10-22) |
| 2 | [List card on the cart](phase-2-list-card.md): header with worth, guest card, Points discount row, reward rows, instant apply, everywhere the card shows | Spec AC 1–8, 12, 13 by hand on the classic cart and the Cart block; `composer test -- --filter RedeemCardViewTest` passes | implemented (yayplancheck PASS 2026-10-22; by-hand AC checks pending) |
| 3 | [Checkout mode, e2e and docs](phase-3-checkout-mode.md): collapsed summary, draft + Update at checkout, rewritten e2e, decision record and doc amendments | Spec AC 15–17: `pnpm --filter @yayloyalty/e2e test -- reward-redemptions redemptions` passes | pending |

Between phase 2 and the end of phase 3 the two e2e specs fail (they click tabs and Apply). That is expected; phase 3 task 2 rewrites them.

## Interface contract

- `SessionIntent::set( int $points ): void` and `set_rewards( array $reward_ids ): void` — phase 1 task 1: neither clears the other key any more. `clear()` unchanged.
- `RedeemCard::on_apply()` and `StoreApiAdapter::on_update( $data )` — phase 1 task 1: accept `points`, `rewards`, or both, each applied to its own key; `rewards` applied first. Consumed by phase 2 task 3 and phase 3 task 1 (`redeem.js` sends `{ points, rewards }` on Update).
- `Context::with_cart_subtotal( float $cart_subtotal ): self` — phase 1 task 2.
- `RedemptionService::quote( \WC_Cart $cart, ?int $user_id ): Result` and `ceiling(…)` — phase 1 task 2: net of the allowed picked rewards. Consumed by phase 2 task 1 (`view()`'s `max`).
- `CommitWriter::commit_all( \WC_Order $order, array $rewards, Reward $flexible ): Outcome` — phase 1 task 3; `$rewards` is `array<string, Reward|null>` keyed by coupon code, as `commit_rewards()`.
- `RedemptionService::NOTICE_EXCLUSIVE_SLIDER`, `NOTICE_EXCLUSIVE_REWARDS` — removed in phase 1 task 2.
- `LauncherPayload::worth( Reward $reward, int $balance ): ?float` — phase 2 task 1, made `public static` (today private static). Consumed by `RedeemCard::heading()`.
- `RedeemCard::view( ?int $balance, Result $quote, Result $ceiling, int $requested ): array` — phase 2 task 1. Returns `[ 'state' => 'guest' ]`, `[ 'state' => 'hidden' ]`, or `state` `slider` with `requested`, `points`, `value`, `balance`, `min`, `step`, `max`, `max_value`, `rate`, `is_disabled`, `bound_by`.
- `RedeemCard::heading( ?int $balance, ?float $worth ): string` — phase 2 task 1.
- `RedeemCard::ROWS_FILTER = 'yayloyalty_redeem_card_rows'`, `( string[] $rows, array{service: RedemptionService, cart: \WC_Cart, user_id: int, balance: int} $context ): string[]` — phase 2 task 1; replaces `PANELS_FILTER`, `SLIDER_PANEL`, `tabs()`. Consumed by phase 2 task 2 (`RewardsPanel::rows()`).
- Template view key `summary` — `array{points: int, count: int}` (`value: float` until 2026-10-22, when the checkout summary changed to the mock's "✓ N rewards selected"), phase 2 task 1; consumed by phase 3 task 1.
- Card markup hooks for JS/CSS — phase 2 task 1/2, consumed by phase 2 task 3 and phase 3 task 1: root `[data-yl-redeem]` with `data-endpoint`, `data-nonce` and money data attributes as today; Points discount checkbox `[data-yl-slider-toggle]`; slider body `[data-yl-slider-body]`; `[data-yl-range]`, `[data-yl-points]`, `[data-yl-off]` as today; reward checkbox `[data-yl-reward]` with `value`, `data-cost`, `data-denied` as today; reward list wrapper `[data-yl-rewards]` with `data-balance` (`data-short-text` dropped with the rows' text); summary `[data-yl-summary]`, Change `[data-yl-change]`, Update `[data-yl-update]`, both with `aria-controls` the list `[data-yl-list]`. Phase 3 task 1 adds `data-applied` on the root (`summary.points > 0`), `data-requested` on the Points discount checkbox, and the script-set `data-mode` (`cart` | `checkout`) and `data-expanded` on the root.
