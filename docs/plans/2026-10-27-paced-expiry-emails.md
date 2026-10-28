# Paced Expiry Emails Implementation Plan

> **For agentic workers:** execute tasks in order, one at a time. Steps use `- [ ]` for tracking. Read the spec linked below before starting; the plan argues from it.

**Status:** active
**Goal:** The expiry job's two emails ("Points expired", "Points expiring soon") go out through a paced Action Scheduler queue, one every 15 s, and a send the transport refuses is retried 3 times before it is logged and dropped.
**Spec:** `docs/specs/2026-10-27-paced-expiry-emails-design.md`
**Architecture:** The shared send path first learns to report sent / skipped / failed instead of a bool. Then a new `QueuedEmails` class takes a pacing slot per email, schedules one action, sends through the email's own `trigger()` and reschedules only a failure. Last, the two expiry relays switch from firing WooCommerce email actions to `QueuedEmails::enqueue()`. The job's ledger writes and reminder claims stay as they are.
**Touches:** `includes/Engine/Emails/LoyaltyEmail.php` (shared: every loyalty email, Lite and Pro, sends through it; consumers `LedgerEntryEmail`, `PointsExpiringSoonEmail`), `includes/Engine/Emails/LedgerEntryEmail.php` (shared: `PointsEarnedEmail`, `PointsAdjustedEmail`, `PointsExpiredEmail`, Pro `BirthdayPointsEmail`), `includes/Engine/Emails/PointsExpiringSoonEmail.php`, `includes/Engine/Emails/PointsExpiredEmail.php`, `includes/Engine/Emails/Emails.php`, new `includes/Engine/Emails/QueuedEmails.php`, `tests/integration/IntegrationTestCase.php`, `tests/integration/Engine/Expiry/ExpiryRemindersTest.php`, new `tests/integration/Emails/QueuedEmailsTest.php`, `docs/architecture.md`.
**Tests:** `tests/integration/Emails/QueuedEmailsTest.php`, integration suite: guards the 15 s pacing slots, the retry backoff and the 4-attempt stop, and that a cap-skipped send is not retried (spec criterion 9). `ExpiryRemindersTest` is updated, not extended, so it runs the queue its emails now go through.

## Global constraints

- Product PHP must run on PHP 7.4: no enums, `match`, `readonly`, promoted constructor properties, `?->`, named arguments, union types, `mixed`/`static`/`never` types, `catch` without a variable, `throw` as an expression, or first-class callables (CLAUDE.md hard rule 4, `.claude/rules/php-standards.md`).
- Lite code: everything lives in `includes/Engine/Emails/`, nothing in `includes/Pro/`.
- Action Scheduler args are scalars only.
- Logging only through `YayLoyalty\Utils\Log` with the reporting class as the context.
- Every hook the plugin fires or registers has a docblock; hooks attach in a `register()` that a composition root calls once.
- Without Action Scheduler (`function_exists( 'as_schedule_single_action' )` false), an email sends inline as today.
- Lint only changed files: `composer lint -- <files>` (a whole-repo run fails today, `docs/known-issues.md`).
- Integration baseline on the local site (dev/edd, 2026-10-27): `PointsEarnedEmailTest::test_the_value_line_shows_while_the_discount_reward_is_live` fails because the store currency is ₫ with 0 decimals. Every other expected result below means "no failures beyond that one".
- Branch from `dev/edd` as `<your-name>/feat/paced-expiry-emails`; land through a PR into `dev/edd`.

## Review focus

- A transport that **throws** (SMTP plugin on a dead server) counts as **failed** and is retried, and the customer's locale is still restored: Task 2 Step 1 `test_a_failed_send_is_retried_after_the_backoff` uses a returning-false transport; Task 2 Step 5 checks the throwing case by hand.
- A cap-skipped or disabled send is **never** retried, or one capped customer would loop forever: Task 2 test `test_a_cap_skipped_send_is_not_retried`.
- Points earned / adjusted / birthday behaviour is unchanged by the bool → string return change: Task 1 Step 2.
- `ExpiryRemindersTest` keeps all its assertions after the relays move to the queue, so reminders still go out exactly once per date: Task 3 Step 4.
- The `expiring_soon_email_sent` activity row is written once per accepted send, including a send that succeeds on a retry: Task 3 Step 4 (`test_inactivity_reminds_once_inside_the_window` checks the row count through the queue).

