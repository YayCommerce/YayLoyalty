# Tier Calendar Mode Implementation Plan

> **For agentic workers:** execute phases in order. Read this file, then the current phase file. Do not start a phase until the previous phase's exit criterion has been verified and `/yayplancheck` has passed on it.

**Status:** active
**Goal:** Tiers can qualify on fixed calendar periods (month, quarter, year in the site time zone). A tier reached in a period is kept for the rest of it and all of the next one, and customers are reviewed at each period boundary on the period that just ended.
**Spec:** [docs/specs/2026-11-11-tier-calendar-mode-design.md](../../specs/2026-11-11-tier-calendar-mode-design.md) (accepted 2026-11-11), building on [the Rolling design](../../specs/2026-11-05-tier-rolling-mode-design.md).
**Architecture:**
- **Calendar reuses Rolling's machinery.** It uses the same hold columns, `LedgerRepository::window_total()`, the unchanged `TierDecision::rolling_move()` and the daily hold-end step.
- **A new pure helper owns the dates.** `Pro\Services\TierCalendar` handles every period boundary, hold date and qualifying window. It takes a `\DateTimeZone`, so it is unit-testable without WordPress.
- **`TierEvaluator` stays the only writer** (D20). It gains a `calendar` branch next to Rolling's.
- **Rejected alternative:** a two-period max with a full sweep at every boundary. It would sweep every customer each month, and it still needs stored state for "keep to period end".

**Touches:**
- **Lite (shared):**
  - `includes/Enums/TierMode.php` (additive). Consumers: `SettingsHelper`, `TiersRestController`, `TierEvaluator`, `TierStanding`, `TierProgress`, `TierRunner`.
  - `includes/Helpers/SettingsHelper.php` (a new key in `TIER_KEYS`). Consumers: `SettingsRestController` (`POST /settings` drops `TIER_KEYS`) and `TiersRestController`.
  - `includes/Engine/Storefront/LauncherPayload.php`, `AccountMyPoints.php`, `LauncherCopy.php`, `templates/tier-progress.php`, `templates/account-tier.php`. These carry an additive `period` key, and the templates are a public, theme-overridable contract.
- **Pro:**
  - `includes/Pro/Services/` (`TierCalendar` [new], `TierEvaluator`, `TierSweep`, `TierRunner`, `TierStanding`);
  - `includes/Pro/Controllers/` (`TiersRestController`, `CustomerTierRestController`);
  - `includes/Pro/Storefront/` (`TierProgress`, `AccountTier`).
- **SPA:** `apps/admin/src/lib/schema/tier.schema.ts`, `apps/admin/src/pro/tiers/TierModeCard.tsx`, `apps/admin/src/pro/customers/CustomerTierCard.tsx`.
- **Not touched:**
  - `TierDecision`;
  - `LedgerService`, and any ledger or customer column;
  - `TierChangeReason`.

**Tests:** both accepted by the user on 2026-11-11.
- `tests/unit/Pro/TierCalendarTest.php` [new] (suite `unit`, `composer test`) guards `TierCalendar`:
  - month, quarter and year bounds, including the exact boundary second;
  - DST in `America/New_York` (spec AC 15);
  - hold dates per source;
  - qualifying-window L rounding across a time-zone change;
  - the bad-hold fallback;
  - `pick()` ties going to current.
- `tests/integration/Services/TierEvaluatorTest.php` [edit] (suite `integration`, `composer test:integration`) guards the Calendar column writes:
  - an upgrade's hold dates;
  - a hold-end drop and a silent renewal;
  - a refund inside versus outside the qualifying period;
  - a staff deduction during a hold;
  - the `to_calendar` step (floor-held clears);
  - placement from the previous period;
  - the existing Lifetime and Rolling cases staying green.

## Global constraints

