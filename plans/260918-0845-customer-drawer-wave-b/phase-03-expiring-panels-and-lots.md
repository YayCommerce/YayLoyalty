# Phase 03 — Expiring panels + lot batches (4b)

## Context Links

- Brief → [`brainstorm-260918-0845-customer-drawer-wave-b.md`](../reports/brainstorm-260918-0845-customer-drawer-wave-b.md)
- Artboard **4b**
- Phase 02 — sets the panel composition this phase reuses
- [`docs/data-model.md`](../../docs/data-model.md) → `lots` / `lot_allocations` (Pro 1.0, **not implemented**)
- `includes/Controllers/Fixtures/CustomerDrawer/DrawerProfiles.php` — the `expiring` scenario row
- `apps/admin/src/components/pro/ProGate.tsx`

## Overview

- Priority: P2
- Status: pending
- 4b answers "how much is he about to lose, was he warned, and can I stop it" — in money, in
  proportion, and with the reminder schedule. The lot-batch table is the Pro FIFO model made visible.

## Key Insights

- **The forecast ledger row is NOT built.** 4b draws "14 Aug 2027 · Scheduled — 6,300 points will
  expire · *not yet written · shown from the expiry queue*" as a ledger row. The ledger is
  append-only, immutable, written history — the entire Wave A ledger UI, its running-balance column
  and its pagination all assume every row is a real DB row. A synthetic row breaks paging (it has no
  id and no position) for information the panel above **already states in words**. Decision locked
  in the brief: panel copy only.
- **The zero-point "Expiry reminder sent" ledger row is NOT built either.** 3d puts sent emails in
  the Activity log and says explicitly that point movements are not repeated there; 4b contradicts
  it. 3d wins. The ledger only ever holds real point movements.
- **Lot data does not exist in any form.** `lots`/`lot_allocations` are unimplemented, and the
  `expiring` scenario carries no batch data today. This phase invents the fixture shape — and it is
  the future contract, so decide it as carefully as Wave A decided the activity row shape.
- **The batch panel is Pro-only and must say what Free does instead.** 4b's own note: "On the free
  inactivity model there are no batches: the whole balance moves together and this panel does not
  appear." So the panel is `ProGate`d *and* Free gets a one-line explanation, not a blank.
- **Two of the three actions are new and disabled**: "Exempt him from expiry" and "Send the reminder
  now" have no endpoint. "Reset his expiry" is already disabled elsewhere in the drawer for the same
  reason; keep the reasons consistent with `CustomerDrawerActions.tsx`.
- Currency equivalents come from `worth.points_per_currency_unit`, already on the wire — the same
  source the (now removed) Worth stat used. No new rate plumbing.

## Requirements

Functional
1. When the customer has an expiring batch, render a warning banner stating: points expiring, the
   date, days remaining, the money equivalent, the share of balance, and the reminder schedule.
2. Three actions beneath it, all disabled with their own reasons.
3. Four stats with currency equivalents: Balance, Expiring in N days, Safe beyond {year}, Pending.
4. A Pro lot-batch table (Earned range / Points / Expires), `ProGate`d, with the Free explanation
   in its place on Lite.
5. Nothing renders when there is no expiring batch.
6. **No ledger row is added, invented or synthesised anywhere in this phase.**

Non-functional
- Every file < 200 lines; all prose in the copy module.
- Figures unabbreviated; money via the existing currency helper.

## Architecture

```
includes/Controllers/Fixtures/CustomerDrawer/
  DrawerExpiry.php            NEW — expiry summary + lot batches per scenario
  CustomerDrawerFixture.php   MODIFY — no new endpoint; folds into the customer payload

apps/admin/src/
  lib/schema/customer.schema.ts            MODIFY — nullable `expiry` block
  pages/customers/drawer/
    CustomerDrawerExpiryPanel.tsx          NEW — banner + three actions
    CustomerDrawerExpiryStats.tsx          NEW — four stats with money
    CustomerDrawerLotBatches.tsx           NEW — Pro table + Free explanation
    CustomerDrawer.tsx                     MODIFY
    customer-drawer.copy.ts                MODIFY
```

