# Adjust points customer description Implementation Plan

> **For agentic workers:** execute tasks in order, one at a time. Steps use `- [ ]` for tracking. Read the backlog item linked below before starting; the plan argues from it.

**Status:** done 2026-10-12
**Goal:** The Adjust points dialog's "Custom description for the customer" is stored on the ledger row and replaces "Adjustment by {store}" wherever the customer sees that adjustment.
**Spec:** none, planned from `docs/backlog/2026-10-08-adjust-points-customer-note-not-sent.md`
**Architecture:** A new nullable `customer_note` column on the ledger row, written only by `LedgerService::adjust()` (single writer, decision 2026-10-01). It sits beside `note` because `note` is the staff-only reason, and the ledger has no meta column. Display is read-side only: `ShopperActivityRow::label()`, the Points adjusted email, and the admin ledger.
**Touches:** ledger schema, `LedgerEntry`, `LedgerService`, the adjust route, `ShopperActivityRow`, `PointsAdjustedEmail` and its two templates (public contract), and the admin SPA's adjust dialog, ledger schema, drawer ledger rows and ledger export. All Lite; nothing under `includes/Pro/` or `apps/admin/src/pro/`.
**Tests:**
- `tests/integration/Storefront/ShopperActivityRowTest.php` (integration): `LedgerService::adjust()` stores the description; the label is the description when set and "Adjustment by {store}" when not; the refund-restore ADJUST row never shows one.
- `tests/integration/Emails/PointsAdjustedEmailTest.php` (integration, new): `build_values()` carries `customer_note` when set and an empty string when not.

## Requirements

- **Goal:** A customer sees the admin's custom description in place of "Adjustment by {store}" for that adjustment.
- **Out of scope:** the staff-only Activity tab event (`CustomerActivityService::record_points_adjusted()`), ledger search (`LedgerRepository::history_where()`), and the bulk-adjust preview route.
- **Acceptance criteria:**
  1. `POST /customers/{id}/adjust` accepts optional `customer_note` (string, max 120 chars, sanitized). An empty or whitespace-only value stores NULL.
  2. The storefront activity list, My Account history and its CSV show the description for that adjustment, and "Adjustment by {store}" for adjustments without one.
  3. The Points adjusted email shows the description when set.
  4. The admin drawer's ledger tab shows it under the reason, and the ledger CSV has a "Customer description" column.
  5. `AdjustPointsOptions.tsx` has no `TODO(adjust-points)`.
  6. End to end: adjust a customer with the switch on and "Thanks for the review!" typed, then see that text on the storefront, in the email and in the drawer.
- **Decided with the user (2026-10-12):**
  - Shown in: storefront, the Points adjusted email, and the admin ledger and drawer. "Storefront only" was also ticked; read as all three.
  - 1.0.0 hasn't shipped, so there's no version bump: dbDelta adds the column on install.
  - Max 120 characters.
  - The two tests above.

## Global constraints

- Product PHP runs on PHP 7.4: no enums, `match`, `readonly`, promoted constructor properties, `?->`, named arguments, union types, `mixed` and the rest listed in `.claude/rules/php-standards.md`.
- Only `LedgerService` writes ledger rows and balances.
- Email template file names and variables are a public contract: only add.
- Wire is snake_case; camelCase maps only in `apps/admin/src/lib/api/*.api.ts`. A TS copy of a PHP shape opens "Mirrors <PHP symbol>" and doesn't repeat PHP's validation.
- Lint only the changed files with `composer lint -- <files>`. Full eslint and `tsc --noEmit` already fail on `dev/edd` (`docs/known-issues.md`): expect no new errors in the changed files.
- Branch from `dev/edd` as `trace/fix/adjust-points-customer-note`.

## Review focus

- **The refund-restore row:** `LedgerService::restore_refund()` writes an ADJUST row with a non-manual source. It must never show a customer description; only `LedgerSource::MANUAL` rows do. Covered by the storefront test.
- **Text that looks like HTML or a formula:** a description such as `<b>hi</b>` or `=1+1` must render as text everywhere. `sanitize_text_field` strips tags; the storefront escapes the label (`activity-row.php` uses `esc_html`, `launcher.js` uses `textContent`); `AccountHistoryCsv::cell()` already guards formulas. Checked in Task 1, Step 5.
- **Switch on, field empty:** this sends no `customer_note`, so the fallback label shows. There's no submit blocker, matching the helper copy "unless you set one".
- **Bulk adjust:** every selected customer gets the same description. Expected, because `useBulkAdjustPointsMutation()` reuses `adjustCustomerPoints()`.

---

### Task 1: Store the description and show it on the storefront

