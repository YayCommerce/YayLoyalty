# Phase 2: Admin UI, storefront and wrap-up

**Plan:** `README.md` in this directory: the Status, constraints and interface contract
**Depends on:** all of Phase 1, landed and passed by `/yayplancheck`. This phase uses:
- `TierCalendar`;
- `TierEvaluator::calendar_period()`;
- the `tiers_calendar_period` setting;
- `MODE_TO_CALENDAR`;
- the widened `tierModeSchema` and `tierSettingsSchema`.

**Delivers:**
- the Qualification card's Calendar option and Period select, with their confirms;
- the drawer's Calendar caption;
- the launcher and My Points captions;
- the Lite check and the end-to-end walkthrough.

**Exit criterion:**
- Spec AC 16, 17, 18, 19, 20 and the end-to-end AC 21 pass on wp-env (with `TZ` and `TIERS`).
- `./scripts/run.sh release lite --no-prompt` validates.
- The Chrome console shows no `yayloyalty` error or warning on `#/tiers`, `#/customers`, the drawer, the launcher, and My Account → My Points.

### Task 1: Calendar standing in the drawer

**Files:**
- Modify: `includes/Pro/Services/TierStanding.php` (`of()` at :33–76)
- Modify: `includes/Pro/Controllers/CustomerTierRestController.php` (`respond()` at :140–175)
- Modify: `apps/admin/src/lib/schema/tier.schema.ts` (`customerTierSchema`)
- Modify: `apps/admin/src/pro/customers/CustomerTierCard.tsx` (`isRolling` at :51, the caption at :86–99, `tierSince` usage at :61)

**Interfaces:**
- Consumes:
  - `TierCalendar::bounds()`;
  - `TierEvaluator::calendar_period()`;
  - `LedgerRepository::window_total()`.
- Produces:
  - `TierStanding::of()` → `period: ?string`, where in Calendar `progress` = `window_total( user, bounds.current_start, now )` and `window_months` = null;
  - REST `period`;
  - `customerTierSchema.period`.

Steps cover the following. Spec AC 16 is the check.
- The card shows the hold sub-line when `mode` is `rolling` or `calendar` and `held_until` is set.
- In Calendar the caption is one of three full strings, "%1$s / %2$s this month|quarter|year", with the translators comment "1: points earned this period, 2: points the next tier needs."
- Lifetime and Rolling output is unchanged.

Steps: expand after Phase 1 lands.

### Task 2: The Qualification card's Calendar option

**Files:**
- Modify: `apps/admin/src/pro/tiers/TierModeCard.tsx`

**Interfaces:**
- Consumes:
  - `TIERS_CALENDAR_PERIODS`, `tiersCalendarPeriodSchema` and `TierSettingsInput` (Phase 1 Task 5);
  - `useUpdateTierSettingsMutation()`, unchanged.
- Produces: nothing other phases consume.

Steps cover the spec's **UI → `pro/tiers/TierModeCard.tsx`** bullet, word for word:
- the `MODES` entry "Calendar" and its description;
- `sm:grid-cols-3`;
- the "Period" `Select` (`id` `yayloyalty-tiers-calendar-period`, `w-48`, items "Monthly" / "Quarterly" / "Yearly");
- `Change` gaining `to_calendar` and `period`;
- the two confirms, each with three full per-period sentences;
- the docblock naming the three modes.

Spec AC 17 is the check.

Steps: expand after Phase 1 lands.

### Task 3: Storefront captions

**Files:**
- Modify: `includes/Pro/Storefront/TierProgress.php` (`line()` at :81, `sample()` at :70, the call at :57)
- Modify: `includes/Pro/Storefront/AccountTier.php` (the call at :60)
- Modify: `includes/Engine/Storefront/LauncherPayload.php` (docblock at :264–280, keys at :311–322, validation at :335)
- Modify: `includes/Engine/Storefront/AccountMyPoints.php` (docblock at :243, keys at :292–304, validation at :318)
- Modify: `includes/Engine/Storefront/LauncherCopy.php` (beside `tier_window_caption()` at :190)
- Modify: `includes/Engine/Storefront/templates/tier-progress.php` (:44–53)
- Modify: `includes/Engine/Storefront/templates/account-tier.php` (:50–52)

**Interfaces:**
- Consumes: `TierStanding::of()['period']` (Task 1), `TierCalendar`, and `TierEvaluator::calendar_period()`.
- Produces:
  - `TierProgress::line()` with `?string $period` before `$held_until`;
  - `LauncherCopy::tier_period_caption( string $period ): string`;
  - the storefront `period` key, as in the README contract.

Steps cover the following. Spec AC 18 is the check.
- **`sample()` in Calendar:** `period` = the setting, and `held_until` = `TierCalendar::hold_dates( 'current', … )['until']`.
- **Payload validation:** `period` is null or `TierMode::is_calendar_period()`.
- **Templates:**
  - each template shows `tier_period_caption()` when `period` isn't null, else the existing `window_months` branch;
  - `tier-progress.php` appends the existing "%1$s kept until %2$s." in both branches.
- **Theme overrides:** an override that predates `period` must render in Calendar without a notice. Check by copying `tier-progress.php` into the active theme's `yayloyalty/` folder, deleting the `period` branch, and loading the launcher.

Steps: expand after Phase 1 lands.

### Task 4: Lite check, end to end, and plan status

**Files:**
- Modify: `docs/plans/2026-11-11-tier-calendar-mode/README.md`: the Phases table statuses, and the Status set to `done YYYY-MM-DD` by the PR that finishes it.

**Interfaces:**
- Consumes: everything above.
- Produces: nothing.

Steps cover the following:
- spec AC 19:
  - `composer test`;
  - `composer test:integration -- --filter TierEvaluatorTest`;
  - the Rolling end-to-end spot checks.
- spec AC 20: build Lite, then `./scripts/run.sh release lite --no-prompt`, then `composer install`;
- spec AC 21, walked through in the browser, with the Chrome console check.

Steps: expand after Phase 1 lands.
