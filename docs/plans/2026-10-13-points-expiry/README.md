# Points Expiry Implementation Plan

> **For agentic workers:** execute phases in order. Read this file, then the current phase file. Do not start a phase until the previous phase's exit criterion has been verified and `/yayplancheck` has passed on it.

**Status:** active
**Goal:** Points expire in both builds. Free uses an inactivity window. Pro adds per-batch expiry, a final reminder and a reminder floor.
**Spec:** [docs/specs/2026-10-13-points-expiry-design.md](../../specs/2026-10-13-points-expiry-design.md)
**Architecture:** Lots move to core. `LedgerService::append()` hands every row to a core `LotWriter` inside the same
transaction, which keeps lots, allocations and `customers.expires_at`. A daily Action Scheduler job expires due customers
through `LedgerService::expire()` and sends reminders. Pro changes only the expiry model and the reminder stage, through two filters.
**Touches:** `includes/Database/` (Installer, schemas), `includes/Services/LedgerService.php` (shared: every ledger writer,
i.e. `RestAPI::controllers()`, `EarningService::build()`, `Reversal::build()` and the new `ExpiryJob`), `includes/Repositories/CustomerRepository.php` (shared:
admin list, drawer, storefront), `includes/Helpers/SettingsHelper.php` (shared: every settings tab), `includes/Engine/Emails/`,
`includes/Engine/Expiry/` (new), `includes/Pro/YayLoyaltyPro.php`, `apps/admin/src/pages/settings/`, `apps/admin/src/pro/settings/`,
`apps/admin/src/lib/schema/settings.schema.ts`, `apps/admin/src/router.tsx`, `apps/admin/src/components/layout/SettingsSideNav.tsx`, docs.
**Tests:**
- `tests/integration/Services/LotWriterTest.php` (integration suite) checks lot creation, FIFO allocation, deficit payoff, the
  balance invariant and the expire row with its lot statuses (spec criteria 2–5, 8). Accepted 2026-10-13. It lives under
  `Services/`, beside `LedgerServiceCampaignLockTest.php`, rather than at the root path the spec names.
- `tests/integration/InstallerLotTablesTest.php` (integration suite) checks that `install()` + `verify()` are clean for both lot tables (spec criterion 13).

## Global constraints

- PHP 7.4 syntax in product code: no enums, `match`, `readonly`, promoted constructor properties, `?->`, named arguments, union types, `mixed`, `static` return, `throw` expressions, trailing commas in declarations.
- Lite names Pro only at the guarded `YayLoyaltyPro::boot()` call in `includes/YayLoyalty.php`. Pro attaches only through filters added in `boot()`.
- SPA Pro code lives in `apps/admin/src/pro/`, is imported only through `apps/admin/src/pro/index.ts`, and renders only behind `IS_PRO_BUILD`.
- Only `LedgerService` writes ledger rows and balances. `LotWriter` is constructed and called only inside `LedgerService`. `LedgerService`'s docblock lists every construction site, `ExpiryJob` included.
- Pro data survives Lite: every `expiry.*` key, Pro ones too, is in `SettingsHelper` defaults and `schema()`. No localized key contains the word `pro`.
- All datetimes are UTC through `Utils\Dates`. Points are integers.
- Month arithmetic clamps to month end, like MySQL `DATE_ADD(…, INTERVAL n MONTH)`: `2026-01-31 + 1 month = 2026-02-28`.
- Nothing slow or external runs under the customer lock. Emails fire after commit.
- SQL: values through `$wpdb->prepare()`, table names through `esc_sql()` in backticks. A failed write throws `QueryException`. Logging only through `Utils\Log`.
- Every hook the plugin fires has a docblock. New hooks attach in a `register()` method called once from `YayLoyalty::plugin_init()`.
- Code files stay under 200 lines.
- `composer lint` and `pnpm --filter @yayloyalty/admin-page lint` fail on the base branch. Lint only the changed files, and expect no new failures in them.

## Review focus

- A customer whose balance is negative earns points: the deficit is paid first, and the lot holds only the rest. (Phase 1 Task 2, test)
- The expiry job runs while the customer earns: the expire row is computed under the lock, so a customer who is no longer due is skipped, and nothing double-expires. (Phase 1 Task 4, test + manual)
- The job runs twice on the same day: the second run writes nothing, because the idempotency key carries the due date. (Phase 1 Task 4, test)
- A customer with points but no earn or redeem activity (admin grants only) still gets an expiry date, measured from `joined_at`. (Phase 1 Task 3, manual)
- A merchant turns expiry off: every `customers.expires_at` is cleared at once, so the points card stops showing a date. (Phase 1 Task 3, manual)
- Under the batch model a month-end lot (31 Jan) expires on 28/29 Feb, and PHP and SQL agree. (Phase 3 Task 1, manual)
- A Lite bundle contains no Pro expiry UI. (Phase 1 Task 5 and Phase 3 Task 2, `release lite`)

