# YayLoyalty — Project Roadmap

Implementation status of features defined in `data-model.md`. All dates, timelines, and priority levels are derived from code and schema only. Gaps are the known limitations, not a prioritized backlog.

---

## Implementation Status by Phase

Data model v0.3 specifies a roadmap across phases 1.0, 1.1, 1.2, and 2.0. Current build is **1.0.0** with Pro (Pro/Lite split); only core tables exist.

### Phase 1.0 — Core (Current Release)

| Table | Spec | Status | Notes |
|---|---|---|---|
| **`campaigns`** | Core earning rules | ✅ **SHIPPED** | Full CRUD; trigger filtering (Lite/Pro); Pro conditions evaluator; import/export ready |
| **`customers`** | Member cache & state | ✅ **SHIPPED** | Lazy-initialized; aggregates; expiry basis; no tier/referral fields yet |
| **`ledger`** | Balance journal, append-only | ✅ **SHIPPED** | Immutable; idempotent on unique key; SOLE writes via LedgerService |
| **`rewards`** | Ways to spend points | ✅ **SHIPPED** | Schema, model, repository, enums; Lite seeds flexible_discount; Pro adds coupon types; no checkout integration yet |
| **`redemptions`** | Reward claim records | ❌ **NOT STARTED** | Fact table; references rewards & ledger; multipart refund tracking |
| **`referrals`** | Referral relationships | ❌ **NOT STARTED** | Advocate → friend tracking; link attribution; status pipeline |
| **`referral_visits`** | Link click events | ❌ **NOT STARTED** | Ephemeral; pruned daily; funnel analytics |
| **`tiers`** | VIP levels | ❌ **NOT STARTED** | Thresholds, perks, ranks; no tier change logic |
| **`tier_history`** | Tier movement audit | ❌ **NOT STARTED** | Evaluation window, basis capture, manual promotion |
| **`lots`** | Per-earning expiry | ❌ **NOT STARTED** (Pro) | FIFO consumption; per-lot expiry mutable counter |
| **`lot_allocations`** | Lot consumption | ❌ **NOT STARTED** (Pro) | Tracks how redemptions/expiry consumed lots |
| **`events`** | Event outbox | ❌ **NOT STARTED** (2.0) | Webhook/webhook-safe persistence; delivery tracking |

### Phase 1.1 — Referrals

**Status: SPEC COMPLETE, IMPLEMENTATION DEFERRED**

All referral tables (`referrals`, `referral_visits`) specified in data model; no code exists yet. Triggers recognized (`referral` in `CampaignTrigger::all_known()`) but not fireable (missing from filtered `all()`).

### Phase 1.2 — Tiers & VIP

**Status: SPEC COMPLETE, IMPLEMENTATION DEFERRED**

`tiers` and `tier_history` tables specified; evaluation window, downgrade policy, and tier basis settings ready in schema; no tier evaluation logic or UI.

### Phase 2.0 — Events & Webhooks

**Status: SPEC ONLY**

`events` table specified as an outbox pattern for webhooks and analytics. No implementation.

---

## Feature Implementation Status

### Earning (Campaigns)

| Feature | Spec | Status | Details |
|---|---|---|---|
| **Lite triggers** | `purchase`, `signup`, `review` | ⚠️ **COMPUTABLE, NOT FIRED** | `PointsEngine` + handlers score all three; nothing listens to WooCommerce events to call it (F01-4) |
| **Pro triggers** | `birthday`, `first_order`, `order_count`, `spend_milestone` | ⚠️ **OFFERABLE IN PRO, NOT FIREABLE** | Added to `CampaignTrigger::all()` by `YayLoyaltyPro`; Lite recognises them (`all_known()`) so a downgrade keeps the rows |
| **Referral trigger** | `referral` | ⚠️ **RECOGNIZED, NOT FIREABLE** | Enum only; referral engine not built |
| **Social trigger** | `social` | ⚠️ **RECOGNIZED, NOT FIREABLE** | Share-for-points not implemented |
| **Renewal trigger** | `renewal` | ⚠️ **RECOGNIZED, NOT FIREABLE** | Subscription renewal trigger not implemented |
| **Conditions (Pro)** | Role, product, category, lifetime spend, order count | ✅ SHIPPED (Pro) | ConditionsEvaluator; ANDed logic; preview endpoint |
| **Points allocation** | `purchase`: points_per_unit, base, rounding, min_subtotal, mode/multiplier | ✅ **SHIPPED (engine)** | `BaseCalculator` + `PurchaseHandler`; 46 engine unit tests, rounding via `Utils\PointsMath`; multiplier campaigns need an active default |
| **Max points cap** | `max_points_per_order`, `daily_cap` | ✅ **SHIPPED (engine)** | `CapPolicy`; `points_earned_today` is the caller's to supply from the ledger |
| **Campaign editor** | Create, read, update, delete | ⚠️ **API COMPLETE, UI INCOMPLETE** | REST endpoints working; SPA list is read-only |
| **Scheduling** | `starts_at`, `ends_at` liveness eval | ✅ SHIPPED | Liveness computed at read; no cron flips status |

