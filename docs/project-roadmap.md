# YayLoyalty — Project Roadmap

Implementation status of features defined in `data-model.md`. All dates, timelines, and priority levels are derived from code and schema only. Gaps are the known limitations, not a prioritized backlog.

---

## Implementation Status by Phase

Data model v0.3 specifies a roadmap across phases 1.0, 1.1, 1.2, and 2.0. Current build is **1.0.0** with Pro (Pro/Lite split); only core tables exist.

### Phase 1.0 — Core (Current Release)

| Table                 | Spec                         | Status                   | Notes                                                                                                              |
| --------------------- | ---------------------------- | ------------------------ | ------------------------------------------------------------------------------------------------------------------ |
| **`campaigns`**       | Core earning rules           | ✅ **SHIPPED**           | Full CRUD; trigger filtering (Lite/Pro); Pro conditions evaluator; import/export ready                             |
| **`customers`**       | Member cache & state         | ✅ **SHIPPED**           | Lazy-initialized; aggregates; expiry basis; tier/referral columns exist but nothing writes them yet                                            |
| **`ledger`**          | Balance journal, append-only | ✅ **SHIPPED**           | Immutable; idempotent on unique key; SOLE writes via LedgerService                                                 |
| **`rewards`**         | Ways to spend points         | ✅ **SHIPPED**           | Schema, model, repository, enums; Lite seeds flexible_discount; Pro adds coupon types; no checkout integration yet |
| **`redemptions`**     | Reward claim records         | ❌ **NOT STARTED**       | Fact table; references rewards & ledger; multipart refund tracking                                                 |
| **`referrals`**       | Referral relationships       | ❌ **NOT STARTED**       | Advocate → friend tracking; link attribution; status pipeline                                                      |
| **`referral_visits`** | Link click events            | ❌ **NOT STARTED**       | Ephemeral; pruned daily; funnel analytics                                                                          |
| **`tiers`**           | VIP levels                   | ❌ **NOT STARTED**       | Thresholds, perks, ranks; no tier change logic                                                                     |
| **`tier_history`**    | Tier movement audit          | ❌ **NOT STARTED**       | Evaluation window, basis capture, manual promotion                                                                 |
| **`lots`**            | Per-earning expiry           | ❌ **NOT STARTED** (Pro) | FIFO consumption; per-lot expiry mutable counter                                                                   |
| **`lot_allocations`** | Lot consumption              | ❌ **NOT STARTED** (Pro) | Tracks how redemptions/expiry consumed lots                                                                        |
| **`events`**          | Event outbox                 | ❌ **NOT STARTED** (2.0) | Webhook/webhook-safe persistence; delivery tracking                                                                |

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
| **Lite triggers** | `purchase`, `signup`, `review` | ✅ **SHIPPED** | `PointsEngine` + handlers score all three; listeners fire and award via `EarningService::award()`; purchase stages at pending/on-hold and awards at completed |
| **Pro triggers** | `birthday`, `first_order`, `order_count`, `spend_milestone` | ⚠️ **OFFERABLE IN PRO, NOT FIREABLE** | Added to `CampaignTrigger::all()` by `YayLoyaltyPro`; Lite recognises them (`all_known()`) so a downgrade keeps the rows |
| **Referral trigger** | `referral` | ⚠️ **RECOGNIZED, NOT FIREABLE** | Enum only; referral engine not built |
| **Social trigger** | `social` | ⚠️ **RECOGNIZED, NOT FIREABLE** | Share-for-points not implemented |
| **Renewal trigger** | `renewal` | ⚠️ **RECOGNIZED, NOT FIREABLE** | Subscription renewal trigger not implemented |
| **Conditions (Pro)** | Role, product, category, lifetime spend, order count | ✅ SHIPPED (Pro) | ConditionsEvaluator; ANDed logic; preview endpoint |
| **Points allocation** | `purchase`: points_per_unit, base, rounding, min_subtotal, mode/multiplier | ✅ **SHIPPED (engine)** | `BaseCalculator` + `PurchaseHandler`; 46 engine unit tests, rounding via `Utils\PointsMath`; multiplier campaigns need an active default |
| **Max points cap** | `max_points_per_order`, `daily_cap` | ✅ **SHIPPED (engine)** | `CapPolicy`; `points_earned_today` is the caller's to supply from the ledger |
| **Campaign editor** | Create, read, update, delete | ✅ **SHIPPED** | `/campaigns/new` template chooser → create form (Pro), `POST /campaigns` persists. `/campaigns/:id/edit` six-section form + summary rail (react-hook-form + zod), `PUT /campaigns/{id}` persists for real. Archive/pause dialogs, bulk actions. All four REST verbs read/write the repository; `points_issued`/`is_deletable` are ledger-derived. Lite has read-only built-in cards with On/Off + Save (status/config only); no editor route (404) |
| **Scheduling** | `starts_at`, `ends_at` liveness eval | ✅ SHIPPED | Liveness computed at read; no cron flips status |