---

### Task 1: Three-way send result

**Files:**
- Modify: `includes/Engine/Emails/LoyaltyEmail.php:99-153` (`send_prepared()`, `send_to_customer()`)
- Modify: `includes/Engine/Emails/LedgerEntryEmail.php:19-33` (`trigger()`)
- Modify: `includes/Engine/Emails/PointsExpiringSoonEmail.php:48-72` (`trigger()`)

**Interfaces:**
- Consumes: nothing.
- Produces:
  - `LoyaltyEmail::SEND_SENT = 'sent'`, `LoyaltyEmail::SEND_SKIPPED = 'skipped'`, `LoyaltyEmail::SEND_FAILED = 'failed'` (public string constants).
  - `protected function send_prepared( int $user_id, $subject, callable $prepare ): string`
  - `protected function send_to_customer( int $user_id ): string`
  - `public function LedgerEntryEmail::trigger( int $entry_id ): string`
  - `public function PointsExpiringSoonEmail::trigger( int $user_id, int $points, string $expires_at, bool $is_single_date ): string`

- [ ] **Step 1: Return a send result instead of a bool**

  In `LoyaltyEmail`, add the three constants with a one-line docblock each.
  - `send_to_customer()` returns `SEND_SKIPPED` when the cap stops it, `SEND_FAILED` when `send()` returns false, and `SEND_SENT` otherwise. A throw from `send()` still propagates, and `EmailThrottle::record()` still runs only on `SEND_SENT`.
  - `send_prepared()` returns `SEND_SKIPPED` for a disabled email, a missing user or an empty recipient, and otherwise the result of `send_to_customer()`. The `finally` that restores the locale stays.
  - `LedgerEntryEmail::trigger()` returns `SEND_SKIPPED` for a missing row or a row `accepts()` refuses, and otherwise the result of `send_prepared()`.
  - `PointsExpiringSoonEmail::trigger()` returns `SEND_SKIPPED` when `strcmp( $expires_at, Dates::now() ) <= 0` (spec criterion 6) or the user is missing, before any send. It fires `Emails::POINTS_EXPIRING_SOON_SENT_ACTION` only on `SEND_SENT`, then returns the result.

  Update each changed docblock's `@return`.

- [ ] **Step 2: Verify the email suites still pass**

  Run: `composer test:integration -- --filter 'PointsEarnedEmailTest|PointsAdjustedEmailTest|BirthdayPointsEmailTest|ExpiryRemindersTest'`
  Expected: only the baseline `test_the_value_line_shows_while_the_discount_reward_is_live` failure.
  Run: `composer lint -- includes/Engine/Emails/LoyaltyEmail.php includes/Engine/Emails/LedgerEntryEmail.php includes/Engine/Emails/PointsExpiringSoonEmail.php`
  Expected: no errors.

- [ ] **Step 3: Commit**

  Stage: `includes/Engine/Emails/LoyaltyEmail.php`, `includes/Engine/Emails/LedgerEntryEmail.php`, `includes/Engine/Emails/PointsExpiringSoonEmail.php`
  Message: `refactor(emails): report sent, skipped or failed from the send path`

### Task 2: `QueuedEmails`, the paced queue with retry

**Files:**
- Create: `includes/Engine/Emails/QueuedEmails.php`
- Modify: `includes/Engine/Emails/Emails.php:36-42` (constructor registers the queue)
- Modify: `tests/integration/IntegrationTestCase.php` (queue helper)
- Test: `tests/integration/Emails/QueuedEmailsTest.php`

