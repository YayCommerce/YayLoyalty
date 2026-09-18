# Phase 02 — Deleted-account panels (4a)

## Context Links

- Brief → [`brainstorm-260918-0845-customer-drawer-wave-b.md`](../reports/brainstorm-260918-0845-customer-drawer-wave-b.md)
- Artboard **4a**
- Wave A phase 05 — shipped 4a's header treatment ONLY (the `ACCOUNT DELETED` badge and the
  `Balance at deletion` / `expiry stopped` stat wording). Read its Deviations before starting.
- `includes/Controllers/Fixtures/CustomerDrawer/DrawerProfiles.php` — the `deleted` scenario row
- `apps/admin/src/pages/customers/drawer/CustomerDrawerActions.tsx` — the existing disabled-action
  pattern with its priority-ordered single reason line

## Overview

- Priority: P2
- Status: pending
- 4a is the drawer arguing that a deleted account is a **record**, not a gap. Wave A shipped the
  badge; this phase adds the three panels that carry the argument.

## Key Insights

- **`deleted_at` has no column.** It is a wire field that is always `null` on the real path, so 4a
  is reachable **only** through `?scenario=deleted`. That is unchanged by this phase and must be
  said in the code, not just here.
- **The `deleted` scenario does not carry what these panels need.** `DrawerProfiles::rows()` holds
  only identity + stats + counts. The "Loose ends" panel needs three new facts (void coupons,
  credited referrals, counted-in-members) and the banner needs the deletion date — which
  `deleted_at` already supplies. New fixture fields are required; plan for them.
- **"Anonymise the record" is a disabled button and nothing else.** It writes off liability and
  touches personal data; both are separate features. Drawing it keeps the design's argument intact
  ("Export and anonymise are the only things that make sense") without smuggling GDPR logic into F09.
- **Export ledger is drawn as LIVE in 4a** but has no job behind it (Wave A left it disabled). It
  stays disabled here — 4a does not get a different Export button from the rest of the drawer.
  Note the divergence from the artboard explicitly.
- The panels sit **between the actions row and the tab bar**, inside the scrolling body — not in
  `SheetHeader`, which would push the tabs off-screen on a short viewport.

## Requirements

Functional
1. When `deleted_at` is non-null, render, in order: a deletion banner, "What can still be done",
   "Loose ends this leaves".
2. Banner states the deletion date and why the ledger is kept (liability, reports, refunds), naming
   the kept entry count.
3. "What can still be done" lists four actions with their real enabled/disabled state and one
   reason line each: Adjust points (disabled), Reset expiry (disabled), Export ledger (disabled —
   no job yet), Anonymise the record (disabled — not built).
4. "Loose ends" lists three facts: unused coupons now void, referrals still credited, whether the
   account is counted in the member total.
5. Nothing renders any of this when `deleted_at` is null.

Non-functional
- Every file < 200 lines; all prose in `customer-drawer.copy.ts`.
- The fixture emits counts and booleans, never sentences.

## Architecture

```
includes/Controllers/Fixtures/CustomerDrawer/
  DrawerProfiles.php          MODIFY — add a `loose_ends` block to the deleted row
  (wire field is null for every other scenario)

apps/admin/src/
  lib/schema/customer.schema.ts               MODIFY — optional `loose_ends` object
  pages/customers/drawer/
    CustomerDrawerDeletedBanner.tsx           NEW
    CustomerDrawerDeletedActions.tsx          NEW — "What can still be done"
    CustomerDrawerLooseEnds.tsx               NEW
    CustomerDrawer.tsx                        MODIFY — render the three when deleted
    customer-drawer.copy.ts                   MODIFY
```

`loose_ends` is nullable on the wire and absent for every non-deleted customer, so the real path
(where `deleted_at` is always null) parses unchanged.

## Related Code Files

Create
- `apps/admin/src/pages/customers/drawer/CustomerDrawerDeletedBanner.tsx`
- `apps/admin/src/pages/customers/drawer/CustomerDrawerDeletedActions.tsx`
- `apps/admin/src/pages/customers/drawer/CustomerDrawerLooseEnds.tsx`