### Redemption (Rewards)

| Feature                        | Spec                               | Status              | Details                                                                                                             |
| ------------------------------ | ---------------------------------- | ------------------- | ------------------------------------------------------------------------------------------------------------------- |
| **Flexible discount**          | Points → cart discount, slider     | ✅ SHIPPED (schema) | Seeded in Lite; engine scaffolded (`Engine/Rewards/`, always denies `not_implemented`); no checkout integration yet |
| **Fixed/percent coupon** (Pro) | Issue WC coupon                    | ✅ SHIPPED (schema) | Seeded in Pro; no coupon generation yet                                                                             |
| **Free product** (Pro)         | Add to cart                        | ✅ SHIPPED (schema) | Seeded in Pro; no logic yet                                                                                         |
| **Free shipping** (Pro)        | Shipping discount                  | ✅ SHIPPED (schema) | Seeded in Pro; no logic yet                                                                                         |
| **Refund handling**            | Proportional/full/none policies    | ✅ **SHIPPED** (earn side) | Proportional cumulative clawback per refund, full and final on cancel/trash, deleted refund restored; policy in `data-model/points-lifecycle.md` §06–§08. Spend side (`refund_redeem`) is F08 |
| **Multi-currency**             | Value + base_value + rate snapshot | ❌ NOT STARTED      | Schema exists; no redemption flow                                                                                   |

### Points Management

