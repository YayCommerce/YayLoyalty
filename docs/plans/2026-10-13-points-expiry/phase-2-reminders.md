# Phase 2: Expiry reminders

**Plan:** `README.md` in this directory: the Status, constraints and interface contract
**Depends on:** Phase 1 merged into `dev/edd` (`ExpiryJob`, `ExpiryRules`, `customers.expires_at`, the `expiry` settings group). Branch `paul/feat/expiry-reminders` from `dev/edd`.
**Delivers:** customers get "Points expiring soon" before their points expire (both builds). Pro also sends a "Final reminder" and
skips customers whose expiring points are below the floor.
**Exit criterion:** spec criterion 9. On the local Pro site, set Expiry on, `reminder_days` 30, `final_reminder_days` 7 and
`reminder_min_points` 100, and give two customers 500 points and 50 points, each with `expires_at` 20 days ahead. Then run
`wp action-scheduler run --hooks=yayloyalty_expire_points --force` twice.
Expected:
- WooCommerce → Status → Logs (or the mail catcher) shows exactly one "Points expiring soon" email, to the 500-point customer;
- their `reminder_sent_at` is set, and the customer meta activity has one `expiring_soon_email_sent`.

Move that customer's `expires_at` to 5 days ahead and run the job again.
Expected: exactly one "Final reminder" email.

### Task 1: "Points expiring soon" in both builds

**Files:**
- Modify: `includes/Models/Customer.php` (`get_reminder_sent_at()`)
- Modify: `includes/Repositories/CustomerRepository.php` (`due_for_reminder`, `mark_reminded`)
- Modify: `includes/Engine/Expiry/ExpiryJob.php` (a reminder pass after the expire pass, sharing `PAGE` and the async follow-up)
- Modify: `includes/Engine/Emails/LoyaltyEmail.php` (`protected function trigger_for_user( int $user_id ): void`: the locale, recipient and `send_to_customer` steps of `LedgerEntryEmail::trigger()`, for emails about a customer rather than a row)
- Modify: `includes/Engine/Emails/Emails.php` (`POINTS_EXPIRING_SOON_ACTION`, `POINTS_EXPIRING_FINAL_ACTION`, both in `add_email_actions()`)
- Modify: `includes/Engine/Emails/PointsExpiringSoonEmail.php` (`trigger( int $user_id )` on `POINTS_EXPIRING_SOON_ACTION . '_notification'`)

**Interfaces:**
- Consumes: README contract `ExpiryRules::expiring_points`, `ExpiryRules::reminder_days`, `customers.expires_at`.
- Produces: README contract `due_for_reminder`, `mark_reminded`, `Customer::get_reminder_sent_at`, the `yayloyalty_expiry_reminder`
  filter, the two email actions, and `LoyaltyEmail::trigger_for_user`.
- The stage rule (spec, edge cases): stage window `d` is due when `expires_at − now ≤ d` and (`reminder_sent_at` IS NULL or
  `reminder_sent_at < expires_at − d`). Core proposes `'soon'` for `reminder_days`, then applies `yayloyalty_expiry_reminder`. It fires
  the action for the returned stage, then calls `mark_reminded()` and records `ActivityEvent::EXPIRING_SOON_EMAIL_SENT`. `''` sends nothing and stamps nothing.
- `due_for_reminder()` selects `balance > 0 AND expires_at > now AND expires_at <= now + max( reminder_days, final_reminder_days )`.

Steps: expand after Phase 1 lands.

### Task 2: Pro final reminder and reminder floor

**Files:**
- Create: `includes/Pro/Expiry/ReminderStage.php` (`filter( string $stage, Customer $customer, int $days_left, int $expiring_points ): string`)
- Modify: `includes/Pro/YayLoyaltyPro.php` (`boot()`: `add_filter( 'yayloyalty_expiry_reminder', [ ReminderStage::class, 'filter' ], 10, 4 )`)
- Modify: `includes/Pro/Emails/ExpiringFinalReminderEmail.php` (`trigger( int $user_id )` on `Emails::POINTS_EXPIRING_FINAL_ACTION . '_notification'`)
- Create: `apps/admin/src/pro/settings/ExpiryRemindersSection.tsx` (second reminder days, "Minimum balance to remind" with the design's reasoning line)
- Modify: `apps/admin/src/pro/index.ts`, `apps/admin/src/pages/settings/ExpirySettingsPage.tsx`, `apps/admin/src/pages/settings/expiry-settings.form.ts` (adds `final_reminder_days`, `reminder_min_points`)

**Interfaces:**
- Consumes: Task 1's filter and actions.
- Produces: `ReminderStage::filter`:
  - `''` when `reminder_min_points` is set and `$expiring_points` is below it;
  - else `'final'` when `final_reminder_days > 0`, `$days_left <= final_reminder_days` and the stage rule allows it for that window;
  - else `$stage`.
- Produces: `ExpiryRemindersSection`, exported through `pro/index.ts` and rendered behind `IS_PRO_BUILD`.

Steps: expand after Task 1 lands.