The expiry block rides on `GET /customers/{id}` rather than a new endpoint: it is header-level
information about one customer, exactly like `counts` and `worth`, and 4b renders it above the tabs.
One request, no extra round trip.

## Related Code Files

Create
- `includes/Controllers/Fixtures/CustomerDrawer/DrawerExpiry.php`
- `apps/admin/src/pages/customers/drawer/CustomerDrawerExpiryPanel.tsx`
- `apps/admin/src/pages/customers/drawer/CustomerDrawerExpiryStats.tsx`
- `apps/admin/src/pages/customers/drawer/CustomerDrawerLotBatches.tsx`

Modify
- `includes/Controllers/Fixtures/CustomerDrawer/DrawerProfiles.php` (delegate to `DrawerExpiry`)
- `includes/Controllers/CustomerRestController.php` (emit `expiry: null` on the real path)
- `apps/admin/src/lib/schema/customer.schema.ts`
- `apps/admin/src/pages/customers/drawer/CustomerDrawer.tsx`
- `apps/admin/src/pages/customers/drawer/customer-drawer.copy.ts`

Delete
- none

## Implementation Steps

1. Write down the `expiry` wire shape first — it is the future `lots` contract:
   ```
   expiry: {
     expiring_points: int, expires_at: string, safe_points: int,
     reminders: { sent_at: string|null, next_due_at: string|null, window_days: int },
     batches: [ { earned_from: string, earned_to: string, points: int, expires_at: string } ] | null
   } | null
   ```
   `batches: null` is the Free inactivity model — no batches exist, not "zero batches".
   Percentages and money are **derived client-side**; the fixture stores neither.
2. `DrawerExpiry.php`: one table keyed by scenario. `expiring` gets 4b's figures (6,300 expiring of
   9,850, three batches, 30-day reminder sent, 7-day due). Every other scenario returns `null`.
3. `DrawerProfiles::to_wire()` calls `DrawerExpiry::for_scenario()` and adds the block. If this
   pushes `DrawerProfiles` toward the cap, move the scenario table out — do not let it grow.
4. `CustomerRestController::format_customer()`: `'expiry' => null`, with a docblock saying the real
   path cannot compute it because `lots` does not exist, and that `expires_at` (already on the wire)
   is all the real path knows.
5. `customer.schema.ts`: nullable `expiry` object; `batches` nullable inside it.
6. `CustomerDrawerExpiryPanel.tsx`: banner + three disabled actions. Derive days-remaining from
   `expires_at`, the money from `worth.points_per_currency_unit`, the share from
   `expiring_points / balance`. Guard the share against a zero or negative balance — `negative`
   is a real scenario and dividing by it must not print nonsense.
7. `CustomerDrawerExpiryStats.tsx`: reuse `CustomerDrawerStat` with `size="lg"`/`sm` as the header
   grid does. Do not fork a second stat component.
8. `CustomerDrawerLotBatches.tsx`: `<ProGate>` around the table; the Free explanation renders in the
   overlay's place. Table uses the shared `Table` primitives, like `LedgerTabRows`.
9. `CustomerDrawer.tsx`: render the three above the tab bar, guarded on `customer.expiry`.
   Ordering with phase 02's panels: a deleted account has expiry stopped, so the two panel sets are
   mutually exclusive in practice — assert that rather than designing a combined layout.
10. Copy: banner sentence (points / date / days / money / share), the reminder-schedule sentence,
    three action reasons, four stat labels, the batch column headers, and the Free explanation.

## Todo List

- [x] `expiry` wire shape written down before any code
- [x] **No ledger row added anywhere** — verify by grepping the diff for ledger touches
- [x] `DrawerExpiry` is its own file; `DrawerProfiles` stays under the cap
- [x] Real path emits `expiry: null` with a docblock saying why
- [x] Money and percentages derived client-side; fixture stores structured figures only
- [x] Share-of-balance guarded against zero/negative balance
- [x] Lot table `ProGate`d with a real Free explanation, not a blank
- [x] Reuses `CustomerDrawerStat`, does not fork it
- [x] Deleted + expiring asserted mutually exclusive
- [x] Every new file < 200 lines

