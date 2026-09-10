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
| **`rewards`** | Ways to spend points | ❌ **NOT STARTED** | Schema & API designed; no SPA UI; no cart/checkout integration |
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
| **Lite triggers** | `purchase`, `signup`, `review` | ⚠️ **OFFERABLE, NOT FIREABLE** | Campaigns can be created for them and config is whitelisted, but no earning engine fires any trigger yet |
| **Pro triggers** | `birthday`, `first_order`, `order_count`, `spend_milestone` | ⚠️ **OFFERABLE IN PRO, NOT FIREABLE** | Added to `CampaignTrigger::all()` by `YayLoyaltyPro`; Lite recognises them (`all_known()`) so a downgrade keeps the rows |
| **Referral trigger** | `referral` | ⚠️ **RECOGNIZED, NOT FIREABLE** | Enum only; referral engine not built |
| **Social trigger** | `social` | ⚠️ **RECOGNIZED, NOT FIREABLE** | Share-for-points not implemented |
| **Renewal trigger** | `renewal` | ⚠️ **RECOGNIZED, NOT FIREABLE** | Subscription renewal trigger not implemented |
| **Conditions (Pro)** | Role, product, category, lifetime spend, order count | ✅ SHIPPED (Pro) | ConditionsEvaluator; ANDed logic; preview endpoint |
| **Points allocation** | `purchase`: points_per_unit, base, rounding, min_subtotal | ⚠️ **CONFIG ONLY** | Keys whitelisted and stored; **earning engine not built** |
| **Max points cap** | `max_points_per_order`, `daily_cap` | ⚠️ **STORED, UNENFORCED** | Config keys exist; no logic applies caps |
| **Campaign editor** | Create, read, update, delete | ⚠️ **API COMPLETE, UI INCOMPLETE** | REST endpoints working; SPA list is read-only |
| **Scheduling** | `starts_at`, `ends_at` liveness eval | ✅ SHIPPED | Liveness computed at read; no cron flips status |

### Redemption (Rewards)

| Feature | Spec | Status | Details |
|---|---|---|---|
| **Flexible discount** | Points → cart discount, slider | ❌ NOT STARTED | Reward type designed; no checkout integration |
| **Fixed/percent coupon** (Pro) | Issue WC coupon | ❌ NOT STARTED | Designed; no coupon generation |
| **Free product** (Pro) | Add to cart | ❌ NOT STARTED | Designed; no logic |
| **Refund handling** | Proportional/full/none policies | ❌ NOT STARTED | Spec written; no implementation |
| **Multi-currency** | Value + base_value + rate snapshot | ❌ NOT STARTED | Schema exists; no redemption flow |

### Points Management

| Feature | Spec | Status | Details |
|---|---|---|---|
| **Balance display** | Current spendable points | ✅ SHIPPED | REST; SPA Points page; TanStack Query |
| **Pending points** | Awaiting order award | ❌ **COLUMN ONLY** | `customers.pending_points` exists and is inserted as 0; nothing writes it (no order handling yet) |
| **Lifetime earned** | Net earn total (unaffected by redeem/expire) | ✅ SHIPPED | Updated on earn/adjust; basis for tier (points mode) |
| **Lifetime redeemed** | Total points spent (excluding refunds) | ✅ SHIPPED | Maintained by `LedgerService` from ledger type; no redemption flow writes REDEEM rows yet |
| **Expiry** | Inactivity basis | ⚠️ **TRACKED, NOT ENFORCED** | Fields exist (`expires_at`, `reminder_sent_at`); no job runs |
| **Expiry per-lot** (Pro) | Lot-based expiry | ❌ NOT STARTED | Lots table unimplemented; no lot tracking |
| **Rebuild balances** | Replay ledger → recalculate customer row | ❌ NOT STARTED | `balance = SUM(ledger.points)` is the invariant; no rebuild routine or UI exists |

### VIP Tiers

