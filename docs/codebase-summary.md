# YayLoyalty — Codebase Summary

Comprehensive guide to the YayLoyalty plugin repository structure, entry points, and request flows.

---

## Directory Structure

| Path | Contents | Purpose |
|---|---|---|
| **`yayloyalty.php`** | Plugin header, constants, autoloader, hooks | Single entry point; defines paths and constants; triggers `plugins_loaded` hook |
| **`includes/`** | All backend PHP code, logically layered | See Layers section below |
| **`apps/admin/`** | React 18 + Vite 6 admin SPA | Built to `assets/dist/admin/js/main.js` |
| **`scripts/`** | `run.sh` (dev/build/release), `validate-build.php` | Build pipeline and validation |
| **`docs/`** | Architecture, standards, model reference, deployment | Living documentation |
| **`release/`** | Packaged zips after `./scripts/run.sh release` | Output only; not in version control |
| **`vendor-prefixed/`** | Scoped Composer deps via php-scoper | Admin shell (not user code) |

---

## PHP Layers (includes/)

### Entry & Bootstrap

| File | Role |
|---|---|
| `YayLoyalty.php` | Singleton initializer; constructs RestAPI, RegisterFacade, Settings; boots Pro via guarded `class_exists` |
| `Engine/RestAPI.php` | Wires REST routes; collects controllers; applies `yayloyalty_rest_controllers` filter |
| `Engine/Register/RegisterProd.php` | Registers scripts/styles for production |
| `Engine/Register/RegisterDev.php` | Registers dev server origin for HMR (Vite) |
| `Engine/Register/RegisterFacade.php` | Facade that selects RegisterProd or RegisterDev |
| `Engine/Admin/Settings.php` | Loads admin menu items and pages |
| `Engine/ActDeact.php` | Activation, deactivation, multisite hooks, installer trigger |
| `Database/Installer.php` | Schema versioning, migrations, `dbDelta` application |
| `I18n.php` | Text domain loading |

### Models & Data (3 tables implemented)

| File | Table | Role |
|---|---|---|
| `Models/AbstractModel.php` | — | Base class; `to_array()`, REST shape cast methods |
| `Models/Customer.php` | `yayloyalty_customers` | Cached aggregates (balance, lifetime earned, etc.) |
| `Models/LedgerEntry.php` | `yayloyalty_ledger` | Immutable fact row; `points` delta, `balance_after`, type, source |
| `Models/Campaign.php` | `yayloyalty_campaigns` | Earning rule; trigger, config (JSON), conditions (JSON) |

### Enums (PHP 7.4 Pseudo-enums)

All in `Enums/` as `final class` + `const` + `all()` / `all_known()` + `is_valid()` + `assert()`:

- **`CampaignTrigger`** — `purchase`, `signup`, `review` (Lite); plus `birthday`, `first_order`, `order_count`, `spend_milestone` (Pro). `all()` is filtered (fireable); `all_known()` is not (recognised).
- **`LedgerType`** — `earn`, `redeem`, `refund_earn`, `refund_redeem`, `expire`, `adjust`.
- **`LedgerSource`** — `order`, `signup`, `review`, `referral`, `birthday`, `milestone`, `social`, `renewal`, `manual`, `import`, `expiry`, `system`.
- **`ReferenceType`** — `order`, `refund`, `comment`, `redemption`, `referral`, `user`, `birthday`, `import`.
- **`Status`** — `active`, `paused`, `draft`.

### Repositories (SQL layer, one per table)

All SQL access **must** go through these. Throw `QueryException` on write failure; never return `false`.

| Class | Table | Methods |
|---|---|---|
| `Repositories/AbstractRepository.php` | — | Base: `table()` via `Utils\Table`, `find()`, `insert()`, `update()`, `delete()`, `paginate()`; all SQL prepared; write failures throw `QueryException` |
| `Repositories/CustomerRepository.php` | `customers` | `find_by_user_id()`, `ensure_row()` (INSERT … ON DUPLICATE KEY UPDATE), `lock_and_get()` (SELECT … FOR UPDATE), `apply_delta()` |
| `Repositories/LedgerRepository.php` | `ledger` | `find_by_reference()`, `paginate_for_user()`, `sum_points_for_user()`, `latest_for_user()` |
| `Repositories/CampaignRepository.php` | `campaigns` | `find_campaign()`, `find_by_slug()`, `find_live_by_trigger()`, `paginate_filtered()`, `seed_builtin()` |

