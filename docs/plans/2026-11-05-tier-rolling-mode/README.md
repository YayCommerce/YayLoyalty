# Tier Rolling Mode (Tiers slice 4) Implementation Plan

> **For agentic workers:** execute phases in order. Read this file, then the current phase file. Do not start a phase until the previous phase's exit criterion has been verified and `/yayplancheck` has passed on it.

**Status:** done 2026-11-05
**Goal:** Tiers can qualify on the points earned in a rolling 3–24 month window. Reaching a tier holds it for the window's length, the hold's end moves customers straight to the tier their recent points reach, and staff switch the mode on the Tiers page and see holds on the drawer card.
**Spec:** [docs/specs/2026-11-05-tier-rolling-mode-design.md](../../specs/2026-11-05-tier-rolling-mode-design.md) (accepted 2026-11-05), a child of [the tiers master](../../specs/2026-11-02-tiers-master-design.md).
**Architecture:**
- **The window total is read at query time.** `LedgerRepository::window_total()` dates each clawback by the earn row it reverses, and the clawback ↔ earn match rule is written once and shared with `sum_refunded_for()`. Rejected alternative: a ledger column, which would change `LedgerService` and need a backfill.
- **Rules and writes stay where they are.** The pure `TierDecision::rolling_move()` decides the move and the hold action. `TierEvaluator` stays the only writer and gathers the totals under the row lock. `TierRunner` ends holds daily, and `TierSweep` gains `to_rolling`/`to_lifetime` for mode changes.

**Touches:**
- **Lite (shared):**
  - `includes/Repositories/LedgerRepository.php`: `sum_refunded_for()` is refactored, and its results must stay identical. Its consumers:
    - `Services\LedgerService::refund_earn()`;
    - `Engine\Earning\Listeners\OrderListener`;
    - `Engine\Earning\OrderPointsState`;
    - `Engine\Admin\ReviewPointsNote`;
    - `Engine\Emails\LedgerEntryEmail`, which with `ReviewPointsNote` and `OrderPointsState` passes `$is_locking` false.
  - `includes/Utils/Dates.php`: additive.
  - `includes/Models/Customer.php`, `includes/Repositories/CustomerRepository.php`, `includes/Database/Schema/CustomersTable.php`: additive. `set_tier_state()` accepts two more keys.
  - `includes/Enums/TierChangeReason.php`: additive.
- **Pro:** `includes/Pro/Services/` (`TierDecision`, `TierEvaluator`, `TierSweep`, `TierRunner`), `includes/Pro/Controllers/` (`TiersRestController`, `CustomerTierRestController`).
- **SPA:**
  - `apps/admin/src/lib/schema/tier.schema.ts`;
  - `apps/admin/src/pro/tiers/` (`TierModeCard.tsx` [new], `tiers.api.ts`, `tiers.queries.ts`);
  - `apps/admin/src/pages/tiers/TiersPage.tsx`;
  - `apps/admin/src/pro/customers/CustomerTierCard.tsx`;
  - `apps/admin/src/pages/customers/drawer/customer-drawer.activity.copy.ts`.
- **Docs:** `docs/backlog/2026-10-06-tiers-not-built.md`.

**Tests:** all three accepted by the user on 2026-11-05.
- `tests/unit/Pro/TierDecisionTest.php` [edit] (suite `unit`, `composer test`) guards `rolling_move()`:
  - an upgrade starts a hold;
  - a negative row with no active hold;
  - a negative row with an active hold (the qualifying tier against the window tier, and keep/start/clear);
  - to-target with a renewal and a clear;
  - the floor winning, which clears the hold;
  - `hold_only`.
- `tests/integration/Repositories/LedgerWindowTotalTest.php` [new] (suite `integration`, `composer test:integration`) guards clawback attribution:
  - an old earn's refund counts outside the window;
  - a refund-restore ADJUST follows its earn;
  - a review clawback follows its earn;
  - an unmatched clawback counts on its own date;
  - `staff_adjust_since()`;
  - `sum_refunded_for()` unchanged (two partial refunds of 50).
