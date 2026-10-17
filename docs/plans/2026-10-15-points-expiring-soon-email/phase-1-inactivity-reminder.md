# Phase 1: Inactivity reminder

**Plan:** `README.md` in this directory: the Status, constraints and interface contract
**Depends on:** `paul/feat/expiry-points` (lots, `ExpiryRunner`, `ExpiryPolicy`, the Expiry tab)
**Delivers:** the Expiry tab gets a "Remind before expiry" row, and in inactivity mode each customer gets "Points expiring soon" once per expiry date, logged in their activity.
**Exit criterion:** on a Lite build (`./scripts/run.sh dev lite`):
1. Turn inactivity expiry on (365 days) and enable Points expiring soon in WooCommerce → Settings → Emails.
2. Run `wp action-scheduler run --hooks=yayloyalty_expiry_run` once so the dates get stamped.
3. Run `wp db query "UPDATE wp_yayloyalty_customers SET expires_at = DATE_ADD(UTC_TIMESTAMP(), INTERVAL 10 DAY), reminder_for = NULL WHERE user_id = <id>"`.
4. Run the job again.

WP Mail Logging should show exactly one "Your Points expire soon" email to that customer, naming their balance and that date. A third run sends nothing. Their drawer activity shows the reminder. `composer test`, `composer test:integration` (including `ExpiryRemindersTest`) and `pnpm --filter @yayloyalty/admin-page exec tsc --noEmit` should all pass.

### Task 0: Branch and commit the spec and plan

- [ ] **Step 1: Create the branch**

Run: `git switch -c paul/feat/expiry-reminder paul/feat/expiry-points`
Expected: `Switched to a new branch 'paul/feat/expiry-reminder'`

- [ ] **Step 2: Commit**

Stage: `docs/specs/2026-10-15-points-expiring-soon-email-design.md`, `docs/plans/2026-10-15-points-expiring-soon-email/README.md`, `docs/plans/2026-10-15-points-expiring-soon-email/phase-1-inactivity-reminder.md`, `docs/plans/2026-10-15-points-expiring-soon-email/phase-2-batch-and-yaymail.md`
Message: `docs(expiry): design and plan the points expiring soon email`

Leave the untracked `docs/plans/2026-10-13-points-expiry/` and `tests/integration/Engine/Expiry/ExpiryEdgeCasesTest.php` unstaged. They belong to `paul/feat/expiry-points`.

### Task 1: `reminder_days` setting and the Expiry tab row

**Files:**
- Modify: `includes/Helpers/SettingsHelper.php` (defaults, `schema()`, `is_valid()`, `is_valid_value()`, the `REMINDER_DAYS_MAX` const)
- Modify: `includes/Engine/Expiry/ExpiryPolicy.php` (`reminder_days()`, `window_days()`, `is_applied()`; `is_due()` uses `is_applied()`)
- Modify: `apps/admin/src/lib/schema/settings.schema.ts`
- Modify: `apps/admin/src/pages/settings/expiry-settings.form.ts`
- Create: `apps/admin/src/pages/settings/ExpiryReminderRow.tsx`
- Modify: `apps/admin/src/pages/settings/ExpirySettingsPage.tsx` (render the row after the inactivity card or batch section, in both builds)
- Modify: `apps/admin/src/pages/settings/EmailsSettingsPage.tsx:101` (link `to="/settings/expiry"`)

**Interfaces:**
- Produces: see the README contract under Settings.

- [ ] **Step 1: Add `'reminder_days' => 30` to the `expiry` defaults and `'reminder_days' => 'reminder_days'` to `schema()` in `SettingsHelper`**

`is_valid_value()` case `'reminder_days'` is `ConfigSpec::is_limit( $value ) && (float) $value <= self::REMINDER_DAYS_MAX`. `validate()` must coerce it like `expiry_window`; follow how that type is coerced.

- [ ] **Step 2: Add `reminder_days()`, `window_days()` and `is_applied()` to `ExpiryPolicy`, and make `is_due()` call `is_applied()`**

