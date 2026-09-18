---
title: "F09 Customer Drawer — Wave B"
description: "The six artboards Wave A left undrawn: Activity log, deleted-account, expiring and negative-balance panels, Redemptions and Referrals tables. UI on fixtures; no real-data work."
status: pending
priority: P2
effort: 26h
branch: paul/feat/customer-drawer
tags: [f09, customers, drawer, fixture, spa, zod, pro-gate]
created: 2026-09-18
---

# F09 Customer Drawer — Wave B

Brief: [`plans/reports/brainstorm-260918-0845-customer-drawer-wave-b.md`](../reports/brainstorm-260918-0845-customer-drawer-wave-b.md).
Every decision there is locked. This plan turns it into phases.

Wave A (complete): [`plans/260917-1504-customer-drawer-wave-a/`](../260917-1504-customer-drawer-wave-a/plan.md).
Read its phase files for the patterns this wave continues — they are not restated here.

Design: `~/Downloads/YayLoyalty_Customer_Drawer.dc.html`. Wave B artboards: **3d, 3e, 3f, 4a, 4b, 5a, 5c**
— every artboard the design carries that is not already built. After this wave none are left.

**5a is labelled F23, not F09** ("Negative balance, explained in the drawer · Depends on F09 · Size S
· FREE + PRO"). It is a separate feature renting space on the drawer canvas, which is exactly how it
slipped out of this plan's first draft — Wave A had deferred it, but it is absent from the canvas's
F09 artboard run. It is phase 04 here; keep the F23 label in the code so it cannot drift again.

## Scope

In: Activity log tab complete (3d), 4a deleted-account panels, 4b expiring panels + lot batches,
5a negative-balance explanation, Redemptions table (3e), Referrals table (3f), a mobile pass over
everything new (5c), docs sync.

Out (unchanged from Wave A, still not this wave): real SQL for ledger filters, real `type_counts`,
`customers.deleted_at` column, Export ledger job, Reset expiry endpoint, real tiers table, any
Activity-log/redemptions/referrals TABLE. **This wave adds no schema and no migration.**

Out (design elements deliberately dropped): 3e's summary strip and "Rewards X could use now",
3f's summary strip and "Revenue referred", 4b's forecast ledger row, real Anonymise logic,
5a's two actions wired to anything.

## Phases

| # | Phase | Effort | Status |
|---|---|---|---|
| 01 | [Activity log tab (3d)](phase-01-activity-log-tab.md) | 5h | complete |
| 02 | [Deleted-account panels (4a)](phase-02-deleted-account-panels.md) | 3h | complete |
| 03 | [Expiring panels + lot batches (4b)](phase-03-expiring-panels-and-lots.md) | 4h | pending |
| 04 | [Negative-balance explanation (5a)](phase-04-negative-balance-panel.md) | 3h | pending |
| 05 | [Redemptions table (3e)](phase-05-redemptions-table.md) | 4h | pending |
| 06 | [Referrals table (3f)](phase-06-referrals-table.md) | 4h | pending |
| 07 | [Mobile pass + docs sync (5c)](phase-07-mobile-pass-and-docs-sync.md) | 3h | pending |

## Dependencies

- 01 is independent — its fixture (`DrawerActivity.php`) already exists.
- 02 → 01 only for the shared header/panel conventions; otherwise independent.
- 03 → 02 and 04 → 02: all three are header-level panels driven by a nullable wire block, and 02
  sets the composition the other two reuse. At most one of the three ever renders at a time
  (deleted / expiring / negative are distinct scenarios) — each phase asserts that rather than
  designing a stacked layout.
- 02, 03 and 04 each add an optional block to `DrawerProfiles`. Whichever phase pushes it toward the
  200-line cap splits the scenario table out; do not defer that to the next one.
- 05 and 06 are independent of each other and of 01–04; 05 first sets the `ProGate` tab pattern 06 copies.
- 07 → 01–06. It is a pass over everything they add, so it must be last.

## Hard constraints (apply to every phase)

- **Every file < 200 lines.** The drawer fixture is already 5 files / 527 lines.
- Fixture emits **structured fields, never prose**. 4a and 4b carry the design's heaviest prose;
  all of it lives in `customer-drawer.copy.ts`.
- Every new payload gets a **Zod mirror parsed identically on the fixture and real paths**. This is
  the one discipline that keeps the eventual data swap an input change rather than a rewrite.
- **Figures never abbreviated**: `formatPoints`, never `formatCompactPoints`/`<PointsValue>`.
- The **ledger holds only real point movements** — no forecast rows, no zero-point rows.
- All four tabs in **both builds**; Free gates Redemptions/Referrals bodies with the existing
  `components/pro/ProGate.tsx`. No new `IS_PRO_BUILD` branch in the drawer.
- Tier ordering stays wrong on purpose (see the brief). Do not "fix" it here.
- kebab-case TS modules, PascalCase React/PHP classes. Caps at the HTTP boundary only.
- YAGNI / KISS / DRY.

## Verification

`?scenario=` reaches every new panel through the hash route, dev server only. Scenarios unchanged:
*(none)*, `new`, `large`, `deleted`, `expiring`, `negative`.

Commands: `composer lint`, `composer test`, `pnpm --filter @yayloyalty/admin-page test`,
`tsc --noEmit`, `vite build`.

**No phase may claim visual verification.** This environment has no browser-automation tool and dev
port 3030 is occupied. Every phase file must say so explicitly, as Wave A's did.