### Redemption (Rewards)

| Feature | Spec | Status | Details |
|---|---|---|---|
| **Flexible discount** | Points → cart discount, slider | ✅ SHIPPED (schema) | Seeded in Lite; engine scaffolded (`Engine/Rewards/`, always denies `not_implemented`); no checkout integration yet |
| **Fixed/percent coupon** (Pro) | Issue WC coupon | ✅ SHIPPED (schema) | Seeded in Pro; no coupon generation yet |
| **Free product** (Pro) | Add to cart | ✅ SHIPPED (schema) | Seeded in Pro; no logic yet |
| **Free shipping** (Pro) | Shipping discount | ✅ SHIPPED (schema) | Seeded in Pro; no logic yet |
| **Refund handling** | Proportional/full/none policies | ❌ NOT STARTED | Spec written; no implementation |
| **Multi-currency** | Value + base_value + rate snapshot | ❌ NOT STARTED | Schema exists; no redemption flow |

### Points Management

| Feature | Spec | Status | Details |
|---|---|---|---|
| **Balance display** | Current spendable points | ✅ SHIPPED | REST `GET /customers/{id}`; SPA Customers page; TanStack Query |
| **Customers list** | Admin view of all members | ✅ SHIPPED | Real `GET /customers` — search/filter/sort/paginate in SQL, not demo data; see Admin UI → Customers below |
| **Pending points** | Awaiting order award | ❌ **COLUMN ONLY** | `customers.pending_points` exists and is inserted as 0; nothing writes it (no order handling yet) |
| **Lifetime earned** | Net earn total (unaffected by redeem/expire) | ✅ SHIPPED | Updated on earn/adjust; basis for tier (points mode) |
| **Lifetime redeemed** | Total points spent (excluding refunds) | ✅ SHIPPED | Maintained by `LedgerService` from ledger type; no redemption flow writes REDEEM rows yet |
| **Expiry** | Inactivity basis | ⚠️ **TRACKED, NOT ENFORCED** | Fields exist (`expires_at`, `reminder_sent_at`); no job runs |
| **Expiry per-lot** (Pro) | Lot-based expiry | ❌ NOT STARTED | Lots table unimplemented; no lot tracking |
| **Adjustment ceiling** | Per-role cap on one manual adjustment | ⚠️ **ENFORCED, NO UI** | `settings.advanced.adjustment_ceiling` (0 = unlimited) checked by `assert_ceiling()`; admins bypass; no settings field to edit it yet |
| **Rebuild balances** | Replay ledger → recalculate customer row | ❌ NOT STARTED | `balance = SUM(ledger.points)` is the invariant; no rebuild routine or UI exists |
| **GDPR erasure on account deletion** | Anonymize ledger, delete customer row | ✅ SHIPPED | `Engine\UserLifecycle` on `deleted_user`/`wpmu_delete_user` → `Services\CustomerErasureService`: ledger rows anonymized (`user_id`→0, `actor_id`/`note`→NULL, never deleted — see `data-model.md`), customers row deleted |

### VIP Tiers