### Services (Business logic, transactions)

| Class | Role |
|---|---|
| `Services/LedgerService.php` | **SOLE writer** of ledger. `record()` creates ledger row, updates customer aggregates, fires `yayloyalty_ledger_recorded`, all in `Transaction::run()`. Encapsulates idempotency and lock order. |

**Design rule:** Fact tables get Entity + Repository + Service. Definition tables (campaigns) get Entity + Repository only — no service.

### Controllers (REST layer)

All `namespace YayLoyalty\Controllers` → routes under `yayloyalty/v1/`.

| Class | Route | Role |
|---|---|---|
| `BaseRestController` | — | Error helpers, `exec_read()`, `exec_write()` wrapper |
| `AbstractRestController` | — | `get_item_schema()` (final, applies filter), `define_item_schema()` (override), `get_write_args()` (readonly stripped), `respond_paginated()`, `permission_callback()` |
| `CustomerRestController` | `yayloyalty/v1/customers/` | List, get, manual adjust |
| `LedgerRestController` | `yayloyalty/v1/ledger/` | List entries per user |
| `CampaignRestController` | `yayloyalty/v1/campaigns/` | List, get, create, update campaigns |
| `SettingsRestController` | `yayloyalty/v1/settings/` | Read/update plugin settings |

Write operations wrapped in `Transaction::run()`, return correlation ID on 500.

### Database

| File | Role |
|---|---|
| `Database/Installer.php` | Versioned schema; applies via `dbDelta`; runs on `plugins_loaded` priority 20 (outside `plugin_init`) so headless/REST paths upgrade |
| `Database/Transaction.php` | Nesting-aware; probes for foreign transactions via savepoint round-trip; guards on reconnect; treats failed rollback as fatal |
| `Database/QueryException.php` | Thrown by repositories on write failure |
| `Database/Schema/*Table.php` | `CREATE TABLE` DDL for each table; subclass `AbstractTableSchema` |

### Utilities & Helpers

| Type | Classes | Char |
|---|---|---|
| `Utils/` — stateless, portable | `Dates` (UTC), `Json` (encode/decode maps; encode_list/decode_list), `Log` (writes to WC logs or `error_log`), `Table` (safe table name), `SingletonTrait` | Lift to another plugin? Yes (almost). |
| `Helpers/` — plugin-specific glue | `SettingsHelper` (read options), `LocalizeHelper` (build admin payload) | Knows this plugin. |

**Key rule:** `Helpers/` reads options and SPA state. `Utils/` never does.

### Pro Extension (includes/Pro/)

| File | Role | Seam |
|---|---|---|
| `Pro/YayLoyaltyPro.php` | Boot; attaches all Pro filters; fires `yayloyalty_pro_booted` | — |
| `Pro/Services/ConditionsEvaluator.php` | Evaluates campaign conditions (ANDed); fails on unknown type | `yayloyalty_campaign_conditions_evaluator` |
| `Pro/Controllers/CampaignPreviewRestController.php` | POST `/campaigns/{id}/preview-conditions` — 404 in Lite | `yayloyalty_rest_controllers` |

**Pro seams (filters):**
- `yayloyalty_table_schemas` — Pro can add tables (currently unused)
- `yayloyalty_ledger_recorded` — React to ledger writes
- `yayloyalty_rest_controllers` — Add Pro routes
- `yayloyalty_campaign_triggers` — Add extra triggers
- `yayloyalty_campaign_config_whitelist` — Whitelist Pro config keys per trigger
- `yayloyalty_rest_item_schema` — Extend REST schemas
- `yayloyalty_campaign_conditions_evaluator` — Supply evaluator

---

## React Admin App (apps/admin/)

**Stack:** React 18 + Vite 6 + TanStack Query (React Query) + Zod + Ky + shadcn/Tailwind

| Path | Contents |
|---|---|
| `src/main.tsx` | Entry point; imports Pro barrel if `__IS_PRO__` |
| `src/pages/` | Route pages; `campaigns/` and `points/` have content; others are placeholders |
| `src/pro/` | **Deleted in Lite build** — Pro feature components (`ConditionsPreview`, sentinel exports) |
| `src/components/pro/` | **Kept in Lite** — `ProGate`, upsell UI, badge components |
| `src/lib/` | Helpers: `api/` (REST calls), `queries/` (TanStack Query), `schema/` (Zod), `helpers/` |
| `src/types/` | TypeScript type definitions |
| `src/hooks/` | Custom React hooks |

