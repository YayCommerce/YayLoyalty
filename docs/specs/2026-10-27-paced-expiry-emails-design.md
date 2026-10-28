# Paced Expiry Emails Design

**Status:** accepted 2026-10-27
**Related:** design `docs/specs/2026-10-15-points-expiring-soon-email-design.md` (the reminder this queues); brainstorm 2026-10-27.

## Goal

When many customers' points expire or are about to expire on the same day, the "Points expired" and "Points expiring soon" emails go out at a fixed pace the SMTP host can absorb, and a send the transport refuses is retried instead of lost.

## Context

- `includes/Engine/Expiry/ExpiryRunner.php`: the daily Action Scheduler job `yayloyalty_expiry_run` (group `yayloyalty`) expires up to `BATCH_SIZE` (100) customers per run and calls `Emails::relay_expiry()` for each `expire` row. `ExpiryReminders::run()` claims up to 100 reminders per run and calls `Emails::relay_expiring_soon()` for each. A full batch queues the next run at once (`ExpiryRunner::queue_now()`), so 10,000 due customers become about 100 back-to-back runs that send up to 200 emails each, inline.
- `includes/Engine/Emails/Emails.php`: `relay_expiry()` fires `yayloyalty_points_expired_email` (ledger row id), and `relay_expiring_soon()` fires `yayloyalty_points_expiring_soon_email` (user id, points, expires_at, is_single_date). Both are in `add_email_actions()`, so WooCommerce relays them to `{action}_notification`, which `PointsExpiredEmail::trigger()` and `PointsExpiringSoonEmail::trigger()` listen to.
- `includes/Engine/Emails/LoyaltyEmail.php`: `send_prepared()` (enabled check, customer locale) and `send_to_customer()` (weekly cap `EmailThrottle`, then `WC_Email::send()`) return a bool, which can't tell an intentional skip from a transport failure.
- A reminder is claimed (`customers.reminder_for` / `lots.reminded_at` stamped) before its email fires, so a failed send is never retried today. The "Points expired" email is fire-and-forget after its ledger row.
- `PointsExpiredEmail` is capped by `EmailThrottle` (`$is_capped = true`); `PointsExpiringSoonEmail` is not (`$is_capped = false`).
- `Emails::add_classes()` registers the two expiry emails only while expiry is on (`Emails::is_expiry_on()`).
- `ExpiryRunner::unschedule_group()` removes every pending action in group `yayloyalty` on deactivation.
- The plugin has no `uninstall.php`; its options stay after uninstall.

## Scope

**In:**
- A queue for the two expiry-job emails, "Points expired" and "Points expiring soon": one Action Scheduler action per email, paced at a fixed interval and retried on transport failure.
- A three-way send result (sent / skipped / failed) from the shared send path, so only a transport failure is retried.
- A skip for an "expiring soon" email whose date has already passed when its turn comes.
- Integration test `QueuedEmailsTest` (see Acceptance 9). Per CLAUDE.md § Tests, accepting this doc accepts this test.

**Out:**
- Points earned, Points adjusted (single and bulk) and Birthday points: they keep sending immediately.
- Any merchant setting or UI for the pace. The pace is fixed in code and changes only through a filter.
- Changes to the weekly cap: "Points expired" stays capped and "Points expiring soon" stays uncapped. A send the cap skips is not retried.
- Changes to the expiry job's batch size, continuation timing, ledger writes or reminder claims.
- Combining several emails for one customer into one.
- Re-queuing emails dropped by deactivation.

## Acceptance criteria

1. With expiry on and 100 customers due for expiry and 100 for a reminder, one `ExpiryRunner::run()` sends no email inline. It schedules one `yayloyalty_send_queued_email` action (group `yayloyalty`) per "Points expired" email and per "Points expiring soon" email.
2. The queued action's args are scalars only: `[ 'email' => <email id>, 'args' => <list of scalars>, 'attempt' => 1 ]`. For "Points expired", `args` is `[ <ledger row id> ]`. For "Points expiring soon", it is `[ <user id>, <points>, <expires_at UTC>, <is_single_date> ]`.
3. Pacing: each new email is scheduled at `slot = max( time(), next_slot )`, after which `next_slot = slot + interval`. `next_slot` is stored in the option `yayloyalty_email_queue_next_slot` (int Unix time, autoload `false`). `interval` is `(int) apply_filters( 'yayloyalty_queued_email_interval', 15 )` seconds; a value below 1 counts as 1. Given an empty option and 3 emails queued at time T, they are scheduled at T, T+15 and T+30, and the option holds T+45.
4. The handler loads `WC()->mailer()`, takes the email by its ID from `get_emails()`, and sends through the existing path (enabled check, customer locale, weekly cap, `accepts()`). The result is one of:
   - **sent:** the transport accepted it;
   - **skipped:** the email is not registered (expiry turned off) or disabled, the user no longer exists or has no email address, the weekly cap stopped it, or `accepts()` refused the row;
   - **failed:** `WC_Email::send()` returned false or threw.
