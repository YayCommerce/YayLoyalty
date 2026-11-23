---
id: P-9
title: Tier points: refunds reverse only the current period, adjustments choose
status: in-progress
from: 
roadmap: 
design:
trello:
type: feat
branch: feat/p-9-tier-points-refunds-reverse-only-the-current-p
pr:
editions: [pro, lite]
created: 2026-11-23
---
# P-9 Tier points: refunds reverse only the current period, adjustments choose

## Goal
A refund takes back tier points only when the refunded order belongs to the customer's current qualifying period. It lowers the customer's tier only when that refund itself is why they no longer qualify. When staff adjust points, they choose, separately from "Adjust lifetime totals too", whether the adjustment counts toward tier points.

Done means, with Silver 800 and Gold 3,000 (and Gold raised to 5,000 where stated):
1. **Lifetime, threshold raised:** a Gold customer at 3,500 points earned, with Gold now at 5,000, is refunded 10 points. They stay Gold.
2. **Lifetime, refund causes the drop:** a customer who reached Gold with a 3,000-point order is refunded 1,500 of it. They drop to Silver, reason `refund`, and keep the entry bonus.
3. **Rolling, after a mode switch:** a customer kept at Gold by the Lifetime → Rolling switch is refunded an order earned 2 years ago. They stay Gold, with the same hold.
4. **Calendar, previous period:** a customer holding Gold from last year's points is refunded 500 points of last year's order. They stay Gold, with the same hold. A refund of an order from the current period still counts.
5. **Adjust with tier points off:** staff add 1,000 points with "Count toward tier points" unticked. Balance and lifetime move, but tier, tier progress and the window totals don't. The same adjustment ticked counts, as today.
6. **Adjust deducting tier points:** staff deduct points with "Count toward tier points" ticked. The customer can still drop, as today. This is staff's way to lower a tier.

## Non-goals
- CSV import rows (`LedgerService::import()`). They keep counting toward tier points, as ADJUST rows do today.
- A ledger or activity label for "not counted toward tier points" in the drawer: BL-24.
- Any change to how refunds move the balance or `lifetime_earned`. Only tier points change.
- A warning in the Adjust dialog that a deduction will drop the customer's tier.
- Re-adding manual tier assignment.
- Bumping the plugin version. It stays `1.0.0` on this branch, as every tier slice notes.

## Findings
1. **Every negative ledger row re-judges the whole standing.** `TierDecision::ledger_policy()` maps any negative row to `MOVE_TO_TARGET` (`includes/Pro/Services/TierDecision.php:77-104`). `move()` then sends a Lifetime customer straight to the tier their total reaches now (`:108-127`), and `rolling_move()`'s `KIND_DOWN` branch drops a held customer when neither the window nor the qualifying total reaches the tier (`:158-171`). A customer kept by a raised threshold, a mode switch or last Calendar period therefore loses the tier on the first refund of any size, even one attributed outside the window. T3 adds a refund rule that compares the total before and after the row.
2. **"Before the row" needs no new query.** `LedgerRepository::window_total()` already attributes a clawback to its earn's date (`includes/Repositories/LedgerRepository.php:247-269`). Running it once more without the refund row (an `except_id` argument) gives the before-total. When the two totals are equal, the refund lies outside the period: that implements D-b in Lifetime and Rolling for free. T2 adds the argument.
3. **Calendar needs one more filter.** A refund of last period's order is attributed to last period, so it lowers the previous total that `calendar_decision()` reads (`includes/Pro/Services/TierEvaluator.php:487-489`) and the qualifying total (`:506`). D-b says it must not. A period total that ignores rows created after the period ended (a `created_by` argument on `window_total()`) does it, and it also stops a staff adjustment from changing a closed period through this path. Staff adjustments still reach the previous and qualifying totals through `staff_adjust_since()`, as today.
4. **Lifetime mode can't read `lifetime_earned` once adjustments can opt out.** The evaluator uses `$customer->get_lifetime_earned()` for Lifetime (`TierEvaluator.php:381`), and `TierStanding` falls back to it (`includes/Pro/Services/TierStanding.php:70`). An ADJUST row that doesn't count toward tier points still moves `lifetime_earned` (`includes/Services/LedgerService.php:83-104`). T3 makes Lifetime's tier total `window_total()` over all time, so all three modes sum the same rows with the same predicate.
5. **There is no column to record the choice.** The ledger row has `type` only: ADJUST or CORRECTION (`includes/Database/Schema/LedgerTable.php`). T1 adds a nullable `counts_toward_tier` to the Lite ledger table. NULL means "by type", so every existing row keeps its meaning and no backfill is needed.
6. **Refund-like rows are already defined in one place.** `clawback_row_sql()` treats a REFUND_EARN row, or an ADJUST row referencing a refund, as a clawback (`LedgerRepository.php:293-306`). The PHP side of the refund rule uses the same definition.
7. **One existing test encodes the old Calendar rule.** The second half of `test_calendar_refund_inside_and_outside_the_qualifying_period` expects a refund of last year's order to drop the held customer to Silver (`tests/integration/Services/TierEvaluatorTest.php:520-539`). D-b reverses it, so T3 flips that assertion. `test_rolling_refund_in_qualifying_window_drops` (`:235`) and `test_refund_downgrades_and_keeps_the_bonus` (`:128`) still hold: there, the refund is the cause.
8. **The Adjust option belongs to the Lite dialog, but tiers are Pro.** `AdjustPointsOptions` is shared by the single and bulk layouts (`apps/admin/src/pages/customers/components/adjust-points/AdjustPointsOptions.tsx`). The Customers page already gates tier UI with `IS_PRO_BUILD ? useTiersEnabled : () => false` (`apps/admin/src/pages/customers/CustomersPage.tsx:20`). T5 reuses that gate.
9. **The work must branch from `jason/feat/tiers`, not `dev`.** None of the tier code is on `dev` yet. `/yay:implement` starts the worktree from `jason/feat/tiers` (`yayh git worktree <branch> jason/feat/tiers`), and the PR targets `jason/feat/tiers`.
- differs: the rolling-mode design's Refinements row on D6 ("during a hold, a negative row is checked against the qualifying total"), and the calendar-mode design's accepted consequence ("a refund of an order from the qualifying period can drop a held customer"), in `docs/specs/2026-11-05-tier-rolling-mode-design.md` and `docs/specs/2026-11-11-tier-calendar-mode-design.md`. Also the "Points earned" definition in `docs/specs/2026-11-02-tiers-master-design.md` (D1) and `docs/knowledge/decisions.md`. T6 amends them.

