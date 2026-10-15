# Points Expiring Soon Email Implementation Plan

> **For agentic workers:** execute phases in order. Read this file, then the current phase file. Do not start a phase until the previous phase's exit criterion has been verified and `/yayplancheck` has passed on it.

**Status:** active
**Goal:** The daily expiry job sends the "Points expiring soon" email `expiry.reminder_days` days before points expire. That's once per expiry date in inactivity mode and once per lot in Pro batch mode. The email can be designed in YayMail.
**Spec:** [docs/specs/2026-10-15-points-expiring-soon-email-design.md](../../specs/2026-10-15-points-expiring-soon-email-design.md)
**Architecture:** A fourth step of `ExpiryRunner::run()`, `ExpiryReminders::run()`, claims each due reminder with a conditional UPDATE and fires the email action only when the claim took rows. That makes a reminder at-most-once without a lock. Inactivity mode claims on `customers.reminder_sent_at` with a window rule, and batch mode on a new `lots.reminded_at`. The obvious alternative, one Action Scheduler action per customer at `expires_at − N`, would reschedule thousands of actions on every restamp.
**Touches:**
- `includes/Engine/Emails/LoyaltyEmail.php` (shared: every loyalty email, plus the YayMail adapters in phase 2)
- `includes/Repositories/CustomerRepository.php` (shared: `apply_delta()` runs on every `LedgerService` write)
- `includes/Repositories/LotRepository.php` (shared: `LotService`, `ExpiryRunner`)
- `includes/Helpers/SettingsHelper.php` (shared: every settings reader and both settings controllers)
- `includes/Engine/Expiry/`
- `includes/Engine/Emails/`
- `includes/Services/CustomerActivityService.php`
- `includes/Database/Schema/LotsTable.php`
- `includes/Integrations/YayMail/` (shared base: the Points earned and Points expired adapters)
- `apps/admin/src/lib/schema/settings.schema.ts`
- `apps/admin/src/pages/settings/`
- `docs/architecture.md`

**Tests:** `tests/integration/Engine/Expiry/ExpiryRemindersTest.php` (integration). It guards the "exactly once" rule:
- inactivity reminds once per window, and an older reminder for an earlier date allows one more;
- batch reminds each lot once, and one email sums the due lots with the earliest date;
- `restamp_open_expiry()` clears `reminded_at` on moved lots;
- a disabled email or unapplied dates claim nothing.

## Global constraints

- Product PHP must run on PHP 7.4: no enums, `match`, `readonly`, promoted constructor properties, `?->`, named arguments or union types; also no `mixed`/`static`/`never` types, catch without a variable, `throw` expressions, trailing comma in declarations.
- Lite names Pro only in the guarded `YayLoyaltyPro::boot()` call. Everything in this plan is Lite code. Batch behaviour comes only from `ExpiryPolicy::mode()` / `is_batch()`.
- Only `LedgerService` writes ledger rows and balances. Reminders write only `customers.reminder_sent_at`, `lots.reminded_at` and `yayloyalty_customer_meta`.
- Datetimes are stored as UTC `Y-m-d H:i:s` through `includes/Utils/Dates.php`. They're shown with `Dates::to_site_date()`.
- Settings keys go in both `SettingsHelper::get_default_settings()` and `schema()`, then in `apps/admin/src/lib/schema/settings.schema.ts` (which opens `Mirrors SettingsHelper, the source of truth.`). `reminder_days` is not part of `ExpiryPolicy::signature()`.
- DDL follows the dbDelta rules in `AbstractTableSchema`'s docblock.
- SQL: values through `$wpdb->prepare()`, table names through `esc_sql()` in backticks, and a write checks `false === $result` and throws `QueryException`. Logging only through `Log::error|warning|debug`.
- WooCommerce email action arguments are scalars only, because deferred emails before WC 10.8 serialize them.
- Never name a `WC_Email` subclass or a YayMail class at `init`: `WC_Email` loads later, and the site fatals.
- Files under 200 lines where practical. PHP classes are PascalCase, new TS non-component files kebab-case, React components PascalCase.
- Commits: Conventional Commits `type(scope): summary`, ending with `Co-Authored-By: Claude <model> <noreply@anthropic.com>`. Stage files by name.
- Branch `paul/feat/expiry-reminder`, stacked on `paul/feat/expiry-points`. Its PR goes into `dev/edd` after `paul/feat/expiry-points` merges.
- Checks that fail on the base today: `composer lint` exits 1 on warnings in `CampaignConditionSet.php`, `RewardConditionSet.php` and `CustomerRowFilters.php` (docs/known-issues.md), and `pnpm --filter @yayloyalty/admin-page lint` fails. The expected result for both is no new errors or warnings in the changed files.

