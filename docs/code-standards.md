# YayLoyalty — Code Standards

Enforced by `phpcs.xml.dist` where a sniff exists; enforced by review where one does not. Rules marked **[REVIEW]** have no automated check — they are the ones most worth catching in a pull request.

Companion: [`data-model.md`](./data-model.md). Docs follow the same single-source rule as code: a fact lives in one doc and others link to it (owners listed in [`codebase-summary.md`](./codebase-summary.md#where-each-fact-lives)).

---

## 1. File naming

The one genuine conflict in this repo, resolved explicitly.

The autoloader in `yayloyalty.php` maps `YayLoyalty\Models\Customer` → `includes/Models/Customer.php`. **PHP class filenames must therefore be PascalCase**, matching the class name exactly. `WordPress.Files.FileName` (which wants `class-customer.php`) is excluded from the ruleset for this reason.

| Kind | Case | Example |
|---|---|---|
| PHP class file | PascalCase — **mandatory**, autoloader depends on it | `Models/LedgerEntry.php` |
| React component | PascalCase | `DashboardPage.tsx` |
| TS module | kebab + dot-namespace | `settings.api.ts`, `customer.queries.ts` |
| Docs, plans | kebab-case | `code-standards.md` |

## 2. PHP style

- 4-space indent. No tabs.
- Aligned `=>` in multi-line array literals.
- Spaces inside parentheses: `foo( $bar )`.
- `defined( 'ABSPATH' ) || exit;` at the top of **every** PHP file (except `includes/Utils/SingletonTrait.php`, `includes/Engine/Register/ScriptName.php`, `includes/Engine/Register/RegisterDev.php`).
- Return types on all public methods. PHP 7.4 supports them; use them.
- Docblocks on public methods where the signature is not self-explanatory.

## 3. Casing

| Element | Case |
|---|---|
| Methods, properties, variables | `snake_case` |
| Classes, interfaces, traits | PascalCase |
| Constants | `UPPER_SNAKE` |
| Hooks | `yayloyalty_` prefix |

Filters carry data. Actions cause side effects. Do not use a filter for its side effects.

## 4. Dates — always UTC

**Never use `current_time()` for anything that gets stored.** Always `Dates::now()`, which returns `gmdate( 'Y-m-d H:i:s' )`.

Every `DATETIME` in this plugin is UTC. Conversion to the site timezone happens only at the edges: admin form rendering, email templates, REST responses intended for display. A timestamp that enters the database in site time is a bug that surfaces months later as an off-by-hours expiry.

**Conversion pattern:** One conversion point per screen. Example: `CampaignEditorSections.tsx` (Schedule section) reads raw UTC `starts_at`/`ends_at` from the form state, converts to site timezone at render time via `splitStoredDateTime()` for the date/time inputs, and joins them back to UTC at the form boundary via `joinStoredDateTime()` before sending to the API. Helpers: `src/lib/helpers/format.helper.ts` → `splitStoredDateTime( storedUtcString, siteTimezoneOffset ) → { date, time }` and `joinStoredDateTime( date, time, siteTimezoneOffset ) → storedUtcString`. This keeps conversion logic in one place and prevents off-by-one-day errors (e.g., "ending 30 November" stores through midnight starting 1 December, not 23:59:59 on the 30th).

## 5. Money — bind as `%s`, never `%f`

Every `DECIMAL(19,4)` column is carried as a **string** end to end, and bound with `%s`.

Binding a decimal through `%f` converts it to a float, and floats cannot represent most decimal fractions exactly. The affected columns are precisely the ones where a rounding error is a financial defect: `rate_snapshot`, `base_value`, `value`, `lifetime_spend`, `threshold`, `tier_progress_value`, `basis_value`.

**[REVIEW]** In TypeScript, money is `z.string()`. Never `parseFloat` a money field; typing it as a string makes TS reject arithmetic on it.

## 6. Database access

**`$wpdb` appears only in `Repositories/` and `Database/`.** Nowhere else — not in controllers, services, models, helpers or engine classes.

- One repository per table. A repository never touches a second table. The one carve-out is **read-only cross-table aggregation** for the dashboard: `Repositories/DashboardRepository` and `Pro/Repositories/CampaignPointsRepository` are standalone classes (not `AbstractRepository`), read several tables, and write nothing — write ownership is unchanged.
- A repository **never opens a transaction**. Atomicity is a service concern.
- All SQL prepared. `search` terms go through `$wpdb->esc_like()` before `prepare()`.
- `orderby` is whitelisted per repository. Never interpolate a caller-supplied column name.
- **Never memoize `$wpdb->prefix` or a table name.** Multisite activation switches blogs mid-request; a cached prefix installs every blog's tables into the wrong one.

### Write methods throw

`$wpdb->insert()` returns `false` on failure — it does not throw. Because rollback in this codebase is exception-driven, a repository that quietly returns `false` will let a failed write commit.

Every repository write checks the return value and `$wpdb->last_error`, then throws `QueryException`.

### Index DDL grammar

Write indexes as `UNIQUE KEY name (cols)` or `KEY name (cols)`. Never bare `UNIQUE (cols)`, never `INDEX`.

`dbDelta` only recognises the `KEY` forms. A bare `UNIQUE (...)` succeeds on first install and then silently fails to normalise on every upgrade — the index quietly disappears on the exact code path nobody tests. Also: name every key, put two spaces after `PRIMARY KEY`, and state `ENGINE=InnoDB` explicitly (savepoints do not exist on MyISAM).

## 7. Transactions

**Only `Transaction::run()` opens or nests a transaction.** Raw `START TRANSACTION` / `COMMIT` / `ROLLBACK` are banned outside `Database/Transaction.php`. **[REVIEW]** — no sniff for this; catch it in review.

The connection is **not ours**. WooCommerce issues `START TRANSACTION` on the same `global $wpdb` (`wc_transaction_query`), and so do sibling Yay plugins installed alongside this one. Consequences:

- At depth 0 we may already be inside someone else's transaction. Issuing `START TRANSACTION` there **implicitly commits their in-flight work** — a loyalty plugin corrupting order data on the host's rollback path.
- `$wpdb` silently reconnects on error 2006 and resumes in autocommit, so a depth counter can outlive the transaction it counts.
- A deadlock (error 1213) rolls back everything and destroys every savepoint.

Therefore `Transaction::run()` probes for a foreign transaction and takes a savepoint rather than starting one, guards connection identity before every commit/rollback, and treats a failed `ROLLBACK TO SAVEPOINT` as fatal rather than continuing.

### How the foreign-transaction probe works, and why

The probe is a **savepoint round-trip**: take a savepoint, roll back to it, release it. Inside a transaction all three succeed. Outside one, MySQL releases the savepoint the moment the auto-committed statement creating it ends, so `ROLLBACK TO SAVEPOINT` fails with error 1305 — and that failure is the signal. Rolling back to a savepoint taken at the current point discards nothing.

Do not replace this with a server-variable check. Both obvious candidates were tried against the target server (MySQL 8.0.35) and **both failed**:

| Probe | Result |
|---|---|
| `SELECT @@in_transaction` | MariaDB-only. MySQL: *"Unknown system variable 'in_transaction'"* |
| `information_schema.INNODB_TRX` | Returned **zero rows** as `root` (which holds `PROCESS`) with an open transaction that had already inserted |

The `INNODB_TRX` failure is the dangerous one: it returns `'0'` rather than erroring, so a capability check that only asks *"did the query run?"* accepts it and then reports "no transaction" forever. The first implementation of `Transaction` did exactly that and **committed WooCommerce's in-flight work** in testing — the bug the class exists to prevent, reproduced by the class itself.

Verified 2026-09-09: with a foreign transaction open, `Transaction::run()` takes a savepoint, and a subsequent foreign `ROLLBACK` correctly discards both the foreign row and ours.

**Lock order is always `customers` before `ledger`.** Never the reverse — interleaved operations on two users deadlock otherwise.

## 8. Layers

| Layer | Owns | Must not |
|---|---|---|
| `Models/` | Typed data, casts, REST shape | Touch the database |
| `Repositories/` | All SQL for exactly one table | Open transactions; touch a second table¹ |
| `Services/` | Invariants, transactions, cross-table writes | Contain SQL |
| `Controllers/` | HTTP shape, schema, permissions | Contain SQL or business invariants |

¹ Exception: `DashboardRepository` and `Pro/Repositories/CampaignPointsRepository` are read-only cross-table classes (see §6 carve-out).

**Definition tables get no service.** A service that only forwards CRUD to one repository is ceremony. Add one when an operation must touch a second table atomically — that is when it earns its existence.

### `Utils/` vs `Helpers/`

Two directories that look interchangeable and are not.

| | Holds | Test |
|---|---|---|
| `Utils/` | Stateless, all-static, no plugin state — `Dates`, `Json`, `Log`, `Table`, `Sql`, `ConditionValueRules`, `PointsMath` | Could you lift it into another plugin by changing one constant? |
| `Helpers/` | Glue that knows this plugin's options, shapes and screens — `SettingsHelper`, `LocalizeHelper` | Does it read plugin state or build a payload for our UI? |

A util that starts reading an option belongs in `Helpers/`. Neither directory is a home for business rules: those live in `Models/` and `Services/`.

### Points arithmetic is shared with the SPA through `PointsMath`

`Utils\PointsMath` holds the arithmetic every layer needs and none may re-derive: `round_points` (once, at order level, half away from zero; unknown mode rounds down), `pct_delta` (|previous| denominator), `pct_of`, `points_to_currency` (rounds to the store currency's decimals, which the caller passes — `wc_get_price_decimals()` in PHP, `currencyDecimals()` in the SPA) — a zero denominator yields `null`, never 0. `apps/admin/src/lib/points-math/points-math.ts` is its camelCase mirror.

**One points → money label.** The SPA shows points as money only through `formatPointsAsMoney()` (`lib/helpers/points.helper.ts`): `pointsToCurrency()` at the store's decimals, then `parseWPCurrency()`. `parseWPCurrency()` formats and never rounds a points conversion itself — its `toFixed()` rounds the binary float, which lands a half-cent the wrong way (1.005 → 1.00).

**The SPA formats; it does not compute.** When a screen must compute a points figure (an editor preview, a delta, a conversion), it calls `lib/points-math`. Every function has a same-named method in `PointsMath`, and every rule has a key in `tests/fixtures/points-math-vectors.json`, which both `composer test` (`PointsMathVectorsTest`) and `pnpm test` (Vitest) iterate. Adding a primitive on one side without the other fails that side's suite; PHP is the source of truth for expected values, and only inputs every supported PHP release rounds identically belong in the file (PHP 8.4 dropped `round()`'s pre-rounding, so `0.49999999999999994`-style inputs stay out).

Future engines follow the same shape: `Engine/Redemption/` reuses `PointsMath::round_points` and adds its string-money conversion via scaled integers (no bcmath dependency). Money at the boundary remains a decimal string per §5.

### Logging

**`Utils\Log`, never bare `error_log()`.** It writes to WooCommerce → Status → Logs when WooCommerce has loaded, falling back to `error_log()` for activation and early bootstrap, so a merchant and a support agent can actually read what happened.

Pick the level by asking who needs the line, not how noisy it is:

| Level | Written | For |
|---|---|---|
| `Log::error()` | always | A permanent fault: a failed install, an unhandled exception |
| `Log::warning()` | always | Survivable misconfiguration — a filter subscriber that had to be ignored |
| `Log::debug()` | WP_DEBUG only | Diagnostics on a hot path, safe to be noisy |

**Do not gate an error behind `WP_DEBUG`.** Production has it off by definition, which is the one moment the record matters. The 500 handler hands the user a correlation id to quote at support; when that line is only written in debug mode, the id refers to nothing.

### Table names come from `Utils\Table`

`Table::name( 'customers' )` — the schema class that CREATEs a table and the repository that reads it must derive the name from the same place, or the repository queries a table the installer never made and the symptom is "no rows", not an error. **[REVIEW]** Never memoize the result, and never cache `$wpdb->prefix`: multisite activation loops `switch_to_blog()`.

### Instantiation: services and repositories are plain objects

`Utils/SingletonTrait` is for bootstrap classes only (`YayLoyalty`, `RestAPI`, `Settings`, `RegisterFacade`, `RegisterProd`, `RegisterDev`). **Models, Repositories and Services never use it**, for two reasons:

- `get_instance( ...$args )` silently ignores `$args` after the first call, so constructor injection and the trait are incompatible — the second caller silently gets the first caller's dependencies.
- `get_instance()` is `public static`, so it hands global access to anything that wants it.

**[REVIEW]** `LedgerService` is the only class that receives both `LedgerRepository` and `CustomerRepository`, and it is constructed in exactly four named places: `Engine\RestAPI::controllers()` (request-time REST), `Database\InitYayLoyaltyCustomer::maybe_create()` (one-time install bootstrap, outside any request — needs real ledger rows so its starter customers don't break `balance = SUM(ledger.points)`), `Engine\Earning\EarningService::build()` (the award path's composition root; a stub until F01-4) and `Engine\Redemption\RedemptionService::build()` (the commit path's composition root; a stub until F08). PHP has no package-private, so this is a convention plus these four named construction sites plus this review rule — **not** something the language enforces. Do not describe it as "structural"; a fifth site needs the same review, not a silent `new LedgerService(...)`.

**[REVIEW]** `Services\CustomerActivityService` is a second, narrower exception: it holds `CustomerRepository` and `LedgerRepository` alongside `CustomerMetaRepository`, justified only because both are READ-ONLY there (membership check, balance at deletion, kept-entries count) — the sole write target is `customer_meta`. It is constructed in exactly one place, `Engine\CustomerActivityListener::service()`. Any further class needing more than one repository needs this same review, not a silent multi-repository constructor.

## 9. REST

- Namespace `yayloyalty/v1`. Routes registered in `register_routes()`, never in a constructor.
- Every resource declares `get_item_schema()`, with `context` (`view`/`edit`) and `readonly` on every property.
- **`readonly` properties are stripped from write args, not merely ignored.** Build write args by filtering the schema. Passing the schema whole makes every endpoint mass-assignable: a `PUT` carrying `is_builtin` or `actor_id` would otherwise defeat the guards that depend on them.
- Server-owned and always readonly: `id`, `created_at`, `updated_at`, `is_builtin`, `balance_after`, `actor_id`, and every aggregate on `customers`.
- `actor_id` is always `get_current_user_id()`. Never read it from a request body.
- ID route params are `(?P<id>[\d]+)` with a `validate_callback` confirming the record exists. For a user id that is `AbstractRestController::member_id_param()` on loyalty-member reads (live WP user, or a deleted one whose customers row survives) and `user_id_param()` everywhere else (writes, and ids that are not "a member", like Pro's campaign preview).
- Payload fields are `snake_case`. The pagination envelope is camelCase (`currentPage`, `totalPage`, `totalItems`, `perPage`, `data`), matching `PaginatedResponse<T>` in the admin app — one envelope, not two. Query params follow WP REST core spelling (`page`, `per_page`, `orderby`, `order`, `balance_range`). Nothing else on the wire is camelCase — including `suggestions[].key` and saved-segment `filters`, which use the query-param names. In the admin app, everything TS-side is camelCase (`perPage`, `orderBy`, `balanceRange`, `RowFilters`), and the `lib/api/*.api.ts` (or `pro/**/*.api.ts`) request builder is the only place the two spellings meet — callers never write a snake_case key. The `wp_localize_script` bootstrap (`LocalizeHelper`, `window.yayLoyaltyMeta` / `yayLoyaltyAdmin`) is a separate boundary: its top-level keys follow the YayCommerce house camelCase shape (`wpMeta.siteUrl`, `isAdministrator`), `wcMeta.currency_data.*` mirrors WooCommerce core's own keys, and any REST resource embedded in it (`settings`, `segments`) keeps its REST snake_case shape.
- Error responses never echo `$exception->getMessage()`; log the detail, return a generic message with a correlation id.
- Every admin route sets `'permission_callback' => $this->requires( Capabilities::X )` — one of the seven in `Access\Capabilities` (`view`, `manage_points`, `manage_tiers`, `manage_campaigns`, `manage_rewards`, `manage_settings`, `run_tools`). Never `__return_true` on an admin route. Storefront / Store API routes are gated by session identity, not by these caps.
- Capabilities are checked at the HTTP boundary only (menu, page render, `permission_callback`). Services, repositories and the earning engine take an `actor_id` and never call `current_user_can()` / `get_current_user_id()` — a cap check inside `LedgerService::record()` breaks every cron, order-hook and WP-CLI path. `grep -r current_user_can includes/Services` must stay empty.
- The adjustment ceiling (`settings.advanced.adjustment_ceiling`, 0 = unlimited) is a policy value, not a capability. `assert_ceiling()` runs inside the handler after the cap check; administrators bypass it.
- The SPA hides and disables (`<Can>` in `lib/access.tsx`) and is never a gate. A 403 from the server is the real refusal; `api.ts` turns `yayloyalty_forbidden` / `yayloyalty_adjustment_ceiling` into one toast.

### Dev fixtures and swap points

Two conventions for fixture data coexist:

**Shared schema/query-arg classes** — `Controllers/Schemas/{CustomerItemSchema,LedgerQueryArgs,ActivityQueryArgs}.php` live in one place so a contract change is made once. The **customer drawer has no fixtures and serves real data only**; these classes are used by its real controller paths and by tests. Dashboard and campaign endpoints use fixtures alongside their real paths.

**Dev-only fixture gate** — the `WP_DEBUG`-gated fixture (`DashboardFixture`) is reached via a `scenario` query arg that is registered only under `WP_DEBUG` and re-checked in the handler — the real path (no arg) always returns real data. A "swap point" fixture (`CampaignRestController::dummy_fixture()`, `CampaignPerformanceFixture`) is never optional; it ships in the release zip. Mark swap-point fixtures in controller docblocks and list them in `project-roadmap.md` Known Gaps.

## 10. PHP 7.4 pseudo-enums

No native enums. Closed vocabularies are `final class` + `const` + `all()` + `is_valid()` + `assert()`.

**When two vocabularies' processing methods are byte-identical, share the methods via a trait — never `extends`.** `CampaignConditionType` and `RewardConditionType` both `use Enums\ConditionTypeMethods` for `all()`/`all_known()`/`is_valid()`/`is_known()`/`operators_for()`/`assert()`, built on a `schema()` the trait declares `abstract` and each class still implements itself. Both stay `final class` with no `extends` and no "is-a" relationship — a trait, unlike a base class, doesn't turn two independent vocabularies into a hierarchy. This is the exception, not a new default: `Status`, `CampaignTrigger` and `RewardType` remain plain, and a trait is worth reaching for only when the bodies are actually identical, not merely similar.

**`assert()` runs at the service boundary, not in the repository.** With no enum type there is no compile-time check, so if the service does not assert, an invalid value surfaces only as a DB error — far from the caller, with a message that names a column instead of the mistake.

Fact-table entities are immutable **by convention**: private properties, getters only, no setters, `with_*()` returns a clone. `readonly` does not exist in 7.4, so the convention is the enforcement. Say so in each such class's docblock.

## 11. Status and liveness

`status` stores merchant **intent** only: `active`, `paused`, `draft`, `archived`. Never `scheduled` or `ended` — those are *derived* labels, produced at read time by `Status::resolve_display()` from an `active` status plus the date window, never written to the column.

Whether something is live *right now* is computed at read time from `status` plus `starts_at`/`ends_at`, evaluated against the **event's** timestamp — an order's award time, not the current request time. A late-processed order must be scored under the rules that were live when it happened.

`archived` is a terminal merchant choice: an archived campaign is kept for history and never fires. Built-in campaigns cannot be archived (`Campaign::set_status()` throws; `CampaignRestController::update_item()` answers 403 first), because the Free UI and the earning engine both assume they exist.

**No cron job may flip a status.** If you find yourself writing one, the model is being worked around.

## 12. JSON columns

Stored as `LONGTEXT`, validated in PHP. Every blob carries a `schema_version` key so formats can evolve without a table migration.

Decoding is **total**: malformed stored JSON returns the default and logs. It never throws. A corrupt `config` blob should degrade one campaign, not white-screen the admin.

### Maps and lists take different methods

`Json::encode()` / `decode()` are for **maps** — `campaigns.config`. `Json::encode_list()` / `decode_list()` are for **lists** — `campaigns.conditions`, a list of `{type, operator, value}` triples.

Stamping `schema_version` into a list turns it into an object: `[{…},{…}]` comes back as `{"0":…,"1":…,"schema_version":1}`, and an empty list comes back as `{"schema_version":1}` — not empty. Anything iterating it then finds a stray integer where it expected a record, and anything asking "are there any conditions?" gets the wrong answer. `encode_list()` wraps the payload instead:

```json
{"schema_version": 1, "items": [ … ]}
```

`decode_list()` unwraps it and also accepts a bare array and the corrupted `{"0":…}` shape, so no stored row is lost. **[REVIEW]** — pick the pair that matches the column's shape; nothing enforces it.

The vocabulary and per-type rules for `campaigns.conditions` live in `Enums\CampaignConditionType`; `Models\CampaignConditionSet` validates and normalises a set on save (`CampaignRestController` runs `Campaign::conditions_are_valid()` before writing; `set_conditions()` normalises), mirroring the `config` pair. `rewards.conditions` is the same pattern with `Enums\RewardConditionType` / `Models\RewardConditionSet` / `Models\RewardCondition`. Named `Campaign*` and `Reward*`, never a bare `Condition*` — the two vocabularies must not collide, and each is free to grow its own types independently. Neither `extends` the other, and there is no shared base class — but two things are genuinely identical between them, so they are shared rather than duplicated: `Enums\ConditionTypeMethods` is a trait supplying `all()`/`all_known()`/`is_valid()`/`is_known()`/`operators_for()`/`assert()`, used by both `CampaignConditionType` and `RewardConditionType` (each still declares its own `schema()` and `UNAVAILABLE` — that's where they diverge); `Utils\ConditionValueRules` is the value-shape checks (`int`, `int_list`, `string_list`, `between` pairs, list caps) used by both `CampaignConditionSet` and `RewardConditionSet`. Both exist so a fix to that logic is made once, not twice — not to merge the vocabularies themselves. See `data-model/campaigns.md` and `data-model/rewards.md` → `conditions`.

## 13. Pro extension seams

YayLoyalty ships as two builds from one repo: **Pro** (the full tree) and **Lite** (built by stripping). Code and user-facing copy both say **Pro** — one word, no translation layer.

### The one rule that makes the split cheap

**Lite contains exactly one reference to Pro** — a guarded `class_exists` in `YayLoyalty::initialize()`. Everything else Pro does, it does by attaching to a filter.

That is why the Lite build is a directory deletion rather than surgery on shared files. Contrast `yay-reviews`, whose bootstrap `use`s its Premium classes directly and therefore needs five `@premium-start` marker pairs in one file, plus a validation script to catch the times stripping goes wrong.

### The seams

Every hook, where it fires and who subscribes: [`system-architecture.md` → Internal Extension Seams](./system-architecture.md#internal-extension-seams-filters).

These are **internal seams for first-party Pro code**, not a documented third-party API. They may change.

`yayloyalty_rest_item_schema` passes `$rest_base`, which is **not** unique: a Pro controller owning a sub-route of an existing resource shares that resource's base. Match on the schema's `title` as well, or a filter meant for one route silently rewrites another's schema.

### Markers are a last resort, not a mechanism

There are **no** `@pro-start` / `@pro-end` markers in this codebase, and adding one requires answering: *why won't a filter do?*

A marker is a symptom of a missing seam. A mismatched pair silently deletes the wrong lines or leaves a dangling import, and the failure only surfaces when the Lite zip fatals at runtime. If a marker is ever genuinely needed, the stripping script and its validation land in the same change — never after.

### Strip behaviour, never vocabulary

**Pro enum values, Pro config keys and Pro table schemas stay in the Lite build.**

A site that downgrades from Pro to Lite must not have its data treated as corrupt. Concretely:

- `CampaignTrigger::all()` is filtered (what this build can *fire*); `all_known()` is **not** (what the schema *recognises*). The asymmetry is the mechanism. `RewardType` follows the same shape: `all()` (offerable) vs `all_known()` (recognised).
- `Campaign::config_whitelist()` keeps Pro keys such as `max_points_per_order` in Lite. Do not "tidy" them out — that would destroy a merchant's configuration on a temporary downgrade. `Reward::config_whitelist()` is the same mechanism for reward types.
- `Campaign::validate_config()` **preserves config wholesale for a trigger this build has no whitelist for**, rather than filtering it against an empty list. A whitelist covers the keys of triggers this build knows; a Pro-only trigger is unknown to Lite entirely, and unknown must never mean delete. Without this, the first re-save of a downgraded `birthday` campaign would empty it, and a later upgrade would restore nothing.
- Lite's `conditions_match()` returns **true** when it cannot evaluate. Failing open keeps a downgraded site awarding slightly too broadly; failing closed would silently disable every conditional campaign.

Pro's evaluator is stricter in the opposite direction — an unknown condition type **fails**, because Pro can evaluate and an unrecognised type is a real error.

### Two directories named "pro" in the SPA, with opposite fates

| Directory | Lite build | Contents |
|---|---|---|
| `apps/admin/src/pro/` | **deleted** | actual pro features |
| `apps/admin/src/components/pro/` | **kept** | `ProGate`, badge, overlay — the upsell UI Lite must render |

Confusing them either ships pro features in Lite or removes Lite's ability to sell the upgrade.

**`ProGate` alone does not keep Pro code out of the Lite bundle.** JSX evaluates its children eagerly, so `<ProGate><ProThing /></ProGate>` references `ProThing` at runtime and drags all of `@/pro` — sentinel included — into Lite. Guard the child with the constant as well:

```tsx
<ProGate>{IS_PRO_BUILD && thing ? <ProThing thing={thing} /> : null}</ProGate>
```

`ProGate` decides what a user sees; `IS_PRO_BUILD` decides what ships. Both are needed, and `scripts/validate-build.php` fails the release when only the first is present.

### Gating is build-time only

`IS_PRO_BUILD` is `import.meta.env.VITE_IS_PRO` (environment variable set by `vite.config.ts`), not a Vite `define`. It is the single gate. **No pro flag is localized to the SPA.** One zip carries both the PHP and the JS bundle, so build type is never ambiguous and there is nothing for a runtime flag to disambiguate. Licence state is deliberately not an input: an expired licence stops updates, not features.

`YayLoyalty::is_pro()` exists for server-side decisions only and is never sent to the browser.

### The split is enforced, not trusted

A stripping build fails silently by nature, so every release is validated against the produced zip and a failure aborts it. `scripts/validate-build.php` checks the sentinel in both directions, confines Lite's `Pro` references to the one guarded seam, and boots the package inside WordPress. See `deployment-guide.md`.

### The schema filter cannot reopen mass assignment

`get_item_schema()` is **final**; subclasses override `define_item_schema()`. The filter is applied centrally so no subclass can bypass it, and `is_readonly_property()` checks `ALWAYS_READONLY` first — so `id`, `created_at`, `updated_at`, `is_builtin`, `balance_after` and `actor_id` stay stripped from write args regardless of what any subscriber returns.

## 14. Running the linter and tests

The one place suite counts and coverage are written down; other docs link here.

```bash
composer lint                          # phpcs (WPCS + PHPCompatibility, 7.4 floor)
composer lint:fix                      # phpcbf
./scripts/check-docs.sh                # docs stay split and single-homed: cited paths exist, links resolve, sizes, test counts only here
composer test                          # phpunit over tests/unit/ — 254 tests, 62 incomplete by design (the earning-split specification in `tests/unit/Earning/`, `tests/unit/Services/`, `tests/unit/ContextFactoryTest.php` and the redemption-split specification in `tests/unit/Redemption/`): points engine (BaseCalculator, PurchaseHandler, PointsEngine), earning orchestration scaffold (IdempotencyKey, Outcome, EarningService), the ledger's duplicate-key guard (QueryException), reward engine + clamp (Clamp, Result, Registry, RewardEngine), redemption orchestration scaffold (Quoter real; service, writer, coupon, session, adapters as specification), dashboard computation (PeriodWindow, DashboardMath, AnomalyDetector, DashboardStats, DashboardNotices), PointsMath + shared vectors, campaign config/conditions validation, drawer query-arg groupings + item formatter (`tests/unit/Controllers/`), design settings coercion and merge (`tests/unit/Helpers/DesignHelperTest.php`), settings boolean coercion (`tests/unit/Helpers/SettingsHelperTest.php`)
composer test:integration              # phpunit over tests/integration/ against a real WordPress and MySQL — 31 tests: the ledger's UNIQUE guard, the balance_after chain and non-idempotent manual rows, adjust_lifetime in both directions, which user ids the customer routes accept (deleted members on the drawer reads, not on adjust), and the campaigns route refusing to archive a built-in. Plugin tables go under a scratch `ylit_` prefix and are dropped afterwards, so the site's own data is never read or written. Needs a WordPress install with the plugin active; see `deployment-guide.md`
pnpm --filter @yayloyalty/admin-page lint   # eslint + prettier on TypeScript/React
pnpm --filter @yayloyalty/admin-page test   # vitest over apps/admin/tests/ — 126 tests: lib/points-math against tests/fixtures/points-math-vectors.json (same file phpunit runs), money formatting, activity rows, adjust-dialog form state, the chunked bulk-write helper, the design schema against the PHP defaults
./scripts/run.sh build [pro|lite]      # Full build — currently fails at tsc --noEmit due to 3 pre-existing missing-dependency type errors
./scripts/run.sh release lite --no-prompt   # builds and validates a package end to end
```

`tests/bootstrap.php` shims `add_filter` / `apply_filters` / `do_action` so pure code that goes through hooks can be tested in milliseconds. Anything that needs `$wpdb` or WooCommerce is not a unit test: it belongs in `tests/integration/`, or, for real `WC_Order` flows the integration suite does not cover yet, is exercised through WP-CLI.

### The points engine is pure

`Engine\Points\*` reads campaigns through `CampaignRepository` and nothing else — no `$wpdb`, no WooCommerce globals, no current user, no `time()`, no capability checks. Everything about the event arrives on the `Context`, built by `ContextFactory` (the one class allowed to touch WooCommerce) or by hand in a test. The engine takes no timestamp argument: liveness is scored at `Context::occurred_at`, the event's time, never `now()` (`ContextFactory::from_order()` throws when the order has no creation date). Config gates (`min_subtotal`, a paused default under a multiplier) are **eligibility**: the engine falls through to the next campaign rather than earning zero or throwing.

Every exclusion in `phpcs.xml.dist` carries a one-line reason. **An exclusion without a reason is a bug** — it means a sniff was disabled to make the build green rather than because it was wrong.

`Engine\Rewards\*` follows the same rule; its `Context` carries the balance as an input so the engine never reads the ledger, and there is no `ContextFactory` for it yet.

### Known blind spot: the linter cannot see PHP 8 syntax **[REVIEW]**

`phpcs.xml.dist` sets `testVersion` to `7.4-` and this is wired correctly — PHPCompatibility does flag constructs newer than the floor *that it knows about*. But `phpcompatibility/php-compatibility` is pinned at 9.3.5, the last stable release, which **predates PHP 8.0 and ships no PHP 8 sniffs**.

Verified 2026-09-09: a file using the nullsafe operator `?->` passes `composer lint` clean.

So the following will **not** be caught by tooling, and are the exact features the PHP 7.4 floor exists to prevent:

- `enum` declarations
- `match` expressions
- constructor property promotion
- `readonly` properties
- nullsafe operator `?->`
- named arguments
- union types

**These are a review responsibility.** Local development runs PHP 8.x, so this code will run fine on the developer's machine and fatal on a 7.4 host. When reviewing, read for these constructs deliberately — the linter passing is not evidence of their absence.

Closing this gap later means either pinning `phpcompatibility/php-compatibility` to `dev-develop` (which has PHP 8.x sniffs) or adding PHPStan with `phpVersion: 70400`. Deliberately deferred; revisit when CI is set up.

---

## 15. Comments **[REVIEW]**

Two kinds of comment, two rules.

**Docblocks** (class, public/protected method, constant, hook) start with one line saying *what this does for its caller* — the contract an IDE shows before the body is read. Keep it unless the name and signature already say it (`get_id()`: cut; `award()` "points for an order, idempotent per reference": keep). After that line, only the *why* and the `@param` / `@return` / `@throws` a caller relies on — typed signatures already say the rest. A docblock never opens with a caveat: if there is a why, the purpose line comes first.

**Inline comments** (inside a body) default to none. **Code shows *how*; comment only to carry *why* — a non-obvious constraint, deliberate deviation, gotcha, or workaround.** Keep the one fact a reader needs at that line, in the fewest words; two lines is the usual ceiling, longer only for a WordPress or WooCommerce behaviour the code works around. Never narrate the code ("loop over campaigns"), narrate the change ("fixed X", "as requested"), or mark block ends.

"Restates the name" means the line adds nothing beyond the identifier — no caller context, trigger, unit, or side effect. A summary that adds one of those is a contract, not a restatement.

When a comment does exist, it states only what is true of the code directly beneath it — the invariant it protects and what breaks without it. Never what other code does, what the code used to do, or what it will do. Cross-cutting rationale lives in `docs/system-architecture.md` or `docs/data-model.md`; the comment points at it in one line.

A comment survives review when all four hold:

1. **Owns its claim** — describes only the code beneath it, not the SPA, a controller, cron, or a commit.
2. **States an invariant or a why** — what this code guarantees or guards, and what breaks without it.
3. **Not derivable** — reading the code would not tell you this. Library semantics (TanStack, react-hook-form, WooCommerce, WP core) are not ours to document unless the code works around them.
4. **Points, doesn't repeat** — if a doc has the full rationale, the comment is one line plus a pointer.

Cut: other-module behaviour · narrated history · restated code or library defaults · rationale duplicated from `docs/` · future tense and promises · performance folklore · design or plan IDs.

Keep: the docblock purpose line · guards whose removal is a silent bug · ordering constraints · WordPress/WooCommerce behaviours the code works around · every `@throws` a caller depends on · real `TODO:` markers (one line, what and why not now) · `translators:` and `phpcs:ignore` directives.

The review question is: *would deleting this comment make the next bug harder to avoid, or the next reader unable to tell what this is for?* If neither, delete it.

## 16. Single source of truth

**Every rule has one owner; everything else calls it or mirrors it from the table below.**

Two places that decide the same thing leave the reader unable to tell which one wins, and they drift: one gets fixed, the other keeps shipping the old rule. § 8's *Points arithmetic is shared with the SPA through `PointsMath`* and *Table names come from `Utils\Table`* are this rule applied to money math and table names.

| Rule | Owner | Allowed mirrors |
|---|---|---|
| Option names, meta keys, table prefix | Constants on the owning class (`SettingsHelper::OPTION_SETTINGS`, `DesignHelper::OPTION_DESIGN`, `Installer::OPTION_DB_VERSION`, …); table names through `Utils\Table::name()` | — |
| Settings keys, types, defaults | `Helpers\SettingsHelper` (`get_default_settings()`, `schema()`) | `apps/admin/src/lib/schema/settings.schema.ts` |
| Design tokens, icon lists, bounds | `Helpers\DesignSchema` (`get_default_design()`, `schema()`, `LAUNCHER_ICONS`, `BADGE_ICONS`) | `apps/admin/src/lib/schema/design.schema.ts`; `apps/admin/tests/fixtures/design-defaults.json` (generated by `scripts/dump-design-defaults.php`) |
| Points arithmetic, rounding | `Utils\PointsMath` | `apps/admin/src/lib/points-math/points-math.ts` (agreement enforced by `tests/fixtures/points-math-vectors.json` in both suites) |
| Ledger types, statuses, actor kinds, activity events, campaign triggers | `includes/Enums/*`; Pro triggers in `YayLoyaltyPro::ADDED_TRIGGERS` | `LEDGER_TYPES`, `CAMPAIGN_STATUSES`, `LITE_CAMPAIGN_TRIGGERS`, `PRO_CAMPAIGN_TRIGGERS`, `ALL_KNOWN_CAMPAIGN_TRIGGERS`, `CAMPAIGN_TRIGGERS`, `ACTIVITY_EVENT_TYPES`, `ACTIVITY_ACTOR_KINDS` in `apps/admin/src/lib/schema/` |
| Campaign display status (scheduled, ended) | `Enums\Status::resolve_display()` | `CAMPAIGN_DISPLAY_STATUSES`, `resolveCampaignDisplayStatus()` in `campaign.schema.ts` |
| Campaign config fields, purchase mode | `Models\Campaign::config_schema()`, `PurchaseHandler::is_multiplier()` | `CAMPAIGN_CONFIG_FIELDS` (UI metadata), `resolvePurchaseMode()` in `campaign.schema.ts` |
| Condition types, operators, value rules | `Enums\CampaignConditionType`, `Enums\RewardConditionType` (shared `ConditionTypeMethods`), `Utils\ConditionValueRules` | `CAMPAIGN_CONDITION_SCHEMA` in `campaign.schema.ts` (UI metadata, not a validator) |
| Drawer filter vocabularies | `Controllers\Schemas\LedgerQueryArgs::filter_types()`, `ActivityQueryArgs::GROUPS` | `LEDGER_FILTER_TYPES`, `ACTIVITY_GROUPS` |
| Capabilities | `Access\Capabilities` | `CAP` in `apps/admin/src/lib/access.tsx` |
| Permission error codes | `AbstractRestController::forbidden()`, `assert_ceiling()` | `FORBIDDEN_ERROR_CODES` in `apps/admin/src/lib/access.tsx` |
| REST wire shapes | PHP `to_array()` and the controller's item schema | `*.schema.ts` in `apps/admin/src/lib/schema/`; Pro response schemas in `apps/admin/src/pro/**/*.api.ts` |
| Points shown as money, bulk writes | `formatPointsAsMoney()` in `lib/helpers/points.helper.ts`, `settleInChunks()` in `lib/helpers/settle-in-chunks.helper.ts` | — |
| Dev `?scenario=` fixtures | `Controllers\DashboardFixture` (PHP), `hooks/useDevScenario.ts` (SPA) | — |
| Cross-cutting rationale | `docs/system-architecture.md` | a code comment of one or two lines plus a pointer |

A mirror exists only where the language boundary forces one; it names its owner in a comment and must agree with it. Adding a mirror means adding it to this table with the reason. `/source-of-truth-check` reads this table to flag copies in a diff.

## 17. Readable code **[REVIEW]**

Body-level rules for new and touched code; the caps are soft and existing code is not refactored to meet them.

- **Return early; nest at most two levels.** Each level is one more condition the reader holds in their head to know when a line runs.
- **One job per function.** If its purpose line needs "and", split it; past ~40 lines, check whether it has. A function doing two jobs can't be reused for either.
- **Names carry kind and unit.** Booleans read `is_`/`has_`/`can_`, quantities name their unit (`_seconds`, `_bytes`), no abbreviations beyond `id` and `url`. A bare `timeout` or `flag` sends the reader to the call site to find out.
- **Boring over clever.** No nested ternaries, at most two chained `map`/`filter`/`reduce`, a string function over a regex when it is enough. Clever code is read many more times than it is written.