| Feature | Spec | Status | Details |
|---|---|---|---|
| **Tier definitions** | Thresholds, perks, badges | ❌ NOT STARTED | Schema designed; no creation UI |
| **Tier evaluation** | Crossing threshold → promotion/demotion | ❌ NOT STARTED | Logic unbuilt; evaluation window unimplemented |
| **Tier history** | Audit trail of promotions | ❌ NOT STARTED | Table spec'd; no job to generate rows |
| **Downgrade policy** | Never / window-end | ❌ NOT STARTED | Policy setting exists; no logic |
| **Basis options** | Lifetime points / rolling 12mo / lifetime spend | ❌ NOT STARTED | Settings designed; no evaluation logic |

**Note:** Admin Customers page displays a cosmetic Tier column and working-but-substituted Tier filter (`customers-page.tier-sample-data.ts`) for UI-check purposes — these derive fake tier labels from customer balance and reuse the existing `balance_range` filter under the hood. This is not real VIP Tier progress; the filter and column will be deleted once the actual tier system ships.

### Referral Program

| Feature | Spec | Status | Details |
|---|---|---|---|
| **Referral codes** | Generate per user | ❌ NOT STARTED | Field exists (`customers.referral_code`); no generation |
| **Link attribution** | Cookie-based or code | ❌ NOT STARTED | Click tracking, email code; no implementation |
| **Friend reward** | Points or coupon | ❌ NOT STARTED | Reward type spec'd; no claim logic |
| **Hold period** | Before payout | ❌ NOT STARTED | Hold field exists; no job to complete holds |
| **Velocity checks** | Spam/fraud prevention | ❌ NOT STARTED | IP/email hashing ready; no check logic |
| **Status pipeline** | pending → signed_up → ordered → completed | ❌ NOT STARTED | Schema designed; no state machine |

### Admin UI (React)

| Page | Status | Details |
|---|---|---|
| **Campaigns** | ✅ FUNCTIONAL | Lite: three built-in campaign cards (purchase/signup/review), editable in local state only — no save yet. Pro: full table (grouped by trigger, priority, scheduling, conditions) + points summary card. Both read from cosmetic dummy data (22 campaigns for UI-check; `CampaignRestController::dummy_fixture()`—to be deleted when visual iteration stops). Real create/update/delete wired but no SPA form yet. Pro conditions preview endpoint working |
| **Customers** | ✅ FUNCTIONAL | Server-paginated list (search/filter/sort/export/row-select) against real `GET /customers`; bulk "Adjust points" and "Export" wired to real endpoints (`POST /customers/{id}/adjust`, client-side CSV); background page/sort/filter refetch dims the table to 60% opacity while placeholderData keeps rows on screen (no toolbar spinner); Tier column and filter are cosmetic sample data over `balance_range` (not real tier feature); Pro adds saved-segment management (`POST/DELETE /customers/segments` — save/apply/remove filter sets); filter suggestions for no-match cases; no per-customer detail view yet |
| **Dashboard** | ✅ SHIPPED | Real `GET /dashboard` (ledger/customers/WC aggregates: 5 stat tiles with deltas, issued-vs-redeemed chart 7/30/90, top earners, recent activity, three notice detectors with per-user dismissal, anomaly panel); Pro adds `GET /dashboard/pro` (campaign breakdown lines, top campaigns) — referrals tile and tier distribution are client dummies until those tables exist. Dev-only `?scenario=` fixtures reproduce every wireframe artboard (WP_DEBUG + Vite dev server) |
| **Rewards** | ⚠️ PLACEHOLDER | Stub page; no catalog UI |
| **Referral** | ⚠️ PLACEHOLDER | Stub page; referral program not built |
| **VIP Tiers** | ⚠️ PLACEHOLDER | Stub page; no tier management UI |
| **Settings** | ⚠️ PLACEHOLDER | Three stub tabs (general, emails, advanced); REST endpoint and `SettingsHelper` defaults exist (`general.enabled`, point labels) but each page renders a placeholder |
| **Capability gating** | ✅ FUNCTIONAL | Nav and routes filtered by `window.yayLoyaltyAdmin.capabilities`; Customers' "Adjust points"/"Export" bulk actions gated to `manage_points`/`view` via `<Can>`; backbone 403s surface as one toast |

---

## Known Gaps