**Files:**
- Modify: `includes/Database/Schema/LedgerTable.php` (DDL and `expected_columns()`)
- Modify: `includes/Models/LedgerEntry.php` (constructor, `from_row()`, `to_row()`, `get_format()`, getter)
- Modify: `includes/Services/LedgerService.php` (`adjust()`)
- Modify: `includes/Controllers/Schemas/CustomerRouteArgs.php` (`adjust()`)
- Modify: `includes/Controllers/CustomerRestController.php` (`adjust_points()`)
- Modify: `includes/Engine/Storefront/ShopperActivityRow.php` (`label()` and the class docblock)
- Test: `tests/integration/Storefront/ShopperActivityRowTest.php`

**Interfaces:**
- Produces:
  - Column `customer_note varchar(191) DEFAULT NULL`, right after `note`.
  - `LedgerEntry::__construct( …, ?string $idempotency_key = null, ?string $customer_note = null )` as a 16th parameter, so the existing positional callers are unchanged.
  - `LedgerEntry::get_customer_note(): ?string`.
  - `LedgerService::adjust( int $user_id, int $points, string $note, int $actor_id, bool $adjust_lifetime = true, ?string $customer_note = null ): LedgerEntry`. A trimmed empty string becomes `null`.
  - REST arg `customer_note`: `'type' => 'string'`, not required, `'maxLength' => 120`, `'sanitize_callback' => 'sanitize_text_field'`, `'validate_callback' => 'rest_validate_request_arg'`. Copy the `DashboardRestController.php:67-79` pattern and its comment.

- [ ] **Step 1: Write the failing tests** in `ShopperActivityRowTest`, using `$this->ledger_service()`:
  - `test_adjustment_with_customer_note_is_labelled_with_it`: `adjust( $user, 50, 'internal reason', 1, true, 'Thanks for the review!' )`. Then `find()` returns `get_customer_note() === 'Thanks for the review!'`, and `items()[0]['label'] === 'Thanks for the review!'`.
  - `test_adjustment_without_customer_note_keeps_store_label`: adjust without one; the label is `'Adjustment by ' . <store name>`, and `get_customer_note()` is `null`. Do the same for `customer_note` `'   '`.
  - `test_refund_restore_row_never_shows_customer_note`: a non-MANUAL ADJUST `LedgerEntry` built with a `customer_note` is still labelled "Adjustment by {store}".
- [ ] **Step 2: Run them; expect failures.** `composer test:integration -- --filter ShopperActivityRowTest`. They fail, because `adjust()` has no sixth parameter. Per `docs/known-issues.md`, read the output, not the exit code.
- [ ] **Step 3: Implement the interfaces above.**
  - Controller: pass `isset( $request['customer_note'] ) ? (string) $request['customer_note'] : null`.
  - `label()`, `default:` branch: return the customer note when `LedgerSource::MANUAL === $entry->get_source()` and it's non-empty. Otherwise keep "Adjustment by %s".
  - Update the class docblock: the admin `note` still never shows; the customer note does.
- [ ] **Step 4: Add the column to the Local site and run the tests.**
  - Run `Installer::install()` through `wp eval` (see memory `wp-cli-local-socket`). Then `Installer::verify()` returns no missing ledger column.
  - Run the filter from Step 2; all of `ShopperActivityRowTest` passes, including the existing key and note assertions.
- [ ] **Step 5: Check over REST.** `POST /wp-json/yayloyalty/v1/customers/{id}/adjust` with `customer_note`:
  - `<b>Hi</b> =1+1`: stored as `Hi =1+1`.
  - 121 characters: a 400 that names `customer_note`.
  - Omitted: stored as NULL.
- [ ] **Step 6: Lint and commit.**
  - Run `composer lint -- <the six PHP files and the test>`: no errors.
  - Stage those files.
  - Message: `feat(ledger): store a customer-facing description on manual adjustments`.

### Task 2: Send the description from the Adjust points dialog

**Files:**
- Modify: `apps/admin/src/lib/schema/customer.schema.ts` (`adjustPointsSchema`)
- Modify: `apps/admin/src/lib/api/customer.api.ts` (`adjustCustomerPoints()`)
- Modify: `apps/admin/src/pages/customers/components/CustomersAdjustPointsDialog.tsx` (`handleSubmit`, docblock at line ~59)
- Modify: `apps/admin/src/pages/customers/components/adjust-points/AdjustPointsOptions.tsx` (remove the TODO at line 38, docblock at line 14, add `maxLength={120}`)

**Interfaces:**
- Consumes: the Task 1 REST arg `customer_note`.
- Produces:
  - `adjustPointsSchema` gains `customerNote: z.string().optional()`.
  - `adjustCustomerPoints()` sends `customer_note: input.customerNote` only when it's defined.

