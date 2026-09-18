# Phase 04 — Negative-balance explanation (5a)

## Context Links

- Brief → [`brainstorm-260918-0845-customer-drawer-wave-b.md`](../reports/brainstorm-260918-0845-customer-drawer-wave-b.md)
- Artboard **5a**
- Phase 02 — sets the panel composition this reuses; phase 03 reuses it too
- Wave A `plan.md` → "Out (later waves): … negative-balance explanation panel (5a)"
- Wave A phase 05 — shipped 5a's stat treatment only (warning icon beside the figure, no tint)
- `apps/admin/src/pages/customers/drawer/CustomerDrawerStats.tsx` — where that icon lives
- `includes/Controllers/Fixtures/CustomerDrawer/DrawerProfiles.php` — the `negative` scenario row

## Overview

- Priority: P2
- Status: pending
- The last undrawn artboard in the whole design file. Wave A renders Tom Weaver's −1,000 with a
  warning glyph and nothing else; 5a is the drawer explaining what happened and what to do.

## Key Insights

- **This is F23, not F09.** The design's own metadata block reads: *"F23 · Negative balance,
  explained in the drawer · Ships 1.0 · Size S · Depends on F09 · FREE + PRO"*, with the note
  *"Moved here from the Dashboard canvas: the notice lives on the dashboard, but the explanation is
  a drawer screen."* It is a separate feature renting space on the drawer canvas — which is exactly
  why it went missing when Wave B's artboard list was drawn up from the canvas rather than from
  Wave A's deferral list. Keep the F23 label in the code so it does not drift again.
- **Free AND Pro.** Along with the Activity log, this is one of only two remaining gaps a Free user
  can see. No `ProGate` anywhere in this phase.
- **The scenario already works.** `negative` (Tom Weaver, −1,000, 4 ledger rows) is wired and
  parsing. This phase adds a panel over data that already resolves — no new endpoint.
- **The design asserts a shape that is not always true.** 5a callout 1: *"A negative balance
  **always** has this shape — earned, spent, then the earning was reversed."* That holds for the
  refund case it draws. It does **not** hold for a manual adjustment pushed below zero, or for an
  expiry clawback. The panel must degrade to a generic explanation when the three-step story cannot
  be assembled, rather than printing a half-filled narrative or crashing.
- **Both actions are disabled**, per the wave's UI-on-fixtures rule. Note in code that "Write off
  the negative balance" is the one action in Wave B that **could** be implemented today — it is an
  adjustment of `+|balance|` with a fixed note, and `POST /customers/{id}/adjust` already works and
  already names the acting admin. Deliberately not wired; recorded so Wave C does not rediscover it.
- **5a draws only three stats** (Balance, Lifetime earned, Expires) where 1e draws five. That is the
  artboard being drawn narrow, not a rule — do not branch the stat grid on balance sign.
- 5a highlights the causing ledger row (`background:#f4f4f4; box-shadow:inset 3px 0 0 #6a6a6a`).
  That is a **ledger-tab** change, small but real, and it is the only cross-tab work in this phase.

## Requirements

Functional
1. When `balance < 0`, render an explanation panel between the actions row and the tab bar.
2. Panel contains: a heading, up to three numbered facts naming the orders involved, two labelled
   columns ("What {first name} sees" / "What happens next"), and two disabled actions with the
   consequence sentence beside them.
3. When the three-step story cannot be assembled, the numbered list is replaced by a single generic
   explanation — the panel still renders.
4. The ledger row that caused the deficit is visually marked in the Points ledger tab.
5. Nothing renders when `balance >= 0`.
6. The panel renders identically in Lite and Pro.

Non-functional
- Every file < 200 lines; every sentence in `customer-drawer.copy.ts`.
- The fixture emits structured fields (order ids, point figures, a `shape` discriminator) — never
  the assembled narrative.

## Architecture

