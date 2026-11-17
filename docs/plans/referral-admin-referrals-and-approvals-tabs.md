---
id: P-5
title: Referral admin: Referrals and Approvals tabs
status: ready
from: BS-3
roadmap: R-4
design: UX-2
trello:
type: feat
branch:
pr:
editions: [pro]
created: 2026-11-17
---
# P-5 Referral admin: Referrals and Approvals tabs

Slice 3 of 4 for R-4. It needs P-3 (table, tab layout) and P-4 (statuses, `ReferralPayout::pay()`, `ReferralRepository::reject()`) merged first.

## Goal
In wp-admin → YayLoyalty → Referral, the merchant sees two more tabs:
- **Referrals** is the default tab. It lists every referral with the advocate, the masked friend email, the status and its reason line, the order, the order value, the reward and the date. It has search, status pills with counts, and 20 rows per page.
- **Approvals** lists the `on_hold` referrals, oldest hold end first, with a count badge on the tab and the header item.
  - **Approve** pays the advocate now, and the referral turns Rewarded.
  - **Reject** asks for a reason from UX-2's list and pays nothing.
  - Both actions also work in bulk.
  - A user without `yayloyalty_manage_campaigns` sees Approvals read-only.

Done means UX-2's Acceptance items for Referrals and Approvals pass:
- Approve → "Paid 1,000 points to {advocate}" and the row shows Rewarded.
- Reject with "Not a genuine referral" → Rejected with that reason and no points.
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
- **Differs:** `architecture.md`'s REST table and `admin-spa.md`'s route list don't have these routes and tabs. → T4.

## Approach
- **REST (Pro, T1–T2):** `ReferralsRestController`, base `referrals`.
  - `GET referrals`: `page`, `per_page` (≤100), `status`, `search`. It returns the paginated envelope plus `counts` by status, under `Capabilities::VIEW`. Each item has:
    - `id`, `status`, `reject_reason`
    - advocate `{ id, name, email }`, friend `{ id, email }`
    - `first_order_id`, `order_total`, `order_currency`, `advocate_points`
    - `hold_until`, `created_at` (UTC)
  - `POST referrals/{id}/approve` → `ReferralPayout::pay()`. `POST referrals/{id}/reject` with `reason` from `ReferralRejectReason::admin_choices()` → `ReferralRepository::reject()`. Both run under `Capabilities::MANAGE_CAMPAIGNS` inside `exec_write`, and return 409 when the referral isn't `on_hold`.
  - Registered through `yayloyalty_rest_controllers`.
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
- [ ] T1 Referrals list REST with search, status filter and counts — `includes/Pro/Controllers/ReferralsRestController.php` (new), `includes/Pro/Repositories/ReferralRepository.php`, `includes/Pro/YayLoyaltyPro.php`
  - Reuse: `StoreLedgerRestController` and `StoreLedgerRepository` (list shape, user search), `AbstractRestController::requires()` / `respond_paginated()`, `exec_read`, `Capabilities::VIEW`, `wc_get_order`, `Utils\Text::plain`
  - Callers: `ReferralsRestController` → filter `yayloyalty_rest_controllers` (`YayLoyaltyPro::add_controllers()`); `ReferralRepository::paginate_list()`, `count_by_status()` → `GET referrals`; route → the Referrals tab (T3), the Approvals tab and badge (T4)
- [ ] T2 Approve and reject routes for held referrals — `includes/Pro/Controllers/ReferralsRestController.php`, `includes/Pro/Enums/ReferralRejectReason.php` [risk: 3]
  - Reuse: `ReferralPayout::pay()` (P-4), `ReferralRepository::reject()` (P-4), `exec_write()`, `Capabilities::MANAGE_CAMPAIGNS`
  - Callers: `POST referrals/{id}/approve`, `POST referrals/{id}/reject` → the Approvals tab (T4); `ReferralRejectReason::admin_choices()` → the reject route's `enum` arg
- [ ] T3 Referral → Referrals tab and default route — `apps/admin/src/pro/referral/referrals/ReferralsTab.tsx` (new), `apps/admin/src/pro/referral/referrals/ReferralsTable.tsx` (new), `apps/admin/src/pro/referral/referrals.schema.ts` (new), `apps/admin/src/pro/referral/referrals.copy.ts` (new), `apps/admin/src/pro/referral/referral.api.ts`, `apps/admin/src/pro/referral/referral.queries.ts`, `apps/admin/src/pro/referral/ReferralLayout.tsx`, `apps/admin/src/router.tsx`
  - Reuse: `pro/ledger/StoreLedger.tsx` and `StoreLedgerTable.tsx`, `parsePaginated` / `paginatedSchema`, `useDebouncedSearchCommit`, `formatWPDate` / `utcToDate` / `formatWPCurrency` / `formatStoreNumber`, `resolveQueryLoad`, `Badge`, `Tooltip`, `CustomerDrawer`; design UX-2 "Referral → Referrals"
  - Callers: `ReferralsTab` → child route `referral/referrals` (and its `:userId` drawer); `referralListSchema` ("Mirrors `ReferralsRestController` item") → `referral.api.ts`
