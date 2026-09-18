# Phase 05 — Redemptions table (3e)

## Context Links

- Brief → [`brainstorm-260918-0845-customer-drawer-wave-b.md`](../reports/brainstorm-260918-0845-customer-drawer-wave-b.md)
- Artboard **3e**
- [`docs/data-model.md`](../../docs/data-model.md) → `yayloyalty_redemptions` (1.0, **NOT IMPLEMENTED**, schema already designed)
- Phase 01 — the endpoint/schema/tab-body pattern this copies wholesale
- `apps/admin/src/components/pro/ProGate.tsx`
- `apps/admin/src/pages/customers/drawer/tabs/LedgerTabRows.tsx` — table + mobile wrap technique

## Overview

- Priority: P3
- Status: pending
- The third tab. **Table only** — the summary strip and "Rewards X could use now" are cut.

## Key Insights

- **Deliberately reduced scope.** 3e draws a three-figure summary strip and a "Rewards X could use
  now" line. Both are cut: the strip's "Redemption rate" and "net of refunds" arithmetic needs real
  redemption data to be meaningful, and the rewards line needs the reward catalog plus tier gating —
  the two most expensive dependencies on the artboard, for information that is not why an admin
  opens this tab. Locked in the brief.
- **`yayloyalty_redemptions`' schema is already designed** in `docs/data-model.md` even though the
  table does not exist. The fixture must emit *that* shape, not a new invention — this is the one
  place in Wave B where the future contract is already written down. Read it first.
- **"Points returned" is the interesting row.** A refunded order returns the points, so the row
  carries a **positive** points figure and a distinct status, and is **excluded from totals**. The
  totals are cut this phase, but the status and the sign are not — they are the row's whole point.
- **Pro-only tab, `ProGate`d body.** Free keeps the tab (locked decision) with
  `<ProGate inline />` beside the label and `<ProGate>` over the body. 3e's own callout explains
  why Free has no such tab naturally: Free has one reward, so its redemptions are already ledger rows.
- `counts.redemptions` stays `0` on the real path — truthful, the table does not exist.

## Requirements

