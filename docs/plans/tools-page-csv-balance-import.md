---
id: P-8
title: Tools page: CSV balance import
status: in-progress
from: BS-5
roadmap: R-6
design: UX-3
trello:
type: feat
branch: feat/p-8-tools-page-csv-balance-import
pr:
editions: [pro, lite]
created: 2026-11-19
---
# P-8 Tools page: CSV balance import

## Goal
In both editions, an administrator opens **YayLoyalty → Tools** and can:
- **import** a CSV of `email, points[, note]`: a per-row preview and errors before anything is written, then apply, with each valid row landing in the ledger as an `adjust` row from source `import`
- **export** every customer's balance to CSV
- jump to **bulk adjust** for selected customers or a segment, which already works on Customers

Done means the R-6 acceptance: export all balances; import with preview and per-row errors before applying; bulk add/remove for selected customers or a segment with a note, each change in the ledger. Importing the same file twice never applies a row twice.

## Non-goals
- Rebuilding bulk adjust or export. They exist on Customers (`apps/admin/src/pages/customers/customers-page.bulk-actions.ts`, `customers-page.export.ts`), and Tools links to or reuses them.
- "Set balance to" mode, creating accounts for unknown emails, emailing customers, carrying old expiry dates (BS-5 decisions; UX-3 Decisions).
- Background processing, or files over 50,000 rows (BS-5 option C).
- Letting shop managers import. `run_tools` stays administrator-only (`includes/Access/Capabilities.php:43-51`).

## Findings
1. **An import key can't be composed today.** `IdempotencyKey::compose()` accepts a non-redeem, non-expire key only with a `CampaignTrigger` qualifier (`includes/Engine/Earning/IdempotencyKey.php:47-53`), and `LedgerService::adjust()` writes no key at all (`includes/Services/LedgerService.php:80-104`). T1 adds `IdempotencyKey::for_import()` (`adjust:<user>:import:<import id>:import`), and T2 adds `LedgerService::import()` on the existing private `write_keyed()` (`:302-334`), which already does the duplicate check under the customer lock.
2. **`ReferenceType::IMPORT` and `LedgerSource::IMPORT` exist and are unused** (`includes/Enums/ReferenceType.php:38`, `LedgerSource.php:20`). The admin ledger already labels source `import` (`apps/admin/src/lib/helpers/ledger.helper.ts:65`). No enum or schema change is needed.
3. **The import id must be a positive integer** (`IdempotencyKey.php:45`, `ledger.reference_id bigint unsigned`). A 53-bit hash of the file's text, made in the browser, gives one id per file. That makes a retry or a re-upload of the same file `DUPLICATE` per row with no stored import record. This replaces BS-5's "uuid at preview", with the same behaviour plus re-upload safety.
4. **No new `LedgerService` construction site.** `Engine/RestAPI.php:58` builds the one REST instance and hands it to controllers (`:82`). The new controller takes it the same way, so hard rule 3's list of five holds.
5. **Adjust ignores earning gates** (no `ProgramGate` in `adjust()`). Import does the same, so opted-out or excluded-role customers are imported like any other. This differs from BS-5's proposed "opted out" error: merchant intent wins, as with a manual adjustment.
6. **`run_tools` is declared and granted to administrators, but no route uses it** (`includes/Access/Capabilities.php:18,44`; `apps/admin/src/lib/access.tsx:23`). Tools uses it for the route, the menu item and both REST routes.
7. **Export-all already exists as code**: `fetchAllMatchingCustomers()` + `exportCustomersToCsv()` + `useCsvExport()` (`customers-page.matching-customers.ts:13`, `customers-page.export.ts`, `hooks/useCsvExport.ts:11`). The Tools card calls them with the default filters.
- differs: `docs/knowledge/architecture.md` (REST table: no `run_tools` routes; "Ledger idempotency" names system rows only) and `docs/knowledge/admin-spa.md` (router list) → T6 updates both.

## Approach
- **Keys and ledger (Lite):** `IdempotencyKey::for_import( user, import_id )`. `LedgerService::import( user, points, import_id, note, actor_id ): Outcome` writes an `ADJUST` row (lifetime moves with the balance, BS-5 decision) with source `import`, reference `import:<id>`, under `write_keyed()`. Negative rows are allowed, but never past zero: the balance is checked under the lock, and the row is skipped with reason `would_go_negative`.
- **REST (Lite), `ToolsImportRestController`, cap `run_tools`, both writes through `exec_write()`:**
  - `POST tools/import/preview` takes `{ import_id, rows: [{ row, email, points, note? }] }` (≤ 500 rows). It returns per-row `{ row, status: ready|already_imported|error, reason?, user_id?, name?, balance?, balance_after? }`. Emails are matched exactly with `get_user_by( 'email' )`. Already imported means the import key exists (`LedgerRepository::find_by_idempotency_key()`). Duplicate emails in a file are caught client-side, across chunks.
  - `POST tools/import/apply` takes the same body (≤ 100 rows, only rows the preview marked ready) and returns per-row outcome `{ row, status: imported|duplicate|skipped|error, reason? }`. One failing row never rolls back the others: each row is its own `Transaction::run` inside the request, nested by savepoints.
  - The default note is "Imported from {file name}" (the client sends `file_name`, ≤ 100 chars, sanitized).