## Phases

| Phase | Delivers (user-visible) | Exit criterion (exact check) | Status |
|---|---|---|---|
| 1 | Free inactivity expiry works end to end: the Expiry tab, lots, the daily job, `expire` rows, the "Points expired" email | Spec criterion 14 under the inactivity model; `composer test:integration -- --filter 'LotWriterTest\|InstallerLotTablesTest'` passes | pending |
| 2 | Reminder emails: "Points expiring soon" (both builds), plus Pro's "Final reminder" and minimum-balance floor | Spec criterion 9 via `wp action-scheduler run` | pending |
| 3 | Pro batch model: the model cards, months after earning, the worked example, the one-way switch | Spec criteria 7 and 11, then criterion 14 repeated with `model = batches` | pending |

Branching: Phase 1 lands from `paul/feat/expires-point` (which already carries `64c230d`) as one PR into `dev/edd`. Phases 2 and 3 each
branch from `dev/edd` after the previous PR merges (`paul/feat/expiry-reminders`, `paul/feat/expiry-batches`) and land as their own PRs.

## Interface contract

**Tables (Phase 1 Task 1):** `YayLoyalty\Database\Schema\LotsTable` (slug `lots`) and `LotAllocationsTable` (slug
`lot_allocations`), listed in `Installer::schemas()`. Columns and indexes are unchanged from `64c230d`. Lot `status` is one of `open`, `consumed`, `expired`.

**`YayLoyalty\Utils\Dates` (Phase 1 Task 3):**
- `add_days( string $utc, int $days ): string`
- `add_months( string $utc, int $months ): string`: clamps to month end, same as MySQL.

**`YayLoyalty\Repositories\LotRepository` (Phase 1 Task 2, extended in Phase 3).** A plain class over row arrays, like `CampaignPointsRepository`, with no model. Statuses are `STATUS_OPEN`, `STATUS_CONSUMED` and `STATUS_EXPIRED`.
- `open_lots_fifo( int $user_id ): array` returns `array<int, array{id: int, remaining: int}>`, ordered `expires_at IS NULL, expires_at, id`
- `insert_lot( int $user_id, int $ledger_id, int $points, int $remaining, ?string $expires_at, string $created_at ): int`
- `set_remaining( int $lot_id, int $remaining, string $status ): void`
- `sum_due( int $user_id, string $now ): int`: SUM(remaining) of open lots with `expires_at <= $now` (Phase 3)
- `earliest_open_expiry( int $user_id ): ?string` (Phase 3)
- `recompute_expiry( ?int $months ): void`: sets every open lot's `expires_at` (NULL when `$months` is null) (Phase 3)

**`YayLoyalty\Repositories\LotAllocationRepository` (Phase 1 Task 2):** `insert( int $lot_id, int $ledger_id, int $points, string $created_at ): void`. It's a separate class because a repository touches one table (`AbstractRepository`'s rule).

**`YayLoyalty\Services\LotWriter` (Phase 1 Task 2):**
- `__construct( LotRepository $lots, LotAllocationRepository $allocations )`. Phase 1 Task 3 adds a third parameter, `CustomerRepository $customers`, for the expiry sync.
- `record( LedgerEntry $row, Customer $before ): void`: `$row` is persisted (has id and balance_after), `$before` is the locked customer before the row

**`YayLoyalty\Services\ExpiryRules` (Phase 1 Task 3), static:**
- `MODEL_INACTIVITY = 'inactivity'`, `MODEL_BATCHES = 'batches'`, `MODEL_FILTER = 'yayloyalty_expiry_model'`
- `enabled(): bool`, `inactivity_days(): int`, `reminder_days(): int`
- `model(): string`: `apply_filters( 'yayloyalty_expiry_model', 'inactivity' )`, and anything other than `batches` reads as `inactivity`
- `lot_expires_at( string $created_at ): ?string`: NULL unless enabled and the model is batches. The batch branches of this and `customer_expires_at()` landed in Phase 1 Task 3, because the rule lives in one place. They stay inert until Phase 3 hooks the model filter and passes the earliest lot expiry.
- `customer_expires_at( int $balance, ?string $last_activity_at, string $joined_at, ?string $earliest_lot_expiry ): ?string`
- `expiring_points( Customer $customer, string $at ): int`: what would expire by `$at`. Inactivity returns `max(balance, 0)`, batches returns `( new LotRepository() )->sum_due( user, $at )` (Phase 1 Task 4, batch branch in Phase 3)