| Feature | Spec | Status | Details |
|---|---|---|---|
| **Balance display** | Current spendable points | ✅ SHIPPED | REST `GET /customers/{id}`; SPA Customers page; TanStack Query |
| **Customers list** | Admin view of all members | ✅ SHIPPED | Real `GET /customers` — search/filter/sort/paginate in SQL, not demo data; see Admin UI → Customers below |
| **Pending points** | Awaiting order award | ✅ **SHIPPED** | `customers.pending_points` tracked; `Engine\Earning\PendingPoints` stages/releases/marks_awarded; order meta holds the cached figure |
| **Lifetime earned** | Net earn total (unaffected by redeem/expire) | ✅ SHIPPED | Updated on earn/adjust; basis for tier (points mode) |
| **Lifetime redeemed** | Total points spent (excluding refunds) | ✅ SHIPPED | Maintained by `LedgerService` from ledger type; no redemption flow writes REDEEM rows yet |
| **Expiry** | Inactivity basis | ⚠️ **TRACKED, NOT ENFORCED** | Fields exist (`expires_at`, `reminder_sent_at`); no job runs |
| **Expiry per-lot** (Pro) | Lot-based expiry | ❌ NOT STARTED | Lots table unimplemented; no lot tracking |
| **Email triggers** | Send transactional emails on events | ❌ NOT STARTED | Email classes registered (3 Lite / 12 Pro); no trigger() hooked, no cron or Action Scheduler job wired. Admin Emails settings page controls on/off toggles (WooCommerce integration) and weekly throttle cap (stored but not enforced). |
| **Email throttle** | Per-customer max sends per week | ⚠️ **STORED, NOT ENFORCED** | `yayloyalty_settings.emails.throttle_per_week` (default 3, non-negative int); no job enforces the limit yet. |
| **Adjustment ceiling** | Per-role cap on one manual adjustment | ⚠️ **ENFORCED, NO UI** | `settings.advanced.adjustment_ceiling` (0 = unlimited) checked by `assert_ceiling()`; admins bypass; no settings field to edit it yet |
| **Adjustment email** | Tell the customer about a manual adjustment | ❌ NOT STARTED | The adjust dialog's "Notify the customer by email" checkbox (single and bulk) is UI-only: `notifyByEmail` is not in the `POST /customers/{id}/adjust` body, and no email is sent. Will be a `LoyaltyEmail` subclass (part of `Engine/Emails` or `Pro/Emails` when built). Needs the body arg, trigger wiring, and a bulk-safe send (one email per customer, not per request retry) |
| **Customer-facing adjustment description** | Text the customer sees for a manual adjustment | ❌ NOT STARTED | The adjust dialog's "Also show a description to the customer" toggle and its text field are UI-only: `customerNote` is not in the `POST /customers/{id}/adjust` body and nothing stores it. The ledger's `note` is staff-only, so this needs its own home (a ledger column, or `customer_meta`), the body arg, and a customer-side points history to show it in — none exists yet. Until then the preview line's "Adjustment" fallback is what a customer would see |
| **Rebuild balances** | Replay ledger → recalculate customer row | ❌ NOT STARTED | `balance = SUM(ledger.points)` is the invariant; no rebuild routine or UI exists |
| **Account-deletion capture** | Snapshot identity + facts when a member's WP user is deleted | ✅ SHIPPED | `Engine\CustomerActivityListener` on `delete_user`/`wpmu_delete_user` → `Services\CustomerActivityService::record_account_deleted()`: one `account_deleted` row in `yayloyalty_customer_meta` (email/display_name snapshot, real balance/kept-entries), `yayloyalty_customers` row and ledger kept forever, byte-identical — see `data-model/customers-and-ledger.md` |
| **GDPR erasure / anonymisation** | Anonymize ledger, erase customer_meta snapshot | ❌ NOT STARTED | Neither `Engine\UserLifecycle` nor `Services\CustomerErasureService` exists. Must be re-specified against the locked "row kept forever" policy above — deleting the customers row, as an earlier draft of this line claimed, would destroy the frozen balance the deletion-capture feature exists to preserve |

### VIP Tiers

| Feature              | Spec                                            | Status         | Details                                        |
| -------------------- | ----------------------------------------------- | -------------- | ---------------------------------------------- |
| **Tier definitions** | Thresholds, perks, badges                       | ❌ NOT STARTED | Schema designed; no creation UI                |
| **Tier evaluation**  | Crossing threshold → promotion/demotion         | ❌ NOT STARTED | Logic unbuilt; evaluation window unimplemented |
| **Tier history**     | Audit trail of promotions                       | ❌ NOT STARTED | Table spec'd; no job to generate rows          |
| **Downgrade policy** | Never / window-end                              | ❌ NOT STARTED | Policy setting exists; no logic                |
| **Basis options**    | Lifetime points / rolling 12mo / lifetime spend | ❌ NOT STARTED | Settings designed; no evaluation logic         |

**Note:** Admin Customers page displays a cosmetic Tier column and working-but-substituted Tier filter (`customers-page.tier-sample-data.ts`) for UI-check purposes — these derive fake tier labels from customer balance and reuse the existing `balance_range` filter under the hood. This is not real VIP Tier progress; the filter and column will be deleted once the actual tier system ships.

### Referral Program

| Feature              | Spec                                      | Status         | Details                                                 |
| -------------------- | ----------------------------------------- | -------------- | ------------------------------------------------------- |
| **Referral codes**   | Generate per user                         | ❌ NOT STARTED | Field exists (`customers.referral_code`); no generation |
| **Link attribution** | Cookie-based or code                      | ❌ NOT STARTED | Click tracking, email code; no implementation           |
| **Friend reward**    | Points or coupon                          | ❌ NOT STARTED | Reward type spec'd; no claim logic                      |
| **Hold period**      | Before payout                             | ❌ NOT STARTED | Hold field exists; no job to complete holds             |
| **Velocity checks**  | Spam/fraud prevention                     | ❌ NOT STARTED | IP/email hashing ready; no check logic                  |
| **Status pipeline**  | pending → signed_up → ordered → completed | ❌ NOT STARTED | Schema designed; no state machine                       |