## Review focus

- **The first run after expiry is turned on.** Every date lands on the 30-day grace floor, so with `reminder_days` 30 every customer is reminded in that run, 100 per batch with continuations, and no one twice. Owner: phase 1 task 3, test `test_inactivity_reminds_once_inside_the_window` plus the verification step.
- **A kept date that activity didn't move.** `apply_delta()` must no longer clear `reminder_sent_at`, or that customer gets a second reminder for the same date. Owner: phase 1 task 3, verification step.
- **Weekly cap reached.** The reminder still sends and the send counts. Points earned is still capped. Owner: phase 1 task 2, verification step.
- **Email switched off in WooCommerce.** Nothing is claimed, so turning the email on later still reminds customers inside their window. Owner: phase 1 task 3, test `test_nothing_is_claimed_while_the_email_is_off_or_dates_unapplied`.
- **Restamp in the same SQL statement.** `reminded_at` must be compared with the old `expires_at`, so its assignment comes before `expires_at`'s in the `SET` list (MySQL assigns left to right). Owner: phase 2 task 1, test `test_a_restamp_that_moves_a_lot_clears_its_reminder`.
- **The YayMail rename.** Points earned and Points expired designs must still render after `LedgerEntry*` becomes `Loyalty*`. Owner: phase 2 task 2, verification step.

## Phases

| Phase | Delivers (user-visible) | Exit criterion (exact check) | Status |
|---|---|---|---|
| 1 | [Inactivity reminder](phase-1-inactivity-reminder.md): Expiry tab "Remind before expiry", the email sent N days before an inactivity expiry, logged in activity | Spec AC 12, Lite part: one reminder email, none on rerun; `composer test:integration --filter ExpiryRemindersTest` passes | implemented, awaiting yayplancheck |
| 2 | [Batch reminder and YayMail](phase-2-batch-and-yaymail.md): per-lot reminders in Pro batch mode, the email in YayMail | Spec AC 12, Pro part, and the YayMail editor showing the email; whole `ExpiryRemindersTest` passes | pending |

## Interface contract

### Settings

- `yayloyalty_settings.expiry.reminder_days`: int, default `30`, schema type `reminder_days` (whole number 1 to `SettingsHelper::REMINDER_DAYS_MAX = 365`). `SettingsHelper::is_valid()` also refuses a value that isn't below `ExpiryPolicy::window_days()` of the merged next `expiry` group. Phase 1 task 1.
- `ExpiryPolicy::reminder_days(): int` (`max( 1, … )`), `ExpiryPolicy::window_days(): int` (`batch_months() * 28` when `mode()` is batch, else `inactivity_days()`), and `ExpiryPolicy::is_applied(): bool` (`get_option( ExpiryRunner::OPTION_APPLIED ) === signature()`, which `is_due()` now uses). Phase 1 task 1.
- TS: `expiry.reminder_days: z.number().int().min(1).max(365)` in `settingsSchema`. `expirySettingsFormSchema` adds a `superRefine` with the error path `['expiry', 'reminder_days']`. Phase 1 task 1.

### Email