Modify
- `includes/Controllers/Fixtures/CustomerDrawer/DrawerProfiles.php`
- `includes/Controllers/CustomerRestController.php` (emit `loose_ends: null` on the real path)
- `apps/admin/src/lib/schema/customer.schema.ts`
- `apps/admin/src/pages/customers/drawer/CustomerDrawer.tsx`
- `apps/admin/src/pages/customers/drawer/customer-drawer.copy.ts`

Delete
- none

## Implementation Steps

1. Decide the `loose_ends` wire shape and write it down before touching PHP:
   `{ void_coupons: { count: int, example: string|null }, credited_referrals: int,
   counted_in_members: bool, kept_ledger_entries: int }`. Structured only — "1 · $5 off" is
   composed client-side from `count` + `example`.
2. `DrawerProfiles`: add `loose_ends` to the `deleted` row with 4a's figures (1 void coupon "$5 off",
   2 credited referrals, not counted, 24 kept entries). Every other scenario emits `null`.
   If the rows array is getting unwieldy, split the loose-ends table into its own small class rather
   than widening the tuple past readability — the 200-line cap applies to `DrawerProfiles` too.
3. `CustomerRestController::format_customer()`: emit `'loose_ends' => null` with a docblock saying
   the real path can never populate it because there is no `deleted_at` column to key it off.
4. `customer.schema.ts`: add `loose_ends: looseEndsSchema.nullable()`. Parse both paths identically.
5. `CustomerDrawerDeletedBanner.tsx`: the deletion date from `deleted_at` + the kept-entries
   sentence. Use the same tinted-panel treatment the tier card now uses, in a warning-neutral tone —
   4a is informational, not an error.
6. `CustomerDrawerDeletedActions.tsx`: four rows, each a `<Button disabled>` plus its own reason.
   **Do not** reuse `CustomerDrawerActions.tsx`'s single shared reason line — 4a's whole point is
   that the four actions are disabled for *different* reasons. Extract nothing; the two components
   are genuinely different shapes.
7. `CustomerDrawerLooseEnds.tsx`: three label/value rows plus the liability sentence.
8. `CustomerDrawer.tsx`: render the three between `<CustomerDrawerActions/>` and
   `<CustomerDrawerTabs/>`, guarded on `customer.deleted_at`.
9. Copy: every sentence above, plus the four reason lines. Keep the Export reason distinct from the
   Anonymise one — "no export job yet" and "not built yet" are different facts.

## Todo List

- [x] `loose_ends` wire shape written down before any code
- [x] Fixture emits structured fields only; `null` for every non-deleted scenario
- [x] Real path emits `loose_ends: null` and says why in a docblock
- [x] Zod parses fixture and real paths identically
- [x] Three panels render only when `deleted_at` is non-null
- [x] Four actions each carry their own distinct reason
- [x] Export stays disabled (divergence from the artboard) and the divergence is documented in code
- [x] Panels sit in the scrolling body, not the sticky header
- [x] Every new file < 200 lines