- **PHP 7.4.** Product PHP may not use any of these:
  - enums, `match`, `readonly`, promoted constructor properties, `?->`, named arguments;
  - union, `mixed`, `static` or `never` types;
  - `catch` without a variable, `throw` as an expression;
  - a trailing comma after the last declared parameter, `new` in a default value;
  - first-class callables, `$object::class`.

  (Hard rule 4, `.claude/rules/php-standards.md`.) Only review catches this.
- **Lite names Pro only in the guarded `YayLoyaltyPro::boot()` call** (hard rule 2). The Lite-side changes (`TierMode`, `SettingsHelper`, `LauncherPayload`, `AccountMyPoints`, `LauncherCopy` and the two templates) name no Pro class in code. Docblocks may name Pro.
- **SPA:** tier code lives in `apps/admin/src/pro/`, is imported only through `apps/admin/src/pro/index.ts`, and renders only behind `IS_PRO_BUILD` (hard rule 3).
- **Single writers:**
  - `TierEvaluator` is the only writer of every tier column and both hold columns (D20).
  - `LedgerService` and the ledger table are unchanged (hard rule 5).
  - No customer column is added.
- **The evaluator runs after the ledger commit,** in its own `Transaction::run()` under `CustomerRepository::ensure_and_lock()`. Lifetime and Rolling behaviour stay exactly as they are today.
- **Dates:**
  - Store UTC `Y-m-d H:i:s`.
  - Period boundaries are computed with `DateTimeImmutable` in the site time zone (`wp_timezone()`, passed into `TierCalendar`), from day 1 at 00:00:00 wall-clock, and converted to UTC.
  - A period covers [start, next start − 1 s].
  - `tier_held_until` is stored as the last second of the kept period.
- **SQL:** no new SQL. Calendar reads only through `window_total()` and `staff_adjust_since()`.
- **REST:**
  - callbacks via `exec_read`/`exec_write`;
  - `permission_callback` via `$this->requires( Capabilities::MANAGE_TIERS )` (settings), and the customer tier route as today;
  - responses in snake_case, parsed with zod in `*.api.ts`.
- **Settings:**
  - `tiers_calendar_period` goes in the `SettingsHelper` defaults (`year`), the schema map (type `tier_calendar_period`) and `TIER_KEYS`;
  - `PUT /tiers/settings` writes through `SettingsHelper::update_settings()`;
  - `POST /settings` keeps dropping `TIER_KEYS`.
- **Templates** `tier-progress.php` and `account-tier.php` are a public contract. The `period` key is additive, and no key changes meaning.
- **Strings:**
  - the `yayloyalty` text domain, with a `translators:` comment on every string with a placeholder;
  - one full sentence per period, never a period noun spliced into a sentence.
- **SPA conventions:**
  - a TypeScript copy of a PHP shape opens with `Mirrors <PHP symbol>, the source of truth.`;
  - UI comes from `components/ui/` and its custom wrappers;
  - failures go through `toastApiError()`;
  - a child component with one caller stays in its parent's file.
- **Version:** the plugin version stays `1.0.0`, and there is no migration.
- **Lint only changed files.**
  - **PHP:** `composer lint -- <files>`. Expected: no error or warning in those files. A whole-repo run already fails on `dev` ([docs/known-issues.md](../../known-issues.md)).
  - **Admin:** `pnpm --filter @yayloyalty/admin-page exec eslint <files>` and `pnpm --filter @yayloyalty/admin-page exec prettier --check <files>`. Expected: no error in those files.
  - **Types:** `pnpm --filter @yayloyalty/admin-page exec tsc --noEmit`. Expected: exit 0.
