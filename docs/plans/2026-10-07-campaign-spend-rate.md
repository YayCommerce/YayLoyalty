# Campaign rate as "Spend X to receive Y"

**Status:** done 2026-10-07
**Branch:** rename the current, unpublished `trace/feat/campaign-rate-equation` to `trace/feat/campaign-spend-rate`. It's based on `origin/dev/edd` and holds the uncommitted "1 ₫ (VND) =" draft, which this change replaces.
**Tests:** none. Storage, server and points calculation stay unchanged. Verify by hand.

## Context

Today the Pro campaign editor asks for "points per 1 currency unit". That's awkward when a point is worth more than one unit, for example "1 point per 10,000 ₫" or "1 point per $2". The user wants the admin to enter **Spend X → receive Y**. The editor then **calculates the stored rate**: `points_per_unit = Y ÷ X`, so $2 → 1 pt stores 0.5. Only `points_per_unit` is stored: no new key, no PHP change, no migration. The engine, the launcher and every label keep reading the same rate.

## Changes

### 1. A pure helper pair in `apps/admin/src/lib/helpers/points.helper.ts`
- `rateFromSpend(spend, points)` returns `points / spend`, or `null` when `spend <= 0`, matching the zero-denominator rule in `PointsMath.php`.
- `spendPairFromRate(rate)` returns the `{ spend, points }` shown when a campaign is reopened:
  - **rate ≥ 1 and whole:** `{ 1, rate }`.
  - **Otherwise:** the smallest whole `spend` from 1 to 100 where `rate × spend` is a whole number, within 1e-6. So 0.5 gives `{2, 1}` and 0.75 gives `{4, 3}`.
  - **Fallback:** `{ round(1 / rate, currencyDecimals()), 1 }`. So 0.0001 gives `{10000, 1}`.
  - Only the stored rate exists, so a pair like 4 → 3 reopens as 4 → 3, but 6 → 3 reopens as 2 → 1, the same rate. That's acceptable.

### 2. Pro editor: `apps/admin/src/pro/campaigns/editor/CampaignEditorRewardPurchase.tsx` (Rate field)
- The row reads **Spend** `[X | ₫]` **to receive** `[Y | pts]`. It's a flex row of two `NumberInputRoot`s.
  - **X input:** unit `storeCurrencySymbol()`, `decimalScale={currencyDecimals()}`, `min` set to its smallest step.
  - **Y input:** whole points, `min={1}`.
- X and Y live in local `useState`, seeded once from `spendPairFromRate(config.points_per_unit ?? 1)`. Typing in either one calls `patchConfig({ points_per_unit: rateFromSpend(x, y) })` while both are above 0, and the form never re-derives the pair while the admin types.
- `FieldDescription` shows the calculated rate: "= 0.5 pts per $1.00. Advanced sets which part of the order counts." It uses `pointsPerCurrencyLabel` with the computed rate, trimmed to at most 4 decimals for display.
- Remove the draft's "1 %1$s (%2$s) =" span and `SMALLEST_PURCHASE_RATE`. Keep `storeCurrencySymbol()` in `format.helper.ts`, which is already added on this branch.
- The `points_per_unit > 0` guard stays as it is today.

### 3. Labels read the same way (optional polish, one line)
- `pointsPerCurrencyLabel(rate)` in `points.helper.ts` uses `spendPairFromRate`, so the campaigns table, dashboard and pause/archive copy say "1 pt per $2.00" instead of "0.5 pts per $1.00".
- Rates that are whole and at least 1, which covers every Lite rate, print exactly as today.

### Out of scope
- **Lite** `BuiltInCampaignList`: its whole-number "N points per ₫1" input stays.
- **PHP, launcher copy, schema:** they all still read `points_per_unit`.
- **Existing float edge:** the engine floors and ceils `base × rate` with no epsilon, which is already true for any decimal rate today. A repeating rate like 1 ÷ 3 can lose a point on exact multiples under Up or Down rounding. Note it as a backlog follow-up rather than changing the engine here.

## Steps
1. Run `git branch -m trace/feat/campaign-spend-rate`.
2. Save this plan as `docs/plans/2026-10-07-campaign-spend-rate.md`.
3. Make changes 1–3.
4. Commit only when asked: `feat(campaigns): enter the purchase rate as spend X to receive Y`.

## Verification
- Run eslint and prettier on the touched files. Run `tsc --noEmit`, filtered to the touched paths. Run Vitest, which should stay at 64 passed.
- In the browser, in the Pro campaign editor for a purchase campaign:
  1. **Spend 2, receive 1:** the description shows 0.5 pts per 1 unit. Save, then confirm the stored config through `GET campaigns/<id>` (read in the page with `javascript_tool`) has `points_per_unit: 0.5`. Reloading shows Spend 2 → receive 1.
  2. **VND store:** spend 10,000, receive 1 stores 0.0001 and reopens as 10,000 → 1.
  3. **Existing campaign at rate 3:** it opens as Spend 1 → receive 3, unchanged.
  4. **Campaigns table:** the Gives column reads "1 pt per …2" for the 0.5 campaign.
- Saving changes campaign data, so ask before saving on a real campaign, or use a draft campaign made for the test.
