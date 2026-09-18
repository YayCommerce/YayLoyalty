# Phase 06 — Referrals table (3f)

## Context Links

- Brief → [`brainstorm-260918-0845-customer-drawer-wave-b.md`](../reports/brainstorm-260918-0845-customer-drawer-wave-b.md)
- Artboard **3f**
- Phase 05 — **copy its endpoint, schema, table and `ProGate` composition verbatim**
- [`docs/data-model.md`](../../docs/data-model.md) → referrals (1.1, not implemented)
- `apps/admin/src/components/pro/ProGate.tsx`

## Overview

- Priority: P3
- Status: pending
- The fourth tab. **Table only** — the summary strip and "Revenue referred" are cut. Structurally a
  near-clone of phase 05, so it should be the fastest phase in the wave.

## Key Insights

- **"Revenue referred" is cut, and it is the expensive one.** 3f states it as "$210.00 · first
  orders only" — that requires joining WooCommerce order totals with first-order-only attribution
  logic that exists nowhere in this codebase and has never been priced. The rest of the tab does
  not depend on it. Locked in the brief.
- **A referral has two conditions and both must be visible** (3f callout 2): the friend signed up,
  *and* their first order completed. A "Pending" row with no explanation is the exact thing the
  design says admins end up asking support about — so pending rows must state **what is missing and
  when the window closes**. That copy is the tab's real value; do not treat it as decoration.
- **Both sides of the reward are shown**: points to the referrer, coupon to the friend, *including
  whether the coupon was used*. Neither side is invented here — 3f notes the points appear in the
  ledger and the coupon in WooCommerce.
- The referral link + Copy button and the campaign description sit **above** the table. The link is
  cheap (derivable/fixture) and is a genuine support-call shortcut, so keep it even though the
  summary strip is cut. The app already has a `copy-button.tsx`.
- Pro-only tab, `ProGate`d body, same as phase 05. `counts.referrals` stays `0` on the real path.

## Requirements

Functional
1. `GET /customers/{id}/referrals` returns a paginated envelope, `CAP::VIEW`-guarded.
2. Fixture under `WP_DEBUG` + `?scenario=`; empty envelope otherwise.
3. Above the table: the referral link with a Copy button, and the campaign description sentence.
4. Six columns: Referred (name + email), Signed up, First order, To referrer, To friend, Status.
5. Pending rows state the missing condition and the window close date.
6. Free build shows the upgrade surface in place of the body; the tab label carries the Pro badge.
7. Empty state as Wave A shipped it when there are no rows.

Non-functional
- Every file < 200 lines; all prose in the copy module; figures unabbreviated.
- Reuse phase 05's structures rather than parallel inventions.

## Architecture

```
includes/Controllers/
  ReferralsRestController.php       NEW  — clone of phase 05's
  Fixtures/CustomerDrawer/
    DrawerReferrals.php             NEW
    CustomerDrawerFixture.php       MODIFY — dispatcher

apps/admin/src/
  lib/schema/referral.schema.ts     NEW
  lib/api/customer.api.ts           MODIFY
  lib/queries/customer.queries.ts   MODIFY
  pages/customers/drawer/
    customer-drawer.referral-rows.ts  NEW
    customer-drawer.copy.ts           MODIFY
    tabs/ReferralsTab.tsx             REWRITE
    tabs/ReferralsTabRows.tsx         NEW
    tabs/ReferralsTabHeader.tsx       NEW — link + Copy + campaign sentence
    CustomerDrawerTabs.tsx            MODIFY — ProGate, copying phase 05
```

## Related Code Files

Create
- `includes/Controllers/ReferralsRestController.php`
- `includes/Controllers/Fixtures/CustomerDrawer/DrawerReferrals.php`
- `apps/admin/src/lib/schema/referral.schema.ts`
- `apps/admin/src/pages/customers/drawer/customer-drawer.referral-rows.ts`
- `apps/admin/src/pages/customers/drawer/tabs/ReferralsTabRows.tsx`
- `apps/admin/src/pages/customers/drawer/tabs/ReferralsTabHeader.tsx`

Modify
- `includes/Controllers/Fixtures/CustomerDrawer/CustomerDrawerFixture.php`
- `includes/Engine/RestAPI.php`
- `apps/admin/src/lib/api/customer.api.ts`
- `apps/admin/src/lib/queries/customer.queries.ts`
- `apps/admin/src/pages/customers/drawer/customer-drawer.copy.ts`
- `apps/admin/src/pages/customers/drawer/tabs/ReferralsTab.tsx`
- `apps/admin/src/pages/customers/drawer/CustomerDrawerTabs.tsx`