5. Retry: a **failed** send is rescheduled with `attempt + 1` at `max( time() + backoff, next_slot )`, and that time also advances `next_slot` by `interval`. The backoffs are 300 s after attempt 1, 1800 s after attempt 2 and 7200 s after attempt 3. A failure at attempt 4 writes `Log::error( 'QueuedEmails', … )` with the email id, user id and attempt count, and schedules nothing. A **skipped** or **sent** result schedules nothing.
6. A "Points expiring soon" email whose `expires_at` is at or before now when its turn comes is **skipped**, and no `expiring_soon_email_sent` activity row is written.
7. The `expiring_soon_email_sent` activity row (`Emails::POINTS_EXPIRING_SOON_SENT_ACTION`) is written once, when a queued "Points expiring soon" email is **sent**, whether on the first attempt or a retry.
8. The `yayloyalty_points_expired_email` and `yayloyalty_points_expiring_soon_email` actions are no longer fired, are removed from `Emails::add_email_actions()`, and the two email classes no longer hook `{action}_notification`. Points earned and Points adjusted are unchanged.
9. `QueuedEmailsTest` (integration, `tests/integration/Emails/`) covers: three emails queued from an empty option land at T, T+15 and T+30 (criterion 3); a transport returning false reschedules attempt 2 at least 300 s later, and attempt 4 failing logs and schedules nothing (criterion 5); a cap-skipped send schedules nothing.
10. End-to-end verification: on a local site with expiry on and the "Points expiring soon" email enabled, seed 10 customers due for a reminder and run `yayloyalty_expiry_run` from WooCommerce → Status → Scheduled Actions. Expected: the run completes, sends no email, and 10 pending `yayloyalty_send_queued_email` actions appear, scheduled 15 s apart. Running them with `pre_wp_mail` returning true sends 10 emails and writes 10 `expiring_soon_email_sent` activity rows. `composer test:integration -- --filter 'QueuedEmails|ExpiryReminders|ExpiryRunner'` passes.

## Constraints

- Product PHP must run on PHP 7.4 (CLAUDE.md hard rule 4, `.claude/rules/php-standards.md`).
- Lite code: both expiry emails and the queue live in `includes/Engine/Emails/`, never in `includes/Pro/`.
- Action args must be scalars: WooCommerce and Action Scheduler serialize them, and before WooCommerce 10.8 a Closure in the args breaks the deferred queue.
- Logging goes only through `includes/Utils/Log.php`.
- Every new hook has a docblock. Hooks are attached in a `register()` method that a composition root calls once.
- Without Action Scheduler (`as_schedule_single_action` missing), the email sends inline as today, so nothing is lost on a site where the job could run anyway.

## Approach

**Chosen:** A. Per-email Action Scheduler queue, paced at a fixed interval, with retry on transport failure.

**Architecture:** The expiry job keeps writing rows and claiming reminders exactly as it does today. Only delivery moves. `Emails::relay_expiry()` and `relay_expiring_soon()` hand their scalars to `QueuedEmails::enqueue()`, which takes the next pacing slot and schedules one action. The action's handler resolves the email from WooCommerce's mailer, calls its trigger, and reads the three-way result: it reschedules a failure with backoff and drops everything else. The queue calls the email directly rather than through the WooCommerce email action, because that relay discards the result and may defer it again.