### Admin UI (React)

| Page | Status | Details |
|---|---|---|
| **Campaigns** | ✅ FUNCTIONAL | Real CRUD end to end: create (`/campaigns/new` template chooser → create form, Pro only), update (`/campaigns/:id/edit` six-section form + summary rail, Pro only), archive/pause/delete (dialogs, bulk actions, Pro only), list (real rows, ledger-derived `points_issued`/`is_deletable`). Lite: three built-in campaign cards (purchase/signup/review) with On/Off toggle + Save changes (status/config only). Performance page (`/campaigns/:id/performance`, purchase/signup/review only) is a **real `ledger` aggregate** (no fixtures) from `CampaignPerformanceRepository`; `skipped`/`estimate`/`avg_order_subtotal` still `null` (need earning engine F01-4). Lookup route for condition values by name. Pro conditions preview endpoint working |
| **Customers** | ✅ FUNCTIONAL | Server-paginated list (search/filter/sort/export/row-select) against real `GET /customers`; bulk "Adjust points" and "Export" wired to real endpoints (`POST /customers/{id}/adjust`, client-side CSV); background page/sort/filter refetch dims the table to 60% opacity while placeholderData keeps rows on screen (no toolbar spinner); Tier column and filter are cosmetic sample data over `balance_range` (not real tier feature); Pro adds saved-segment management (`POST/DELETE /customers/segments` — save/apply/remove filter sets); filter suggestions for no-match cases; below `sm` the table becomes a card stack (`CustomersCardList`) with a sort dropdown. Per-customer drawer at `customers/:userId` (F09): header stats, expiry/negative-balance/deleted panels, four paged tabs — Points ledger (real), Activity log / Redemptions / Referrals (honest empty envelopes until their tables exist; the latter two Pro-gated). Adjust points is the drawer's one live action; Reset expiry / Export ledger are disabled (no endpoint) |
| **Dashboard** | ✅ SHIPPED | Real `GET /dashboard` (ledger/customers/WC aggregates: 5 stat tiles with deltas, issued-vs-redeemed chart 7/30/90, top earners, recent activity, three notice detectors with per-user dismissal, anomaly panel); Pro adds `GET /dashboard/pro` (campaign breakdown lines, top campaigns) — referrals tile and tier distribution are client dummies until those tables exist. Dev-only `?scenario=` fixtures reproduce every wireframe artboard (WP_DEBUG + Vite dev server) |
| **Rewards** | ⚠️ PLACEHOLDER | Stub page; no catalog UI |
| **Referral** | ⚠️ PLACEHOLDER | Stub page; referral program not built |
| **VIP Tiers** | ⚠️ PLACEHOLDER | Stub page; no tier management UI |
| **Settings** | ⚠️ PARTIAL | Design tab shipped (admin-side only; nothing renders on the storefront yet). Emails tab shipped (12 transactional email toggles, weekly throttle cap, read-only WC sending config). General and Advanced are stubs; REST endpoint and `SettingsHelper` defaults exist (`general.enabled`, point labels) but each renders an inline "not implemented yet" Card (Rewards/Referral/VIP render `PagePlaceholder`) |
| **Capability gating** | ✅ FUNCTIONAL | Nav and routes filtered by `window.yayLoyaltyAdmin.capabilities`; Customers' "Adjust points"/"Export" bulk actions gated to `manage_points`/`view` via `<Can>`; backbone 403s surface as one toast |

---

## Known Gaps

Ordered by how much of the product each blocks, not by an assigned priority — priorities are a product decision that the code cannot make.

