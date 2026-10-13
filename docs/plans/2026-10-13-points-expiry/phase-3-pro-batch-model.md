# Phase 3: Pro batch model

**Plan:** `README.md` in this directory: the Status, constraints and interface contract
**Depends on:** Phase 2. Branch `paul/feat/expiry-batches`, stacked on `paul/feat/expiry-reminders` while the earlier phases await their PRs.
**Delivers:** in Pro, a merchant switches once from inactivity to batches. Each lot then expires `batch_months` after the day it was
earned, the job expires only due lots, and the switch can't be undone.
**Exit criterion:** spec criteria 7 and 11, then criterion 14 repeated with `model = batches`:
1. On the local Pro site, save Expiry on, batches, 1 month.
2. Give a test customer two earns with `created_at` 40 days ago (300) and today (200).
3. Save the settings again (a recompute), then run `wp action-scheduler run --hooks=yayloyalty_expire_points --force`.

Expected:
- one `expire −300` row; the older lot reads `expired` and the newer one `open`, with remaining 200;
- `customers.expires_at` = the newer lot's `created_at` + 1 month;
- saving `model = inactivity` answers 400 `yayloyalty_expiry_model_one_way`;
- `./scripts/run.sh release lite --no-prompt` validates, and the Lite Expiry tab shows no model cards.

### Task 1: Batch dates, recompute and the one-way switch

**Files:**
- Modify: `includes/Services/ExpiryRules.php` (the batch branch of `lot_expires_at`, `customer_expires_at` and `expiring_points`)
- Modify: `includes/Services/LotWriter.php` (passes `LotRepository::earliest_open_expiry()` to `customer_expires_at()` under batches)
- Modify: `includes/Repositories/LotRepository.php` (`sum_due`, `earliest_open_expiry`, `recompute_expiry`)
- Modify: `includes/Repositories/CustomerRepository.php` (`recompute_expiry_from_lots`)
- Modify: `includes/Engine/Expiry/ExpiryRecompute.php` (batches: `recompute_expiry( batch_months )`, then `recompute_expiry_from_lots()`. Inactivity: `recompute_expiry( null )`, then Phase 1's customer recompute)
- Modify: `includes/Controllers/SettingsRestController.php` (one-way check before `update_settings()`)
- Modify: `includes/Pro/YayLoyaltyPro.php` (`boot()`: `add_filter( ExpiryRules::MODEL_FILTER, [ self::class, 'expiry_model' ] )`, which returns the stored `expiry.model`)

**Interfaces:**
- Consumes: README contract `ExpiryRules`, `LotRepository`, `ExpiryRecompute`.
- Produces:
  - `LotRepository::recompute_expiry( ?int $months )`: `UPDATE … SET expires_at = DATE_ADD( created_at, INTERVAL %d MONTH ) WHERE status = 'open'`, or NULL when `$months` is null.
  - `CustomerRepository::recompute_expiry_from_lots()`: `expires_at` = MIN open lot `expires_at` for `balance > 0`, else NULL.
  - The `yayloyalty_expiry_model_one_way` error: HTTP 400, returned when the stored `expiry.model` is `batches` and the payload's `expiry.model` is `inactivity`.
  - `Dates::add_months()` (Phase 1) must equal MySQL's `DATE_ADD` for `2026-01-31 + 1` and `2024-01-31 + 1` (leap year: `2024-02-29`).
    Verify with `wp eval` against `wp db query "SELECT DATE_ADD('2024-01-31 09:00:00', INTERVAL 1 MONTH)"`.

- [x] **Step 1: Implement the files above.** `ExpiryRules::batch_months()` was added. `LotWriter` reads `earliest_open_expiry()` only under batches, which saves a query per ledger row under inactivity. `recompute_expiry_from_lots()` reads `lots` in a join, as the admin list joins `users`, and writes only `customers`.
- [x] **Step 2: Verify.** Done 2026-10-13 with a throwaway harness check (12 assertions):
  - criterion 7: 12 months gives `2027-03-14` and `2027-01-31`; the customer's date is the earliest lot; the recompute to 1 month gives `2026-02-28`, the same as PHP;
  - the exit end-to-end over REST: `expire −300`, the old lot `expired`, the new lot `open` 200, and `expires_at` is the new lot + 1 month;
  - inactivity after batches gets 400 `yayloyalty_expiry_model_one_way`, and batches → batches still saves.

  Both suites pass (33 unit, 78 integration). Lint is clean.
- [x] **Step 3: Commit** `feat(expiry): add Pro's batch expiry model`

### Task 2: Pro model section in the Expiry tab

**Files:**
- Create: `apps/admin/src/pro/settings/ExpiryModelSection.tsx` (the two model cards with the one-way copy; the inactivity card is disabled once `batches` is saved)
- Create: `apps/admin/src/pro/settings/ExpiryBatchExample.tsx` (the worked example)
- Create: `apps/admin/src/pro/settings/expiry-model.copy.ts`
- Modify: `apps/admin/src/pro/index.ts`, `apps/admin/src/pages/settings/ExpirySettingsPage.tsx` (renders `ExpiryModelSection` behind `IS_PRO_BUILD`, and asks for confirmation before saving a switch to `batches` with the one-dialog primitive), `apps/admin/src/pages/settings/expiry-settings.form.ts` (adds `model`, `batch_months`)

**Interfaces:**
- Consumes: Task 1's one-way error (shown with `toastApiError()`).
- Produces: `ExpiryModelSection`, exported through `pro/index.ts`.
- Worked example: a fixed sample balance of 9,850 in batches of 4,200, 3,000 and 2,650, dated 7, 4 and 2 months before today. Each
  "expires" date is `earned + batch_months` (exact day), and it updates as the field changes. The sentence below it explains the
  next-expiry move after a 5,000 redemption, as in the design. This is a plan decision: the spec's "store's own numbers" would need a new
  endpoint, so it uses a sample.
- No "Spend order" control.

- [x] **Step 1: Implement the files above.** Other changes:
  - Under batches, `ExpiryInactivityCard` retitles itself "Expire points after they are earned" and hides the inactivity window and its sentence.
  - The confirm uses `ConfirmDialog`. Since the tidy commit `8953086`, it lives inside `ExpiryModelSection` with its strings in `expiry-model.copy.ts`, and opens when the batches card is picked rather than on save. The shared page holds no Pro logic.
  - Lite ignores a stored `batches`: `ExpiryInactivityCard` checks `IS_PRO_BUILD && model === 'batches'` (fixed after `/yayplancheck`, spec criterion 11).
- [x] **Step 2: Verify.** Done 2026-10-13:
  - tsc, eslint and prettier are clean, and Vitest passes 73/73; every new file is under 200 lines;
  - the Lite `main.js` has 0 hits each for the model cards, the switch confirm and the reminders, and keeps the upsell; Pro has 1 of each and no upsell;
  - the changed core PHP adds no code-level `Pro` word, and `includes/YayLoyalty.php` stays at 2, the validator's limit.
- [x] **Step 3: Commit** `feat(settings): add Pro's expiry model section`
