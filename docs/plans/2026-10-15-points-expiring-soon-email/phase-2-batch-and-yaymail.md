# Phase 2: Batch reminder and YayMail

**Plan:** `README.md` in this directory: the Status, constraints and interface contract
**Depends on:** phase 1 (all tasks)
**Delivers:** in Pro batch mode each lot is announced once before it expires, and merchants with YayMail can design the reminder.
**Exit criterion:**
1. On a Pro build (`./scripts/run.sh dev pro`), switch to batch expiry (12 months) with the reminder at 30 days, and run the job once so the lots get dated.
2. For one customer, set two open lots' `expires_at` to now + 5 and now + 20 days by SQL, with `reminded_at` NULL, and run `wp action-scheduler run --hooks=yayloyalty_expiry_run`.

   Expected: one email for the two lots' summed `remaining`, "starting" the now + 5 date, and both lots get `reminded_at`.
3. Back-date a third lot into the window and run the job again.

   Expected: one more email for that lot only.
4. Open YayMail.

   Expected: it lists "Points expiring soon" with sample values, and the Points earned and Points expired designs still render in YayMail's preview.

`composer test:integration` passes, including the whole `ExpiryRemindersTest`.

### Task 1: Per-lot reminders in batch mode

**Files:**
- Modify: `includes/Database/Schema/LotsTable.php` (`reminded_at`)
- Modify: `includes/Repositories/LotRepository.php` (`reminder_due_user_ids()`, `claim_reminder_lots()`; `restamp_open_expiry()` sets `reminded_at` before `expires_at`)
- Modify: `includes/Repositories/CustomerRepository.php` (`mark_reminded()`)
- Modify: `includes/Engine/Expiry/ExpiryReminders.php` (the batch branch: horizon `Dates::offset( $days * DAY_IN_SECONDS, $now )`; `relay_expiring_soon()` gets `is_single_date` from the claim)
- Modify: `docs/architecture.md` (the job paragraph: per-lot reminders, the `lots` columns)
- Test: `tests/integration/Engine/Expiry/ExpiryRemindersTest.php` (add batch tests: each lot reminded once; one email sums the due lots and says "starting" the earliest date; a restamp that moves a lot clears `reminded_at`)

**Interfaces:**
- Consumes: the `ExpiryReminders` step (phase 1 task 3)
- Produces: see the README contract under Schema and Repositories.

Steps: expand after phase 1 lands. Note: batch tests need Pro's `yayloyalty_expiry_modes` filter, or the test adds `ExpiryMode::BATCH` to that filter itself.

### Task 2: Generalise the YayMail base to any loyalty email

**Files:**
- Modify: `includes/Engine/Emails/LoyaltyEmail.php` and `includes/Engine/Emails/LedgerEntryEmail.php` (move `get_template_values()` up)
- Rename: `includes/Integrations/YayMail/LedgerEntryYayMailEmail.php` → `LoyaltyYayMailEmail.php`, `LedgerEntryShortcodes.php` → `LoyaltyEmailShortcodes.php`, `templates/yaymail-ledger-entry.php` → `templates/yaymail-loyalty-email.php`
- Modify: `PointsEarnedYayMailEmail.php`, `PointsEarnedShortcodes.php`, `PointsExpiredYayMailEmail.php`, `PointsExpiredShortcodes.php`, `YayMailIntegration.php` (docblock), `docs/architecture.md` (the YayMail paragraph names)

**Interfaces:**
- Produces: `LoyaltyYayMailEmail`, `LoyaltyEmailShortcodes`, `LoyaltyEmail::get_template_values()` (README contract under YayMail). `placeholders_to_shortcodes()` gains `'{expiry_date}' => LoyaltyEmailShortcodes::tag( 'expiry_date' )`.

Steps: expand after phase 1 lands. The verification is the Points earned and Points expired YayMail preview and a test send, unchanged. Commit as `refactor(yaymail): adapt any loyalty email, not only ledger ones`.

### Task 3: Points expiring soon in YayMail

**Files:**
- Create: `includes/Integrations/YayMail/PointsExpiringSoonYayMailEmail.php`, `includes/Integrations/YayMail/PointsExpiringSoonShortcodes.php`
- Modify: `includes/Integrations/YayMail/YayMailIntegration.php` (register both), `docs/architecture.md` (the YayMail paragraph lists three emails)

**Interfaces:**
- Consumes: task 2's base and `PointsExpiringSoonEmail`'s template values (README contract under Email)
- Produces: the YayMail shortcodes listed in the README contract. `VALUE_KEYS`: `customer_name` → `greeting_name`, `expiring_points` → `expiring_text`, `points_url` → `cta_url`. `HTML_KEYS`: `expiring_points`, `points_value`, `points_value_text`.

Steps: expand after phase 1 lands. The default body is the greeting, `expiring_points`, then `points_value_text`.
