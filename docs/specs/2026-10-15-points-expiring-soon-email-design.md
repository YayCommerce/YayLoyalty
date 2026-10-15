# Points Expiring Soon Email Design

**Status:** accepted 2026-10-15
**Related:** design `YayLoyalty Settings.dc.html` (Expiry tab `#expiry-free` "Remind before expiry", Emails tab rows "Points expiring soon"); plan `docs/plans/2026-10-13-points-expiry/` (listed reminders as out of scope).

## Goal

While points expiry is on, each customer gets the "Points expiring soon" email N days (default 30) before their points expire. That's once per expiry date in inactivity mode and once per lot in Pro batch mode, so they can spend the points before losing them.

## Context

- `includes/Engine/Emails/PointsExpiringSoonEmail.php` is registered in `Emails::add_classes()` but has no trigger, no placeholders and no ID const. Its templates (`templates/emails/yayloyalty-points-expiring-soon.php` and `plain/`) show one generic sentence.
- `includes/Engine/Expiry/ExpiryRunner.php`: the daily job `yayloyalty_expiry_run` runs backfill, then `apply_settings`, then `expire_due`, in batches of 100 with an async continuation. `ExpiryPolicy` holds the effective rules, `signature()`, `is_due()` and `OPTION_APPLIED`.
- `customers.reminder_sent_at` (datetime NULL) exists and nothing sets it. `CustomerRepository::apply_delta()` clears it on activity. `customers.expires_at` is the whole balance's date in inactivity mode and the soonest open lot's date in batch mode.
- `lots` (`LotsTable`) holds `expires_at` per lot in batch mode. `LotRepository` has `due_lots`, `due_user_ids`, `earliest_open_expiry` and `restamp_open_expiry`.
- `ActivityEvent::EXPIRING_SOON_EMAIL_SENT` is defined and never written. The drawer already renders it (`customer-drawer.activity-rows.ts`, reading `data.window_days`).
- `LoyaltyEmail::send_to_customer()` applies `EmailThrottle` (`emails.throttle_per_week`, default 3).
- YayMail: `LedgerEntryYayMailEmail` / `LedgerEntryShortcodes` / `templates/yaymail-ledger-entry.php` adapt Points earned and Points expired. They're typed to `LedgerEntryEmail`.
- The Emails tab's "Expiry reminder timing" link (`EmailsSettingsPage.tsx:101`) points to `/settings/general`. It should point to `/settings/expiry`.

## Scope

**In:**
- Setting `expiry.reminder_days`, and a "Remind before expiry" row on the Expiry tab in both builds and both modes.
- `PointsExpiringSoonEmail` sent by the daily job: inactivity mode uses `customers.reminder_sent_at`, batch mode uses a new `lots.reminded_at`.
- The reminder bypasses the weekly cap but still counts toward it.
- An `expiring_soon_email_sent` activity row per accepted send.
- A YayMail adapter for the reminder, done by generalising the ledger-only YayMail base to any `LoyaltyEmail`.
- Fix the Emails tab "Expiry reminder timing" link.
- Integration test `ExpiryRemindersTest`.

**Out:**
- Pro "Second reminder" / `ExpiringFinalReminderEmail` trigger (stays untriggered).
- Pro "Minimum balance to remind" floor.
- The Expiry tab's dated example sentence and "N customers expiring in 90 days" count.
- The Emails tab "Last sent … recipients" line.
- The drawer's `expiry.reminders` object (stays `null`).
- Exemptions.
- Changing the Points expired email.
- A default CTA URL: it's filter-only, like Points earned.

## Acceptance criteria

