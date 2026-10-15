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

`claim_reminder_lots()` runs `UPDATE … SET reminded_at = %s WHERE user_id = %d AND <lot due rule>`, where the lot due rule is `status = 'open' AND reminded_at IS NULL AND expires_at > %s AND expires_at <= %s` and comes from one shared fragment, as `CustomerRepository::reminder_due_sql()` does. On more than 0 affected rows it reads `SUM(remaining)`, `MIN(expires_at)` and `COUNT(DISTINCT DATE(expires_at))` of the user's open lots with `reminded_at = $now`. Otherwise it returns null: a racing run that got 0 rows sends nothing.

`restamp_open_expiry()` builds its new-date expression once and uses it twice: `SET reminded_at = IF( <expr> <=> expires_at, reminded_at, NULL ), expires_at = <expr>`. `reminded_at` comes first, because MySQL assigns left to right.

- [ ] **Step 1: Write the failing tests** in `ExpiryRemindersTest`

The batch helper `customer_with_lots( array $days_ahead ): array`:
- adds `ExpiryMode::BATCH` to `yayloyalty_expiry_modes` (removed in `tearDown()`);
- pins `mode` batch with `batch_months` 12;
- earns 100 points per entry, with order ids 1..n;
- sets `OPTION_APPLIED`;
- sets each lot's `expires_at` to now + that many days by SQL;
- returns the lot ids in order.

```php
public function test_batch_reminds_due_lots_once_with_their_sum_and_first_date(): void {
    $lots = $this->customer_with_lots( [ 5, 20, 200 ] );

    ( new ExpiryRunner() )->run();
    ( new ExpiryRunner() )->run();

    $this->assertCount( 1, $this->sent );
    $this->assertStringContainsString( '200 Points will expire, starting', (string) $this->sent[0]['message'] );
    $this->assertNotNull( $this->lot_row( $lots[0] )['reminded_at'] );
    $this->assertNotNull( $this->lot_row( $lots[1] )['reminded_at'] );
    $this->assertNull( $this->lot_row( $lots[2] )['reminded_at'] );
}

public function test_a_lot_entering_the_window_later_gets_its_own_reminder(): void {
    $lots = $this->customer_with_lots( [ 5, 200 ] );
    ( new ExpiryRunner() )->run();

    $this->set_lot_expires_at( $lots[1], Dates::offset( 25 * DAY_IN_SECONDS ) );
    ( new ExpiryRunner() )->run();

    $this->assertCount( 2, $this->sent );
    $this->assertStringContainsString( '100 Points will expire on', (string) $this->sent[1]['message'] );
}

public function test_a_restamp_that_moves_a_lot_clears_its_reminder(): void {
    $lots = $this->customer_with_lots( [ 5 ] );
    ( new ExpiryRunner() )->run();

    ( new LotRepository() )->restamp_open_expiry( 12, ExpiryPolicy::grace_floor( Dates::now() ), false );

    $this->assertNull( $this->lot_row( $lots[0] )['reminded_at'] );
}
```

- [ ] **Step 2: Run the tests to confirm they fail**

Run: `composer test:integration -- --filter ExpiryRemindersTest`
Expected: FAIL. The 3 batch tests fail because `reminded_at` is an unknown column and no email is sent. The 5 inactivity tests still pass.

- [ ] **Step 3: Add `reminded_at` to `LotsTable`, then add the `LotRepository` methods and the `restamp_open_expiry()` change, then `CustomerRepository::mark_reminded()`**

- [ ] **Step 4: Add the batch branch to `ExpiryReminders::run()`, replacing phase 1's early return for batch**

Order: claim the lots, then call `mark_reminded()`, then `relay_expiring_soon( $user_id, $claim['points'], $claim['expires_at'], $claim['is_single_date'] )`.

- [ ] **Step 5: Run the tests to confirm they pass**

Run: `composer test:integration -- --filter ExpiryRemindersTest`
Expected: PASS, 8 tests. `composer test` and `composer test:integration` still pass in full.

- [ ] **Step 6: Commit**

Stage: `includes/Database/Schema/LotsTable.php`, `includes/Repositories/LotRepository.php`, `includes/Repositories/CustomerRepository.php`, `includes/Engine/Expiry/ExpiryReminders.php`, `docs/architecture.md`, `tests/integration/Engine/Expiry/ExpiryRemindersTest.php`
Message: `feat(expiry): remind customers before each batch of points expires`