**`YayLoyalty\Services\LedgerService` (Phase 1 Task 4):**
- `REASON_NOT_DUE = 'not_due'`
- `expire( int $user_id, string $due_at ): Outcome`: the amount is computed under the lock (`ExpiryRules::expiring_points( $locked, Dates::now() )`). It
  skips with `REASON_NOT_DUE` when the locked `expires_at` ≠ `$due_at`, when it is later than now, or when the amount is 0. The key is
  `IdempotencyKey::compose( LedgerType::EXPIRE, $user_id, ReferenceType::USER, $user_id, gmdate( 'YmdHis', Dates::to_timestamp( $due_at ) ) )`.
  This deliberately differs from the spec's `$points` parameter: the amount is decided under the lock, as the spec's edge cases require.

**`YayLoyalty\Repositories\CustomerRepository`:**
- `set_expires_at( int $user_id, ?string $expires_at ): void` (Phase 1 Task 3)
- `recompute_inactivity_expiry( ?int $days ): void`: one `UPDATE` (Phase 1 Task 3)
- `recompute_expiry_from_lots(): void`: `expires_at` = MIN open lot `expires_at` (Phase 3)
- `due_for_expiry( string $now, int $limit ): array` returns `array<int, array{user_id: int, expires_at: string}>`, with `expires_at <= $now AND balance > 0` (Phase 1 Task 4)
- `due_for_reminder( string $now, int $max_days, int $limit ): Customer[]` (Phase 2)
- `mark_reminded( int $user_id, string $at ): void` (Phase 2)
- `Customer::get_reminder_sent_at(): ?string` (Phase 2)

**Engine (Phase 1 Task 4):** `YayLoyalty\Engine\Expiry\ExpiryJob`, with `HOOK = 'yayloyalty_expire_points'`, `GROUP = 'yayloyalty'`,
`PAGE = 200`, `register(): void` and `run(): void`. `YayLoyalty\Engine\Expiry\ExpiryRecompute::run(): void` (Phase 1 Task 3).

**Settings, the `expiry` group (Phase 1 Task 3, all keys at once):**

| Key | Schema type | Default | Valid range |
|---|---|---|---|
| `enabled` | `bool` | `false` | — |
| `inactivity_days` | `expiry_days` | `365` | 1–3650 |
| `reminder_days` | `reminder_days` | `30` | 0–365 |
| `model` | `expiry_model` | `inactivity` | `inactivity`\|`batches` |
| `batch_months` | `expiry_months` | `12` | 1–120 |
| `final_reminder_days` | `reminder_days` | `7` | 0–365 |
| `reminder_min_points` | `limit` | `null` | null or ≥ 1 |

The zod copy is `settingsSchema.shape.expiry` in `apps/admin/src/lib/schema/settings.schema.ts`.

**Hooks:**
- `yayloyalty_expiry_model` (filter, string): Phase 1 Task 3 applies it, Phase 3 Pro hooks it.
- `yayloyalty_expiry_reminder` (filter: `string $stage, Customer $customer, int $days_left, int $expiring_points`; returns `'soon'`, `'final'` or `''`): Phase 2.
- `Emails::POINTS_EXPIRED_ACTION = 'yayloyalty_points_expired_email'` (arg: ledger row id): Phase 1 Task 4.
- `LoyaltyEmail::trigger_for_user( int $user_id ): void` (protected): the shared send path for emails about a customer rather than a ledger row. Phase 2.
- `Emails::POINTS_EXPIRING_SOON_ACTION = 'yayloyalty_points_expiring_soon_email'` and `Emails::POINTS_EXPIRING_FINAL_ACTION = 'yayloyalty_points_expiring_final_email'` (arg: user id): Phase 2.

**SPA:**
- Route `/settings/expiry` (`ExpirySettingsPage`). The index and `*` redirects go to `/settings/expiry`, and it's the first `SETTINGS_NAV` entry (Phase 1 Task 5).
- `apps/admin/src/pro/index.ts` exports `ExpiryRemindersSection` (Phase 2) and `ExpiryModelSection` (Phase 3).