- **SPA (Lite), UX-3:** route `#/tools` guarded by `CAP.RUN_TOOLS`, and a `HeaderMenu` item. `pages/tools/` holds:
  - the page with three cards
  - the import flow: parse → chunked preview → table and filters → confirm → chunked apply
  - a small RFC 4180 CSV parser and the file-hash helper, both pure and js-tested
  - Export reuses the Customers export functions; *Go to Customers* is a link.
- **Compatibility:** PHP 7.4 (the 53-bit id fits a 64-bit PHP int; on 32-bit PHP the REST arg validation rejects the id above `PHP_INT_MAX`, and the page says the import can't run). No storage, option or migration. Downgrade-safe: rows are ordinary ledger rows.

## Tasks
- [ ] T1 Import idempotency key — `includes/Engine/Earning/IdempotencyKey.php`, `tests/php/unit/Engine/Earning/IdempotencyKeyTest.php`
  - Reuse: `IdempotencyKey::compose()` with a new `adjust` + `import` qualifier branch next to the expire branch; `ReferenceType::IMPORT`
  - Callers: `IdempotencyKey::for_import()` → `LedgerService::import()` (T2), `ToolsImportRestController::preview()` (T3) [risk: 3]
- [ ] T2 `LedgerService::import()`: keyed ADJUST row with source and reference import, no negative balance — `includes/Services/LedgerService.php`, `tests/php/integration/Services/LedgerServiceTest.php`
  - Reuse: `write_keyed()` with `$points_under_lock` returning 0 to skip (as `earn()` does), `LedgerEntry`, `Dates::now()`, `LedgerSource::IMPORT`
  - Callers: `LedgerService::import()` → `ToolsImportRestController::apply()` (T3) [risk: 3]
- [ ] T3 Tools import REST routes: preview and apply, `run_tools`, per-row results — `includes/Controllers/ToolsImportRestController.php` (new), `includes/Engine/RestAPI.php`, `tests/php/integration/Controllers/ToolsImportRestControllerTest.php` (new)
  - Reuse: `AbstractRestController::requires()`, `exec_read()` / `exec_write()` (`BaseRestController.php:40,58`), `error_invalid_arguments()`, the RestAPI `$ledger_service` and `$customers` instances, `CustomerRepository::find_by_user_id()`, `LedgerRepository::find_by_idempotency_key()`, core `get_user_by()`, `sanitize_text_field()`
  - Callers: `ToolsImportRestController` → `RestAPI::controllers()`; `preview()` → route `POST tools/import/preview`; `apply()` → route `POST tools/import/apply` [risk: 3]
- [ ] T4 SPA import plumbing: CSV parsing, file hash, API calls and zod schemas — `apps/admin/src/pages/tools/tools-import.csv.ts` (new), `apps/admin/src/lib/api/tools.api.ts` (new), `apps/admin/src/lib/schema/tools-import.schema.ts` (new), `tests/js/lib/tools-import.csv.test.ts` (new)
  - Reuse: `api` (ky client, `lib/api/api.ts`), zod `.parse` pattern of `customer.api.ts`, `settleInChunks()` shape for sequential chunks (`lib/helpers/settle-in-chunks.helper.ts`); none for parsing, new `parseImportCsv()` because the SPA only writes CSV (`csv.helper.ts`) and a parser dependency is heavier than ~60 lines
  - Callers: `parseImportCsv()`, `importIdForFile()`, `previewImport()`, `applyImport()` → `useToolsImport()` (T5)
- [ ] T5 Tools page UI: the three cards, import preview table with filters and errors CSV, confirm and progress (UX-3 "Tools page", "Import preview") — `apps/admin/src/pages/tools/ToolsPage.tsx` (new), `apps/admin/src/pages/tools/ToolsImportCard.tsx` (new), `apps/admin/src/pages/tools/ToolsImportPreview.tsx` (new), `apps/admin/src/pages/tools/tools-import.use-import.ts` (new), `apps/admin/src/pages/tools/tools-page.copy.ts` (new), `apps/admin/src/router.tsx`, `apps/admin/src/components/layout/HeaderMenu.tsx`
  - Reuse: `PageLayout`, `Card`, `Table`, `Badge`, `Segmented`, `Alert`, `Dialog`, `Button` (`components/ui/`); `guarded(CAP.RUN_TOOLS, …)` and `CustomMenuItem`; `useCsvExport` + `fetchAllMatchingCustomers` + `exportCustomersToCsv`; `toCsv` / `downloadCsv`; `toastApiError`
  - Callers: `ToolsPage` → route `tools`; `ToolsImportCard`, `ToolsImportPreview` → `ToolsPage`; `useToolsImport()` → `ToolsImportCard`
- [ ] T6 Knowledge: `run_tools` routes and the import key in architecture, the Tools route in admin-spa — `docs/knowledge/architecture.md`, `docs/knowledge/admin-spa.md` [risk: 1]
  - Reuse: n/a
- [ ] T7 E2E: import a 3-row CSV, preview errors, apply, re-upload shows already imported — `tests/e2e/specs/tools-import.spec.ts` (new), `docs/knowledge/e2e.md` (Flows row)
  - Reuse: admin login and SPA helpers of `tests/e2e/specs/customer-adjust-points.spec.ts`
  - Callers: n/a (test)

**Tests:** [unit] IdempotencyKeyTest::for_import_composes_adjust_import_key (tests/php/unit/Engine/Earning/IdempotencyKeyTest.php) — the key's shape, rejecting a zero id, and that other adjust qualifiers stay rejected; [integration] LedgerServiceTest::import_* (tests/php/integration/Services/LedgerServiceTest.php) — writes one ADJUST/import row, moves balance and lifetime, opens a lot, the same key twice is DUPLICATE, a debit past zero is skipped; [integration] ToolsImportRestControllerTest (tests/php/integration/Controllers/ToolsImportRestControllerTest.php) — 403 without `run_tools`, preview statuses (ready, unknown email, zero or non-integer points, already imported), apply writes only valid rows and one bad row doesn't undo the rest; [js] tools-import.csv (tests/js/lib/tools-import.csv.test.ts) — quoted fields, BOM, CRLF, missing columns, duplicate emails, the same text gives the same import id

**E2E:** Tools import: a 3-row CSV previews 2 ready and 1 error, applying shows 2 imported, re-uploading the same file shows 2 already imported and applies nothing

## Verify
- `yayh test run unit --id=P-8` → exit 0 (unit)
- `yayh test run integration --id=P-8` → exit 0 (integration)
- `yayh test run js --id=P-8` → exit 0 (js)
- `pnpm --filter @yayloyalty/admin-page exec tsc --noEmit` → exit 0
- `pnpm --filter @yayloyalty/admin-page lint` → exit 0
- `./scripts/run.sh build lite` → exit 0
- `./scripts/run.sh build pro` → exit 0
- `wp eval 'wp_set_current_user(1); $r = rest_do_request(new WP_REST_Request("POST","/yayloyalty/v1/tools/import/preview")); echo $r->get_status();'` → 400
- `wp eval '$u = get_users(["role"=>"shop_manager","number"=>1]); wp_set_current_user($u ? $u[0]->ID : 0); $q = new WP_REST_Request("POST","/yayloyalty/v1/tools/import/preview"); $q->set_body_params(["import_id"=>1,"rows"=>[]]); echo rest_do_request($q)->get_status();'` → 403
- Lite and Pro: YayLoyalty → Tools shows Import balances, Export balances and Adjust points in bulk. *Export CSV* downloads every customer with their balance. *Go to Customers* opens Customers.
- Import `email,points,note` with 2 known emails (+100, -10) and 1 unknown: the preview shows 2 Ready with balances before and after, and 1 error "No customer with this email". Import, and `wp db query "SELECT user_id,type,source,reference_type,points,note FROM wp_yayloyalty_ledger WHERE source='import' ORDER BY id DESC LIMIT 2"` shows 2 `adjust`/`import` rows. Upload the same file again: 2 Already imported, and nothing applies (e2e).
- Signed in as a shop manager: no Tools menu item, and `#/tools` shows the not-found page.
- `yayh code unused` → reports none of the symbols this plan adds

## Risks
- **Wrong money applied in bulk:** the preview shows every row's balance before and after, a confirm dialog states the total, and the key makes retries and re-uploads safe. Rollback: an import's rows are findable by `source='import' AND reference_id=<id>`, and a counter-import CSV with negated points reverses them (a new file, so a new id).
- **Hash collision between two different files:** 53 bits, and the key also holds the user id. A collision would show rows as "Already imported" in the preview, so it's visible, never silent.
- **Long imports and timeouts:** 100 rows per apply request, each row in its own savepoint. A timed-out chunk is retried by re-uploading the file, and done rows return `duplicate`.
- **Customer-visible history:** imported rows show in My Points as an adjustment by the store (the existing `adjust` label), with no customer note. That's acceptable for a migration.
