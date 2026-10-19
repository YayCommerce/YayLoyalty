# Birthday Campaign Implementation Plan

> **For agentic workers:** execute phases in order. Read this README, then the current phase file. Do not start a phase until the previous phase's exit criterion has been verified and `/yayplancheck` has passed on it.

**Status:** done 2026-10-19
**Goal:** Pro merchants run a Birthday campaign that pays each customer a set number of points once per birthday, emails them, and is advertised in "Ways to earn".
**Spec:** none, planned from the request
**Architecture:** A Pro `BirthdayHandler` plugs into the existing engine through `yayloyalty_register_trigger_handlers`, and a Pro Action Scheduler job (`BirthdayRunner`) calls the existing `EarningService::award()` for each customer whose birthday window contains the site's today. Idempotency comes from the ledger's UNIQUE key `earn:{user}:birthday:{year}:birthday` (where `ReferenceType::BIRTHDAY` = the birthday's year), not a new claim column, so no schema change is needed and `LedgerService` gets no sixth constructor. Customers enter month + day in WooCommerce's own registration and Edit-account forms; the dormant `customers.birthday_month/day` columns hold it.
**Touches:** `includes/Models/Customer.php`, `includes/Repositories/CustomerRepository.php`, `includes/Models/Campaign.php` (shared: config validation for every trigger; consumers `CampaignRestController`, `Installer` seeding), `includes/Engine/Points/ContextFactory.php` (shared: `from_user()` consumers `SignupListener`), `includes/Engine/ActDeact.php`, `includes/Engine/Expiry/ExpiryRunner.php`, `includes/Engine/Emails/PointsEarnedEmail.php`, `includes/Engine/Storefront/LauncherEarnRules.php` (shared: consumers `LauncherPayload`, `Placements`, `AccountRewards`, `ProductEarnMessage`, `templates/panel-member.php`, `templates/panel-guest.php`, `templates/account-rewards.php`, `templates/ways-to-earn.php`), new files under `includes/Pro/Birthday/`, `includes/Pro/YayLoyaltyPro.php`, `includes/Pro/Emails/BirthdayPointsEmail.php` + its two templates, SPA `apps/admin/src/pro/campaigns/{add,editor}/`, `apps/admin/src/lib/schema/campaign.schema.ts`.
**Tests:**
- `tests/unit/Pro/BirthdayWindowTest.php` (unit): the date math that picks the ledger key's year and whether today is in the window.
- `tests/integration/Earning/BirthdayAwardTest.php` (integration): the run awards once per birthday year, never twice, never outside the window.
- `tests/integration/Emails/BirthdayPointsEmailTest.php` (integration): a birthday award sends Birthday points, not Points earned.

## Requirements

- **Goal:** A customer with a birthday on file receives the live Birthday campaign's points once per birthday (on the day, or up to `days_before` days early), gets a "Happy birthday" email, and sees birthday in "Ways to earn".
- **Out of scope:** admin viewing/editing a customer's birthday (drawer, REST); checkout field; YayMail adapter for the Birthday email (no Pro email has one; add a backlog item); privacy exporter/eraser (existing `TODO(privacy-eraser)`); birth year; Lite (birthday stays Pro-only).
- **Acceptance criteria:**
  1. Pro "Add a campaign" offers **Birthday**; its editor sets `points` (int ≥ 0, default 100) and `days_before` (int 0–30, default 0). The server rejects `points: "abc"` or `days_before: 31` with a 400.
  2. While a Birthday campaign is live, My Account → Register and My Account → Account details show an optional Birthday (month + day) field. Saving both stores them; clearing both stores null; one without the other, or Feb 30, shows "Please enter a valid birthday." and saves nothing. With no live Birthday campaign, the field is hidden.
  3. The daily run awards each due customer once: ledger row source `birthday`, reference `birthday`/`{year}`, points = campaign `points`, subject to the usual gates (program on, not opted out, role not excluded, daily limit). A second run, or a birthday edited afterwards, adds no row for that birthday year.
  4. A customer is due when the site-timezone today lies within `days_before` days before their next birthday, inclusive. Feb 29 birthdays fall on Feb 28 in non-leap years.
  5. The award sends the **Birthday points** email (points + balance) and not **Points earned**.
  6. "Ways to earn" (launcher, My Account Rewards tab, block) lists "Celebrate your birthday" with the points; a logged-in member with no birthday on file gets a link to Account details.
  7. End to end on the Local site: create a live Birthday campaign (150 points, 0 days before), set user 2's birthday to today in Account details, run `yayloyalty_birthday_run`; user 2's ledger shows one "Birthday bonus" +150 row, the Birthday points email is in the WooCommerce email log, and a second run adds nothing.
- **Decided with the user:**
  - Where customers enter it → My Account details and the registration form.
  - Anti-gaming → one earning per (birthday) year; edits stay free.
  - Ways to earn → yes, through a Pro filter, with a link for members missing a birthday.
  - Tests → the three listed above.

## Global constraints

- Product PHP runs on PHP 7.4: no enums, `match`, `readonly`, promoted constructor properties, `?->`, named arguments or union types.
- Lite names Pro only in the guarded `YayLoyaltyPro::boot()` call; Pro attaches only through filters/actions `boot()` adds. Lite may name the domain (`birthday`), never the edition; new Lite seams are named for the domain.
- SPA Pro code lives in `apps/admin/src/pro/`, imported only through `apps/admin/src/pro/index.ts`, rendered only behind `IS_PRO_BUILD`. Pro copy (`__()`) never sits at module level.
- Only `LedgerService` writes ledger rows and balances; reach it only through `EarningService::build()`.
- Datetimes stored/sent as UTC `Y-m-d H:i:s` via `includes/Utils/Dates.php`; the site time zone is used only to decide the site-calendar day.
- Points are integers; display through `SettingsHelper::points_text()` / `format_number()`.
- A TypeScript copy of a PHP shape opens with `Mirrors <PHP symbol>, the source of truth.`
- Comments: one-line docblocks; a why only where code would look wrong; `translators:` on every placeholder string; `phpcs:ignore` gives its reason after `--`.
- Never edit `vendor/`, `vendor-prefixed/`, `assets/dist/`, `release/`.
- Commits: Conventional Commits, stage files by name, end with `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`. Commit only when the user asks.
- Base-branch state: `composer lint` and the SPA `lint` already fail on `dev/edd`; expected result is no new failures in changed files.

## Review focus

- Birthday window crossing New Year (birthday Jan 2, `days_before` 5, today Dec 29) → awarded under year+1, once. Phase 1 Task 1 (BirthdayWindowTest).
- Feb 29 birthday in a non-leap year → due on Feb 28, not skipped. Phase 1 Task 1.
- Customer moves their birthday later in the same year after being paid → no second award. Phase 1 Task 4 (BirthdayAwardTest).
- Two live Birthday campaigns with different `days_before` → the run selects with the largest, the engine pays the highest-priority campaign whose own window matches. Phase 1 Task 4 (BirthdayAwardTest).
- Opted-out customer or excluded role in the window → skipped every day, and the batch still advances (the cursor, not the skip, moves it). Phase 1 Task 4.
- A batch of exactly 100 due customers → continuation queued with the last user id, no customer paid twice, no infinite loop. Phase 1 Task 4 (manual step).
- Registration with a half-filled birthday → registration fails with the error, no account created. Phase 1 Task 5.
- Disabled Birthday points email → nothing sent, and Points earned still does not send. Phase 2 Task 1.
- Lite build → no birthday field, no runner, no row, and `scripts/validate-build.php` passes. Phase 3 Task 2.

## Phases

| Phase | Delivers (user-visible) | Exit criterion (exact check) | Status |
|---|---|---|---|
| 1 | Merchant creates a Birthday campaign; customers enter a birthday; the daily run pays it | Acceptance 7 minus the email; `composer test -- --filter BirthdayWindowTest` and `composer test:integration -- --filter BirthdayAwardTest` print `OK` | done 2026-10-19 |
| 2 | Birthday points email replaces Points earned for birthday awards | `composer test:integration -- --filter BirthdayPointsEmailTest` prints `OK`; Acceptance 7 email part | done 2026-10-19 |
| 3 | "Ways to earn" shows birthday | Acceptance 6 on the storefront; Lite zip validates | done 2026-10-19 |

## Interface contract

- `YayLoyalty\Pro\Birthday\BirthdayWindow::next( int $month, int $day, \DateTimeImmutable $site_today ): array{year: int, days_until: int}` — pure; P1T1 → P1T3, P1T4.
- `BirthdayWindow::due_days( \DateTimeImmutable $site_today, int $days_before ): array<int, array{month: int, day: int}>` — every (month, day) whose next occurrence is 0..`$days_before` days away, adding (2, 29) when Feb 28 is in range in a non-leap year; P1T1 → P1T4.
- `Customer::get_birthday_month(): ?int`, `Customer::get_birthday_day(): ?int` — P1T2 → P1T4, P1T5, P3T1.
- `CustomerRepository::set_birthday( int $user_id, ?int $month, ?int $day ): void` — calls `ensure_row()` first; P1T2 → P1T5.
- `CustomerRepository::birthdays_on( array $days, int $after_user_id, int $limit ): array<int, array{user_id: int, month: int, day: int}>` — `$days` from `due_days()`, ascending `user_id > $after_user_id`, each row with the birthday it matched so the run need not reload it; P1T2 → P1T4. (Was `birthday_user_ids(): int[]`; changed by the pre-PR tidy.)
- Filter `yayloyalty_campaign_config_schema` ( array $schema ): array — applied in `Campaign::config_schema()`; P1T3. Pro adds `birthday => [ points: int min 0, days_before: int min 0 max 30 ]` and drops `birthday` from `add_config_keys()`.
- `ContextFactory::from_user( int $user_id, string $occurred_at, array $extra = [] ): Context` — P1T3 → P1T4.
- Context extras `birthday_year` (int) and `birthday_days_until` (int) — set by P1T4, read by `BirthdayHandler`.
- `YayLoyalty\Pro\Birthday\BirthdayHandler implements TriggerHandler` — `trigger()` `CampaignTrigger::BIRTHDAY`; `is_eligible()` = `birthday_days_until` ≤ config `days_before`; `compute_points()` = `Breakdown::flat( $campaign->flat_points() )`; `reference_type()` `ReferenceType::BIRTHDAY`; `reference_id()` = `birthday_year`; `source()` `LedgerSource::BIRTHDAY`; P1T3.
- `YayLoyalty\Pro\Birthday\BirthdayRunner` — `RUN_ACTION = 'yayloyalty_birthday_run'`, group `ExpiryRunner::GROUP`, `BATCH_SIZE = 100`; `__construct( ?\DateTimeImmutable $site_today = null )` (null = `new DateTimeImmutable( 'today', wp_timezone() )` at run time; tests pin a day), `register(): void`, `schedule(): void`, `run( $after_user_id = 0 ): void`; P1T4.
- `YayLoyalty\Pro\Birthday\BirthdayFields` — `register(): void`; POST fields `yayloyalty_birthday_month`, `yayloyalty_birthday_day`; `is_offered(): bool` (a live Birthday campaign exists); P1T5 → P3T1.
- `LedgerSource::BIRTHDAY` rows are excluded by `PointsEarnedEmail::accepts()` and accepted by `BirthdayPointsEmail::accepts()`; both listen on `Emails::POINTS_EARNED_ACTION . '_notification'`; P2T1.
- Filter `yayloyalty_launcher_earn_rows` ( array $rows, array $earn ): array — applied at the end of `LauncherEarnRules::member_rows()`; rows gain optional `url` (string, '' = no link); P3T1 → P3T2.
