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

- [x] Envelope carries link + campaign as structured fields, no sentences
- [x] Fixture row counts agree with `DrawerProfiles`' `counts_referrals` for every scenario
- [x] Pending rows state the missing condition AND the window close date
- [x] Both sides of the reward rendered, including coupon-used state
- [x] "Referral settings" link included only if the route exists; otherwise omitted
- [x] Empty envelope on the real path with the "zero is true" docblock
- [x] Zod parses fixture and real paths identically
- [x] `ProGate` composition identical to phase 05's
- [x] Every new file < 200 lines

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

## Deviations

- **`envelope()` signature dropped the `$referral_code` parameter the first draft had** — the plan's
  wire shape only needs `referral_link` (a string) on the envelope, not a separate code parameter
  threaded through the dispatcher. Simplified to a private `REFERRAL_CODES` map inside
  `DrawerReferrals.php` keyed by scenario (`''`/`expiring` → `daniel-4f2`, matching 3f verbatim;
  the other four scenarios get a deterministic, invented code), read inside `envelope()` itself.
  `CustomerDrawerFixture::referrals()` and `ReferralsRestController` stay generic — same
  `(scenario, args)` shape every other tab's dispatcher uses.
- **`respond_paginated()` does not pass through extra envelope fields** — discovered while wiring
  the controller: it only returns `currentPage/totalPage/totalItems/perPage/data`, silently dropping
  anything else in the `$result` array. `LedgerRestController::get_history()` already solved this for
  `type_counts` (extract before calling `respond_paginated()`, re-merge after via `+`). Copied that
  exact pattern for `referral_link`/`campaign` rather than inventing a second technique — both
  `+`-merges are safe here since `respond_paginated()`'s own keys never collide with either field.
- **`customer-drawer.copy.ts` was at its 200-line hard cap already** (confirmed via `wc -l` before
  this phase started) — per the plan's own precedent (`customer-drawer.panels.copy.ts` split in
  phase 02), all of 3f's prose went into a new `customer-drawer.referrals.copy.ts` instead. Also
  moved `EMPTY_REFERRALS_TITLE`/`EMPTY_REFERRALS_BODY` there from `customer-drawer.copy.ts` (Wave A
  leftovers, only consumed by the Referrals tab) — freed 3 lines and kept all of 3f's copy in one
  place. `TAB_REFERRALS` stayed in the shared file since `CustomerDrawerTabs.tsx` needs it alongside
  every other tab's label.