- **Shorthands** (same as the Rolling plan):
  - `WP` means `pnpm --filter @yayloyalty/e2e exec wp-env run tests-cli wp`. The site is http://localhost:8889, with the `pro` SPA built or `./scripts/run.sh dev pro` running.
  - `REST <METHOD> <route> [json]` means `$WP eval 'wp_set_current_user(1); $q = new WP_REST_Request("<METHOD>", "/yayloyalty/v1<route>"); $q->set_header("content-type","application/json"); $q->set_body(<json or "">); $r = rest_do_request($q); echo wp_json_encode([$r->get_status(), $r->get_data()]);'`.
  - `RUN` means `$WP eval 'do_action("yayloyalty_tier_run");'`, repeated until `REST GET /tiers/settings` shows `"sweep":null`.
  - `BACKDATE <ledger id> <datetime>` means `$WP db query "UPDATE wp_yayloyalty_ledger SET created_at='<datetime>' WHERE id=<id>"`.
  - `TZ` means `$WP option update timezone_string Asia/Ho_Chi_Minh`. The spec's AC setup needs it once, before Phase 1's wp-env checks.
  - `TIERS` means setting the thresholds to Bronze 0 / Silver 500 / Gold 1,000 with every bonus 0, through `REST PUT /tiers/<id> {"threshold":…}` for each tier (ids from `REST GET /tiers`).
- **Branch:** all work is on `jason/feat/tiers`. Commit only when the user asks. Stage by name, use a Conventional Commits message, and end it with `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`.

## Review focus

- **Boundary off by one second or one hour.** Expected:
  - a row at exactly local 00:00:00 on 1 Jan belongs to the new year;
  - `tier_held_until` reads "31 Dec" in every display;
  - a DST zone gives `2027-01-01 05:00:00` → `2027-04-01 03:59:59` for a Q1 2027 hold in `America/New_York`.

  Owned by Phase 1 Task 2 (unit test).
- **The daily job inside a period's last second.** Expected: a hold whose `tier_held_until` is `23:59:59` local isn't reviewed until the boundary passes, and the continuation doesn't spin on it. Owned by Phase 1 Task 4, Step 3 (the runner passes `Dates::offset( -1 )`) and Step 5.
- **Ties choosing the wrong period.** Expected: when the current tier and the previous tier rank equal, the hold takes the current period's (longer) dates (spec ACs 5 and 10). Owned by Phase 1 Task 2 (unit `pick()`) and Task 3 (integration).
- **A refund of an older order during a hold.** Expected: a refund of a kept-period order never strips the tier, and a refund of a qualifying-period order can. Owned by Phase 1 Task 3 (integration).
- **The SPA white-screening once the API says `calendar`.** Expected: the Tiers page and the drawer keep rendering when `tiers_mode` is `calendar`, `sweep.mode` is `to_calendar`, or `period` is present. The zod enums widen in Phase 1 Task 5, before any route returns these. Owned by Phase 1 Task 5, Step 4.
- **A period change racing a sweep.** Expected: 409 `yayloyalty_tier_sweep_running` for a mode or period change while a sweep is pending, while a `tiers_rolling_months`-only change still returns 200. Owned by Phase 1 Task 5, Step 5.
- **Lifetime and Rolling regressions.** Expected: every existing `TierEvaluatorTest` and `TierDecisionTest` case passes untouched. Owned by Phase 1 Tasks 3 and 4 (test runs).
- **Theme template overrides.** Expected: an override without `period` still renders in Calendar, with no caption and no notice. Owned by Phase 2 Task 3.
- **Lite bundle.** Expected: the Lite zip validates, and no Pro name lands in Lite code. Owned by Phase 2 Task 4.

## Phases

| Phase | Delivers (user-visible) | Exit criterion (exact check) | Status |
|---|---|---|---|
| 1 | Calendar mode works end to end through REST: settings, upgrades with period holds, the refund and deduction rules, the daily review and renewal, `to_calendar` on switch-in and on period change, and leaving Calendar | `composer test -- --filter TierCalendarTest` and `composer test:integration -- --filter TierEvaluatorTest` pass (all cases, test count shown). On wp-env with `TZ` and `TIERS`, spec AC 1, 2, 3, 8 and 14 pass by Phase 1 Task 5's checks. AC 4–7 and 9–13 are covered by the integration cases | pending |
| 2 | The Qualification card's Calendar option, the drawer caption, the storefront captions, the Lite check, and the end-to-end walkthrough | Spec AC 16, 17, 18, 19, 20 and the end-to-end AC 21 pass on wp-env, and `./scripts/run.sh release lite --no-prompt` validates | pending |