| Feature | Spec | Status | Details |
|---|---|---|---|
| **Tier definitions** | Thresholds, perks, badges | ❌ NOT STARTED | Schema designed; no creation UI |
| **Tier evaluation** | Crossing threshold → promotion/demotion | ❌ NOT STARTED | Logic unbuilt; evaluation window unimplemented |
| **Tier history** | Audit trail of promotions | ❌ NOT STARTED | Table spec'd; no job to generate rows |
| **Downgrade policy** | Never / window-end | ❌ NOT STARTED | Policy setting exists; no logic |
| **Basis options** | Lifetime points / rolling 12mo / lifetime spend | ❌ NOT STARTED | Settings designed; no evaluation logic |

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
| **Campaigns** | ✅ FUNCTIONAL | List/view/edit-read-only; Pro conditions preview; no create/update UI |
| **Points** | ✅ FUNCTIONAL | Customer lookup, balance, ledger history, manual adjust |
| **Dashboard** | ⚠️ PLACEHOLDER | Stub page; no analytics yet |
| **Rewards** | ⚠️ PLACEHOLDER | Stub page; no catalog UI |
| **Referral** | ⚠️ PLACEHOLDER | Stub page; referral program not built |
| **VIP Tiers** | ⚠️ PLACEHOLDER | Stub page; no tier management UI |
| **Settings** | ⚠️ PLACEHOLDER | Tab shell only; REST endpoint and `SettingsHelper` defaults exist (`general.enabled`, point labels) but the page renders a placeholder |

---

## Known Gaps

Ordered by how much of the product each blocks, not by an assigned priority — priorities are a product decision that the code cannot make.

| Gap | What exists | What does not |
|---|---|---|
| **No earning engine** | Campaigns, triggers, config whitelist, `LedgerService::record()` | Nothing listens to WooCommerce events and awards points. No campaign of any trigger fires. |
| **Point caps unenforced** | `max_points_per_order`, `daily_cap` stored and described in the REST schema | Nothing reads them; there is no award path for them to cap |
| **No campaign editor** | REST create/update/delete; read-only SPA list | No SPA form to create or edit a campaign |
| **No redemption** | `LedgerType::REDEEM` and refund types; `lifetime_redeemed` maintained | No rewards table, no checkout integration, no REDEEM writer |
| **No referral program** | `customers.referral_code` column; `referral` trigger recognised | No code generation, attribution, hold, or payout; `referrals` / `referral_visits` tables not created |
| **No VIP tiers** | `customers.tier_id`, `tier_evaluated_at`, `tier_progress_value` columns | No `tiers` / `tier_history` tables, no evaluation, no downgrade job |
| **Expiry not enforced** | `expires_at`, `reminder_sent_at` columns | No scheduled job expires points or sends reminders |
| **`pending_points` never written** | Column exists, inserted as 0 | No order lifecycle handling to move points from pending to awarded |
| **No event outbox** | — | `events` table not created; no webhook delivery |
| **`yayloyalty_table_schemas` seam unproven** | Filter wired in `Installer`, documented as a Pro seam | No Pro table exists yet, so the strip-a-Pro-table path has never run |
| **`referral`, `social`, `renewal` triggers** | Recognised by `all_known()` for downgrade safety | Offered by no build; nothing can fire them |
| **Placeholder pages** | Routes and nav for Dashboard, Rewards, Referral, VIP Tiers, Settings | Each renders `PagePlaceholder` |

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

**Why the earning engine is the blocker:** Points are stored and aggregated, but no logic applies the campaign config to an order. Implementation: detect order award status → look up live campaigns for that order's trigger → call `calculate_points()` with purchase amounts, rules, and conditions → `LedgerService::record()`.

---

## Related Documentation

- **`data-model.md`** — Full schema specification (12 tables, relationships, invariants)
- **`code-standards.md`** — Implementation rules for new features
- **`codebase-summary.md`** — Current code structure and entry points
