---
id: P-5
title: Referral admin: Referrals and Approvals tabs
status: in-progress
from: BS-3
roadmap: R-4
design: UX-2
trello:
type: feat
branch: feat/p-5-referral-admin-referrals-and-approvals-tabs
pr:
editions: [pro]
created: 2026-11-17
---
# P-5 Referral admin: Referrals and Approvals tabs

Slice 3 of 4 for R-4. It needs P-3 (table, tab layout) and P-4 (statuses, `ReferralPayout::pay()`, `ReferralRepository::reject()`) merged first.

## Goal
In wp-admin → YayLoyalty → Referral, the merchant sees two more tabs:
- **Referrals** is the default tab. It lists every referral with the advocate, the masked friend email, the status and its reason line, the order, the order value, the reward and the date. It has search, status pills with counts, and 20 rows per page.
- **Customer drawer → Referrals** (any customer, from Customers, Ledger or Referrals) shows that customer as an advocate: three tiles (Referred, Points earned, Revenue referred), their referral link with Copy and the program's one-line terms, and the people they referred with each one's first order, both sides of the reward and the status (UX-2 "Customer drawer → Referrals").
- **Approvals** lists the `on_hold` referrals, oldest hold end first, with a count badge on the tab and the header item.
  - **Approve** pays the advocate now, and the referral turns Rewarded.
  - **Reject** asks for a reason from UX-2's list and pays nothing.
  - Both actions also work in bulk.
  - A user without `yayloyalty_manage_campaigns` sees Approvals read-only.

Done means UX-2's Acceptance items for Referrals and Approvals pass:
- Approve → "Paid 1,000 points to {advocate}" and the row shows Rewarded.
- Reject with "Not a genuine referral" → Rejected with that reason and no points.
- Customers → an advocate → Referrals shows their referrals and link; a customer with none still has a link.
- The read-only view for a user without manage-campaigns.

That completes R-4's "the merchant can pay early or reject from Approvals".

## Non-goals
- The Overview report, the funnel, revenue tiles and CSV (BL-5, R-10).
- Fraud flags and their review queue, and the cap (BL-8, R-12).
- Undoing a Rewarded or Rejected referral from the admin: a manual points adjustment covers it.
- Row actions on the Referrals tab: it stays a record (UX-2).

## Findings
1. **The list needs data from three tables plus WooCommerce:**
   - advocate and friend names and emails: `wp_users`
   - the reward: the terms snapshot, or the referral's earn row
   - the order total and currency: the WC order
   
   `StoreLedgerRepository` (`includes/Pro/Repositories/StoreLedgerRepository.php`) joins users for search, and the order value is read per page with `wc_get_order`, so it's HPOS-safe and never joined. → T1 copies that shape: one `paginate_list()` on `ReferralRepository` (search joins `users` on both ids), and the controller decorates each page's rows with the order total.
2. **Counts per status** for the pills and the Approvals badge: one `GROUP BY status` query, returned with the list as `counts`, so the header badge reuses it (`?per_page=1`).
3. **Approve and reject race the daily job.** P-4's `ReferralPayout::pay()` and `reject()` are conditional on `status = 'on_hold'`. → The routes return 409 `yayloyalty_referral_changed` when the row has moved, which the SPA shows as "This referral was already paid or rejected. The list has been refreshed." (UX-2 Failure).
4. **The customer drawer** is a nested route on Customers and Ledger (`apps/admin/src/router.tsx`, `ledger` → `:userId`). → `referral/referrals/:userId` reuses `CustomerDrawer`.
5. **Bulk writes** already have a client helper, `settleInChunks` (`apps/admin/src/lib/helpers/settle-in-chunks.helper.ts`). → No bulk route: the SPA calls the single routes in chunks, as the Customers bulk actions do.
6. **The drawer's tabs** are `CustomerDrawerTabs.tsx`: Lite tabs plus Redemptions behind `ProGate` (a presentation gate). Its counts come from Lite's customer payload, which can't count a Pro table. → The Referrals tab is a Pro component from `src/pro/` behind an `IS_PRO_BUILD` ternary (hard rule 2), and its count comes from its own Pro summary route. Lite shows the tab with `ProGate`, like Redemptions.
7. **Codes are created lazily.** P-6 planned `CustomerRepository::assign_referral_code()`; the drawer needs it first (the user chose "every customer has a link"). → T5 adds it and `ReferralListRepository::counts_for_advocate()`; P-6 reuses both.
- **Differs:** `architecture.md`'s REST table and `admin-spa.md`'s route list don't have these routes and tabs. → T6.

