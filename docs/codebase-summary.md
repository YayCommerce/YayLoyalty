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

### Models & Data (4 tables implemented)

| File | Table | Role |
|---|---|---|
| `Models/AbstractModel.php` | — | Base class; `to_array()`, REST shape cast methods |
| `Models/Customer.php` | `yayloyalty_customers` | Cached aggregates (balance, lifetime earned, etc.) |
| `Models/LedgerEntry.php` | `yayloyalty_ledger` | Immutable fact row; `points` delta, `balance_after`, type, source |
| `Models/Campaign.php` | `yayloyalty_campaigns` | Earning rule; trigger, config (JSON), conditions (JSON) |
| `Models/Reward.php` | `yayloyalty_rewards` | Redemption option; type, cost (for catalog), config (JSON), conditions (JSON) |
| `Models/RewardCondition.php` | — | One condition in a reward's eligibility rules |
| `Models/RewardConditionSet.php` | — | Validation & normalisation for a reward's conditions array |

### Enums (PHP 7.4 Pseudo-enums)

All in `Enums/` as `final class` + `const` + `all()` / `all_known()` + `is_valid()` + `assert()`:

- **`CampaignTrigger`** — `purchase`, `signup`, `review` (Lite); plus `birthday`, `first_order`, `order_count`, `spend_milestone` (Pro). `all()` is filtered (fireable); `all_known()` is not (recognised).
- **`LedgerType`** — `earn`, `redeem`, `refund_earn`, `refund_redeem`, `expire`, `adjust`.
- **`LedgerSource`** — `order`, `signup`, `review`, `referral`, `birthday`, `milestone`, `social`, `renewal`, `manual`, `import`, `expiry`, `system`.
- **`ReferenceType`** — `order`, `refund`, `comment`, `redemption`, `referral`, `user`, `birthday`, `import`.
- **`Status`** — `active`, `paused`, `draft`, `archived`.
- **`RewardType`** — `flexible_discount` (Lite); plus `fixed_coupon`, `percent_coupon`, `free_shipping`, `free_product` (Pro). `all()` is fireable; `all_known()` is recognised (downgrade-safe).
- **`CampaignConditionType`** & **`RewardConditionType`** — Eligibility/availability rules; share `Enums\ConditionTypeMethods` trait for common methods.
- **`ConditionTypeMethods`** — Trait providing `all()`, `all_known()`, `is_valid()`, `is_known()`, `operators_for()`, `assert()` for condition vocabularies.

### Repositories (SQL layer, one per table)

All SQL access **must** go through these. Throw `QueryException` on write failure; never return `false`.

| Class | Table | Methods |
|---|---|---|
| `Repositories/AbstractRepository.php` | — | Base: `table()` via `Utils\Table`, `find()`, `insert()`, `update()`, `delete()`, `paginate()`; all SQL prepared; write failures throw `QueryException` |
| `Repositories/CustomerRepository.php` | `customers` | `find_by_user_id()`, `ensure_row()` (INSERT … ON DUPLICATE KEY UPDATE), `lock_and_get()` (SELECT … FOR UPDATE), `apply_delta()` |
| `Repositories/LedgerRepository.php` | `ledger` | `find_by_reference()`, `paginate_for_user()`, `sum_points_for_user()`, `latest_for_user()` |
| `Repositories/CampaignRepository.php` | `campaigns` | `find_campaign()`, `find_by_slug()`, `live_for_trigger($trigger, $at)`, `all_non_archived()` (cached), `find_default_purchase()`, `paginate_filtered()`, `seed_builtin()`, `flush_cache()` |
| `Repositories/RewardRepository.php` | `rewards` | `find_reward()`, `find_by_slug()`, `paginate_filtered()`, `seed_builtin()` |

### Services (Business logic, transactions)