- [ ] **Step 3: Add the cross-check to `SettingsHelper::is_valid()`**

It runs after `fields_valid()` passes, and only when `$input['expiry']` is an array. Merge it over `self::get_settings()['expiry']`, build `new ExpiryPolicy( $merged )`, and return false when `reminder_days() >= window_days()`. Update the docblock to say so.

- [ ] **Step 4: Verify the server rule**

Run (as an admin, with the REST nonce or `wp eval`): `wp eval 'var_dump( YayLoyalty\Helpers\SettingsHelper::is_valid( [ "expiry" => [ "enabled" => true, "inactivity_days" => 30, "reminder_days" => 30 ] ] ), YayLoyalty\Helpers\SettingsHelper::is_valid( [ "expiry" => [ "reminder_days" => 400 ] ] ), YayLoyalty\Helpers\SettingsHelper::is_valid( [ "expiry" => [ "inactivity_days" => 365, "reminder_days" => 30 ] ] ) );'`
Expected: `bool(false) bool(false) bool(true)`. The cross-check runs only while expiry is on, so the first case sets `enabled`.

- [ ] **Step 5: Add `reminder_days` to `settingsSchema.expiry`, and add the `superRefine` to `expirySettingsFormSchema`**

The window is `IS_PRO_BUILD && mode === 'batch' ? batch_months * 28 : inactivity_days`. The message is `__('Send the reminder fewer days ahead than the expiry window.', 'yayloyalty')`.

- [ ] **Step 6: Create `ExpiryReminderRow`**

It's a Card row modelled on `ExpiryInactivityCard`:
- the label "Remind before expiry" (`htmlFor="expiry-reminder-days"`);
- `NumberInputRoot` with `min={1}`, `max={365}`, unit "days", and `disabled={!isEnabled}`;
- the text "sends the “Points expiring soon” email ·" and a `Link to="/settings/emails"` reading "Emails";
- `fieldState.error?.message` below it, in `text-destructive text-sm`.

- [ ] **Step 7: Verify in the admin**

Run: `pnpm --filter @yayloyalty/admin-page exec tsc --noEmit`
Expected: exit 0

Open: `/wp-admin/admin.php?page=yayloyalty#/settings/expiry` on `./scripts/run.sh dev lite`, then on `./scripts/run.sh dev pro` in both modes.
Action: with expiry off, check the row is disabled. Turn expiry on, set the window to 20 days with the reminder at 30, and Save. Then set the reminder to 14 and Save.
Expected:
- the row shows in every case;
- the 30/20 save is blocked by the inline message;
- the 14 save succeeds, and `wp option get yayloyalty_settings --format=json` shows `"reminder_days":14`;
- on the Emails tab, "Expiry reminder timing" opens `#/settings/expiry`.

- [ ] **Step 8: Commit**

Stage: `includes/Helpers/SettingsHelper.php`, `includes/Engine/Expiry/ExpiryPolicy.php`, `apps/admin/src/lib/schema/settings.schema.ts`, `apps/admin/src/pages/settings/expiry-settings.form.ts`, `apps/admin/src/pages/settings/ExpiryReminderRow.tsx`, `apps/admin/src/pages/settings/ExpirySettingsPage.tsx`, `apps/admin/src/pages/settings/EmailsSettingsPage.tsx`
Message: `feat(settings): add the expiry reminder timing`

### Task 2: The Points expiring soon email

