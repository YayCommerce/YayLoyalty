---
area: decisions
updated: 2026-11-25
updated_ref: dd5c69a
covers: [includes/YayLoyalty.php, includes/Services/LedgerService.php, includes/Database/, includes/Engine/Redemption/, includes/Engine/Earning/, apps/admin/src/main.tsx, apps/admin/src/pro/index.ts, scripts/run.sh]
---

# Decisions

> Update when: a change makes or reverses a choice that is costly to undo and that the code can't justify by itself. Add a dated entry; don't rewrite old ones.

### 2026-11-05 Commit `vendor-prefixed/`; dev and release never rescope in place
**Why:** `dev` ran `yaycommerce-update` and `release` ran `yaycommerce-prerelease` in the plugin folder; both deleted `vendor-prefixed/` before rescoping, so the site fatalled meanwhile, and release left `vendor/` installed `--no-dev`.
**Instead:** `shell-update` swaps in a rebuilt copy; `release` rescopes into the staging copy only.
**Evidence:** `f197818e` (#174), `scripts/run.sh`.

### 2026-11-06 The Lite build never names Pro
**Why:** WordPress.org review of the Lite zip.
**How:** the release drops " Pro" from the plugin header and strips the admin-shell licensing; Pro adds its name through `yayloyalty_plugin_name`. `validate-build.php` allows Pro to be named only in the guarded seam in `includes/YayLoyalty.php`.
**Evidence:** `1c0b6c38` (#180), `40d6d9b1` (#160).

### Pro and Lite split at build time, not at runtime
**Why:** a runtime or localized flag would ship Pro code in the Lite zip. PHP: Lite is the tree without `includes/Pro/`, reached only through `YayLoyaltyPro::boot()`. SPA: `IS_PRO_BUILD` is a build constant and Rollup drops `src/pro/`.
**Rejected:** a localized Pro flag; `<ProGate>` alone (JSX evaluates children eagerly); a dynamic import of Pro (a side chunk escapes the validator's scan of the main bundle).
**Evidence:** `includes/YayLoyalty.php`, `apps/admin/src/main.tsx`, `apps/admin/src/pro/index.ts`, `scripts/validate-build.php`.

### A single writer for the ledger and balances
**Why:** balance, lifetime, `balance_after` and lots must move together under one customer row lock. `LedgerService` is the only writer and is constructed in five listed places; readers that must not write get `CustomerReader`.
**Evidence:** `includes/Services/LedgerService.php` docblock, `includes/Repositories/CustomerReader.php`.

### 2026-10-12 Campaign delete serialises with awards
**Why:** a delete checked for ledger rows, then deleted unlocked, so a racing award could name a campaign that no longer exists. The delete now locks the campaign row; `LedgerService` takes it shared before an earn row.
**Evidence:** `86239eae` (#121).

### One idempotency column for the ledger
**Why:** a composite unique index with nullable parts doesn't stop duplicates in MySQL. Every system row now has `idempotency_key`; the old indexes are dropped in `Installer::run_migrations()`.
**Evidence:** `includes/Database/Schema/LedgerTable.php`, `includes/Database/Installer.php`.

### Redemption is a virtual coupon re-derived on every totals run
**Why:** nothing to clean up if the cart changes or checkout fails; the amount always matches the current cart. Only the WC session intent persists before the order; the value is frozen at commit.
**Rejected:** fees and real `shop_coupon` posts.
**Evidence:** `includes/Engine/Redemption/CouponProvider.php`, `CommitWriter.php`.

### Free shipping and free product rewards ride on a 0 coupon
**Why:** the 0 coupon is the marker: the free cart line (`RewardCartLines`) and the zeroed rates (`RewardShipping`) exist only while it is applied, so commit, reversal, order screen and emails keep keying on the reward's code, and a downgrade or a dropped reward leaves nothing free behind. The value is what the order freed, read from the line and shipping item meta at commit. Modelled on YayPricing's free gift lines and shipping rule.
**Rejected:** WooCommerce's own free-shipping coupon flag (needs a merchant-configured method), a 100% product coupon on the shopper's own copy, and a separate non-coupon redemption path (BS-6).
**Evidence:** `includes/Engine/Redemption/RewardCartLines.php`, `RewardShipping.php`, `CommitWriter.php` (`freed_value()`).

### 2026-11-25 A refund gives back a free reward's points only for its gift
**Why:** a product decision (BL-30): refunding paid items must not return points for a gift the shopper keeps. A free product gives back its points for the refunded units of its free line, free shipping only once the order is fully refunded (the freed shipping is 0, so no refund can take it back alone). Coupon rewards keep their `refund_policy`.
**Evidence:** `includes/Engine/Redemption/Reversal.php` (`gift_target()`), `tests/php/integration/Engine/Redemption/FreeRewardRefundTest.php`.

### Only `completed` orders award points
**Why:** a product decision stated on the class; `pending` and `on-hold` only stage points.
**Evidence:** `includes/Engine/Earning/Listeners/OrderListener.php`, `includes/Enums/OrderStatus.php`.

### 2026-10-08 The admin shows points, not money
**Why:** the admin valued points with the flexible-discount reward's rate even when that reward was off, so its money figures could disagree with checkout.
**Evidence:** `e6760a70` (#90).

### UTC storage, upgrades on `plugins_loaded` under an option mutex
**Why:** a stored site-time value surfaces months later as an off-by-hours expiry. `admin_init` misses REST, cron and WP-CLI; transients aren't atomic under an object cache, `add_option` is.
**Evidence:** `includes/Utils/Dates.php`, `includes/Database/Installer.php`, `yayloyalty.php`.

### Lite fails open without a conditions evaluator
**Why:** failing closed would disable every conditional campaign or reward when a site downgrades from Pro.
**Evidence:** `includes/Engine/Points/PointsEngine.php`, `includes/Engine/Redemption/RewardGate.php`.

### 2026-11-17 Referral is Pro's first table of its own, with its own install stamp
**Why:** referral is a Pro-only feature (BS-3), so a Lite site must have no referrals table, and Pro data in a Lite table would ship Lite a schema it never uses. Lite's install runs on activation before Pro boots and skips while its stamp is current, so Pro keeps `yayloyalty_pro_db_version` and installs under the same mutex. Referral settings are a Pro option for the same reason.
**Rejected:** a Lite `referrals` table; storing the settings in a Referral campaign (UX-2's first draft).
**Evidence:** `includes/Pro/Database/ProInstaller.php`, `includes/Database/Installer.php` `install_locked()`, P-3.

### 2026-11-18 A referral payout carries the friend's order, but is never shown as that order's points
The advocate's referral row keeps the friend's `order_id` so `Reversal` claws it back on a refund, prorated like any campaign-less row. Every reader that means "this customer's points for this order" (the orders list column, Missing points, the grant check, the advocate's history and earned email, the daily earning total) leaves referral rows out, through `LedgerEntry::get_own_order_id()` or `source <> 'referral'`. Alternative rejected: no order id and a Pro-side clawback, which needed a new ledger seam and lost proration (P-4).

### 2026-11-13 Conditions are one list matched by all or any, flat rows untouched
**Why:** Merchants asked for the YayPricing model: one list of conditions and one Any/All switch, no groups. An `all` list keeps the 1.0.0 list envelope, so flat rows need no migration; an `any` list adds `"match":"any"` at `schema_version` 2 to the same envelope, so a 1.0.0 reader still sees its items and reads them as `all`, which fails closed. At most one entry per type under either match. New conditions start as `all`.
**Rejected:** two levels of AND/OR groups (built first in P-1, then removed at review); a nested tree (BS-1 option B); a data migration.
**Evidence:** `includes/Utils/ConditionMatch.php`, P-1.

### 2026-11-23 Tier points are their own total; a refund drops a tier only when it is the cause
**Why:** staff must be able to correct a balance without touching a tier, and a refund must not strip a tier the customer is being kept at (raised threshold, mode switch, hold). A ledger row counts toward tier points by `counts_toward_tier`, or by type when NULL (EARN, REFUND_EARN, ADJUST), so every existing row keeps its meaning. All three modes sum tier points through `LedgerRepository::window_total()`; Lifetime no longer reads `lifetime_earned`, which can now differ. A refund compares the mode's total with and without that refund's rows (all of them: one reversal writes its clawbacks in one transaction): already short before → keep; otherwise judged as any negative row. In Calendar, a closed period ignores rows created after it ended, with a restore dated by its refund, so refunding last period's order changes no tier (accepted abuse window). A staff deduction counted toward tier points still drops a customer: it is staff's lever.
**Rejected:** reading `lifetime_earned` for Lifetime; leaving out only the refund row for the before-total; cutting off only REFUND_EARN rows at a closed period (a later restore would inflate it).
**Evidence:** `includes/Repositories/LedgerRepository.php` (`window_total()`, `tier_points_sql()`), `includes/Pro/Services/TierDecision.php` (`refund_move()`), P-9.

### 2026-11-19 A CSV import is keyed on a hash of the file, not stored as a job
**Why:** a migration's worst failure is applying a row twice. Each imported row is an `adjust` row keyed `adjust:{user}:import:{import id}:import`, the import id being a 53-bit hash of the file's parsed rows (email, points, note) made in the SPA, so a retried chunk or the same file uploaded again, even re-saved by a spreadsheet, is `Outcome::DUPLICATE` per row, with nothing stored about the import. Apply runs each row in its own transaction (`exec_self_committing()`, not `exec_write()`), so no outer transaction holds every customer's lock or lets a deadlock undo rows already reported imported. An edited file is a new import, so the preview flags each customer with a row from another import (`LedgerRepository::latest_other_import()`) as `imported_before`, and the SPA leaves them out unless the merchant ticks them in. Manual adjustments stay unkeyed on purpose.
**Rejected:** the client-only per-customer adjust loop (not retry-safe); an Action Scheduler job with stored files (a sixth `LedgerService` site, BS-5 option C).
**Evidence:** `includes/Engine/Earning/IdempotencyKey.php`, `LedgerService::import()`, `includes/Controllers/ToolsImportRestController.php`, P-8.