**Interfaces:**
- Consumes: Task 1's `SEND_*` constants and `trigger()` return types.
- Produces:
  - `final class QueuedEmails` in namespace `YayLoyalty\Engine\Emails`.
  - `public const HOOK = 'yayloyalty_send_queued_email';`
  - `public const OPTION_NEXT_SLOT = 'yayloyalty_email_queue_next_slot';`
  - `public const INTERVAL_FILTER = 'yayloyalty_queued_email_interval';` (default 15; a value below 1 counts as 1)
  - `public const BACKOFFS = [ 1 => 300, 2 => 1800, 3 => 7200 ];` (seconds to wait after a failed attempt N; attempt 4 has no entry, so it is the last)
  - `public function register(): void`: `add_action( self::HOOK, [ $this, 'handle' ], 10, 3 )`
  - `public static function enqueue( string $email_id, array $args ): void`
  - `public function handle( string $email_id, array $args, int $attempt ): void`
  - `private static function schedule( string $email_id, array $args, int $attempt, int $earliest ): void`: `as_schedule_single_action( self::take_slot( $earliest ), self::HOOK, [ 'email' => $email_id, 'args' => $args, 'attempt' => $attempt ], ExpiryRunner::GROUP )`
  - `private static function take_slot( int $earliest ): int`: `$slot = max( $earliest, (int) get_option( self::OPTION_NEXT_SLOT, 0 ) )`, then `update_option( self::OPTION_NEXT_SLOT, $slot + self::interval(), false )`, return `$slot`
  - `IntegrationTestCase::run_queued_emails(): void` (test helper): processes every pending `QueuedEmails::HOOK` action through `ActionScheduler_QueueRunner::instance()->process_action( $id )`, repeating until none is pending, so a retry is processed too.

- [ ] **Step 1: Write the failing test**

  `QueuedEmailsTest extends IntegrationTestCase`, `use WooCommerceOrdersFixture`. Its setUp and tearDown copy `ExpiryRemindersTest`'s for `PointsExpiringSoonEmail` and also for `PointsExpiredEmail`:
  - enable both emails;
  - pin settings with expiry on in inactivity mode and `emails.throttle_per_week` from a `$throttle` property;
  - add either email to `WC()->mailer()->emails` if it's missing;
  - route `pre_wp_mail` to a capture whose return comes from a `$mail_result` property.

  tearDown also calls `as_unschedule_all_actions( QueuedEmails::HOOK )` and `delete_option( QueuedEmails::OPTION_NEXT_SLOT )`.

  ```php
  public function test_queued_emails_take_slots_15_seconds_apart(): void {
      $before = time();

      foreach ( [ 101, 102, 103 ] as $entry_id ) {
          QueuedEmails::enqueue( PointsExpiredEmail::ID, [ $entry_id ] );
      }

      $slots = array_map(
          static function ( int $entry_id ): int {
              return (int) as_next_scheduled_action( QueuedEmails::HOOK, [ 'email' => PointsExpiredEmail::ID, 'args' => [ $entry_id ], 'attempt' => 1 ], ExpiryRunner::GROUP );
          },
          [ 101, 102, 103 ]
      );

      $this->assertGreaterThanOrEqual( $before, $slots[0] );
      $this->assertSame( 15, $slots[1] - $slots[0] );
      $this->assertSame( 15, $slots[2] - $slots[1] );
      $this->assertSame( $slots[2] + 15, (int) get_option( QueuedEmails::OPTION_NEXT_SLOT ) );
  }

  public function test_a_failed_send_is_retried_after_the_backoff(): void {
      $user_id           = $this->customer_with_email();
      $args              = [ $user_id, 120, Dates::offset( 10 * DAY_IN_SECONDS ), true ];
      $this->mail_result = false;
      $before            = time();

      ( new QueuedEmails() )->handle( PointsExpiringSoonEmail::ID, $args, 1 );

      $retry = as_next_scheduled_action( QueuedEmails::HOOK, [ 'email' => PointsExpiringSoonEmail::ID, 'args' => $args, 'attempt' => 2 ], ExpiryRunner::GROUP );
      $this->assertCount( 1, $this->sent );
      $this->assertGreaterThanOrEqual( $before + 300, (int) $retry );

      as_unschedule_all_actions( QueuedEmails::HOOK );
      ( new QueuedEmails() )->handle( PointsExpiringSoonEmail::ID, $args, 4 );

      $this->assertFalse( as_has_scheduled_action( QueuedEmails::HOOK ), 'Attempt 4 is the last.' );
  }

  public function test_a_cap_skipped_send_is_not_retried(): void {
      $this->throttle = 1;
      $user_id        = $this->customer_with_email();
      $this->ledger_service()->earn( $this->earn_draft( $user_id, 1, 300 ) );
      update_option( ExpiryRunner::OPTION_APPLIED, ExpiryPolicy::current()->signature(), false );
      ( new CustomerRepository() )->set_expires_at( $user_id, Dates::offset( -DAY_IN_SECONDS ) );
      $entry = $this->ledger_service()->expire( $user_id, Dates::now() )->get_entry();
      update_user_meta( $user_id, EmailThrottle::META_SENDS, [ time() ] );

      ( new QueuedEmails() )->handle( PointsExpiredEmail::ID, [ (int) $entry->get_id() ], 1 );

      $this->assertCount( 0, $this->sent );
      $this->assertFalse( as_has_scheduled_action( QueuedEmails::HOOK ) );
  }
  ```

