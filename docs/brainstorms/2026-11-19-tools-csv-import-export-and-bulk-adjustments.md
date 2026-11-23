---
id: BS-5
title: Tools: CSV import/export and bulk adjustments
status: decided
from: R-6
decision: A. Server-side import in client-driven batches
created: 2026-11-19
---
# BS-5 Tools: CSV import/export and bulk adjustments

## Problem
R-6 (sprint 2, Pro + Lite, 1.1.0): merchants moving from another loyalty plugin need to bring balances in, and adjustments are thought to be one customer at a time. Acceptance: on a **Tools page** a merchant (1) exports all balances to CSV, (2) imports a CSV of email + points **with a preview and per-row errors before applying**, and (3) adds or removes points for selected customers or a segment with a note, **each change landing in the ledger**.

**Most of (1) and (3) already ships, on the Customers page:**
- Export: `apps/admin/src/pages/customers/customers-page.export.ts` builds a CSV (user id, name, email, balance, pending, lifetime, last activity, expires) client-side from every matching row.
- Bulk adjust: `customers-page.bulk-actions.ts` plus the adjust dialog (`components/adjust-points/AdjustPointsBulkPanels.tsx`). It works on a tick list or "all matching the filters", with a server count and liability preview (`POST customers/adjust/preview`, `lib/api/customer.api.ts:55`) and an acknowledgement checkbox. Pro's saved segments (`pro/customers/customers-saved-segment-picker.ts`) are filter presets on that same list, so "for a segment" already works in Pro.
- Both are capped at `MAX_MATCHING_ROWS` = 50,000 (`customers-page.matching-customers.ts:10`).
- Bulk adjust sends one `POST customers/{id}/adjust` per customer, 10 at a time (`lib/helpers/settle-in-chunks.helper.ts`, `lib/queries/customer.queries.ts:132`).

**What's missing:** CSV import (nothing exists), and a Tools page as the place for it. `Capabilities::RUN_TOOLS` (`includes/Access/Capabilities.php:18`) is declared, granted, and used by no route, so it's ready for this. `LedgerSource::IMPORT` and `ReferenceType::IMPORT` are declared and unused (`includes/Enums/LedgerSource.php:20`, `ReferenceType.php:38`).

## Constraints
- **Both editions.** Import, export and bulk adjust are Lite. Segments stay Pro (they already are). No Pro code in Lite (hard rules 1–2).
- **Only `LedgerService` writes balances** (hard rule 3), constructed only in its five listed places. `Engine/RestAPI.php:58` is one of them, so a REST controller can write rows without adding a sixth. A background (Action Scheduler) worker would need a new construction site and a docblock change.
- **`LedgerService::adjust()` is deliberately not idempotent** (`includes/Services/LedgerService.php:68-80`): no reference, no key. That's right for a one-off grant, but wrong for an import. A retried batch, a double-click or re-uploading the same file must not double the balance. Import rows need an `IdempotencyKey` (`import:<user>:import:<import id>:<row>`), the way earns have one.
- **Money and dates:** points are integers. Dates are stored UTC. `ADJUST` moves lifetime with the balance, `CORRECTION` moves the balance only (`LedgerService.php:72-75`).
- **Lots and expiry:** imported points must open a lot like any credit, so expiry counts them (`LotService`, via `LedgerService::record`).
- **Emails:** an import of 5,000 rows must not send 5,000 "points adjusted" emails unless the merchant asks. `EmailThrottle` caps per customer, not per run.
- **Size:** 10k–100k-row migrations are realistic. PHP max_execution_time (30–60 s) and upload limits apply. Matching email → user is one indexed lookup per row.
- **REST rules:** writes go through `exec_write()` inside `Transaction::run` (`BaseRestController.php`), permission `requires( Capabilities::RUN_TOOLS )`.

## Options