1. Settings `expiry.reminder_days` defaults to `30`. The server answers 400 unless it's a whole number from 1 to 365 and shorter than the effective window in days: `inactivity_days` in inactivity mode, `batch_months × 28` in batch mode (mode as `ExpiryPolicy` resolves it, so batch only on Pro). The Expiry form shows the same error inline.
2. Expiry tab: below the inactivity card or the Pro batch section, a row reads "Remind before expiry [30] days · sends the 'Points expiring soon' email · Emails", where "Emails" links to `/settings/emails`. The row is disabled while `expiry.enabled` is false. It appears in Lite and Pro, in both modes.
3. **Inactivity:** a customer is reminded when all of these hold:
   - expiry is enabled;
   - `OPTION_APPLIED === signature()`;
   - the email is enabled in WooCommerce;
   - `balance > 0`;
   - `now < expires_at <= now + reminder_days`;
   - `reminder_for IS NULL OR reminder_for <> expires_at` (`customers.reminder_for`, the date the last reminder announced).

   The send claims `reminder_sent_at = now` and `reminder_for = expires_at` first, only while the row still holds the balance and date it read. Points = balance, date = `expires_at`. A second run the same day sends nothing. Activity that moves `expires_at` later lets exactly one more reminder go out when the new date enters the window.
4. **Batch (Pro):** a customer is reminded when they have at least one open lot with `reminded_at IS NULL` and `now < expires_at <= now + reminder_days`. One email covers all such lots: points = the sum of their `remaining`, date = the earliest `expires_at`. The send sets those lots' `reminded_at = now` and the customer's `reminder_sent_at = now`. A lot that enters the window later gets its own reminder. No lot is reminded twice.
5. `LotRepository::restamp_open_expiry()` sets `reminded_at = NULL` on every lot whose `expires_at` it changes.
6. The email sends even when the customer has reached `throttle_per_week`, and the send counts toward the week.
7. Placeholders `{points}`, `{points_label}` (count-based) and `{expiry_date}` (`wc_date_format()`, site time zone, via `wp_date()`). Default subject `[{site_title}]: Your {points_label} expire soon`, heading `Your {points_label} expire soon`. Body (HTML and plain):
   - the greeting;
   - inactivity: "**120 Points** will expire on 14 November 2026. Earn or redeem before then to keep them.";
   - batch, one date: "**120 Points** will expire on 14 November 2026.";
   - batch, several dates: "**120 Points** will expire, starting 14 November 2026.";
   - "That's worth **$1.20** in rewards." when `balance_value( points )` isn't '';
   - a button only when `yayloyalty_points_expiring_soon_cta_url` returns a URL.
8. The WooCommerce preview renders 120 points expiring now + 30 days for the current user.
9. Each accepted send writes one `customer_meta` row `expiring_soon_email_sent` with `{ points, expires_at, window_days }`, `staff_only = false`, actor `system`.
10. With YayMail ≥ 4.4.7 licensed, the email appears in YayMail with shortcodes `customer_name`, `expiring_points`, `expiry_date`, `points_value` and `points_url`. Points earned and Points expired still render as before.
11. The Emails tab's "Expiry reminder timing" link goes to `/settings/expiry`.
12. **End to end.** On a Lite site with inactivity expiry on (365 days) and Points expiring soon enabled:
    1. Set a customer's `customers.expires_at` to now + 10 days by SQL.
    2. Run `wp action-scheduler run --hooks=yayloyalty_expiry_run`.

    WP Mail Logging should show one "Your Points expire soon" email naming their balance and that date. Rerunning sends nothing. On Pro batch, two lots dated now + 5 and now + 20 days get one email for their sum, "starting" the first date. A third lot back-dated into the window on a later run gets its own email.

## Constraints