## Approach
- **Decided** (with the user, 2026-11-23):
  - D-a: a refund lowers the tier only when it is the cause. Before the row the customer qualified for their tier on this mode's own total; after it, they don't.
  - D-b: "current period" is the whole history in Lifetime, the last N months in Rolling, and only the current period in Calendar. The user accepted the risk of a big order at the end of December being refunded in January while the customer keeps Gold all year.
  - D-c: a separate "Count toward tier points" option in the Adjust dialog, independent of "Adjust lifetime totals too".
- **Decided** (by the plan):
  - The new option defaults to ticked, so today's behaviour is unchanged.
  - It shows only in a Pro build with tiers on.
  - A staff adjustment counted toward tier points keeps today's rule (it may drop the customer), because it is staff's lever to lower a tier (D13).
- **Storage (Lite):** `ledger.counts_toward_tier tinyint(1) DEFAULT NULL`. A row counts toward tier points when `COALESCE(counts_toward_tier, type IN lifetime_earned_types)` is true. One PHP helper and one SQL fragment hold this rule. No migration: dbDelta adds the column. Lite never reads it, and a move to Lite keeps it.
- **Writes:** `LedgerService::adjust()` takes an optional `?bool $counts_toward_tier`. NULL keeps today's behaviour. It stays the only writer, so no new construction site.
- **REST (Lite):** `POST /customers/{id}/adjust` takes an optional boolean `adjust_tier`. When it is absent, the column stays NULL, so older clients and Lite behave as today.
- **Totals:** `window_total()` gains the tier predicate and two optional arguments: `except_id` (the before-total) and `created_by` (a closed period ignores rows created after it ended). `staff_adjust_since()` uses the same predicate over ADJUST and CORRECTION rows.
- **Evaluator (Pro):**
  - `ledger_policy()` decides on `counts_toward_tier` instead of the type alone, and marks clawback rows as refunds.
  - A refund row runs a new `TierDecision::refund_move()`. It compares the tier the mode's total reaches before the row with the tier it reaches after. In Calendar, that total is the better of the current period and the previous one, using `created_by`.
    - If the tier before was below the current tier (the customer is being kept), or the tier after still reaches it: no change.
    - Otherwise: move to the better of the floor and the tier after. Rolling and Calendar start a hold for a window tier, as today.
  - Lifetime's total becomes `window_total()` over all time.