Delete
- none

## Implementation Steps

1. Wire row: `{ id, referred_name, referred_email, signed_up_at, first_order_id, first_order_total,
   referrer_points, friend_coupon: { label, used }, status, window_closes_at }`.
   `status` ∈ `['completed','pending','lapsed']`. `window_closes_at` is only meaningful for pending.
   Envelope carries the referral link: `{ items, total, page, per_page, referral_link, campaign: {
   referrer_points, friend_coupon_label, window_days } }` — structured, never a sentence.
2. `DrawerReferrals.php`: rows per scenario matching `DrawerProfiles`' `counts_referrals` (3 default,
   12 `large`, 2 `deleted`, 0 `new`/`negative`). Default scenario reproduces 3f's three rows,
   including Yuki's pending row with its 1 December window.
3. `CustomerDrawerFixture::referrals()` dispatcher.
4. `ReferralsRestController`: clone phase 05's. Empty envelope on the real path, same docblock.
5. `RestAPI.php`: construct and register.
6. `referral.schema.ts`: Zod mirror including the envelope's `referral_link` and `campaign` block.
7. `customer.api.ts` + `customer.queries.ts`: plain paginated `useQuery`, keyed
   `['customer','referrals',userId,params]`.
8. `customer-drawer.referral-rows.ts`: structured fields → display row. Pending rows compose their
   "waiting on a first order · window closes {date}" line here, from `status` + `window_closes_at`.
9. `ReferralsTabHeader.tsx`: link + `copy-button.tsx` + the campaign sentence composed from the
   `campaign` block. A "Referral settings" link is drawn in 3f — include it only if a settings route
   already exists; otherwise omit rather than shipping a dead link.
10. `ReferralsTabRows.tsx`: six columns, status `Badge`, the two-line Referred cell (name + email),
    mobile wrap as in phase 05.
11. `ReferralsTab.tsx`: header + rows + pager (whatever phase 05 settled on).
12. `CustomerDrawerTabs.tsx`: `ProGate` badge + body wrap, **copying phase 05's composition exactly**.

## Todo List

- [ ] Envelope carries link + campaign as structured fields, no sentences
- [ ] Fixture row counts agree with `DrawerProfiles`' `counts_referrals` for every scenario
- [ ] Pending rows state the missing condition AND the window close date
- [ ] Both sides of the reward rendered, including coupon-used state
- [ ] "Referral settings" link included only if the route exists; otherwise omitted
- [ ] Empty envelope on the real path with the "zero is true" docblock
- [ ] Zod parses fixture and real paths identically
- [ ] `ProGate` composition identical to phase 05's
- [ ] Every new file < 200 lines

## Success Criteria

- `?scenario=` renders 3f's three rows with Yuki pending and its window date; `large` renders 12.
- Lite build: tab visible with a Pro badge, body replaced by the upgrade surface.
- No dead links anywhere in the tab.
- `composer lint`, `composer test`, `tsc --noEmit`, `vite build` clean.

## Risk Assessment

- **Divergence from phase 05.** Two near-identical endpoints and tables invite two slightly
  different shapes. Diff them before finishing; anything gratuitously different should be aligned.
- **Scope creep back toward "Revenue referred".** It is cut. It needs Woo order joins and
  first-order attribution.
- **Dead "Referral settings" link** if no settings route exists. Omit rather than ship it.
- **Fixture count drift** across the now-four per-scenario count tables. Same mitigation as phase 05.

## Security Considerations

- `CAP::VIEW`-guarded server-side.
- The referral link and referred customers' **emails** are personal data of third parties. They are
  fixture-only here, but the shape is the future contract: note in the schema docblock that the real
  endpoint must apply the same `CAP::VIEW` gate and must not expose referred-customer emails to any
  lower capability.
- `ProGate` is presentation, not access control (see phase 05).
- Fixture stays `WP_DEBUG` + `?scenario=` gated.

## Next Steps

- Phase 07 sweeps mobile across everything phases 01–06 added, then syncs docs.

## Verification limits

No browser automation; dev port 3030 occupied. No visual verification. Verify with
`composer lint`, `composer test`, `tsc --noEmit`, `vite build`, and a live `rest_do_request()`
against 3f's figures.