- **Pending coupon reads "issued", not "unused"** — the wire's `friend_coupon.used` is a plain
  boolean with no third state, but 3f's design shows Yuki's (pending) coupon as "issued" rather than
  "unused", since the coupon *was* sent even though the referral itself hasn't qualified yet.
  Derived client-side in `customer-drawer.referral-rows.ts`'s `formatFriendCoupon()`: `status ===
  'pending'` overrides the boolean's own label, rather than adding a third fixture-side enum value
  for what is genuinely a display-only distinction.
- **Default (`''`) scenario identity remains unreachable via live `?scenario=` REST** — the same
  pre-existing architectural limitation documented in phase 05 (confirmed again here: `scenario=''`
  → 400 on `/customers/{id}/referrals` too). `default_rows()` was verified by reading the fixture
  source directly against 3f's figures, and live-verified indirectly via the `expiring` scenario,
  which reuses the identical `default_rows()` code path and reproduced Priya/Ana/Yuki exactly,
  including Yuki's `window_closes_at: "2026-12-01 00:00:00"`.

## Live verification

Via `rest_do_request()` against an admin-impersonated request, `WP_DEBUG` on:

- `?scenario=expiring` (reuses `default_rows()`, same identity as `''`) → 3 rows: Priya Raghunathan
  (completed, coupon used), Ana Sousa (completed, coupon unused), Yuki Tanaka (pending, coupon
  issued, `window_closes_at: 2026-12-01`) — matches 3f exactly. `referral_link:
  "nordvik.example/r/daniel-4f2"`, `campaign: { referrer_points: 500, friend_coupon_label: "$5
  coupon", window_days: 90 }`.
- `?scenario=large&per_page=20` → 12 rows (matches `DrawerProfiles`' `counts_referrals`), newest-first,
  alternating coupon used/unused.
- `?scenario=deleted` → 2 rows. `?scenario=new` / `?scenario=negative` → 0 rows, empty `data: []`,
  `referral_link`/`campaign` still populated (they're per-scenario, not per-row).
- No `?scenario=` (real path) → `totalItems: 0`, `referral_link: ""`, `campaign: null` — the "zero is
  true" empty envelope, not an error.
- `?scenario=''` → 400 `rest_invalid_param` (pre-existing limitation, see Deviations).

**No visual verification.** This environment has no browser-automation tool and dev port 3030 is
occupied, as every phase file in this wave has stated.

## Code Review

Spawned `code-reviewer`, scoped to exactly this phase's 14 touched files (plus read-only comparison
against `RedemptionsRestController.php`, `LedgerRestController.php`, `UpgradeToPro.tsx`). Two findings,
both fixed:

- **HIGH — `customer.api.ts` breached the 200-line cap (212 lines)** after `getCustomerReferrals()`
  landed on top of an already-186-line file. The project's file-size rule has no "only new files"
  carve-out. Fixed by extracting the three paginated tab-body fetchers that only the drawer calls
  (`getCustomerActivity`/`getCustomerRedemptions`/`getCustomerReferrals` + their param types) into a
  new `apps/admin/src/lib/api/customer-drawer.api.ts` (94 lines), leaving `getCustomer()`/
  `getCustomerLedger()`/`adjustCustomerPoints()`/`getCustomers()` in `customer.api.ts` (128 lines) —
  those four have callers outside the drawer. Updated `customer.queries.ts`'s imports to split across
  both files; no other file imported the three moved functions.
- **MEDIUM — dead "Referral settings" link for `CAP.VIEW`-only admins.** `/referral` is gated behind
  `CAP.MANAGE_CAMPAIGNS` in `router.tsx`, stricter than the drawer's own `CAP.VIEW` gate — a support
  role with only `CAP.VIEW` would see the link and land on the SPA's 404 on click. The phase's own
  "no dead links" success criterion only checked the route existed, not who can reach it. Fixed by
  wrapping the link in `<Can cap={CAP.MANAGE_CAMPAIGNS}>`, the same primitive `HeaderMenu.tsx` already
  uses to hide its own Referral nav item from the same class of user.
- One LOW observation, not fixed: `status: 'lapsed'` exists in the schema/enum/copy but no fixture
  scenario ever produces one — the render logic was checked and is correct for the hypothetical case
  (falls through to the real `used`/`unused` label, deliberately not "issued"), just untested until a
  real data path introduces a lapsed row. Left as-is; inventing a fixture scenario for it is out of
  this phase's scope.

Everything else the reviewer checked came back clean: `ReferralsRestController.php` has no gratuitous
divergence from `RedemptionsRestController.php`; the `referral_link`/`campaign` extract-then-`+`-merge
around `respond_paginated()` copies `LedgerRestController`'s `type_counts` pattern correctly and safely
(no phase-04-style left-operand collision); fixture row counts match `DrawerProfiles` for every
scenario; third-party PII (referred name/email) is documented and `CAP::VIEW`-gated; the Zod envelope
parses `referral_link`/`campaign` explicitly rather than via the generic (and silently-dropping)
`parsePaginated()` helper; the `ProGate` composition is structurally identical to phase 05's and the
shared `UpgradeToProBadge` click-bubbling fix from phase 05 does cover this new tab.

Full re-verification after both fixes: `composer lint`/`composer test` clean, `tsc --noEmit` clean
(same 4 pre-existing unrelated errors), ESLint clean, `vitest` 63/63, `vite build` clean, and a live
`rest_do_request()` re-check confirming the Referrals endpoint still answers correctly post-refactor.