- **SPA:** Lite plumbing (form state, zod schema, API field). The checkbox renders under "Adjust lifetime totals too" in both layouts, only while `IS_PRO_BUILD` and tiers are on.
  - Label: "Count toward tier points".
  - Helper: "Turn off to leave the customer's tier and tier progress unchanged."
- **Compatibility:** existing rows read NULL and count exactly as before. The mode-switch gap is covered by D-a: a customer the `to_rolling` or `to_calendar` sweep hasn't reached yet doesn't reach their tier on the new mode's total, so a refund keeps them. A staff deduction in that gap can still drop them (accepted, see Risks).

## Tasks
- [x] T1 Ledger column and the tier-points rule — `includes/Database/Schema/LedgerTable.php`, `includes/Models/LedgerEntry.php`, `includes/Enums/LedgerType.php` [risk: 3]
  - Reuse: `LedgerType::affects_lifetime_earned()` and `lifetime_earned_types()` (`includes/Enums/LedgerType.php:52-58`); the `tier_multiplier` column pattern in `LedgerEntry::from_row()`, `to_row()` and `get_format()` (not `to_array()`: nothing reads the field over REST until BL-24)
  - Callers: `LedgerEntry::get_counts_toward_tier()` → `TierDecision::ledger_policy()` callers in `TierEvaluator::on_ledger_entry()` (T3); `LedgerType::counts_toward_tier()` → `TierEvaluator::on_ledger_entry()` (T3)
- [ ] T2 Tier-aware totals in the ledger repository — `includes/Repositories/LedgerRepository.php`, `tests/integration/Repositories/LedgerWindowTotalTest.php` [risk: 3]
  - Reuse: `window_total()`, `staff_adjust_since()`, `clawback_row_sql()`, `sum_points()`, `Sql::in_placeholders()`; none for the predicate, new private `tier_points_sql()` because the type list alone can't express the override
  - Callers: `tier_points_sql()` → `window_total()`, `staff_adjust_since()`; `window_total()`'s `except_id` → `TierEvaluator::refund_totals()` (T3); its `created_by` → `TierEvaluator::calendar_decision()` (T3)
- [ ] T3 Refund rule in the evaluator, and Lifetime on tier points — `includes/Pro/Services/TierDecision.php`, `includes/Pro/Services/TierEvaluator.php`, `includes/Pro/Services/TierStanding.php`, `tests/unit/Pro/TierDecisionTest.php`, `tests/integration/Services/TierEvaluatorTest.php` [risk: 3]
  - Reuse: `TierDecision::target()`, `move()`, `rolling_move()`'s private `best()` and `hold_for()`; `TierCalendar::pick()` and `bounds()`; `LedgerRepository::window_total()` (T2); the existing `plan()` and `apply()` flow; `TierChangeReason::REFUND`
  - Callers: `TierDecision::refund_move()` → `TierEvaluator::apply()` for plans of the new `KIND_REFUND`; `TierDecision::KIND_REFUND` → `TierEvaluator::on_ledger_entry()`; private `TierEvaluator::refund_totals()` → `apply()`
- [ ] T4 Adjust write path and REST argument — `includes/Services/LedgerService.php`, `includes/Controllers/CustomerRestController.php`, `includes/Controllers/Schemas/CustomerRouteArgs.php` [risk: 3]
  - Reuse: `LedgerService::adjust()` and `record()`; the `adjust_lifetime` argument's shape in `CustomerRouteArgs`; `rest_sanitize_boolean()`
  - Callers: `adjust()`'s `$counts_toward_tier` → `CustomerRestController::adjust_points()` → route `POST /customers/{id}/adjust`
- [ ] T5 Adjust dialog option, Pro and tiers on only — `apps/admin/src/pages/customers/components/customers-adjust-points.form-state.ts`, `apps/admin/src/pages/customers/components/customers-adjust-points.copy.ts`, `apps/admin/src/pages/customers/components/adjust-points/AdjustPointsOptions.tsx`, `apps/admin/src/pages/customers/components/CustomersAdjustPointsDialog.tsx`, `apps/admin/src/lib/schema/customer.schema.ts`, `apps/admin/src/lib/api/customer.api.ts`, `apps/admin/tests/customer.schema.test.ts`
  - Reuse: `useTiersEnabled` through `@/pro` with the `IS_PRO_BUILD ? … : () => false` gate (`CustomersPage.tsx:20`); `Checkbox` and the "Adjust lifetime totals too" row markup; `__()`
  - Callers: `adjustTier` form field → `AdjustPointsOptions` and `CustomersAdjustPointsDialog::handleSubmit()`; `ADJUST_TIER_LABEL` and `ADJUST_TIER_SUBTEXT` → `AdjustPointsOptions`; the `adjust_tier` wire field → `adjustCustomerPoints()`