Ordered by how much of the product each blocks, not by an assigned priority — priorities are a product decision that the code cannot make.

| Gap | What exists | What does not |
|---|---|---|
| **No customer detail view** | `useCustomerQuery`, `useAdjustPointsMutation`, `useBulkAdjustPointsMutation`, `useCustomerLedgerQuery` hooks all exist and call working REST endpoints (`GET /customers/{id}`, `GET /customers/{id}/ledger`, `POST /customers/{id}/adjust`) | Customers page is a paginated list view only — no per-customer detail drawer/modal to see one member's full history. "Adjust points"/"Export" are wired at the bulk-action level (`CustomersBulkActionBar`), not per-row. |
| **No order listener** | `PointsEngine` computes points for `purchase`/`signup`/`review`; `LedgerService::adjust()` writes (manual only); 46 engine unit tests | Nothing listens to WooCommerce events, calls the engine and records the result (F01-4). `LedgerService` has no `earn()` entry point yet |
| **No campaign editor** | REST create/update/delete; read-only SPA list | No SPA form to create or edit a campaign |
| **No redemption** | `LedgerType::REDEEM` and refund types; `lifetime_redeemed` maintained | No rewards table, no checkout integration, no REDEEM writer |
| **No referral program** | `customers.referral_code` column; `referral` trigger recognised | No code generation, attribution, hold, or payout; `referrals` / `referral_visits` tables not created |
| **No VIP tiers** | `customers.tier_id`, `tier_evaluated_at`, `tier_progress_value` columns; Admin Customers page shows cosmetic Tier column + filter (sample data only) | No `tiers` / `tier_history` tables, no evaluation, no downgrade job; sample Tier UI is a stand-in over `balance_range` for testing purposes |
| **Expiry not enforced** | `expires_at`, `reminder_sent_at` columns | No scheduled job expires points or sends reminders |
| **`pending_points` never written** | Column exists, inserted as 0 | No order lifecycle handling to move points from pending to awarded |
| **No event outbox** | — | `events` table not created; no webhook delivery |
| **`yayloyalty_table_schemas` seam unproven** | Filter wired in `Installer`, documented as a Pro seam | No Pro table exists yet, so the strip-a-Pro-table path has never run |
| **`referral`, `social`, `renewal` triggers** | Recognised by `all_known()` for downgrade safety | Offered by no build; nothing can fire them |
| **Placeholder pages** | Routes and nav for Rewards, Referral, VIP Tiers, Settings | Each renders `PagePlaceholder` |
| **No permission matrix** (Pro) | Seven caps on roles; `Capabilities::payload()` | No Settings → Permissions UI or `POST /settings/permissions`; roles are edited with a role plugin for now |
| **No `uninstall.php`** | `Capabilities::uninstall()` | Nothing calls it; caps and options persist after delete |
| **Licence routes share the menu cap** | AdminShell applies `get_capability()` (`view`) to menu, licence REST and `LicenseHandler` | A viewer can reach licence endpoints; needs a second adapter method in the shared shell |

---

## Backbone TODOs

Two backbones landed 2026-09-11: capabilities (`Access\Capabilities`) and the points engine (`Engine\Points`). Both are complete for their tickets; these are the follow-ups each one deliberately left to a later ticket, so nobody re-derives them.

### Points engine (F01-3 done) → what calls it