**Files:**
- Modify: `includes/Engine/Emails/LoyaltyEmail.php` (`$is_capped`, `send_to_customer()` returns bool)
- Modify: `includes/Engine/Emails/EmailThrottle.php` (docblock: an uncapped email still counts)
- Modify: `includes/Engine/Emails/Emails.php` (`POINTS_EXPIRING_SOON_ACTION`, `add_email_actions()`, `relay_expiring_soon()`)
- Modify: `includes/Engine/Emails/PointsExpiringSoonEmail.php`
- Modify: `includes/Engine/Emails/templates/emails/yayloyalty-points-expiring-soon.php`, `includes/Engine/Emails/templates/emails/plain/yayloyalty-points-expiring-soon.php`
- Modify: `includes/Services/CustomerActivityService.php` (`record_expiring_soon_email_sent()`, and the class docblock's event list)
- Modify: `includes/Enums/ActivityEvent.php` (the docblock's list of events written today)

**Interfaces:**
- Consumes: `ExpiryPolicy::current()->is_batch()` and `reminder_days()` (task 1)
- Produces: see the README contract under Email and Activity.

- [ ] **Step 1: In `LoyaltyEmail`, add `protected $is_capped = true;` and change `send_to_customer( int $user_id ): bool`**

When `$this->is_capped` is false, skip the `allows()` check. Always `record()` an accepted send.

- [ ] **Step 2: Add the action constant, its `add_email_actions()` entry, and `relay_expiring_soon()` to `Emails`**

`relay_expiring_soon()` is modelled on `relay_expiry()`, with the docblock `@param` lines for all four args.

- [ ] **Step 3: Rewrite `PointsExpiringSoonEmail`**

Members:
- `ID`;
- `$is_capped = false`;
- placeholders `{points}`, `{points_label}` and `{expiry_date}`;
- `add_action( Emails::POINTS_EXPIRING_SOON_ACTION . '_notification', [ $this, 'trigger' ], 10, 4 )`.

`trigger()`:
1. Load the user, and return when it's missing.
2. Set `$this->object = $user` (`WP_User`, for the `is_enabled` filter).
3. Return unless `is_enabled()`.
4. Store the four values, then `setup_locale()`, fill the placeholders, and set the recipient.
5. When `send_to_customer()` returns true, call `record_expiring_soon_email_sent( $user_id, $points, $expires_at, ExpiryPolicy::current()->reminder_days() )`.
6. `restore_locale()` in `finally`, as `LedgerEntryEmail::trigger()` does.

Placeholder and template values:
- `{points}` is `SettingsHelper::format_number()`.
- `{points_label}` is `SettingsHelper::points_label( $points )`.
- `{expiry_date}` is `Dates::to_site_date( $expires_at )`.
- `extra_template_args()` returns the template values from the README contract, and `[]` while no reminder is stored.
- Use one whole translatable sentence for each `expiring_text` variant (inactivity, batch single date, batch several dates), with the exact words of spec AC 7.
- `keep_text` is "Earn or redeem before then to keep them." in inactivity mode only.

`prepare_preview()` stores the current user, 120 points, `Dates::offset( 30 * DAY_IN_SECONDS )` and a single date. Update the description to the spec's wording, and drop "Nothing triggers it yet".

- [ ] **Step 4: Rewrite both templates**

Order: greeting, `expiring_text`, `keep_text`, `points_value_text`, the CTA button when `cta_url` isn't '', then additional content. Follow `yayloyalty-points-expired.php`'s structure and fallbacks (empty `extra_template_args()` → the generic sentence it shows today).

- [ ] **Step 5: Add `CustomerActivityService::record_expiring_soon_email_sent()`, per the README contract**

- [ ] **Step 6: Verify the preview, the send and the cap**

Run: `wp eval 'update_option( "yayloyalty_settings", array_replace_recursive( get_option( "yayloyalty_settings", [] ), [ "emails" => [ "throttle_per_week" => 1 ] ] ) ); $u = <customer id>; YayLoyalty\Engine\Emails\EmailThrottle::record( $u ); WC()->mailer(); YayLoyalty\Engine\Emails\Emails::relay_expiring_soon( $u, 120, gmdate( "Y-m-d H:i:s", time() + 10 * DAY_IN_SECONDS ), true );'`. The email must be enabled in WooCommerce → Settings → Emails. Then restore `throttle_per_week` to 3.
Expected:
- WP Mail Logging shows one "Your Points expire soon" email to that customer: "**120 Points** will expire on <date 10 days out>. Earn or redeem before then to keep them.";
- the customer's drawer activity shows the expiring-soon row;
- `wp user meta get <id> _yayloyalty_email_sends` (`EmailThrottle::META_SENDS`) holds 2 entries;
- WooCommerce → Settings → Emails → Points expiring soon → preview shows 120 Points and a date 30 days out.

- [ ] **Step 7: Verify Points earned is still capped**

Action: with the cap at 1 and one recorded send, complete an order for that customer.
Expected: no Points earned email (existing `PointsEarnedEmailTest::test_the_weekly_cap_skips_the_send_at_the_cap` must still pass under `composer test:integration --filter PointsEarnedEmailTest`).

- [ ] **Step 8: Commit**

Stage: `includes/Engine/Emails/LoyaltyEmail.php`, `includes/Engine/Emails/EmailThrottle.php`, `includes/Engine/Emails/Emails.php`, `includes/Engine/Emails/PointsExpiringSoonEmail.php`, both `yayloyalty-points-expiring-soon.php` templates, `includes/Services/CustomerActivityService.php`, `includes/Enums/ActivityEvent.php`
Message: `feat(emails): build the points expiring soon email`

### Task 3: Reminder step for inactivity mode

**Files:**
- Create: `includes/Engine/Expiry/ExpiryReminders.php`
- Modify: `includes/Engine/Expiry/ExpiryRunner.php` (call the step after `expire_due`; update the class docblock to four steps)
- Modify: `includes/Repositories/CustomerRepository.php` (`reminder_due_user_ids()`, `claim_reminder()`; `apply_delta()` drops `reminder_sent_at = NULL` and its comment)
- Modify: `docs/architecture.md` (the emails paragraph: a third sending email, the reminder bypasses the cap; the job paragraph: the reminder step)
- Test: `tests/integration/Engine/Expiry/ExpiryRemindersTest.php`

**Interfaces:**
- Consumes: `ExpiryPolicy::is_applied()` and `reminder_days()` (task 1); `Emails::relay_expiring_soon()` and `PointsExpiringSoonEmail::ID` (task 2); `EmailsHelper::registered_emails()`
- Produces: see the README contract under Job and Repositories.

`ExpiryReminders::run()` gate: it returns false unless `$policy->is_enabled()`, `$policy->is_applied()`, and the `PointsExpiringSoonEmail::ID` entry of `EmailsHelper::registered_emails()` exists and `is_enabled()`. Phase 1 handles the non-batch branch only; `is_batch()` returns false until phase 2. Each user gets its own try/catch → `Log::error( 'ExpiryReminders', … )`, as `ExpiryRunner::expire_due()` does. It also returns false in batch mode until phase 2 task 1. `claim_reminder()` first reads `balance` and `expires_at`, then runs `UPDATE … SET reminder_sent_at = %s, reminder_for = expires_at WHERE user_id = %d AND balance = %d AND expires_at = %s AND <due rule>`, and returns the read values on `1 === $result`, otherwise null.

- [ ] **Step 1: Write the failing test**

The class extends `IntegrationTestCase`, uses `WooCommerceOrdersFixture`, and follows the setup and teardown of `PointsEarnedEmailTest` for the mail capture (`$this->sent`), with `PointsExpiringSoonEmail::ID` in place of `PointsEarnedEmail::ID`. Its settings are pinned to `expiry` `enabled` true, `mode` inactivity, `inactivity_days` 365, `reminder_days` 30. Helpers:
- `customer_due_in( int $days ): int`: `create_customer( 'r@example.test' )`, earn 300, set `OPTION_APPLIED` to `ExpiryPolicy::current()->signature()`, then `set_expires_at( $user_id, Dates::offset( $days * DAY_IN_SECONDS ) )`;
- `set_reminder_for( int $user_id, string $expires_at )`: a direct SQL update.

```php
public function test_inactivity_reminds_once_inside_the_window(): void {
    $user_id = $this->customer_due_in( 10 );

    ( new ExpiryRunner() )->run();
    ( new ExpiryRunner() )->run();

    $row = $this->customer_row( $user_id );

    $this->assertCount( 1, $this->sent );
    $this->assertStringContainsString( '300 Points', $this->sent[0]['message'] );
    $this->assertNotNull( $row['reminder_sent_at'] );
    $this->assertSame( $row['expires_at'], $row['reminder_for'], 'The claim records which date it announced.' );
}

public function test_a_date_outside_the_window_is_not_reminded(): void {
    $this->customer_due_in( 31 );

    ( new ExpiryRunner() )->run();

    $this->assertCount( 0, $this->sent );
}

public function test_a_reminder_for_an_earlier_date_allows_one_more(): void {
    $user_id = $this->customer_due_in( 10 );
    // The last reminder announced a date that activity has since moved.
    $this->set_reminder_for( $user_id, Dates::offset( -355 * DAY_IN_SECONDS ) );

    ( new ExpiryRunner() )->run();

    $this->assertCount( 1, $this->sent );
}

public function test_a_date_already_announced_is_not_announced_again(): void {
    $user_id = $this->customer_due_in( 10 );
    // Announced already, however far ahead: lowering reminder_days must not re-announce it.
    $this->set_reminder_for( $user_id, (string) $this->customer_row( $user_id )['expires_at'] );

    ( new ExpiryRunner() )->run();

    $this->assertCount( 0, $this->sent );
}

public function test_nothing_is_claimed_while_the_email_is_off_or_dates_unapplied(): void {
    $user_id = $this->customer_due_in( 10 );

    add_filter( 'woocommerce_email_enabled_' . PointsExpiringSoonEmail::ID, '__return_false' );
    ( new ExpiryRunner() )->run();
    remove_filter( 'woocommerce_email_enabled_' . PointsExpiringSoonEmail::ID, '__return_false' );
    $this->assertNull( $this->customer_row( $user_id )['reminder_sent_at'] );

    update_option( ExpiryRunner::OPTION_APPLIED, 'stale' );
    ( new ExpiryReminders( new CustomerRepository(), new LotRepository() ) )->run( ExpiryPolicy::current(), Dates::now() );
    $this->assertNull( $this->customer_row( $user_id )['reminder_sent_at'] );
    $this->assertCount( 0, $this->sent );
}
```

- [ ] **Step 2: Run the test to confirm it fails**

Run: `composer test:integration -- --filter ExpiryRemindersTest`
Expected: FAIL. `ExpiryReminders` is not found, and the first test sends 0 emails. Check that PHPUnit reports the test count, because exit 0 alone doesn't prove the suite ran (docs/known-issues.md).

- [ ] **Step 3: Implement the two `CustomerRepository` methods and drop the clear from `apply_delta()`**

- [ ] **Step 4: Implement `ExpiryReminders` and call it from `ExpiryRunner::run()` after `expire_due`**

- [ ] **Step 5: Run the test to confirm it passes**

Run: `composer test:integration -- --filter ExpiryRemindersTest`
Expected: PASS, 5 tests

- [ ] **Step 6: Run the full suites**

Run: `composer test` and `composer test:integration`
Expected: both pass. `ExpiryShortenTest` and `ExpiryEdgeCasesTest`, if present, are unchanged.

- [ ] **Step 7: Verify a kept date isn't reminded twice**

Action: on a dev site, take a customer who was reminded (`reminder_for` set) and whose `expires_at` was kept by a shorten-with-keep. Earn points for them, then run the job.
Expected: `reminder_sent_at` and `reminder_for` are unchanged after the earn, and no second email arrives.

- [ ] **Step 8: Verify the phase exit criterion** (top of this file)

- [ ] **Step 9: Commit**

Stage: `includes/Engine/Expiry/ExpiryReminders.php`, `includes/Engine/Expiry/ExpiryRunner.php`, `includes/Repositories/CustomerRepository.php`, `docs/architecture.md`, `tests/integration/Engine/Expiry/ExpiryRemindersTest.php`
Message: `feat(expiry): remind customers before inactivity expiry`

Then run `/yayplancheck docs/plans/2026-10-15-points-expiring-soon-email`.