## Interface contract

**PHP, Lite**
- `TierMode` (Phase 1 Task 1):
  - `CALENDAR = 'calendar'`, in `all()`;
  - `CALENDAR_MONTH = 'month'`, `CALENDAR_QUARTER = 'quarter'`, `CALENDAR_YEAR = 'year'`;
  - `CALENDAR_PERIODS = [ 'month', 'quarter', 'year' ]`, `DEFAULT_CALENDAR_PERIOD = 'year'`;
  - `is_calendar_period( $value ): bool` (strict string match);
  - `calendar_period_months( string $period ): int`: 1, 3 or 12, and 12 for an unknown value.
- Setting `tiers_calendar_period` (string, default `'year'`), in `SettingsHelper::TIER_KEYS`. Phase 1 Task 1.
- `LauncherCopy::tier_period_caption( string $period ): string`. Phase 2 Task 3.
- Storefront tier arrays (`LauncherPayload`, `AccountMyPoints`, and both templates) gain `period: ?string`, which is null outside Calendar. Phase 2 Task 3.

**PHP, Pro**
- `TierCalendar` (Phase 1 Task 2), `final`, static, pure:
  - `SOURCE_CURRENT = 'current'`, `SOURCE_PREVIOUS = 'previous'`;
  - `bounds( string $now_utc, string $period, \DateTimeZone $tz ): array{current_start: string, next_start: string, previous_start: string}`;
  - `pick( ?Tier $current, ?Tier $previous ): array{0: ?Tier, 1: string}`;
  - `hold_dates( string $source, array $bounds, string $period, \DateTimeZone $tz ): array{from: string, until: string}`;
  - `qualifying_window( ?string $held_from, string $held_until, string $period, \DateTimeZone $tz ): array{from: string, to: string}`.
- `TierEvaluator` (Phase 1 Tasks 3–4):
  - `static mode(): string` returns `'calendar'` when stored;
  - `static calendar_period(): string`;
  - `end_hold()` acts in Rolling and Calendar;
  - `sweep_one()` accepts `TierSweep::MODE_TO_CALENDAR`.
- `TierSweep::MODE_TO_CALENDAR = 'to_calendar'`, a mode sweep in the merge and in `current()`'s list. Phase 1 Task 4.
- REST `PUT /tiers/settings` accepts `tiers_calendar_period` (`enum` `TierMode::CALENDAR_PERIODS`) and `tiers_mode` `calendar`. `GET` returns `tiers_calendar_period`. Phase 1 Task 5.
- `TierStanding::of()` gains `period: ?string`. In Calendar, `progress` = the current-period total read now and `window_months` = null. Phase 2 Task 1.
- REST `GET/POST/DELETE /customers/{id}/tier` gains `period`. Phase 2 Task 1.
- `TierProgress::line( ?Tier $tier, ?Tier $next, int $progress, ?int $window_months, ?string $period, ?string $held_until ): array`, with `period` inserted before `held_until`. Phase 2 Task 3.

**SPA**
- `tierModeSchema = z.enum(['lifetime', 'rolling', 'calendar'])`. Phase 1 Task 5.
- `tiersCalendarPeriodSchema = z.enum(['month', 'quarter', 'year'])` and `TIERS_CALENDAR_PERIODS = ['month', 'quarter', 'year'] as const`. Phase 1 Task 5.
- `tierSettingsSchema` gains `tiers_calendar_period`, and `sweep.mode` gains `'to_calendar'`. `TierSettingsInput` adds `'tiers_calendar_period'` to its `Pick`. Phase 1 Task 5.
- `customerTierSchema` gains `period: tiersCalendarPeriodSchema.nullable()`. Phase 2 Task 1.
