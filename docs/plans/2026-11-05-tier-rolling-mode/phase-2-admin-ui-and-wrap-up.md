# Phase 2: Qualification card, drawer hold, and wrap-up

**Plan:** `README.md` in this directory: the Status, constraints and interface contract
**Depends on:** Phase 1, all tasks: `PUT /tiers/settings` with mode and months, the mode sweeps, the Rolling evaluator, and `window_total()`.
**Delivers:**
- The Tiers page "Qualification" card switches mode and months, with confirms.
- In Rolling, the drawer tier card shows the hold and the live window progress.
- The activity log labels hold ends and mode changes.
- The backlog is updated, and the Lite build is checked.

**Exit criterion:** spec AC 16, 17, 18 and 20, and the end-to-end AC 21, pass on wp-env. `./scripts/run.sh release lite --no-prompt` validates, and the Chrome console shows no error or warning from `yayloyalty` on `#/tiers`, `#/customers` and the drawer.

### Task 1: Customer tier route and the drawer card in Rolling

**Done when:** `GET /customers/{id}/tier` returns the mode, window and hold, with the live window total in Rolling. The drawer card shows "Since … · held until …" and the "in the last N months" caption, and the activity log labels `hold_end` and `mode_change` (spec AC 16, 17).

**Files:**
- Modify: `includes/Pro/Controllers/CustomerTierRestController.php`, `apps/admin/src/lib/schema/tier.schema.ts`, `apps/admin/src/pro/customers/CustomerTierCard.tsx`, `apps/admin/src/pages/customers/drawer/customer-drawer.activity.copy.ts`

The spec names `customer-drawer.activity-rows.ts`, but the reason labels live in `customer-drawer.activity.copy.ts` (the "Tiers turned on" switch), so that file is edited.

**Interfaces:**
- Consumes: `TierEvaluator::mode()`, `LedgerRepository::window_total()`, `Dates::add_months()`, and the `Customer` hold getters (README contract).
- Produces: the route's added fields and the `customerTierSchema` additions (README contract).

The route's rules:
- `respond()` adds `mode` = `TierEvaluator::mode()`, and `window_months` = `tiers_rolling_months` in Rolling or null.
- `held_from`/`held_until` are the stored values only while `held_until` > now, otherwise null.
- In Rolling, `progress_value` = `window_total( user, add_months( now, -window_months ), now )`. In Lifetime it is unchanged.
- The constructor gains a `LedgerRepository`, and `YayLoyaltyPro` passes it.

The card's rules:
- With `mode === 'rolling'`, the TIER sub-line uses `sprintf( __( 'Since %1$s · held until %2$s', 'yayloyalty' ), formatStoredDate( achieved_at ), formatStoredDate( held_until ) )` while `held_until` is set.
- The caption uses `__( '%1$s / %2$s in the last %3$s months', 'yayloyalty' )`.
- Reason labels: `hold_end` → "Tier hold ended", `mode_change` → "Tier mode changed".

Steps: expand after Phase 1 lands. The verification is spec AC 16 (including the backdated row with no evaluation) and AC 17, plus slice 2's drawer card unchanged in Lifetime.

### Task 2: The "Qualification" card

**Done when:** the Tiers page switches mode and months, with spec AC 18's confirms, disabled states and toasts.

**Files:**
- Create: `apps/admin/src/pro/tiers/TierModeCard.tsx`
- Modify: `apps/admin/src/pro/tiers/tiers.api.ts`, `apps/admin/src/pro/tiers/tiers.queries.ts`, `apps/admin/src/lib/schema/tier.schema.ts`, `apps/admin/src/pro/index.ts`, `apps/admin/src/pages/tiers/TiersPage.tsx`

**Interfaces:**
- Consumes: `PUT /tiers/settings` and `TIER_QUERIES.settings()`, the existing query under `['tier', 'settings']` that `TierSwitchCard` polls.
- Produces: `TierModeCard` exported from `@/pro`, `TierSettingsInput`, `tiersRollingMonthsSchema` and `TIERS_ROLLING_MONTHS` (README contract).

The card's rules (spec **UI**):
- `TiersPage` renders `<TierModeCard />` between `TierSwitchCard` and `TierCatalog`.
- The form state is local `useState` for `mode` and `months`, initialised from the settings query. The card has two fields and no validation beyond the select's fixed values, so it needs no react-hook-form module.
- The three confirms are one `ConfirmDialog` whose copy is chosen by the change. They show only while `tiers_enabled` is true.
- After save: invalidate `['tier']` and the main settings query, as `useUpdateTierSettingsMutation` already does.
- A 409 `yayloyalty_tier_sweep_running` surfaces through `toastApiError()`.

Steps: expand after Phase 1 lands. The verification is spec AC 18 on http://localhost:8889/wp-admin/admin.php?page=yayloyalty#/tiers, plus `tsc --noEmit` and eslint on the changed files.

### Task 3: Backlog, Lite check and the end-to-end walkthrough

**Done when:** the backlog no longer lists Rolling mode as missing, the Lite zip validates, and spec AC 20 and AC 21 pass.

**Files:**
- Modify: `docs/backlog/2026-10-06-tiers-not-built.md` (remove the "Rolling mode and holds" bullet from "Still missing", and add to "Today": "Tiers qualify in Lifetime or Rolling mode (3–24 months, with holds), set in Tiers → Qualification.")
- Modify: this plan's `README.md` (phase statuses; `**Status:** done YYYY-MM-DD` once Phase 2 passes `/yayplancheck`) and the spec's backlog reference, unchanged.

**Interfaces:**
- Consumes: everything above.
- Produces: nothing.

Steps: expand after Phase 1 lands. The checks:
- **Lite:** `./scripts/run.sh release pro --no-prompt`, then `./scripts/run.sh release lite --no-prompt` → both validate, then `composer install`. On the Lite build, `Installer::install()` keeps `tier_held_from` and `tier_held_until`, and `tiers_mode` and `tiers_rolling_months` stay in `yayloyalty_settings` (spec AC 20).
- **End to end:** spec AC 21 steps 1–6, on wp-env with the `pro` SPA built.