- Product PHP runs on PHP 7.4. No enums, `match`, `readonly`, promoted constructor properties, `?->`, named arguments, union types, `mixed`/`static`/`never` types, catch without a variable, `throw` expressions, or a trailing comma in declarations.
- Lite names Pro only in the guarded `YayLoyaltyPro::boot()` call. The reminder, its setting, `lots.reminded_at` and the YayMail adapter are all Lite code. Batch behaviour comes only from `ExpiryPolicy::mode()`.
- Only `LedgerService` writes ledger rows and balances. Reminders write only `customers.reminder_sent_at`, `lots.reminded_at` and `customer_meta`.
- Datetimes are stored as UTC `Y-m-d H:i:s` through `includes/Utils/Dates.php`. Display uses the site time zone.
- Settings keys go in `SettingsHelper::get_default_settings()` and `schema()`, then `apps/admin/src/lib/schema/settings.schema.ts` (mirror). `reminder_days` is not part of `ExpiryPolicy::signature()`: changing it restamps nothing.
- DDL follows the `AbstractTableSchema` dbDelta rules. The SQL rules are `$wpdb->prepare()`, `esc_sql()` table names in backticks, and `false === $result` → `QueryException`.
- A WooCommerce email action's arguments are scalars only (deferred emails serialize them before WC 10.8).
- No class extending `WC_Email` or YayMail classes is named at `init` (see memory: WC_Email not autoloadable at init).
- Files under 200 lines where practical. PHP classes are PascalCase, new TS non-component files kebab-case.
- Commits are Conventional Commits with the Co-Authored-By trailer. Branch from `dev/edd` as `paul/feat/expiry-reminder`, or continue on `paul/feat/expiry-points` if it hasn't merged yet.

## Approach

**Chosen:** per-lot `reminded_at` for batch mode, plus `customers.reminder_for` (the date last announced) for inactivity mode, run as a new step of the daily expiry job.

**Architecture:** `ExpiryRunner::run()` gets a fourth step after `expire_due`: `ExpiryReminders::run( $policy, $now )`. It does nothing unless expiry is enabled, the dates are applied (`OPTION_APPLIED === signature()`) and the email is enabled. It picks up to `BATCH_SIZE` due customers, using the inactivity or batch query that `$policy->mode()` selects. For each one it claims with a conditional UPDATE. Only when that UPDATE affects rows does it fire `Emails::POINTS_EXPIRING_SOON_ACTION` with `( user_id, points, expires_at )`. A full batch with progress queues the continuation, as the other steps do. Claim-then-send makes the reminder at-most-once: a transport failure loses that reminder ("dropped, not queued", as the design says) and never doubles it.

**Components:**
- `includes/Engine/Expiry/ExpiryReminders.php` (new): the reminder step. It returns whether a full batch made progress.
- `ExpiryRunner`: calls the step. `$has_more |= …`.
- `ExpiryPolicy`: `reminder_days(): int`, and a static `window_days()` helper for validation.
- `CustomerRepository`:
  - `reminder_due_user_ids( string $now, int $days, int $limit ): int[]`;
  - `claim_reminder( int $user_id, string $now, int $days ): ?array{points:int, expires_at:string}`, a conditional UPDATE then a read of the balance and `expires_at`;
  - `mark_reminded( int $user_id, string $now ): void`, for batch;
  - `apply_delta()` loses the `reminder_sent_at = NULL` line. `reminder_for` replaces it, and clearing would re-remind a kept date that activity didn't move.
- `LotRepository`:
  - `reminder_due_user_ids( string $now, string $horizon, int $limit ): int[]`;
  - `claim_reminder_lots( int $user_id, string $now, string $horizon ): ?array{points:int, expires_at:string, is_single_date:bool}`. It runs `UPDATE … SET reminded_at = %s WHERE user_id AND status='open' AND reminded_at IS NULL AND expires_at > now AND expires_at <= horizon`, and on more than 0 affected rows it reads SUM, MIN and COUNT(DISTINCT DATE) of the rows with `reminded_at = now`;
  - `restamp_open_expiry()` clears `reminded_at` where `expires_at` changes.