| # | TODO | Where | Ticket |
|---|---|---|---|
| 1 | Order lifecycle listener: award status → `ContextFactory::from_order()` → `PointsEngine::evaluate()` per trigger (`purchase`, then `first_order`…) → write the ledger. `actor_id` null. | new `Engine\OrderListener` (or similar) | F01-4 |
| 2 | `LedgerService::earn( user_id, points, source, reference_type, reference_id, campaign_id )` — only `adjust()` exists. Idempotent on `uniq_ledger_event`. | `Services/LedgerService.php` | F01-4 |
| 3 | `points_earned_today( user_id )` ledger read to feed `Context::points_earned_today` for `daily_cap`. | `Repositories/LedgerRepository.php` | F01-4 |
| 4 | Refunds / clawbacks / cancellations (`refund_earn`, `refund_redeem`). | listener | F01-4 |
| 5 | Review `one_per_product` and review `daily_cap` — need ledger reads, so they are the caller's, not `ReviewHandler`'s. Caller also sets `extra['verified_purchase']`. | review listener | F01-5 |
| 6 | Pro trigger handlers (`birthday`, `first_order`, `order_count`, `spend_milestone`) registered on `yayloyalty_register_trigger_handlers`. | `Pro/` | Pro |
| 7 | YayCurrency: return the order's rate on `yayloyalty_context_base_rate`. | integration | later |
| 8 | Admin "why did this order earn?" preview route (`view`) may return the full `Result`; storefront earn preview (Store API, no cap) must return `get_points()` only. | REST / Store API | F09, F12, F13, F15 |
| 9 | Guest scoring: `Campaign::conditions_match()` receives user id `0`; Pro's evaluator should treat 0 as "no customer" for customer-bound conditions. | `Pro/Services/ConditionsEvaluator.php` | Pro |
| 10 | PHPStan level 6 is not in the repo; add it and run on `includes/Engine/Points` first. | tooling | — |
| 11 | wp-phpunit harness for `CampaignRepository` (cache + liveness against real rows) and `ContextFactory` (real `WC_Order`). Verified by WP-CLI script today. | `tests/integration/` | — |

### Reward engine (scaffold, 2026-09-18) → what implements it