Deviations from plan:
- **`customer-drawer.copy.ts` split before this phase touched it.** It was at 190/200 lines
  (flagged in phase 01's review, twice) — adding 4a's ~15 new strings would have pushed it over.
  Created `customer-drawer.panels.copy.ts` for the three header-level edge-state panels (4a here;
  4b and 5a next, per this phase's own "Next Steps") rather than reopening the main module again in
  phase 03 and 04. Same house rules: flat exported functions, every entry a function.
- **`CustomerItemSchema.php` also updated**, though the plan's file list didn't name it. It defines
  `GET /customers/{id}`'s JSON Schema `properties` (used by REST discovery/OPTIONS) and already
  documents `deleted_at`/`counts` field-for-field with `format_customer()` — leaving `loose_ends`
  out of it would make the endpoint's own schema lie about its response shape. Fixed a pre-existing,
  unrelated `phpcs` violation in the same file while there (missing trailing comma in `counts`'
  multi-line array) since the new `loose_ends` entry needed the identical trailing comma anyway.
- **`CustomerDrawer.tsx` gates whether the three panels mount at all** on `deleted_at && loose_ends`
  — that is a runtime rendering decision, not a type-narrowing one. The children still receive the
  whole `customer: Customer` prop, and prop types don't inherit call-site narrowing, so
  `CustomerDrawerDeletedBanner`/`CustomerDrawerLooseEnds` each still need (and keep) their own
  internal null check before reading `loose_ends`. An earlier draft of this note claimed the parent
  guard removed the need for those — code review caught that the claim didn't match what TypeScript
  actually does; corrected here rather than removing the (correct, necessary) child guards.
- **Adjust points reuses `DELETED_ACCOUNT_ACTION_REASON()`**; Reset expiry does not — code review
  flagged that string ("there is nobody to spend the points") as a non-sequitur next to a disabled
  Reset expiry button (extending a deadline isn't about spending). Added
  `DELETED_RESET_EXPIRY_REASON()` with the more general framing the existing string's own docblock
  already used ("no owner left to act on"), reused for Reset expiry specifically. Export and
  Anonymise keep their own distinct reasons (`DELETED_EXPORT_REASON`/`DELETED_ANONYMISE_REASON`) per
  the plan's explicit instruction that those two must stay distinct from each other.

Live-verified via `rest_do_request()`: `?scenario=deleted` returns `loose_ends` with the artboard's
exact figures (1 void coupon "$5 off", 2 credited referrals, not counted, 24 kept entries) and
`deleted_at: "2026-09-08 00:00:00"`; `?scenario=large` and the real path (no `?scenario=`) both
return `loose_ends: null` alongside `deleted_at: null`, confirming the guard never fires outside the
`deleted` scenario.

**No live browser verification was possible** — no browser-automation tool in this environment, dev
port 3030 occupied. Verified instead: `composer lint` clean on every touched PHP file (one
pre-existing, unrelated warning remains in `CustomerRowFilters.php`), `tsc --noEmit` no new errors
(same 5 pre-existing, unrelated ones), ESLint clean, Vitest 63/63, production `vite build` clean.

### Code Review

Two Medium findings, both fixed (see the corrected Deviations entries above — one was a doc-accuracy
fix, one a copy-wording fix): the parent-guard/narrowing claim was corrected to match what
TypeScript actually does; `DELETED_RESET_EXPIRY_REASON()` was added so Reset expiry no longer reuses
spend-specific wording. One Low style note (banner checks two fields, loose-ends checks one) resolved
with a one-line comment rather than a code change — the asymmetry is correct, just needed explaining.
Everything else — three-way schema consistency (PHP fixture → JSON Schema → Zod), the deliberate
non-extraction from `CustomerDrawerActions.tsx`, dead code — came back clean. Re-verified after
fixes: ESLint clean, `tsc --noEmit` no new errors, ready to commit.

## Success Criteria

- `?scenario=deleted` renders 4a's three panels with the artboard's figures.
- Every other scenario renders exactly as it does today — no visual change at all.
- Real path unaffected: `loose_ends` is null, nothing renders.
- `composer lint`, `tsc --noEmit`, `vite build` clean.

## Risk Assessment

- **Header height.** The tier card already pushes content down; three more panels above the tabs
  make a short viewport scroll before reaching the ledger. Verify the tab bar is still reachable —
  and note this cannot be checked visually here (see below).
- **Widening `DrawerProfiles`' tuple** past readability. It is already a 13-element positional
  array; adding a nested block to it is the point at which to split the class.
- **Temptation to build Anonymise.** It is a button with a reason line. Nothing else.

## Security Considerations

- No new endpoint, no new capability surface.
- "Anonymise" must not be wired to anything, even a stub handler — a disabled button with no
  backing route cannot be triggered by a crafted request.
- Loose-ends figures are fixture-only and dev-gated; no real customer data is exposed.
- The banner must not print the deleted user's email — 4a does not, and the identity header
  already shows it.

## Next Steps

- Phases 03 and 04 reuse this phase's panel composition for 4b and 5a. Whatever shape the
  "What can still be done" block takes here is the one both copy, so settle it properly.

## Verification limits

No browser-automation tool exists in this environment and dev port 3030 is occupied, so no visual
verification is possible. Verify with `composer lint`, `tsc --noEmit`, `vite build`, and a live
`rest_do_request()` check of the `deleted` scenario's payload. State this in the phase's Deviations
section on completion, as Wave A did.