### A. Server-side import in client-driven batches; Tools page links the existing export and bulk adjust
- **How:** a new Tools route in the SPA (Lite). It has three cards: **Import balances**, **Export balances** and **Bulk adjust**. Export and bulk adjust reuse the Customers page code: Export calls the existing `exportCustomersToCsv` over all rows, and Bulk adjust deep-links to Customers with the dialog open, or embeds the same dialog. Import:
  1. The SPA parses the file locally (small CSV reader, or `papaparse`) into `{ email, points, note? }` rows. Nothing is stored server-side.
  2. `POST tools/import/preview` (RUN_TOOLS) takes chunks of 500 rows. Per row it returns the matched user, current balance and resulting balance, or an error: unknown email, not a number, zero, duplicate email in file, would go negative, opted out or excluded role. The SPA shows the summary plus an error table and a "download errors CSV".
  3. `POST tools/import/apply` takes chunks of 100 rows with an `import_id` (uuid made at preview). Each row is a ledger row with source `import`, reference `import`, and an idempotency key on `import_id + row number`, written by a new `LedgerService::import()` next to `adjust()`, inside the controller's transaction. A retried chunk returns `DUPLICATE` per row and writes nothing.
  - Mode: **add** (delta). For a migration, an optional **set balance to** is computed server-side as a delta at apply time.
- **Pros:** retry-safe and auditable, because every row is traceable to one import. No background jobs, so no new `LedgerService` construction site. Request time is bounded by chunk size, and the SPA shows a real progress bar. One request per 100 rows instead of one per row.
- **Cons:** the merchant must keep the tab open while it runs (the same trade-off bulk adjust already makes). The new `import()` write method plus key means touching `LedgerService`, which needs a test (CLAUDE.md: money logic is test-warranted).
- **Edition:** Lite (Pro adds nothing). **Size:** M.

### B. Client-only import on the existing per-customer adjust endpoint
- **How:** parse in the SPA, resolve emails through the customers list search, preview client-side, then reuse `useBulkAdjustPointsMutation` (`POST customers/{id}/adjust` per row via `settleInChunks`). The Tools page is a thin wrapper around existing pieces.
- **Pros:** smallest: almost no PHP. Rows show as normal manual adjustments.
- **Cons:** **not idempotent**. A timeout mid-chunk, a refresh or a second upload double-applies, which is the worst failure for a migration. Rows land as `manual` with no tie to the import. One HTTP request per row (10k rows is 10k requests, minutes at best). An email lookup by search is fuzzy, not exact.
- **Edition:** Lite. **Size:** S.

### C. Uploaded file processed in the background (Action Scheduler)
- **How:** upload the CSV to a protected uploads dir. Preview is a sync dry-run on the server. Apply enqueues `yayloyalty_import_run` batches in the `yayloyalty` group (like `ExpiryRunner` / `ReferralRunner`). Progress and errors go in an option or a small `imports` table, and the Tools page polls. Export can become a server-streamed file too, lifting the 50k cap.
- **Pros:** survives closing the tab and handles 100k+ rows. There's a server-side record of each import, and it can email the merchant when done.
- **Cons:** a sixth `LedgerService` construction site (hard rule 3 docblock change, review). File storage, cleanup and a status model to build. Async is harder to test and to support ("it's stuck"), and WP-Cron-less hosts stall Action Scheduler. That's a lot for 1.1.0's two-week sprint alongside R-2 (L) and R-5.
- **Edition:** Lite. **Size:** L.

## Recommendation
**A.** The real gap is import, and import's risk is double-applying money. A puts each row behind the ledger's existing idempotency key, so it's safe to retry by design, and it stays inside the REST path that already owns a `LedgerService`. Export and bulk adjust already work well, including segments and a liability preview, so the Tools page should reuse them rather than rebuild them. Rebuilding them would be pure duplication. B is tempting on size, but its failure mode (a half-applied or doubled migration with no way to tell which rows came from the file) is exactly what merchants migrating from another plugin can't afford. C is the right shape only if real imports routinely pass ~50k rows or merchants won't keep a tab open. That would change my mind, and A's endpoints would become C's batch worker later without throwing work away.

Decided 2026-11-19: the CSV's points **add** to the balance as `ADJUST` rows, so lifetime earned moves too. There's no "set balance" mode in 1.1.0.

## Open questions
- **Unknown emails:** skip as an error row (proposed), or create a WordPress customer account? Creating accounts is a bigger promise (welcome emails, GDPR).
- **Emails:** silent by default with a "notify customers" checkbox? (Proposed: silent.)
- **Placement:** a new top-level "Tools" menu item, or a tab under Settings? The roadmap says "Tools page"; the existing Customers page entry points stay either way.
- **Expiry dates in the CSV:** should a migrated balance carry its old expiry date (a `expires_at` column), or start fresh under this store's rules?