- [ ] T4 Referral → Approvals tab with approve, reject, bulk and badge — `apps/admin/src/pro/referral/approvals/ApprovalsTab.tsx` (new), `apps/admin/src/pro/referral/approvals/ApprovalsTable.tsx` (new), `apps/admin/src/pro/referral/approvals/RejectReferralDialog.tsx` (new), `apps/admin/src/pro/referral/approvals/approvals.copy.ts` (new), `apps/admin/src/pro/referral/approvals/ReferralApprovalsBadge.tsx` (new), `apps/admin/src/pro/referral/referral.queries.ts`, `apps/admin/src/pro/referral/ReferralLayout.tsx`, `apps/admin/src/pro/index.ts`, `apps/admin/src/components/layout/HeaderMenu.tsx`, `apps/admin/src/router.tsx`
  - Reuse: `pages/customers/` list with `bulk-actions-menu.tsx`, `settleInChunks`, `setPaginatedRow`, `pro/campaigns/archive/CampaignArchiveDialog.tsx` (`ConfirmDialog` + `Select`), `toastApiError`, `Can` / `CAP.MANAGE_CAMPAIGNS`; design UX-2 "Referral → Approvals"
  - Callers: `ApprovalsTab` → child route `referral/approvals`; `RejectReferralDialog` → `ApprovalsTable`; `ReferralApprovalsBadge` (pro export) → `HeaderMenu.tsx` behind `IS_PRO_BUILD`, `ReferralLayout`
- [ ] T5 Knowledge docs for the routes and tabs — `docs/knowledge/architecture.md`, `docs/knowledge/admin-spa.md`, `docs/knowledge/e2e.md` [risk: 1]
  - Reuse: n/a

**Tests:** [integration] ReferralsRestControllerTest (`tests/php/integration/Pro/Controllers/ReferralsRestControllerTest.php`) — the list filters by status, searches the advocate or friend email, and returns counts, approve pays once and a second approve or approving a rejected row returns 409, reject only moves `on_hold` with an allowed reason, and a viewer can list but gets 403 on approve and reject

**E2E:** referral-approvals: Referral → Approvals → Approve on a held referral shows "Paid 1,000 points to {advocate}", the row leaves Approvals and Referrals shows it Rewarded. Reject with "Not a genuine referral" shows Rejected with that reason (new flow, tag `@P-5 @flow-referral-approvals`).

## Verify
- `yayh test run integration --id=P-5` → OK (integration)
- `yayh e2e run --grep @P-5` → passed (e2e)
- `wp eval 'wp_set_current_user(1); $r = rest_do_request( new WP_REST_Request( "GET", "/yayloyalty/v1/referrals" ) ); echo $r->get_status();'` → 200
- `wp eval '$u = wp_insert_user( [ "user_login" => "p5viewer", "user_pass" => wp_generate_password(), "role" => "subscriber" ] ); get_userdata( $u )->add_cap( "yayloyalty_view" ); wp_set_current_user( $u ); $r = rest_do_request( new WP_REST_Request( "POST", "/yayloyalty/v1/referrals/1/approve" ) ); echo $r->get_status(); wp_delete_user( $u );'` → 403
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
- As a user with `yayloyalty_view` but not `yayloyalty_manage_campaigns`: Approvals has no checkboxes or buttons.
- Approve a row in one tab after it's been rejected in another: the toast reads "This referral was already paid or rejected. The list has been refreshed."
- Lite build: there's no Referral menu item, and `#/referral/referrals` shows the 404 page.

## Risks
- **Approve pays the wrong amount or twice:** it calls only P-4's `pay()`, which is keyed and conditional. Rollback: revert the routes. The ledger keeps what was paid, and it can be adjusted by hand.
- **Slow list on a big store:** search joins `users` on both ids with `LIKE`, as the store ledger does. It's indexed on `(advocate_user_id, status)`. Accepted for 1.1.0.
- **Emails in the list:** shown only to users with `yayloyalty_view`, masked by default (UX-2 Decisions).
