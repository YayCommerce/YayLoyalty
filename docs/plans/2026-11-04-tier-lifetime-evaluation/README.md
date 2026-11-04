# Tier Lifetime Evaluation (Tiers slice 2) Implementation Plan

> **For agentic workers:** execute phases in order. Read this file, then the current phase file. Do not start a phase until the previous phase's exit criterion has been verified and `/yayplancheck` has passed on it.

**Status:** active
**Goal:** Customers are placed into tiers and move between them in Lifetime mode, earn a once-per-tier entry bonus, can be given a manual tier floor from the drawer, and can be shown, sorted and filtered by tier in the Customers list.
**Spec:** [docs/specs/2026-11-04-tier-lifetime-evaluation-design.md](../../specs/2026-11-04-tier-lifetime-evaluation-design.md) (accepted 2026-11-04), a child of [the tiers master](../../specs/2026-11-02-tiers-master-design.md).
**Architecture:**
- A Pro `TierEvaluator` is the only writer of the tier columns. It runs after the ledger commit, on `yayloyalty_ledger_recorded`. The move rules sit in a pure `TierDecision` class so they can be unit-tested without WordPress.
- Placement and re-evaluation run as cursor sweeps (option `yayloyalty_tier_sweep`), drained 100 customers at a time by a Pro daily `yayloyalty_tier_run`, as `BirthdayRunner` does.
- Routes that change the catalogue or the switch only request a sweep. They never write customer columns, so the evaluator stays the only writer (D20).

**Touches:**
- **Lite (shared):**
  - `includes/Enums/` (`LedgerSource`, `ReferenceType`, `TierChangeReason` [new]): consumers are every ledger writer and reader; the changes are additive constants.
  - `includes/Engine/Earning/IdempotencyKey.php`: consumers are every keyed ledger write; adds one accepted earn qualifier.
  - `includes/Services/CustomerActivityService.php`: consumer is `CustomerActivityListener`; adds one method.
  - `includes/Services/LedgerService.php`: docblock only.
  - `includes/Models/Customer.php`: consumers are every customer reader; adds read-only getters.
  - `includes/Repositories/CustomerRepository.php`: consumers are the list, export, bulk adjust preview, `LedgerService` and `ExpiryRunner`; adds methods and an off-by-default criteria key.
  - `includes/Repositories/TierRepository.php`.
  - `includes/Controllers/` (`CustomerRowFilters`, `CustomerRowSort`, `CustomerListFormatter`, `CustomerAdjustPreviewRestController`, `LedgerRestController`, `Schemas/ActivityQueryArgs`, `LedgerItemTierNames` [new]).
  - `includes/Engine/Storefront/ShopperActivityRow.php`.
- **Pro:** `includes/Pro/` (`Services/Tier*` [new], `Controllers/TiersRestController`, `Controllers/CustomerTierRestController` [new], `Controllers/StoreLedgerRestController`, `Helpers/CustomerSegmentsHelper`, `YayLoyaltyPro`).
- **SPA:** `apps/admin/src/` (`pro/tiers`, `pro/customers`, `pages/tiers`, `pages/customers`, `lib/schema`, `lib/api`, `lib/helpers/ledger.helper.ts`).
- **Docs:** `docs/decisions/` and `docs/backlog/`.

**Tests:** both accepted by the user on 2026-11-04.
- `tests/unit/Pro/TierDecisionTest.php` (suite `unit`, `composer test`) guards the pure move rule: what counts as the target, up-only versus to-target moves, the floor, a deleted current tier, placement, and when a bonus is due.
- `tests/integration/Services/TierEvaluatorTest.php` (suite `integration`, `composer test:integration`) guards the evaluator's ledger writes:
  - one `tier_bonus` EARN row per customer per tier, with key `earn:{u}:tier:{t}:tier_bonus`;
  - no bonus on placement;
  - no upgrade cascade from the bonus row;
  - no second bonus on re-reaching a tier;
  - a refund downgrade.

## Global constraints