**Components:**
- `includes/Engine/Emails/QueuedEmails.php` (new): `register()` hooks the handler on `yayloyalty_send_queued_email`; `enqueue( string $email_id, array $args )` takes a slot and schedules attempt 1; `handle( string $email, array $args, int $attempt )` sends, then retries or logs; it owns `HOOK`, `OPTION_NEXT_SLOT`, the `yayloyalty_queued_email_interval` filter and the backoff list.
- `includes/Engine/Emails/LoyaltyEmail.php`: send result constants `SEND_SENT = 'sent'`, `SEND_SKIPPED = 'skipped'`, `SEND_FAILED = 'failed'`. `send_prepared()` and `send_to_customer()` return one of them instead of a bool; a throw from `send()` propagates to the caller.
- `includes/Engine/Emails/LedgerEntryEmail.php`: `trigger()` returns the send result (`SEND_SKIPPED` when the row is missing or `accepts()` refuses it).
- `includes/Engine/Emails/PointsExpiringSoonEmail.php`: `trigger()` returns the send result, skips a past `expires_at` (criterion 6), and stops hooking `{action}_notification`.
- `includes/Engine/Emails/PointsExpiredEmail.php`: stops hooking `{action}_notification`.
- `includes/Engine/Emails/Emails.php`: `relay_expiry()` and `relay_expiring_soon()` call `QueuedEmails::enqueue()`. The two actions are removed from `add_email_actions()`, along with `POINTS_EXPIRED_ACTION` and `POINTS_EXPIRING_SOON_ACTION` once nothing reads them. The constructor calls `( new QueuedEmails() )->register()`.
- `tests/integration/Engine/Expiry/ExpiryRemindersTest.php` and any test that hooks the removed actions: updated to the queue.

**Data and storage:** One new option, `yayloyalty_email_queue_next_slot` (int Unix time, autoload `false`), with no migration: a missing option means `time()`. Queued emails are Action Scheduler actions in group `yayloyalty`, visible and cancellable under WooCommerce → Status → Scheduled Actions. There's no uninstall cleanup, consistent with the plugin's other options.

**Integration points:**
- New action `yayloyalty_send_queued_email` (Action Scheduler hook, group `yayloyalty`; internal, not a public API).
- New filter `yayloyalty_queued_email_interval` (int seconds, default 15; public, documented for merchants whose host needs a slower or faster pace).
- Removed: actions `yayloyalty_points_expired_email` and `yayloyalty_points_expiring_soon_email`, and their `_notification` relays.
- Unchanged: `yayloyalty_points_expiring_soon_email_sent`, YayMail adapters (they read `get_template_values()`, which doesn't change), `EmailThrottle`.

**UI:** None.

**Edge cases and failure modes:**
- Two enqueues racing on `next_slot`: both may take the same slot, so two emails share one 15 s slot. No email is lost.
- Action Scheduler runs about once a minute through WP-Cron, so at 15 s about 4 emails go out together each minute. The hourly rate is unchanged.
- Plugin deactivated with emails pending: `unschedule_group()` removes them, and they are not sent after reactivation.
- Expiry turned off while emails are pending: the expiry emails are no longer registered, so each pending email is **skipped**.
- Email disabled in WooCommerce while emails are pending: **skipped** at send time.
- "Points expired" row clawed back before its turn: **skipped** by `accepts()`.
- SMTP down for longer than the backoffs (about 2.6 hours in total): the email is logged and dropped after attempt 4.
- A queue of 10,000 emails at 15 s takes about 42 hours. A reminder window of at least 1 day (`expiry.reminder_days` ≥ 1) plus the past-date skip keeps a late reminder from claiming points that are already gone.

**Decision record:** None. This changes how two emails are delivered, not an architectural choice.

## Alternatives considered

| Option | Why rejected |
|---|---|
| B. Slow the expiry job (smaller batches, delayed continuation, hourly budget) | Failed sends are still lost, and slowing the job also delays the ledger's `expire` rows. |
| C. Rely on an SMTP plugin's own queue | Stores without such a plugin keep the flood, and a send our code counts as accepted can still be lost downstream. |
| A merchant setting for the pace | The user chose a fixed pace in code; the filter covers stores that need a different one. |
| Queue every loyalty email | The user scoped it to the expiry job; checkout emails should stay immediate. |
| Exempt "Points expired" from the weekly cap | The user chose to keep the cap as today. |

## Rollout

- No setting and no migration. After the update, the next expiry run queues its emails instead of sending them inline.
- Release note: "Expiry emails now go out at most one every 15 seconds and are retried if the mail server refuses them. Developers can change the pace with the `yayloyalty_queued_email_interval` filter. Deactivating the plugin cancels queued emails."
- Update `docs/architecture.md` § emails (the relay description) and § Action Scheduler ("There is one Action Scheduler job" becomes two hooks).

## Open questions

None.