| # | TODO | Where | Ticket |
|---|---|---|---|
| 1 | `RewardEngine` gate 1 — liveness. A reward not live at the context's time denies `REASON_NOT_LIVE`. | `Engine\Rewards\RewardEngine::evaluate()` | — |
| 2 | `RewardEngine` gate 2 — balance vs cost. A balance below the cost denies `REASON_INSUFFICIENT_BALANCE`. Cost is `reward.cost_points` for fixed types, `context.points_requested` for `flexible_discount`. | `Engine\Rewards\RewardEngine::evaluate()` | — |
| 3 | `RewardEngine` gate 3 — conditions filter. A false from `yayloyalty_reward_conditions_match` (default `true`, args reward and context) denies `REASON_CONDITIONS_NOT_MET`. | `Engine\Rewards\RewardEngine::evaluate()` | — |
| 4 | `FlexibleDiscountHandler::quote()` — validate config: `min_points`, `increment`, `max_points_per_order`, `max_percent_of_subtotal` (each a `denied()` with its reason), then value via `Utils\PointsMath::points_to_currency()`. | `Engine\Rewards\Handlers\FlexibleDiscountHandler` | — |
| 5 | Pro handlers registered via `yayloyalty_register_reward_handlers` — one `RewardHandler` per type: `fixed_coupon`, `percent_coupon`, `free_shipping`, `free_product`. | `includes/Pro/` | — |
| 6 | `ContextFactory` — build `Context` from the cart (or none, for a catalog claim); balance from `CustomerRepository`. The one class allowed to touch WooCommerce. | `Engine\Rewards\` | — |
| 7 | Write path: `redemptions` table, `RedemptionRepository`, `LedgerService::redeem()`. | separate plan | — |
| 8 | Checkout hook caller — apply reward, write ledger on valid result. Store API caller. | listener | — |

### Capabilities (backbone done) → what is still open

| # | TODO | Where | Ticket |
|---|---|---|---|
| 1 | `uninstall.php` with a delete-data branch calling `Capabilities::uninstall()`. Method exists, nothing calls it. | plugin root | — |
| 2 | Permissions matrix UI + `POST /settings/permissions` with the five guards (renders only for `manage_options`; administrator row locked; roles from `wp_roles()`; caps-version migration; caps survive deactivation). | Settings → Advanced, `SettingsRestController` | F20 (Pro) |
| 3 | Settings field for `advanced.adjustment_ceiling` (enforced today, no UI). | Settings → Advanced | F20 (Pro) |
| 4 | Rewards REST routes take `Capabilities::MANAGE_REWARDS`; the nav/route already do. | `RewardsRestController` | rewards |
| 5 | `GET /settings` must omit licence key / secrets unless `manage_settings` — nothing secret is in the payload yet. | `SettingsRestController::get_settings()` | when a secret lands |
| 6 | AdminShell applies one adapter capability (`view`) to the menu, licence REST and `LicenseHandler`; a viewer can reach licence endpoints. Needs a second adapter method in the shared shell. | `yaycommerce/admin-shell` | shell |
| 7 | Route guard as CI: walk `yayloyalty/v1`, fail on a missing or `__return_true` `permission_callback`. Needs the wp-phpunit harness above. | `tests/integration/` | — |

### Dev-database drift (both)

`dbDelta` adds columns and indexes; it never renames a column or drops an index. Dev sites that predate the `campaigns.trigger` → `trigger_type` rename or the ledger key rename carry stale schema silently. `Installer::run_migrations()` is the seam for a first real migration (`ALTER TABLE … CHANGE`, `DROP INDEX reference`, `DROP INDEX user_created`); until then, reset the plugin tables on an old dev site.

---

## Database Schema Completeness

| Table | Phase | Implemented | Reason |
|---|---|---|---|
| `campaigns` | 1.0 | ✅ YES | Core earning rules; must be first |
| `customers` | 1.0 | ✅ YES | Caching layer; required for O(1) storefront reads |
| `ledger` | 1.0 | ✅ YES | Source of truth; foundation for all analytics |
| `rewards` | 1.0 | ❌ NO | Redemption not yet built; can add later without migration |
| `redemptions` | 1.0 | ❌ NO | Fact table; no code depends on it; safe to defer |
| `referrals` | 1.1 | ❌ NO | Separate program; can launch independently |
| `referral_visits` | 1.1 | ❌ NO | Click tracking; referrals prerequisite |
| `tiers` | 1.2 | ❌ NO | VIP program; can activate post-launch |
| `tier_history` | 1.2 | ❌ NO | Audit table; tiers prerequisite |
| `lots` | 1.0 Pro | ❌ NO | Advanced expiry; Pro-only optional feature |
| `lot_allocations` | 1.0 Pro | ❌ NO | Lot-based tracking; Pro feature |
| `events` | 2.0 | ❌ NO | Webhook outbox; 2.0 release |

**Migration strategy:** Schema installed on-demand. `Database/Installer::maybe_upgrade()` checks `yayloyalty_db_version` option; any phase table is created when that phase feature is activated (or at install time if included in the current version).

---

## Version Roadmap

| Version | Focus | Expected Tables | Earned Triggers |
|---|---|---|---|
| **1.0** | Core earning + campaigns | customers, ledger, campaigns | purchase, signup, review |
| **1.0 Pro** | Conditions, advanced triggers | + Pro-specific tables (if any) | + birthday, first_order, order_count, spend_milestone |
| **1.1** | Referral program | + referrals, referral_visits | + referral |
| **1.2** | VIP tiers | + tiers, tier_history | (no new triggers; tier basis for existing ones) |
| **2.0** | Webhooks & events | + events | (event outbox; no new triggers) |

---

## Implementation Notes

**Why tables defer:** YayLoyalty uses lazy table creation. `dbDelta` runs on `plugins_loaded` priority 20; the Installer queries `yayloyalty_table_schemas` filter. Pro adds its schemas via filter; Lite returns only core tables. A future phase (1.1, 1.2) activates its feature → schema gets registered → tables created at next upgrade check.

**Why triggers are safe to recognize early:** `CampaignTrigger::all()` is filtered (fireable); `all_known()` is not (recognized). A merchant can save a `birthday` campaign in Pro, downgrade to Lite, and upgrade back — the config persists untouched because Lite's `conditions_match()` fails open and `Campaign::validate_config()` preserves unknown trigger config wholesale.

**Why the order listener is the blocker:** `PointsEngine` applies campaign config to an order and answers with points, campaign and ledger reference — but nothing calls it. F01-4 wires: award status → `ContextFactory::from_order()` → `evaluate()` per trigger → `LedgerService::earn()` (to be added). See `includes/Engine/Points/README.md` for both call sites.

---

## Related Documentation

- **`data-model.md`** — Full schema specification (12 tables, relationships, invariants)
- **`code-standards.md`** — Implementation rules for new features
- **`codebase-summary.md`** — Current code structure and entry points
