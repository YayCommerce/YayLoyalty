# Group the campaign Reward step's Advanced fields

**Status:** active
**Branch:** `trace/feat/campaign-reward-advanced-layout`, from `dev/edd`
**Tests:** none (layout only, no points or money math changes). Verify by hand.

## Context

In the campaign editor (Pro), the Reward step of a **purchase** campaign has an "Advanced" toggle. When it opens, it shows 9 fields in one flat column with no headings: Base amount, Rounding, Include tax, Minimum order subtotal, Exclude coupon orders, Maximum points per order, Daily cap, Excluded products and Excluded categories. Related settings are far apart. For example, Rounding sits next to Base amount but away from the caps it interacts with, and the two eligibility gates sit in the middle of the amount settings. The open block also has no visual boundary, so it runs into the Conditions step.

The goal is to put each field where it belongs, in three headed groups ordered the way the engine applies them, inside a bordered panel. Signup and review rewards have no Advanced block and stay unchanged.

## Target layout (chosen with the user)

```
▾ Advanced  · <summary phrases when closed>
┌ rounded-lg border p-4 ───────────────────────────┐
│ Eligible orders                                   │
│   Minimum order subtotal   [ 0.00 USD ]           │
│   Exclude orders that used a coupon ....... [○]   │
│ ───────────────────────────────────────────────── │
│ Amount counted                                    │
│   Base amount   [After discounts|Before discounts]│
│   Include tax ............................. [○]   │
│   Excluded products     [Search products]         │
│   Excluded categories   [Search categories]       │
│ ───────────────────────────────────────────────── │
│ Rounding and caps                                 │
│   Rounding  [Down|Nearest|Up]                     │
│   Max points per order [   pts]  Daily cap [  pts]│  ← sm:grid-cols-2
└───────────────────────────────────────────────────┘
```

## Changes

All the changes are in `apps/admin/src/pro/campaigns/editor/`. This is Pro-only code that `CampaignEditorPage` already renders behind `IS_PRO_BUILD`, so it adds no new Pro/Lite exposure.

### 1. `CampaignEditorRewardPurchase.tsx`

- **`PurchaseAdvanced`:** keep the existing toggle button, its `aria-expanded`, the closed-state phrases and the open-on-mount rule. Wrap the open body in `<div className="flex flex-col gap-5 rounded-lg border p-4">`. The bordered pattern already exists in the condition rows in `CampaignEditorConditions.tsx`. Render the three groups with `<FieldSeparator />` between them.
- **Replace** `PurchaseCountingFields` and `PurchaseLimitFields` with three group components:
  - `PurchaseEligibilityFields`: Minimum order subtotal, then Exclude coupon orders.
  - `PurchaseAmountFields`: Base amount, Include tax, Excluded products, then Excluded categories.
  - `PurchaseRoundingFields`: Rounding, then Maximum points per order and Daily cap side by side in `grid gap-4 sm:grid-cols-2`.
- **Group heading:** each group is a `FieldSet` (`gap-4`) with a `FieldLegend variant="label"`, from `@/components/ui/field`, which exports them but uses none of them yet. Add a small local `PurchaseGroup({ legend, children })` helper so the three groups don't repeat that markup.
- **Descriptions:** keep only what the label doesn't already say: shorten min subtotal, coupon, base amount, max per order and daily cap; drop include tax, both exclusions and rounding.
- **Switch rows:** "Include tax" and "Exclude orders that used a coupon" become `Field orientation="horizontal"`, with the label and description in `FieldContent` and the `Switch` on the right. This fixes the current markup, where the `Switch` sits between the label and the description without a `FieldContent`.
- Keep every control, id, config key, default and `patchConfig` call exactly as it is. Only position and wrappers change, so saved data and the server contract don't change.
- Update the docblocks to one line each, per the minimal-comments preference.

### 2. `campaign-reward.copy.ts`

- Reorder the pushes in `purchaseAdvancedPhrases` to match the group order: min subtotal, no coupon orders, tax included, product exclusions, category exclusions, per-order cap, daily cap. The closed toggle then reads in the same order as the open panel. The wording stays the same.

### Out of scope

- No new Collapsible or Accordion primitive.
- No change to Signup, Review, the Lite built-in `PurchaseFields`, the schema or PHP.

## Steps

1. Branch from `dev/edd` as `trace/feat/campaign-reward-advanced-layout`.
2. Save this plan as `docs/plans/2026-10-07-campaign-reward-advanced-layout.md`.
3. Make edits 1 and 2.
4. Verify (below), then commit only when asked: `feat(campaigns): group the purchase reward's advanced fields`.

## Verification

- **Static:** run `pnpm --filter @yayloyalty/admin-page exec eslint src/pro/campaigns/editor/CampaignEditorRewardPurchase.tsx src/pro/campaigns/editor/campaign-reward.copy.ts`. The full lint and tsc already fail today, so judge only these two files against `dev/edd`. Run Vitest with `pnpm --filter @yayloyalty/admin-page test`, which should still pass.
- **Browser:** start `./scripts/run.sh dev pro` and check `git status` afterwards for sync rewrites. In wp-admin, open Campaigns, then a purchase campaign, then Reward, and check each of the following:
  1. A campaign with no advanced values opens with Advanced closed. Opening it shows a bordered panel with the headings "Eligible orders", "Amount counted" and "Rounding and caps", in that order, with the fields listed above.
  2. Toggling Include tax, setting Min subtotal 50, Max per order 100 and Daily cap 200, excluding one product and one category, then saving and reloading shows the same values. Advanced opens on mount, and closing it shows the phrases in the new order.
  3. At a narrow width (<640px), Max per order and Daily cap stack. Nothing scrolls horizontally.
  4. A Signup or Review campaign's Reward step looks the same as before.