| Class | Role |
|---|---|
| `Services/LedgerService.php` | **SOLE writer** of ledger. `adjust()` (public) creates ledger row, updates customer aggregates, fires `yayloyalty_ledger_recorded`, all in `Transaction::run()`. Encapsulates idempotency and lock order. `record()` is private; use `adjust()` for manual adjustments. |

**Design rule:** Fact tables get Entity + Repository + Service. Definition tables (campaigns) get Entity + Repository only — no service.

### Controllers (REST layer)

All `namespace YayLoyalty\Controllers` → routes under `yayloyalty/v1/`.

| Class | Route | Role |
|---|---|---|
| `BaseRestController` | — | Error helpers, `exec_read()`, `exec_write()` wrapper |
| `AbstractRestController` | — | `get_item_schema()` (final, applies filter), `define_item_schema()` (override), `get_write_args()` (readonly stripped), `respond_paginated()`, `requires( $cap )` (permission callback), `forbidden()`, `assert_ceiling()` |
| `CustomerRestController` | `yayloyalty/v1/customers/{id}`, `POST /customers/{id}/adjust` | Get one customer, manual adjust |
| `LedgerRestController` | `yayloyalty/v1/customers/{id}/ledger` | Paginated history per user |
| `CampaignRestController` | `yayloyalty/v1/campaigns` | CRUD operations on campaigns |
| `SettingsRestController` | `yayloyalty/v1/settings` | Read/update plugin settings |
| `CustomerRowFilters`, `CustomerRowSort` | — | Pure helpers for server-side customer list filtering/sorting (unwired; no `GET /customers` endpoint) |
| `CallbackReturnedError` | — | Error object when a controller callback fails (logged, correlated) |

Write operations wrapped in `Transaction::run()`, return correlation ID on 500.

### Points engine (includes/Engine/Points/)

`namespace YayLoyalty\Engine\Points`. Computes what an event earns and which campaign paid; writes nothing. See its `README.md` for the two call sites.

| Class | Role |
|---|---|
| `PointsEngine` | `evaluate( $trigger, Context, $at )` → `Result`. Candidates → select → compute → cap. Reads campaigns only; no `$wpdb`, no current user, no `time()`, no capability checks |
| `Context`, `ContextLineItem` | Immutable inputs. Amounts in order currency plus `base_rate`; `get_real_coupon_codes()` drops our own redeem coupon; `get_extra()` for trigger-specific facts (`verified_purchase`) |
| `ContextFactory` | `from_order()`, `from_product()`, `from_cart()` — the only class that touches WooCommerce. `yayloyalty_context_base_rate` is the multi-currency seam |
| `Triggers\Registry` + `TriggerHandler` | `PurchaseHandler` (gates: min_subtotal, exclude_coupon_orders, multiplier needs an active default), `SignupHandler`, `ReviewHandler` (require_verified). `with_lite_handlers()` fires `yayloyalty_register_trigger_handlers` for Pro |
| `BaseCalculator`, `Rounding`, `CapPolicy` | Earning base (exclusions, before/after discounts, tax, loyalty-discount add-back), order-level rounding, per-order and daily caps |
| `Result`, `Breakdown` | Outcome + how it was reached. Storefront exposes `get_points()` only |

Filters (public API): `yayloyalty_campaign_candidates`, `yayloyalty_campaign_matches`, `yayloyalty_earning_base_amount`, `yayloyalty_points_for_order`.

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
| `Utils/` — stateless, portable | `Dates` (UTC), `Json` (encode/decode maps; encode_list/decode_list), `Log` (writes to WC logs or `error_log`), `Table` (safe table name), `SingletonTrait`, `ConditionValueRules` (validation for list/int/between/cap shapes) | Lift to another plugin? Yes (almost). |
| `Helpers/` — plugin-specific glue | `SettingsHelper` (read options), `LocalizeHelper` (build admin payload) | Knows this plugin. |
| `Access/` — capabilities | `Capabilities` (the seven cap names — campaigns and rewards are separate — role defaults, `install()`/`migrate()`/`uninstall()`, `ceiling()`, `payload()` for the SPA). Checked at the HTTP boundary only; services take an `actor_id`. | Knows this plugin. |