- `tests/integration/Services/TierEvaluatorTest.php` [edit] (suite `integration`) guards the Rolling column writes:
  - an upgrade's hold dates;
  - a refund in the qualifying window drops the customer;
  - a refund after the hold started keeps them;
  - a staff deduction;
  - a hold-end move and a renewal (no activity row);
  - floor-replaces-hold;
  - the `to_rolling` and `to_lifetime` steps.

## Global constraints

- **PHP 7.4.** Product PHP may not use enums, `match`, `readonly`, promoted constructor properties, `?->`, named arguments, union/`mixed`/`static`/`never` types, `catch` without a variable, `throw` as an expression, a trailing comma after the last declared parameter, `new` in a default value, first-class callables or `$object::class` (hard rule 4, `.claude/rules/php-standards.md`). Only review catches this.
- **Lite names Pro only in the guarded `YayLoyaltyPro::boot()` call** (hard rule 2). Lite-side additions don't name Pro in code: the column, the `Customer` getters, `window_total()`/`staff_adjust_since()`, `hold_ended_user_ids()`, the `Dates` helpers and `TierChangeReason::MODE_CHANGE`. Docblocks may name Pro.
- **SPA:** tier code lives in `apps/admin/src/pro/`, is imported only through `apps/admin/src/pro/index.ts`, and renders only behind `IS_PRO_BUILD` (hard rule 3). `TiersPage.tsx` already guards with `IS_PRO_BUILD`.
- **Single writers:**
  - `TierEvaluator` is the only writer of every tier column, `tier_held_from` and `tier_held_until` included (D20).
  - `LedgerService` and the ledger table are unchanged (hard rule 5).
- **The evaluator runs after the ledger commit,** in its own `Transaction::run()` under `CustomerRepository::ensure_and_lock()`. Lifetime mode keeps slice 2's behaviour exactly, and writes no hold column (except `to_lifetime` clearing them).
- **Dates** are UTC through `Utils\Dates`. Month arithmetic uses `DateTimeImmutable` in `UTC` with PHP's overflow (Aug 31 + 6 months = Mar 3 in a non-leap year).
- **SQL:** through `$wpdb->prepare()`; names through `esc_sql()`; type lists through `Sql::in_placeholders()`. Enum literals in SQL come from class constants, never input. A repository opens no transaction.
- **REST:** callbacks via `exec_read`/`exec_write`, and `permission_callback` via `$this->requires( Capabilities::… )`. Responses are snake_case and parsed with zod in `*.api.ts`.
- **Settings:** `tiers_mode` and `tiers_rolling_months` keep their `SettingsHelper` defaults and schema. `PUT /tiers/settings` writes through `SettingsHelper::update_settings()`, and `POST /settings` keeps dropping them (`TIER_KEYS`).
- **Version:** the plugin version stays `1.0.0`. A dev site gets `tier_held_from` through `$WP option update yayloyalty_db_version 0`, then loading any wp-admin page.
- **SPA conventions:**
  - zod parses responses in `*.api.ts`, and a TypeScript copy of a PHP shape opens with `Mirrors <PHP symbol>, the source of truth.`;
  - UI comes from `components/ui/` and its custom wrappers;
  - failures go through `toastApiError()`;
  - a child component with one caller stays in its parent's file.
- **Strings** use the `yayloyalty` text domain, with a `translators:` comment on every string with a placeholder.
- **Lint only changed files.**
  - PHP: `composer lint -- <files>`. Expected: no error or warning in those files. A whole-repo run fails on `dev/edd` too (docs/known-issues.md).
  - Admin: `pnpm --filter @yayloyalty/admin-page exec eslint <files>`. Expected: no error in those files.
  - Types: `pnpm --filter @yayloyalty/admin-page exec tsc --noEmit`. Expected: exit 0.