- [ ] **Step 2: Run the test to confirm it fails**

  Run: `composer test:integration -- --filter QueuedEmailsTest`
  Expected: FAIL (error): class `YayLoyalty\Engine\Emails\QueuedEmails` not found.

- [ ] **Step 3: Implement `QueuedEmails` and register it**

  `includes/Engine/Emails/QueuedEmails.php` with the Produces members above, a class docblock summarising the spec's Architecture paragraph, and a docblock on each hook (`HOOK` internal, `INTERVAL_FILTER` public).
  - `enqueue()`: without `as_schedule_single_action`, call `( new self() )->handle( $email_id, $args, 1 )` and return. Otherwise `schedule( $email_id, $args, 1, time() )`.
  - `handle()`:
    1. Take `WC()->mailer()->get_emails()[ $email_id ]`. If it is not a `LoyaltyEmail`, return (skipped).
    2. Call `$email->trigger( ...$args )` inside `try`; a caught `\Throwable` counts as `SEND_FAILED`.
    3. On `SEND_FAILED`: when `isset( self::BACKOFFS[ $attempt ] )` and Action Scheduler exists, call `schedule( $email_id, $args, $attempt + 1, time() + self::BACKOFFS[ $attempt ] )`. Otherwise call `Log::error( 'QueuedEmails', sprintf( '%s %s failed after %d attempts; dropped', $email_id, wp_json_encode( $args ), $attempt ) )`.
  - `interval()`: `max( 1, (int) apply_filters( self::INTERVAL_FILTER, 15 ) )`, as a private static.

  In `Emails::__construct()`, add `( new QueuedEmails() )->register();`.

- [ ] **Step 4: Add the test helper `run_queued_emails()` to `IntegrationTestCase`**

  As in the Interfaces block. It's used by Task 3.

- [ ] **Step 5: Run the test to confirm it passes, and check a throwing transport by hand**

  Run: `composer test:integration -- --filter QueuedEmailsTest`
  Expected: PASS (3 tests).
  Check: temporarily add `add_filter( 'pre_wp_mail', function () { throw new \RuntimeException( 'SMTP down' ); }, 1 );` before `handle()` in a local copy of `test_a_failed_send_is_retried_after_the_backoff`, run it, then revert.
  Expected: the attempt 2 action is scheduled and no exception escapes `handle()`.
  Run: `composer lint -- includes/Engine/Emails/QueuedEmails.php includes/Engine/Emails/Emails.php tests/integration/Emails/QueuedEmailsTest.php tests/integration/IntegrationTestCase.php`
  Expected: no errors.

- [ ] **Step 6: Commit**

  Stage: `includes/Engine/Emails/QueuedEmails.php`, `includes/Engine/Emails/Emails.php`, `tests/integration/Emails/QueuedEmailsTest.php`, `tests/integration/IntegrationTestCase.php`
  Message: `feat(emails): paced queue with retry for loyalty emails`

### Task 3: Route the expiry emails through the queue

**Files:**
- Modify: `includes/Engine/Emails/Emails.php:25-29, 44-56, 92-119` (constants, `add_email_actions()`, `relay_expiry()`, `relay_expiring_soon()`)
- Modify: `includes/Engine/Emails/PointsExpiredEmail.php:28` (drop the `_notification` hook)
- Modify: `includes/Engine/Emails/PointsExpiringSoonEmail.php:35` (drop the `_notification` hook)
- Modify: `tests/integration/Engine/Expiry/ExpiryRemindersTest.php`
- Modify: `docs/architecture.md:71` (email relays) and `:120` (Action Scheduler)

**Interfaces:**
- Consumes: `QueuedEmails::enqueue()`, `QueuedEmails::HOOK`, `QueuedEmails::OPTION_NEXT_SLOT` (Task 2); `IntegrationTestCase::run_queued_emails()` (Task 2).
- Produces: `Emails::relay_expiry( LedgerEntry $entry ): void` and `Emails::relay_expiring_soon( int $user_id, int $points, string $expires_at, bool $is_single_date ): void`, with signatures unchanged, now queueing. The constants `POINTS_EXPIRED_ACTION` and `POINTS_EXPIRING_SOON_ACTION` are removed.