**Key rule:** `Helpers/` reads options and SPA state. `Utils/` never does.

### Pro Extension (includes/Pro/)

| File | Role | Seam |
|---|---|---|
| `Pro/YayLoyaltyPro.php` | Boot; attaches all Pro filters; fires `yayloyalty_pro_booted` | — |
| `Pro/Services/ConditionsEvaluator.php` | Evaluates campaign conditions (ANDed); fails on unknown type | `yayloyalty_campaign_conditions_evaluator` |
| `Pro/Controllers/CampaignPreviewRestController.php` | POST `/campaigns/{id}/preview-conditions` — 404 in Lite | `yayloyalty_rest_controllers` |

**Pro seams (filters):**
- `yayloyalty_table_schemas` — Pro can add tables (currently unused)
- `yayloyalty_ledger_recorded` — React to ledger writes (no subscriber yet)
- `yayloyalty_rest_controllers` — Add Pro routes (CampaignPreviewRestController)
- `yayloyalty_campaign_triggers` — Add extra triggers (birthday, first_order, order_count, spend_milestone)
- `yayloyalty_campaign_config_whitelist` — Whitelist Pro config keys per trigger
- `yayloyalty_reward_types` — Add extra reward types (fixed_coupon, percent_coupon, free_shipping, free_product)
- `yayloyalty_reward_config_whitelist` — Whitelist Pro config keys per reward type
- `yayloyalty_rest_item_schema` — Extend REST schemas
- `yayloyalty_campaign_conditions_evaluator` — Supply conditions evaluator instance

**Other seams (Pro/Lite/Engine):**
- `yayloyalty_max_manual_adjustment` — Filter per-role cap on manual adjustments
- `yayloyalty_run_migrations` — Trigger custom migrations
- `yayloyalty_localize_admin_data` — Extend SPA payload
- `yayloyalty_localize_meta` — Add meta tags to admin head
- `yayloyalty_pro_booted` — Action fired after Pro boot completes

---

## React Admin App (apps/admin/)

**Stack:** React 18 + Vite 6 + TanStack Query (React Query) + Zod + Ky + shadcn/Tailwind

| Path | Contents |
|---|---|
| `src/main.tsx` | Entry point; imports Pro barrel if `IS_PRO_BUILD` |
| `src/router.tsx` | Hash router with routes for `/dashboard`, `/customers`, `/campaigns`, `/rewards`, `/referral`, `/vip-tiers`, `/settings` (each gated by capability via `<Can>`) |
| `src/pages/` | Route pages; `campaigns/` and `settings/` have content; Dashboard, Rewards, Referral, VIP Tiers are placeholders |
| `src/pages/customers/` | **BROKEN** — CustomersPage built; list UI exists; endpoint + imports removed #7 → tsc errors |
| `src/pro/` | **Deleted in Lite build** — Pro feature components (sentinel exports) |
| `src/components/pro/` | **Kept in Lite** — `ProGate`, upsell UI, badge components |
| `src/lib/` | Helpers: `api/` (REST calls), `queries/` (TanStack Query), `schema/` (Zod), `utils.ts`, `is-pro-build.ts`, `helpers/`, `access.tsx` (`CAP`, `can()`, `<Can>` — hide/disable by capability from `window.yayLoyaltyAdmin`; cosmetic, the server decides) |
| `src/types/` | TypeScript type definitions |
| `src/hooks/` | Custom React hooks |

**Build-time gate:** `IS_PRO_BUILD` is `import.meta.env.VITE_IS_PRO` (NOT a Vite `define`). Set by vite.config.ts from `BUILD_TYPE` environment variable. Literal substitution → dead code elimination. No runtime pro flag ever sent to browser.

**Component gating:** `<ProGate>` gates rendering; `IS_PRO_BUILD &&` gates inclusion. Both needed.