Functional
1. `GET /customers/{id}/redemptions` returns a paginated envelope, `CAP::VIEW`-guarded.
2. Fixture under `WP_DEBUG` + `?scenario=`; empty envelope otherwise.
3. Six columns: Date, Reward, Value, Points, Order, Status.
4. Refunded rows show a positive points figure and the "Points returned" status.
5. Paged (reuse the ledger's pager), not Load more — this tab has no Load-more note in 3e and the
   ledger's pager component already exists.
6. Free build shows the upgrade surface in place of the body; the tab label carries the Pro badge.
7. Empty state as Wave A shipped it when there are no rows.

Non-functional
- Every file < 200 lines; all prose in the copy module; figures unabbreviated.
- Fixture row shape matches `docs/data-model.md`'s designed `yayloyalty_redemptions` columns.

## Architecture

```
includes/Controllers/
  RedemptionsRestController.php     NEW  — copy of phase 01's controller shape
  Fixtures/CustomerDrawer/
    DrawerRedemptions.php           NEW  — rows per scenario
    CustomerDrawerFixture.php       MODIFY — dispatcher

apps/admin/src/
  lib/schema/redemption.schema.ts   NEW
  lib/api/customer.api.ts           MODIFY
  lib/queries/customer.queries.ts   MODIFY
  pages/customers/drawer/
    customer-drawer.redemption-rows.ts  NEW — structured fields → display row
    customer-drawer.copy.ts             MODIFY
    tabs/RedemptionsTab.tsx             REWRITE
    tabs/RedemptionsTabRows.tsx         NEW
    CustomerDrawerTabs.tsx              MODIFY — ProGate badge + body wrap
```

## Related Code Files

Create
- `includes/Controllers/RedemptionsRestController.php`
- `includes/Controllers/Fixtures/CustomerDrawer/DrawerRedemptions.php`
- `apps/admin/src/lib/schema/redemption.schema.ts`
- `apps/admin/src/pages/customers/drawer/customer-drawer.redemption-rows.ts`
- `apps/admin/src/pages/customers/drawer/tabs/RedemptionsTabRows.tsx`

Modify
- `includes/Controllers/Fixtures/CustomerDrawer/CustomerDrawerFixture.php`
- `includes/Engine/RestAPI.php`
- `apps/admin/src/lib/api/customer.api.ts`
- `apps/admin/src/lib/queries/customer.queries.ts`
- `apps/admin/src/pages/customers/drawer/customer-drawer.copy.ts`
- `apps/admin/src/pages/customers/drawer/tabs/RedemptionsTab.tsx`
- `apps/admin/src/pages/customers/drawer/CustomerDrawerTabs.tsx`

Delete
- none

## Implementation Steps

1. Read `docs/data-model.md`'s `yayloyalty_redemptions` section and derive the wire row from it —
   `{ id, reward_id, reward_name, order_id, points, value, currency, status, created_at }`.
   `reward_name` is the one addition (the table stores `reward_id`); note in the fixture docblock
   that the real path will need a join or an SPA-side lookup, the same open question the ledger's
   `campaign_name` has. **Do not solve it here** — record it.
2. `DrawerRedemptions.php`: rows per scenario, counts matching `DrawerProfiles`' `counts_redemptions`
   (6 for the default, 38 for `large`, 1 for `deleted`, 0 for `new`). Include at least one refunded
   row in the default scenario — 3e's 18 Feb "Points returned" row.
3. `CustomerDrawerFixture::redemptions()` dispatcher.
4. `RedemptionsRestController`: copy phase 01's controller wholesale. Empty envelope on the real
   path with the same "zero is the true count" docblock.
5. `RestAPI.php`: construct and register.
6. `redemption.schema.ts`: Zod mirror, `REDEMPTION_STATUSES = ['applied','returned','issued']`
   (confirm against 3e's rendered statuses before fixing the enum).
7. `customer.api.ts` + `customer.queries.ts`: a plain paginated `useQuery`, keyed
   `['customer','redemptions',userId,params]`. Not infinite — this tab is paged.
8. `customer-drawer.redemption-rows.ts`: structured fields → `{ date, reward, value, points, order,
   status }`. The sign on `points` comes from the status: returned rows are positive. Reuse
   `formatSignedPoints` if it already does this; otherwise put the rule here, once.
9. `RedemptionsTabRows.tsx`: six columns, status as a `Badge`. Copy `LedgerTabRows.tsx`'s
   below-`sm` wrap technique — six columns will not fit at 390px and the rule is already set.
10. `RedemptionsTab.tsx`: query + rows + the ledger's existing `LedgerTabPager`. If that pager is
    reusable as-is, rename/move it to a shared `DrawerTabPager` in this phase; if it is entangled
    with ledger copy, leave it and note the duplication rather than forcing a bad abstraction.
11. `CustomerDrawerTabs.tsx`: `<ProGate inline />` after the Redemptions label, `<ProGate>` around
    `<RedemptionsTab/>`. Establish the exact composition here — phase 06 copies it verbatim.

## Todo List

- [x] Wire row derived from `docs/data-model.md`, not invented
- [x] `reward_name` join question recorded, not solved
- [x] Fixture row counts agree with `DrawerProfiles`' `counts_redemptions` for every scenario
- [x] At least one "Points returned" row with a positive figure in the default scenario
- [x] Empty envelope on the real path with the "zero is true" docblock
- [x] Zod parses fixture and real paths identically
- [x] Six columns wrap below `sm` using the existing technique
- [x] Pager reused (already `DrawerTabPager` since phase 01's amendment — not re-extracted here)
- [x] `ProGate` badge + body wrap; Free shows the upgrade surface
- [x] Every new file < 200 lines

Deviations from plan:
- **Added a `currency` field** (`"USD"`, fixed) that the plan's own step 1 named in its wire row
  list but I initially left out of the first draft — caught before verification, not by review.
  Matches `docs/data-model.md`'s real `currency CHAR(3)` column.
- **`counts.redemptions` derivation centralized**: added `DrawerProfiles::redemptions_count_for()`,
  matching the existing `ledger_count_for()`/`activity_count_for()` accessors — the Risk Assessment's
  "derive from one source" instruction, followed rather than duplicating the count in
  `DrawerRedemptions.php` itself.
- **The default (`''`) identity's 6-row story is unreachable via any live REST call**, discovered
  during this phase's own verification, not specific to Redemptions: `?scenario=` is registered
  with an `enum` that does NOT include `''` (`CustomerDrawerFixture::SCENARIOS`), so passing
  `scenario=''` explicitly fails validation (`400`) before reaching any handler; omitting the param
  entirely skips the fixture branch's `!empty($request['scenario'])` gate and falls through to the
  real (empty) repository path instead. Confirmed this is NOT new: the already-shipped
  `GET /customers/{id}` and `GET /customers/{id}/ledger` endpoints have the identical limitation —
  their own `''` identity rows are equally unreachable via `?scenario=`. `DrawerRedemptions`'s
  `'' === $scenario` branch exists for the same reason the ledger/activity ones do: documenting what
  the default identity's data conceptually looks like, and matching the established
  `rows()[$scenario] ?? rows()['']` fallback shape, even though nothing can currently reach it
  through the REST layer. Not fixed — pre-existing, affects the whole fixture system, out of this
  phase's scope. `large`/`deleted`/`new`/real-path were all live-verified instead (see below).
- **`customer-drawer.copy.ts` is now at exactly 200 lines** — the redemptions copy fit without a
  second split, but there is no more room left in it. Phase 06 (Referrals) will need its own new
  copy destination, the same way `customer-drawer.panels.copy.ts` was split out in phase 02.

### Code Review

One High finding, fixed at its root cause rather than at this phase's own call site:
**`<ProGate inline />` nested inside `TabsTrigger` (a `<button>`) let clicks bubble** —
`UpgradeToProBadge`'s own `onClick` (`window.open(...)`) had no `stopPropagation()`, so a Lite user
clicking the Redemptions tab's "Pro" badge both opened the marketing page AND switched the drawer to
that tab. Every other existing `<ProGate inline />` call site sits inside a plain `<div>`/`CardTitle`,
never a clickable ancestor, so this was the first composition to surface it. Fixed in
`UpgradeToProBadge` itself (`components/pro/UpgradeToPro.tsx`), not by adding a workaround at this
phase's call site — a shared component's own click handler should be safe inside any container, and
phase 06 (Referrals) copies this exact composition verbatim, so the fix needed to live where every
future `<ProGate inline />` consumer inherits it.

Everything else came back clean: three-way schema consistency including `currency` (all three layers
agree), `default_rows()`'s internal correctness despite being unreachable via REST (verified
statically — 6 rows, correct sign on the refund row, ids/currency assigned correctly), the minimal
(no ledger-shaped carryover) query/params shape, the 6-column mobile-stacking math, `DrawerTabPager`
reuse as a genuine non-decision, and no stale references to the old empty-state `RedemptionsTab.tsx`
leaking into `ReferralsTab.tsx`. Re-verified after the fix: ESLint clean, `tsc --noEmit` no new
errors, Vitest 63/63, `vite build` clean.

## Success Criteria

- `?scenario=` renders 3e's six rows including the refunded one; `large` renders 38; `new` the empty state.
- Tab count and envelope total agree.
- Lite build: tab visible with a Pro badge, body replaced by the upgrade surface.
- `composer lint`, `composer test`, `tsc --noEmit`, `vite build` clean.

## Risk Assessment

- **Six columns in a 600px drawer.** 3e's own grid is `1.1fr 1.6fr 1.2fr .85fr .8fr 1.15fr` at
  600px, so it does fit — but only just, and the mobile wrap is mandatory, not optional.
- **Pager extraction.** Tempting to generalise on the second use. Only do it if it comes out clean;
  a shared pager carrying ledger-specific copy is worse than two pagers.
- **Fixture/count drift.** Three places now hold per-scenario counts (`DrawerProfiles`,
  `DrawerActivity`, `DrawerRedemptions`). Derive from one source, as `DrawerActivity` already does
  via `activity_count_for()`.
- **Scope creep back toward the summary strip.** It is cut. "Redemption rate" needs real data.

## Security Considerations

- `CAP::VIEW`-guarded server-side; SPA guard cosmetic.
- `ProGate` is a **build-time** gate for presentation. It is not access control and must never be
  the only thing standing between a Lite user and Pro data — but here there is no Pro data, only a
  dev fixture, so nothing is at risk. Keep it that way when the real table lands.
- Fixture stays `WP_DEBUG` + `?scenario=` gated.

## Next Steps

- Phase 06 copies this phase's endpoint, schema, table and `ProGate` composition for Referrals.

## Verification limits

No browser automation; dev port 3030 occupied. No visual verification. Verify with
`composer lint`, `composer test`, `tsc --noEmit`, `vite build`, and a live `rest_do_request()`
comparing the envelope against 3e's figures.

Live-verified via `rest_do_request()`: `?scenario=large` returns exactly 38 rows with the fixture's
cycling reward templates and `counts.redemptions` (from the customer endpoint, same scenario)
agreeing exactly with the redemptions endpoint's own `totalItems` (38 = 38); `?scenario=deleted`
returns 1 row; `?scenario=new` and the real path both return an empty envelope
(`totalItems: 0`, `200`). The default identity's 6 specific rows could not be live-verified this
way — see the Deviations entry above explaining why that path is unreachable via REST for every
drawer endpoint, not only this one. `composer lint` clean on every touched PHP file (one
pre-existing, unrelated warning remains), `tsc --noEmit` no new errors, ESLint clean, Vitest 63/63,
`vite build` clean.