- **PHP 7.4.** Product PHP may not use enums, `match`, `readonly`, promoted constructor properties, `?->`, named arguments, union/`mixed`/`static`/`never` types, `catch` without a variable, `throw` as an expression, a trailing comma after the last declared parameter, first-class callables or `$object::class` (hard rule 4, `.claude/rules/php-standards.md`). Only review catches this.
- **Lite names Pro only in the guarded `YayLoyaltyPro::boot()` call** in `includes/YayLoyalty.php` (hard rule 2). Pro attaches through `boot()`.
  - Pro: `TierDecision`, `TierEvaluator`, `TierSweep`, `TierRunner`, `TierLedgerListener` and `CustomerTierRestController`.
  - Lite additions don't name Pro in code. Docblocks may, because the Lite validator strips comments.
- **SPA tier code** lives in `apps/admin/src/pro/`, is imported only through `apps/admin/src/pro/index.ts`, and renders only behind `IS_PRO_BUILD` (hard rule 3). `<ProGate>` alone is not enough. Lite files gain only slots or `IS_PRO_BUILD &&` branches, as `CustomersFilterBar` does.
- **Ledger:** only `LedgerService` writes ledger rows and balances (hard rule 5). The bonus goes through `LedgerService::earn()`. `TierEvaluator::build()` is the sixth construction site, named in `LedgerService`'s docblock.
- **Tier columns:** `TierEvaluator` is the only writer of `tier_id`, `tier_evaluated_at`, `tier_progress_value`, `tier_achieved_at`, `tier_manual_id` and `tier_manual_until`, through `CustomerRepository::set_tier_state()`. `Models\Customer` maps them read-only: getters and `from_row()`, never `to_row()` or `to_array()`, so no other save can overwrite them.
- **No tier work inside `LedgerService`'s lock.** The evaluator runs after commit and takes the customer lock in its own `Transaction::run()`.
- **Dates** are stored in UTC through `Utils\Dates`.
- **Hooks:** every hook fired has a docblock. `yayloyalty_tier_changed` fires from `Transaction::after_commit()`. Hooks attach in a `register()` method called once from `YayLoyaltyPro::boot()`.
- **SQL:**
  - values through `$wpdb->prepare()`, names backticked through `esc_sql()`, IN lists through `Sql::in_placeholders()`;
  - `ORDER BY` only from `CustomerRowSort`/`LIST_ORDER_COLUMNS`;
  - writes check `false === $result` and throw `QueryException`;
  - a repository doesn't open a transaction or touch a second table.
- **REST:**
  - callbacks are `fn( $request ) => $this->exec_read|exec_write( … )`;
  - `permission_callback` is `$this->requires( Capabilities::… )`;
  - validate before writing, and answer with `$this->error_*()` or a `WP_Error` with a status;
  - entities are snake_case;
  - writes run inside `exec_write()`'s transaction, so anything that queues Action Scheduler work does it from `Transaction::after_commit()`.
- **SPA:**
  - responses are parsed with zod in the `*.api.ts` module;
  - a TypeScript copy of a PHP shape opens with `Mirrors <PHP symbol>, the source of truth.`;
  - queries are `queryOptions` in `TIER_QUERIES`;
  - failures go through `toastApiError()`;
  - forms use react-hook-form + `zodResolver`;
  - UI comes from `components/ui/` (custom wrappers where they exist);
  - no dynamic `import()`.
- **Component placement:** a child component used by one parent stays in the parent's file. Form logic goes in a `.form.ts` module.
- **Strings** use the `yayloyalty` text domain, with a `translators:` comment on every string with a placeholder.
- **Lint only changed files.**
  - PHP: `composer lint -- <files>`. Expected: no error or warning in those files. A whole-repo run fails on `dev/edd` too (docs/known-issues.md).
  - Admin: `pnpm --filter @yayloyalty/admin-page exec eslint <files>`. Expected: no error in those files.
  - Types: `pnpm --filter @yayloyalty/admin-page exec tsc --noEmit`. Expected: exit 0.
- **Branch:** all work is on `jason/feat/tiers`. Commit only when the user asks. Stage by name, use a Conventional Commits message, and end it with `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`.

## Review focus