- `Emails::POINTS_EXPIRING_SOON_ACTION = 'yayloyalty_points_expiring_soon_email'`, added in `add_email_actions()`. Phase 1 task 2.
- `Emails::relay_expiring_soon( int $user_id, int $points, string $expires_at, bool $is_single_date ): void`. Phase 1 task 2. The spec lists three arguments. The fourth carries the batch "several dates" wording to a deferred send, which can't recompute it once the lots are claimed.
- `PointsExpiringSoonEmail::ID = 'yayloyalty_points_expiring_soon'` and `trigger( int $user_id, int $points, string $expires_at, bool $is_single_date ): void`, on `POINTS_EXPIRING_SOON_ACTION . '_notification'`, priority 10, 4 args. Phase 1 task 2.
- Its template values are `greeting_name`, `greeting_text`, `points`, `points_label`, `expiry_date`, `expiring_text` (escaped HTML), `keep_text` (inactivity only, else ''), `points_value` (`balance_value( $points )`), `points_value_text` and `cta_url`. Phase 1 task 2; YayMail reads them in phase 2.
- Filter `yayloyalty_points_expiring_soon_cta_url` `( string $url, int $user_id ): string`, default ''. Phase 1 task 2.
- `LoyaltyEmail`: `protected $is_capped = true;`. `send_to_customer( int $user_id ): bool` returns whether the transport accepted the send, and skips `EmailThrottle::allows()` when `! $this->is_capped`. Phase 1 task 2.
- `LoyaltyEmail::get_template_values(): array` (moved up from `LedgerEntryEmail`, returns `extra_template_args()`). Phase 2 task 2.

### Activity

- `CustomerActivityService::record_expiring_soon_email_sent( int $user_id, int $points, string $expires_at, int $window_days ): void` writes `ActivityEvent::EXPIRING_SOON_EMAIL_SENT` with data `{ points, expires_at, window_days }`, `ActorKind::AUTOMATIC`, `staff_only` false, `created_at` `Dates::now()`. Phase 1 task 2. The spec says actor "system", but the codebase's kind for that is `ActorKind::AUTOMATIC`.

### Repositories

- `CustomerRepository::reminder_due_user_ids( string $now, int $days, int $limit ): int[]` and `claim_reminder( int $user_id, string $now, int $days ): ?array` returning `array{points:int, expires_at:string}`. Both apply the window rule:

  ```
  balance > 0
  AND expires_at > now
  AND expires_at <= now + days
  AND ( reminder_for IS NULL OR reminder_for <> expires_at )
  ```

  `claim_reminder()` reads the row first and claims with `AND balance = %d AND expires_at = %s`, stamping `reminder_sent_at = now, reminder_for = expires_at`. Changed after code review: comparing `reminder_sent_at` with `expires_at − days` re-announced a date when `reminder_days` was lowered.

  Phase 1 task 3.
- `CustomerRepository::mark_reminded( int $user_id, string $now ): void`. Phase 2 task 1.
- `LotRepository::reminder_due_user_ids( string $now, string $horizon, int $limit ): int[]` and `claim_reminder_lots( int $user_id, string $now, string $horizon ): ?array` returning `array{points:int, expires_at:string, is_single_date:bool}`. Phase 2 task 1.

### Schema

- `{prefix}yayloyalty_customers.reminder_for datetime DEFAULT NULL`, the `expires_at` the last reminder announced. Phase 1, added after code review.

- `{prefix}yayloyalty_lots.reminded_at datetime DEFAULT NULL`, added to `LotsTable::definition()` after `expires_at` and to `expected_columns()`. Phase 2 task 1.

### Job

- `YayLoyalty\Engine\Expiry\ExpiryReminders`: `__construct( CustomerRepository $customers, LotRepository $lots )` and `run( ExpiryPolicy $policy, string $now ): bool` (returns whether a full batch of `ExpiryRunner::BATCH_SIZE` made progress). `ExpiryRunner::run()` calls it after `expire_due` and sets `$has_more` on true. Phase 1 task 3 (inactivity); phase 2 task 1 adds batch.

### YayMail

- Renames, in phase 2 task 2:
  - `LedgerEntryYayMailEmail` → `LoyaltyYayMailEmail`
  - `LedgerEntryShortcodes` → `LoyaltyEmailShortcodes`
  - `templates/yaymail-ledger-entry.php` → `templates/yaymail-loyalty-email.php`

  The type checks become `LoyaltyEmail`.
- `PointsExpiringSoonYayMailEmail` and `PointsExpiringSoonShortcodes` (shortcodes `customer_name`, `expiring_points`, `points`, `points_label`, `expiry_date`, `points_value`, `points_value_text`, `points_url`). Phase 2 task 3.