```
includes/Controllers/Fixtures/CustomerDrawer/
  DrawerNegativeBalance.php    NEW — the structured story for the `negative` scenario
  DrawerProfiles.php           MODIFY — fold the block in (null for every other scenario)

apps/admin/src/
  lib/schema/customer.schema.ts                 MODIFY — nullable `negative_balance` block
  pages/customers/drawer/
    CustomerDrawerNegativePanel.tsx             NEW — heading + facts + two columns
    CustomerDrawerNegativeActions.tsx           NEW — two disabled actions + consequence line
    CustomerDrawer.tsx                          MODIFY — render when balance < 0
    customer-drawer.copy.ts                     MODIFY
    tabs/LedgerTabRows.tsx                      MODIFY — mark the causing row
    customer-drawer.ledger-rows.ts              MODIFY — carry an `isCause` flag
```

Rides on `GET /customers/{id}` like phase 03's `expiry` block: header-level information about one
customer, rendered above the tabs, one request.

## Related Code Files

Create
- `includes/Controllers/Fixtures/CustomerDrawer/DrawerNegativeBalance.php`
- `apps/admin/src/pages/customers/drawer/CustomerDrawerNegativePanel.tsx`
- `apps/admin/src/pages/customers/drawer/CustomerDrawerNegativeActions.tsx`

Modify
- `includes/Controllers/Fixtures/CustomerDrawer/DrawerProfiles.php`
- `includes/Controllers/CustomerRestController.php` (emit `negative_balance: null` on the real path)
- `apps/admin/src/lib/schema/customer.schema.ts`
- `apps/admin/src/pages/customers/drawer/CustomerDrawer.tsx`
- `apps/admin/src/pages/customers/drawer/customer-drawer.copy.ts`
- `apps/admin/src/pages/customers/drawer/customer-drawer.ledger-rows.ts`
- `apps/admin/src/pages/customers/drawer/tabs/LedgerTabRows.tsx`

Delete
- none

## Implementation Steps

1. Write the wire shape down first:
   ```
   negative_balance: {
     shape: 'refund_reversal' | 'other',
     earned:   { order_id: int, points: int, occurred_at: string } | null,
     spent:    { order_id: int, points: int, value: string } | null,
     reversed: { order_id: int, points: int, occurred_at: string } | null,
     cause_ledger_id: int | null,
     deficit: int
   } | null
   ```
   `shape: 'other'` means the three-step narrative does not apply and the SPA prints the generic
   explanation. `deficit` is always present — it is what the two columns and the write-off
   consequence sentence are computed from.
2. `DrawerNegativeBalance.php`: the `negative` scenario returns 5a's figures (earned 1,000 on #4102,
   spent on #4150 for $10.00, reversed #4102, deficit 1,000, `shape: 'refund_reversal'`). Every
   other scenario returns `null`.
3. `DrawerProfiles::to_wire()` folds the block in, as phase 03 does for `expiry`. If `DrawerProfiles`
   is nearing the cap by now (phases 02 and 03 both added to it), split the scenario table out
   rather than letting it grow — this is the third block added to that class.
4. `CustomerRestController::format_customer()`: `'negative_balance' => null`, docblocked with the
   reason — the real path would have to walk the ledger backwards to assemble the story, which is
   its own piece of work and is not this wave's.
5. `customer.schema.ts`: nullable `negative_balance`, with `shape` as a Zod enum so an unknown
   discriminator fails loudly rather than rendering a blank panel.
6. `CustomerDrawerNegativePanel.tsx`: heading, the numbered facts (or the generic line when
   `shape === 'other'` or any of the three legs is null), then the two labelled columns. Facts are
   composed by the copy module from the structured legs; this component only decides which to call.
7. `CustomerDrawerNegativeActions.tsx`: two `<Button disabled>` plus the consequence sentence.
   **Neither is wired to anything.** Add a comment naming `POST /customers/{id}/adjust` as the
   endpoint that would implement write-off, and that it was deliberately not wired this wave.
