# Phase 2: Expiry reminders

**Plan:** `README.md` in this directory: the Status, constraints and interface contract
**Depends on:** Phase 1 (`ExpiryJob`, `ExpiryRules`, `customers.expires_at`, the `expiry` settings group). Branch `paul/feat/expiry-reminders`, stacked on `paul/feat/expires-point` because Phase 1 isn't merged yet. Rebase onto `dev/edd` once it lands.
**Delivers:** customers get "Points expiring soon" before their points expire (both builds). Pro also sends a "Final reminder" and
skips customers whose expiring points are below the floor.
**Exit criterion:** spec criterion 9.
1. In the integration harness (scratch tables), set expiry on, `reminder_days` 30, `final_reminder_days` 7 and `reminder_min_points` 100.
2. Give two customers 500 and 50 points, each with `expires_at` 20 days ahead, and run `ExpiryJob::run()` twice.

Expected:
- the soon action fires once, for the 500-point customer only (Pro). In Lite, both get it, because there is no floor;
- both get `reminder_sent_at`;
- one `expiring_soon_email_sent` activity row is recorded, with `window_days` 20: the days that were left, which the drawer adds to occurred_at to show the deadline.

Then simulate 15 days passing: move the 500-point customer's `expires_at` to 5 days ahead **and** backdate its `reminder_sent_at` 15 days, then run again. Moving `expires_at` alone would leave the soon stamp inside the 7-day final window, which time never does.
Expected (Pro): the final action fires once.