- **Shorthands:**
  - `WP` means `pnpm --filter @yayloyalty/e2e exec wp-env run tests-cli wp`. The site is http://localhost:8889 (the `pro` SPA built, or `./scripts/run.sh dev pro` running).
  - `REST <METHOD> <route> [json]` means `$WP eval 'wp_set_current_user(1); $q = new WP_REST_Request("<METHOD>", "/yayloyalty/v1<route>"); $q->set_header("content-type","application/json"); $q->set_body(<json or "">); $r = rest_do_request($q); echo wp_json_encode([$r->get_status(), $r->get_data()]);'`.
  - `RUN` means `$WP eval 'do_action("yayloyalty_tier_run");'`, repeated until `REST GET /tiers/settings` shows `"sweep":null`.
  - `BACKDATE <ledger id> <datetime>` means `$WP db query "UPDATE wp_yayloyalty_ledger SET created_at='<datetime>' WHERE id=<id>"`.
- **Branch:** all work is on `jason/feat/tiers`. Commit only when the user asks. Stage by name, use a Conventional Commits message, and end it with `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`.

## Review focus

- **`sum_refunded_for()` drifting after the refactor.** Expected: partial refunds, a cancel after a partial refund, and a deleted refund claw back exactly what they did before. Owned by Phase 1 Task 2 (integration test, plus the existing `LedgerRefundRedeemTest` and `TierEvaluatorTest` refund cases staying green).
- **A clawback dated by the wrong earn when an order paid several triggers.** Expected: each REFUND_EARN takes the date of the EARN row with the same `campaign_id` (NULL-safe). Owned by Phase 1 Task 2 (integration test, two-campaign order case).
- **Month overflow and hand-edited holds.** Expected: `add_months('2026-08-31 10:00:00', 6)` = `2027-03-03 10:00:00`, `whole_months_between` of that pair = 6, and a hold with `tier_held_from` NULL or ≥ `tier_held_until` uses L = N. Owned by Phase 1 Task 1 (the `$WP eval` check) and Task 4 (integration test, bad-hold case).
- **The SPA breaking during a mode sweep.** Expected: the Tiers page keeps rendering while `sweep.mode` is `to_rolling` or `to_lifetime`, because the zod enum accepts both from Phase 1. Owned by Phase 1 Task 5, Step 4.
- **Lifetime mode changing.** Expected: slice 2's `TierEvaluatorTest` passes untouched, and no hold column is written in Lifetime. Owned by Phase 1 Task 4 (integration test run).
- **A mode change racing a sweep.** Expected: 409 `yayloyalty_tier_sweep_running` while any sweep is pending, and a months-only change still returns 200. Owned by Phase 1 Task 5, Step 5.
- **The drawer progress being stale.** Expected: in Rolling, `GET /customers/{id}/tier` reflects a backdated row with no evaluation. Owned by Phase 2 Task 1.
- **Lite bundle.** Expected: the Lite zip validates, and no Pro name lands in Lite code. Owned by Phase 2 Task 3.

## Phases

| Phase | Delivers (user-visible) | Exit criterion (exact check) | Status |
|---|---|---|---|
| 1 | Rolling mode works end to end through REST: switching mode, holds on upgrade, refund and deduction rules, the daily hold end and renewal, and switching back | `composer test -- --filter TierDecisionTest`, `composer test:integration -- --filter LedgerWindowTotalTest` and `composer test:integration -- --filter TierEvaluatorTest` pass (tests counted in the summary). On wp-env, spec AC 1, 2, 3, 4, 6, 13, 14 and 15 pass by Phase 1's checks | done 2026-11-05 |
| 2 | The Tiers page "Qualification" card, the drawer's hold line and rolling progress, the activity labels, the backlog, the Lite check, and the end-to-end walkthrough | Spec AC 16, 17, 18, 20 and the end-to-end AC 21 pass on wp-env, and `./scripts/run.sh release lite --no-prompt` validates | done 2026-11-05 |

## Interface contract

**PHP, Lite**
- `customers.tier_held_from datetime DEFAULT NULL`, placed after `tier_held_until`. Phase 1 Task 1.
- `Customer::get_tier_held_until(): ?string` and `get_tier_held_from(): ?string`: constructor tail params `?string $tier_held_until = null, ?string $tier_held_from = null`, read-only like the other tier columns. Phase 1 Task 1.
- `CustomerRepository` (Phase 1 Task 1):
  - `TIER_COLUMNS` gains `tier_held_until` and `tier_held_from` (`%s`);
  - `hold_ended_user_ids( string $now, int $limit ): int[]`.