---

## Request Flows

### Order Award → Ledger Entry (Not Wired)

```
[Currently NOT IMPLEMENTED — Engine built, listener missing]

WooCommerce order reaches award status
  ↓
Hook listener on order status change (F01-4, not built)
  ↓
ContextFactory::from_order( $order, $points_earned_today )   ← the only WooCommerce read
  ↓
PointsEngine::evaluate( 'purchase', $ctx, $ctx->get_occurred_at() )   pure: campaigns only; 46 unit tests
  ├─ CampaignRepository::live_for_trigger()  liveness filtered at the ORDER's time
  ├─ first campaign: conditions_match() AND handler->is_eligible()   (gates fall through, never zero)
  ├─ PurchaseHandler → BaseCalculator → Rounding → CapPolicy
  └─ Result: points, campaign, breakdown, reference, source | none( reason )
  ↓
LedgerService::earn( user_id, points, source, reference_type, reference_id, campaign_id )   [to be added]
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
  ├─ permission_callback: requires( Capabilities::MANAGE_CAMPAIGNS ) → 403 yayloyalty_forbidden
  ├─ Validate args (readonly stripped)
  ↓
CampaignRestController::create_item()
  ↓
BaseRestController::exec_write( callback, request )
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
| 1. Compile SPA | `pnpm build` (BUILD_TYPE=lite or pro) | Vite bundles to `assets/dist/admin/js/main.js`; `vite.config.ts` sets `VITE_IS_PRO` from `BUILD_TYPE` |
| 2. TypeScript check | `tsc --noEmit` | **Currently fails due to dangling imports from #7** |
| 3. Stage | Internal to `run.sh` | Copy tree, strip `includes/Pro/` for Lite, run `composer dump-autoload --no-dev --classmap-authoritative` (dev deps restored after packaging) |
| 4. Package | `zip -r yayloyalty-{version}.zip yayloyalty/` | Create distributable; strip `.gitkeep`, `RegisterDev.php`, `.map`, `.vite`, `.DS_Store` |
| 5. Validate | `php scripts/validate-build.php <zip> <pro|lite> [--require-boot]` | Check sentinel, Pro/Lite consistency, PHP lint, headless boot |
| 6. Output | `release/yayloyalty-{version}.zip` | Ready for release |

**Pro first, Lite second:** Version is stamped once; Lite uses `--no-prompt` to skip re-prompting. After release, run `composer install` to restore dev deps.

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
- **Headless / WP-CLI:** Runs via `plugins_loaded` — no `admin_init` required
- **Tests:** `tests/unit/` with `tests/bootstrap.php` shim for hooks (no `$wpdb`, no WooCommerce). Run with `composer test` (46 PHPUnit tests)

---

## Key Invariants & Contracts

1. **Ledger is truth.** `customers.balance = SUM(ledger.points)` per user.
2. **Lock order:** Always `customers` before `ledger`. Reverse = deadlock.
3. **Timestamps UTC.** Always `Dates::now()`, never `current_time()`.
4. **Money as strings.** Bind `DECIMAL` columns via `%s`, never `%f`.
5. **One seam in Lite.** Only reference to Pro is guarded `class_exists` in `YayLoyalty::initialize()`.
6. **Transaction nesting.** Only `Transaction::run()` opens/nests; never raw SQL.
7. **Caps at the boundary only.** `Access\Capabilities` is checked in the menu, page render and `permission_callback`; services and repositories take an `actor_id` and never call `current_user_can()`. The SPA hides/disables, the server refuses.

---

## Related Documentation

- **`code-standards.md`** — PHP/REST conventions, layer rules, enum patterns, transaction safety
- **`deployment-guide.md`** — Build commands, validation, headless setup
- **`data-model.md`** — 12-table specification; 4 tables implemented; schema reference
- **`system-architecture.md`** — Architecture diagrams, Pro/Lite mechanism, build pipeline detail
