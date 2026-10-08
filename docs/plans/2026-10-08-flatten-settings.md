# Flatten Settings Implementation Plan

> **For agentic workers:** execute tasks in order, one at a time. Steps use `- [ ]` for tracking. There is no spec; the Requirements section below is the contract.

**Status:** active
**Goal:** Store and serve the plugin settings as root fields (no `general` / `advanced` groups, `emails` kept as a group), and merge the admin's Advanced tab into General.
**Spec:** none, planned from the request
**Architecture:** The wire shape (option, REST, localized `settings`, zod mirror) changes in one task so PHP and the SPA never disagree; the tab move is a pure SPA rename on top. `SettingsHelper::schema()` keeps one map, where a string value is a root field and an array value is a group, so the whitelist/coerce rules stay in one place.
**Touches:** `includes/Helpers/SettingsHelper.php` (shared: read by `ProgramGate`, Pro `DailyEarningLimit`, `EmailThrottle`, `EmailsHelper`, `EmailsRestController`, `SettingsRestController`, `LocalizeHelper`, `ActDeact`), `apps/admin/src/lib/schema/settings.schema.ts` (shared: `emails.schema.ts`, `settings.api.ts`, `settings.queries.ts`, the settings pages), the settings pages, router and nav, docs.
**Tests:** none (no points/money math or ledger write changes, no fixed bug; every task has an exact manual check instead)

## Requirements

- **Goal:** `get_option( 'yayloyalty_settings' )`, `GET /yayloyalty/v1/settings` and `window.yayLoyaltyAdmin.settings` have the shape `{ enabled, excluded_roles, points_label_singular, points_label_plural, max_points_per_day, emails: { throttle_per_week }, schema_version }`, and Settings shows General, Design, Emails (no Advanced) with points naming and the fraud limit on General.
- **Out of scope:** UI for `enabled` / `excluded_roles` (none exists today); the `emails` group's structure; the `yayloyalty_design` option; any migration.
- **Acceptance criteria:**
  1. `SettingsHelper::get_default_settings()` returns `enabled => true`, `excluded_roles => []`, `points_label_singular => __( 'Point' )`, `points_label_plural => __( 'Points' )`, `max_points_per_day => null` at the root and `emails => [ 'throttle_per_week' => 3 ]`.
  2. A stored blob that still has `general` / `advanced` keys reads as defaults for those fields, and the output contains no `general` or `advanced` key. The next save drops them from the option.
  3. POST `/settings` with `{ "max_points_per_day": 0 }` or `{ "emails": { "throttle_per_week": 1.5 } }` answers 400; `{ "points_label_singular": "Star" }` stores only that field at the root.
  4. `/settings/advanced` redirects to `/settings/general`; the side nav lists General, Design, Emails.
  5. End to end: on Settings → General, set singular "Star" and (Pro) daily limit 10, save, reload: both persist, the option holds `points_label_singular => 'Star'`, `max_points_per_day => 10` at the root, and no `points_label_plural` key.
- **Decided with the user:**
  - Migration? No; the plugin is unreleased. Old grouped keys are simply dropped by the whitelist.
  - Name the moved SPA files for General? Yes: nothing named "advanced" remains in settings code.
  - Update the proposed (not accepted) YayCurrency spec to the new shape? Yes. Accepted specs and plans stay as history.

## Global constraints

- Product PHP must run on PHP 7.4: no enums, `match`, `readonly`, promoted constructor properties, `?->`, named arguments or union types (also no `mixed` type, trailing comma in a declaration, etc. per `.claude/rules/php-standards.md`).
- Settings keys go in both the defaults and `schema()`, then in the zod mirror `apps/admin/src/lib/schema/settings.schema.ts`.
- SPA Pro code lives in `apps/admin/src/pro/`, is imported only through `apps/admin/src/pro/index.ts`, and renders only behind `IS_PRO_BUILD`.
- Lite names Pro only via the guarded `YayLoyaltyPro::boot()` call.
- Never hand-edit `assets/dist/`, `vendor/`, `vendor-prefixed/`.
- Commits: Conventional Commits, ending `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`; stage by name; branch from `dev/edd` as `<name>/refactor/flatten-settings`; commit only when asked.
- `SettingsHelper::SCHEMA_VERSION` stays `2` (no migration reads it).

