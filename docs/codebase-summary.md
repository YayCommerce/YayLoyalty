# YayLoyalty — Codebase Summary

Comprehensive guide to the YayLoyalty plugin repository structure, entry points, and request flows.

---

## Where each fact lives

A fact is written out in one doc; every other doc links to it. Update the owner, not a copy — `./scripts/check-docs.sh` catches repeated test counts.

| Fact | Owner |
| --- | --- |
| Test suites: what each checks, current counts | [`code-standards.md` § 14](./code-standards.md#14-running-the-linter-and-tests) |
| Which customer routes accept which user ids (`member_id_param` / `user_id_param`) | [`code-standards.md` § 9](./code-standards.md#9-rest) |
| REST controllers and their routes | this doc, [PHP Layers → Controllers](#php-layers-includes) |
| Pro seams (hooks): where each fires, who subscribes | [`system-architecture.md` → Internal Extension Seams](./system-architecture.md#internal-extension-seams-filters) |
| `WP_DEBUG` / `?scenario=` dev fixtures | [`deployment-guide.md` → Fixture-backed endpoints](./deployment-guide.md#fixture-backed-endpoints) |
| Tables and columns | [`data-model.md`](./data-model.md) and its per-table files in `data-model/` |
| Which place owns each code rule, and its allowed mirrors | [`code-standards.md` § 16](./code-standards.md#16-single-source-of-truth) |

---

## Directory Structure

| Path                   | Contents                                             | Purpose                                                                         |
| ---------------------- | ---------------------------------------------------- | ------------------------------------------------------------------------------- |
| **`yayloyalty.php`**   | Plugin header, constants, autoloader, hooks          | Single entry point; defines paths and constants; triggers `plugins_loaded` hook |
| **`includes/`**        | All backend PHP code, logically layered              | See Layers section below                                                        |
| **`apps/admin/`**      | React 18 + Vite 6 admin SPA                          | Built to `assets/dist/admin/js/main.js`                                         |
| **`scripts/`**         | `run.sh` (dev/build/release), `validate-build.php`, `dump-design-defaults.php` | Build pipeline and validation                                                   |
| **`docs/`**            | Architecture, standards, model reference, deployment | Living documentation                                                            |
| **`release/`**         | Packaged zips after `./scripts/run.sh release`       | Output only; not in version control                                             |
| **`.claude/skills/`**  | Shared Claude Code skills (`yay:code-corrector`)     | Tracked; the rest of `.claude/` (agent memory, worktrees) stays ignored         |
| **`vendor-prefixed/`** | Scoped Composer deps via php-scoper                  | Admin shell (not user code)                                                     |

---

## PHP Layers (includes/)

### Entry & Bootstrap

| File                                 | Role                                                                                                      |
| ------------------------------------ | --------------------------------------------------------------------------------------------------------- |
| `YayLoyalty.php`                     | Singleton initializer; constructs RestAPI, RegisterFacade, Settings; boots Pro via guarded `class_exists` |
| `Engine/RestAPI.php`                 | Wires REST routes; collects controllers; applies `yayloyalty_rest_controllers` filter                     |
| `Engine/Register/RegisterProd.php`   | Registers scripts/styles for production                                                                   |
| `Engine/Register/RegisterDev.php`    | Registers dev server origin for HMR (Vite)                                                                |
| `Engine/Register/RegisterFacade.php` | Facade that selects RegisterProd or RegisterDev                                                           |
| `Engine/Admin/Settings.php`          | Loads admin menu items and pages                                                                          |
| `Engine/ActDeact.php`                | Activation, deactivation, multisite hooks, installer trigger                                              |
| `Engine/CustomerActivityListener.php` | First WP-core business-logic listener: `delete_user`/`wpmu_delete_user` (account-deletion capture) + `yayloyalty_ledger_recorded` (account_created/points_adjusted capture); registered directly in `yayloyalty.php`, no dedicated Listeners/Hooks base class exists |
| `Database/Installer.php`             | Schema versioning, migrations, `dbDelta` application                                                      |
| `Fallback.php`                       | Version-conflict admin notice on activation when another YayLoyalty version is detected                   |
| `I18n.php`                           | Text domain loading                                                                                       |

### Models & Data (5 tables implemented)

| File                            | Table                  | Role                                                                          |
| ------------------------------- | ---------------------- | ----------------------------------------------------------------------------- |
| `Models/AbstractModel.php`      | —                      | Base class; `to_array()`, REST shape cast methods                             |
| `Models/Customer.php`           | `yayloyalty_customers` | Cached aggregates (balance, lifetime earned, etc.)                            |
| `Models/LedgerEntry.php`        | `yayloyalty_ledger`    | Immutable fact row; `points` delta, `balance_after`, type, source, `idempotency_key` (null on `adjust`) |
| `Models/CustomerMeta.php`       | `yayloyalty_customer_meta` | Non-points customer fact + one activity-log row; `to_array()` is the activity wire shape, not the raw row |
| `Models/Redemption.php`         | `yayloyalty_redemptions` (table not created yet) | One reward claim: points, value, currency, rate snapshot, coupon, status, restored_points |
| `Models/Campaign.php`           | `yayloyalty_campaigns` | Earning rule; trigger, config (JSON), conditions (JSON)                       |
| `Models/CampaignCondition.php`  | —                      | One campaign eligibility rule: { type, operator, value }; immutable |
| `Models/CampaignConditionSet.php` | — | Validation & normalisation for a campaign's conditions array                    |
| `Models/Reward.php`             | `yayloyalty_rewards`   | Redemption option; type, cost (for catalog), config (JSON), conditions (JSON) |
| `Models/RewardCondition.php`    | —                      | One condition in a reward's eligibility rules                                 |
| `Models/RewardConditionSet.php` | —                      | Validation & normalisation for a reward's conditions array                    |

### Enums (PHP 7.4 Pseudo-enums)

All in `Enums/` as `final class` + `const` + `all()` / `all_known()` + `is_valid()` + `assert()`:

- **`CampaignTrigger`** — `purchase`, `signup`, `review` (Lite); plus `birthday`, `first_order`, `order_count`, `spend_milestone` (Pro). `all()` is filtered (fireable); `all_known()` is not (recognised).
- **`CustomerFilters`** — Shared filter vocabulary: `balance_range`, `expiring_soon`, `tier` (range keys); shared by `CustomerRowFilters.php` (REST) and `CustomerSegmentsHelper.php` (saved segments).
- **`LedgerType`** — `earn`, `redeem`, `refund_earn`, `refund_redeem`, `expire`, `adjust`, `correction`.
- **`LedgerSource`** — `order`, `signup`, `review`, `referral`, `birthday`, `milestone`, `social`, `renewal`, `manual`, `import`, `expiry`, `system`.
- **`ReferenceType`** — `order`, `refund`, `comment`, `redemption`, `referral`, `user`, `birthday`, `import`.
- **`ActivityEvent`** — the `yayloyalty_customer_meta.meta_key` vocabulary, also the activity log's `event_type`: `account_created`, `points_adjusted`, `tier_changed`, `expiry_reset`, `reward_redeemed`, `balance_email_sent`, `expiring_soon_email_sent`, `account_deleted`. Only the first, second and last have a writer.
- **`ActorKind`** — who performed an activity-log event: `staff`, `automatic`, `customer`. Constants + `ALL` only, like `ActivityEvent`.
- **`Status`** — `active`, `paused`, `draft`, `archived`.
- **`RewardType`** — `flexible_discount` (Lite); plus `fixed_coupon`, `percent_coupon`, `free_shipping`, `free_product` (Pro). `all()` is fireable; `all_known()` is recognised (downgrade-safe).
- **`CampaignConditionType`** & **`RewardConditionType`** — Eligibility/availability rules; share `Enums\ConditionTypeMethods` trait for common methods.
- **`ConditionTypeMethods`** — Trait providing `all()`, `all_known()`, `is_valid()`, `is_known()`, `operators_for()`, `assert()` for condition vocabularies.

### Repositories (SQL layer, one per table)

All SQL access **must** go through these. Throw `QueryException` on write failure; never return `false`.

| Class                                  | Table                                                                                               | Methods                                                                                                                                                                                                                                                                                                                                                                                                         |
| -------------------------------------- | --------------------------------------------------------------------------------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `Repositories/AbstractRepository.php`  | —                                                                                                   | Base: `table()` via `Utils\Table`, `find()`, `insert()`, `update()`, `delete()`, `paginate()`; all SQL prepared; write failures throw `QueryException`                                                                                                                                                                                                                                                          |
| `Repositories/CustomerReader.php`      | `customers` (interface) | `find_by_user_id()` only — the read-only face `CustomerRepository` implements; the redemption `ContextFactory` depends on this so the quote path holds nothing that can write |
| `Repositories/CustomerRepository.php`  | `customers`                                                                                         | `find_by_user_id()`, `ensure_row()` (INSERT … ON DUPLICATE KEY UPDATE), `lock_and_get()` (SELECT … FOR UPDATE), `apply_delta()`                                                                                                                                                                                                                                                                                 |
| `Repositories/LedgerRepository.php`    | `ledger`                                                                                            | `paginate_for_user()`, `sum_points_for_user()`, `count_for_user()`; scaffold stubs `find_by_idempotency_key()`, `points_earned_today()`                                                                                                                                                                                                                                                                                                                      |
| `Repositories/CustomerMetaRepository.php` | `customer_meta`                                                                                  | `latest()`, `has()` (once-only write guard), `count_activity()`, `paginate_activity()` (group/search/date-range; search delegates to `CustomerMetaActivitySearch` for the PHP-side filter path); not final, extended as a test double in `CustomerActivityServiceTest`                                                                                                                                       |
| `Repositories/CampaignRepository.php`  | `campaigns`                                                                                         | `find_campaign()`, `find_by_slug()`, `live_for_trigger($trigger, $at)`, `all_non_archived()` (cached), `find_default_purchase()`, `insert()`, `update()`, `delete()`, `paginate_filtered()`, `seed_builtin()`, `flush_cache()`                                                                                                                                                                                                                      |
| `Repositories/RedemptionRepository.php` | `redemptions` (table not created yet) | `find_redemption()`, `find_by_order( order_id, reward_id )` (the double-debit read), `count_for_customer()` (for `redemption_count`) — stubs |
| `Repositories/RewardRepository.php`    | `rewards`                                                                                           | `find_reward()`, `find_by_slug()`, `paginate_filtered()`, `seed_builtin()`                                                                                                                                                                                                                                                                                                                                      |
| `Repositories/DashboardRepository.php` | ledger + customers + users + rewards + WC (**read-only, standalone** — not an `AbstractRepository`) | Dashboard aggregates: `count_members()`, `sum_issued()`/`sum_redeemed()`/`net_delta()`, `daily_points()`, `liability_points()`/`net_balance()`, `orders_with_points()`/`orders_with_earn()`, `campaign_points_on_day()`, `top_earners()`, `latest_activity()`, `count_activity_since()`, `count_negative_balances()`, `count_orders()` (WC, unix-timestamp range, completed only), `points_per_currency_unit()` |

### Services (Business logic, transactions)

| Class                        | Role                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                 |
| ---------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| `Services/Dashboard/`        | Pure, unit-tested dashboard computation: `PeriodWindow` (site-tz calendar-day windows → UTC bounds), `DashboardMath` (series zero-fill; ratios come from `Utils\PointsMath`), `AnomalyDetector` (jump rule over active days), `DashboardStats` (composes the payload), `DashboardNotices` (three detectors, structured `{key, severity, fingerprint, data}` — no copy), `DashboardCache` (transient above 10k members, `''`/`'pro'` variants, flushed on `yayloyalty_ledger_recorded` + `yayloyalty_campaigns_changed`), `NoticeDismissals` (per-user meta, applied after the cache) |
| `Services/LedgerService.php` | **SOLE writer** of ledger. `adjust()` (public) creates ledger row, updates customer aggregates, writes inside `Transaction::run()`, then fires `yayloyalty_ledger_recorded` after it returns. Encapsulates idempotency and lock order. `record()` is private; use `adjust()` for manual adjustments. `earn( LedgerEntry $draft ): Outcome` is the award-path entry point — a scaffold stub until F01-4 (lock customer row → INSERT under `uniq_idempotency_key` → aggregates; MySQL 1062 becomes `Outcome::duplicate`). `redeem( LedgerEntry $draft ): Outcome` is its redemption sibling over the same `record()` — a stub until F08; hard-refuses a balance below zero. |
| `Services/CustomerActivityService.php` | Writes `yayloyalty_customer_meta` rows for the three captured activity events: `record_account_deleted()`, `record_account_created()`, `record_points_adjusted()`. `[REVIEW]` (§8): holds `CustomerRepository` + `LedgerRepository` alongside `CustomerMetaRepository`, justified as read-only. Never opens `Transaction::run()` — single-table inserts only. |

**Design rule:** Fact tables get Entity + Repository + Service. Definition tables (campaigns) get Entity + Repository only — no service.

### Controllers (REST layer)

All `namespace YayLoyalty\Controllers` → routes under `yayloyalty/v1/`.

| Class                                   | Route                                                                           | Role                                                                                                                                                                                                                                                                                                                                                                                                |
| --------------------------------------- | ------------------------------------------------------------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `BaseRestController`                    | —                                                                               | Error helpers, `exec_read()`, `exec_write()` wrapper                                                                                                                                                                                                                                                                                                                                                |
| `AbstractRestController`                | —                                                                               | `get_item_schema()` (final, applies filter), `define_item_schema()` (override), `get_write_args()` (readonly stripped), `respond_paginated()`, `requires( $cap )` (permission callback), `forbidden()`, `assert_ceiling()`                                                                                                                                                                          |
| `CustomerRestController`                | `GET /customers` (list), `GET /customers/{id}`, `POST /customers/{id}/adjust`   | List (search/filter/sort/paginate, `CustomerListFormatter::respond_list()`); get one — `deleted_at`/`loose_ends`/`counts.activity` are now real, delegated to `CustomerItemFormatter::format()` (`worth` was removed, see `docs/data-model/customers-and-ledger.md`); manual adjust |
| `CustomerAdjustPreviewRestController`   | `POST /customers/adjust/preview`                                                | Bulk adjust dialog only (MANAGE_POINTS): `count` of a filter (null for a ticked list), store-wide `liability_points`, `points_per_currency_unit`. A single customer is read from `GET /customers/{id}` instead |
| `LedgerRestController`                  | `GET /customers/{id}/ledger`                                                    | Paginated history per user — F09 drawer adds `type`/`search`/`after`/`before` filter args (`Schemas/LedgerQueryArgs.php`) and a `type_counts` envelope field (`null` on the real path: the real filtered-count query is future work) |
| `LookupRestController`                  | `GET /lookups/{entity}`                                                        | Picker name lookups (`products`, `categories`, `customers`) by `search` or `ids`, 20 rows, `{ items: [{ id, label }] }`; reads WooCommerce and WP core directly, no repository; cap `VIEW`. Shared by every editor that names one of these (campaign conditions now, reward conditions next) |
| `ActivityRestController`                | `GET /customers/{id}/activity`                                                  | Paginated per-customer timeline, reading real `yayloyalty_customer_meta` rows for the three captured event types (group filter + search + date range, same shape as the ledger); a missing table or query failure degrades to an empty page rather than a 500 |
| `RedemptionsRestController`             | `GET /customers/{id}/redemptions`                                               | Paginated redemption rows (F09 Wave B, phase 05) — same empty-envelope-on-real-path shape as Activity. Pro-gated tab body (`ProGate`), presentation only |
| `ReferralsRestController`               | `GET /customers/{id}/referrals`                                                 | Paginated referral rows (F09 Wave B, phase 06) — same shape again; envelope also carries `referral_link`/`campaign`. Pro-gated tab body, same as Redemptions |
| `CampaignRestController`                | `/campaigns`, `/campaigns/{id}`                                                 | Real CRUD on campaigns (`CampaignRepository`); `update_item()`/`delete_item()` validate (trigger, config whitelist, conditions, built-in guards) and persist. Responses decorated with `points_issued`/`is_deletable`, derived from `LedgerRepository::totals_by_campaign()` at read time                                                                                                                                                                                                                 |
| `SettingsRestController`                | `yayloyalty/v1/settings`                                                        | Read/update plugin settings. POST is a field-level patch: writes only the fields sent in the request, preserving unchanged fields and never overwriting with defaults (via `SettingsHelper::update_settings()`)                                                                                                                                                                                                                                                                                         |
| `DesignRestController`                  | `/design`, `POST /design/reset`                                                 | Read/update the Design tab's option through `DesignHelper`; reset restores one group or, with no group, the whole option. Read needs `VIEW`, writes `MANAGE_SETTINGS`                                                                                                                                                                                                                                  |
| `EmailsRestController`                  | `GET /emails`, `POST /emails`                                                   | Registered loyalty emails (3 Lite / 12 Pro) with on/off toggles (WooCommerce options via `EmailsHelper::update_emails()`), weekly throttle cap (plugin settings), and live WC sender info (never stored). GET lists emails grouped by category with full metadata; POST is one patch body (`emails` id => on/off, plus `throttle_per_week` only if sent); payload keys snake_case. No schema override; whitelist via `EmailsHelper::registered_emails()`.                                               |
| `DashboardRestController`               | `GET /dashboard?range=7\|30\|90[&refresh=1]`, `POST /dashboard/notices/dismiss` | Landing-page payload (cap `VIEW`); `scenario` arg exists only under `WP_DEBUG` → `DashboardFixture` artboards (SWAP POINT, dev-only)                                                                                                                                                                                                                                                                |
| `CustomerRowFilters`, `CustomerRowSort` | —                                                                               | Pure helpers for the Customers list's server-side filtering/sorting; SQL-free — `CustomerRepository::paginate_list()`/`count_list()` turn their criteria/order shapes into SQL                                                                                                                                                                                                                      |
| `CustomerListFormatter`                 | —                                                                               | Full `GET /customers` request→response handling (`respond_list()`), route args (`filter_params()`), wire-row / no-match-suggestion shaping, split from `CustomerRestController` for the 200-line cap; sibling of the two helpers above                                                                                                                                                                                                              |
| `CustomerItemFormatter`                 | —                                                                               | `GET /customers/{id}` response assembly split from `CustomerRestController` for the 200-line cap — pure array assembly, no `$wpdb`/`get_userdata()`/`__()`, unit-tested without a WP bootstrap; also owns `live_identity()` (its only caller)                                                                                                                                                                             |
| `Schemas/*`                             | —                                                                               | Shared REST vocab: `CustomerItemSchema` (drawer item schema), `CustomerRouteArgs` (Customers list sort override + `/adjust` body args incl. `adjust_lifetime`), `LedgerQueryArgs` (filter args + `group_of()`), `ActivityQueryArgs` (`GROUPS`, `event_types_for()`, `ACTOR_KINDS`) — referenced by controllers and the drawer fixtures alike                                                                                                                                                                             |
| `CallbackReturnedError`                 | —                                                                               | Error object when a controller callback fails (logged, correlated)                                                                                                                                                                                                                                                                                                                                  |

Write operations wrapped in `Transaction::run()`, return correlation ID on 500.

**Note on `CustomerRestController`:** `GET /customers` is real, not demo data — `CustomerRepository::paginate_list()` queries `yayloyalty_customers` JOINed with `wp_users`. `CustomerRowFilters.php`/`CustomerRowSort.php` own the filter/sort semantics; the controller only translates a request into their SQL-free criteria/order shapes and the result back into wire rows. TypeScript mirrors: `customers-page.filter-rows.ts`, `customers-page.sort-rows.ts`. Filter-suggestion keys (`suggestions[].key`) are **wire param names** (snake_case: `balance_range`, `expiring_soon`, `tier`). Saved-segment payload (`filters`) also uses snake_case keys on the wire; `customer-segments.api.ts` converts to/from camelCase for the SPA.

**Note on the customer drawer's real path (F09):** all five drawer endpoints — `GET /customers/{id}`,
`.../ledger`, `.../activity`, `.../redemptions`, `.../referrals` — are real, routed handlers. The
per-type ledger grouping is `LedgerQueryArgs::group_of()` and the activity vocabularies are
`Enums\ActivityEvent::ALL`/`ActivityQueryArgs::ACTOR_KINDS`, referenced by the REST item schemas. Activity now
reads real `yayloyalty_customer_meta` rows for three of its eight event types (`account_created`,
`points_adjusted`, `account_deleted`) — the other five have no source feature, so a customer whose
only history would be one of them gets an honest empty page. Redemptions and Referrals still
always answer an empty envelope — no backing table exists for either yet (see
`docs/data-model/redemptions.md`, `docs/data-model/future-tables.md`); the ledger and customer-detail paths are fully real, and a deleted member's
item route and all four tab routes resolve (`AbstractRestController::member_id_param()`; which
routes take which id rule: `code-standards.md` § 9). Every response parses through the exact same Zod schema on the SPA
(`customer.schema.ts` / `ledger.schema.ts` / `activity.schema.ts` / `redemption.schema.ts` /
`referral.schema.ts`). No fixture remains for the drawer — the `CustomerDrawerFixture`/`Drawer*`
classes under `Controllers/Fixtures/CustomerDrawer/` and the `scenario` arg on these five routes
were removed once the real path covered every wireframe artboard.

**Note on `CampaignRestController`:** All four verbs are real (`CampaignRepository`). `get_items()`/`get_item()` decorate each row with `points_issued`/`is_deletable` from `LedgerRepository::totals_by_campaign()` (one batched query per response, never per row). `update_item()` writes via `Campaign::to_update_row()` — pairs `to_row()`'s data with `get_format()`'s placeholders position-for-position, since `$wpdb->update()`'s `$format` is consumed positionally and dropping the immutable columns (`slug`, `trigger_type`, `created_at`, `created_by`, `is_builtin`) from one without the other would silently misalign the rest. `delete_item()` 409s when the campaign has any ledger row (count, not points — a refunded-to-zero campaign still has rows naming it).

**Note on `CampaignPerformanceRestController`:** real end to end — `resolve_row()` looks up the real campaign via `CampaignRepository`; `get_performance()`'s totals and `get_entries()`'s rows both come from `Pro\Repositories\CampaignPerformanceRepository` (one aggregate query over `ledger`, plus a paged/searchable query joined to `wp_users`). `totals` is lifetime; the chart's own `window`/`daily` is a 30-day span clamped to the campaign's run — see the controller's class docblock ("TWO TIME BASES ON PURPOSE"). `skipped`, `estimate` and `totals.avg_order_subtotal` still ship `null` — they need the earning engine (F01-4, scaffold), not a missing repository.

### Points engine (includes/Engine/Points/)

`namespace YayLoyalty\Engine\Points`. Computes what an event earns and which campaign paid; writes nothing. See its `README.md` for the two call sites.

| Class                                  | Role                                                                                                                                                                                                                                 |
| -------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| `PointsEngine`                         | `evaluate( $trigger, Context )` → `Result`; liveness scored at `Context::occurred_at`. Candidates → select → compute → cap. Reads campaigns only; no `$wpdb`, no current user, no `time()`, no capability checks                        |
| `Context`, `ContextLineItem`           | Immutable inputs. Amounts in order currency plus `base_rate`; `get_real_coupon_codes()` drops our own redeem coupon; `get_extra()` for trigger-specific facts (`verified_purchase`)                                                  |
| `ContextFactory`                       | `from_order()` (order facts `payment_method` / billing / shipping country in `extra`), `from_user()`, `from_comment()` (verified-purchase meta in `extra`), `from_product()`, `from_cart()`. `customer_facts()` reads facts only when a conditions evaluator is attached (Pro). `yayloyalty_context_base_rate` is the multi-currency seam |
| `CustomerFacts`                        | Lifetime points, paid-order count and spend per currency (orders created before the evaluated event, excluding its own order), tier — so Pro's `ConditionsEvaluator` compares instead of querying |
| `CustomerFactsReader`                  | `read( user_id, as_of, exclude_order_id )` — with `ContextFactory`, the engine's second impure boundary: `wc_get_orders` over `OrderStatus::paid()` plus `LedgerRepository::lifetime_earned_before()` |
| `Triggers\Registry` + `TriggerHandler` | `PurchaseHandler` (gates: min_subtotal, exclude_coupon_orders, multiplier needs an active default), `SignupHandler`, `ReviewHandler` (require_verified). `with_lite_handlers()` fires `yayloyalty_register_trigger_handlers` for Pro |
| `BaseCalculator`, `CapPolicy`          | Earning base (exclusions, before/after discounts, tax, loyalty-discount add-back), per-order and daily caps; rounding is `Utils\PointsMath::round_points()`                                                                          |
| `Result`, `Breakdown`                  | Outcome + how it was reached. Storefront exposes `get_points()` only                                                                                                                                                                 |

Filters (public API): `yayloyalty_campaign_candidates`, `yayloyalty_campaign_matches`, `yayloyalty_earning_base_amount`, `yayloyalty_points_for_order`.

### Earning orchestration (includes/Engine/Earning/) — award path shipped

`namespace YayLoyalty\Engine\Earning`. The award path around the pure engine: listeners build a `Context`, `EarningService` runs gates then `PointsEngine::evaluate()` (**preview**), and `award()` repeats the preview at the same `occurred_at` then writes through `LedgerService::earn()` inside one `Transaction::run()`, releases pending, marks awarded in order meta, fires `yayloyalty_points_awarded` after commit. See its `README.md` for the ten invariants; its behaviour is asserted in `tests/integration/Earning/` (real orders, reviews, signups) and `tests/unit/Earning/`.

| Class | Role |
|---|---|
| `EarningService` | `build()` composition root (constructs `LedgerService`, see `code-standards.md` §8) — **implemented**, called from `YayLoyalty::__construct()`; `preview( $trigger, Context ): Result` — **implemented** (gates → engine → reason/Result); `award( $trigger, Context ): Outcome` — **implemented** (preview → earns/releases/marks_awarded → yayloyalty_points_awarded after commit) |
| `Gate` + `ProgramGate` | `check( $trigger, Context ): ?string` — a reason to skip, or null. `ProgramGate` is the one place on the path that reads settings (`excluded_roles`) and user meta (`_yayloyalty_opted_out`) — **implemented** |
| `IdempotencyKey` | `compose( type, user_id, reference_type, reference_id, trigger )` → `earn:7:order:1234:purchase`; the ledger's single-column UNIQUE guard; `qualifier_of()` parses the qualifier — **implemented** |
| `Outcome` | `awarded( entry, evaluation )`, `skipped( reason )`, `duplicate( existing )` — a duplicate insert is a normal return, never an exception — **implemented** |
| `PendingPoints` | `stage()` — writes order meta `_yayloyalty_points_pending` and increments customer row; `release()` — sets `_yayloyalty_points_released` once and decrements the customer row; `mark_awarded()` — writes `_yayloyalty_points_earned` (sum), `_yayloyalty_campaign_id` (first) — **implemented** |
| `Listeners\OrderListener`, `SignupListener`, `ReviewListener` | One per event family; `register()` binds the WooCommerce / WP hooks — **implemented and registered at boot**. Award handlers fire and write: `OrderListener::on_stageable()` stages at `pending`/`on-hold` (from `woocommerce_new_order` or a status transition); `on_awardable()` awards at `completed`; `SignupListener::on_registered()` awards (self-registration only: no current user, not WP-CLI, not cron, not Action Scheduler); `ReviewListener::on_approved()` awards with `one_per_product` and a per-source daily cap — **all implemented** |

**Implementation status:** real: `EarningService::preview()` and `award()`, `PendingPoints::stage()` / `release()` / `mark_awarded()`, `IdempotencyKey::qualifier_of()`, `Outcome`, `CustomerFacts`, `CustomerFactsReader`, `ProgramGate`, the gate-chain loop, `LedgerService::earn()`, `LedgerRepository::find_by_idempotency_key()` / `points_earned_today()` (daily cap by source), `Transaction::after_commit()` for post-commit hooks. Listeners `register()`, routing entry points (`route()`), and award handlers `on_stageable()` / `on_awardable()` / `on_registered()` / `on_approved()` all **implemented and wired at boot**. Reversal is real too: `Reversal` (`full_order()`, `refund()`, `refund_deleted()`, `full_review()`) with `ClawbackMath::owed()`, `LedgerService::refund_earn()` / `restore_refund()`, `OrderReversalListener` and `ReviewListener::on_unapproved()`. Every listener adapter runs through `Listeners\ListenerGuard`.

### Reward engine (includes/Engine/Rewards/) — ZONE 1, the quote

`namespace YayLoyalty\Engine\Rewards`. For one customer, one cart and one reward: how many points may be spent, what discount that buys and which limit bit. Writes nothing; pure (no `$wpdb`, no WooCommerce, no current user, no `time()`); runs on every cart recalculation. No eligibility here — gates live in `Engine\Redemption`.

| Class | Role |
|---|---|
| `Clamp` | **The one place a limit is computed.** `apply( Reward, int $requested, int $balance, ?float $cart_subtotal, string $currency ): Result` — ceilings balance → `max_points_per_order` → cart (after other coupons, tightened by `max_percent_of_subtotal`), floored to `increment` (one currency unit by default), zeroed below `min_points`; reports the binding ceiling as `BOUND_*`. Real, unit-tested |
| `RewardEngine` | `evaluate( Reward, Context ): Result` — type handler lookup + quote. Real |
| `Context` | Immutable inputs: user id (nullable), balance, points_requested, cart_subtotal (net of other coupons, nullable), currency, at, `CustomerFacts` (nullable). Built only by `ContextFactory` or by hand in a test |
| `ContextFactory` | `from_cart( \WC_Cart, ?int $user_id, ?int $points_requested )`, `from_order( \WC_Order, int $locked_balance )` — the one class on the quote path that reads WooCommerce and the customer row; typed on `Repositories\CustomerReader` so it cannot write. Stubs |
| `Result` | The quote. `allowed( Reward, requested, points, value, currency, rate_snapshot, bound_by )` or `denied( reason )`. Never stored |
| `Handlers\Registry`, `Handlers\RewardHandler` | Type → handler; `with_lite_handlers()` fires `yayloyalty_register_reward_handlers` for Pro |
| `Handlers\FlexibleDiscountHandler` | Lite's only type; one-line delegate to `Clamp`. Stub |

### Loyalty Email Registration (includes/Engine/Emails/, includes/Pro/Emails/)

Lite registers 3 transactional loyalty emails; Pro adds 9 more. Each is a `WC_Email` subclass registered through WooCommerce's `woocommerce_email_classes` and `woocommerce_email_groups` filters (not yayloyalty seams). The email id doubles as the WooCommerce option name. On/off toggled via `EmailsHelper::update_emails()` (WooCommerce option); sending info (from-name, from-address) read live from WooCommerce; weekly throttle stored in plugin settings but not enforced yet. No trigger() hooked — registration-only; sending not built.

| Class | Role |
|---|---|
| `Engine\Emails\LoyaltyEmail` | Abstract base: customer email, template id derived from id |
| `Engine\Emails\{PointsEarned,PointsExpiringSoon,PointsExpired}Email` | Lite: three concrete emails |
| `Engine\Emails\Emails` | Registrar and group definitions for Lite (GROUP_POINTS); fired from `YayLoyalty::initialize()` |
| `Pro\Emails\ProLoyaltyEmail` | Pro base: templates under `includes/Pro` so Lite strips them on build |
| `Pro\Emails\{BirthdayPoints,CouponIssued,ExpiringFinalReminder,InviteSent,ReferralRewardEarned,RewardClaimed,TierEndingSoon,TierLost,TierReached}Email` | Pro: nine more concrete emails |
| `Pro\Emails\ProEmails` | Pro registrar: four groups (Rewards, Referral, Tier, Promo), nine emails; fired from `YayLoyaltyPro::boot()` |

### Third-party integrations (includes/Integrations/)

| Class | Role |
|---|---|
| `Integrations/YayMail/YayMailIntegration` | Boot gate: `class_exists('YaymailPluginAdapter', false) && is_licensed()` when available; registers on `yaymail_register_emails` / `yaymail_register_shortcodes` hooks |
| `Integrations/YayMail/PointsEarnedYayMailEmail` | Extends `BaseEmail`; swaps WC template via `wc_get_template` when YayMail template enabled; default layout Logo/Heading/Text/Button/Footer |
| `Integrations/YayMail/PointsEarnedShortcodes` | 10 shortcodes (yaymail_yayloyalty_{customer_name, earned_text, points, points_label, balance, balance_text, balance_value, balance_value_text, order_number, points_url}); values memoized per award; sample award for is_sample |

### Redemption orchestration (includes/Engine/Redemption/) — ZONE 2 and the adapters

`namespace YayLoyalty\Engine\Redemption`. The impure shell around the pure quote, mirroring `Engine/Earning` around `Engine/Points`. See its `README.md` for the nine invariants; the test names under `tests/unit/Redemption/` are the specification.

| Class | Role |
|---|---|
| `RedemptionService` | `build()` composition root (LedgerService's fourth construction site); `quote( \WC_Cart, ?int $user_id ): Result` (Zone 1, writes nothing); `checkout_error( \WC_Cart, ?int ): ?string` (affordability before totals freeze); `commit( \WC_Order ): Outcome` (Zone 2). Stubs |
| `Quoter` | The pure half: `quote( Reward, Context ): Result` — gates cheap-first, first reason wins, then the engine. Holds nothing that can write. Real; one instance shared by quote and commit |
| `Gate` | `check( Reward, Context ): ?string` |
| `ProgramGate`, `RewardGate` | Logged in / program on / coupons enabled; reward live / fixed cost vs balance / `yayloyalty_reward_conditions_match`. Stubs |
| `SessionIntent` | The only mutable state on the quote path: one integer, `yayloyalty_redeem_points`, unclamped. Stubs |
| `CouponProvider` | Virtual coupon `yayloyalty-redeem` via `woocommerce_get_shop_coupon_data` (fixed_cart, `individual_use` false, no free shipping), sorted last via `woocommerce_coupon_sort`, amount re-quoted on every totals run. Stubs |
| `CommitWriter` | Holds everything that writes. One `Transaction::run()`: lock customer row → duplicate check → re-quote with the locked balance → redemptions row → `LedgerService::redeem()` → order meta. Zone 3 seam named in its docblock. Stubs |
| `Adapters\ClassicCheckoutAdapter`, `Adapters\StoreApiAdapter` | Hook translation only; both call the same three service methods. Stubs |

**Scaffold status:** real: `Quoter`, `Clamp`, `Result`, `Context`, `Models\Redemption`. Stubs (`TODO(F08)`): everything else above plus `Repositories/RedemptionRepository`, `Database/Schema/RedemptionsTable` (not yet in `Installer::schemas()`), `LedgerService::redeem()`. Not started: the block UI, Zone 3 (cancel/refund), Pro catalog coupons.

### Database

| File                                  | Role                                                                                                                                                                      |
| ------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `Database/Installer.php`              | Versioned schema; applies via `dbDelta`; runs on `plugins_loaded` priority 20 (outside `plugin_init`) so headless/REST paths upgrade. `run_migrations()` holds what `dbDelta` cannot express (index drops; `drop_index_if_exists()` is a stub) |
| `Database/InitYayLoyaltyCustomer.php` | Starter data seeding: creates 5 real WooCommerce customers with real ledger history (via `LedgerService::adjust()`); split from Installer to keep both under 200-line cap |
| `Database/Transaction.php`            | Nesting-aware; probes for foreign transactions via savepoint round-trip; guards on reconnect; treats failed rollback as fatal                                             |
| `Database/QueryException.php`         | Thrown by repositories on write failure                                                                                                                                   |
| `Database/Schema/*Table.php`          | `CREATE TABLE` DDL for each table; subclass `AbstractTableSchema`. `RedemptionsTable` exists as a DDL stub and is not registered yet |

### Utilities & Helpers

| Type                              | Classes                                                                                                                                                                                                                                                                                                                                                                                | Char                                  |
| --------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------- |
| `Utils/` — stateless, portable    | `Dates` (UTC; `to_utc()` turns any date object into the stored form), `Text` (`plain()`: store names without tags or entities), `Json` (encode/decode maps; encode_list/decode_list), `Log` (writes to WC logs or `error_log`), `Table` (safe table name), `Sql` (`in_placeholders()` for prepared `IN (…)` lists), `WooOrders` (`is_hpos()`, the one HPOS check behind order admin links), `SingletonTrait`, `ConditionValueRules` (validation for list/int/between/cap shapes), `PointsMath` (round_points / pct_delta / pct_of / points_to_currency — mirrored in the SPA, synchronised by `tests/fixtures/points-math-vectors.json`) | Lift to another plugin? Yes (almost). |
| `Helpers/` — plugin-specific glue | `SettingsHelper` (read/update plugin settings), `DesignHelper` (design option: load, merge, reset) with `DesignSchema` (defaults + per-field rules) and `DesignCoercion` (applies a rule), `EmailsHelper` (list registered emails, update on/off and throttle), `LocalizeHelper` (build admin payload, incl. the roles/gateways/countries/currencies vocabularies the pickers offer)                                                                                                                                                                  | Knows this plugin.                    |
| `Access/` — capabilities          | `Capabilities` (the seven cap names — campaigns and rewards are separate — role defaults, `install()`/`migrate()`/`uninstall()`, `ceiling()`, `payload()` for the SPA). Checked at the HTTP boundary only; services take an `actor_id`.                                                                                                                                                | Knows this plugin.                    |

**Key rule:** `Helpers/` reads options and SPA state. `Utils/` never does.

### Pro Extension (includes/Pro/)

| File | Role | Seam |
|---|---|---|
| `Pro/YayLoyaltyPro.php` | Boot; attaches all Pro filters; fires `yayloyalty_pro_booted` | — |
| `Pro/Services/ConditionsEvaluator.php` | Evaluates campaign conditions (ANDed) as a pure comparison against the Context; fails on unknown type or missing input | `yayloyalty_campaign_conditions_evaluator` |
| `Pro/Controllers/CampaignPreviewRestController.php` | POST `/campaigns/{id}/preview-conditions` — 404 in Lite | `yayloyalty_rest_controllers` |
| `Pro/Controllers/CampaignPerformanceRestController.php` | `GET /campaigns/{id}/performance` (the decorated campaign itself + totals/daily/skipped/estimate), `GET /campaigns/{id}/performance/entries` (paged rows) via `Pro/Repositories/CampaignPerformanceRepository.php` (ledger aggregate + paged/searchable query, real) — cap `MANAGE_CAMPAIGNS`, `purchase`/`signup`/`review` only (other triggers 404); `totals` lifetime, chart `window`/`daily` last-30-days; `skipped`/`estimate`/`avg_order_subtotal` still `null` pending the earning engine (F01-4); 404 in Lite | `yayloyalty_rest_controllers` |
| `Pro/Controllers/DashboardProRestController.php` | `GET /dashboard/pro?range=` (rest base `dashboard-pro`) — per-campaign series + top campaigns via `Pro/Repositories/CampaignPointsRepository.php` (standalone read-only, ledger `campaign_id`); referrals/tiers `null` until their tables exist; 404 in Lite | `yayloyalty_rest_controllers` |
| `Pro/Controllers/CustomerSegmentsRestController.php` | POST `/customers/segments`, DELETE `/customers/segments` (name in body) — save/delete saved Customers-page filter sets (name + filters minus search); 404 in Lite | `yayloyalty_rest_controllers` |
| `Pro/Helpers/CustomerSegmentsHelper.php` | `yayloyalty_customer_segments` option: site-wide list of `{name, filters: {snake_case keys}}`, whitelisted both directions; name is the identity, duplicates rejected (no upsert) | — |

**Seams (filters and actions):** where each fires and who subscribes: [`system-architecture.md` → Internal Extension Seams](./system-architecture.md#internal-extension-seams-filters).

---

## React Admin App (apps/admin/)

**Stack:** React 18 + Vite 6 + TanStack Query (React Query) + Zod + Ky + shadcn/Tailwind

| Path | Contents |
|---|---|
| `src/main.tsx` | Entry point; imports Pro barrel if `IS_PRO_BUILD` |
| `src/router.tsx` | Hash router with routes for `/dashboard`, `/customers`, `/campaigns`, `/campaigns/new`, `/campaigns/new/:templateId`, `/campaigns/:id/edit`, `/campaigns/:id/performance`, `/rewards`, `/referral`, `/vip-tiers`, `/settings` (each gated by capability via `<Can>`). `/customers` has one child route, `:userId` → `CustomerDrawer` (F09) — the parent's `<Can>` gate covers it, not re-wrapped. `/settings` nests `general | design | emails | advanced` under `SettingsLayout`. The four campaign sub-routes mount Lite-safe pages (`CampaignAddPage`/`CampaignCreatePage`/`CampaignEditorPage`/`CampaignPerformancePage`) that render the `@/pro` component or 404 in Lite |
| `src/pages/` | Route pages; `dashboard/`, `campaigns/`, `customers/` and `settings/` have content; Rewards, Referral, VIP Tiers are placeholders |
| `src/pages/dashboard/` | `DashboardPage.tsx` (one `IS_PRO_BUILD` branch: `DashboardProPanels` from `@/pro` vs `DashboardProTeasers`, both feeding the same `DashboardProSlots`); `components/` (stat tiles/grid, recharts points chart + frame, top earners, recent activity, empty state, cache strip, skeletons, notice strip/card, anomaly panel/settlements); `dashboard-page.copy.ts` + `dashboard-page.notice-copy.ts` own every sentence (server sends structured notice data only); `?scenario=` read from the hash route on the dev server only |
| `src/pages/campaigns/` | `CampaignsPage.tsx` routes: Lite renders `BuiltInCampaignList` (three fixed cards for purchase/signup/review; the On/Off switch and "Save changes" both persist for real via `updateCampaignStatus()`/`updateCampaignConfig()`), Pro renders `CampaignTable` (grouped by trigger) + `CampaignPointsSummary` card. Both read from the same `useCampaignsQuery()` (real `GET /campaigns`, real rows, `points_issued`/`is_deletable` included). `CampaignAddPage.tsx`/`CampaignCreatePage.tsx`/`CampaignEditorPage.tsx`/`CampaignPerformancePage.tsx` are the thin Lite-safe wrappers behind `campaigns/new[/:templateId]`, `campaigns/:id/edit` and `campaigns/:id/performance` — each just an `IS_PRO_BUILD` branch importing its real component from the `@/pro` barrel |
| `src/pages/customers/` | Server-paginated list (search/filter/sort/export/row-select), real `GET /customers`; bulk "Adjust points"/"Export" wired to real endpoints (via `customers-page.bulk-actions.ts`, `customers-page.export.ts`); the adjust dialog (`components/CustomersAdjustPointsDialog.tsx` + `components/adjust-points/`) takes an explicit single/bulk target — single reads `GET /customers/{id}` (one fresh read per open, baselined for a pre-submit stale-balance re-check that fails closed), bulk reads `POST /customers/adjust/preview` for count/liability/rate; the pure rules (typo guard, liability math, `canSubmit()`, bulk effective count) live in `customers-adjust-points.form-state.ts` and are Vitest-pinned; a partial bulk failure narrows the selection to the failed customers and resets consent; selection resolves via fast path or sequential walk of `GET /customers` (see `customers-page.matching-customers.ts`, max 500 pages); Tier column/filter are sample-data stand-ins over `balance_range` (see `customers-page.tier-sample-data.ts`—to be deleted when VIP Tiers ships); Pro adds a saved-segment picker above the toolbar; table dims to 60% opacity during background page/sort/filter refetch (placeholderData keeps rows on screen; no toolbar spinner); below `sm` the `<Table>` is swapped for `components/CustomersCardList.tsx` (same three states) and column-header sorting for `CustomersSortDropdown.tsx`; the search box debounces through `hooks/useDebouncedSearchCommit.ts` (empty commits immediately, non-empty 300ms/`maxWait` 800ms, generation counter cancels stale commits) |
| `src/pages/customers/drawer/` (F09, Wave A+B complete) | `CustomerDrawer.tsx` — Sheet shell for the `customers/:userId` child route, rendered through the list's `<Outlet/>` so it stays mounted/dimmed behind the drawer; full-screen below `sm` (5c), 600px panel above. Header/stats (five-stat `auto-fit`/`minmax(150px,1fr)` grid, never abbreviated, 2-column reflow below `sm` — see `CustomerDrawerStats.tsx`'s docblock for the 4-vs-5 decision)/actions (Adjust points is the one live action; Reset expiry/Export ledger/[Assign tier] are permanently disabled with a stated reason — no endpoint yet)/deleted-account, expiring-points and negative-balance panels (mutually exclusive, header-level, each keyed off a real wire field that is always null today — no `deleted_at` column, no `lots` table, no backwards ledger walk) / a 4-tab bar with counts, all four with real paged bodies now (Points ledger, Activity log, Redemptions, Referrals — the latter two `ProGate`-badged, Pro-only content behind a tab both builds see). Every tab table renders through `tabs/DrawerTable.tsx` (shell, skeleton, empty state, `drawerCell()` responsive cell class); the two filterable tabs own their state through `customer-drawer.tab-query-state.ts` (`useDrawerTabQueryState`) and render `tabs/DrawerTabFilters.tsx`; `DRAWER_PAGE_SIZE` (`tabs/DrawerTabPager.tsx`) is the one page size. `customer-drawer.copy.ts` + `customer-drawer.panels.copy.ts` + `customer-drawer.referrals.copy.ts` own every sentence between them (split once the first hit its 200-line cap). The real path always serves the real repository or an honest empty envelope (Redemptions/Referrals — no backing table exists for either yet, see `docs/data-model/redemptions.md`, `docs/data-model/future-tables.md`) |
| `src/pages/settings/` | `SettingsLayout` wraps the four tab pages (GeneralSettingsPage, DesignSettingsPage, EmailsSettingsPage, AdvancedSettingsPage); Design and Emails are live, General and Advanced are stubs; each page has `.copy.ts` and `.form.ts` companions for strings/schema |
| `src/pro/campaigns/` | **Deleted in Lite build** — Pro campaign features: `CampaignTable.tsx` (full table with grouping, priority, scheduling, conditions); `CampaignPointsSummary.tsx` (points contribution card, real `points_issued` from the campaigns list); `ConditionsPreview.tsx` + `conditions-preview.api.ts` (POST `/campaigns/{id}/preview-conditions` for condition-builder live evaluation); bulk archive/pause/delete via `useBulkCampaignWritesMutation()` (per-id calls through `lib/helpers/settle-in-chunks.helper.ts`, no bulk endpoint; the Customers bulk adjust uses the same helper); `components/ui/custom/bulk-actions-menu.tsx` is the shared bulk-action menu |
| `src/pro/campaigns/add/` | **Deleted in Lite build** — Add-campaign chooser (`campaigns/new`): `CampaignTemplatePicker.tsx` (one card per template, `campaigns/new/:templateId` on pick), `campaign-templates.ts` (`getCampaignTemplates()` — a function, not a module-level const, so the bundler defers every `__()` call rather than shipping Pro-only marketing copy as readable text in Lite's JS; each template's `seed: () => CampaignFormValues` must return fresh values per call, never shared across visits) |
| `src/pro/campaigns/editor/` | **Deleted in Lite build** — Campaign create + edit form, one component tree: `CampaignEditor.tsx` exports both `CampaignEditor` (`campaigns/:id/edit`) and `CampaignCreator`/`CampaignCreatorForm` (`campaigns/new/:templateId`, optionally `?from=<id>` for Duplicate), sharing a skeleton, the six sections and the summary rail. The edit header shows the saved campaign (status via `CampaignStatusBadge`), an "Unsaved changes" marker while the form is dirty, and a "View performance" button. `CampaignEditorContext` (`campaign-editor.form.ts`) carries `mode: 'create' | 'edit'` and `trigger` outside react-hook-form state — `campaign.trigger` fixed on edit, component state + `onTriggerChange` on create, since changing it also resets `config`/`conditions`. `CampaignEditorSections.tsx` (Basics/Trigger/Schedule/Priority and the section shell), `CampaignEditorReward.tsx` (per-trigger reward config editors for the Lite triggers, read-only passthrough for Pro-only triggers), `CampaignEditorRewardPurchase.tsx` (the purchase rate or rate/multiplier choice plus the Advanced block, which opens itself when anything in it is set), `CampaignEditorConditions.tsx` (10-type condition builder + `validateConditionValue()`), `CampaignEditorSummaryRail.tsx` ("What this means"/"Before it can run"/save; component only), `campaign-reward.copy.ts` (the one reading of which purchase settings are set: short phrases for the collapsed Advanced toggle, rate/counting/limit sentences for the rail), `campaign-summary.copy.ts` (the rail's prose and readiness checklist, pure over form values), `campaign-editor.form.ts` (`CampaignFormValues` = `CampaignUpdateInput`; `formValuesToCreateInput()`/`formValuesToUpdateInput()`; `duplicateToFormValues()`; `synthesizeDraftCampaign()` — create mode's placeholder `Campaign`, `id: Number.MAX_SAFE_INTEGER` so a new row's priority ties resolve the way it will once saved; `starts_at`/`ends_at` stay raw UTC — the Schedule section converts at its inputs only). First RHF + `zodResolver` use in this codebase; `useRouteLeaveGuard` for unsaved changes in both modes, wired once by `use-campaign-editor-save.ts` (both save buttons, the leave dialog's Save, the redirect after a save). Saves go through `useUpdateCampaignMutation()`/`useCreateCampaignMutation()` → `updateCampaign()`/`createCampaign()`, both real |
| `src/pro/campaigns/archive/` | **Deleted in Lite build** — Archive, delete and bulk actions, reached from `CampaignTable`'s row menu and bulk bar: `CampaignArchiveDialog.tsx` (one campaign — delete/finished/live cases from real `is_deletable` + display status; also exports `CampaignBulkArchiveDialog` for several), `campaign-archive.copy.ts` / `campaign-bulk-archive.copy.ts` (pure copy; per-trigger sentences written out whole, not spliced) |
| `src/pro/campaigns/pause/` | **Deleted in Lite build** — Pause confirmation (not a toggle — pausing a default campaign can stop earning store-wide): `CampaignPauseDialog.tsx`, `campaign-pause.copy.ts` (shares `activeStops()`/`liveStanding()` with `campaign-archive.copy.ts` rather than duplicating them) |
| `src/pro/campaigns/performance/` | **Deleted in Lite build** — Campaign performance page (`campaigns/:id/performance`, purchase/signup/review only): `CampaignPerformance.tsx` (composes the page/route entry), `CampaignPerformanceTiles/Chart/SidePanel/Entries.tsx` (tiles use `components/ui/custom/stat-tile.tsx`, the delta-less sibling of `DashboardStatTile`), `campaign-performance.copy.ts` (pure, trigger-aware copy), `campaign-performance.api/queries.ts`. Reads `lib/schema/campaign-performance.schema.ts`; entry points are `CampaignTable`'s row menu and the editor header's "View performance" button (both gated by `hasPerformancePage(trigger)`) |
| `src/pro/dashboard/` | **Deleted in Lite build** — `DashboardProPanels` (render-prop slots), tier distribution, top campaigns, breakdown toggle, `dashboard-pro.api/queries.ts`, `dashboard-pro-dummy.ts` (referrals + tiers, SWAP POINT) |
| `src/pro/campaigns/campaign-labels.ts` | `campaignStatusBadgeVariant()` (status → badge colour, rendered by `CampaignStatusBadge.tsx` in the table, editor, performance page and bulk-archive list); `givesLabel()` / `conditionsLabel()` shared by `CampaignTable`, `DashboardTopCampaigns`, the pause/archive dialogs, the performance page and the campaign editor's rate lines |
| `src/pro/customers/` | **Deleted in Lite build** — saved-segment feature: `CustomersSavedSegmentRow`, `CustomersSaveSegmentDialog`, `customers-saved-segment-row.state.ts`, `customer-segments.api.ts` (converts camelCase ↔ snake_case wire shape) |
| `src/components/pro/` | **Kept in Lite** — `ProGate`, upsell UI, badge components |
| `src/components/ui/chart.tsx` | Vendored shadcn chart primitive for recharts 2 (`.yay-dark` selector, `--chart-1..5` tokens from `styles/theme.css`) |
| `src/lib/points-math/points-math.ts` | camelCase mirror of `Utils\PointsMath`; the only place the SPA computes a points figure. `tests/points-math.vectors.test.ts` (Vitest, `pnpm test`) runs the shared vector file |
| `src/lib/schema/dashboard*.schema.ts` | Zod mirrors of the dashboard contracts: `dashboard.schema.ts` (Free), `dashboard-notice.schema.ts` (per-key notice `data`), `dashboard-pro.schema.ts` (Pro) |
| `src/lib/` | Helpers: `api/` (REST calls), `queries/` (TanStack Query), `schema/` (Zod), `utils.ts`, `is-pro-build.ts`, `helpers/`, `access.tsx` (`CAP`, `can()`, `<Can>` — hide/disable by capability from `window.yayLoyaltyAdmin`; cosmetic, the server decides) |
| `src/types/` | TypeScript type definitions |
| `src/hooks/` | `useDebouncedSearchCommit` (shared search debounce policy — Customers list, drawer tabs, `lookup-multi-select`), `useDevScenario` (the one client-side `?scenario=` reader; `undefined` outside the Vite dev server), `useRouteLeaveGuard` (unsaved-changes dialog on navigation — campaign create and edit), `useCombinedRefs`, `useUncontrolled` |

**Build-time gate:** `IS_PRO_BUILD` is `import.meta.env.VITE_IS_PRO` (NOT a Vite `define`). Set by vite.config.ts from `BUILD_TYPE` environment variable. Literal substitution → dead code elimination. No runtime pro flag ever sent to browser.

**Component gating:** `<ProGate>` gates rendering; `IS_PRO_BUILD &&` gates inclusion. Both needed.

---

## Request Flows

### Order Award → Ledger Entry (award path implemented, 2026-09-30)

```
[Listeners and award handlers registered from YayLoyalty::__construct(); full award path implemented 2026-09-30]

WooCommerce order created / reaches award status
  ↓
Engine\Earning\Listeners\OrderListener::on_status_changed() / on_order_created()  →  route()  →  on_stageable() / on_awardable()
  ↓
ContextFactory::from_order( $order, LedgerRepository::points_earned_today() )   ← the only WooCommerce read; occurred_at = order creation date
  ↓
EarningService::preview( 'purchase', $ctx )
  ├─ gates: ProgramGate (excluded_roles, _yayloyalty_opted_out) → Outcome::skipped( reason )
  └─ PointsEngine::evaluate( 'purchase', $ctx )   pure: campaigns only; liveness at the ORDER's time
       ├─ CampaignRepository::live_for_trigger()
       ├─ first campaign: conditions_match() AND handler->is_eligible()   (gates fall through, never zero)
       ├─ PurchaseHandler → BaseCalculator → PointsMath::round_points → CapPolicy
       └─ Result: points, campaign, breakdown, reference, source | none( reason )
  ↓ on_created: PendingPoints::stage()   idempotent on _yayloyalty_points_pending
  ↓ on_awardable:
EarningService::award( 'purchase', $ctx )   preview again, same occurred_at
  ↓
Transaction::run()
  ├─ LedgerService::earn( $draft )
  │    ├─ CustomerRepository::lock_and_get()      SELECT … FOR UPDATE → unbroken balance_after chain
  │    ├─ LedgerRepository::insert()              UNIQUE uniq_idempotency_key; MySQL 1062 → Outcome::duplicate
  │    └─ CustomerRepository::apply_delta()       balance, lifetime_earned
  └─ PendingPoints::release() / mark_awarded()
  ↓ after commit
do_action( 'yayloyalty_ledger_recorded' ), do_action( 'yayloyalty_points_awarded' )
  ↓
Outcome: awarded | skipped( reason ) | duplicate
```

`SignupListener` (`user_register` → `ContextFactory::from_user()`) and `ReviewListener` (comment approval → `from_comment()`) follow the same preview/award shape without the pending stage. Points come back out through `OrderReversalListener` (cancel, trash, refund, refund deleted) and `ReviewListener::on_unapproved()`, both calling `Engine\Earning\Reversal`.

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
| 2. TypeScript check | `tsc --noEmit` | Passes on the app's own code; fails only on 3 pre-existing errors from 2 files missing dependencies never added to `package.json` (`@emoji-mart/data`, `@emoji-mart/react` in `emoji-picker-content.tsx`; `@radix-ui/react-progress` in `progress.tsx`) — pre-existing on `main`, unrelated to any feature branch, not a regression |
| 3. Stage | Internal to `run.sh` | Copy tree, strip `includes/Pro/` for Lite, run `composer dump-autoload --no-dev --classmap-authoritative` (dev deps restored after packaging) |
| 4. Package | `zip -r yayloyalty-{version}.zip yayloyalty/` | Create distributable; strip `.gitkeep`, `RegisterDev.php`, `.map`, `.vite`, `.DS_Store` |
| 5. Validate | `php scripts/validate-build.php <zip> <pro|lite> [--require-boot]` | Check sentinel, Pro/Lite consistency, PHP lint, headless boot |
| 6. Output | `release/yayloyalty-{version}.zip` | Ready for release |

**Pro first, Lite second:** Version is stamped once; Lite uses `--no-prompt` to skip re-prompting. After release, run `composer install` to restore dev deps.

---

## File Naming Conventions

| Kind              | Case                                             | Example                                       |
| ----------------- | ------------------------------------------------ | --------------------------------------------- |
| PHP class file    | PascalCase (matches class, autoloader dependent) | `Models/Customer.php`                         |
| React component   | PascalCase                                       | `DashboardPage.tsx`                           |
| TypeScript module | kebab-case + dot-namespace                       | `settings.api.ts`, `customer.queries.ts`      |
| Documentation     | kebab-case                                       | `code-standards.md`, `system-architecture.md` |

---

## Execution Context Clues

- **Plugin init:** `plugins_loaded` hook (late, after WooCommerce)
- **Admin JS:** Enqueued by `RegisterProd` / `RegisterDev`; communicates via `ky` to REST
- **REST:** Namespace `yayloyalty/v1`, all under `yayloyalty_rest_controllers` filter
- **Headless / WP-CLI:** Runs via `plugins_loaded` — no `admin_init` required
- **Tests:** unit (`tests/unit/`, `composer test`), integration (`tests/integration/`, `composer test:integration`) and Vitest (`apps/admin/tests/`) — what each checks and its count live in `code-standards.md` § 14.

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
- **`data-model.md`** — 13-table specification; 5 tables implemented; schema reference
- **`system-architecture.md`** — Architecture diagrams, Pro/Lite mechanism, build pipeline detail
