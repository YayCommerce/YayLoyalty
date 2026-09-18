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

- [ ] `expiry` wire shape written down before any code
- [ ] **No ledger row added anywhere** — verify by grepping the diff for ledger touches
- [ ] `DrawerExpiry` is its own file; `DrawerProfiles` stays under the cap
- [ ] Real path emits `expiry: null` with a docblock saying why
- [ ] Money and percentages derived client-side; fixture stores structured figures only
- [ ] Share-of-balance guarded against zero/negative balance
- [ ] Lot table `ProGate`d with a real Free explanation, not a blank
- [ ] Reuses `CustomerDrawerStat`, does not fork it
- [ ] Deleted + expiring asserted mutually exclusive
- [ ] Every new file < 200 lines

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