- `LotsTable`: column `reminded_at datetime DEFAULT NULL` and `expected_columns()` entry. The existing `idx_status_expires` serves the query.
- `PointsExpiringSoonEmail`:
  - `ID` const and `is_capped = false`;
  - `trigger( int $user_id, int $points, string $expires_at )` on `POINTS_EXPIRING_SOON_ACTION . '_notification'` (3 args);
  - placeholders, `build_values()` / `extra_template_args()`, `prepare_preview()`;
  - an updated description: "Sent before a customer's points expire, as many days ahead as the Expiry tab sets."
- `LoyaltyEmail`:
  - `protected $is_capped = true`;
  - `send_to_customer()` returns `bool` and skips `EmailThrottle::allows()` when not capped, but always `record()`s an accepted send;
  - `get_template_values()` moves up from `LedgerEntryEmail`.
- `Emails`: `POINTS_EXPIRING_SOON_ACTION = 'yayloyalty_points_expiring_soon_email'` in `add_email_actions()`, and `relay_expiring_soon( int $user_id, int $points, string $expires_at )`.
- `CustomerActivityService::record_expiring_soon_email_sent( int $user_id, int $points, string $expires_at, int $window_days )`, called by the email after an accepted send. Its docblock and `ActivityEvent`'s "writes only three" docblock are updated.
- Both `yayloyalty-points-expiring-soon.php` templates: rewritten to the body in AC 7.
- YayMail:
  - rename `LedgerEntryYayMailEmail` → `LoyaltyYayMailEmail`, `LedgerEntryShortcodes` → `LoyaltyEmailShortcodes`, and `yaymail-ledger-entry.php` → `yaymail-loyalty-email.php`;
  - their type checks become `LoyaltyEmail`;
  - `placeholders_to_shortcodes` gains `{expiry_date}`;
  - new `PointsExpiringSoonYayMailEmail` and `PointsExpiringSoonShortcodes`, registered in `YayMailIntegration`.
- SPA:
  - `apps/admin/src/pages/settings/ExpiryReminderRow.tsx` (new), rendered by `ExpirySettingsPage` in both builds;
  - `settings.schema.ts` gains `reminder_days`;
  - `expirySettingsFormSchema` gains a refine for the window cross-check;
  - fix the `EmailsSettingsPage.tsx` link.

**Data and storage:**
- `yayloyalty_settings.expiry.reminder_days`, int, default 30, schema type `reminder_days`.
- `{prefix}yayloyalty_lots.reminded_at` datetime NULL, added by dbDelta on the next `Installer::maybe_upgrade()`.
- `customers.reminder_sent_at`, existing.
- `yayloyalty_customer_meta` rows `expiring_soon_email_sent`.
- Uninstall: nothing new beyond the tables and options already dropped.