| Gap | What exists | What does not |
|---|---|---|
| **Drawer tabs without tables** | Customer drawer (`customers/:userId`) with `GET /customers/{id}/activity`, `/redemptions`, `/referrals` routes and Zod contracts | No `activity`, `redemptions` or `referrals` table — the real path always answers an empty envelope and `counts` stay `0`; `type_counts` on the ledger tab is `null` on the real path. Reset expiry / Export ledger have no endpoint (buttons disabled) |
| **`uniq_idempotency_key` reaches a site only through dbDelta** | Points award and come back out (`Engine/Earning/`, integration-tested against real orders) | The index only reaches a site through `dbDelta`, which `Installer::maybe_upgrade()` skips while the db version equals the plugin version; the drawer's `negative_balance` wire field stays `null` although reversals can now drive a balance below zero |
| **Redemption is a scaffold** | `rewards` table; `Engine/Rewards/` quote with a real, tested `Clamp`; `Engine/Redemption/` fixes the three-zone shape — service, quoter (real), session intent, virtual coupon provider, commit writer, two adapters — with incomplete tests as the spec; `LedgerService::redeem()` and `Earning\IdempotencyKey` widened for redeem rows | Every other body is a `TODO(F08)` stub, nothing is registered at boot, `RedemptionsTable` is not in `Installer::schemas()`; no block UI; Zone 3 (restore redeemed points on cancel/refund) undesigned |
| **No referral program** | `customers.referral_code` column; `referral` trigger recognised | No code generation, attribution, hold, or payout; `referrals` / `referral_visits` tables not created |
| **No VIP tiers** | `customers.tier_id`, `tier_evaluated_at`, `tier_progress_value` columns; Admin Customers page shows cosmetic Tier column + filter (sample data only) | No `tiers` / `tier_history` tables, no evaluation, no downgrade job; sample Tier UI is a stand-in over `balance_range` for testing purposes |
| **Drawer wire fields always null on real path** | Customer drawer carries `deleted_at`, `expiry.batches`, `negative_balance`, `loose_ends` — parsed through same Zod schema fixture and real paths | Fields exist on the wire for UI review; real path always `null` because `deleted_at` column, `lots`/`lot_allocations`, and activity table do not exist yet |
| **Expiry not enforced** | `expires_at`, `reminder_sent_at` columns | No scheduled job expires points or sends reminders |
| **`pending_points` never written** | Column exists, inserted as 0; `PendingPoints::stage()`/`release()` stubs | No order lifecycle handling to move points from pending to awarded (F01-4) |
| **No event outbox** | — | `events` table not created; no webhook delivery |
| **`yayloyalty_table_schemas` seam unproven** | Filter wired in `Installer`, documented as a Pro seam | No Pro table exists yet, so the strip-a-Pro-table path has never run |
| **`referral`, `social`, `renewal` triggers** | Recognised by `all_known()` for downgrade safety | Offered by no build; nothing can fire them |
| **Placeholder pages** | Routes and nav for Rewards, Referral, VIP Tiers | Each renders `PagePlaceholder` |
| **Settings tabs incomplete** | Stub tabs (general, emails, advanced); REST endpoint and `SettingsHelper` defaults exist | Each settings tab renders an inline "not implemented yet" Card (unlike Rewards/Referral/VIP) |
| **Design rendered (storefront launcher v1)** | Launcher v1 reads `yayloyalty_design` (launcher, storefront_text, visibility scope/pages/hide_for_roles). Floating button + panel displayed per visibility rules; guest/signed-in panel content, recent activity lazy-loaded from `/me/activity`, ways to redeem info-only. Read-only (redeem button hidden until F08). | Typography and custom_css remain unrendered; Pro panel blocks (tier/referral) not rendered; remaining: redeem (F08), My Account Rewards tab (1d), Pro panel blocks, typography/custom_css, terms link |
| **No permission matrix** (Pro) | Seven caps on roles; `Capabilities::payload()` | No Settings → Permissions UI or `POST /settings/permissions`; roles are edited with a role plugin for now |
| **No `uninstall.php`** | `Capabilities::uninstall()` | Nothing calls it; caps and options persist after delete |
| **Licence routes share the menu cap** | AdminShell applies `get_capability()` (`view`) to menu, licence REST and `LicenseHandler` | A viewer can reach licence endpoints; needs a second adapter method in the shared shell |
| **Rules decided in two places** | § 16 owner table in `code-standards.md`; `/yay:code-corrector --all` | First full run (2026-09-24): 21 copies and 7 drifted mirrors. Highest risk: `settings.schema.ts` lacks `general.excluded_roles` and requires `adjustment_ceiling` ≥ 0 while PHP stores negatives; points rounding copied outside `PointsMath` (`Clamp::points_in()`, fixtures, `CustomerDrawerExpiryPanel`) |
| **No SPA tests for drawer/editor/performance** | Controllers fixture-tested; points engine (unit tests + shared TS vectors); dashboard computation; PointsMath sync | Customer drawer, campaign editor, campaign performance have no client-side test coverage |

