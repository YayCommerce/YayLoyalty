---
area: decisions
updated: 2026-11-13
updated_ref: 6ec1e2d
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

### 2026-11-13 Conditions are one list matched by all or any, flat rows untouched
**Why:** Merchants asked for the YayPricing model: one list of conditions and one Any/All switch, no groups. An `all` list keeps the 1.0.0 list envelope, so flat rows need no migration; an `any` list adds `"match":"any"` at `schema_version` 2 to the same envelope, so a 1.0.0 reader still sees its items and reads them as `all`, which fails closed. At most one entry per type under either match. New conditions start as `all`.
**Rejected:** two levels of AND/OR groups (built first in P-1, then removed at review); a nested tree (BS-1 option B); a data migration.
**Evidence:** `includes/Utils/ConditionMatch.php`, P-1.