- [ ] **Step 1: Queue from the relays**

  - `relay_expiry()` calls `QueuedEmails::enqueue( PointsExpiredEmail::ID, [ (int) $entry->get_id() ] )`.
  - `relay_expiring_soon()` calls `QueuedEmails::enqueue( PointsExpiringSoonEmail::ID, [ $user_id, $points, $expires_at, $is_single_date ] )`.
  - Rewrite both docblocks to say the email is queued and paced (no `@param` hook docs: they no longer fire actions).
  - Remove both constants, their two lines in `add_email_actions()`, and the `add_action( … '_notification' … )` line in each email's constructor. Update the class docblocks of `PointsExpiredEmail` and `PointsExpiringSoonEmail` to say the queue sends them.

- [ ] **Step 2: Confirm nothing still names the removed actions**

  Run: `git grep -n "POINTS_EXPIRED_ACTION\|POINTS_EXPIRING_SOON_ACTION\|yayloyalty_points_expired_email\b\|yayloyalty_points_expiring_soon_email\b" -- includes tests`
  Expected: only `ExpiryRemindersTest.php:63` (fixed in Step 3).

- [ ] **Step 3: Run `ExpiryRemindersTest` through the queue**

  - Replace each `( new ExpiryRunner() )->run();` with a private `run_expiry()` that calls `( new ExpiryRunner() )->run()` and then `$this->run_queued_emails()`.
  - In tearDown, drop the `remove_action( Emails::POINTS_EXPIRING_SOON_ACTION … )` line and add `as_unschedule_all_actions( QueuedEmails::HOOK )` and `delete_option( QueuedEmails::OPTION_NEXT_SLOT )`.
  - Remove the now-unused `use` of `Emails` if nothing else needs it.
  - Leave every assertion unchanged.

- [ ] **Step 4: Verify**

  Run: `composer test:integration -- --filter 'ExpiryRemindersTest|ExpiryShortenTest|QueuedEmailsTest|PointsEarnedEmailTest|PointsAdjustedEmailTest|BirthdayPointsEmailTest'`
  Expected: only the baseline value-line failure.
  Run: `composer lint -- includes/Engine/Emails/Emails.php includes/Engine/Emails/PointsExpiredEmail.php includes/Engine/Emails/PointsExpiringSoonEmail.php tests/integration/Engine/Expiry/ExpiryRemindersTest.php`
  Expected: no errors.

- [ ] **Step 5: Update `docs/architecture.md`**

  - Line 71: the expiry job's two emails now go through `QueuedEmails` (one `yayloyalty_send_queued_email` action each, paced by `yayloyalty_queued_email_interval`, default 15 s; a transport failure is retried after 300 s, 1800 s and 7200 s, then logged), and they no longer fire WooCommerce email actions.
  - Line 120: "There is one Action Scheduler job" now also names the queued email action in group `yayloyalty`, and that deactivation's `unschedule_group()` cancels pending emails.

- [ ] **Step 6: End-to-end verification (spec criterion 10)**

  On the local site, with expiry on and the "Points expiring soon" email enabled in WooCommerce → Settings → Emails:
  1. Seed 10 customers due for a reminder. Either run `composer test:integration -- --filter test_inactivity_reminds_once_inside_the_window` with `run_queued_emails()` commented out in a local copy, or give 10 customers a balance and an `expires_at` within 30 days.
  2. Run `yayloyalty_expiry_run` from WooCommerce → Status → Scheduled Actions (Pending, "Run").

  Expected:
  - the run completes;
  - Scheduled Actions shows 10 pending `yayloyalty_send_queued_email` actions whose scheduled times are 15 s apart;
  - running them (with a mail catcher such as Valet's Mailpit, or `pre_wp_mail` returning true) delivers 10 emails;
  - each customer's activity tab shows one "Expiry reminder sent" row.

- [ ] **Step 7: Commit**

  Stage: `includes/Engine/Emails/Emails.php`, `includes/Engine/Emails/PointsExpiredEmail.php`, `includes/Engine/Emails/PointsExpiringSoonEmail.php`, `tests/integration/Engine/Expiry/ExpiryRemindersTest.php`, `docs/architecture.md`
  Message: `feat(emails): pace and retry the expiry job's emails`