Deviations from plan:
- **`worth` no longer exists on the wire — a fact discovered mid-phase, not anticipated by the
  plan.** Between phase 02 and this phase, a parallel change (`9b3f474`, "Remove worth field &
  update tier range") deleted the whole `worth` object from `customer.schema.ts`,
  `CustomerRestController.php`, `DrawerProfiles.php` and `CustomerItemSchema.php` — reasonable at
  the time (the Worth stat itself was cut from the header grid earlier), but this phase's own Key
  Insights assumed `worth.points_per_currency_unit` would still be there. Restored the RATE only,
  as a standalone `points_per_currency_unit` field (not the whole `worth` object) on both the
  fixture and real paths — the real path reads it from the same `RewardRepository` method `worth`
  used to. This is the minimum needed for 4b's (and later 5a's) money figures; it does not revert
  the decision to drop the Worth stat display.
- **The same parallel commit appears to have also fixed the tier-ordering bug** this plan's brief
  explicitly recorded as "left as-is until a real tiers table" (`customers-page.tier-sample-data.ts`
  was touched). Not verified in depth or acted on here — out of this phase's scope — but noted so
  it isn't rediscovered as still-open in a later report.
- **`pointsToMoney()`/`daysUntil()` extracted to a shared `customer-drawer.money.ts`**, not inlined
  in either component. Two reasons: both figures are needed in both
  `CustomerDrawerExpiryPanel.tsx` and `CustomerDrawerExpiryStats.tsx` (DRY), and `daysUntil()`
  specifically CANNOT be inlined in a component body — ESLint's `react-hooks/purity` rule flags
  `Date.now()` as an impure call when it appears directly inside a component/hook function. Moving
  it into an ordinary function sidesteps that (the same reason `parseWPRelativeDate` already lives
  in `format.helper.ts` rather than being computed inline wherever it's shown).
- **`CustomerDrawerLotBatches.tsx` does not use `<ProGate>` directly** — it branches on the same
  underlying `isProBuild()` the component exports, because `<ProGate>`'s overlay is a generic
  upsell message, but 4b's own note calls for a SPECIFIC sentence ("On the free inactivity model
  there are no batches"). Using bare `<ProGate>` would have shown the wrong message on Lite. Also
  handles `batches: null` (the wire contract's other "no batches" case, for a future inactivity-mode
  flag) with the same explanation, even though the `expiring` fixture never actually sends it —
  correct against the schema rather than assuming the only null-batches path is "not Pro".
- **Batch "earned range" fields are NOT a real `lots` column.** `docs/data-model.md`'s `yayloyalty_lots`
  table has one row per `earn` event with a single `expires_at`, no date-range field at all — the
  range 4b displays is a UI-level bucketing of lots that share an expiry window. Recorded in
  `DrawerExpiry.php`'s own docblock so whoever builds the real table doesn't expect to query this
  shape directly; they'll need to group individual `lots` rows by expiry window for display.
- **Reset expiry reuses `NOT_AVAILABLE_YET_REASON()`**, the same reason `CustomerDrawerActions.tsx`
  shows for it in the main actions row (this scenario's balance is positive, so that component
  would show the generic reason, not the balance-specific one) — per the plan's explicit
  instruction to stay consistent with that component. Exempt/Send reminder share the same reason
  since both are equally "no endpoint exists," not three near-duplicate strings.

Live-verified via `rest_do_request()`: `?scenario=expiring` returns `expiry` with the artboard's
exact figures — `expiring_points: 6300`, `safe_points: 3550`, batch points summing to the customer's
full balance (9,850), reminder dates `2027-07-15`/`2027-08-07` (matching "30-day reminder went out
15 July; 7-day one due 7 August"); derived money at rate 100 = **$63.00**, derived share =
**64%** — both matching 4b's stated figures exactly. `?scenario=large`, the real path (no
`?scenario=`), and `?scenario=deleted` all return `expiry: null`; `deleted` confirms mutual
exclusivity (`loose_ends` non-null, `expiry` null, never both). Real path's
`points_per_currency_unit` returns the actual configured `RewardRepository` rate (100.0 in this
dev environment).

**No live browser verification was possible** — no browser-automation tool in this environment, dev
port 3030 occupied. Verified instead: `composer lint` clean on every touched PHP file (only
pre-existing, unrelated warnings remain elsewhere), `tsc --noEmit` no new errors (same 5
pre-existing, unrelated ones), ESLint clean (after fixing the `Date.now()` purity violation above),
Vitest 63/63, production `vite build` clean.

### Code Review

Two Medium findings, same root cause, fixed together:
- **`DrawerExpiry.php`'s own return-type docblock disagreed with the wire contract it defines** —
  typed `reminders.sent_at`/`next_due_at`/`batches` as non-nullable while `CustomerItemSchema.php`
  and the Zod schema both correctly allow null (for a future real `lots` path). Widened to
  `?string`/`?array` so the file calling itself "the future contract" doesn't mislead whoever reads
  it next.
- **The nullable-reminder fallback was semantically wrong if ever exercised**: `sent_at ?? expires_at`
  would have shown "the reminder went out on the day the points expired" for a future null
  `sent_at` ("not sent yet") — actively misleading, not just imprecise. Fixed by skipping the
  reminder sentence entirely when either date is missing, rather than guessing one.
- Also fixed while in the same area (Low priority, but cheap): the `sharePercent` fallback of `'0'`
  for a non-positive balance would have read as false reassurance ("0% of the balance") rather than
  a neutral absence. Changed to `null`, and `expiringBannerBody()` now drops the percentage clause
  entirely when it can't be honestly computed, instead of printing a number.
- One cosmetic fix: a stray `--` in `CustomerItemSchema.php`'s `expiry` description, now an em dash
  matching every other description in the file.

Everything else came back clean: the three-layer `points_per_currency_unit` consistency (int/float
vs `number`/`z.number()`, no mismatch), the `daysUntil()` extraction correctly sidesteps the ESLint
rule (confirmed low-impact even though it doesn't fix the underlying non-determinism — no
`useEffect`/`setState` involved, no SSR, worst case is day-granularity staleness until the next
re-render), the `isProBuild()` direct-call precedent in `CustomerDrawerLotBatches.tsx` (confirmed
the only way to get 4b's specific Lite copy rather than `ProGate`'s generic upsell), and reusing
`NOT_AVAILABLE_YET_REASON()` across all three actions (confirmed to match, not diverge from,
`CustomerDrawerActions.tsx`'s own behavior for this exact scenario's balance). Re-verified after
fixes: ESLint clean, `tsc --noEmit` no new errors, Vitest 63/63, `vite build` clean.

## Success Criteria

- `?scenario=expiring` renders 4b's banner, actions, four stats and batch table with the artboard's
  figures, including "$63.00" and "64%".
- Pro build shows the batch table; Lite shows the upgrade surface plus the inactivity-model sentence.
- Every other scenario is visually unchanged.
- The ledger tab is byte-for-byte unchanged in behaviour — no new row types, no synthetic rows.
- `composer lint`, `tsc --noEmit`, `vite build` clean.

## Risk Assessment

- **Scope creep into the expiry engine.** Three disabled buttons. No endpoints, no scheduling, no
  email sending.
- **The forecast row will look like a missing feature** to anyone comparing against 4b. Record the
  omission and its reasoning in the phase's Deviations section, prominently.
- **Inventing the `lots` contract.** Whatever shape this fixture emits becomes what the real table is
  expected to produce. Keep it minimal — four fields per batch, nothing speculative (YAGNI).
- **Header keeps growing.** Tier card + phase 02 panels + these three. Reaching the tabs may need
  real scrolling on a laptop viewport; unverifiable here.

## Security Considerations

- No new endpoint or capability.
- "Send the reminder now" must not be wired to any handler — an email-sending action reachable by a
  crafted request is a spam vector. Disabled button only, no route.
- Fixture remains `WP_DEBUG` + `?scenario=` gated.

## Next Steps

- Phase 04 adds the third and last header-level panel (5a), reusing the same composition.
- Phase 05 begins the two Pro tab tables.

## Verification limits

No browser automation and dev port 3030 occupied — no visual verification. Verify with
`composer lint`, `tsc --noEmit`, `vite build`, and a live `rest_do_request()` of the `expiring`
scenario checking the derived money/share figures against 4b's stated $63.00 and 64%.