### Task 2: Generalise the YayMail base to any loyalty email

**Files:**
- Modify: `includes/Engine/Emails/LoyaltyEmail.php` and `includes/Engine/Emails/LedgerEntryEmail.php` (move `get_template_values()` up)
- Rename: `includes/Integrations/YayMail/LedgerEntryYayMailEmail.php` → `LoyaltyYayMailEmail.php`, `LedgerEntryShortcodes.php` → `LoyaltyEmailShortcodes.php`, `templates/yaymail-ledger-entry.php` → `templates/yaymail-loyalty-email.php`
- Modify: `PointsEarnedYayMailEmail.php`, `PointsEarnedShortcodes.php`, `PointsExpiredYayMailEmail.php`, `PointsExpiredShortcodes.php`, `YayMailIntegration.php` (docblock), `docs/architecture.md` (the YayMail paragraph names)

**Interfaces:**
- Produces: `LoyaltyYayMailEmail`, `LoyaltyEmailShortcodes`, `LoyaltyEmail::get_template_values()` (README contract under YayMail). `placeholders_to_shortcodes()` gains `'{expiry_date}' => LoyaltyEmailShortcodes::tag( 'expiry_date' )`.

- [ ] **Step 1: Move `get_template_values()` from `LedgerEntryEmail` up to `LoyaltyEmail`, unchanged**

- [ ] **Step 2: Rename the three files and their classes with `git mv`, retype `LedgerEntryEmail` → `LoyaltyEmail` in the base checks, and update every reference**

The references are the four subclasses, the template, the docblocks and `docs/architecture.md`. Add `{expiry_date}` to `placeholders_to_shortcodes()`.

- [ ] **Step 3: Verify**

Run: `git grep -n "LedgerEntryYayMailEmail\|LedgerEntryShortcodes\|yaymail-ledger-entry" -- includes docs/architecture.md`
Expected: no output.

Run: `composer test:integration -- --filter 'PointsEarnedEmailTest|PointsAdjustedEmailTest'`
Expected: PASS.

Then load WordPress read-only, as YayMail does, and render PointsEarnedYayMailEmail's and PointsExpiredYayMailEmail's sample shortcodes.
Expected: both return non-empty `customer_name` and `points`.

- [ ] **Step 4: Commit**

Stage: the renamed and modified files
Message: `refactor(yaymail): adapt any loyalty email, not only ledger ones`

### Task 3: Points expiring soon in YayMail

**Files:**
- Create: `includes/Integrations/YayMail/PointsExpiringSoonYayMailEmail.php`, `includes/Integrations/YayMail/PointsExpiringSoonShortcodes.php`
- Modify: `includes/Integrations/YayMail/YayMailIntegration.php` (register both), `docs/architecture.md` (the YayMail paragraph lists three emails)

**Interfaces:**
- Consumes: task 2's base and `PointsExpiringSoonEmail`'s template values (README contract under Email)
- Produces: the YayMail shortcodes listed in the README contract. `VALUE_KEYS`: `customer_name` → `greeting_name`, `expiring_points` → `expiring_text`, `points_url` → `cta_url`. `HTML_KEYS`: `expiring_points`, `points_value`, `points_value_text`.

- [ ] **Step 1: Create the two classes, modelled on the Points expired pair, and register both in `YayMailIntegration`**

The default body is the greeting, `expiring_points`, then `points_value_text`.

- [ ] **Step 2: Verify**

Load WordPress read-only and fire `yaymail_register_emails` and `yaymail_register_shortcodes` as YayMail does, or open YayMail → Emails.
Expected:
- "Points expiring soon" is listed;
- its sample `expiring_points` renders "<strong>120 Points</strong> will expire on <date 30 days out>.";
- `expiry_date` renders that date.

- [ ] **Step 3: Commit**

Stage: `includes/Integrations/YayMail/PointsExpiringSoonYayMailEmail.php`, `includes/Integrations/YayMail/PointsExpiringSoonShortcodes.php`, `includes/Integrations/YayMail/YayMailIntegration.php`, `docs/architecture.md`
Message: `feat(yaymail): design the points expiring soon email in yaymail`
