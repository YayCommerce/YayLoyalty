# Tier Lifetime Evaluation (Tiers slice 2) Design

**Status:** accepted 2026-11-04
**Related:** [Tiers master design](2026-11-02-tiers-master-design.md) (Delivery slice 2), [slice 1 plan](../plans/2026-11-03-tier-catalogue.md), `docs/backlog/2026-10-06-tiers-not-built.md`

This child spec refines the master for slice 2. Everything the master decides (D1–D22) still holds; this doc fixes the values and rules the master leaves open, and records where it deliberately departs from the master's wording.

## Goal

A Pro merchant turns tiers on, and every customer is placed into the tier their earned points qualify for. From then on each ledger write moves customers up (and refunds or deductions down), each upgrade pays the tier's entry bonus once, staff can set a manual tier floor from the customer drawer, and the Customers list shows, sorts and filters by tier. Lifetime mode only.

## Context

- **Slice 1 (done, on `jason/feat/tiers`):**
  - `wp_yayloyalty_tiers`, `Models\Tier`, `Repositories\TierRepository` (Lite), the seed Bronze 0 / Silver 800 / Gold 3000.
  - The `customers` columns `tier_id`, `tier_evaluated_at`, `tier_progress_value` (decimal(19,4)), `tier_held_until`, `tier_achieved_at`, `tier_manual_id` and `tier_manual_until`, with keys `tier_id`, `tier_held_until` and `tier_manual_until`. Nothing writes them yet.
  - Settings keys `tiers_enabled` (false), `tiers_mode` (`lifetime`) and `tiers_rolling_months` (6). `POST /settings` drops them (`SettingsHelper::TIER_KEYS`).
  - `Pro\Controllers\TiersRestController`: `GET/POST /tiers`, `PUT/DELETE /tiers/{id}`. `member_count` comes from `CustomerRepository::count_by_tier()`.
  - The SPA `pro/tiers/` (`TierCatalog.tsx`, `tiers.api.ts`, `tiers.queries.ts` with `TIER_QUERIES` under key `['tier']`, `tier-catalog.form.ts`), plus `lib/schema/tier.schema.ts`.
- **Ledger:**
  - `LedgerService` (`includes/Services/LedgerService.php`) is the only writer of ledger rows and customer totals. Its docblock lists five construction sites.
  - `earn()` needs an EARN draft with an idempotency key and points ≥ 1. It runs `Transaction::run()` under `CustomerRepository::ensure_and_lock()`, and nested transactions become savepoints (`includes/Database/Transaction.php`).
  - `announce()` fires `yayloyalty_ledger_recorded( LedgerEntry )` from `Transaction::after_commit()`.
  - Keys are built only by `Engine\Earning\IdempotencyKey::compose( type, user_id, reference_type, reference_id, qualifier )` → `earn:7:order:1234:purchase`.
- **`lifetime_earned`** moves on EARN, REFUND_EARN and ADJUST rows (`LedgerType::affects_lifetime_earned()`).
- **Activity:**
  - `Services\CustomerActivityService` writes `customer_meta` rows. It never opens a transaction.
  - `Engine\CustomerActivityListener` shows the subscriber pattern for `yayloyalty_ledger_recorded`: a type guard plus `ListenerGuard::run()`.
  - `Controllers\Schemas\ActivityQueryArgs::event_types_for()` maps the drawer's activity groups.
  - The SPA `customer-drawer.activity-rows.ts` has a `tier_changed` placeholder.
- **Daily jobs:**
  - `Engine\Expiry\ExpiryRunner` (Lite, `yayloyalty_expiry_run`) exposes `register_daily()`, `keep_daily()` and `GROUP` (`yayloyalty`).
  - `Pro\Birthday\BirthdayRunner` is the Pro precedent: its own action, 100 per batch, and an async continuation with an `after_user_id` cursor.
- **Customers list:**
  - Filters: `Controllers\CustomerRowFilters` (Lite, string params, `DEFAULTS`, `candidates_for_no_match()`).
  - Sort keys: `Controllers\CustomerRowSort::KEYS`.
  - Saved segments: `Pro\Helpers\CustomerSegmentsHelper` (option `yayloyalty_customer_segments`, string-enum `FILTER_SCHEMA`, `SCHEMA_VERSION` 1).
  - SPA: `pages/customers/` (`CustomersToolbar`, `CustomersFilterBar` with its `IS_PRO_BUILD` branch, `CustomersTable` with `SortableHead`, `CustomerCardRow`, `customers-page.filters.ts`).