**Build-time gate:** `__IS_PRO__` Vite define. Literal substitution → dead code elimination. No runtime pro flag ever sent to browser.

**Component gating:** `<ProGate>` gates rendering; `__IS_PRO__ &&` gates inclusion. Both needed.

---

## Request Flows

### Order Award → Ledger Entry

```
WooCommerce order reaches award status
  ↓
Hook listener on order status change
  ↓
LedgerService::record()
  ↓
  ├─ Lock customers row (gap lock → row lock)
  ├─ Insert ledger row
  ├─ Update customers balance + aggregates
  ├─ Insert lots (Pro only)
  ├─ Fire do_action( 'yayloyalty_ledger_recorded' )
  └─ Commit (or savepoint release)
  ↓
REST GET /customers/{id} returns updated balance (next sync)
```

### Admin API Request Flow

```
Browser POST /yayloyalty/v1/campaigns/
  ↓
Route dispatcher (rest_dispatch_request)
  ↓
CampaignRestController::create_item()
  ↓
BaseRestController::exec_write( callback, request )
  ├─ Apply capability check
  ├─ Validate schema (readonly stripped)
  ├─ Call callback (wrapped in Transaction::run())
  ├─ On error: log, return correlation ID
  └─ Return rest_response
```

---

## Build Pipeline

**Input:** Source tree + Node 22 + Composer + PHP 7.4+

**Output:** Two zips (Pro and Lite), validated.

| Step | Command | What |
|---|---|---|
| 1. Compile SPA | `pnpm build [--mode pro\|lite]` | Vite bundles to `assets/dist/admin/js/main.js` with `__IS_PRO__` define |
| 2. Stage | Internal to `run.sh` | Copy tree, strip `includes/Pro/` for Lite, run `composer install --no-dev` |
| 3. Package | `zip -r yayloyalty-{version}.zip yayloyalty/` | Create distributable |
| 4. Validate | `scripts/validate-build.php` zip build | Check sentinel, Pro/Lite consistency, PHP lint, headless boot |
| 5. Output | `release/yayloyalty-{version}.zip` | Ready for release |

**Pro first, Lite second:** Version is stamped once; Lite uses `--no-prompt` to skip re-prompting.

---

## File Naming Conventions

| Kind | Case | Example |
|---|---|---|
| PHP class file | PascalCase (matches class, autoloader dependent) | `Models/Customer.php` |
| React component | PascalCase | `DashboardPage.tsx` |
| TypeScript module | kebab-case + dot-namespace | `settings.api.ts`, `customer.queries.ts` |
| Documentation | kebab-case | `code-standards.md`, `system-architecture.md` |

---

## Execution Context Clues

- **Plugin init:** `plugins_loaded` hook (late, after WooCommerce)
- **Admin JS:** Enqueued by `RegisterProd` / `RegisterDev`; communicates via `ky` to REST
- **REST:** Namespace `yayloyalty/v1`, all under `yayloyalty_rest_controllers` filter
- **Cron:** Action Scheduler jobs (no traditional WordPress cron)
- **Headless / WP-CLI:** Runs via `plugins_loaded` — no `admin_init` required

---

## Key Invariants & Contracts

1. **Ledger is truth.** `customers.balance = SUM(ledger.points)` per user.
2. **Lock order:** Always `customers` before `ledger`. Reverse = deadlock.
3. **Timestamps UTC.** Always `Dates::now()`, never `current_time()`.
4. **Money as strings.** Bind `DECIMAL` columns via `%s`, never `%f`.
5. **One seam in Lite.** Only reference to Pro is guarded `class_exists` in `YayLoyalty::initialize()`.
6. **Transaction nesting.** Only `Transaction::run()` opens/nests; never raw SQL.

---

## Related Documentation

- **`code-standards.md`** — PHP/REST conventions, layer rules, enum patterns, transaction safety
- **`deployment-guide.md`** — Build commands, validation, headless setup
- **`data-model.md`** — 12-table specification; 3 tables implemented; schema reference
- **`system-architecture.md`** — Architecture diagrams, Pro/Lite mechanism, build pipeline detail