## Review focus

- Stale grouped blob on a dev site: reads clean defaults, no PHP notice, and a save rewrites the option without `general` / `advanced` (Task 1 Step 4).
- Untouched points label: saving only the fraud limit must not store `points_label_*`, so translated defaults keep following the site language (Task 1 Step 5).
- `emails.throttle_per_week` still validates and saves from the Emails tab, since `is_valid()` / `apply_schema()` now walk a mixed root/group map (Task 1 Step 5).
- Lite build must not pull `FraudLimitsSection` in: General keeps the `IS_PRO_BUILD ? … : <FraudLimitsUpsellCard />` guard (Task 2 Step 4).

---

### Task 1: Flat settings shape (PHP + SPA mirror)

**Files:**
- Modify: `includes/Helpers/SettingsHelper.php`
- Modify: `includes/Engine/Earning/ProgramGate.php:25,33-45`
- Modify: `includes/Pro/Services/DailyEarningLimit.php:44`
- Modify: `apps/admin/src/lib/schema/settings.schema.ts`
- Modify: `apps/admin/src/pages/settings/advanced-settings.form.ts`
- Modify: `apps/admin/src/pages/settings/AdvancedPointsNamingCard.tsx`
- Modify: `apps/admin/src/pro/settings/AdvancedFraudLimitsSection.tsx`

**Interfaces:**
- Produces: settings shape `{ enabled: bool, excluded_roles: string[], points_label_singular: string, points_label_plural: string, max_points_per_day: int|null, emails: { throttle_per_week: int|null }, schema_version: int }`; TS `SettingsUpdate = Partial<Omit<Settings, 'schema_version' | 'emails'>> & { emails?: Partial<Settings['emails']> }`; form values flat `{ points_label_singular, points_label_plural, max_points_per_day }` with flat dirty map `Partial<Record<keyof FormValues, boolean>>`.

- [ ] **Step 1: Flatten `SettingsHelper`**
  - `get_default_settings()` and `schema()` take the AC 1 shape; `schema()` is `@return array<string, string|array<string, string>>` (types unchanged: `bool`, `slug_list`, `points_label`, `limit`; `emails.throttle_per_week` stays `limit`). Keep the two field comments (ProgramGate, DailyEarningLimit) on the moved keys.
  - `apply_schema()`: a string type coerces the root field; an array type applies to that group per field as today. The empty-`points_label` skip still applies.
  - `build_settings()`: root fields replace the default; a group is `array_replace`d per field as today (keep the "per field, not recursive" reason).
  - `update_settings()`: root fields set directly, group fields per field.
  - `is_valid()`: checks every `limit` field at its own level (`max_points_per_day` at root, `emails.throttle_per_week` in its group).
  - `points_label()` / `points_label_plural()` read the root keys.
- [ ] **Step 2: Readers**: `ProgramGate::program_enabled()` reads `get_settings()['enabled']`, `check()` reads `get_settings()['excluded_roles']` (drop `$general`); `DailyEarningLimit::clamp()` reads `get_settings()['max_points_per_day']`.
  Verify: `git grep -n -E "\['(general|advanced)'\]" -- includes` prints nothing; `composer lint -- includes/Helpers/SettingsHelper.php includes/Engine/Earning/ProgramGate.php includes/Pro/Services/DailyEarningLimit.php` reports no errors in these files beyond what `dev/edd` reports for them; `composer test` passes.