- **Drawer:** `pages/customers/drawer/` (`CustomerDrawer`, `CustomerDrawerStats`/`CustomerDrawerStat`, `CustomerDrawerActions` with `<Can … disable>`, `tabs/DrawerTabFilters`).
- **Labels:** `Engine\Storefront\ShopperActivityRow::earn_label()` (storefront history, Lite) and `lib/helpers/ledger.helper.ts` `describeEarn()` (admin).
- **UI patterns reused:**
  - `ProgramStatusCard` (a Card header with a Switch);
  - `Alert variant="outline"` with `InfoIcon` (CampaignTable's notice);
  - `ConfirmDialog`;
  - `Dialog` from `components/ui/custom/dialog` with react-hook-form + zod (slice 1's `TierFormDialog`);
  - `MultiSelectCombobox`, `RadioGroup`, `NumberInputRoot`, `LoadingButton`, `toastApiError()`.

## Scope

**In:**
- `Pro\Services\TierEvaluator`, the only writer of the tier columns, in Lifetime mode, with the rules in **Evaluation rules** below.
- The after-commit trigger on `yayloyalty_ledger_recorded`.
- `Pro\Services\TierRunner`, a daily `yayloyalty_tier_run` job: it ends manual floors and runs placement and re-evaluation sweeps, 100 customers per batch, with async continuation.
- `TIER_CHANGED` activity rows and the `yayloyalty_tier_changed` action.
- The entry bonus: one EARN row per customer per tier, ever.
- The on/off switch (`GET/PUT /tiers/settings`) with confirm dialogs and a sweep progress banner on the Tiers page.
- Re-evaluation sweeps after a tier create, a threshold change or a delete. The delete sweep moves members of the deleted tier and clears floors that point at it.
- The drawer tier card, the Assign tier dialog, Remove manual tier, `GET/POST/DELETE /customers/{id}/tier`, and the floor's daily end step.
- The Customers list's Tier column (sortable), Tier multi-select filter, and the saved-segment `tier` key.
- The drawer activity tab's "Tier changes" filter and the `tier_changed` row rendering.
- The "{Tier} tier bonus" label in the admin ledger and in the storefront points history.
- The decision record for D20.

**Out (later slices or never):**
- **Slice 3:**
  - the multiplier seam and `TierMultiplier`;
  - `RewardTierCondition`;
  - `TIER` leaving `RewardConditionType::UNAVAILABLE`;
  - tier facts in `Rewards\ContextFactory`;
  - the reward editor's Tier section.
- **Slice 4:** Rolling mode, holds, `tier_held_until` writes, and the mode and months controls. `PUT /tiers/settings` accepts only `tiers_enabled` in this slice.
- **Slice 5:** the launcher badge and progress line.
- **Never:**
  - tier emails;
  - the dashboard tier-distribution card;
  - clawing back an entry bonus;
  - a daily-cap clamp on the bonus;
  - campaign tier conditions.
- **Writes outside `TierEvaluator`:** none. `TiersRestController` writes no customer tier column. It only requests sweeps.
- **No new database columns or keys.** The plugin version stays at `1.0.0`, as in slice 1.

## Evaluation rules

Definitions, computed under the customer-row lock:
- **qualify** = the live tier with the highest threshold where `threshold ≤ lifetime_earned`, or none.
- **floor** = the tier `tier_manual_id` names, only while that tier is live and `tier_manual_until` is NULL or later than now.
- **target** = whichever of qualify and floor has the higher `tier_rank`. Either may be none.
- **current** = the stored `tier_id`, which may name a deleted tier, or be NULL.
- **Rank comparison:** a deleted or NULL current counts as rank 0 when comparing.

| Trigger | Move allowed | `reason` |
|---|---|---|
| Ledger row, `affects_lifetime_earned( type )`, points > 0, source ≠ `tier_bonus` | up only (to target if it outranks current) | EARN → `earn`, REFUND_EARN → `refund`, ADJUST → `adjustment` |
| Ledger row, `affects_lifetime_earned( type )`, points < 0 | to target (up or down) | REFUND_EARN → `refund`, ADJUST → `adjustment` |
| Ledger row with source `tier_bonus` | none: the evaluator returns without writing (D11) | — |
| Ledger row whose type doesn't affect `lifetime_earned` | none: the evaluator returns without writing | — |
| `reevaluate` sweep, current deleted, or `tier_manual_id` names a deleted tier | clear that floor; then to target | `tier_deleted` |
| `reevaluate` sweep, any other customer | up only | `threshold_change` |
| `placement` sweep | to target exactly (up or down) | `placement` |
| Manual assign (`POST /customers/{id}/tier`) | set floor; then up only | `manual`, `actor_id` = the current user |
| Floor ends (daily step) or `DELETE /customers/{id}/tier` | clear floor; then to target | `manual_end` (`actor_id` = the current user for DELETE, null for the daily step) |

**On every evaluation that runs** (every row above except "none"):
- Write `tier_progress_value` = `lifetime_earned` and `tier_evaluated_at` = now (UTC), even when the tier doesn't change.

**When the tier changes**, in the same transaction:
1. Write `tier_id` and `tier_achieved_at` = now.
2. Write one `TIER_CHANGED` row through `CustomerActivityService::record_tier_changed()`.
3. **On an upgrade** (the new rank is above the current one, or current is NULL or deleted), unless the trigger is `placement`, and when the new tier's `entry_bonus` > 0: pay the bonus through `LedgerService::earn()`. Details are in **Approach → Entry bonus**.

**After commit:**
- `do_action( 'yayloyalty_tier_changed', int $user_id, ?int $from_tier_id, ?int $to_tier_id, string $reason )`, once per change.

**Gates:**
- **Tiers off:** while `tiers_enabled` is false the evaluator writes nothing for any trigger. Manual routes answer 409.
- **No WordPress user:** a user id with no WordPress user (`false === get_userdata()`) is skipped, with no write.

Consequences that follow from these rules (accepted):
- **Raised thresholds:** a customer kept in a tier by a raised threshold (D18) drops at their next negative row.
- **Re-enabling:** turning tiers back on runs placement, which may move frozen customers down to what their points qualify for.

## Acceptance criteria

Setup unless stated: the wp-env Pro site, seed tiers Bronze 0 / Silver 800 / Gold 3000, every bonus 0, tiers on, Lifetime mode, the default purchase campaign at 1 pt per currency unit, and the sweep from turning tiers on finished.

1. **Off does nothing.** With `tiers_enabled` false:
   - A customer at 780 whose 30-point order completes keeps `tier_id` NULL, and `tier_evaluated_at` stays NULL.
   - No `tier_changed` meta row is written.
   - `POST /customers/{id}/tier` returns 409 `yayloyalty_tiers_disabled`.
2. **Placement** (master AC 3). Customers A (`lifetime_earned` 0), B (900) and C (3,500), all with `tier_id` NULL, and Silver's bonus 100.
   - `PUT /tiers/settings {"tiers_enabled":true}` returns 200 with `sweep.mode` `placement`, and `yayloyalty_tier_sweep` exists.
   - When the queued `yayloyalty_tier_run` actions finish:
     - A is Bronze, B Silver, C Gold;
     - each has one `tier_changed` row with reason `placement`;
     - no ledger row with source `tier_bonus` exists;
     - the option is gone, and `GET /tiers/settings` returns `"sweep":null`.
3. **Earn upgrade** (master AC 4). A Bronze customer at 780 completes an order earning 30. After commit:
   - `tier_id` = Silver, `tier_progress_value` = 810, and `tier_achieved_at` is set.
   - There is one `tier_changed` row `{from_name: "Bronze", to_name: "Silver", reason: "earn", actor_id: null}`.
   - `yayloyalty_tier_changed` fired once with `(user_id, <Bronze id>, <Silver id>, "earn")`.
4. **Bonus once per tier** (master AC 8). With Silver's bonus at 100, AC 3's upgrade also writes:
   - one EARN row of 100 with source `tier_bonus`, `reference_type` `tier`, `reference_id` = Silver's id, and idempotency key `earn:{user_id}:tier:{Silver id}:tier_bonus`;
   - `lifetime_earned` 910.
   - A −200 ADJUST then drops them to Bronze (reason `adjustment`). A later 200-point ADJUST returns them to Silver (reason `adjustment`) and writes no second bonus row.
5. **No bonus cascade** (master AC 9). Gold's threshold is 850 and Silver's bonus 100. A Bronze customer at 780 earns 30:
   - they end in Silver with `lifetime_earned` 910;
   - the evaluation of the bonus row writes nothing (`tier_evaluated_at` unchanged by it).
   - Their next earn of 1 point moves them to Gold (reason `earn`).
6. **Refund downgrade** (master AC 10). A customer who reached Silver at 810 through a 30-point order has that order fully refunded. After commit they are Bronze at 780, with one `tier_changed` row with reason `refund`.
7. **Raised threshold keeps members** (master AC 18).
   - A Silver customer is at 850. Raising Silver to 1,000 runs a `reevaluate` sweep that leaves them in Silver.
   - An ADJUST of +10 (860) leaves them in Silver.
   - An ADJUST of −10 (850) moves them to Bronze (reason `adjustment`).
8. **Lowered threshold pays the bonus.** With Gold's bonus at 50, lowering Gold to 2,000 moves a Silver customer at 2,500 to Gold (reason `threshold_change`) and writes one 50-point `tier_bonus` row.
9. **Delete re-evaluates** (master AC 13, completed). Silver has a member at 900, and a customer at 100 holds a Silver floor (so is Silver too). Deleting Silver (no reward references it) runs a sweep, after which:
   - the member is Bronze, with a `tier_changed` row with reason `tier_deleted` and `from_name` "Silver";
   - the floor customer is Bronze (reason `tier_deleted`), with `tier_manual_id` and `tier_manual_until` NULL;
   - the drawer's activity tab still reads "Silver".
10. **Manual floor** (master AC 17). Gold's bonus is 50, and a Bronze customer is at 100.
    - `POST /customers/{id}/tier {"tier_id":<Gold>,"days":30}` gives `tier_id` Gold, `tier_manual_id` Gold, and `tier_manual_until` = now + 30 days (UTC, to the second). It writes one `tier_changed` row with reason `manual` and `actor_id` = the admin, and one 50-point `tier_bonus` row.
    - A −50 ADJUST leaves them Gold.
    - After setting `tier_manual_until` to the past and running `yayloyalty_tier_run`, they are Bronze (reason `manual_end`, `actor_id` null), with both floor columns NULL.
11. **Low assignment stores the floor only.** Assigning Silver (No end) to a Gold customer sets `tier_manual_id` Silver and `tier_manual_until` NULL. It leaves `tier_id` Gold and writes no `tier_changed` row and no bonus.
12. **Remove floor.** `DELETE /customers/{id}/tier` clears both floor columns and re-evaluates (reason `manual_end`, `actor_id` = the admin, only if the tier changes). With no floor it returns 404.
13. **Customers filter, sort and segments** (master AC 19).
    - `GET /customers?tier=<Silver id>,none` returns only Silver and tierless customers.
    - `orderby=tier&order=desc` lists Gold, then Silver, then Bronze, then tierless.
    - A segment saved with Tier = Silver + No tier stores `"tier":["<Silver id>","none"]` and re-applies both. After Silver is deleted, the segment reads `"tier":["none"]`.
14. **Switch off freezes and hides.**
    - After `PUT /tiers/settings {"tiers_enabled":false}` (any pending sweep option is deleted), a Bronze customer crossing 800 stays Bronze.
    - The Customers Tier column and filter, the drawer tier card and the Tiers page's member counts ("—") are hidden or blanked.
    - Turning tiers on again runs placement and moves them to Silver with no bonus.
15. **Bonus label.** A `tier_bonus` row for Silver reads "Silver tier bonus" in the drawer's Ledger tab and in My Account's points history, and still does after Silver is deleted.
16. **Lite keeps data and labels.** On a Lite build (`./scripts/run.sh release lite --no-prompt` passes validation), `Installer::install()` leaves every customer tier column unchanged, and My Account still labels the bonus row "Silver tier bonus".
17. **End to end** on the wp-env Pro site, with the seed tiers, Silver's bonus at 100, tiers off, and a customer at 780 lifetime points:
    1. On Tiers, turn on → the confirm names the customer count. The banner "Updating customer tiers… X of N" shows, then disappears with the toast "Customer tiers updated.", and members are counted.
    2. The customer completes a 30-unit order → the drawer card shows ● Silver, "Since {today}", and "2,090 pts to Gold" (910 of 3,000); the Ledger tab shows "Silver tier bonus +100".
    3. Assign Gold, Limited 30 days → the card shows ● Gold and "Manual tier: ● Gold · until {today + 30}", and the Activity tab's "Tier changes" filter shows "Bronze → Silver (Earned points)" and "Silver → Gold (Manual by admin)".
    4. Remove manual tier → back to Silver (Manual tier ended).
    5. The Customers list filtered to Silver shows the customer with the Silver badge.
    6. The Chrome console shows no error or warning from `yayloyalty` on `#/tiers`, `#/customers` and the drawer.

## Constraints

- **PHP 7.4:** no enums, `match`, `readonly`, promoted constructor properties, `?->`, named arguments, union/`mixed`/`static`/`never` types, `catch` without a variable, `throw` as an expression, trailing comma after the last parameter, first-class callables or `$object::class` (hard rule 4, `.claude/rules/php-standards.md`).
- **Lite names Pro only in the guarded `YayLoyaltyPro::boot()` call.**
  - `TierEvaluator`, `TierRunner`, `TierSweep`, `CustomerTierRestController` and the hook wiring are Pro, attached in `YayLoyaltyPro::boot()`.
  - Lite-side additions (`CustomerActivityService::record_tier_changed()`, `LedgerSource::TIER_BONUS`, `ReferenceType::TIER`, `IdempotencyKey::TIER_BONUS`, the Customers `tier` filter and sort, the label) don't name Pro in code. Docblocks may, because the Lite validator strips comments.
- **SPA tier code** lives in `apps/admin/src/pro/`, is imported only through `apps/admin/src/pro/index.ts`, and renders only behind `IS_PRO_BUILD`. Lite files gain only slots, guarded the way `CustomersFilterBar` guards its branch.
- **Ledger writes:** only `LedgerService` writes ledger rows and balances.
  - The bonus goes through `LedgerService::earn()`.
  - `TierEvaluator` constructs it and is added to `LedgerService`'s docblock as the sixth site.
  - No tier work runs inside `LedgerService`'s lock; the evaluator runs after commit (D20).
- **Tier columns:** `TierEvaluator` is the only writer of `tier_id`, `tier_evaluated_at`, `tier_progress_value`, `tier_achieved_at`, `tier_manual_id` and `tier_manual_until`.
- **Lock order:** the customer row first, then the ledger write inside it. That matches `LedgerService`'s order, so there's no new deadlock path.
- **Dates:** stored in UTC through `Utils\Dates`.
- **Hooks:** every new hook has a docblock. `yayloyalty_tier_changed` fires from `Transaction::after_commit()`.
- **SQL:** through `$wpdb->prepare()`; names through `esc_sql()`; IN lists through `Sql::in_placeholders()`; `ORDER BY` only from `sortable_columns()`/`CustomerRowSort`. A repository doesn't open a transaction or touch a second table.
- **REST:** callbacks via `exec_read`/`exec_write`, and `permission_callback` via `$this->requires( Capabilities::… )`. Responses are snake_case and parsed with zod in `*.api.ts`.
- **Strings** use the `yayloyalty` text domain, with a `translators:` comment on every placeholder.
- **Tests:** per CLAUDE.md § Tests. The evaluator's bonus and ledger path qualifies (ledger writes), so yayplan proposes the test with AskUserQuestion before writing it. Everything else ends in an exact manual check.
- **Branch:** all work on `jason/feat/tiers`; commit only when the user asks.

## Approach

**Chosen:** a Pro evaluator triggered after commit, with sweeps tracked by a cursor in an option (approach A), driven by a Pro daily runner.

**Architecture:**
- `TierEvaluator::evaluate( int $user_id, string $trigger, array $args )` runs one `Transaction::run()`. It locks the customer row, applies **Evaluation rules**, writes the columns, activity and bonus, and registers the after-commit action. Every caller goes through it:
  - the ledger subscriber;
  - the sweep and floor-end steps of `TierRunner`;
  - the manual routes.
- `TierSweep` owns the sweep option. `TierRunner` drains it in batches of 100 by `user_id`, as `BirthdayRunner` does.
- `TiersRestController` and the settings route request sweeps; they never write customer columns, which keeps D20 exact.

**Components:**
- `includes/Pro/Services/TierEvaluator.php` [new]: the rules, the column writes, `TIER_CHANGED`, the bonus and the after-commit action. Constructed with `CustomerRepository`, `TierRepository`, `LedgerService`, `CustomerActivityService`.
- `includes/Pro/Services/TierSweep.php` [new]: `request( string $mode )`, `cancel()`, `current(): ?array`, `advance( int $after_user_id )`, `finish()`, `progress(): ?array{mode, done, total}`. `MODE_PLACEMENT = 'placement'`, `MODE_REEVALUATE = 'reevaluate'`, `OPTION = 'yayloyalty_tier_sweep'`.
- `includes/Pro/Services/TierRunner.php` [new]: `RUN_ACTION = 'yayloyalty_tier_run'`, `BATCH_SIZE = 100`, `register()` through `ExpiryRunner::register_daily()`, `schedule()`, `run()`, `queue_now()`.
- `includes/Pro/Services/TierLedgerListener.php` [new]: subscribes to `yayloyalty_ledger_recorded`. It type-guards `LedgerEntry`, skips rows the rules skip, and calls the evaluator inside `ListenerGuard::run()`.
- `includes/Pro/Controllers/CustomerTierRestController.php` [new]: `GET/POST/DELETE /customers/{id}/tier`.
- `includes/Pro/Controllers/TiersRestController.php` [edit]:
  - `GET/PUT /tiers/settings`;
  - `TierSweep::request( MODE_REEVALUATE )` after create, after an update whose `threshold` changed, and after delete, only while tiers are on;
  - `get_items()` unchanged.
- `includes/Pro/YayLoyaltyPro.php` [edit]: registers the listener, the runner and the new controller.
- `includes/Services/LedgerService.php` [edit]: docblock only, naming the sixth construction site.
- `includes/Services/CustomerActivityService.php` [edit]: `record_tier_changed( int $user_id, ?int $from_tier_id, ?int $to_tier_id, string $from_name, string $to_name, string $reason, ?int $actor_id, string $at )`.
- `includes/Enums/LedgerSource.php` [edit]: `TIER_BONUS = 'tier_bonus'`.
- `includes/Enums/ReferenceType.php` [edit]: `TIER = 'tier'`.
- `includes/Engine/Earning/IdempotencyKey.php` [edit]: `TIER_BONUS = 'tier_bonus'`, accepted as a qualifier for earn rows with reference type `tier`.
- `includes/Enums/TierChangeReason.php` [new, Lite]: the nine reasons, so the activity reader and a downgraded site share the vocabulary.
- `includes/Repositories/CustomerRepository.php` [edit]:
  - `set_tier_state( int $user_id, array $columns )`, the only tier-column write, called only by `TierEvaluator`;
  - `user_ids_after( int $after_user_id, int $limit ): int[]`;
  - `count_all(): int`;
  - `floor_ended_user_ids( string $now, int $limit ): int[]`;
  - `count_up_to( int $user_id ): int`;
  - `tier` criteria in `where_fragments()` and `tier` ordering in `order_by_sql()`.
- `includes/Models/Customer.php` [edit]: maps `tier_id`, `tier_evaluated_at`, `tier_progress_value` (string), `tier_achieved_at`, `tier_manual_id` and `tier_manual_until`. `tier_held_until` stays unmapped until slice 4.
- `includes/Repositories/TierRepository.php` [edit]: `find_many( int[] $ids ): Tier[]` (deleted included) for name lookups.
- `includes/Controllers/CustomerRowFilters.php` [edit]: the `tier` param (`''` = no filter; comma-separated positive ints and/or `none`), and `tier_ids`/`tier_none` criteria.
- `includes/Controllers/CustomerRowSort.php` [edit]: key `tier`. The controller passes the live tier ids in rank order, and the repository orders by `tier_id IS NULL, FIELD( tier_id, … )`; rank DESC for `desc`, tierless always last.
- `includes/Controllers/CustomerListFormatter.php` [edit]: each row gains `tier_id: int|null`.
- `includes/Pro/Helpers/CustomerSegmentsHelper.php` [edit]: the `tier` key is a list. It's sanitised to live tier ids (as strings) and `none`, `[]` by default, and a missing key reads as `[]`. `SCHEMA_VERSION` stays 1.
- `includes/Controllers/Schemas/ActivityQueryArgs.php` [edit]: the group `tiers` → `[ ActivityEvent::TIER_CHANGED ]`.
- `includes/Engine/Storefront/ShopperActivityRow.php` [edit]: the `tier_bonus` label.
- `includes/Controllers/LedgerRestController.php`, `includes/Pro/Controllers/StoreLedgerRestController.php` [edit]: each item gains `tier_name: string|null`, filled for `tier_bonus` rows.
- `docs/decisions/2026-11-04-tier-evaluator-sole-writer.md` [new]: records D20.
- `docs/backlog/2026-10-06-tiers-not-built.md` [edit]: tick the evaluator, Customers filter and drawer boxes.
- SPA (component placement is in **UI**).

**Entry bonus:**
- **Draft:** `new LedgerEntry` with type EARN, points = `entry_bonus`, source `tier_bonus`, `reference_type` `tier`, `reference_id` = the tier id, and note `"{name} tier bonus"`.
- **Key:** `IdempotencyKey::compose( LedgerType::EARN, $user_id, ReferenceType::TIER, $tier_id, IdempotencyKey::TIER_BONUS )` → `earn:{user_id}:tier:{tier_id}:tier_bonus`.
  - This departs from the master's `tier_bonus:{user_id}:{tier_id}`, because every ledger key goes through `compose()`.
  - It is at most 54 characters, within `MAX_LENGTH` 96.
- **Write:** `LedgerService::earn( $draft )`, with no `points_under_lock` callable, so `DailyEarningLimit` never clamps it and the multiplier (slice 3) never applies.
- **Already paid:** a `duplicate` outcome means the bonus was already paid, which is normal.
- **Emails:** none; no email is relayed.

**Data and storage:**
- **Option `yayloyalty_tier_sweep`** (autoload `no`): `{ "mode": "placement"|"reevaluate", "after_user_id": int, "total": int, "started_at": "Y-m-d H:i:s" }`.
  - `request()`: if there's no option, it writes `after_user_id` 0, `total` = `CustomerRepository::count_all()`, and `started_at` = now. If an option exists, it resets `after_user_id` to 0, recounts `total`, and keeps `placement` when either the stored or the requested mode is `placement`. Either way it then calls `TierRunner::queue_now()`.
  - `cancel()` deletes the option.
  - `progress()` returns `done` = `count_up_to( after_user_id )`, capped at `total`.
- **`TIER_CHANGED` meta value:** `{from_tier_id, to_tier_id, from_name, to_name, reason, actor_id}`. Names are frozen at write time. `actor_id` is null except for `manual` and the DELETE-route `manual_end`.
- **`tier_changed.reason`** ∈ `earn`, `refund`, `adjustment`, `manual`, `manual_end`, `threshold_change`, `tier_deleted`, `placement`. `hold_end` is reserved for slice 4.
- **Settings:** `PUT /tiers/settings` writes `tiers_enabled` through `SettingsHelper::update_settings()` (which bypasses `POST /settings`' `TIER_KEYS` drop).
- **Migration:** none. Every column exists from slice 1, NULL by default.
- **Uninstall:** none. The plugin has no `uninstall.php`, so options and tables stay, as today.

**`TierRunner::run()`**, on `yayloyalty_tier_run`, only while `tiers_enabled` is true:
1. **Ending floors:** up to 100 ids from `floor_ended_user_ids( now )` (`tier_manual_id IS NOT NULL AND tier_manual_until <= now`) → `evaluate( id, 'manual_end' )`.
2. **Sweep batch:** if `TierSweep::current()` exists, take up to 100 ids from `user_ids_after( after_user_id )` → `evaluate( id, mode )` → `advance( last id )`. Fewer than 100 ids calls `finish()`, which deletes the option.
3. **Continuation:** if either step returned 100 ids, `as_enqueue_async_action( 'yayloyalty_tier_run', [], 'yayloyalty' )`.
4. **Failures:** each evaluation is wrapped in `try/catch` with `Log::error( 'TierRunner', … )`, and a failure doesn't stop the batch.

**Integration points:**
- **Subscribes:**
  - `yayloyalty_ledger_recorded` (`TierLedgerListener`, priority 20, after `CustomerActivityListener`);
  - `yayloyalty_tier_run`.
- **Fires:** `yayloyalty_tier_changed( int $user_id, ?int $from_tier_id, ?int $to_tier_id, string $reason )`, after commit. It's the future hook for tier emails.
- **REST** (Pro, `yayloyalty/v1`):

  | Route | Capability | Request | Response / errors |
  |---|---|---|---|
  | `GET /tiers/settings` | `yayloyalty_manage_tiers` | — | `{tiers_enabled: bool, tiers_mode: string, tiers_rolling_months: int, customer_count: int, sweep: null \| {mode, done, total}}` |
  | `PUT /tiers/settings` | `yayloyalty_manage_tiers` | `{tiers_enabled: bool}` (required) | The GET shape. false → true: `request( placement )`. true → false: `cancel()`. Same value: no sweep change |
  | `GET /customers/{id}/tier` | `yayloyalty_view` | — | `{tier: {id, name, color, tier_rank} \| null, progress_value: int, next: {id, name, threshold} \| null, achieved_at: string \| null, manual: {tier_id, name, color, until: string \| null} \| null}`. `tier` is null when the stored tier is deleted. `next` is the lowest live tier ranked above the effective tier (above none = the lowest tier). 404 `error_not_found()` for an unknown customer |
  | `POST /customers/{id}/tier` | `yayloyalty_manage_tiers` | `{tier_id: int, days: int \| null}`; `days` 1–3650 | The GET shape. 409 `yayloyalty_tiers_disabled` "Turn on tiers before assigning one."; 400 for bad `days`; 404 for an unknown customer or a non-live tier |
  | `DELETE /customers/{id}/tier` | `yayloyalty_manage_tiers` | — | The GET shape. 404 `yayloyalty_no_manual_tier` when no floor is set; 409 `yayloyalty_tiers_disabled` while off |

- **Lite customers routes:**
  - `GET /customers` accepts `tier` and `orderby=tier`, and each row returns `tier_id`.
  - `GET /customers/{id}/activity?group=tiers`.
- **Shared code changed, and who consumes it:**
  - `CustomerActivityService`, `LedgerSource`, `ReferenceType` and `IdempotencyKey`: additive constants and a method, so existing callers are unaffected.
  - `CustomerRepository`: additive methods and a new criteria key that defaults to off, so the existing list, export and no-match suggestions are unaffected.
  - `CustomerSegmentsHelper`: an additive key, so existing segments read `tier: []`.
  - `LedgerService`: docblock only.

**UI** (all tier UI in `apps/admin/src/pro/`, rendered when `IS_PRO_BUILD && settings.tiers_enabled` unless noted):
- `pro/tiers/TierBadge.tsx` [new]: the slice‑1 swatch (`size-3 shrink-0 rounded-full border`, background = colour) plus the name. It has four callers, so it gets its own file; `TierCatalog`'s row uses it too.
- `pro/tiers/TierSwitchCard.tsx` [new] on the Tiers page, above the slice‑1 card. It shows whether tiers are on or off.
  - **Layout:** as `ProgramStatusCard`, a `Card` with a `CardHeader` holding the title "Enable tiers" and the description "Place customers into tiers by the points they've earned." on the left, and a `Switch` (`w-11!`) on the right.
  - **The switch flips only after the confirm is accepted** (`ConfirmDialog`):
    - **On:** title "Turn on tiers?", description "%s customers will be placed into the tier their earned points qualify for. No entry bonus is paid for this placement." (`customer_count`), confirm "Turn on".
    - **Off:** title "Turn off tiers?", description "Customers keep their current tier, but nothing changes it until you turn tiers back on. Tier columns and the customer tier card are hidden while off.", confirm "Turn off", `variant="destructive"`.
  - **Save errors** go through `toastApiError()`.
  - **Progress banner:** `Alert variant="outline"` with `InfoIcon` reading "Updating customer tiers… %1$s of %2$s", shown while `sweep` is set. The `['tier', 'settings']` query refetches every 5,000 ms while `sweep` is non-null. When it turns null, the page invalidates `['tier']` and toasts "Customer tiers updated."
  - **While off,** the catalogue table's Members column shows "—".
- `pro/tiers/tiers.api.ts`, `tiers.queries.ts` [edit]:
  - `getTierSettings`, `updateTierSettings`, `getCustomerTier`, `assignCustomerTier`, `removeCustomerTier`;
  - `TIER_QUERIES.settings()` with key `['tier', 'settings']`;
  - `TIER_QUERIES.customer(id)` with key `['tier', 'customer', id]`.
  - Assign and remove invalidate `['tier']`, the customer's detail, list and activity queries, and the ledger queries.
- `lib/schema/tier.schema.ts` [edit]: `tierSettingsSchema` and `customerTierSchema`, each opening with `Mirrors <PHP symbol>, the source of truth.`
- **Customers list:**
  - **Filter:**
    - `CustomersToolbar` [edit, Lite] gains an optional `extraFilters?: ReactNode` slot, rendered after the Expiring soon select.
    - `CustomersFilterBar`'s `IS_PRO_BUILD` branch passes `<CustomersTierFilter>` from `@/pro`: a `MultiSelectCombobox` with the placeholder "Tier", the options = the live tiers by rank plus "No tier" (`none`), and the same width as the other filter selects.
    - `customers-page.filters.ts` [edit]: `CustomerListFilters.tier: string` (comma-separated, default `''`), and `initialFiltersFromSearch` reads `tier`.
    - `countActiveFilters` and the no-match suggestions count it.
  - **Column:**
    - `CustomersTable` [edit, Lite] renders, inside `IS_PRO_BUILD &&` guards, a `SortableHead sortKey="tier" label="Tier"` after "Customer", and a cell `<CustomersTierCell tierId>` from `@/pro`.
    - The cell shows a `TierBadge` from `TIER_QUERIES.list()`, or a muted "—" when tierless or when the id isn't live.
    - `CustomerCardRow` [edit] shows the badge under the name.
    - `customers-page.sort.ts` gains `tier`.
  - **Segments:** `pro/customers/` [edit] saves and applies `tier` as a string array, joined into the filter string.
  - **While off,** the column, the filter and a segment's `tier` value are ignored.
- **Drawer:**
  - `pro/customers/CustomerTierCard.tsx` [new], exported from `@/pro`. `CustomerDrawer` [edit, Lite] renders it between `CustomerDrawerStats` and `CustomerDrawerActions`, guarded by `IS_PRO_BUILD`. The card is one file with its Assign dialog and Remove confirm, each having one caller.
  - **Box:** `rounded-md border`, as `CustomerDrawerStats`.
    - **Top row**, a two-column grid of `CustomerDrawerStat`s with `p-4`:
      - **TIER**, `size="lg"`: `TierBadge` or "No tier", with the sub-line "Since %s" (`formatStoredDate( achieved_at )`).
      - **NEXT TIER**: "%1$s pts to %2$s" (`next.threshold − progress_value`, `formatPoints`), or "Top tier".
      - Under the NEXT TIER stat, a progress bar `h-1.5 rounded-full bg-muted` with a `bg-primary` fill = `progress_value / next.threshold`, and the caption "%1$s / %2$s" (`tabular-nums`, text-xs muted). There's no bar at the top tier.
    - **`border-t` strip**, `px-4 py-3`:
      - when a floor exists: "Manual tier: {TierBadge} · until %s" or "· no end", in text-xs muted;
      - the buttons `Button variant="outline" size="sm"` "Assign tier" and, when a floor exists, "Remove manual tier", each in `<Can cap={CAP.MANAGE_TIERS} disable>`.
    - **Deleted account:** both buttons are disabled, with the existing `DELETED_ACCOUNT_ACTION_REASON()` line.
  - **Assign dialog:** `Dialog` from `components/ui/custom/dialog`, react-hook-form + zod.
    - **Title:** "Assign tier". **Description:** "%s is kept at this tier or above. Their earned points can still move them higher."
    - **Tier:** a `Select` of live tiers rendered as `TierBadge`.
    - **End:** a `RadioGroup` with "No end" (default) or "Limited". Limited shows `NumberInputRoot` with the unit "days" (1–3650) and the line "Ends on %s".
    - **Notes:**
      - When the chosen tier ranks at or below the current one: "%s is already at or above this tier. This only stops them dropping below it."
      - When the chosen tier's `entry_bonus` > 0 and it outranks the current one: "Pays the %s entry bonus once, if they haven't had it."
    - **Footer:** Cancel and `LoadingButton` "Assign". The toast reads "%1$s assigned to %2$s."; errors go through `toastApiError()`.
  - **Remove:** `ConfirmDialog`, title "Remove manual tier?", description "%s goes back to the tier their earned points qualify for, which may be lower.", confirm "Remove". The toast reads "Manual tier removed."
  - **Activity:**
    - `tabs/DrawerTabFilters` / the activity group list [edit] gain the option "Tier changes" (`tiers`), only when `IS_PRO_BUILD`.
    - `customer-drawer.activity-rows.ts` [edit] renders `tier_changed`:
      - title "Tier changed";
      - detail "{from_name or No tier} → {to_name or No tier}";
      - sub-line, the reason label: `earn` "Earned points", `refund` "Refund", `adjustment` "Points adjustment", `manual` "Manual by %s", `manual_end` "Manual tier ended", `threshold_change` "Tier thresholds changed", `tier_deleted` "Tier deleted", `placement` "Tiers turned on". An unknown reason shows no sub-line.
    - The names come from the payload, so a downgraded or deleted tier still reads correctly.
- **Ledger labels:**
  - `lib/schema/ledger.schema.ts` [edit]: `'tier_bonus'` in `LEDGER_SOURCES`, and `tier_name: z.string().nullable()` on the entry.
  - `lib/helpers/ledger.helper.ts` `describeEarn()` [edit]: `tier_bonus` → "%s tier bonus" (`tier_name`), or "Tier bonus" when null.
  - `ShopperActivityRow::earn_label()`: the same strings, with the name from `TierRepository::find_many()`.

**Edge cases and failure modes:**
- **Ledger write inside a foreign transaction:** `yayloyalty_ledger_recorded` fires at the outer commit, so the evaluator never runs inside it.
- **Bonus row re-entering the evaluator:** the source `tier_bonus` is skipped (D11).
- **Racing evaluations** (a ledger trigger and the sweep, or two requests): both lock the customer row. The second sees the first's result and writes the same tier. The bonus key turns a second payment into a `duplicate` outcome.
- **Evaluator failure in the ledger listener:** `ListenerGuard` logs and swallows it, and the ledger write already committed. The customer is fixed at their next ledger write or the next `reevaluate`/`placement` sweep.
- **A tier deleted or edited mid-sweep:** the new request resets the cursor to 0, so every customer is evaluated against the final catalogue.
- **Turned off mid-sweep:** `cancel()` removes the option, and a continuation already queued finds no option and stops.
- **A tier deleted while tiers are off:** no sweep runs. Customers keep the deleted `tier_id` (the column shows "—") until the next placement.
- **Floor end with no Action Scheduler** (WooCommerce inactive): an expired floor is already ignored at evaluation (floor needs `until > now`), and its columns clear at the next run.
- **Assigning the customer's current tier as a floor:** the floor is stored, no tier change, no bonus (AC 11).
- **Assigning to a customer with no `customers` row** (never earned): `ensure_and_lock()` creates the row at balance 0, as `LedgerService` does.
- **User deleted from WordPress:** the evaluator skips the id, and the sweep advances past it.
- **Lite downgrade:** the evaluator, runner, listener and routes are gone, and `tier_id` freezes. `yayloyalty_tier_run` actions left in the queue have no callback, and Action Scheduler marks them failed, harmlessly. Bonus rows keep their label, because `ShopperActivityRow` and `TierRepository` are Lite.
- **Large stores:** each batch is 100 PK-ordered ids plus 100 short transactions. Progress counts use the PK index.

**Decision record:** `docs/decisions/2026-11-04-tier-evaluator-sole-writer.md` (status `accepted`) records D20. The tier is stored, `TierEvaluator` is its only writer, it runs after the ledger commit and never inside `LedgerService`'s lock, and sweeps are cursor batches requested by the routes that change the catalogue.

## Alternatives considered

| Option | Why rejected |
|---|---|
| Sweep as a stale marker per customer (`tier_evaluated_at < requested_at`) | Needs a new `KEY tier_evaluated_at` and a non-PK range scan per batch; the cursor merges requests just as well |
| Sweep synchronously in the REST request | Times out on large stores; breaks the master's 100-per-batch rule |
| A tier step inside `yayloyalty_expiry_run` (the master's wording) | Each tier continuation would re-run the whole expiry pass; Pro's own action follows the `BirthdayRunner` precedent |
| Downgrade on any ledger re-evaluation | Breaks D18: a raised threshold would drop members at their next earn |
| Store the threshold each customer qualified at | Exact D18 forever, but a new column for a narrow case; downgrading on the next negative row is enough |
| Never downgrade in Lifetime | Contradicts D6 and master AC 10 |
| No bonus from re-evaluation sweeps | A threshold-lowering upgrade is real; the once-per-tier key already prevents duplicates |
| Clear every `tier_id` on switch-off | Loses state for no gain; placement on re-enable rebuilds it anyway |
| Keep tier surfaces visible while off | Shows frozen, possibly stale data as if live |
| Floor removal only by re-assigning | Leaves no way to undo a mistaken floor with No end |
| Block assigning a tier at or below the current one | A floor below the current tier is a valid protection against dropping |
| Block deleting a tier that is a floor (409) | Floors are staff convenience, not configuration; clearing them in the sweep is enough |
| Clamp the bonus with `DailyEarningLimit` | A clamped bonus would be lost for good, because its key is spent |
| Bonus key `tier_bonus:{user_id}:{tier_id}` (master AC 8) | Every ledger key is built by `IdempotencyKey::compose()`; `earn:{user_id}:tier:{tier_id}:tier_bonus` keeps one format |
| Tier fields on the Lite `GET /customers/{id}` | Puts Pro presentation in a Lite formatter; a Pro route keeps the drawer card's data in Pro |
| Generic "Tier bonus" label | The tier name is cheap to resolve, and tiers are soft-deleted, so it always resolves |

## Rollout

- `tiers_enabled` stays false after upgrade; nothing changes until a merchant turns it on, which runs placement with no bonus.
- No schema change, no migration. The plugin version stays at `1.0.0` on this branch (slice 1 decision). The release that ships tiers must bump it so existing sites run `Installer::install()` and get slice 1's schema.
- Release note: "Tiers: turn on in Loyalty → Tiers. Existing customers are placed by the points they've earned; no entry bonus is paid for that first placement."

## Open questions

None.