- [ ] **Step 1: Implement.** `handleSubmit` passes `customerNote: state.showCustomerNote && state.customerNote.trim() ? state.customerNote.trim() : undefined`. Update the dialog docblock: both notify and the description are now sent.
- [ ] **Step 2: Verify in wp-admin.** Run `./scripts/run.sh dev`, then open `/wp-admin/admin.php?page=yayloyalty#/customers`. Adjust a customer by +10:
  - Switch on, "Thanks for the review!" typed: the request body (network tab) has `customer_note: "Thanks for the review!"`.
  - Switch off: no `customer_note` key.
  - A 130-character paste: the input stops at 120.
- [ ] **Step 3: Check.**
  - Run `git grep -n "TODO(adjust-points)" apps/admin/src`: no output.
  - Run eslint on the four files only: no errors.
- [ ] **Step 4: Commit.** Stage the four files. Message: `feat(admin): send the adjust dialog's customer description`.

### Task 3: Show the description to staff

**Files:**
- Modify: `includes/Models/LedgerEntry.php` (`to_array()`)
- Modify: `includes/Controllers/LedgerRestController.php` (`define_item_schema()`, next to `note`)
- Modify: `apps/admin/src/lib/schema/ledger.schema.ts` (`ledgerEntrySchema`)
- Modify: `apps/admin/src/pages/customers/drawer/customer-drawer.ledger-rows.ts` (`toLedgerRow()`, the `subLine` docblock)
- Modify: `apps/admin/src/pages/customers/drawer/customer-drawer.ledger-export.ts` (header and cell)

**Interfaces:**
- Produces:
  - `to_array()['customer_note']`: the value when the source is `LedgerSource::MANUAL`, else `null`.
  - The schema property is `'type' => [ 'string', 'null' ]`, "Customer-facing description. Present on manual adjustments only."
  - TS gets `customer_note: z.string().nullable()`.

- [ ] **Step 1: Implement.**
  - `toLedgerRow()`: for an entry with `customer_note`, `subLine` is `sprintf( __( 'Customer sees: %s', 'yayloyalty' ), customer_note )`. The campaign sub-line stays for earn rows; the two never overlap.
  - Export: add a "Customer description" column after the note column, with cell `entry.customer_note ?? ''`.
- [ ] **Step 2: Verify in wp-admin.** In the drawer's Ledger tab for the Task 2 customer:
  - The row reads the reason, with "Customer sees: Thanks for the review!" under it.
  - Export CSV: the new column holds the text, and is empty for other rows.
- [ ] **Step 3: Lint and commit.**
  - Lint the PHP with `composer lint -- <files>` and the TS with eslint on its files: no errors.
  - Stage the five files.
  - Message: `feat(admin): show the customer description in the drawer ledger`.

### Task 4: Show the description in the Points adjusted email

**Files:**
- Modify: `includes/Engine/Emails/PointsAdjustedEmail.php` (`build_values()`)
- Modify: `includes/Engine/Emails/templates/emails/yayloyalty-points-adjusted.php`
- Modify: `includes/Engine/Emails/templates/emails/plain/yayloyalty-points-adjusted.php`
- Test: `tests/integration/Emails/PointsAdjustedEmailTest.php` (new, modelled on `PointsEarnedEmailTest.php`)

**Interfaces:**
- Produces: template variable `$customer_note` (string; empty when there is none).

- [ ] **Step 1: Write the failing tests.**
  - `test_values_include_customer_note`: an entry adjusted with `'Thanks for the review!'`; `build_values()` (through the test seam `PointsEarnedEmailTest` uses) has `customer_note === 'Thanks for the review!'`.
  - `test_values_have_empty_customer_note_without_one`: no description gives `customer_note === ''`.
- [ ] **Step 2: Run** `composer test:integration -- --filter PointsAdjustedEmailTest`. Both fail.
- [ ] **Step 3: Implement.**
  - `build_values()` adds `'customer_note' => (string) $entry->get_customer_note()`.
  - Both templates add `@var string $customer_note` to their docblock, with "Empty when the adjustment has none".
  - Each renders it guarded by `! empty( $customer_note )`, right after `adjusted_text`: HTML as `<p>` + `esc_html()`, plain as `esc_html( Text::plain( $customer_note ) )`.
- [ ] **Step 4: Run the tests again; both pass.** Then adjust the Task 2 customer with "Notify the customer by email" ticked. The caught mail shows "Thanks for the review!" under the adjusted sentence, in both the HTML and plain parts.
- [ ] **Step 5: Lint and commit.**
  - Run `composer lint -- <the four files>`.
  - Stage them.
  - Message: `feat(emails): show the customer description in the Points adjusted email`.

### Task 5: Close the backlog item

- [ ] **Step 1:** Run `/yaybacklog check 2026-10-08-adjust-points-customer-note-not-sent`. All three open boxes come back met; tick them and remove the item.
- [ ] **Step 2:** Run `composer test:integration`: no new failures compared with `dev/edd`.
- [ ] **Step 3: Commit.**
  - Stage the plan file (Status `done 2026-10-12`) and the removed backlog item.
  - Message: `docs(backlog): close the adjust points customer note item`.