**Integration points:**
- Action `yayloyalty_points_expiring_soon_email` (and WC's `_notification` relay), args `( int $user_id, int $points, string $expires_at )`.
- Filter `yayloyalty_points_expiring_soon_cta_url` `( string $url, int $user_id )`, default ''.
- WooCommerce `woocommerce_email_actions`; YayMail `yaymail_register_emails` / `yaymail_register_shortcodes`.
- Shared code changed: `LoyaltyEmail` (all loyalty emails), `CustomerRepository::apply_delta()` (every ledger write), and the YayMail base (both existing adapters).

**UI:** shadcn `NumberInput*` with the "days" unit and the text "sends the 'Points expiring soon' email ·", plus a `Link` to Emails. It's disabled while expiry is off, follows the pattern of `ExpiryInactivityCard`, and sits inside the same form, so Save and the confirm dialogs are unchanged.

**Edge cases and failure modes:**
- **Turning expiry on.** The grace floor gives everyone `expires_at ≥ now + 30`, so with the default 30 days every customer whose date lands on the floor is reminded on the first run after the restamp. That is the design's promise ("a 30-day grace period and a reminder email"). It goes out in batches of 100 with continuations.
- **Shorten with `apply`.** Dates already past expire in that run with no reminder. That's the admin's explicit choice.
- **Dates not yet applied** after a save: no reminders until the restamp, the same gate as `is_due()`.
- **Email disabled:** the step is skipped entirely and nothing is claimed, so enabling the email later reminds anyone still inside their window.
- **Lite with a stored batch mode:** runs as inactivity, using `customers.reminder_sent_at`.
- **Concurrent runs:** the conditional UPDATE lets only one claim succeed (0 affected rows → skip).
- **Customer deleted, or no email address:** `trigger()` returns without a send or an activity row. The claim stays, so there are no retries.
- **Balance spent between claim and a deferred send:** the email still reports the claimed figures (same request, negligible window).
- **Changing `reminder_days`:** a date already announced is never announced again, because the claim compares `reminder_for` with `expires_at` (a code-review fix: comparing `reminder_sent_at` with `expires_at − days` re-announced dates when the timing was lowered). A stored timing at or above the window is clamped to window − 1 at runtime. Batch lots already reminded stay reminded.
- **Restore of a deleted refund** opens a lot with `reminded_at NULL`. It may be reminded again, which is acceptable.

**Decision record:** None. This follows the existing job, email and ledger-ownership decisions.

## Alternatives considered

| Option | Why rejected |
|---|---|
| Window rule on `customers.reminder_sent_at` for both modes | No schema change, but lots inside an already-reminded window are never announced individually. The user chose per-lot precision for batch. |
| Batch cooldown of N days between reminders | It caps frequency, but a lot entering the window right after a send gets a late, short warning. The user chose one reminder per lot. |
| One Action Scheduler action per customer at `expires_at − N` | Every date change and every restamp reschedules thousands of actions. Fragile. |
| Reminder obeys the weekly cap | It contradicts the design ("expiry reminders always win"), and a capped customer would lose the only email that saves points. |
| Separate YayMail base for non-ledger emails | It duplicates the base. Generalising the existing one to `LoyaltyEmail` is DRY. |

## Tests

`tests/integration/Engine/Expiry/ExpiryRemindersTest.php` (integration, accepted by the user):
- inactivity reminds once per window, a second run sends nothing, and activity that moves `expires_at` allows exactly one more;
- batch reminds each lot once, a later lot entering the window gets its own reminder, and one email sums lots with the earliest date;
- `restamp_open_expiry()` clears `reminded_at` on lots whose date it changed;
- disabled email or unapplied dates claims nothing.

## Rollout

- Unreleased, at `YAYLOYALTY_VERSION` 1.0.0. The `lots.reminded_at` column reaches existing dev sites by deactivating and reactivating, and a release reaches it through the version bump in `Installer::maybe_upgrade()`.
- The email stays enabled by default (`LoyaltyEmail` default). Existing stores with expiry off are unaffected.
- `docs/architecture.md` gets updates in two places: the emails paragraph (a third sending email, the reminder bypassing the cap, the YayMail base rename) and the job paragraph (the fourth step).

## Verification (end to end)

1. `composer test` and `composer test:integration` pass, including `ExpiryRemindersTest`. So does `pnpm --filter @yayloyalty/admin-page exec tsc --noEmit`.
2. The AC 12 walkthrough on Lite (`./scripts/run.sh dev lite`) and on Pro, checked in WP Mail Logging. Check `wp db query "SELECT user_id, reminder_sent_at FROM wp_yayloyalty_customers"` and `… reminded_at FROM wp_yayloyalty_lots`.
3. WooCommerce → Settings → Emails → Points expiring soon preview shows 120 Points and a date 30 days out. The YayMail editor shows the new email with sample values. Points earned and Points expired YayMail designs still render.
4. Settings → Expiry: set reminder 400 → inline error. Set reminder ≥ window → inline error, and a direct `POST /settings` answers 400.
5. The customer drawer's activity shows "Expiring soon email sent" after step 2.

## Open questions

None.
