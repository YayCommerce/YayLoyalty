# Tier Catalogue (Tiers slice 1) Implementation Plan

> **For agentic workers:** execute tasks in order, one at a time. Steps use `- [ ]` for tracking. Read the spec linked below before starting; the plan argues from it.

**Status:** active
**Goal:** Ship the tier catalogue: the tiers table seeded with Bronze/Silver/Gold, the new customer tier columns and settings keys, the `yayloyalty_manage_tiers` capability (v3), Pro's tier CRUD REST with its delete rules, and a Pro "Tiers" page with a list and create, edit and delete dialogs.
**Spec:** [docs/specs/2026-11-02-tiers-master-design.md](../specs/2026-11-02-tiers-master-design.md), Delivery slice 1. There is no child spec: the user chose to plan from the master, and the slice-1 values the master leaves open are in `## Requirements` below.
**Architecture:** The table, model, repository and seed are Lite, so Lite installs and keeps the data (conventions § Pro data survives Lite). The REST controller is Pro and is registered through the `yayloyalty_rest_controllers` filter in `YayLoyaltyPro::add_controllers()`. Like `RewardRestController`, there is no service: the invariants are validation in the controller, inside `exec_write()`'s transaction, and every write first locks the live tier rows so the count and uniqueness checks can't race. The SPA page lives under `apps/admin/src/pro/tiers/`. In Lite the nav item is not rendered and the route 404s, as `LedgerPage` does.
**Touches:** `includes/Database/` (Installer, Schema), `includes/Models/Tier.php` [new], `includes/Repositories/` (Tier [new]; Reward and Customer gain one read each, shared, consumers: every reward/customer caller, read-only additions), `includes/Enums/TierMode.php` [new], `includes/Helpers/SettingsHelper.php` (shared, every settings reader), `includes/Controllers/SettingsRestController.php`, `includes/Access/Capabilities.php` (shared, every route), `includes/Pro/` (controller, boot), `apps/admin/src/` (access, settings schema, tier schema, router, HeaderMenu, pro/tiers, pages/tiers), `docs/architecture.md`, `docs/backlog/2026-10-06-tiers-not-built.md`.
**Tests:** `tests/unit/Models/TierTest.php` (suite `unit`, `composer test`), accepted in `/ja-test`: guards `Tier::normalize_multiplier()`, whose result will scale every campaign's points from slice 3 on, plus the threshold, entry bonus, colour and name bounds. Everything else is catalogue CRUD, outside CLAUDE.md § Tests, and ends in an exact manual check; the cases are in [the test cases](../specs/2026-11-03-tier-catalogue.testcases.md).

## Requirements

Slice-1 values the master spec leaves open. Everything else comes from the spec verbatim.

- **Goal:** a Pro merchant opens Tiers, sees Bronze 0 / Silver 800 / Gold 3000, and creates, edits and deletes tiers within the rules.
- **Out of scope (later slices):** `PUT /tiers/settings` and the on/off switch, the mode controls, `TierEvaluator`, placement, `TIER_CHANGED` writes, the bonus, `POST /customers/{id}/tier`, the drawer card, the Customers filter, `Models\Customer` mapping of tier columns, the multiplier seam, `RewardTierCondition`, `TIER` leaving `UNAVAILABLE`, the reward editor's Tier section, the launcher. In this slice a delete does not re-evaluate customers (no evaluator yet, and nothing has written `tier_id`), and `member_count` is 0 for every tier.
- **Field rules (server is the source of truth; the SPA mirrors them for messages only):**
  - `name`: `sanitize_text_field`, trimmed, 1–100 characters (`mb_strlen`), unique among live tiers case-insensitively (`mb_strtolower`). Duplicate → 400 "A tier with that name already exists."
  - `threshold`: integer 0–1,000,000,000, unique among live tiers. Duplicate → 400 "Another tier already starts at this number of points."
  - `multiplier`: decimal string (a JSON number is accepted and read the same), 1.00–10.00 inclusive, a multiple of 0.05. Stored `decimal(4,2)`, always sent back as a two-decimal string (`"1.25"`). Anything else → 400.
  - `entry_bonus`: integer 0–1,000,000.
  - `color`: `#RRGGBB` (6 hex digits), stored uppercase.
  - Create requires every field; `PUT` is partial (an absent field keeps its stored value) and re-checks uniqueness against the result.
- **Count rules (409, state conflicts):**
  - Create with 10 live tiers → 409, code `yayloyalty_tier_limit`, "You can have at most 10 tiers."
  - Delete of the only live tier → 409, code `yayloyalty_last_tier`, "You need at least one tier."
  - Delete of a tier that any reward's stored conditions reference (type `tier`, any status, the value equal to the id or containing it in a list) → 409, code `yayloyalty_tier_in_use`, `data.rewards` = `[{id, name, status}]`.