8. `CustomerDrawer.tsx`: render the two components between `<CustomerDrawerActions/>` and
   `<CustomerDrawerTabs/>`, guarded on `customer.negative_balance`. Ordering against phases 02/03:
   a deleted account has expiry stopped and `negative` is its own scenario, so at most one of the
   three panel sets is ever active — assert that rather than designing a stacked layout.
9. `customer-drawer.ledger-rows.ts`: add `isCause: entry.id === cause_ledger_id`. Thread
   `cause_ledger_id` down from `LedgerTab` — it comes from the customer payload, not the ledger one.
10. `LedgerTabRows.tsx`: a left inset marker plus a subtle row tint when `isCause`. Reuse the tint
    token the tier card and other panels use; do not introduce a new colour.
11. Copy: the heading, the three fact sentences, the generic fallback, both column headings and
    bodies, the two action labels, and the write-off consequence sentence. `deficit` and the money
    equivalent are interpolated; nothing is hardcoded English in the fixture.

## Todo List

- [ ] Wire shape written down before any code, including the `shape` discriminator
- [ ] `shape: 'other'` / missing legs render the generic explanation, not a half-filled list
- [ ] Fixture emits structured legs only; no assembled narrative in PHP
- [ ] Real path emits `negative_balance: null` with the "would have to walk the ledger" docblock
- [ ] Zod enum on `shape` so an unknown value fails loudly
- [ ] Both actions disabled and wired to nothing; write-off feasibility recorded in a comment
- [ ] Causing ledger row marked; `cause_ledger_id` threaded from the customer payload
- [ ] No `ProGate` anywhere — this is Free and Pro alike
- [ ] Stat grid NOT branched on balance sign
- [ ] Panel sets (deleted / expiring / negative) asserted mutually exclusive
- [ ] `F23` named in the component docblocks so the feature does not drift again
- [ ] Every new file < 200 lines

## Success Criteria

- `?scenario=negative` renders 5a: the three numbered facts naming #4102 and #4150, both columns,
  both disabled actions, and the marked refund row in the ledger below.
- Every other scenario is visually unchanged, including the ledger tab.
- Lite and Pro render identically.
- A hand-made payload with `shape: 'other'` renders the generic explanation with no gaps.
- `composer lint`, `composer test`, `tsc --noEmit`, `vite build` clean.

## Risk Assessment

- **The "always has this shape" assumption is wrong in general.** This is the phase's main hazard:
  building the panel as if the three legs are guaranteed produces broken output the first time a
  manual adjustment goes negative. The `shape` discriminator exists precisely to stop that — do not
  optimise it away because the fixture only ever emits one value.
- **`DrawerProfiles` is now carrying three optional blocks** (phases 02, 03, 04). Split it.
- **Touching `LedgerTabRows.tsx`** — a shipped, working component, for a marker used by one
  scenario. Keep the change additive and verify the non-negative path renders byte-identically.
- **Temptation to wire write-off.** It is one `adjust` call away and it is still out of scope this
  wave. It also needs a decided note string and a stated position on reversibility, neither of which
  exists yet.

## Security Considerations

- No new endpoint or capability surface.
- Neither action may be wired, even to a stub — "write off" mutates a balance, and a disabled button
  with no backing route cannot be triggered by a crafted request.
- The panel prints order ids belonging to the customer being viewed; the route is already
  `CAP::VIEW`-guarded, so no new exposure.
- Fixture stays `WP_DEBUG` + `?scenario=` gated.

## Next Steps

- Phase 05 begins the two Pro tab tables.
- Wave C should reconsider wiring write-off: it is the cheapest real capability left in this design,
  needing only a decided note string and a position on whether it can be undone.

## Verification limits

No browser-automation tool exists here and dev port 3030 is occupied, so no visual verification is
possible. Verify with `composer lint`, `tsc --noEmit`, `vite build`, and a live `rest_do_request()`
of the `negative` scenario checking the structured legs against 5a's stated figures. State this in
the phase's Deviations section on completion, as Wave A did.