- [ ] T6 Specs and knowledge: the refund rule, the Calendar period rule, tier points vs lifetime — `docs/specs/2026-11-02-tiers-master-design.md`, `docs/specs/2026-11-05-tier-rolling-mode-design.md`, `docs/specs/2026-11-11-tier-calendar-mode-design.md`, `docs/knowledge/decisions.md`, `docs/knowledge/architecture.md` [risk: 1]
  - Reuse: n/a

**Tests:** [unit] TierDecisionTest::test_refund_move_* (tests/unit/Pro/TierDecisionTest.php) — kept customer stays, refund that causes the loss drops to the better of floor and after-tier, a hold starts only for a window tier, and `ledger_policy()` honours the override and flags clawbacks; [integration] LedgerWindowTotalTest::test_counts_toward_tier_*, test_except_id_*, test_created_by_* (tests/integration/Repositories/LedgerWindowTotalTest.php) — the override in both directions, the before-total, and a closed period ignores later rows; [integration] TierEvaluatorTest::test_refund_* (tests/integration/Services/TierEvaluatorTest.php) — Goal 1–5 against real ledger writes, plus the flipped Calendar assertion (Finding 7); [js] customer.schema.test (apps/admin/tests/customer.schema.test.ts) — `adjustTier` is optional and maps to `adjust_tier` only when set

**E2E:** none

## Verify
- `composer test` → exit 0 (unit)
- `composer test:integration` → exit 0 (integration)
- `yayh test run js` → exit 0 (js)
- `pnpm --filter @yayloyalty/admin-page exec tsc --noEmit` → exit 0
- `pnpm --filter @yayloyalty/admin-page lint` → exit 0
- `./scripts/run.sh build lite` → exit 0
- `./scripts/run.sh build pro` → exit 0
- `cd tests/e2e && ./node_modules/.bin/wp-env run cli wp db query "SHOW COLUMNS FROM wp_yayloyalty_ledger LIKE 'counts_toward_tier'"` → counts_toward_tier
- Pro, tiers on: Customers → a customer → Adjust points shows "Count toward tier points", ticked, under "Adjust lifetime totals too". Add 1,000 points with it unticked. Then `wp db query "SELECT type, counts_toward_tier FROM wp_yayloyalty_ledger ORDER BY id DESC LIMIT 1"` shows `adjust 0`, and the drawer's tier card progress is unchanged.
- Pro, tiers off, and Lite: the Adjust dialog shows no tier option. An adjustment writes `counts_toward_tier` NULL.
- Pro, Lifetime, Gold raised from 3,000 to 5,000: refund 10 points of an order for a Gold customer at 3,500. They stay Gold, and the activity log shows no `TIER_CHANGED` row (integration).
- Pro, Calendar yearly: refund last year's order for a customer holding Gold from last year. They stay Gold, with the same "held until" date in the drawer (integration).
- `yayh code unused jason/feat/tiers` → exit 0

## Risks
- **Tier points and `lifetime_earned` diverge on purpose.** Reports and the reward `lifetime_points` condition still read `lifetime_earned`. Support may see a customer whose lifetime total passes Gold while they aren't Gold. The drawer's tier card shows the tier total, which explains it. Rollback: drop the column read (the predicate falls back to type), and NULL rows behave as today.
- **Abuse window in Calendar (accepted, D-b).** A large order at the end of a period earns a tier for the whole next period, and refunding it after the period ends doesn't take the tier back.
- **Mode-switch gap for staff deductions.** A tier-counted staff deduction made before the `to_rolling` or `to_calendar` sweep reaches the customer can still drop them. Refunds can't (D-a). Accepted: the sweep drains in minutes, and a staff deduction is deliberate.
- **Query cost.** Lifetime now sums the customer's ledger rows on each tier evaluation instead of reading one column, and a refund runs `window_total()` twice. Both use `idx_user_created`. This is the same cost Rolling and Calendar already pay.
- **Existing dev sites** need `yayloyalty_db_version` reset (or deactivate and reactivate) for dbDelta to add the column, as every tier slice notes.
- Deferred: BL-24, a drawer label for adjustments not counted toward tier points.