(Corrected 2026-10-13 after `/yayplancheck`. The first recipe expected `window_days` 30 and moved only `expires_at`. The code follows the spec's one-column rule, `reminder_sent_at < expires_at − d`.)

## Design refinement (2026-10-13)

The README contract had one filter, `yayloyalty_expiry_reminder`, that could also turn a stage into `final`. Two things made that unsafe for paging:
- a customer due only for a window this build doesn't send (Lite and `final_reminder_days`);
- a customer skipped by the floor.

Each would stay in `due_for_reminder()`'s result forever and could starve the page. So:

- `ExpiryRules::reminder_stages(): array<string, int>` returns stage → window days, narrowest first, dropping windows ≤ 0. It is
  `apply_filters( 'yayloyalty_expiry_reminder_stages', [ 'soon' => reminder_days ] )`. Pro adds `'final' => final_reminder_days`.
- `CustomerRepository::due_for_reminder( string $now, int[] $windows, int $limit ): Customer[]` selects `balance > 0 AND expires_at > now`,
  plus, for some window `d`: `expires_at <= now + d DAY AND ( reminder_sent_at IS NULL OR reminder_sent_at < expires_at - d DAY )`.
- The job picks the narrowest due stage. Pro's `yayloyalty_expiry_reminder` (`string $stage, Customer $customer, int $days_left,
  int $expiring_points`) may answer `''` to skip, which is the floor. Every handled customer is stamped with `mark_reminded( $user_id, now )`,
  sent or skipped, so it leaves the result. A skipped customer can still get a narrower window's reminder later.
- The action per stage is `Emails::reminder_action( $stage )`, which gives `yayloyalty_points_expiring_{stage}_email`. `Emails` lists the
  `soon` and `final` names in `add_email_actions()`. The names live on `Emails`, never on an email class, because `WC_Email` isn't loaded at `init`.
- A sent reminder records `ActivityEvent::EXPIRING_SOON_EMAIL_SENT` with `{ window_days }`, the shape the drawer already renders,
  through `CustomerActivityService::record_expiring_soon_email_sent( int $user_id, int $window_days, string $occurred_at )`.

### Task 1: "Points expiring soon" in both builds

**Files:**
- Modify: `includes/Models/Customer.php` (`get_reminder_sent_at()`)
- Modify: `includes/Repositories/CustomerRepository.php` (`due_for_reminder`, `mark_reminded`)
- Modify: `includes/Services/ExpiryRules.php` (`reminder_stages()`, `STAGES_FILTER`, `REMINDER_FILTER`)
- Modify: `includes/Services/CustomerActivityService.php` (`record_expiring_soon_email_sent`)
- Modify: `includes/Engine/Expiry/ExpiryJob.php` (a reminder pass after the expire pass, sharing `PAGE` and the follow-up run)
- Modify: `includes/Engine/Emails/LoyaltyEmail.php` (`trigger_for_user( int $user_id ): void`, protected here, made public with `deliver()` in the tidy commit `8953086`, as the README contract records)
- Modify: `includes/Engine/Emails/Emails.php` (`reminder_action()`, the two names in `add_email_actions()`)
- Modify: `includes/Engine/Emails/PointsExpiringSoonEmail.php` (`trigger( int $user_id )` on the soon action's `_notification`)

- [x] **Step 1: Implement the repository, rules, activity and email pieces above.**
- [x] **Step 2: Add the reminder pass to `ExpiryJob::run()`.** It returns early when no stage is due. `days_left = ceil( ( expires_at − now ) / DAY )`.
  `expiring_points = ExpiryRules::expiring_points( $customer, expires_at )`. The follow-up run queues when either pass filled its page and made progress.
- [x] **Step 3: Verify.** Run a throwaway harness check (scratch tables, not committed) of the exit criterion's Lite half. Then `composer lint` the changed
  files, `composer test` and `composer test:integration`, expecting no new failures. Load `wp-load.php` once to confirm no fatal.
Verified 2026-10-13 with a throwaway harness check (11 assertions):
- Lite stages: the soon reminder fires once each, the stamp and activity row (`window_days` 20) are written, a rerun sends nothing, and earning clears the stamp so the next window reminds;
- with Pro-like filters: the floor skips but still stamps, and final is sent once.

Both suites pass (33 unit, 78 integration). `wp-load.php` + `WC()->mailer()` loads with no fatal and lists all three expiry actions.

- [x] **Step 4: Commit** `feat(expiry): send the points-expiring-soon reminder`

### Task 2: Pro final reminder and reminder floor

**Files:**
- Create: `includes/Pro/Expiry/ReminderStages.php` (`add_final( array $stages ): array` and `apply_floor( string $stage, Customer $customer, int $days_left, int $expiring_points ): string`)
- Modify: `includes/Pro/YayLoyaltyPro.php` (`boot()` hooks both)
- Modify: `includes/Pro/Emails/ExpiringFinalReminderEmail.php` (`trigger( int $user_id )` on `Emails::reminder_action( 'final' ) . '_notification'`)
- Create: `apps/admin/src/pro/settings/ExpiryRemindersSection.tsx` (second reminder days, "Minimum balance to remind" with the design's reasoning line)
- Modify: `apps/admin/src/pro/index.ts`, `apps/admin/src/pages/settings/ExpirySettingsPage.tsx`, `apps/admin/src/pages/settings/expiry-settings.form.ts` (adds `final_reminder_days`, `reminder_min_points`)

- [x] **Step 1: Implement `ReminderStages` and the email trigger, and hook them in `boot()`.**
- [x] **Step 2: Add the Pro reminders section, exported through `pro/index.ts` and rendered behind `IS_PRO_BUILD`.**
- [x] **Step 3: Verify.** Run the exit criterion's Pro half in the harness. Then tsc, eslint and prettier on the changed SPA files, and check that the Lite bundle doesn't
  contain `ExpiryRemindersSection`'s strings.
Verified 2026-10-13:
- A throwaway harness check with Pro's real filters (5 assertions): the floor of 100 skips the 50-point customer and still stamps them; final is sent once; `final_reminder_days` 0 leaves only `soon`.
- tsc, eslint and prettier are clean, and Vitest passes 73/73.
- Lite `main.js` has 0 hits for "Minimum balance to remind"; Pro has 1.

- [x] **Step 4: Commit** `feat(expiry): add Pro's final reminder and reminder floor`