- **Rank:** after every create, update and delete, live tiers are renumbered `tier_rank` 1..n by `threshold` ascending. Deleted rows keep their last rank.
- **Delete** is soft: `deleted_at` = now (UTC). A deleted or unknown id → 404 on `PUT` and `DELETE`.
- **Seed:** on install, only when the tiers table has no row at all (deleted rows included), so a merchant's deletions and edits are never undone by a later install.
- **Version:** unchanged at `1.0.0`; this slice does not touch `yayloyalty.php`, `YAYLOYALTY_VERSION` or `readme.txt` (user decision, overriding the spec's "version bump" in slice 1). An existing dev site gets the schema and seed by re-running `Installer::install()`: reset `yayloyalty_db_version` (as the steps below do), or deactivate and reactivate the plugin.
- **Settings keys** `tiers_enabled` (false), `tiers_mode` (`lifetime`), `tiers_rolling_months` (6) are readable from `GET /settings`, but `POST /settings` drops them, so `tiers_enabled` stays off until slice 2's `PUT /tiers/settings` (behind `yayloyalty_manage_tiers`) writes them.
- **Page access:** the Tiers route and nav item need `yayloyalty_manage_tiers`. `GET /tiers` needs `yayloyalty_view` (spec), for later slices' read-only uses.
- **Acceptance criteria:**
  1. After `Installer::install()` runs on a site that predates the change (forced by resetting `yayloyalty_db_version`), `wp_yayloyalty_tiers` holds Bronze 0 / 1.00 / 0 / #CD7F32, Silver 800 / 1.25 / 0 / #C0C0C0, Gold 3000 / 1.50 / 0 / #FFD700 with ranks 1/2/3, `customers` has the four new columns and two keys, `Installer::verify()` returns `[]`, and `GET /settings` shows `tiers_enabled: false` (spec AC 1).
  2. A second `Installer::install()` adds no tier row, and after deleting Bronze it does not bring Bronze back.
  3. administrator and shop_manager hold `yayloyalty_manage_tiers` after the upgrade; a custom role holding `yayloyalty_manage_campaigns` gets it; a role without it doesn't; `yayloyalty_caps_version` is 3.
  4. The field, count, rank and delete rules above hold over REST, with the exact codes.
  5. Deleting Silver while a reward's stored conditions hold `{type: tier, operator: gte, value: <Silver id>}` returns 409 and lists that reward; after removing the condition, the delete soft-deletes Silver (spec AC 13, without the slice-2 re-evaluation).
  6. A Lite build installs and keeps the tiers table and columns, has no Tiers nav item, `#/tiers` shows the 404 page, and `./scripts/run.sh release lite --no-prompt` passes validation.
  7. **End to end** on the wp-env Pro site: Tiers lists the three seed tiers sorted by threshold with name, threshold, multiplier, bonus, colour swatch and member count 0. Creating "Platinum" 6000 / 1.75 / 200 / #E5E4E2 adds it as the last row; editing Silver's threshold to 7000 moves it below Platinum; deleting it removes the row; the console shows no error.
- **Decided with the user:**
  - Child spec or plan from master? → Plan from master, slice values in Requirements.
  - Version bump? → No: leave the plugin version untouched.
  - Settings keys and `PUT /tiers/settings` → keys now, route in slice 2.
  - Lite nav and route → hidden, route 404s.

## Global constraints

- Product PHP runs on PHP 7.4: no enums, `match`, `readonly`, promoted constructor properties, `?->`, named arguments, union types, `mixed`/`static`/`never` types, `catch` without a variable, `throw` as an expression, trailing comma after the last declared parameter, first-class callables, `$object::class` (hard rule 4, `.claude/rules/php-standards.md`). Only review catches this.
- Lite names Pro only in the guarded `YayLoyaltyPro::boot()` call in `includes/YayLoyalty.php`. Pro attaches only through filters `boot()` adds (hard rule 2). `TiersTable`, `Tier`, `TierRepository`, `TierMode`, `Installer` and `Capabilities` are Lite and must not name Pro.
- SPA tier code lives in `apps/admin/src/pro/`, is imported only through `apps/admin/src/pro/index.ts`, and renders only behind `IS_PRO_BUILD` (hard rule 3). `<ProGate>` alone is not enough.
- No ledger write in this slice (hard rule 5).
- Dates stored in UTC through `Utils\Dates`. The multiplier is `decimal(4,2)`, carried in PHP as a string, bound `%s`, sent as a string, parsed in the SPA with `z.string()` (conventions § Money).
- Table DDL follows the dbDelta rules in `AbstractTableSchema`'s docblock: `KEY name (cols)`, two spaces after `PRIMARY KEY`, `ENGINE=InnoDB`.
- SQL: values through `$wpdb->prepare()`; names backticked through `esc_sql()`; writes check `false === $result` and throw `QueryException`; a repository never opens a transaction or touches a second table.
- REST: callbacks are `fn( $request ) => $this->exec_read|exec_write( … )`; `permission_callback` is `$this->requires( Capabilities::… )`; validate before writing and answer with `$this->error_*()` or a `WP_Error` with a status.
- A TypeScript copy of a PHP shape opens with `Mirrors <PHP symbol>, the source of truth.` REST entities are snake_case.
- SPA: responses parsed with zod in the `*.api.ts` module; queries as `queryOptions` in a `TIER_QUERIES` map; failures through `toastApiError()`; forms with react-hook-form + `zodResolver`; UI from `components/ui/` (custom wrappers where they exist); no dynamic `import()`.
- A child component used by one parent stays in the parent's file; form logic goes in a `.form.ts` module as `reward-editor.form.ts` does.
- Strings in the `yayloyalty` text domain, with a `translators:` comment on every string with a placeholder.
- Lint only changed files: `composer lint -- <files>`. Expected: no error or warning in those files (a whole-repo run fails on `dev/edd` too, docs/known-issues.md). Admin lint: `pnpm --filter @yayloyalty/admin-page exec eslint <files>`; expected no error in the changed files.
- All work is on `jason/feat/tiers` (spec D21). Commit only when the user asks; each commit step below is staged by name and run only on request.

## Review focus

- **Reinstall resurrecting tiers.** A merchant deletes Bronze, then `Installer::install()` runs again. Expected: Bronze stays deleted. Task 1 Step 5.
- **Fresh install capability order.** `upgrade_roles( 0 )` runs the v2 block (removes `yayloyalty_manage_tiers`) before the defaults loop. Expected: a fresh install still ends with the cap on administrator and shop_manager. Task 2 Step 3.
- **Custom roles.** A merchant's role with `manage_campaigns`. Expected: it gains `manage_tiers` on upgrade, without a manual grant. Task 2 Step 3.
- **Multiplier precision.** `"1.3"`, `1.25` (number), `"10"`, `"10.05"`, `"0.95"`, `"1.234"`. Expected: `"1.30"`, `"1.25"`, `"10.00"`, 400, 400, 400. Task 3 Step 6.
- **Partial update collision.** `PUT` with only `threshold` equal to another live tier's. Expected: 400, nothing written. Task 3 Step 6.
- **Deleted tiers don't block.** A soft-deleted "Silver" at 800. Expected: creating a new "Silver" at 800 succeeds. Task 3 Step 7.
- **Settings bypass.** `POST /settings` with `tiers_enabled: true`. Expected: stored value stays false. Task 1 Step 6.
- **Lite bundle.** The Tiers page imported anywhere outside an `IS_PRO_BUILD` branch. Expected: Lite release validation passes and the Lite bundle has no tiers code. Task 5 Step 2.

## File tree

- `includes/Database/Schema/TiersTable.php` [new] — the tiers DDL, expected columns and indexes.
- `includes/Database/Schema/CustomersTable.php` [edit] — four tier columns and two keys.
- `includes/Database/Installer.php` [edit] — registers `TiersTable`, seeds the three tiers when the table is empty.
- `includes/Models/Tier.php` [new] — the typed tier row and the field normalizers.
- `includes/Repositories/TierRepository.php` [new] — all tiers-table SQL.
- `includes/Repositories/RewardRepository.php` [edit] — `find_referencing_tier()`.
- `includes/Repositories/CustomerRepository.php` [edit] — `count_by_tier()`.
- `includes/Enums/TierMode.php` [new] — `lifetime` / `rolling` and the allowed rolling months.
- `includes/Helpers/SettingsHelper.php` [edit] — three tier keys in defaults and schema, two new value types.
- `includes/Controllers/SettingsRestController.php` [edit] — `POST /settings` drops the tier keys.
- `includes/Access/Capabilities.php` [edit] — `MANAGE_TIERS`, v3 role upgrade.
- `includes/Pro/Controllers/TiersRestController.php` [new] — `GET/POST /tiers`, `PUT/DELETE /tiers/{id}`.
- `includes/Pro/YayLoyaltyPro.php` [edit] — registers the controller.
- `apps/admin/src/lib/access.tsx` [edit] — `CAP.MANAGE_TIERS`.
- `apps/admin/src/lib/schema/settings.schema.ts` [edit] — three tier keys.
- `apps/admin/src/lib/schema/tier.schema.ts` [new] — tier zod schemas and limits mirror.
- `apps/admin/src/pro/tiers/tiers.api.ts` [new] — REST client.
- `apps/admin/src/pro/tiers/tiers.queries.ts` [new] — `TIER_QUERIES` and mutation hooks.
- `apps/admin/src/pro/tiers/tier-catalog.form.ts` [new] — form values, defaults, payload mapping.
- `apps/admin/src/pro/tiers/TierCatalog.tsx` [new] — list table, create/edit dialog, delete dialog.
- `apps/admin/src/pro/index.ts` [edit] — exports `TierCatalog`.
- `apps/admin/src/pages/tiers/TiersPage.tsx` [new] — Lite-safe route entry.
- `apps/admin/src/router.tsx` [edit] — `tiers` route.
- `apps/admin/src/components/layout/HeaderMenu.tsx` [edit] — Tiers nav item, Pro only.
- `docs/architecture.md` [edit] — the tiers table and its seed rule in § Data and upgrades.
- `docs/backlog/2026-10-06-tiers-not-built.md` [edit] — tick the two boxes this slice meets.

## Verification setup

All manual checks run on the wp-env Pro site (http://localhost:8889, `admin` / `password`), which mounts this working tree, so PHP edits are live.

- Once per session: `./scripts/run.sh build pro`, then `pnpm --filter @yayloyalty/e2e env:start`. Rebuild after SPA edits.
- `WP` below means `pnpm --filter @yayloyalty/e2e exec wp-env run tests-cli wp`.
- `REST <METHOD> <route> [json]` below means:
  `$WP eval 'wp_set_current_user(1); $q = new WP_REST_Request("<METHOD>", "/yayloyalty/v1<route>"); $q->set_header("content-type","application/json"); $q->set_body(<json or "">); $r = rest_do_request($q); echo wp_json_encode([$r->get_status(), $r->get_data()]);'`

---

### Task 1: Schema, seed and settings keys

**Done when:** a re-run of `Installer::install()` creates and seeds the tiers table, adds the customer columns, and exposes the three settings keys read-only.

**Files:**
- Create: `includes/Database/Schema/TiersTable.php`, `includes/Models/Tier.php`, `includes/Repositories/TierRepository.php`, `includes/Enums/TierMode.php`
- Modify: `includes/Database/Schema/CustomersTable.php`, `includes/Database/Installer.php`, `includes/Helpers/SettingsHelper.php`, `includes/Controllers/SettingsRestController.php`, `apps/admin/src/lib/schema/settings.schema.ts`

**Interfaces:**
- Consumes: nothing.
- Produces:
  - Table slug `tiers` (`Table::name( 'tiers' )`), columns exactly as the spec's table, index `idx_live_rank (deleted_at,tier_rank)`.
  - `customers` columns `tier_held_until`, `tier_achieved_at`, `tier_manual_id`, `tier_manual_until`; keys `tier_held_until`, `tier_manual_until`.
  - `final class Tier extends AbstractModel`: constructor `( ?int $id, string $name, int $threshold, int $tier_rank, string $multiplier, int $entry_bonus, string $color, string $created_at, string $updated_at, ?string $deleted_at )`; getters `get_id()`, `get_name()`, `get_threshold()`, `get_tier_rank()`, `get_multiplier(): string`, `get_entry_bonus()`, `get_color()`, `get_deleted_at()`, `is_live(): bool`; setters `set_name()`, `set_threshold()`, `set_multiplier()`, `set_entry_bonus()`, `set_color()`, `set_updated_at()`; `to_array()` keys `id, name, threshold, tier_rank, multiplier, entry_bonus, color, created_at, updated_at`; `immutable_columns()` = `[ 'created_at', 'tier_rank', 'deleted_at' ]`: only `TierRepository` moves the rank (`rerank()`) and the deletion stamp (`soft_delete()`), each in its own statement.
  - Normalizers, each returning null on invalid input: `Tier::normalize_name( $value ): ?string`, `Tier::normalize_threshold( $value ): ?int`, `Tier::normalize_multiplier( $value ): ?string`, `Tier::normalize_entry_bonus( $value ): ?int`, `Tier::normalize_color( $value ): ?string`. Constants `Tier::NAME_MAX = 100`, `THRESHOLD_MAX = 1000000000`, `ENTRY_BONUS_MAX = 1000000`, `MULTIPLIER_MIN = '1.00'`, `MULTIPLIER_MAX = '10.00'`, `MULTIPLIER_STEP_CENTS = 5`, `MAX_LIVE = 10`.
  - `final class TierRepository extends AbstractRepository` (slug `tiers`, sortable `id, threshold, tier_rank, name`): `find_tier( int $id ): ?Tier`, `find_live( int $id ): ?Tier`, `live(): Tier[]` (threshold ASC), `count_all(): int` (deleted included), `lock_live(): void` (`SELECT id … WHERE deleted_at IS NULL FOR UPDATE`), `rerank(): void` (1..n by threshold ASC over live rows, via `bulk_set_int_column`), `soft_delete( int $id, string $now ): void`, `seed_defaults( string $now ): void` (inserts the three only when `count_all() === 0`).
  - `final class TierMode`: `LIFETIME = 'lifetime'`, `ROLLING = 'rolling'`, `all(): string[]`, `ROLLING_MONTHS = [ 3, 6, 9, 12, 18, 24 ]`, `DEFAULT_ROLLING_MONTHS = 6`.
  - Settings root keys `tiers_enabled` (`bool`, false), `tiers_mode` (`tier_mode`, `lifetime`), `tiers_rolling_months` (`tier_rolling_months`, 6); `SettingsHelper::TIER_KEYS = [ 'tiers_enabled', 'tiers_mode', 'tiers_rolling_months' ]`.

- [ ] **Step 1: Add `TiersTable` and the customer columns**

`TiersTable` per the spec's table: `name varchar(100) NOT NULL`, `threshold bigint unsigned NOT NULL`, `tier_rank int unsigned NOT NULL`, `multiplier decimal(4,2) NOT NULL DEFAULT 1.00`, `entry_bonus bigint unsigned NOT NULL DEFAULT 0`, `color varchar(7) NOT NULL`, `created_at`/`updated_at datetime NOT NULL`, `deleted_at datetime DEFAULT NULL`. Its docblock says name and threshold uniqueness among live tiers lives in the controller, because a soft-deleted row would collide with a unique index. In `CustomersTable`, add the four columns after `tier_progress_value` (all `DEFAULT NULL`, `tier_manual_id bigint unsigned`) and the two keys, to `definition()`, `expected_columns()` and `expected_indexes()`. Extend its docblock: `tier_manual_until` NULL with a `tier_manual_id` means no end.

- [ ] **Step 2: Add `Tier`, `TierRepository` and `TierMode`** (signatures in Interfaces)

`normalize_multiplier()` accepts a numeric string or number, reads it as integer cents (reject more than two decimals), and requires 100 ≤ cents ≤ 1000 and cents % 5 = 0; it returns `sprintf( '%d.%02d', … )`. Model docblock: the multiplier is a decimal string, never a float.

- [ ] **Step 3: Register and seed in `Installer`**

Add `new \YayLoyalty\Database\Schema\TiersTable()` to `schemas()`. Add `seed_default_tiers()` to `seed_builtins()`, calling `( new TierRepository() )->seed_defaults( Dates::now() )`, with names translated (`__( 'Bronze', 'yayloyalty' )` …) and the spec D19 values, then `rerank()`.

- [ ] **Step 4: Add the settings keys**

`SettingsHelper`: the three keys in `get_default_settings()` and `schema()`; `is_valid_value()` and `coerce()` cases `tier_mode` (`in_array( $value, TierMode::all(), true )`) and `tier_rolling_months` (an int in `TierMode::ROLLING_MONTHS`; `coerce()` returns `(int)` or null). `SettingsRestController::save_settings()` removes `SettingsHelper::TIER_KEYS` from the body before `is_valid()`, with a comment: these are written only by the Tiers route, behind its own capability. `settings.schema.ts`: `tiers_enabled: z.boolean()`, `tiers_mode: z.enum(['lifetime', 'rolling'])`, `tiers_rolling_months: z.union([z.literal(3), …, z.literal(24)])`.

- [ ] **Step 5: Verify install and seed**

Run: `$WP option update yayloyalty_db_version 0`, load http://localhost:8889/wp-admin/ once, then `$WP option get yayloyalty_db_version`, then:
- `$WP db query "SELECT name, threshold, tier_rank, multiplier, entry_bonus, color, deleted_at FROM wp_yayloyalty_tiers ORDER BY threshold"`
- `$WP db query "SHOW COLUMNS FROM wp_yayloyalty_customers LIKE 'tier_%'"`
- `$WP eval 'var_export( \YayLoyalty\Database\Installer::verify() );'`
Expected: `1.0.0` after the page load (the install re-ran and re-stamped it); rows `Bronze 0 1 1.00 0 #CD7F32 NULL`, `Silver 800 2 1.25 0 #C0C0C0 NULL`, `Gold 3000 3 1.50 0 #FFD700 NULL`; seven `tier_` columns; `array ( )`.
Then: `$WP db query "UPDATE wp_yayloyalty_tiers SET deleted_at = UTC_TIMESTAMP() WHERE name = 'Bronze'"`, `$WP eval '\YayLoyalty\Database\Installer::install();'`, rerun the SELECT. Expected: still three rows, Bronze with a `deleted_at`. Restore: `$WP db query "UPDATE wp_yayloyalty_tiers SET deleted_at = NULL WHERE name = 'Bronze'"`.

- [ ] **Step 6: Verify the settings keys**

Run: `REST GET /settings` then `REST POST /settings '{"tiers_enabled":true,"tiers_mode":"rolling"}'` then `REST GET /settings`.
Expected: both GETs show `"tiers_enabled":false,"tiers_mode":"lifetime","tiers_rolling_months":6`; the POST answers 200.

- [ ] **Step 7: Lint**

Run: `composer lint -- includes/Database includes/Models/Tier.php includes/Repositories/TierRepository.php includes/Enums/TierMode.php includes/Helpers/SettingsHelper.php includes/Controllers/SettingsRestController.php`
Expected: no error or warning in these files.

- [ ] **Step 8: Commit**

Stage: `includes/Database/Schema/TiersTable.php`, `includes/Database/Schema/CustomersTable.php`, `includes/Database/Installer.php`, `includes/Models/Tier.php`, `includes/Repositories/TierRepository.php`, `includes/Enums/TierMode.php`, `includes/Helpers/SettingsHelper.php`, `includes/Controllers/SettingsRestController.php`, `apps/admin/src/lib/schema/settings.schema.ts`
Message: `feat(tiers): add the tiers table, seed and settings keys`

### Task 2: Capability v3

**Done when:** every role that manages campaigns can manage tiers, and the SPA knows the capability.

**Files:**
- Modify: `includes/Access/Capabilities.php`, `apps/admin/src/lib/access.tsx`

**Interfaces:**
- Consumes: nothing.
- Produces: `Capabilities::MANAGE_TIERS = 'yayloyalty_manage_tiers'`, listed in `all()` after `MANAGE_REWARDS` and in shop_manager's `defaults()`; `Capabilities::VERSION = 3`; `CAP.MANAGE_TIERS` in the SPA.

- [ ] **Step 1: Add the constant, the defaults and the v3 block**

In `upgrade_roles()`, after the v2 block: `if ( $stored < 3 )` grant `MANAGE_TIERS` to every role in `wp_roles()->role_objects` that has `MANAGE_CAMPAIGNS`, as the docblock's example shows. Comment: 3 brings tiers back. Leave the v2 block's literal unchanged.

- [ ] **Step 2: Mirror it in `access.tsx`**: `MANAGE_TIERS: 'yayloyalty_manage_tiers'`.

- [ ] **Step 3: Verify**

Run: `$WP role create loyalty_editor "Loyalty editor" && $WP cap add loyalty_editor yayloyalty_manage_campaigns && $WP role create loyalty_viewer "Loyalty viewer" && $WP cap add loyalty_viewer yayloyalty_view && $WP option update yayloyalty_caps_version 2`, load http://localhost:8889/wp-admin/, then `$WP eval 'foreach (["administrator","shop_manager","loyalty_editor","loyalty_viewer"] as $r) echo $r, "=", var_export(get_role($r)->has_cap("yayloyalty_manage_tiers"), true), " "; echo get_option("yayloyalty_caps_version");'`
Expected: `administrator=true shop_manager=true loyalty_editor=true loyalty_viewer=false 3`.
Then the fresh-install order: `$WP option delete yayloyalty_caps_version`, `$WP eval '\YayLoyalty\Access\Capabilities::install(); var_export(get_role("shop_manager")->has_cap("yayloyalty_manage_tiers"));'`. Expected: `true`.
Clean up: `$WP role delete loyalty_editor` and `$WP role delete loyalty_viewer` (one role per call).

- [ ] **Step 4: Lint**: `composer lint -- includes/Access/Capabilities.php`. Expected: clean.

- [ ] **Step 5: Commit**

Stage: `includes/Access/Capabilities.php`, `apps/admin/src/lib/access.tsx`
Message: `feat(tiers): add the manage tiers capability (v3)`

### Task 3: Tier CRUD REST

**Done when:** the four routes enforce every rule in `## Requirements` over REST.

**Files:**
- Create: `includes/Pro/Controllers/TiersRestController.php`
- Modify: `includes/Pro/YayLoyaltyPro.php` (`add_controllers()`), `includes/Repositories/RewardRepository.php`, `includes/Repositories/CustomerRepository.php`

**Interfaces:**
- Consumes: Task 1's `Tier`, `TierRepository`; Task 2's `Capabilities::MANAGE_TIERS`.
- Produces:
  - `RewardRepository::find_referencing_tier( int $tier_id ): Reward[]` — rows whose `conditions` hold a `tier` entry naming the id (a SQL `LIKE '%"tier"%'` prefilter, then a PHP check over `get_conditions()`: `value` equal to the id, or a list containing it). Every status.
  - `CustomerRepository::count_by_tier(): array<int, int>` — tier id ⇒ customers with that `tier_id`.
  - `final class TiersRestController extends AbstractRestController`, rest base `tiers`, constructor `( TierRepository $tiers, RewardRepository $rewards, CustomerRepository $customers )`; the other two repositories are read-only here, as in `RewardRestController`.
  - `GET /tiers` (VIEW) → `Tier[]` as `to_array()` plus `member_count: int`, live only, threshold ASC, not paginated.
  - `POST /tiers` (MANAGE_TIERS), body `{name, threshold, multiplier, entry_bonus, color}`, all required → the created item.
  - `PUT /tiers/{id}` (MANAGE_TIERS), any subset → the updated item.
  - `DELETE /tiers/{id}` (MANAGE_TIERS) → `{deleted: true, id}`.
  - Error codes `yayloyalty_tier_limit`, `yayloyalty_last_tier`, `yayloyalty_tier_in_use` (409, `data.rewards: [{id, name, status}]`); field errors via `error_invalid_arguments()` (400); unknown or deleted id via `error_not_found()` (404).

- [ ] **Step 1: Add `find_referencing_tier()` and `count_by_tier()`**

- [ ] **Step 2: Add the controller's routes and schema**

`define_item_schema()` declares the five writable fields plus readonly `tier_rank`, `member_count`. Route args take `multiplier` as `[ 'string', 'number' ]`. `PUT` uses `get_write_args()` with every field optional.

- [ ] **Step 3: Implement `create_item()`, `update_item()`, `delete_item()` and `get_items()`**

Each write first calls `$this->tiers->lock_live()`, then validates in this order: normalizers → count limit (create) → name and threshold uniqueness among other live tiers → write → `rerank()`. Delete order: 404 → last live tier → references → `soft_delete()` → `rerank()`. Every response item comes from one private `respond_item( Tier $tier, array $counts )`.

- [ ] **Step 4: Register it in `YayLoyaltyPro::add_controllers()`**: `new TiersRestController( new TierRepository(), new RewardRepository(), new CustomerRepository() )`.

- [ ] **Step 5: Verify the list and create**

Run: `REST GET /tiers`, then `REST POST /tiers '{"name":"Platinum","threshold":6000,"multiplier":"1.75","entry_bonus":200,"color":"#e5e4e2"}'`, then `REST GET /tiers`.
Expected: first, 200 with three items, ranks 1–3, `"multiplier":"1.25"` for Silver, `member_count` 0; the POST, 200 with `"color":"#E5E4E2"`, `"tier_rank":4`; the last GET lists four.

- [ ] **Step 6: Verify the field rules**

Run each `REST PUT /tiers/<Silver id>` body and read the status: `{"multiplier":"1.3"}` → 200 `"1.30"`; `{"multiplier":1.25}` → 200 `"1.25"`; `{"multiplier":"10"}` → 200 `"10.00"`; `{"multiplier":"10.05"}`, `{"multiplier":"0.95"}`, `{"multiplier":"1.234"}`, `{"multiplier":"1.27"}` → 400; `{"threshold":3000}` → 400 and Silver still 800; `{"name":"gold"}` → 400; `{"name":"   "}` → 400; `{"color":"#FFF"}` → 400; `{"entry_bonus":-1}` and `{"entry_bonus":1000001}` → 400; `{"threshold":7000}` → 200, then `REST GET /tiers` shows Silver last with rank 4 and Platinum rank 3. Restore `{"threshold":800,"multiplier":"1.25"}`.
As a user without the cap: `$WP user create viewer viewer@example.test --role=subscriber && $WP user add-cap viewer yayloyalty_view`, then the same `REST` eval with `wp_set_current_user(<viewer id>)`: `GET /tiers` → 200, `POST /tiers` → 403 `yayloyalty_forbidden`.

- [ ] **Step 7: Verify the count and delete rules**

Run: create tiers until there are 10 live, then one more POST. Expected: 409 `yayloyalty_tier_limit`. Delete the extras.
Run: `$WP db query "UPDATE wp_yayloyalty_rewards SET conditions = '[{\"type\":\"tier\",\"operator\":\"gte\",\"value\":<Silver id>}]' WHERE id = <any reward id>"`, then `REST DELETE /tiers/<Silver id>`. Expected: 409 `yayloyalty_tier_in_use` with that reward in `data.rewards`.
Run: set that reward's `conditions` back to `[]`, `REST DELETE /tiers/<Silver id>`, `REST GET /tiers`, `$WP db query "SELECT name, deleted_at FROM wp_yayloyalty_tiers WHERE name='Silver'"`. Expected: 200 `{"deleted":true,…}`; Silver absent from the list and ranks contiguous; the row has a `deleted_at`.
Run: `REST POST /tiers '{"name":"Silver","threshold":800,"multiplier":"1.25","entry_bonus":0,"color":"#C0C0C0"}'`. Expected: 200 (the deleted row doesn't block).
Run: `REST PUT /tiers/<deleted Silver id> '{"name":"X"}'`. Expected: 404.
Run: delete every live tier but one, then delete that one. Expected: 409 `yayloyalty_last_tier`. Restore the seed by `$WP db query "TRUNCATE wp_yayloyalty_tiers"` and `$WP eval '\YayLoyalty\Database\Installer::install();'`.

- [ ] **Step 8: Lint**: `composer lint -- includes/Pro/Controllers/TiersRestController.php includes/Pro/YayLoyaltyPro.php includes/Repositories/RewardRepository.php includes/Repositories/CustomerRepository.php`. Expected: clean.

- [ ] **Step 9: Commit**

Stage: `includes/Pro/Controllers/TiersRestController.php`, `includes/Pro/YayLoyaltyPro.php`, `includes/Repositories/RewardRepository.php`, `includes/Repositories/CustomerRepository.php`
Message: `feat(tiers): add the tier CRUD routes with their delete rules`

### Task 4: Tiers page

**Done when:** a Pro admin manages tiers from the Tiers page, and Lite shows neither the nav item nor the page.

**Files:**
- Create: `apps/admin/src/lib/schema/tier.schema.ts`, `apps/admin/src/pro/tiers/tiers.api.ts`, `apps/admin/src/pro/tiers/tiers.queries.ts`, `apps/admin/src/pro/tiers/tier-catalog.form.ts`, `apps/admin/src/pro/tiers/TierCatalog.tsx`, `apps/admin/src/pages/tiers/TiersPage.tsx`
- Modify: `apps/admin/src/pro/index.ts`, `apps/admin/src/router.tsx`, `apps/admin/src/components/layout/HeaderMenu.tsx`

**Interfaces:**
- Consumes: Task 3's routes and error codes; Task 2's `CAP.MANAGE_TIERS`.
- Produces:
  - `tier.schema.ts` (opens `Mirrors Models\Tier and TiersRestController, the source of truth.`): `tierSchema` (`id, name, threshold, tier_rank, multiplier: z.string(), entry_bonus, color, member_count, created_at, updated_at`), `tiersSchema = z.array(tierSchema)`, `type Tier`, `type TierInput = Pick<Tier, 'name' | 'threshold' | 'multiplier' | 'entry_bonus' | 'color'>`, `TIER_LIMITS` (`nameMax 100`, `thresholdMax 1_000_000_000`, `entryBonusMax 1_000_000`, `multiplierMin 1`, `multiplierMax 10`, `multiplierStep 0.05`, `maxLive 10`), `tierInUseErrorSchema` (`code: z.literal('yayloyalty_tier_in_use')`, `data.rewards: [{id, name, status}]`). Slice 2 and 3 import this schema for the Customers filter and the reward editor.
  - `tiers.api.ts`: `getTiers(signal?): Promise<Tier[]>`, `createTier(input: TierInput): Promise<Tier>`, `updateTier(id: number, input: Partial<TierInput>): Promise<Tier>`, `deleteTier(id: number): Promise<void>`, `readTierInUse(error: unknown): Promise<TierInUse | null>`, where `TierInUse` is `{ message: string; rewards: { id: number; name: string; status: string }[] }`, so the dialog shows the server's message.
  - `tiers.queries.ts`: `TIER_QUERIES.list()` with key `['tier', 'list']`; `useTiersQuery()`, `useCreateTierMutation()`, `useUpdateTierMutation()`, `useDeleteTierMutation()`, each invalidating `['tier']`.
  - `TierCatalog` exported from `@/pro`.
  - Route `#/tiers`.

- [ ] **Step 1: Add the schema, api and queries modules** (signatures in Interfaces)

- [ ] **Step 2: Add `tier-catalog.form.ts`**

`TierFormValues` holds `multiplier` as a number for the input; `toTierInput(values): TierInput` formats it with two decimals. A zod form schema over `TIER_LIMITS` gives inline messages; the server stays the judge, and its 400 message is shown through `toastApiError()`. New-tier defaults: empty name, threshold = highest live threshold + 1000, multiplier 1, bonus 0, colour `#6B7280`.

- [ ] **Step 3: Add `TierCatalog.tsx`**

One file holding:
- the list: a table sorted by threshold with columns Name (colour swatch + name), Points to reach, Multiplier (`×1.25`, via `formatStoreDecimal`), Entry bonus (`formatPoints`), Members (`formatStoreNumber`), and a row menu with Edit and Delete; loading skeleton and error state from `components/ui/custom/`; an "Add tier" button disabled with a tooltip "You can have at most 10 tiers." at 10.
- the create/edit dialog (`Dialog` from `components/ui/custom/dialog`): Name, Points to reach (`PointsInput`), Multiplier (number input, step 0.05, 1–10), Entry bonus (`PointsInput`), Colour (`ColorPicker`).
- the delete dialog (`ConfirmDialog`): "Delete {name}?". On `yayloyalty_tier_in_use` it switches to "These rewards require this tier. Remove the tier from them before deleting it." with each reward's name; on `yayloyalty_last_tier` it shows the server message.

- [ ] **Step 4: Wire the page**

`TiersPage.tsx`: `IS_PRO_BUILD ? <PageLayout title={__('Tiers', 'yayloyalty')}><TierCatalog /></PageLayout> : <NotFoundPage />`. `router.tsx`: `{ path: 'tiers', element: guarded(CAP.MANAGE_TIERS, <TiersPage />) }` after the rewards routes. `HeaderMenu.tsx`: `{IS_PRO_BUILD && ( <CustomMenuItem asChild key="tiers" cap={CAP.MANAGE_TIERS}> … Crown icon, "Tiers" … )}` between Rewards and Settings. Export `TierCatalog` from `pro/index.ts`.

- [ ] **Step 5: Type-check and lint**

Run: `pnpm --filter @yayloyalty/admin-page exec tsc --noEmit` and `pnpm --filter @yayloyalty/admin-page exec eslint src/lib/schema/tier.schema.ts src/lib/schema/settings.schema.ts src/lib/access.tsx src/pro/tiers src/pro/index.ts src/pages/tiers src/router.tsx src/components/layout/HeaderMenu.tsx`
Expected: tsc exits 0; eslint reports no error in these files.

- [ ] **Step 6: Verify in the admin (end to end, Requirements AC 7)**

Run: `./scripts/run.sh build pro`. Open: http://localhost:8889/wp-admin/admin.php?page=yayloyalty#/tiers
Action and expected:
1. The nav shows Tiers; the table lists Bronze 0 ×1.00, Silver 800 ×1.25, Gold 3,000 ×1.50, bonus 0, members 0, each with its swatch.
2. Add tier "Platinum", 6000, 1.75, 200, #E5E4E2 → the dialog closes, Platinum is the last row.
3. Add tier with name "gold" → the server's duplicate-name message is toasted and the dialog stays open with the values.
4. Edit Silver, threshold 7000 → Silver moves below Platinum.
5. Delete Silver → confirm → the row is gone.
6. Chrome console (Claude, through claude-in-chrome): no error or warning from `yayloyalty` during 1–5.
Restore the seed as in Task 3 Step 7.

- [ ] **Step 7: Commit**

Stage: the files in this task's Files list, by name.
Message: `feat(tiers): add the Tiers page with its dialogs`

### Task 5: Lite check, docs and backlog

**Done when:** a Lite release validates and the docs describe the new table.

**Files:**
- Modify: `docs/architecture.md`, `docs/backlog/2026-10-06-tiers-not-built.md`

**Interfaces:**
- Consumes: Tasks 1–4.
- Produces: nothing.

- [ ] **Step 1: Docs**

`docs/architecture.md` § Data and upgrades: one bullet saying the tiers table is seeded with three tiers only when it has no row at all, so deletions and edits survive a reinstall, and that Lite installs it. In the backlog item, tick "A tiers table with a rank exists, and a schema change or data migration ships it." and "Merchants can manage tiers on a Tiers page, behind a capability.", and update its `## Today` list to say the table, capability and page now exist.

- [ ] **Step 2: Verify the Lite build**

Run: `./scripts/run.sh release pro --no-prompt`, then `./scripts/run.sh release lite --no-prompt`, then `composer install`, then `git status --short yayloyalty.php readme.txt`.
Expected: both validations pass at version 1.0.0; the Lite zip has `includes/Database/Schema/TiersTable.php`, no `includes/Pro/`; `git status` prints nothing (the version files are untouched). If a release run rewrote them, `git restore yayloyalty.php readme.txt`.
Run: `./scripts/run.sh build lite`, reload http://localhost:8889/wp-admin/admin.php?page=yayloyalty#/tiers. Expected: no Tiers nav item, the 404 page. The tiers table still has its rows. Rebuild Pro afterwards: `./scripts/run.sh build pro`.

- [ ] **Step 3: Commit**

Stage: `docs/architecture.md`, `docs/backlog/2026-10-06-tiers-not-built.md`
Message: `docs(tiers): record the tiers table and tick the slice-1 backlog boxes`

## Planned test cases

Manual, derived from the acceptance criteria (no automated test is warranted, see **Tests:**):

| AC | Check |
|---|---|
| 1, 2 | Task 1 Step 5 |
| 3 | Task 2 Step 3 |
| 4 | Task 3 Steps 5–7, Task 1 Step 6 |
| 5 | Task 3 Step 7 |
| 6 | Task 5 Step 2 |
| 7 | Task 4 Step 6 |

## Chrome console check

Claude checks it with claude-in-chrome on the wp-env Pro site, page `http://localhost:8889/wp-admin/admin.php?page=yayloyalty#/tiers`, during Task 4 Step 6, and once more on `#/rewards` and `#/settings/general` to confirm the settings-schema change parses.

## Risks and open questions

- **No version bump.** The version stays at 1.0.0, against the spec's slice-1 "version bump". A site that already stamped `yayloyalty_db_version` 1.0.0 won't run `Installer::install()` on its own, so it gets no tiers table, columns or seed until the install re-runs (reset the option, or deactivate and reactivate the plugin). The release that ships tiers must bump the version, or existing sites miss the schema.
- **`POST /settings` drops tier keys.** Slice 2 must write them through `SettingsHelper::update_settings()` from `PUT /tiers/settings`, not through `/settings`.
- **Reward reference check** is reachable only through SQL in this slice, because `TIER` is still in `RewardConditionType::UNAVAILABLE` and the REST validator refuses it. Slice 3 makes it reachable from the editor.
- **No re-evaluation on delete.** Slice 2 adds it (`tier_deleted`). Until then nothing writes `tier_id`, so no customer can point at a deleted tier.