- **Bonus row re-entering the evaluator.** Expected: the `tier_bonus` row writes nothing, so Silver's bonus never lifts a customer into Gold in the same evaluation. Owned by Phase 1 Task 2 (integration test, cascade case).
- **Lost bonus key on a rolled-back evaluation.** Expected: if the activity insert throws, the bonus row rolls back with it, and the next evaluation pays it. Owned by Phase 1 Task 2 (the bonus is written inside the evaluator's `Transaction::run()`).
- **Queuing inside a rolled-back REST write.** Expected: a failed `PUT /tiers/settings` or tier save leaves no sweep option and queues no `yayloyalty_tier_run`. Owned by Phase 1 Task 3, Step 5.5 (`request()` does all its work from `Transaction::after_commit()`).
- **Overlapping sweep requests** (two threshold edits mid-sweep, or a delete then turning tiers off). Expected:
  - the cursor restarts, and `placement` wins over `reevaluate`;
  - turning tiers off deletes the option, and the next queued run stops at once.
  - Owned by Phase 1 Task 3, Step 5.
- **`Customer::to_row()` overwriting tier columns.** Expected: `ensure_row()` or any other customer save never writes a tier column. Owned by Phase 1 Task 1, Step 1.
- **Bulk adjust "all matching" ignoring the tier filter.** Expected: with Tier = Silver active, the bulk adjust preview count and the rows adjusted are Silver customers only. Owned by Phase 3 Task 1 (the preview route's `filters.tier`) and Task 2 (every `getCustomers` and preview call passes `tier`).
- **A saved segment naming a deleted tier.** Expected: the id drops on read, and the segment still applies its other filters. Owned by Phase 3 Task 2, Step verification.
- **Lite bundle.** Expected: the tier card, filter, column or switch imported outside an `IS_PRO_BUILD` branch fails Lite release validation. Owned by Phase 3 Task 3.

## Phases

| Phase | Delivers (user-visible) | Exit criterion (exact check) | Status |
|---|---|---|---|
| 1 | The Tiers page switch with its confirms and progress banner. Turning tiers on places customers; ledger writes move them; upgrades pay the entry bonus; threshold and delete edits re-evaluate. | `composer test -- --filter TierDecisionTest` and `composer test:integration -- --filter TierEvaluatorTest` pass. On wp-env, turning tiers on from `#/tiers` shows the banner, then "Customer tiers updated." and non-zero member counts. | pending |
| 2 | The drawer tier card with Assign tier and Remove manual tier, floors ending daily, "Tier changes" activity rows, and "{Tier} tier bonus" labels in the admin ledger and My Account | Spec AC 10, 11, 12 and 15 pass by their manual checks in Phase 2 | pending |
| 3 | The Customers Tier column, sort, filter and segments; bulk adjust honouring the tier filter; the decision record; the Lite check | Spec AC 13, 14, 16 and the end-to-end AC 17 pass on wp-env, and the Lite release validates | pending |

## Interface contract

**PHP, Lite**
- `LedgerSource::TIER_BONUS = 'tier_bonus'`; `ReferenceType::TIER = 'tier'`; `IdempotencyKey::TIER_BONUS = 'tier_bonus'`, accepted by `compose()` only with type `earn` and reference type `tier`. Phase 1 Task 1.
- `final class TierChangeReason` (`includes/Enums/TierChangeReason.php`): `EARN = 'earn'`, `REFUND = 'refund'`, `ADJUSTMENT = 'adjustment'`, `MANUAL = 'manual'`, `MANUAL_END = 'manual_end'`, `THRESHOLD_CHANGE = 'threshold_change'`, `TIER_DELETED = 'tier_deleted'`, `PLACEMENT = 'placement'`, `HOLD_END = 'hold_end'` (reserved for slice 4), and `all(): string[]`, using `StringEnumMethods`. Phase 1 Task 1.
- `CustomerActivityService::record_tier_changed( int $user_id, ?int $from_tier_id, ?int $to_tier_id, string $from_name, string $to_name, string $reason, ?int $actor_id, string $at ): void`. It writes the `tier_changed` meta value `{from_tier_id, to_tier_id, from_name, to_name, reason, actor_id}`, with actor kind and name from the existing `actor()`, `staff_only` true (the storefront shows tiers through its own surfaces). Phase 1 Task 1.
- `Customer` getters: `get_tier_id(): ?int`, `get_tier_evaluated_at(): ?string`, `get_tier_progress_value(): ?string`, `get_tier_achieved_at(): ?string`, `get_tier_manual_id(): ?int`, `get_tier_manual_until(): ?string`. Phase 1 Task 1.
- `CustomerRepository` (Phase 1 Task 1):
  - `set_tier_state( int $user_id, array $columns ): void`, whose keys are restricted to the six tier columns;
  - `count_all(): int`;
  - `user_ids_after( int $after_user_id, int $limit ): int[]` (by `user_id` ASC);
  - `count_up_to( int $user_id ): int`;
  - `floor_ended_user_ids( string $now, int $limit ): int[]`.
- `TierRepository::find_many( int[] $ids ): array<int, Tier>`, keyed by id, deleted included. Phase 1 Task 1.
- `Controllers\LedgerItemTierNames::attach( array $items ): array`, which adds `tier_name: ?string` to each ledger item array (non-null only for source `tier_bonus`). Phase 2 Task 3.
- `CustomerRowFilters`: param `tier` (`''` = no filter). Criteria keys `tier_ids: int[]` and `tier_none: bool`. Phase 3 Task 1.
- `CustomerRowSort::to_order( string $orderby, string $order, int[] $tier_ids_by_rank = [] )`. The key `tier` returns `{column: 'tier', direction, nulls_last: true, tier_ids: int[]}`. Phase 3 Task 1.
- Each `GET /customers` row gains `tier_id: int|null`. `POST /customers/adjust/preview` `filters.tier: string`. Phase 3 Task 1.
- `ActivityQueryArgs::GROUPS` gains `tiers` → `[ ActivityEvent::TIER_CHANGED ]`. Phase 2 Task 2.

**PHP, Pro**
- `Pro\Services\TierDecision` (pure, no WordPress calls). Phase 1 Task 2.
  - Constants `MOVE_UP = 'up'` and `MOVE_TO_TARGET = 'to_target'`.
  - `target( Tier[] $live, int $total, ?Tier $floor ): ?Tier`: the live tier with the highest threshold ≤ `$total`, or `$floor` when it outranks that.
  - `ledger_policy( string $type, string $source, int $points ): ?array{move: string, reason: string}`: null for `tier_bonus` rows, for types outside `LedgerType::affects_lifetime_earned()`, and for 0 points.
  - `move( ?int $current_id, int $current_rank, ?Tier $target, string $move ): array{changed: bool, to: ?Tier, is_upgrade: bool}`. `$current_rank` is 0 for a NULL or deleted current tier. `is_upgrade` = `to` is non-null and its rank is above `$current_rank`.
- `Pro\Services\TierEvaluator`. Phase 1 Task 2.
  - `static build(): self`, `static is_enabled(): bool`.
  - `on_ledger_entry( LedgerEntry $entry ): void`.
  - `sweep_one( int $user_id, string $mode ): void`.
  - `assign_floor( int $user_id, int $tier_id, ?int $days, int $actor_id ): void`.
  - `clear_floor( int $user_id, ?int $actor_id ): void`.
  - Each method is a no-op while `! is_enabled()` or when `false === get_userdata( $user_id )`.
- Action `yayloyalty_tier_changed( int $user_id, ?int $from_tier_id, ?int $to_tier_id, string $reason )`, after commit. Phase 1 Task 2.
- `Pro\Services\TierSweep`. Phase 1 Task 3.
  - Constants `OPTION = 'yayloyalty_tier_sweep'`, `MODE_PLACEMENT = 'placement'` and `MODE_REEVALUATE = 'reevaluate'`.
  - `static request( string $mode ): void`, which writes the option and calls `TierRunner::queue_now()` from one `Transaction::after_commit()` callback.
  - `static cancel(): void`.
  - `static current(): ?array{mode: string, after_user_id: int, total: int, started_at: string, token: string}`. Each request writes a fresh `token` (`wp_generate_uuid4()`).
  - `static advance( array $from, int $after_user_id ): void` and `static finish( array $from ): void`: both write only while the stored token still equals `$from['token']`, so a batch that began before a newer request can't move or end it.
  - `static progress(): ?array{mode: string, done: int, total: int}`.
- `Pro\Services\TierRunner`: `RUN_ACTION = 'yayloyalty_tier_run'`, `BATCH_SIZE = 100`, `register()`, `schedule()`, `run()`, `static queue_now()`. The floor-end step is in Phase 1, so Phase 2 only exercises it. Phase 1 Task 3.
- REST `GET/PUT /tiers/settings`, in the spec's shapes. Phase 1 Task 3.
- REST `GET/POST/DELETE /customers/{id}/tier`, in the spec's shapes. Its error codes are `yayloyalty_tiers_disabled` (409) and `yayloyalty_no_manual_tier` (404). Phase 2 Task 1.

**SPA**
- `lib/schema/tier.schema.ts` (Phase 1 Task 4):
  - `tierSettingsSchema` / `TierSettings`: `{tiers_enabled, tiers_mode, tiers_rolling_months, customer_count, sweep: {mode: 'placement'|'reevaluate', done, total} | null}`;
  - `customerTierSchema` / `CustomerTier` (Phase 2 Task 2).
- `pro/tiers/tiers.api.ts` (Phase 1 Task 4):
  - `getTierSettings(signal?)`, `updateTierSettings({ tiers_enabled })`;
  - `getCustomerTier(userId, signal?)`, `assignCustomerTier(userId, { tier_id, days })`, `removeCustomerTier(userId)` (Phase 2 Task 2).
- `pro/tiers/tiers.queries.ts`:
  - `TIER_QUERIES.settings()` with key `['tier', 'settings']` (Phase 1 Task 4);
  - `TIER_QUERIES.customer(userId)` with key `['tier', 'customer', userId]` (Phase 2 Task 2).
- `pro/tiers/TierBadge.tsx`: `TierBadge({ name, color }: { name: string; color: string })`. Phase 1 Task 4.
- From `@/pro`:
  - `TierSwitchCard` (Phase 1);
  - `CustomerTierCard({ customer }: { customer: Customer })` (Phase 2);
  - `CustomersTierFilter({ value, onChange, disabled })` and `CustomersTierCell({ tierId })` (Phase 3);
  - `useTiersEnabled(): boolean`, which reads `SETTINGS_QUERIES.main`'s `tiers_enabled` (Phase 1).
- `lib/schema/ledger.schema.ts`: `'tier_bonus'` in `LEDGER_SOURCES`, and `tier_name: z.string().nullable()` on the ledger entry. Phase 2 Task 3.
- `CustomerListFilters.tier: string` (comma-separated, default `''`), and `CustomerSortKey` gains `'tier'`. Phase 3 Task 2.

## Verification setup

All manual checks run on the wp-env Pro site (http://localhost:8889, `admin` / `password`), which mounts this working tree, so PHP edits are live.

- Once per session: `./scripts/run.sh build pro`, then `pnpm --filter @yayloyalty/e2e env:start`. Rebuild after SPA edits.
- `WP` below means `pnpm --filter @yayloyalty/e2e exec wp-env run tests-cli wp`.
- `REST <METHOD> <route> [json]` below means `$WP eval 'wp_set_current_user(1); $q = new WP_REST_Request("<METHOD>", "/yayloyalty/v1<route>"); $q->set_header("content-type","application/json"); $q->set_body(<json or "">); $r = rest_do_request($q); echo wp_json_encode([$r->get_status(), $r->get_data()]);'`.
- `RUN` below means `$WP eval 'do_action("yayloyalty_tier_run");'`, repeated until `REST GET /tiers/settings` shows `"sweep":null`. That stands in for waiting on Action Scheduler.
- Customer seed: `$WP user create c780 c780@example.test --role=customer --porcelain` (repeat as each step names). Points come from `REST POST /customers/<id>/adjust '{"points":780,"note":"seed","adjust_lifetime":true}'`, which writes an ADJUST row.
- `composer test:integration` needs `YAYLOYALTY_TEST_SOCKET` on a Local site. Exit 0 alone doesn't prove it ran, so read the PHPUnit summary line (docs/known-issues.md).