- [ ] **Step 3: SPA mirror**
  - `settings.schema.ts`: flat `z.object` per the Produces shape (keep the field doc comments); `SettingsUpdate` as above, keeping its docblock.
  - `advanced-settings.form.ts`: form schema `settingsSchema.pick({ max_points_per_day: true }).extend({ points_label_singular: pointsLabel, points_label_plural: pointsLabel })`; defaults read root fields; dirty-fields type flat; the update builder returns `{ field: value }` for dirty fields only.
  - `AdvancedPointsNamingCard.tsx`: `register(name)`, `formState.errors[name]`, `useWatch` names `['points_label_singular', 'points_label_plural']`.
  - `AdvancedFraudLimitsSection.tsx`: `name="max_points_per_day"`.
  Verify: `pnpm --filter @yayloyalty/admin-page exec tsc --noEmit 2>&1 | grep -E "settings\.schema|pages/settings|pro/settings|emails\.schema"` prints nothing (the full run fails on `dev/edd`; only new errors in these paths count); `pnpm --filter @yayloyalty/admin-page test` passes.
- [ ] **Step 4: Verify a stale grouped blob** (from `/Users/shrief/Local-Sites/yaydevs/app/public`, with the Local socket flag `php -d "mysqli.default_socket=…/DmdPSy05S/mysql/mysqld.sock" /opt/homebrew/bin/wp`):
  `wp eval 'update_option("yayloyalty_settings", ["general"=>["enabled"=>false],"advanced"=>["points_label_singular"=>"Star"],"schema_version"=>2]); var_export(YayLoyalty\Helpers\SettingsHelper::get_settings());'`
  Expected: `enabled => true`, `points_label_singular => 'Point'`, `emails => ['throttle_per_week' => 3]`, no `general` / `advanced` keys, no notices. Then `wp eval 'YayLoyalty\Helpers\SettingsHelper::update_settings(["max_points_per_day"=>5]); var_export(get_option("yayloyalty_settings"));'` → `['max_points_per_day' => 5, 'schema_version' => 2]`. Finish with `wp option delete yayloyalty_settings`.
- [ ] **Step 5: Verify in the admin** (`./scripts/run.sh dev pro`, `http://yaydevs.local/wp-admin/admin.php?page=yayloyalty#/settings/advanced`):
  - Change only the daily limit to 10, save, reload: 10 persists; `wp option get yayloyalty_settings --format=json` has `"max_points_per_day":10` and no `points_label_*`.
  - Set singular "Star", save, reload: persists; option gains `"points_label_singular":"Star"` only.
  - Emails tab: set the weekly cap to 2, save: option has `"emails":{"throttle_per_week":2}`.
  - Admin page console: `await wp.apiFetch({ path: '/yayloyalty/v1/settings', method: 'POST', data: { max_points_per_day: 0 } })` rejects with the invalid-arguments 400; same for `{ emails: { throttle_per_week: 1.5 } }`.
- [ ] **Step 6: Commit**: stage the seven files above. Message: `refactor(settings): store settings as root fields, keep the emails group`

### Task 2: Merge the Advanced tab into General

**Files:**
- Delete: `apps/admin/src/pages/settings/GeneralSettingsPage.tsx` (placeholder), then rename `AdvancedSettingsPage.tsx` → `GeneralSettingsPage.tsx`
- Rename: `pages/settings/advanced-settings.form.ts` → `general-settings.form.ts`; `advanced-settings.copy.ts` → `general-settings.copy.ts`; `AdvancedPointsNamingCard.tsx` → `PointsNamingCard.tsx`
- Rename: `apps/admin/src/pro/settings/AdvancedFraudLimitsSection.tsx` → `FraudLimitsSection.tsx`
- Modify: `apps/admin/src/pro/index.ts`, `apps/admin/src/router.tsx:20,114-118`, `apps/admin/src/components/layout/SettingsSideNav.tsx:12`, `includes/Helpers/SettingsHelper.php:19` (docblock path)

**Interfaces:**
- Consumes: Task 1's flat form values.
- Produces: `generalSettingsFormSchema`, `GeneralSettingsFormValues`, `GeneralSettingsDirtyFields`, `generalSettingsDefaults(settings: Settings)`, `buildGeneralSettingsUpdate(values, dirty): SettingsUpdate` in `general-settings.form.ts`; `PointsNamingCard`; Pro `FraudLimitsSection` exported from `@/pro`; default export `GeneralSettingsPage`.