---

## Backbone TODOs

Two backbones landed 2026-09-11: capabilities (`Access\Capabilities`) and the points engine (`Engine\Points`). Both are complete for their tickets; these are the follow-ups each one deliberately left to a later ticket, so nobody re-derives them.

### Points engine (F01-3 done) → what calls it

| # | TODO | Where | Ticket |
|---|---|---|---|
| 1 | Order lifecycle listener: award status → `ContextFactory::from_order()` → `EarningService::preview()`/`award()` per trigger (`purchase`, then `first_order`…) → `LedgerService::earn()`. `actor_id` null. Scaffolded 2026-09-22; registered at boot 2026-09-28 (`register()`/`route()` implemented); `on_stageable()`/`on_awardable()` implemented (call `preview_and_log()`, logging to debug); `on_cancelled()` and refund/approve bodies still stubs. | `Engine\Earning\Listeners\OrderListener` | F01-4 |
| 2 | `LedgerService::earn( LedgerEntry $draft ): Outcome` — stubbed; idempotent on the single `idempotency_key` column (`Engine\Earning\IdempotencyKey`). Scaffold and invariants: `includes/Engine/Earning/README.md`. | `Services/LedgerService.php` | F01-4 |
| 3 | `points_earned_today( user_id, at )` ledger read to feed `Context::points_earned_today` for `daily_cap` — stubbed. | `Repositories/LedgerRepository.php` | F01-4 |
| 4 | Refunds / clawbacks / cancellations (`refund_earn`). **Shipped 2026-09-30**: `Engine\Earning\Reversal` + `ClawbackMath`, `OrderReversalListener` (refund, refund deleted, trash), `ReviewListener::on_unapproved()`. `refund_redeem` stays with row 11. | listener | F01-4 |
| 5 | Review `one_per_product` and review `daily_cap` — need ledger reads, so they are the caller's, not `ReviewHandler`'s. Caller also sets `extra['verified_purchase']`. | review listener | F01-5 |
| 6 | Pro trigger handlers (`birthday`, `first_order`, `order_count`, `spend_milestone`) registered on `yayloyalty_register_trigger_handlers`. | `Pro/` | Pro |
| 7 | YayCurrency: return the order's rate on `yayloyalty_context_base_rate`. | integration | later |
| 8 | Admin "why did this order earn?" preview route (`view`) may return the full `Result`; storefront earn preview (Store API, no cap) must return `get_points()` only. | REST / Store API | F09, F12, F13, F15 |
| 9 | ~~Guest scoring: the evaluator receives a `Context` with `user_id` null and no `CustomerFacts`; customer-bound conditions must not match.~~ **Done (2026-09-28):** `ConditionsEvaluator::explain()` returns `no_customer` reason when a customer-scoped condition is evaluated with `user_id` null. | `Pro/Services/ConditionsEvaluator.php` | Pro |
| 10 | PHPStan level 6 is not in the repo; add it and run on `includes/Engine/Points` first. | tooling | — |
| 11 | wp-phpunit harness for `CampaignRepository` (cache + liveness against real rows) and `ContextFactory` (real `WC_Order`). Verified by WP-CLI script today. | `tests/integration/` | — |

### Redemption (scaffold, 2026-09-22) → what implements it

The shape is fixed (`includes/Engine/Redemption/README.md`); each row is one stub body, fillable without touching the others.