- `Dates` (Phase 1 Task 1):
  - `add_months( string $utc, int $months ): string`;
  - `whole_months_between( string $from_utc, string $to_utc ): int`, which is 0 when `$to_utc` ≤ `$from_utc`.
- `TierChangeReason::MODE_CHANGE = 'mode_change'`, in `all()`. Phase 1 Task 1.
- `LedgerRepository` (Phase 1 Task 2):
  - `window_total( int $user_id, string $from, string $to ): int`, with both bounds inclusive;
  - `staff_adjust_since( int $user_id, string $after ): int`, with `$after` exclusive.

**PHP, Pro**
- `TierDecision` (Phase 1 Task 3):
  - constants `HOLD_KEEP = 'keep'`, `HOLD_START = 'start'`, `HOLD_CLEAR = 'clear'`, and `KIND_UP = 'up'`, `KIND_DOWN = 'down'`, `KIND_TO_TARGET = 'to_target'`, `KIND_HOLD_ONLY = 'hold_only'`;
  - `rolling_move( ?int $current_id, int $current_rank, ?Tier $window_tier, ?Tier $qualifying_tier, ?Tier $floor, bool $hold_active, string $kind ): array{changed: bool, to: Tier|null, is_upgrade: bool, hold: string}`.

  This narrows the spec's trigger kinds: hold end, placement, floor end and deleted-tier moves are all `KIND_TO_TARGET`, and a manual assign is `KIND_UP` with the floor.
- `TierEvaluator` (Phase 1 Task 4):
  - `static mode(): string`, `TierDecision`-independent: `'rolling'` only when `tiers_mode` is `rolling`;
  - `end_hold( int $user_id ): void`;
  - `sweep_one( int $user_id, string $mode )` accepts `TierSweep::MODE_TO_ROLLING` and `MODE_TO_LIFETIME`.
- `TierSweep`: `MODE_TO_ROLLING = 'to_rolling'` and `MODE_TO_LIFETIME = 'to_lifetime'` (constants, Phase 1 Task 4); the merge order and `is_pending(): bool` (Phase 1 Task 5). `progress()['mode']` may be any of the four modes.
- `TierRunner::run()` gains the hold-end step. Phase 1 Task 4.
- REST `PUT /tiers/settings`, in the spec's shape: optional `tiers_enabled`, `tiers_mode` and `tiers_rolling_months`; 400 `error_invalid_arguments`; 409 `yayloyalty_tier_sweep_running` "Wait for the current tier update to finish.". Phase 1 Task 5.
- REST `GET/POST/DELETE /customers/{id}/tier` gains `mode`, `window_months`, `held_from` and `held_until`, and the live `progress_value` in Rolling. Phase 2 Task 1.

**SPA**
- `tierSettingsSchema.sweep.mode`: `z.enum(['placement', 'reevaluate', 'to_rolling', 'to_lifetime'])`. Phase 1 Task 5.
- `tiersRollingMonthsSchema = z.union([z.literal(3), z.literal(6), z.literal(9), z.literal(12), z.literal(18), z.literal(24)])`, and `TIERS_ROLLING_MONTHS = [3, 6, 9, 12, 18, 24] as const`. Phase 2 Task 2.
- `updateTierSettings( input: TierSettingsInput )`, where `TierSettingsInput = Partial<Pick<TierSettings, 'tiers_enabled' | 'tiers_mode' | 'tiers_rolling_months'>>`, and `useUpdateTierSettingsMutation()` takes the same input. Phase 2 Task 2.
- `customerTierSchema` gains `mode: z.enum(['lifetime', 'rolling'])`, `window_months: z.number().int().nullable()`, `held_from: z.string().nullable()` and `held_until: z.string().nullable()`. Phase 2 Task 1.