- [ ] **Step 1: Renames** with `git rm` / `git mv` as listed (history follows the Advanced files), and update the exported names and every import to the Produces names. Docblocks and copy say "General" / "Settings → General" where they said "Advanced"; toasts become `General settings saved.` / `Failed to save general settings.`. `SettingsHelper::POINTS_LABEL_MAX_LENGTH`'s docblock points at `general-settings.form.ts`.
- [ ] **Step 2: Route and nav**: remove the `AdvancedSettingsPage` import and the `advanced` route (the `*` child already redirects to `/settings/general`); remove the Advanced entry from `SETTINGS_NAV`. The Emails tab's `/settings/general` link stays.
  Verify: `git grep -n -i "advanced" -- apps/admin/src/pages/settings apps/admin/src/pro/settings apps/admin/src/pro/index.ts apps/admin/src/router.tsx apps/admin/src/components/layout/SettingsSideNav.tsx` prints nothing; the Task 1 narrowed `tsc` grep prints nothing; `pnpm --filter @yayloyalty/admin-page test` passes.
- [ ] **Step 3: Verify in the admin (Pro)**: `#/settings/advanced` lands on `#/settings/general`; the nav shows General, Design, Emails; General shows Points naming then Fraud limits with one Save; repeat the first two Task 1 Step 5 checks here; leaving with an unsaved edit opens the unsaved-changes dialog.
- [ ] **Step 4: Verify Lite**: `./scripts/run.sh dev lite`, open `#/settings/general`: Points naming plus the Fraud limits upsell card (no input). `grep -n "IS_PRO_BUILD" apps/admin/src/pages/settings/GeneralSettingsPage.tsx` shows the guard around `<FraudLimitsSection />`.
- [ ] **Step 5: Commit**: stage the renamed/deleted/modified files above by name. Message: `refactor(admin): merge the Advanced settings tab into General`

### Task 3: Docs

**Files:**
- Modify: `docs/architecture.md:93`
- Modify: `docs/backlog/2026-10-08-launcher-advertises-earning-past-the-daily-cap.md:11,23`
- Modify: `docs/specs/2026-10-07-yaycurrency-currency-and-rounding-design.md:21,54,62-63,115,122,132-133,138`
- Create: `docs/plans/2026-10-08-flatten-settings.md` (this plan)

- [ ] **Step 1: architecture.md**: in the settings bullet, add that `yayloyalty_settings` keeps its fields at the root and only the emails' fields sit under `emails`.
- [ ] **Step 2: backlog item**: `advanced.max_points_per_day` → `max_points_per_day`.
- [ ] **Step 3: YayCurrency spec** (Status stays `proposed`): `advanced.currency_conversion` / `advanced.currency_rounding` → root `currency_conversion` / `currency_rounding`; "two `advanced` fields" → "two root fields"; "Advanced tab" and `AdvancedSettingsPage.tsx` → General tab / `GeneralSettingsPage.tsx`; `advanced-settings.copy.ts` / `.form.ts` → `general-settings.*`; `AdvancedFraudLimitsSection.tsx` → `FraudLimitsSection.tsx`.
  Verify: `git grep -n -E "advanced\.|advanced-settings|AdvancedSettings|AdvancedFraud|Advanced tab" -- docs README.md CLAUDE.md .claude ':!docs/plans' ':!docs/specs/2026-10-05-points-naming-design.md'` prints nothing.
- [ ] **Step 4: Commit**: stage the three docs and the plan file. Message: `docs(settings): describe the flat settings shape and the merged General tab`

## Final verification

Acceptance criterion 5 on a Pro dev build (Task 2 Step 3), plus Task 1 Step 4's stale-blob check, then `/yayplancheck docs/plans/2026-10-08-flatten-settings.md`.