## Approach
- **REST (Pro, T1–T2):** `ReferralsRestController`, base `referrals`.
  - `GET referrals`: `page`, `per_page` (≤100), `status`, `search`. It returns the paginated envelope plus `counts` by status, under `Capabilities::VIEW`. Each item has:
    - `id`, `status`, `reject_reason`
    - advocate `{ id, name, email }`, friend `{ id, email }`
    - `first_order_id`, `order_total`, `order_currency`, `advocate_points`
    - `hold_until`, `created_at` (UTC)
  - `POST referrals/{id}/approve` → `ReferralPayout::pay()`. `POST referrals/{id}/reject` with `reason` from `ReferralRejectReason::admin_choices()` → `ReferralRepository::reject()`. Both run under `Capabilities::MANAGE_CAMPAIGNS` inside `exec_write`, and return 409 when the referral isn't `on_hold`.
  - Registered through `yayloyalty_rest_controllers`.
- **Drawer (Pro, T5):** `GET customers/{id}/referrals` (`Capabilities::VIEW`, member-scoped like the drawer's other reads) returns `{ link, terms_line_parts, counts: { referred, rewarded, pending, points_earned, revenue_referred }, items, total }` with 10 items per page. It calls `assign_referral_code()` when the customer has no code: a one-time, idempotent write on a read, so every customer has a link (Decided). Items carry the friend's name and email, `created_at`, `first_order` `{ id, number, total, currency, used_code }`, `status`, `advocate_points`, `coupon_expires_at`. The SPA tab is `pro/referral/drawer/CustomerReferralsTab.tsx`.
- **SPA (Pro, T3–T4):**
  - `#/referral` now redirects to `referrals`, the layout gains the Referrals and Approvals tabs, and the Approvals tab and header item get the badge (hidden at 0, read as "Approvals, 2 waiting").
  - The Referrals tab builds UX-2 "Referral → Referrals" on `StoreLedger` / `StoreLedgerTable`: `useDebouncedSearchCommit`, the status pills, `Badge` plus a reason line, the masked friend with a `Tooltip`, `formatWPCurrency`, `formatWPDate`, `Skeleton`, `ErrorState`, and the empty states.
  - The Approvals tab builds UX-2 "Referral → Approvals" on the Customers list's checkboxes and `bulk-actions-menu.tsx`:
    - Approve and Reject per row and in bulk, through `settleInChunks`.
    - The reject dialog is a `ConfirmDialog` with a `Select`, as `CampaignArchiveDialog`.
    - It has UX-2's toasts and accessible names.
    - Actions show only with `CAP.MANAGE_CAMPAIGNS`.
- **Editions:** Pro only. Lite already 404s `#/referral` from P-3.

## Tasks
- [x] T1 Referrals list REST with search, status filter and counts — `includes/Pro/Controllers/ReferralsRestController.php` (new), `includes/Pro/Repositories/ReferralListRepository.php` (new), `includes/Pro/YayLoyaltyPro.php`
  - Reuse: `StoreLedgerRestController` and `StoreLedgerRepository` (list shape, user search), `AbstractRestController::requires()` / `respond_paginated()`, `exec_read`, `Capabilities::VIEW`, `wc_get_order`, `Utils\Text::plain`
  - Callers: `ReferralsRestController` → filter `yayloyalty_rest_controllers` (`YayLoyaltyPro::add_controllers()`); `ReferralListRepository::paginate()`, `count_by_status()` → `GET referrals`; route → the Referrals tab (T3), the Approvals tab and badge (T4)
- [x] T2 Approve and reject routes for held referrals — `includes/Pro/Controllers/ReferralsRestController.php`, `includes/Pro/Enums/ReferralRejectReason.php`, `includes/Pro/Repositories/ReferralRepository.php` (`find_for_update()`), `includes/Pro/YayLoyaltyPro.php` [risk: 3]
  - Reuse: `ReferralPayout::pay()` (P-4), `ReferralRepository::reject()` (P-4), `exec_write()`, `Capabilities::MANAGE_CAMPAIGNS`
  - Callers: `POST referrals/{id}/approve`, `POST referrals/{id}/reject` → the Approvals tab (T4); `ReferralRejectReason::admin_choices()` → the reject route's `enum` arg
- [x] T3 Referral → Referrals tab and default route — `apps/admin/src/pro/referral/referrals/ReferralsTab.tsx` (new), `apps/admin/src/pro/referral/referrals/ReferralsTable.tsx` (new), `apps/admin/src/pro/referral/referrals.schema.ts` (new), `apps/admin/src/pro/referral/referrals.copy.ts` (new), `apps/admin/src/pro/referral/referral.api.ts`, `apps/admin/src/pro/referral/referral.queries.ts`, `apps/admin/src/pro/referral/ReferralLayout.tsx`, `apps/admin/src/router.tsx`, `apps/admin/src/pro/referral/referrals/referral-row.ts` (new), `apps/admin/src/pro/index.ts`, `apps/admin/src/pages/customers/drawer/tabs/DrawerTabFilters.tsx`, `includes/Pro/Controllers/ReferralsRestController.php`
  - Reuse: `pro/ledger/StoreLedger.tsx` and `StoreLedgerTable.tsx`, `parsePaginated` / `paginatedSchema`, `useDebouncedSearchCommit`, `formatWPDate` / `utcToDate` / `formatWPCurrency` / `formatStoreNumber`, `resolveQueryLoad`, `Badge`, `Tooltip`, `CustomerDrawer`; design UX-2 "Referral → Referrals"
  - Callers: `ReferralsTab` → child route `referral/referrals` (and its `:userId` drawer); `referralListSchema` ("Mirrors `ReferralsRestController` item") → `referral.api.ts`
- [x] T4 Referral → Approvals tab with approve, reject, bulk and badge — `apps/admin/src/pro/referral/approvals/ApprovalsTab.tsx` (new), `apps/admin/src/pro/referral/approvals/ApprovalsTable.tsx` (new), `apps/admin/src/pro/referral/approvals/RejectReferralDialog.tsx` (new), `apps/admin/src/pro/referral/approvals/approvals.copy.ts` (new), `apps/admin/src/pro/referral/approvals/ReferralApprovalsBadge.tsx` (new), `apps/admin/src/pro/referral/referral.queries.ts`, `apps/admin/src/pro/referral/ReferralLayout.tsx`, `apps/admin/src/pro/index.ts`, `apps/admin/src/components/layout/HeaderMenu.tsx`, `apps/admin/src/router.tsx`, `apps/admin/src/pro/referral/referral.api.ts`, `apps/admin/src/pro/referral/referrals.schema.ts`, `apps/admin/src/components/ui/custom/confirm-dialog.tsx` (an optional `loadingLabel`)
  - Reuse: `pages/customers/` list with `bulk-actions-menu.tsx`, `settleInChunks`, `setPaginatedRow`, `pro/campaigns/archive/CampaignArchiveDialog.tsx` (`ConfirmDialog` + `Select`), `toastApiError`, `Can` / `CAP.MANAGE_CAMPAIGNS`; design UX-2 "Referral → Approvals"
  - Callers: `ApprovalsTab` → child route `referral/approvals`; `RejectReferralDialog` → `ApprovalsTable`; `ReferralApprovalsBadge` (pro export) → `HeaderMenu.tsx` behind `IS_PRO_BUILD`, `ReferralLayout`
- [x] T5 Customer drawer → Referrals tab, with the customer's referral code — `includes/Repositories/CustomerRepository.php`, `includes/Pro/Repositories/ReferralListRepository.php`, `includes/Pro/Controllers/CustomerReferralsRestController.php` (new), `includes/Pro/Controllers/ReferralsRestController.php`, `includes/Pro/YayLoyaltyPro.php`, `apps/admin/src/pro/referral/drawer/CustomerReferralsTab.tsx` (new), `apps/admin/src/pro/referral/drawer/CustomerReferralsTrigger.tsx` (new), `apps/admin/src/pro/referral/drawer/customer-referrals.rows.ts` (new), `apps/admin/src/pages/customers/drawer/tabs/DrawerTab.tsx` (new, moved out of `CustomerDrawerTabs.tsx`), `apps/admin/src/pro/referral/drawer/customer-referrals.copy.ts` (new), `apps/admin/src/pro/referral/drawer/customer-referrals.schema.ts` (new), `apps/admin/src/pro/referral/referral.api.ts`, `apps/admin/src/pro/referral/referral.queries.ts`, `apps/admin/src/pro/index.ts`, `apps/admin/src/pages/customers/drawer/CustomerDrawerTabs.tsx`, `apps/admin/src/pages/customers/drawer/customer-drawer.copy.ts` [risk: 3]
  - Reuse: `CustomerRepository::ensure_row()`, `QueryException` on a unique-key collision, `AbstractRestController::member_user_id_param()` and `exec_read`, `ReferralSettings::get()`, `CurrencyRate::from_base()` / `to_base()`, `wc_get_order`, `CustomerDrawerStat`, `DrawerTable` / `DrawerTabPager`, `RedemptionsTab.tsx` (tab shape), `ProGate`, `IS_PRO_BUILD`, `Badge` and the status labels from T3, `formatWPDate` / `formatWPCurrency` / `formatStoreNumber`, `copyToClipboard` if the SPA has one (else `navigator.clipboard`); design UX-2 "Customer drawer → Referrals"
  - Callers: `CustomerRepository::assign_referral_code()` → `CustomerReferralsRestController`, P-6's `AccountReferrals`; `ReferralListRepository::counts_for_advocate()` → `CustomerReferralsRestController`, P-6; `ReferralListRepository::paginate()` (T1) with an `advocate` filter → `CustomerReferralsRestController`; `CustomerReferralsRestController` → filter `yayloyalty_rest_controllers`; `CustomerReferralsTab` (pro export) → `CustomerDrawerTabs.tsx` behind `IS_PRO_BUILD`
- [x] T6 Knowledge docs for the routes and tabs — `docs/knowledge/architecture.md`, `docs/knowledge/admin-spa.md`, `docs/knowledge/e2e.md` [risk: 1]
  - Reuse: n/a

**Tests:** [integration] ReferralsRestControllerTest (`tests/php/integration/Pro/Controllers/ReferralsRestControllerTest.php`) — the list filters by status, searches the advocate or friend email, and returns counts, approve pays once and a second approve or approving a rejected row returns 409, reject only moves `on_hold` with an allowed reason, a store with no referrals still gets `counts` as an object, and a viewer can list but gets 403 on approve and reject; [integration] CustomerReferralsRestControllerTest (`tests/php/integration/Pro/Controllers/CustomerReferralsRestControllerTest.php`) — a customer with no code gets one, once, and the same link on the next read, the counts, points and first-order revenue match the rows, and another customer's referrals never appear; [js] customer-referrals rows (`tests/js/pro/referral/customer-referrals.rows.test.ts`) — the To friend and To advocate cells per status

**E2E:** referral-approvals: Referral → Approvals → Approve on a held referral shows "Paid 1,000 points to {advocate}", the row leaves Approvals and Referrals shows it Rewarded. Reject with "Not a genuine referral" shows Rejected with that reason, and the advocate's drawer → Referrals shows their `?yl_ref=` link (new flow, tag `@P-5 @flow-referral-approvals`).

## Verify
- `yayh test run integration --id=P-5` → OK (integration)
- `yayh e2e run --grep @P-5` → passed (e2e)
- `yayh e2e cli wp eval 'wp_set_current_user(1); $r = rest_do_request( new WP_REST_Request( "GET", "/yayloyalty/v1/referrals" ) ); echo $r->get_status();'` → 200
- `yayh e2e cli wp eval '$u = wp_insert_user( [ "user_login" => "p5viewer", "user_pass" => wp_generate_password(), "role" => "subscriber" ] ); get_userdata( $u )->add_cap( "yayloyalty_view" ); wp_set_current_user( $u ); $r = rest_do_request( new WP_REST_Request( "POST", "/yayloyalty/v1/referrals/1/approve" ) ); echo $r->get_status(); wp_delete_user( $u );'` → 403
- `vendor/bin/phpcs includes/Pro/Controllers/ReferralsRestController.php includes/Pro/Repositories/ReferralRepository.php includes/Pro/Enums/ReferralRejectReason.php` → exit 0
- `pnpm --filter @yayloyalty/admin-page exec tsc --noEmit` → exit 0
- `pnpm --filter @yayloyalty/admin-page lint` → exit 0
- `./scripts/run.sh build pro` → exit 0
- `./scripts/run.sh release lite 1.0.0 --no-prompt` → passed
- UX-2 Acceptance on the Pro site, with one held referral (from P-4's walk-through):
  - Referral → Referrals shows it On hold with "releases {date}". The pills count it, and search by the friend's email finds it.
  - Referral → Approvals → Approve gives the toast "Paid 1,000 points to {advocate}". The row shows Rewarded in Referrals, and `wp db query "SELECT status FROM $(wp db prefix)yayloyalty_referrals WHERE id=<id>"` → `rewarded` (e2e).
  - Reject another held one with "Not a genuine referral": Referrals shows Rejected with that reason, `reject_reason` is stored, and no `referral` ledger row was written (e2e).
  - The header item and tab badges show the held count and are hidden at 0.
- Customers → open an advocate with one held and one rewarded referral → Referrals: the tab count is 2, the tiles read "Referred 2 · 1 rewarded · 1 pending" style figures, Copy puts `?yl_ref=CODE` on the clipboard, and each row shows its first order and status. A customer with none shows the link and "No referrals yet." Lite: the tab shows the Pro badge.
- As a user with `yayloyalty_view` but not `yayloyalty_manage_campaigns`: Approvals has no checkboxes or buttons.
- Approve a row in one tab after it's been rejected in another: the toast reads "This referral was already paid or rejected. The list has been refreshed."
- Lite build: there's no Referral menu item, and `#/referral/referrals` shows the 404 page.

## Risks
- Decided (whole-branch review): `counts` is sent as an object even when no referral exists yet, so the tabs load on a new store; Approvals reads a missing `on_hold` count as 0 and clamps its page after the last rows of a page leave. A live user who isn't a loyalty member has no link, and the drawer says so. Answered: the full friend email on hover is UX-2's design ("the full email on hover in a `Tooltip` for users with `CAP.VIEW`"); the "Paid N points" toast uses the snapshot, which is what `award_flat()` writes, since it skips the earning-limit filter.
- Decided (T5): `counts_for_advocate()` lives on `ReferralListRepository` (the size limit again), and returns `waiting` (pending or on hold) and the qualified first orders, whose totals the controller sums in base currency for "Revenue referred". `DrawerTab` moves to `tabs/DrawerTab.tsx`, so the Pro `CustomerReferralsTrigger` renders the tab with its own count from the tab's query without an import cycle; Lite shows the tab with `ProGate`. The row helpers are a plain `customer-referrals.rows.ts`, which the js test reads. List items carry `first_order.used_code`. From the T5 review: the drawer query is keyed `['customer', 'referrals', userId]` and an approve or reject invalidates it with the detail; `terms` is sent as an object even when empty; a code is created only for an existing member, so viewing an admin never adds a member row; a rejected or expired row shows "—" where the first order or the discount use isn't known. Answered: the code is created when the drawer opens (the tab count needs the same request), as the user chose "every customer has a link"; the drawer shows the friend's full email, as the design's artboard does, while the Referrals list masks it.
- Decided (T4): the selection bar shows UX-2's two buttons ("Approve selected", "Reject selected") rather than the Customers list's Bulk Actions dropdown, as the wireframe draws it. `ConfirmDialog` gains `loadingLabel`, since its busy text was fixed to "Deleting...". The badge reads `counts.on_hold` from a one-row list request. "Held since" is the referral's `updated_at`, the moment it went on hold.
- Decided (T3): `DrawerTabFilters` takes an optional date range, since UX-2's Referrals tab has search and status pills only. Each list item carries the first order's `edit_url` (`WC_Order::get_edit_order_url()`, HPOS-safe), so the Order column links to it with no URL built in JS. A total in another currency than the store's shows with its currency code. `referral-row.ts` turns an item into what a row shows, so the Approvals table (T4) renders it the same way.
- Decided (T2 review): approve and reject read the referral with `ReferralRepository::find_for_update()` (`SELECT … FOR UPDATE`), so under `exec_write`'s transaction a referral the daily job just moved is seen as moved and answers 409 `yayloyalty_referral_changed`. An approve that can't pay (a gate skip, a deleted advocate, a first order no longer completed) answers 409 `yayloyalty_referral_not_payable`. Rejecting from the admin notifies no one, as UX-2 specifies no message.
- Decided (T1): the list query lives in a new `ReferralListRepository`, as the store ledger's does in `StoreLedgerRepository`, because `ReferralRepository` is near the 200-line limit. `orderby=hold_until` serves the Approvals queue (oldest hold end first).
- **Approve pays the wrong amount or twice:** it calls only P-4's `pay()`, which is keyed and conditional. Rollback: revert the routes. The ledger keeps what was paid, and it can be adjusted by hand.
- **Slow list on a big store:** search joins `users` on both ids with `LIKE`, as the store ledger does. It's indexed on `(advocate_user_id, status)`. Accepted for 1.1.0.
- **Emails in the list:** shown only to users with `yayloyalty_view`, masked by default (UX-2 Decisions).