| # | TODO | Where | Ticket |
|---|---|---|---|
| 1 | `FlexibleDiscountHandler::quote()` — one line delegating to `Clamp::apply()`. | `Engine\Rewards\Handlers\` | F08 |
| 2 | `ContextFactory::from_cart()` / `from_order()` — balance from the customer row (locked balance passed in at commit), subtotal net of every coupon but ours, `CustomerFacts`. | `Engine\Rewards\ContextFactory` | F08 |
| 3 | `ProgramGate` (logged in, program on, `wc_coupons_enabled()`) and `RewardGate` (live, fixed cost vs balance, `yayloyalty_reward_conditions_match`). | `Engine\Redemption\` | F08 |
| 4 | `SessionIntent::get()/set()/clear()` over `WC()->session`, key `yayloyalty_redeem_points`. | `Engine\Redemption\SessionIntent` | F08 |
| 5 | `CouponProvider` — `woocommerce_get_shop_coupon_data` (fixed_cart, `individual_use` false), `woocommerce_coupon_sort` (weight PHP_INT_MAX, so last), `sync_cart` on `woocommerce_before_calculate_totals`. | `Engine\Redemption\CouponProvider` | F08 |
| 6 | `RedemptionsTable::definition()` + registration in `Installer::schemas()`; `RedemptionRepository::find_by_order()`, `count_for_customer()`. | `Database\Schema\`, `Repositories\` | F08 |
| 7 | `LedgerService::redeem()` — sibling of `earn()` over `record()`; refuses below zero under the lock. | `Services\LedgerService` | F08 |
| 8 | `CommitWriter::commit()` and its two draft builders + `freeze()`; `RedemptionService::quote()/checkout_error()/commit()/build()` (the `Quoter` is already real). | `Engine\Redemption\` | F08 |
| 9 | Adapters' hook bodies; register `CouponProvider` + both adapters from `YayLoyalty::__construct()` on storefront requests. | `Engine\Redemption\Adapters\`, `YayLoyalty` | F08 |
| 10 | Block UI: a storefront package reading `extensions.yayloyalty` and posting the `yayloyalty-redeem` update callback. | new package | F08+ |
| 11 | Zone 3: `Engine\Redemption\Reversal` from `OrderReversalListener::on_cancelled()` / `on_order_refunded()`, `LedgerService::refund_redeem()`, `Redemption::restored_points`. | undesigned | later |
| 12 | Pro catalog rewards: handlers for `fixed_coupon`, `percent_coupon`, `free_shipping`, `free_product` via `yayloyalty_register_reward_handlers`; real single-use coupons. | `includes/Pro/` | Pro |

### Capabilities (backbone done) → what is still open

| #   | TODO                                                                                                                                                                                                                 | Where                                         | Ticket              |
| --- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | --------------------------------------------- | ------------------- |
| 1   | `uninstall.php` with a delete-data branch calling `Capabilities::uninstall()`. Method exists, nothing calls it.                                                                                                      | plugin root                                   | —                   |
| 2   | Permissions matrix UI + `POST /settings/permissions` with the five guards (renders only for `manage_options`; administrator row locked; roles from `wp_roles()`; caps-version migration; caps survive deactivation). | Settings → Advanced, `SettingsRestController` | F20 (Pro)           |
| 3   | Settings field for `advanced.adjustment_ceiling` (enforced today, no UI).                                                                                                                                            | Settings → Advanced                           | F20 (Pro)           |
| 4   | Rewards REST routes take `Capabilities::MANAGE_REWARDS`; the nav/route already do.                                                                                                                                   | `RewardsRestController`                       | rewards             |
| 5   | `GET /settings` must omit licence key / secrets unless `manage_settings` — nothing secret is in the payload yet.                                                                                                     | `SettingsRestController::get_settings()`      | when a secret lands |
| 6   | AdminShell applies one adapter capability (`view`) to the menu, licence REST and `LicenseHandler`; a viewer can reach licence endpoints. Needs a second adapter method in the shared shell.                          | `yaycommerce/admin-shell`                     | shell               |
| 7   | Route guard as CI: walk `yayloyalty/v1`, fail on a missing or `__return_true` `permission_callback`. Needs the wp-phpunit harness above.                                                                             | `tests/integration/`                          | —                   |

### Dev-database drift (both)

`dbDelta` adds columns and indexes; it never renames a column or drops an index. Dev sites that predate the `campaigns.trigger` → `trigger_type` rename or the ledger key rename carry stale schema silently. `Installer::run_migrations()` is the seam for a first real migration (`ALTER TABLE … CHANGE`, `DROP INDEX reference`, `DROP INDEX user_created`); until then, reset the plugin tables on an old dev site.

---

## Database Schema Completeness

| Table             | Phase   | Implemented | Reason                                                        |
| ----------------- | ------- | ----------- | ------------------------------------------------------------- |
| `campaigns`       | 1.0     | ✅ YES      | Core earning rules; must be first                             |
| `customers`       | 1.0     | ✅ YES      | Caching layer; required for O(1) storefront reads             |
| `ledger`          | 1.0     | ✅ YES      | Source of truth; foundation for all analytics                 |
| `rewards`         | 1.0     | ✅ YES      | Schema, model, repository, seeding; redemption flow not built |
| `redemptions`     | 1.0     | ❌ NO       | Fact table; no code depends on it; safe to defer              |
| `referrals`       | 1.1     | ❌ NO       | Separate program; can launch independently                    |
| `referral_visits` | 1.1     | ❌ NO       | Click tracking; referrals prerequisite                        |
| `tiers`           | 1.2     | ❌ NO       | VIP program; can activate post-launch                         |
| `tier_history`    | 1.2     | ❌ NO       | Audit table; tiers prerequisite                               |
| `lots`            | 1.0 Pro | ❌ NO       | Advanced expiry; Pro-only optional feature                    |
| `lot_allocations` | 1.0 Pro | ❌ NO       | Lot-based tracking; Pro feature                               |
| `events`          | 2.0     | ❌ NO       | Webhook outbox; 2.0 release                                   |

**Migration strategy:** Schema installed on-demand. `Database/Installer::maybe_upgrade()` checks `yayloyalty_db_version` option; any phase table is created when that phase feature is activated (or at install time if included in the current version).

---

## Version Roadmap

| Version     | Focus                         | Expected Tables                | Earned Triggers                                       |
| ----------- | ----------------------------- | ------------------------------ | ----------------------------------------------------- |
| **1.0**     | Core earning + campaigns      | customers, ledger, campaigns   | purchase, signup, review                              |
| **1.0 Pro** | Conditions, advanced triggers | + Pro-specific tables (if any) | + birthday, first_order, order_count, spend_milestone |
| **1.1**     | Referral program              | + referrals, referral_visits   | + referral                                            |
| **1.2**     | VIP tiers                     | + tiers, tier_history          | (no new triggers; tier basis for existing ones)       |
| **2.0**     | Webhooks & events             | + events                       | (event outbox; no new triggers)                       |

---

## Implementation Notes

**Why tables defer:** YayLoyalty uses lazy table creation. `dbDelta` runs on `plugins_loaded` priority 20; the Installer queries `yayloyalty_table_schemas` filter. Pro adds its schemas via filter; Lite returns only core tables. A future phase (1.1, 1.2) activates its feature → schema gets registered → tables created at next upgrade check.

**Why triggers are safe to recognize early:** `CampaignTrigger::all()` is filtered (fireable); `all_known()` is not (recognized). A merchant can save a `birthday` campaign in Pro, downgrade to Lite, and upgrade back — the config persists untouched because Lite's `conditions_match()` fails open and `Campaign::validate_config()` preserves unknown trigger config wholesale.

**Why the order listener is the blocker:** `PointsEngine` applies campaign config to an order and answers with points, campaign and ledger reference — but nothing calls it in production yet. The listener is wired (2026-09-28: registered at boot, every order/review/signup event reaches its routing table) but the call path past routing is still scaffolded in `includes/Engine/Earning/` (`ContextFactory::from_order()` → `EarningService::preview()`/`award()` → `LedgerService::earn()`); F01-4 fills those remaining stub bodies. See `includes/Engine/Earning/README.md` for the invariants and `includes/Engine/Points/README.md` for both engine call sites.

---

## Related Documentation

- **`data-model.md`** — Full schema specification (13 tables, relationships, invariants)
- **`code-standards.md`** — Implementation rules for new features
- **`codebase-summary.md`** — Current code structure and entry points
